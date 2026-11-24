
"""
Custom Runtime Workspace Manager.

Responsibilities:
- Create a private workspace for a registered model runtime.
- Validate the uploaded ZIP archive.
- Extract only files declared in the runtime manifest.
- Store the manifest in the workspace.
- Create standard runtime directories.
- Never import or execute customer code.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import stat
import tempfile
import uuid
import zipfile

from pathlib import Path, PurePosixPath
from typing import Any


MAX_ARCHIVE_SIZE = 512 * 1024 * 1024
MAX_FILE_SIZE = 512 * 1024 * 1024
MAX_TOTAL_UNCOMPRESSED = 2 * 1024 * 1024 * 1024
MAX_ENTRIES = 5000
MAX_COMPRESSION_RATIO = 100


class WorkspaceError(Exception):
    """Raised when workspace preparation fails."""


def _is_inside(path: Path, parent: Path) -> bool:
    try:
        path.relative_to(parent)
        return True
    except ValueError:
        return False


def _validate_runtime_key(runtime_key: str) -> None:
    if not re.fullmatch(r"[a-f0-9]{32}", runtime_key):
        raise WorkspaceError("Invalid runtime identifier.")


def _safe_relative_path(value: Any) -> str:
    if not isinstance(value, str) or not value.strip():
        raise WorkspaceError("Invalid package file path.")

    if "\\" in value or "\x00" in value:
        raise WorkspaceError("Unsafe package file path.")

    path = PurePosixPath(value)

    if (
        path.is_absolute()
        or value.startswith("/")
        or re.match(r"^[a-zA-Z]:", value)
        or any(part in ("", ".", "..") for part in value.split("/"))
    ):
        raise WorkspaceError("Unsafe package file path.")

    if not path.parts:
        raise WorkspaceError("Empty package file path.")

    return path.as_posix()


def _validate_manifest(manifest: dict[str, Any]) -> list[str]:
    if not isinstance(manifest, dict):
        raise WorkspaceError("Invalid runtime manifest.")

    if manifest.get("status") != "READY":
        raise WorkspaceError("Runtime manifest is not READY.")

    package = manifest.get("package")

    if not isinstance(package, dict):
        raise WorkspaceError("Runtime manifest has no package information.")

    files = package.get("files")

    if not isinstance(files, list) or not files:
        raise WorkspaceError("Runtime manifest contains no package files.")

    if len(files) > MAX_ENTRIES:
        raise WorkspaceError("Manifest contains too many package files.")

    approved_files: list[str] = []

    for item in files:
        safe_path = _safe_relative_path(item)

        if safe_path in approved_files:
            raise WorkspaceError("Duplicate file in runtime manifest.")

        approved_files.append(safe_path)

    return approved_files


def _validate_archive(
    archive: zipfile.ZipFile,
    approved_files: list[str]
) -> dict[str, zipfile.ZipInfo]:

    entries = archive.infolist()

    if len(entries) > MAX_ENTRIES:
        raise WorkspaceError("Archive contains too many entries.")

    approved_set = set(approved_files)
    found: dict[str, zipfile.ZipInfo] = {}

    total_uncompressed = 0
    seen: set[str] = set()

    for info in entries:
        raw_name = info.filename

        if not raw_name or "\x00" in raw_name or "\\" in raw_name:
            raise WorkspaceError("Archive contains an unsafe filename.")

        is_directory = info.is_dir()
        normalized_name = raw_name[:-1] if is_directory else raw_name

        if not normalized_name:
            continue

        safe_name = _safe_relative_path(normalized_name)

        if safe_name in seen:
            raise WorkspaceError("Archive contains duplicate entries.")

        seen.add(safe_name)

        unix_mode = info.external_attr >> 16

        if stat.S_ISLNK(unix_mode):
            raise WorkspaceError("Archive contains a symbolic link.")

        if info.flag_bits & 0x1:
            raise WorkspaceError("Encrypted archive entries are not supported.")

        if is_directory:
            continue

        if info.file_size > MAX_FILE_SIZE:
            raise WorkspaceError("Archive contains an oversized file.")

        total_uncompressed += info.file_size

        if total_uncompressed > MAX_TOTAL_UNCOMPRESSED:
            raise WorkspaceError("Archive exceeds the uncompressed size limit.")

        if info.file_size > 0:
            if info.compress_size == 0:
                raise WorkspaceError("Invalid archive compression metadata.")

            ratio = info.file_size / info.compress_size

            if ratio > MAX_COMPRESSION_RATIO:
                raise WorkspaceError("Archive compression ratio is too high.")

        if safe_name in approved_set:
            found[safe_name] = info

    missing = approved_set - set(found)

    if missing:
        raise WorkspaceError(
            "Manifest files are missing from the archive: "
            + ", ".join(sorted(missing))
        )

    return found


def prepare_runtime_workspace(
    runtime_key: str,
    source_zip: str,
    models_root: str,
    workspace_root: str,
    manifest: dict[str, Any]
) -> dict[str, Any]:
    """
    Prepare a workspace for one registered runtime.

    Customer files are copied as data only.
    No customer Python code is imported or executed.
    """

    _validate_runtime_key(runtime_key)

    approved_files = _validate_manifest(manifest)

    models_directory = Path(models_root).resolve()
    source_path = Path(source_zip).resolve()
    root_path = Path(workspace_root).resolve()

    if not _is_inside(source_path, models_directory):
        raise WorkspaceError("Model archive is outside private model storage.")

    if not source_path.is_file():
        raise WorkspaceError("Model archive does not exist.")

    if source_path.suffix.lower() != ".zip":
        raise WorkspaceError("Only ZIP model packages are supported.")

    if source_path.stat().st_size > MAX_ARCHIVE_SIZE:
        raise WorkspaceError("Model archive exceeds the size limit.")

    root_path.mkdir(parents=True, exist_ok=True)

    final_workspace = root_path / runtime_key

    if not _is_inside(final_workspace.resolve(), root_path):
        raise WorkspaceError("Invalid workspace destination.")

    if final_workspace.exists():
        raise WorkspaceError(
            "A workspace already exists for this runtime. "
            "Inspect or recover the existing workspace before retrying."
        )

    staging_workspace = root_path / (
        f".{runtime_key}.staging-{uuid.uuid4().hex}"
    )

    try:
        staging_workspace.mkdir(mode=0o700)

        directories = [
            "config",
            "model/artifacts",
            "environment",
            "logs",
            "runtime"
        ]

        for directory in directories:
            (staging_workspace / directory).mkdir(
                parents=True,
                exist_ok=True,
                mode=0o700
            )

        with zipfile.ZipFile(source_path, "r") as archive:

            found_files = _validate_archive(
                archive,
                approved_files
            )

            for relative_path in approved_files:

                info = found_files[relative_path]

                destination = (
                    staging_workspace
                    / "model"
                    / "artifacts"
                    / Path(*PurePosixPath(relative_path).parts)
                )

                destination.parent.mkdir(
                    parents=True,
                    exist_ok=True,
                    mode=0o700
                )

                if not _is_inside(
                    destination.resolve(),
                    (staging_workspace / "model" / "artifacts").resolve()
                ):
                    raise WorkspaceError(
                        "Extracted file destination is unsafe."
                    )

                written = 0

                with archive.open(info, "r") as source:
                    with destination.open("xb") as target:

                        while True:
                            chunk = source.read(1024 * 1024)

                            if not chunk:
                                break

                            written += len(chunk)

                            if written > MAX_FILE_SIZE:
                                raise WorkspaceError(
                                    "Extracted file exceeds the size limit."
                                )

                            target.write(chunk)

                if written != info.file_size:
                    raise WorkspaceError(
                        "Extracted file size does not match archive metadata."
                    )

        manifest_path = staging_workspace / "config" / "manifest.json"

        with manifest_path.open("x", encoding="utf-8") as handle:
            json.dump(
                manifest,
                handle,
                ensure_ascii=False,
                indent=2
            )

        metadata = {
            "runtime_key": runtime_key,
            "workspace_version": "1.0",
            "file_count": len(approved_files),
            "framework": manifest["runtime"]["framework"],
            "python_version": manifest["runtime"]["python_version"],
            "workspace_status": "ready"
        }

        metadata_path = staging_workspace / "config" / "workspace.json"

        with metadata_path.open("x", encoding="utf-8") as handle:
            json.dump(metadata, handle, indent=2)

        # Set restrictive permissions on the workspace.
        for directory, subdirectories, filenames in os.walk(staging_workspace):
            os.chmod(directory, 0o700)

            for filename in filenames:
                os.chmod(
                    Path(directory) / filename,
                    0o600
                )

        # Atomic rename prevents a partially prepared workspace
        # from appearing as a completed workspace.
        staging_workspace.rename(final_workspace)

        return {
            "success": True,
            "workspace_status": "ready",
            "runtime_key": runtime_key,
            "workspace_path": str(final_workspace),
            "relative_workspace_path": runtime_key,
            "file_count": len(approved_files),
            "directories": directories
        }

    except Exception:
        if staging_workspace.exists():
            shutil.rmtree(staging_workspace, ignore_errors=True)

        raise
