
"""
CLI entry point for runtime virtual environment creation.

Reads JSON from stdin and prints JSON to stdout.
"""

import json
import sys

from runtime_environment.environment_builder import (
    create_runtime_environment
)


def main():
    try:
        payload = json.load(sys.stdin)

        result = create_runtime_environment(
            runtime_key=payload["runtime_key"],
            workspace_path=payload["workspace_path"],
            python_version=payload["python_version"]
        )

        print(json.dumps(result, indent=4))

        return 0 if result.get("success") else 1

    except Exception as exc:
        print(json.dumps({
            "success": False,
            "status": "failed",
            "message": str(exc)
        }, indent=4))

        return 1


if __name__ == "__main__":
    sys.exit(main())
