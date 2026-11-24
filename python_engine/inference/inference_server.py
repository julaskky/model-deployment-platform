"""
Custom Model Inference Server.

Responsibilities:
- Load a model from a prepared runtime workspace.
- Expose health, metadata and prediction endpoints.
- Keep the service bound to localhost.
- Require an internal authentication token.
- Never expose filesystem paths in API responses.

IMPORTANT:
joblib/pickle model loading can execute arbitrary code.
Only load trusted model packages in this development implementation.
"""

from __future__ import annotations

import argparse
import hmac
import importlib
import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path, PurePosixPath
from typing import Any


MAX_REQUEST_SIZE = 1024 * 1024


def read_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise RuntimeError(f"Required runtime configuration is missing: {path.name}")

    try:
        with path.open("r", encoding="utf-8") as handle:
            value = json.load(handle)
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Could not read {path.name}.") from exc

    if not isinstance(value, dict):
        raise RuntimeError(f"Invalid JSON structure in {path.name}.")

    return value


def safe_artifact_path(artifacts_dir: Path, filename: str) -> Path:
    if (
        not isinstance(filename, str)
        or not filename.strip()
        or "\\" in filename
        or "\x00" in filename
    ):
        raise RuntimeError("Invalid model artifact filename.")

    relative = PurePosixPath(filename)

    if (
        relative.is_absolute()
        or any(part in ("", ".", "..") for part in filename.split("/"))
    ):
        raise RuntimeError("Unsafe model artifact filename.")

    result = (artifacts_dir / Path(*relative.parts)).resolve()
    root = artifacts_dir.resolve()

    try:
        result.relative_to(root)
    except ValueError as exc:
        raise RuntimeError("Model artifact path is outside the workspace.") from exc

    if not result.is_file():
        raise RuntimeError(f"Model artifact is missing: {filename}")

    return result


class LoadedModel:
    """Loads and retains one model and its prediction function."""

    def __init__(self, workspace: Path):
        self.workspace = workspace.resolve()
        self.artifacts_dir = self.workspace / "model" / "artifacts"

        workspace_metadata = read_json(
            self.workspace / "config" / "workspace.json"
        )
        self.manifest = read_json(
            self.workspace / "config" / "manifest.json"
        )
        self.metadata = read_json(
            self.artifacts_dir / "metadata.json"
        )

        self.runtime_key = workspace_metadata.get("runtime_key")

        if not self.artifacts_dir.is_dir():
            raise RuntimeError("Model artifacts directory is missing.")

        loader_name = self.metadata.get("model_loader")
        entry_name = self.metadata.get("entry_point")
        model_file = self.metadata.get("model_file")
        function_name = self.metadata.get("prediction_function")

        if not all(
            isinstance(value, str) and value.strip()
            for value in (loader_name, entry_name, model_file, function_name)
        ):
            raise RuntimeError("Model metadata is missing required loading information.")

        self.loader_path = safe_artifact_path(
            self.artifacts_dir,
            loader_name
        )
        self.entry_path = safe_artifact_path(
            self.artifacts_dir,
            entry_name
        )
        self.model_path = safe_artifact_path(
            self.artifacts_dir,
            model_file
        )

        # The model package directory is added only inside this
        # dedicated inference process.
        sys.path.insert(0, str(self.artifacts_dir))

        try:
            # Import the package's model loader.
            loader_module_name = Path(loader_name).stem

            loader_module = importlib.import_module(
                loader_module_name
            )

            load_function = getattr(loader_module, "load_model", None)

            if not callable(load_function):
                raise RuntimeError(
                    "The model loader does not provide load_model()."
                )

            # Load the real serialized model.
            self.model = load_function()

            if self.model is None:
                raise RuntimeError("The model loader returned no model.")

            # Import the prediction entry point.
            entry_module_name = Path(entry_name).stem

            self.prediction_module = importlib.import_module(
                entry_module_name
            )

            self.predict_function = getattr(
                self.prediction_module,
                function_name,
                None
            )

            if not callable(self.predict_function):
                raise RuntimeError(
                    "The prediction function was not found."
                )

            # The supplied Iris predict.py uses a global MODEL.
            # Populate it with the model already loaded above.
            if hasattr(self.prediction_module, "MODEL"):
                self.prediction_module.MODEL = self.model

        except Exception as exc:
            raise RuntimeError(
                f"Model loading failed: {type(exc).__name__}: {exc}"
            ) from exc

        self.name = self.metadata.get("model_name", "Deployed Model")
        self.framework = self.metadata.get("framework", "unknown")
        self.framework_version = self.metadata.get(
            "framework_version",
            "unknown"
        )
        self.task = self.metadata.get("task", "unknown")
        self.algorithm = self.metadata.get("algorithm", "unknown")

    def predict(self, input_data: dict[str, Any]) -> Any:
        return self.predict_function(input_data)

    def public_metadata(self) -> dict[str, Any]:
        return {
            "model_name": self.name,
            "framework": self.framework,
            "framework_version": self.framework_version,
            "task": self.task,
            "algorithm": self.algorithm,
            "input_format": self.metadata.get("input_format", "json"),
            "output_format": self.metadata.get("output_format", "json"),
            "input_schema": self.metadata.get("input_schema", {}),
            "output_classes": self.metadata.get("output_classes", []),
        }


