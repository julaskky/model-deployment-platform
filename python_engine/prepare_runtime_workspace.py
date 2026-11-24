
"""
CLI entry point for custom runtime workspace preparation.

Receives JSON through stdin and returns JSON through stdout.
"""

import json
import sys
import traceback

from runtime_environment.workspace_manager import (
    prepare_runtime_workspace
)


def main() -> int:
    try:
        payload = json.load(sys.stdin)

        result = prepare_runtime_workspace(
            runtime_key=payload["runtime_key"],
            source_zip=payload["source_zip"],
            models_root=payload["models_root"],
            workspace_root=payload["workspace_root"],
            manifest=payload["manifest"]
        )

        print(json.dumps(result, ensure_ascii=False))
        return 0

    except Exception as exception:
        print(json.dumps({
            "success": False,
            "workspace_status": "failed",
            "message": str(exception)
        }, ensure_ascii=False))

        return 1


if __name__ == "__main__":
    sys.exit(main())
