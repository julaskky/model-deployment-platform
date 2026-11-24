"""
Installs a previously verified runtime wheelhouse into an isolated
Python runtime environment.

Security properties:
- Verifies the wheelhouse before installation.
- Uses only the runtime environment's Python executable.
- Uses only local wheel files.
- Disables network package access.
- Installs exact locked wheel files.
- Does not resolve dependencies from the internet.
- Does not execute customer model code.
- Verifies installed package versions after installation.

This is dependency installation, not an OS-level security boundary.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
from pathlib import Path
from typing import Any

from wheelhouse_verify import verify_wheelhouse


class DependencyInstallationError(Exception):
    """Raised when controlled dependency installation fails."""


PACKAGE_NAME_PATTERN = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9._-]*$"
)

VERSION_PATTERN = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9.+!_-]*$"
)


def _run_command(
    command: list[str],
    *,
    cwd: Path | None = None,
    timeout: int = 600
) -> subprocess.CompletedProcess[str]:
    """
    Run a subprocess without invoking a shell.

    Customer-supplied values must never become shell commands.
    """

    try:
        return subprocess.run(
            command,
            cwd=str(cwd) if cwd else None,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
            shell=False,
            timeout=timeout
        )

    except subprocess.TimeoutExpired as exc:
        raise DependencyInstallationError(
            f"Command timed out after {timeout} seconds."
        ) from exc

    except OSError as exc:
        raise DependencyInstallationError(
            f"Unable to execute runtime Python command: {exc}"
        ) from exc


def _validate_package_entry(package: dict[str, Any]) -> tuple[str, str, str]:
    """
    Validate a lockfile package entry.
    """

    name = package.get("name")
    version = package.get("version")
    filename = package.get("filename")

    if not all(
        isinstance(value, str)
        for value in (name, version, filename)
    ):
        raise DependencyInstallationError(
            "Invalid package entry in dependency lockfile."
        )

    if not PACKAGE_NAME_PATTERN.fullmatch(name):
        raise DependencyInstallationError(
            f"Unsafe package name in dependency lockfile: {name}"
        )

    if not VERSION_PATTERN.fullmatch(version):
        raise DependencyInstallationError(
            f"Unsafe package version in dependency lockfile: {version}"
        )

    if (
        "/" in filename
        or "\\" in filename
        or filename in (".", "..")
    ):
        raise DependencyInstallationError(
            f"Unsafe wheel filename in dependency lockfile: {filename}"
        )

    if not filename.lower().endswith(".whl"):
        raise DependencyInstallationError(
            f"Dependency lockfile references a non-wheel file: {filename}"
        )

    return name, version, filename


def _read_lockfile(lockfile_path: Path) -> dict[str, Any]:
    """
    Read and validate the dependency lockfile structure.
    """

    try:
        with lockfile_path.open(
            "r",
            encoding="utf-8"
        ) as file:
            lock = json.load(file)

    except (OSError, json.JSONDecodeError) as exc:
        raise DependencyInstallationError(
            f"Unable to read dependency lockfile: {exc}"
        ) from exc

    if not isinstance(lock, dict):
        raise DependencyInstallationError(
            "Dependency lockfile must contain a JSON object."
        )

    if lock.get("lock_version") != "1.0":
        raise DependencyInstallationError(
            "Unsupported dependency lockfile version."
        )

    if lock.get("integrity_algorithm") != "sha256":
        raise DependencyInstallationError(
            "Unsupported dependency integrity algorithm."
        )

    packages = lock.get("packages")

    if not isinstance(packages, list) or not packages:
        raise DependencyInstallationError(
            "Dependency lockfile contains no packages."
        )

    return lock


def _ensure_python_environment(python_executable: Path) -> None:
    """
    Confirm that the target Python executable exists and is usable.
    """

    if not python_executable.is_file():
        raise DependencyInstallationError(
            f"Runtime Python executable does not exist: "
            f"{python_executable}"
        )

    result = _run_command(
        [
            str(python_executable),
            "--version"
        ],
        timeout=30
    )

    if result.returncode != 0:
        raise DependencyInstallationError(
            "Runtime Python executable could not be started.\n"
            f"stdout: {result.stdout.strip()}\n"
            f"stderr: {result.stderr.strip()}"
        )


def _ensure_pip(
    python_executable: Path
) -> dict[str, Any]:
    """
    Ensure pip exists inside the isolated runtime.

    Uses the Python environment's bundled ensurepip only.
    No network access is used.
    """

    pip_check = _run_command(
        [
            str(python_executable),
            "-m",
            "pip",
            "--version"
        ],
        timeout=60
    )

    if pip_check.returncode == 0:
        return {
            "status": "already_available",
            "output": pip_check.stdout.strip()
        }

    ensurepip = _run_command(
        [
            str(python_executable),
            "-m",
            "ensurepip",
            "--upgrade"
        ],
        timeout=300
    )

    if ensurepip.returncode != 0:
        raise DependencyInstallationError(
            "Unable to bootstrap pip using ensurepip.\n"
            f"stdout: {ensurepip.stdout.strip()}\n"
            f"stderr: {ensurepip.stderr.strip()}"
        )

    verify_pip = _run_command(
        [
            str(python_executable),
            "-m",
            "pip",
            "--version"
        ],
        timeout=60
    )

    if verify_pip.returncode != 0:
        raise DependencyInstallationError(
            "pip bootstrap completed but pip is still unavailable.\n"
            f"stdout: {verify_pip.stdout.strip()}\n"
            f"stderr: {verify_pip.stderr.strip()}"
        )

    return {
        "status": "bootstrapped",
        "output": verify_pip.stdout.strip()
    }


def _install_locked_wheels(
    python_executable: Path,
    wheelhouse: Path,
    packages: list[dict[str, Any]]
) -> dict[str, Any]:
    """
    Install only the wheel files listed in the lockfile.

    --no-index prevents package retrieval from package indexes.
    --no-deps prevents pip from resolving anything outside the lock.
    """

    wheel_paths: list[str] = []

    for package in packages:
        _, _, filename = _validate_package_entry(package)

        wheel_path = wheelhouse / filename

        if not wheel_path.is_file():
            raise DependencyInstallationError(
                f"Locked wheel is missing: {filename}"
            )

        wheel_paths.append(str(wheel_path))

    command = [
        str(python_executable),
        "-m",
        "pip",
        "install",
        "--no-index",
        "--no-deps",
        "--disable-pip-version-check",
        *wheel_paths
    ]

    result = _run_command(
        command,
        cwd=wheelhouse,
        timeout=1200
    )

    if result.returncode != 0:
        raise DependencyInstallationError(
            "Locked wheel installation failed.\n"
            f"stdout: {result.stdout.strip()}\n"
            f"stderr: {result.stderr.strip()}"
        )

    return {
        "stdout": result.stdout.strip(),
        "stderr": result.stderr.strip()
    }


def _verify_installed_packages(
    python_executable: Path,
    packages: list[dict[str, Any]]
) -> list[dict[str, str]]:
    """
    Verify every locked package has the exact expected version.
    """

    package_names: list[str] = []

    for package in packages:
        name, _, _ = _validate_package_entry(package)
        package_names.append(name)

    script = r"""
