
"""
Runtime dependency policy validator.

Validates manifest dependencies without installing packages,
executing package code, or accessing the network.
"""

from __future__ import annotations

import re
from typing import Any


PACKAGE_PATTERN = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$"
)

VERSION_PATTERN = re.compile(
    r"^[0-9]+(?:\.[0-9]+){1,3}$"
)

MAX_DEPENDENCIES = 100


FRAMEWORK_ALLOWLIST = {
    "scikit-learn": {
        "scikit-learn",
        "numpy",
        "scipy",
        "joblib",
        "threadpoolctl",
    },
    "xgboost": {
        "xgboost",
        "numpy",
        "scipy",
    },
    "lightgbm": {
        "lightgbm",
        "numpy",
        "scipy",
    },
    "pytorch": {
        "torch",
        "numpy",
    },
    "tensorflow": {
        "tensorflow",
        "numpy",
        "keras",
    },
    "keras": {
        "keras",
        "numpy",
        "tensorflow",
    },
    "onnx": {
        "onnxruntime",
        "numpy",
    },
}


class DependencyPolicyError(Exception):
    """Raised when dependencies violate runtime policy."""


def normalize_package_name(name: str) -> str:
    """Normalize a package name using PyPI-style normalization."""

    return re.sub(r"[-_.]+", "-", name).lower()


def validate_runtime_dependencies(
    manifest: dict[str, Any]
) -> dict[str, Any]:
    """
    Validate dependencies declared in a runtime manifest.

    Only exact package versions and framework-approved package
    names are accepted.
    """

    if not isinstance(manifest, dict):
        raise DependencyPolicyError("Invalid runtime manifest.")

    if manifest.get("status") != "READY":
        raise DependencyPolicyError(
            "Runtime manifest is not ready."
        )

    runtime = manifest.get("runtime")

    if not isinstance(runtime, dict):
        raise DependencyPolicyError(
            "Runtime configuration is missing."
        )

    framework = runtime.get("framework")

    if not isinstance(framework, str):
        raise DependencyPolicyError(
            "Runtime framework is missing."
        )

    framework = framework.strip().lower()

    if framework not in FRAMEWORK_ALLOWLIST:
        raise DependencyPolicyError(
            f"Framework '{framework}' has no approved dependency policy."
        )

    dependencies = manifest.get("dependencies")

    if not isinstance(dependencies, list):
        raise DependencyPolicyError(
            "Manifest dependencies must be a list."
        )

    if len(dependencies) > MAX_DEPENDENCIES:
        raise DependencyPolicyError(
            "Dependency count exceeds the allowed limit."
        )

    approved_packages = FRAMEWORK_ALLOWLIST[framework]

    validated = []
    seen_packages = set()

    for index, dependency in enumerate(dependencies):

        if not isinstance(dependency, dict):
            raise DependencyPolicyError(
                f"Invalid dependency entry at position {index}."
            )

        name = dependency.get("name")
        declaration = dependency.get("declaration")

        if not isinstance(name, str):
            raise DependencyPolicyError(
                f"Dependency {index} has an invalid package name."
            )

        if not isinstance(declaration, str):
            raise DependencyPolicyError(
                f"Dependency {index} has an invalid declaration."
            )

        name = name.strip()
        declaration = declaration.strip()

        if not PACKAGE_PATTERN.fullmatch(name):
            raise DependencyPolicyError(
                f"Invalid package name: {name}"
            )

        normalized_name = normalize_package_name(name)

        if normalized_name not in approved_packages:
            raise DependencyPolicyError(
                f"Package '{name}' is not approved for "
                f"framework '{framework}'."
            )

        if normalized_name in seen_packages:
            raise DependencyPolicyError(
                f"Duplicate dependency: {name}"
            )

        # Only exact, simple package==version declarations are allowed.
        # URLs, ranges, extras, markers and source references are rejected.
        expected_prefix = f"{name}=="

        if not declaration.startswith(expected_prefix):
            raise DependencyPolicyError(
                f"Dependency '{name}' must use an exact "
                "name==version declaration."
            )

        version = declaration[len(expected_prefix):]

        if not VERSION_PATTERN.fullmatch(version):
            raise DependencyPolicyError(
                f"Invalid or unpinned version for package '{name}'."
            )

        seen_packages.add(normalized_name)

        validated.append({
            "name": normalized_name,
            "version": version,
            "declaration": f"{normalized_name}=={version}",
        })

    return {
        "success": True,
        "status": "validated",
        "framework": framework,
        "dependency_count": len(validated),
        "dependencies": validated,
        "installation_authorized": False,
        "message": (
            "Dependencies passed the initial runtime policy checks. "
            "Installation still requires the platform's secure "
            "execution controls."
        ),
    }