class InferenceHTTPServer(ThreadingHTTPServer):
    allow_reuse_address = True
    daemon_threads = True


def create_handler(model: LoadedModel, token: str):

    class InferenceHandler(BaseHTTPRequestHandler):

        server_version = "CustomModelRuntime/1.0"

        def _send_json(
            self,
            status: int,
            payload: dict[str, Any]
        ) -> None:

            body = json.dumps(
                payload,
                ensure_ascii=False,
                allow_nan=False
            ).encode("utf-8")

            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(body)

        def _authorized(self) -> bool:
            supplied = self.headers.get("X-Runtime-Token", "")

            if not hmac.compare_digest(supplied, token):
                self._send_json(401, {
                    "success": False,
                    "message": "Unauthorized runtime request."
                })
                return False

            return True

        def _read_json(self) -> dict[str, Any] | None:
            try:
                length = int(self.headers.get("Content-Length", "0"))
            except ValueError:
                self._send_json(400, {
                    "success": False,
                    "message": "Invalid Content-Length."
                })
                return None

            if length <= 0 or length > MAX_REQUEST_SIZE:
                self._send_json(413, {
                    "success": False,
                    "message": "Request body is empty or too large."
                })
                return None

            try:
                raw = self.rfile.read(length)
                data = json.loads(raw.decode("utf-8"))
            except (UnicodeDecodeError, json.JSONDecodeError):
                self._send_json(400, {
                    "success": False,
                    "message": "Request body must contain valid JSON."
                })
                return None

            if not isinstance(data, dict):
                self._send_json(400, {
                    "success": False,
                    "message": "Input must be a JSON object."
                })
                return None

            return data

        def do_GET(self):
            if not self._authorized():
                return

            if self.path == "/health":
                self._send_json(200, {
                    "success": True,
                    "status": "healthy",
                    "model_loaded": True,
                    "model_name": model.name,
                })
                return

            if self.path == "/metadata":
                self._send_json(200, {
                    "success": True,
                    "data": model.public_metadata(),
                })
                return

            self._send_json(404, {
                "success": False,
                "message": "Endpoint not found."
            })

        def do_POST(self):
            if not self._authorized():
                return

            if self.path == "/shutdown":
                self._send_json(200, {
                    "success": True,
                    "message": "Runtime shutdown requested."
                })

                threading.Thread(
                    target=self.server.shutdown,
                    daemon=True
                ).start()
                return

            if self.path != "/predict":
                self._send_json(404, {
                    "success": False,
                    "message": "Endpoint not found."
                })
                return

            input_data = self._read_json()

            if input_data is None:
                return

            try:
                prediction = model.predict(input_data)

                self._send_json(200, {
                    "success": True,
                    "data": prediction,
                })

            except (ValueError, TypeError) as exc:
                self._send_json(400, {
                    "success": False,
                    "message": str(exc),
                })

            except Exception:
                # Do not return tracebacks or internal paths.
                self._send_json(500, {
                    "success": False,
                    "message": "Model inference failed."
                })

        def log_message(self, format_string, *args):
            # Log requests to the runtime's redirected service log.
            sys.stdout.write(
                "[inference] " + format_string % args + "\n"
            )
            sys.stdout.flush()

    return InferenceHandler


def main() -> int:
    parser = argparse.ArgumentParser()

    parser.add_argument("--workspace", required=True)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", required=True, type=int)
    parser.add_argument("--token", required=True)

    args = parser.parse_args()

    if args.host != "127.0.0.1":
        raise RuntimeError("Inference service must bind to localhost.")

    if not 1024 <= args.port <= 65535:
        raise RuntimeError("Invalid inference service port.")

    if len(args.token) < 32:
        raise RuntimeError("Invalid internal runtime token.")

    workspace = Path(args.workspace).resolve()

    model = LoadedModel(workspace)

    server = InferenceHTTPServer(
        (args.host, args.port),
        create_handler(model, args.token)
    )

    print(json.dumps({
        "event": "model_loaded",
        "model_name": model.name,
        "framework": model.framework,
        "port": args.port,
    }), flush=True)

    try:
        server.serve_forever(poll_interval=0.5)
    finally:
        server.server_close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())