
"""
Builds a verified-integrity lockfile for a runtime wheelhouse.

This module:
- Does not install packages.
- Does not execute package code.
- Does not extract wheel archives.
- Checks wheel metadata against package identities.
- Records SHA-256 hashes.
- Verifies that direct manifest dependencies are present.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import tempfile
import zipfile

from email.parser import Parser
from pathlib import Path
from typing import Any


MAX_WHEELS = 200
MAX_WHEEL_SIZE = 250 * 1024 * 1024
MAX_TOTAL_SIZE = 1024 * 1024 * 1024
MAX_ARCHIVE_ENTRIES = 10000
MAX_METADATA_SIZE = 1024 * 1024

PACKAGE_PATTERN = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$"
)

VERSION_PATTERN = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9.!+_-]{0,99}$"
)


class WheelhouseLockError(Exception):
    """Raised when wheelhouse validation fails."""


def normalize_name(name: str) -> str:
    return re.sub(r"[-_.]+", "-", name).lower()


def calculate_sha256(path: Path) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as file:
        while True:
            chunk = file.read(1024 * 1024)

            if not chunk:
                break

            digest.update(chunk)

    return digest.hexdigest()


def read_wheel_metadata(path: Path) -> dict[str, Any]:

    try:
        with zipfile.ZipFile(path, "r") as archive:

            entries = archive.infolist()

            if len(entries) > MAX_ARCHIVE_ENTRIES:
                raise WheelhouseLockError(
                    f"Wheel contains too many entries: {path.name}"
                )

            metadata_files = [
                entry for entry in entries
                if entry.filename.endswith(".dist-info/METADATA")
            ]

            wheel_files = [
                entry for entry in entries
                if entry.filename.endswith(".dist-info/WHEEL")
            ]

            if len(metadata_files) != 1 or len(wheel_files) != 1:
                raise WheelhouseLockError(
                    f"Invalid wheel metadata structure: {path.name}"
                )

            metadata_entry = metadata_files[0]
            wheel_entry = wheel_files[0]

            if (
                metadata_entry.file_size > MAX_METADATA_SIZE
                or wheel_entry.file_size > MAX_METADATA_SIZE
            ):
                raise WheelhouseLockError(
                    f"Wheel metadata is too large: {path.name}"
                )

            metadata_text = archive.read(
                metadata_entry
            ).decode("utf-8")

            wheel_text = archive.read(
                wheel_entry
            ).decode("utf-8")

    except (OSError, zipfile.BadZipFile, UnicodeDecodeError) as exc:
        raise WheelhouseLockError(
            f"Unable to inspect wheel {path.name}: {exc}"
        ) from exc

    metadata = Parser().parsestr(metadata_text)
    wheel = Parser().parsestr(wheel_text)

    name = metadata.get("Name")
    version = metadata.get("Version")

    if not name or not version:
        raise WheelhouseLockError(
            f"Wheel has no package name or version: {path.name}"
        )

    if not PACKAGE_PATTERN.fullmatch(name):
        raise WheelhouseLockError(
            f"Invalid package name in wheel: {path.name}"
        )

    if not VERSION_PATTERN.fullmatch(version):
        raise WheelhouseLockError(
            f"Invalid package version in wheel: {path.name}"
        )

    tags = wheel.get_all("Tag") or []

    if not tags:
        raise WheelhouseLockError(
            f"Wheel has no compatibility tags: {path.name}"
        )

    requires_dist = metadata.get_all("Requires-Dist") or []

    return {
        "name": normalize_name(name),
        "version": version,
        "tags": sorted(set(tags)),
        "requires_dist": sorted(set(requires_dist)),
    }


def build_wheelhouse_lock(
    manifest: dict[str, Any],
    wheelhouse_path: str | Path,
    platform_label: str
) -> dict[str, Any]:
    """
    Validate wheelhouse contents and generate dependency-lock.json.

    Hashes provide integrity checks against subsequent modification.
    They do not independently establish publisher authenticity.
    """

    if not isinstance(manifest, dict):
        raise WheelhouseLockError("Invalid runtime manifest.")

    if manifest.get("status") != "READY":
        raise WheelhouseLockError("Runtime manifest is not ready.")

    if (
        not isinstance(platform_label, str)
        or not re.fullmatch(r"[A-Za-z0-9._-]{1,80}", platform_label)
    ):
        raise WheelhouseLockError("Invalid platform label.")

    wheelhouse = Path(wheelhouse_path).resolve()

    if not wheelhouse.exists() or not wheelhouse.is_dir():
        raise WheelhouseLockError("Wheelhouse directory does not exist.")

    wheel_paths = sorted(
        path for path in wheelhouse.iterdir()
        if path.is_file() and path.suffix.lower() == ".whl"
    )

    if not wheel_paths:
        raise WheelhouseLockError("No wheel files were found.")

    if len(wheel_paths) > MAX_WHEELS:
        raise WheelhouseLockError("Wheel count exceeds the allowed limit.")

    total_size = 0
    packages = []
    seen_packages = set()

    for wheel_path in wheel_paths:

        size = wheel_path.stat().st_size

        if size <= 0 or size > MAX_WHEEL_SIZE:
            raise WheelhouseLockError(
                f"Invalid wheel size: {wheel_path.name}"
            )

        total_size += size

        if total_size > MAX_TOTAL_SIZE:
            raise WheelhouseLockError(
                "Total wheelhouse size exceeds the allowed limit."
            )

        metadata = read_wheel_metadata(wheel_path)

        package_name = metadata["name"]

        if package_name in seen_packages:
            raise WheelhouseLockError(
                f"Duplicate package in wheelhouse: {package_name}"
            )

        seen_packages.add(package_name)

        packages.append({
            "name": package_name,
            "version": metadata["version"],
            "filename": wheel_path.name,
            "size": size,
            "sha256": calculate_sha256(wheel_path),
            "tags": metadata["tags"],
            "requires_dist": metadata["requires_dist"],
        })

    # Ensure all direct manifest dependencies are present
    # at precisely their declared versions.
    direct_dependencies = manifest.get("dependencies")

    if not isinstance(direct_dependencies, list):
        raise WheelhouseLockError(
            "Manifest dependencies must be a list."
        )

    wheel_lookup = {
        package["name"]: package
        for package in packages
    }

    direct_entries = []

    for dependency in direct_dependencies:

        if not isinstance(dependency, dict):
            raise WheelhouseLockError(
                "Invalid direct dependency entry."
            )

        name = dependency.get("name")
        declaration = dependency.get("declaration")

        if not isinstance(name, str) or not isinstance(declaration, str):
            raise WheelhouseLockError(
                "Invalid direct dependency declaration."
            )

        normalized_name = normalize_name(name)

        if declaration != f"{name}=={dependency.get('version', '')}" \
                and declaration != f"{name}=={declaration.split('==')[-1]}":
            # Exact declaration syntax is checked below.
            pass

        match = re.fullmatch(
            re.escape(name) + r"==([A-Za-z0-9][A-Za-z0-9.!+_-]{0,99})",
            declaration
        )

        if not match:
            raise WheelhouseLockError(
                f"Direct dependency is not exactly pinned: {name}"
            )

        expected_version = match.group(1)

        package = wheel_lookup.get(normalized_name)

        if package is None:
            raise WheelhouseLockError(
                f"Required direct dependency is missing: {name}"
            )

        if package["version"] != expected_version:
            raise WheelhouseLockError(
                f"Version mismatch for {name}: "
                f"expected {expected_version}, "
                f"found {package['version']}"
            )

        direct_entries.append({
            "name": normalized_name,
            "version": expected_version,
        })

    model = manifest.get("model", {})
    runtime = manifest.get("runtime", {})

    lock = {
        "lock_version": "1.0",
        "platform": platform_label,
        "model_id": model.get("model_id"),
        "model_version_id": model.get("model_version_id"),
        "framework": runtime.get("framework"),
        "python_version": runtime.get("python_version"),
        "direct_dependencies": direct_entries,
        "resolved_package_count": len(packages),
        "packages": packages,
        "integrity_algorithm": "sha256",
        "installation_authorized": False,
    }

    lock_path = wheelhouse / "dependency-lock.json"

    temporary_path = None

    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=wheelhouse,
            prefix=".dependency-lock-",
            suffix=".tmp",
            delete=False
        ) as file:

            temporary_path = Path(file.name)

            json.dump(
                lock,
                file,
                indent=4
            )

            file.flush()
            os.fsync(file.fileno())

        os.replace(temporary_path, lock_path)
        temporary_path = None

    finally:
        if temporary_path is not None:
            temporary_path.unlink(missing_ok=True)

    return {
        "success": True,
        "status": "locked",
        "lock_path": str(lock_path),
        "platform": platform_label,
        "direct_dependency_count": len(direct_entries),
        "resolved_package_count": len(packages),
        "message": (
            "Wheelhouse validated and dependency lockfile generated. "
            "Package installation has not been authorized."
        )
    }