import importlib.metadata
import json
import sys

names = json.loads(sys.argv[1])
result = []

for name in names:
    try:
        version = importlib.metadata.version(name)
    except importlib.metadata.PackageNotFoundError:
        print(
            json.dumps({
                "success": False,
                "error": f"Package not installed: {name}"
            })
        )
        raise SystemExit(2)

    result.append({
        "name": name,
        "version": version
    })

print(json.dumps({
    "success": True,
    "packages": result
}))
"""

    result = _run_command(
        [
            str(python_executable),
            "-c",
            script,
            json.dumps(package_names)
        ],
        timeout=120
    )

    if result.returncode != 0:
        raise DependencyInstallationError(
            "Installed package verification failed.\n"
            f"stdout: {result.stdout.strip()}\n"
            f"stderr: {result.stderr.strip()}"
        )

    try:
        payload = json.loads(result.stdout.strip())
    except json.JSONDecodeError as exc:
        raise DependencyInstallationError(
            "Runtime returned invalid package verification output."
        ) from exc

    if not isinstance(payload, dict) or payload.get("success") is not True:
        raise DependencyInstallationError(
            "Installed package verification reported failure."
        )

    installed = payload.get("packages")

    if not isinstance(installed, list):
        raise DependencyInstallationError(
            "Installed package verification returned invalid data."
        )

    expected_versions = {
        package["name"].lower(): package["version"]
        for package in packages
    }

    verified: list[dict[str, str]] = []

    for item in installed:
        name = item.get("name")
        version = item.get("version")

        if not isinstance(name, str) or not isinstance(version, str):
            raise DependencyInstallationError(
                "Installed package verification returned an invalid entry."
            )

        expected = expected_versions.get(name.lower())

        if expected is None:
            raise DependencyInstallationError(
                f"Unexpected installed package reported: {name}"
            )

        if version != expected:
            raise DependencyInstallationError(
                f"Package version mismatch for {name}: "
                f"expected {expected}, found {version}"
            )

        verified.append({
            "name": name,
            "version": version
        })

    if len(verified) != len(packages):
        raise DependencyInstallationError(
            "Installed package count does not match the dependency lock."
        )

    return verified


def install_locked_dependencies(
    python_executable: str | Path,
    wheelhouse_path: str | Path,
    lockfile_path: str | Path
) -> dict[str, Any]:
    """
    Perform controlled installation from a verified wheelhouse.

    Installation is allowed only after wheelhouse integrity verification
    succeeds.
    """

    python_path = Path(python_executable).resolve()
    wheelhouse = Path(wheelhouse_path).resolve()
    lockfile = Path(lockfile_path).resolve()

    _ensure_python_environment(python_path)

    if not wheelhouse.is_dir():
        raise DependencyInstallationError(
            "Wheelhouse directory does not exist."
        )

    if not lockfile.is_file():
        raise DependencyInstallationError(
            "Dependency lockfile does not exist."
        )

    # Load the lock before verification so the package list used for
    # installation is exactly the package list that was verified.
    lock = _read_lockfile(lockfile)
    packages = lock["packages"]

    # CRITICAL SECURITY GATE:
    # No installation may happen unless every wheel passes integrity
    # verification.
    verification = verify_wheelhouse(
        wheelhouse,
        lockfile
    )

    if not verification.get("success"):
        raise DependencyInstallationError(
            "Wheelhouse integrity verification failed. "
            "Installation has been blocked."
        )

    if (
        verification.get("verified_count")
        != verification.get("expected_count")
    ):
        raise DependencyInstallationError(
            "Wheelhouse verification count mismatch. "
            "Installation has been blocked."
        )

    pip_status = _ensure_pip(python_path)

    installation = _install_locked_wheels(
        python_path,
        wheelhouse,
        packages
    )

    installed_packages = _verify_installed_packages(
        python_path,
        packages
    )

    return {
        "success": True,
        "status": "installed",
        "python_executable": str(python_path),
        "wheelhouse": str(wheelhouse),
        "lockfile": str(lockfile),
        "verification": {
            "status": verification.get("status"),
            "verified_count": verification.get("verified_count"),
            "expected_count": verification.get("expected_count")
        },
        "pip": pip_status,
        "installed_count": len(installed_packages),
        "installed_packages": installed_packages,
        "installation_authorized": True,
        "network_access": False,
        "message": (
            "All locked wheels passed integrity verification and "
            "were installed into the isolated runtime environment. "
            "Installed package versions were verified."
        ),
        "pip_output": installation
    }


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description=(
            "Install verified locked dependencies into a runtime "
            "Python environment."
        )
    )

    parser.add_argument(
        "--python",
        required=True,
        help="Path to the runtime Python executable."
    )

    parser.add_argument(
        "--wheelhouse",
        required=True,
        help="Path to the verified wheelhouse."
    )

    parser.add_argument(
        "--lockfile",
        required=True,
        help="Path to dependency-lock.json."
    )

    args = parser.parse_args()

    try:
        result = install_locked_dependencies(
            python_executable=args.python,
            wheelhouse_path=args.wheelhouse,
            lockfile_path=args.lockfile
        )

        print(json.dumps(result, indent=4))

    except DependencyInstallationError as exc:
        print(
            json.dumps(
                {
                    "success": False,
                    "status": "failed",
                    "message": str(exc),
                    "installation_authorized": False
                },
                indent=4
            )
        )

        raise SystemExit(1)