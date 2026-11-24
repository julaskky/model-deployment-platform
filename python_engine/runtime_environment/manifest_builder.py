
"""
ML Model Runtime Manifest Builder
=================================

Builds a deployment manifest from saved static inspection results.

Security:
- Does not open or extract uploaded ZIP archives.
- Does not import or execute model code.
- Does not load model artifacts.
- Does not install dependencies.
- Does not execute user-supplied commands.

The resulting manifest is configuration data for a later,
isolated Docker runtime preparation stage.
"""

import re

from pathlib import PurePosixPath


SUPPORTED_FRAMEWORKS = {
    "scikit-learn",
    "xgboost",
    "lightgbm",
    "pytorch",
    "tensorflow",
    "keras",
    "onnx",
    "custom",
}

MANIFEST_VERSION = "1.0"


class RuntimeManifestError(ValueError):
    """Raised when inspection data cannot produce a valid manifest."""


def validate_identifier(value, field_name):
    """Validate positive database identifiers."""

    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise RuntimeManifestError(
            f"{field_name} must be a positive integer."
        )

    return value


def validate_python_version(value):
    """Accept an explicit Python major.minor version."""

    if not isinstance(value, str):
        raise RuntimeManifestError(
            "A Python version must be supplied explicitly."
        )

    value = value.strip()

    if not re.fullmatch(r"\d+\.\d+", value):
        raise RuntimeManifestError(
            "Python version must use the format MAJOR.MINOR, "
            "for example 3.11."
        )

    return value


def validate_package_path(value, field_name):
    """Ensure a package path is a safe relative POSIX path."""

    if not isinstance(value, str) or not value.strip():
        raise RuntimeManifestError(
            f"{field_name} must be a non-empty string."
        )

    normalized = value.strip().replace("\\", "/")

    if normalized.startswith("/") or re.match(
        r"^[a-zA-Z]:", normalized
    ):
        raise RuntimeManifestError(
            f"{field_name} cannot be an absolute path."
        )

    path = PurePosixPath(normalized)

    if (
        not path.parts
        or ".." in path.parts
        or "." in path.parts
        or "\x00" in normalized
    ):
        raise RuntimeManifestError(
            f"{field_name} contains an unsafe path."
        )

    return normalized


def find_inspected_file(path, files):
    """Check whether a path exists in the inspection file listing."""

    path_key = path.casefold()

    return any(
        isinstance(item, dict)
        and isinstance(item.get("path"), str)
        and item["path"].casefold() == path_key
        for item in files
    )


def validate_declared_file(path, field_name, files):
    """Validate a declared file against inspected archive contents."""

    safe_path = validate_package_path(path, field_name)

    if not find_inspected_file(safe_path, files):
        raise RuntimeManifestError(
            f"{field_name} was not found in the inspected package: "
            f"{safe_path}"
        )

    return safe_path


def normalize_dependencies(requirements):
    """Convert inspected requirements into manifest dependencies."""

    if not isinstance(requirements, list):
        raise RuntimeManifestError(
            "Inspection requirements must be a list."
        )

    dependencies = []

    for index, item in enumerate(requirements):

        if not isinstance(item, dict):
            raise RuntimeManifestError(
                f"Invalid dependency entry at index {index}."
            )

        name = item.get("name")
        declaration = item.get("declaration")

        if not isinstance(name, str) or not name.strip():
            raise RuntimeManifestError(
                f"Dependency name is missing at index {index}."
            )

        if (
            not isinstance(declaration, str)
            or not declaration.strip()
        ):
            raise RuntimeManifestError(
                f"Dependency declaration is missing at index {index}."
            )

        dependencies.append({
            "name": name.strip(),
            "declaration": declaration.strip(),
        })

    return dependencies


