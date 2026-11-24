
"""
Integration test for the runtime manifest CLI.

Builds inspection data using the existing static inspector,
then sends that data to the CLI through standard input.
"""

import json
import subprocess
import sys

from pathlib import Path

from package_inspection import inspect_package


PYTHON_ENGINE = Path(__file__).resolve().parent

PROJECT_ROOT = PYTHON_ENGINE.parent

MODEL_ZIP = (
    PROJECT_ROOT
    / "api"
    / "storage"
    / "models"
    / "d41d844470dae9e823598ccca63467285677b18fda80ad59.zip"
)

CLI_SCRIPT = PYTHON_ENGINE / "build_runtime_manifest.py"


def main():

    print("=" * 60)
    print("RUNTIME MANIFEST CLI INTEGRATION TEST")
    print("=" * 60)

    if not MODEL_ZIP.is_file():
        print("Model ZIP not found:", MODEL_ZIP)
        return 1

    print("\n1. Generating inspection data...")

    inspection = inspect_package(str(MODEL_ZIP))

    if inspection.get("status") != "READY":
        print("Inspection failed:")
        print(json.dumps(inspection, indent=4))
        return 1

    print("Inspection status:", inspection["status"])

    payload = {
        "inspection": inspection,
        "model_id": 2,
        "model_version_id": 7,
        "python_version": "3.14",
    }

    print("\n2. Calling runtime manifest CLI...")

    process = subprocess.run(
        [sys.executable, str(CLI_SCRIPT)],
        input=json.dumps(payload),
        capture_output=True,
        text=True,
        cwd=str(PROJECT_ROOT),
        timeout=30,
    )

    if process.stderr.strip():
        print("\nCLI diagnostic output:")
        print(process.stderr)

    if not process.stdout.strip():
        print("\nFAILED: CLI returned no JSON output.")
        return 1

    try:
        result = json.loads(process.stdout)
    except json.JSONDecodeError:
        print("\nFAILED: CLI returned invalid JSON.")
        print(process.stdout)
        return 1

    print("\n3. CLI result:")
    print(json.dumps(result, indent=4, ensure_ascii=False))

    if process.returncode == 0 and result.get("success"):
        print("\nSUCCESS: CLI integration test passed.")
        return 0

    print("\nFAILED: CLI integration test did not pass.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
