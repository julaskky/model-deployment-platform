
"""
Runtime Manifest Builder CLI
=============================

Receives saved inspection data as JSON through stdin
and returns a generated runtime manifest as JSON through stdout.

Does not inspect, extract, import, or execute uploaded model code.
"""

import json
import sys

from runtime_environment import build_runtime_manifest


def main():
    try:
        payload = json.load(sys.stdin)

        if not isinstance(payload, dict):
            raise ValueError("Input must be a JSON object.")

        result = build_runtime_manifest(
            inspection=payload.get("inspection"),
            model_id=payload.get("model_id"),
            model_version_id=payload.get("model_version_id"),
            python_version=payload.get("python_version"),
        )

        print(json.dumps(
            result,
            ensure_ascii=False,
            separators=(",", ":"),
        ))

        return 0 if result.get("success") else 1

    except Exception as exc:
        print(json.dumps({
            "success": False,
            "status": "FAILED",
            "manifest": None,
            "errors": [
                f"{type(exc).__name__}: {exc}"
            ],
        }, ensure_ascii=False))

        return 1


if __name__ == "__main__":
    sys.exit(main())
