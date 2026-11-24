
"""
Command-line interface for the ML Model Package Inspector.

Usage:
    python python_engine/inspect_package.py "path/to/model.zip"
"""

import argparse
import json
import sys

from package_inspection import inspect_package


def main():

    parser = argparse.ArgumentParser(
        description="Inspect an uploaded ML model ZIP package."
    )

    parser.add_argument(
        "zip_path",
        help="Full path to the uploaded ZIP archive."
    )

    args = parser.parse_args()

    result = inspect_package(args.zip_path)

    print(
        json.dumps(
            result,
            indent=4,
            ensure_ascii=False
        )
    )

    # Exit code 0 means inspection completed.
    # The returned status determines whether configuration is needed.
    if result.get("success"):
        return 0

    return 1


if __name__ == "__main__":
    sys.exit(main())
