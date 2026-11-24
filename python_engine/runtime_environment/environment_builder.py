
"""
Creates an isolated Python virtual environment for a registered runtime.

This module deliberately:
- Does not install packages.
- Does not execute customer code.
- Does not load model artifacts.
- Does not enable pip inside the runtime environment.

A virtual environment is dependency isolation, not an OS security boundary.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import sys
import tempfile
import venv

from pathlib import Path
from typing import Any


class EnvironmentError(Exception):
    """Raised when runtime environment creation fails."""


RUNTIME_KEY_PATTERN = re.compile(r"^[a-f0-9]{32}$")
PYTHON_VERSION_PATTERN = re.compile(r"^\d+\.\d+$")


def _validate_python_version(version: str) -> None:
    if not isinstance(version, str):
        raise EnvironmentError("Python version must be a string.")

    if not PYTHON_VERSION_PATTERN.fullmatch(version):
        raise EnvironmentError(
            "Python version must use MAJOR.MINOR format."
        )

    current_version = (
        f"{sys.version_info.major}.{sys.version_info.minor}"
    )

    if version != current_version:
        raise EnvironmentError(
            f"Requested Python {version}, but the configured "
            f"interpreter is Python {current_version}. "
            "Configure the platform with a matching Python interpreter."
        )


def _validate_workspace(
    runtime_key: str,
    workspace_path: str | Path
) -> Path:

    if not isinstance(runtime_key, str):
        raise EnvironmentError("Invalid runtime key.")

    if not RUNTIME_KEY_PATTERN.fullmatch(runtime_key):
        raise EnvironmentError("Invalid runtime key format.")

    workspace = Path(workspace_path).resolve()

    if not workspace.exists() or not workspace.is_dir():
        raise EnvironmentError("Runtime workspace does not exist.")

    # Workspace manager stores metadata inside config/.
    config_directory = workspace / "config"

    workspace_metadata = config_directory / "workspace.json"
    manifest_file = config_directory / "manifest.json"

    if not config_directory.is_dir():
        raise EnvironmentError(
            "Runtime workspace config directory is missing."
        )

    if not workspace_metadata.is_file():
        raise EnvironmentError(
            "Runtime workspace metadata file is missing."
        )

    if not manifest_file.is_file():
        raise EnvironmentError(
            "Runtime manifest file is missing."
        )

    try:
        with workspace_metadata.open(
            "r",
            encoding="utf-8"
        ) as file:
            metadata = json.load(file)

        with manifest_file.open(
            "r",
            encoding="utf-8"
        ) as file:
            manifest = json.load(file)

    except (OSError, json.JSONDecodeError) as exc:
        raise EnvironmentError(
            f"Unable to read runtime workspace metadata: {exc}"
        ) from exc

    if not isinstance(metadata, dict):
        raise EnvironmentError("Invalid workspace metadata.")

    if not isinstance(manifest, dict):
        raise EnvironmentError("Invalid runtime manifest.")

    if metadata.get("runtime_key") != runtime_key:
        raise EnvironmentError(
            "Workspace runtime key does not match."
        )

    if manifest.get("status") != "READY":
        raise EnvironmentError(
            "Runtime manifest is not ready."
        )

    
    if metadata.get("workspace_status") != "ready":
        raise EnvironmentError(
            "Runtime workspace is not ready."
        )

    return workspace


def create_runtime_environment(
    runtime_key: str,
    workspace_path: str | Path,
    python_version: str
) -> dict[str, Any]:
    """
    Create a Python virtual environment without pip.

    Returns a JSON-serializable result.
    """

    staging_path: Path | None = None

    try:
        _validate_python_version(python_version)

        workspace = _validate_workspace(
            runtime_key,
            workspace_path
        )

        environment_root = workspace / "environment"

        if not environment_root.exists():
            raise EnvironmentError(
                "Runtime environment directory is missing."
            )

        environment_root = environment_root.resolve()

        if not environment_root.is_dir():
            raise EnvironmentError(
                "Runtime environment path is not a directory."
            )

        target_path = environment_root / "python"

        if target_path.exists():
            raise EnvironmentError(
                "A Python environment already exists for this runtime."
            )

        staging_path = Path(
            tempfile.mkdtemp(
                prefix=".python-staging-",
                dir=str(environment_root)
            )
        )

        staging_path.rmdir()

        builder = venv.EnvBuilder(
            system_site_packages=False,
            clear=False,
            symlinks=(os.name != "nt"),
            with_pip=False
        )

        builder.create(str(staging_path))

        if os.name == "nt":
            python_executable = (
                staging_path / "Scripts" / "python.exe"
            )
        else:
            python_executable = staging_path / "bin" / "python"

        if not python_executable.is_file():
            raise EnvironmentError(
                "The virtual environment Python executable was not created."
            )

        pyvenv_config = staging_path / "pyvenv.cfg"

        if not pyvenv_config.is_file():
            raise EnvironmentError(
                "The virtual environment configuration file is missing."
            )

        environment_metadata = {
            "runtime_key": runtime_key,
            "environment_type": "python_venv",
            "python_version": python_version,
            "pip_installed": False,
            "system_site_packages": False,
            "created_by": "custom_runtime_environment_manager"
        }

        metadata_path = staging_path / "environment.json"

        with metadata_path.open(
            "w",
            encoding="utf-8"
        ) as file:
            json.dump(
                environment_metadata,
                file,
                indent=4
            )

        if os.name != "nt":
            os.chmod(staging_path, 0o700)
            os.chmod(metadata_path, 0o600)

        staging_path.rename(target_path)
        staging_path = None

        if os.name == "nt":
            python_executable = (
                target_path / "Scripts" / "python.exe"
            )
        else:
            python_executable = target_path / "bin" / "python"

        return {
            "success": True,
            "status": "created",
            "runtime_key": runtime_key,
            "environment_type": "python_venv",
            "python_version": python_version,
            "environment_path": str(target_path),
            "python_executable": str(python_executable),
            "pip_installed": False,
            "message": (
                "Python virtual environment created successfully. "
                "No customer dependencies were installed."
            )
        }

    except Exception as exc:
        if staging_path is not None:
            try:
                if staging_path.exists():
                    shutil.rmtree(staging_path)
            except OSError:
                pass

        return {
            "success": False,
            "status": "failed",
            "message": str(exc)
        }