def build_runtime_manifest(
    inspection,
    model_id,
    model_version_id,
    python_version,
):
    """
    Generate a deployment manifest from saved inspection results.

    Parameters:
        inspection:
            Dictionary containing the saved inspection result.

        model_id:
            Database ID of the parent model.

        model_version_id:
            Database ID of the selected model version.

        python_version:
            Explicit Python version, e.g. "3.11".

    Returns:
        A dictionary containing success, status, manifest or errors.
    """

    try:
        model_id = validate_identifier(model_id, "model_id")

        model_version_id = validate_identifier(
            model_version_id,
            "model_version_id",
        )

        python_version = validate_python_version(python_version)

        if not isinstance(inspection, dict):
            raise RuntimeManifestError(
                "Inspection data must be a JSON object."
            )

        if inspection.get("success") is not True:
            raise RuntimeManifestError(
                "The model package inspection was not successful."
            )

        if inspection.get("status") != "READY":
            raise RuntimeManifestError(
                "Only model versions with READY inspection status "
                "can receive a runtime manifest."
            )

        framework = inspection.get("framework")

        if framework not in SUPPORTED_FRAMEWORKS:
            raise RuntimeManifestError(
                "The inspected framework is not supported."
            )

        metadata = inspection.get("metadata")
        files = inspection.get("files")
        requirements = inspection.get("requirements")

        if not isinstance(metadata, dict):
            raise RuntimeManifestError(
                "Valid model metadata is required."
            )

        if not isinstance(files, list):
            raise RuntimeManifestError(
                "The inspected package file listing is missing."
            )

        metadata_framework = metadata.get("framework")

        if not isinstance(metadata_framework, str):
            raise RuntimeManifestError(
                "The metadata framework is missing."
            )

        metadata_framework = metadata_framework.strip().lower()

        framework_aliases = {
            "sklearn": "scikit-learn",
            "scikit_learn": "scikit-learn",
            "scikit-learn": "scikit-learn",
            "torch": "pytorch",
            "pytorch": "pytorch",
            "tf": "tensorflow",
            "tensorflow": "tensorflow",
            "keras": "keras",
            "xgboost": "xgboost",
            "lightgbm": "lightgbm",
            "onnx": "onnx",
            "custom": "custom",
            "custom-python": "custom",
            "python": "custom",
        }

        normalized_metadata_framework = framework_aliases.get(
            metadata_framework
        )

        if normalized_metadata_framework != framework:
            raise RuntimeManifestError(
                "The inspection framework and metadata framework "
                "do not match."
            )

        model_name = metadata.get("model_name")

        if not isinstance(model_name, str) or not model_name.strip():
            raise RuntimeManifestError(
                "A valid model_name is required."
            )

        task = metadata.get("task")

        if not isinstance(task, str) or not task.strip():
            raise RuntimeManifestError(
                "A valid model task is required."
            )

        model_file = validate_declared_file(
            metadata.get("model_file"),
            "model_file",
            files,
        )

        entry_point = None

        if framework != "onnx":
            entry_point = validate_declared_file(
                metadata.get("entry_point"),
                "entry_point",
                files,
            )

            if not entry_point.lower().endswith(".py"):
                raise RuntimeManifestError(
                    "The Python entry point must reference a .py file."
                )

        model_loader = metadata.get("model_loader")

        if model_loader is not None:
            model_loader = validate_declared_file(
                model_loader,
                "model_loader",
                files,
            )

        prediction_function = metadata.get(
            "prediction_function"
        )

        if prediction_function is not None:
            if (
                not isinstance(prediction_function, str)
                or not re.fullmatch(
                    r"[A-Za-z_][A-Za-z0-9_]*",
                    prediction_function,
                )
            ):
                raise RuntimeManifestError(
                    "prediction_function must be a valid Python "
                    "function identifier."
                )

        input_format = metadata.get("input_format", "json")
        output_format = metadata.get("output_format", "json")

        if not isinstance(input_format, str) or not input_format.strip():
            raise RuntimeManifestError(
                "A valid input_format is required."
            )

        if not isinstance(output_format, str) or not output_format.strip():
            raise RuntimeManifestError(
                "A valid output_format is required."
            )

        dependencies = normalize_dependencies(requirements)

        framework_version = metadata.get("framework_version")

        if framework_version is not None and not isinstance(
            framework_version, str
        ):
            raise RuntimeManifestError(
                "framework_version must be a string."
            )

        warnings = inspection.get("warnings", [])

        if not isinstance(warnings, list):
            warnings = []

        inference_configuration = {
            "task": task.strip(),
            "algorithm": metadata.get("algorithm"),
            "model_file": model_file,
            "entry_point": entry_point,
            "model_loader": model_loader,
            "prediction_function": prediction_function,
            "input_format": input_format.strip(),
            "output_format": output_format.strip(),
            "input_schema": metadata.get("input_schema"),
            "output_classes": metadata.get("output_classes"),
        }

        manifest = {
            "manifest_version": MANIFEST_VERSION,
            "status": "READY",

            "model": {
                "model_id": model_id,
                "model_version_id": model_version_id,
                "name": model_name.strip(),
            },

            "runtime": {
                "framework": framework,
                "framework_version": framework_version,
                "python_version": python_version,
            },

            "dependencies": dependencies,

            "inference": inference_configuration,

            "package": {
                "file_count": len(files),
                "files": [
                    item["path"]
                    for item in files
                    if isinstance(item, dict)
                    and isinstance(item.get("path"), str)
                ],
            },

            "warnings": warnings,
        }

        return {
            "success": True,
            "status": "READY",
            "manifest": manifest,
            "errors": [],
        }

    except RuntimeManifestError as exc:

        return {
            "success": False,
            "status": "NEEDS_CONFIGURATION",
            "manifest": None,
            "errors": [str(exc)],
        }

    except Exception as exc:

        return {
            "success": False,
            "status": "FAILED",
            "manifest": None,
            "errors": [
                f"Unexpected manifest generation error: "
                f"{type(exc).__name__}: {exc}"
            ],
        }
