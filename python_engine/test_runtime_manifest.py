
"""
Temporary test for the runtime manifest builder.

Uses the existing static package inspector.
Does not extract or execute uploaded model code.
"""

import json
from pathlib import Path

from package_inspection import inspect_package
from runtime_environment import build_runtime_manifest


PROJECT_ROOT = Path(__file__).resolve().parent.parent

MODEL_ZIP = (
    PROJECT_ROOT
    / "api"
    / "storage"
    / "models"
    / "d41d844470dae9e823598ccca63467285677b18fda80ad59.zip"
)


def main():

    print("=" * 60)
    print("IRIS MODEL RUNTIME MANIFEST TEST")
    print("=" * 60)

    if not MODEL_ZIP.is_file():
        print(f"Model ZIP not found: {MODEL_ZIP}")
        return

    print("\n1. Inspecting model package...")

    inspection = inspect_package(str(MODEL_ZIP))

    print("Inspection status:", inspection.get("status"))
    print("Framework:", inspection.get("framework"))

    if inspection.get("status") != "READY":
        print("\nInspection did not return READY.")
        print(json.dumps(inspection, indent=4))
        return

    print("\n2. Building runtime manifest...")

    result = build_runtime_manifest(
        inspection=inspection,
        model_id=2,
        model_version_id=7,
        python_version="3.14",
    )

    print("\n3. Runtime manifest result:")
    print(json.dumps(result, indent=4, ensure_ascii=False))

    if result.get("success"):
        print("\nSUCCESS: Runtime manifest generated.")
    else:
        print("\nFAILED: Runtime manifest could not be generated.")


if __name__ == "__main__":
    main()
