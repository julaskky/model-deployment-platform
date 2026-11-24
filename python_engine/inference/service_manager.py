
"""
Custom Runtime Inference Service Manager.

Actions:
    start
    status
    stop

Windows:
- Launches inference servers independently of the manager process.
- Prevents the child process from opening a console window.
- Redirects output to the runtime inference log.
- Verifies process and HTTP health before reporting success.

The inference service listens only on 127.0.0.1.
"""

from __future__ import annotations

import argparse
import csv
import io
import json
import os
import secrets
import socket
import subprocess
import time
import urllib.request

from pathlib import Path
from typing import Any


PROJECT_ROOT = Path(__file__).resolve().parents[2]
RUNTIMES_ROOT = (
    PROJECT_ROOT / "api" / "storage" / "runtimes"
).resolve()


def output(data: dict[str, Any], exit_code: int = 0) -> None:
    print(json.dumps(data, ensure_ascii=False), flush=True)
    raise SystemExit(exit_code)


def read_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        value = json.load(handle)

    if not isinstance(value, dict):
        raise RuntimeError("Invalid runtime service configuration.")

    return value


def write_json(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    temporary = path.with_name(path.name + ".tmp")

    with temporary.open("w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2)

    os.replace(temporary, path)


def validate_workspace(value: str) -> Path:
    workspace = Path(value).resolve()

    try:
        workspace.relative_to(RUNTIMES_ROOT)
    except ValueError as exc:
        raise RuntimeError(
            "Runtime workspace is outside permitted storage."
        ) from exc

    required = [
        workspace / "config" / "workspace.json",
        workspace / "config" / "manifest.json",
        workspace / "model" / "artifacts" / "metadata.json",
        workspace / "model" / "artifacts",
        workspace / "runtime",
    ]

    for item in required:
        if not item.exists():
            raise RuntimeError(
                f"Runtime workspace component is missing: {item.name}"
            )

    return workspace


def validate_python(value: str) -> Path:
    python = Path(value).resolve()

    if not python.is_file():
        raise RuntimeError(
            "Runtime Python executable does not exist."
        )

    if python.name.lower() not in ("python.exe", "python"):
        raise RuntimeError(
            "Invalid runtime Python executable."
        )

    return python


def service_file(workspace: Path) -> Path:
    return workspace / "runtime" / "inference_service.json"


def reserve_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def process_exists(pid: int) -> bool:
    if pid <= 0:
        return False

    if os.name == "nt":
        try:
            result = subprocess.run(
                [
                    "tasklist",
                    "/FI",
                    f"PID eq {pid}",
                    "/FO",
                    "CSV",
                    "/NH",
                ],
                capture_output=True,
                text=True,
                timeout=5,
                creationflags=getattr(
                    subprocess,
                    "CREATE_NO_WINDOW",
                    0
                ),
            )

            if result.returncode != 0:
                return False

            rows = list(csv.reader(
                io.StringIO(result.stdout)
            ))

            return any(
                len(row) >= 2 and row[1].strip() == str(pid)
                for row in rows
            )

        except Exception:
            return False

    try:
        os.kill(pid, 0)
        return True
    except (OSError, ProcessLookupError):
        return False


def terminate_process(pid: int) -> None:
    if pid <= 0 or not process_exists(pid):
        return

    if os.name == "nt":
        subprocess.run(
            [
                "taskkill",
                "/PID",
                str(pid),
                "/T",
                "/F",
            ],
            capture_output=True,
            text=True,
            timeout=10,
            creationflags=getattr(
                subprocess,
                "CREATE_NO_WINDOW",
                0
            ),
        )
    else:
        try:
            os.kill(pid, 15)
        except OSError:
            pass


def request_service(
    service: dict[str, Any],
    endpoint: str,
    method: str = "GET",
    body: dict[str, Any] | None = None,
    timeout: float = 3.0,
) -> dict[str, Any]:

    url = (
        f"http://127.0.0.1:{int(service['port'])}{endpoint}"
    )

    payload = None

    if body is not None:
        payload = json.dumps(body).encode("utf-8")

    request = urllib.request.Request(
        url,
        data=payload,
        method=method,
        headers={
            "X-Runtime-Token": str(service["token"]),
            "Content-Type": "application/json",
        },
    )

    with urllib.request.urlopen(
        request,
        timeout=timeout
    ) as response:
        result = json.loads(
            response.read().decode("utf-8")
        )

    if not isinstance(result, dict):
        raise RuntimeError(
            "Invalid response from inference service."
        )

    return result


def public_service_data(
    service: dict[str, Any],
    healthy: bool,
) -> dict[str, Any]:

    return {
        "success": True,
        "status": "running" if healthy else "stopped",
        "host": "127.0.0.1",
        "port": service.get("port"),
        "pid": service.get("pid"),
        "started_at": service.get("started_at"),
        "health": "healthy" if healthy else "unavailable",
        "model": service.get("model", {}),
    }


def read_log_tail(log_path: Path) -> str:
    try:
        return log_path.read_text(
            encoding="utf-8",
            errors="replace",
        )[-6000:]
    except OSError:
        return ""


def launch_background_process(
    command: list[str],
    log_handle: Any,
) -> subprocess.Popen:

    options: dict[str, Any] = {
        "args": command,
        "cwd": str(PROJECT_ROOT),
        "stdin": subprocess.DEVNULL,
        "stdout": log_handle,
        "stderr": subprocess.STDOUT,
        "close_fds": True,
        "env": {
            **os.environ,
            "PYTHONUNBUFFERED": "1",
        },
    }

    if os.name == "nt":
        startup_info = subprocess.STARTUPINFO()

        startup_info.dwFlags |= (
            subprocess.STARTF_USESHOWWINDOW
        )

        startup_info.wShowWindow = subprocess.SW_HIDE

        options["startupinfo"] = startup_info

        # CREATE_NO_WINDOW prevents console applications from
        # opening a visible Windows console.
        # The process still runs independently and writes its
        # output to the supplied log file.
        options["creationflags"] = subprocess.CREATE_NO_WINDOW

    else:
        options["start_new_session"] = True

    return subprocess.Popen(**options)


def start_service(
    workspace: Path,
    python: Path,
) -> None:

    config_path = service_file(workspace)

    # Reuse an existing service only if its HTTP health
    # endpoint confirms that the model is loaded.
    if config_path.is_file():
        try:
            existing = read_json(config_path)

            health = request_service(
                existing,
                "/health",
            )

            if (
                health.get("success")
                and health.get("model_loaded")
            ):
                existing["status"] = "running"
                write_json(config_path, existing)

                output(
                    public_service_data(existing, True)
                )

        except Exception:
            pass

        # The previous service is stale or unavailable.
        try:
            old_service = read_json(config_path)
            old_pid = int(old_service.get("pid") or 0)

            if process_exists(old_pid):
                terminate_process(old_pid)

                for _ in range(20):
                    if not process_exists(old_pid):
                        break
                    time.sleep(0.25)

        except Exception:
            pass

    port = reserve_port()
    token = secrets.token_urlsafe(48)

    log_path = workspace / "logs" / "inference.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)

    server_script = (
        PROJECT_ROOT
        / "python_engine"
        / "inference"
        / "inference_server.py"
    )

    if not server_script.is_file():
        raise RuntimeError(
            "Inference server implementation is missing."
        )

    service: dict[str, Any] = {
        "status": "starting",
        "host": "127.0.0.1",
        "port": port,
        "pid": None,
        "token": token,
        "started_at": None,
        "model": {},
    }

    write_json(config_path, service)

    command = [
        str(python),
        str(server_script),
        "--workspace",
        str(workspace),
        "--host",
        "127.0.0.1",
        "--port",
        str(port),
        "--token",
        token,
    ]

    with log_path.open("ab") as log_handle:
        process = launch_background_process(
            command,
            log_handle,
        )

    service["pid"] = process.pid
    service["started_at"] = time.strftime(
        "%Y-%m-%d %H:%M:%S"
    )

    write_json(config_path, service)

    last_error = "The inference process did not become healthy."

    # Wait for the actual process and HTTP service.
    for _ in range(40):

        if not process_exists(process.pid):
            last_error = (
                "The inference process terminated during startup."
            )
            break

        try:
            health = request_service(
                service,
                "/health",
                timeout=2.0,
            )

            if (
                health.get("success")
                and health.get("model_loaded")
            ):
                metadata = request_service(
                    service,
                    "/metadata",
                    timeout=3.0,
                )

                if not metadata.get("success"):
                    raise RuntimeError(
                        "Inference metadata check failed."
                    )

                service["status"] = "running"
                service["model"] = metadata.get(
                    "data",
                    {},
                )

                write_json(config_path, service)

                # One additional health check before success.
                final_health = request_service(
                    service,
                    "/health",
                    timeout=2.0,
                )

                if (
                    final_health.get("success")
                    and final_health.get("model_loaded")
                    and process_exists(process.pid)
                ):
                    output(
                        public_service_data(service, True)
                    )

                raise RuntimeError(
                    "Inference service stopped after startup."
                )

        except Exception as exc:
            last_error = str(exc)

        time.sleep(0.5)

    service["status"] = "failed"
    write_json(config_path, service)

    log_text = read_log_tail(log_path)

    raise RuntimeError(
        "Inference service did not become healthy. "
        + last_error
        + ("\nInference log:\n" + log_text if log_text else "")
    )


def status_service(workspace: Path) -> None:
    config_path = service_file(workspace)

    if not config_path.is_file():
        output({
            "success": True,
            "status": "not_started",
            "health": "unavailable",
        })

    service = read_json(config_path)

    pid = int(service.get("pid") or 0)

    if not process_exists(pid):
        output(public_service_data(service, False))

    try:
        health = request_service(
            service,
            "/health",
        )

        if health.get("success") and health.get("model_loaded"):
            service["status"] = "running"
            write_json(config_path, service)

            output(
                public_service_data(service, True)
            )

    except Exception:
        pass

    output(public_service_data(service, False))


def stop_service(workspace: Path) -> None:
    config_path = service_file(workspace)

    if not config_path.is_file():
        output({
            "success": True,
            "status": "not_started",
        })

    service = read_json(config_path)
    pid = int(service.get("pid") or 0)

    try:
        request_service(
            service,
            "/shutdown",
            method="POST",
            body={},
            timeout=3.0,
        )
    except Exception:
        pass

    for _ in range(20):
        if not process_exists(pid):
            break
        time.sleep(0.25)

    # Ensure the process is stopped if graceful shutdown failed.
    if process_exists(pid):
        terminate_process(pid)

    service["status"] = "stopped"
    write_json(config_path, service)

    output({
        "success": True,
        "status": "stopped",
        "message": "Inference service stopped.",
    })


def main() -> None:
    parser = argparse.ArgumentParser()

    parser.add_argument(
        "--action",
        required=True,
        choices=("start", "status", "stop"),
    )

    parser.add_argument(
        "--workspace",
        required=True,
    )

    parser.add_argument(
        "--python",
    )

    args = parser.parse_args()

    try:
        workspace = validate_workspace(
            args.workspace
        )

        if args.action == "start":
            if not args.python:
                raise RuntimeError(
                    "Runtime Python executable is required."
                )

            python = validate_python(args.python)

            start_service(
                workspace,
                python,
            )

        elif args.action == "status":
            status_service(workspace)

        elif args.action == "stop":
            stop_service(workspace)

    except SystemExit:
        raise

    except Exception as exc:
        output({
            "success": False,
            "status": "failed",
            "message": str(exc),
        }, 1)


if __name__ == "__main__":
    main()
