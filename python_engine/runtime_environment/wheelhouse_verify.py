"""
Verifies a runtime wheelhouse against its dependency lockfile.

This module:
- Does not install packages.
- Does not execute package code.
- Does not access the network.
- Recalculates SHA-256 hashes.
- Verifies package names, versions and filenames.
"""

from __future__ import annotations

import hashlib
import json
import re

from pathlib import Path
from typing import Any


class WheelhouseVerificationError(Exception):
    """Raised when wheelhouse integrity verification fails."""


SHA256_PATTERN = re.compile(r"^[a-f0-9]{64}$")


def calculate_sha256(path: Path) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as file:
        while True:
            chunk = file.read(1024 * 1024)

            if not chunk:
                break

            digest.update(chunk)

    return digest.hexdigest()


def verify_wheelhouse(
    wheelhouse_path: str | Path,
    lockfile_path: str | Path
) -> dict[str, Any]:
    """
    Verify every locked wheel against its SHA-256 hash.

    No package installation or package execution occurs.
    """

    wheelhouse = Path(wheelhouse_path).resolve()
    lockfile = Path(lockfile_path).resolve()

    if not wheelhouse.is_dir():
        raise WheelhouseVerificationError(
            "Wheelhouse directory does not exist."
        )

    if not lockfile.is_file():
        raise WheelhouseVerificationError(
            "Dependency lockfile does not exist."
        )

    try:
        with lockfile.open(
            "r",
            encoding="utf-8"
        ) as file:
            lock = json.load(file)

    except (OSError, json.JSONDecodeError) as exc:
        raise WheelhouseVerificationError(
            f"Unable to read dependency lockfile: {exc}"
        ) from exc

    if not isinstance(lock, dict):
        raise WheelhouseVerificationError(
            "Invalid dependency lockfile."
        )

    if lock.get("lock_version") != "1.0":
        raise WheelhouseVerificationError(
            "Unsupported dependency lockfile version."
        )

    if lock.get("integrity_algorithm") != "sha256":
        raise WheelhouseVerificationError(
            "Unsupported integrity algorithm."
        )

    packages = lock.get("packages")

    if not isinstance(packages, list):
        raise WheelhouseVerificationError(
            "Lockfile package list is invalid."
        )

    if not packages:
        raise WheelhouseVerificationError(
            "Lockfile contains no packages."
        )

    verified = []
    failures = []

    expected_filenames = set()

    for package in packages:

        if not isinstance(package, dict):
            failures.append({
                "reason": "Invalid package entry."
            })
            continue

        name = package.get("name")
        version = package.get("version")
        filename = package.get("filename")
        expected_hash = package.get("sha256")

        if not all(
            isinstance(value, str)
            for value in (
                name,
                version,
                filename,
                expected_hash
            )
        ):
            failures.append({
                "package": name,
                "reason": "Incomplete lockfile package entry."
            })
            continue

        if not SHA256_PATTERN.fullmatch(expected_hash):
            failures.append({
                "package": name,
                "reason": "Invalid SHA-256 hash."
            })
            continue

        if (
            "/" in filename
            or "\\" in filename
            or filename in (".", "..")
        ):
            failures.append({
                "package": name,
                "reason": "Unsafe wheel filename."
            })
            continue

        expected_filenames.add(filename)

        wheel_path = wheelhouse / filename

        if not wheel_path.is_file():
            failures.append({
                "package": name,
                "version": version,
                "filename": filename,
                "reason": "Wheel file is missing."
            })
            continue

        actual_hash = calculate_sha256(wheel_path)

        if actual_hash != expected_hash:
            failures.append({
                "package": name,
                "version": version,
                "filename": filename,
                "reason": "SHA-256 hash mismatch.",
                "expected_sha256": expected_hash,
                "actual_sha256": actual_hash
            })
            continue

        verified.append({
            "name": name,
            "version": version,
            "filename": filename,
            "sha256": actual_hash
        })

    # Detect additional wheel files that are not part of the lock.
    actual_wheels = {
        path.name
        for path in wheelhouse.iterdir()
        if path.is_file()
        and path.suffix.lower() == ".whl"
    }

    unexpected_wheels = sorted(
        actual_wheels - expected_filenames
    )

    if unexpected_wheels:
        failures.append({
            "reason": "Unexpected wheel files found.",
            "files": unexpected_wheels
        })

    if failures:
        return {
            "success": False,
            "status": "failed",
            "verified_count": len(verified),
            "expected_count": len(packages),
            "failures": failures,
            "message": (
                "Wheelhouse integrity verification failed. "
                "Installation must not proceed."
            )
        }

    return {
        "success": True,
        "status": "verified",
        "verified_count": len(verified),
        "expected_count": len(packages),
        "packages": verified,
        "installation_authorized": False,
        "message": (
            "All locked wheel files passed SHA-256 integrity "
            "verification. Installation has not yet occurred."
        )
    }