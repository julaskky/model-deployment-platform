
"""
ML Model Package Inspection Engine
==================================

Performs static inspection of uploaded ZIP model packages.

Security principles:
- Never extracts uploaded ZIP archives.
- Never imports uploaded Python files.
- Never executes uploaded code.
- Never loads uploaded model artifacts.
- Never installs uploaded dependencies.
- Validates archive paths, sizes, compression ratios and file types.

Supported frameworks:
- Scikit-learn
- XGBoost
- LightGBM
- PyTorch
- TensorFlow
- Keras
- ONNX
- Custom Python packages
"""

import json
import os
import re
import stat
import zipfile

from pathlib import PurePosixPath


# ============================================================
# CONFIGURATION
# ============================================================

MAX_ARCHIVE_SIZE = 512 * 1024 * 1024
MAX_ENTRIES = 5000
MAX_FILE_SIZE = 512 * 1024 * 1024
MAX_TOTAL_UNCOMPRESSED = 2 * 1024 * 1024 * 1024
MAX_COMPRESSION_RATIO = 100

MAX_METADATA_SIZE = 1024 * 1024
MAX_REQUIREMENTS_SIZE = 256 * 1024

SUPPORTED_COMPRESSION = {
    zipfile.ZIP_STORED,
    zipfile.ZIP_DEFLATED
}


# ============================================================
# FRAMEWORK DEFINITIONS
# ============================================================

FRAMEWORKS = {

    "scikit-learn": {
        "aliases": [
            "sklearn",
            "scikit_learn",
            "scikit-learn"
        ],
        "packages": [
            "scikit-learn"
        ],
        "extensions": [
            ".joblib",
            ".pkl",
            ".pickle"
        ]
    },

    "xgboost": {
        "aliases": [
            "xgboost"
        ],
        "packages": [
            "xgboost"
        ],
        "extensions": [
            ".json",
            ".ubj",
            ".model"
        ]
    },

    "lightgbm": {
        "aliases": [
            "lightgbm"
        ],
        "packages": [
            "lightgbm"
        ],
        "extensions": [
            ".txt",
            ".model"
        ]
    },

    "pytorch": {
        "aliases": [
            "pytorch",
            "torch"
        ],
        "packages": [
            "torch"
        ],
        "extensions": [
            ".pt",
            ".pth",
            ".torchscript"
        ]
    },

    "tensorflow": {
        "aliases": [
            "tensorflow",
            "tf"
        ],
        "packages": [
            "tensorflow"
        ],
        "extensions": [
            ".keras",
            ".h5",
            ".hdf5",
            ".pb"
        ]
    },

    "keras": {
        "aliases": [
            "keras"
        ],
        "packages": [
            "keras"
        ],
        "extensions": [
            ".keras",
            ".h5",
            ".hdf5"
        ]
    },

    "onnx": {
        "aliases": [
            "onnx"
        ],
        "packages": [
            "onnx",
            "onnxruntime"
        ],
        "extensions": [
            ".onnx"
        ]
    },

    "custom": {
        "aliases": [
            "custom",
            "custom-python",
            "python"
        ],
        "packages": [],
        "extensions": []
    }
}


# ============================================================
# RESULT HELPERS
# ============================================================

def make_result(
    status,
    framework=None,
    errors=None,
    warnings=None,
    metadata=None,
    files=None,
    requirements=None
):
    """
    Standard inspection response.
    """

    return {
        "success": True,
        "status": status,
        "framework": framework,
        "summary": {
            "READY": "Package passed static inspection.",
            "NEEDS_CONFIGURATION": (
                "Package requires additional configuration."
            ),
            "UNSUPPORTED": (
                "Package is invalid, unsafe or unsupported."
            )
        }.get(status, "Inspection completed."),
        "errors": errors or [],
        "warnings": warnings or [],
        "metadata": metadata,
        "files": files or [],
        "requirements": requirements or []
    }


def failure_result(message):
    """
    Result for an archive that cannot be inspected.
    """

    return {
        "success": False,
        "status": "UNSUPPORTED",
        "framework": None,
        "summary": "Package could not be inspected.",
        "errors": [message],
        "warnings": [],
        "metadata": None,
        "files": [],
        "requirements": []
    }


# ============================================================
# PATH VALIDATION
# ============================================================

def validate_archive_path(filename):
    """
    Prevent path traversal and dangerous archive paths.

    No extraction is performed, but paths are validated
    before any package information is trusted.
    """

    if not filename:
        raise ValueError("Archive contains an empty filename.")

    if "\x00" in filename:
        raise ValueError("Archive contains a null byte in a filename.")

    normalized = filename.replace("\\", "/")

    if normalized.startswith("/"):
        raise ValueError(
            f"Absolute archive path is not allowed: {filename}"
        )

    if normalized.startswith("//"):
        raise ValueError(
            f"UNC archive path is not allowed: {filename}"
        )

    if re.match(r"^[a-zA-Z]:", normalized):
        raise ValueError(
            f"Windows drive paths are not allowed: {filename}"
        )

    path = PurePosixPath(normalized)

    if ".." in path.parts:
        raise ValueError(
            f"Path traversal detected: {filename}"
        )

    if "." in path.parts:
        raise ValueError(
            f"Ambiguous archive path detected: {filename}"
        )

    if not path.parts:
        raise ValueError("Invalid archive path.")

    return normalized.rstrip("/")


# ============================================================
# FRAMEWORK NORMALIZATION
# ============================================================

def normalize_framework(value):
    """
    Convert framework aliases into canonical framework names.
    """

    if not isinstance(value, str):
        return None

    value = value.strip().lower()

    for framework, definition in FRAMEWORKS.items():

        if value in definition["aliases"]:
            return framework

    return None


# ============================================================
# ZIP ARCHIVE VALIDATION
# ============================================================

def inspect_archive_structure(zip_path):
    """
    Validate ZIP structure and return an open archive
    together with safe file information.

    The caller is responsible for closing the archive.
    No extraction is performed.
    """

    archive_size = os.path.getsize(zip_path)

    if archive_size <= 0:
        raise ValueError("Uploaded ZIP archive is empty.")

    if archive_size > MAX_ARCHIVE_SIZE:
        raise ValueError(
            "ZIP archive exceeds the maximum allowed size."
        )

    archive = zipfile.ZipFile(zip_path, "r")

    try:
        entries = archive.infolist()

        if not entries:
            raise ValueError("ZIP archive contains no files.")

        if len(entries) > MAX_ENTRIES:
            raise ValueError(
                "ZIP archive contains too many entries."
            )

        seen_paths = set()
        file_records = []
        total_uncompressed = 0

        for entry in entries:

            normalized_path = validate_archive_path(entry.filename)
            is_directory = entry.is_dir()

            # Reject duplicate paths, including case-only duplicates.
            path_key = normalized_path.casefold()

            if path_key in seen_paths:
                raise ValueError(
                    f"Duplicate archive entry: {normalized_path}"
                )

            seen_paths.add(path_key)

            if is_directory:
                continue

            if entry.flag_bits & 0x1:
                raise ValueError(
                    f"Encrypted archive entry is not allowed: "
                    f"{normalized_path}"
                )

            if entry.compress_type not in SUPPORTED_COMPRESSION:
                raise ValueError(
                    f"Unsupported compression method: "
                    f"{normalized_path}"
                )

            unix_mode = (entry.external_attr >> 16) & 0xFFFF

            if stat.S_ISLNK(unix_mode):
                raise ValueError(
                    f"Symbolic links are not allowed: "
                    f"{normalized_path}"
                )

            if entry.file_size > MAX_FILE_SIZE:
                raise ValueError(
                    f"File exceeds maximum size: {normalized_path}"
                )

            if entry.file_size < 0 or entry.compress_size < 0:
                raise ValueError(
                    f"Invalid file size information: {normalized_path}"
                )

            total_uncompressed += entry.file_size

            if total_uncompressed > MAX_TOTAL_UNCOMPRESSED:
                raise ValueError(
                    "ZIP archive exceeds the total "
                    "uncompressed size limit."
                )

            if entry.file_size > 0:

                if entry.compress_size == 0:
                    raise ValueError(
                        f"Suspicious compression ratio: {normalized_path}"
                    )

                ratio = entry.file_size / entry.compress_size

                if ratio > MAX_COMPRESSION_RATIO:
                    raise ValueError(
                        f"Suspicious compression ratio: {normalized_path}"
                    )

            file_records.append({
                "path": normalized_path,
                "size": entry.file_size,
                "compressed_size": entry.compress_size,
                "extension": PurePosixPath(
                    normalized_path
                ).suffix.lower()
            })

        return archive, file_records

    except Exception:
        archive.close()
        raise


# ============================================================
# ROOT FILE LOOKUP
# ============================================================

def find_root_file(file_records, filename):
    """
    Find a file located directly in the ZIP root.
    """

    for record in file_records:

        if record["path"].casefold() == filename.casefold():
            return record["path"]

    return None


# ============================================================
# SAFE FILE READING
# ============================================================

def read_small_file(archive, filename, maximum_size):
    """
    Read a small configuration file without extracting it.

    Reads at most maximum_size + 1 bytes.
    """

    info = archive.getinfo(filename)

    if info.file_size > maximum_size:
        raise ValueError(
            f"{filename} exceeds its permitted size."
        )

    with archive.open(info, "r") as file:

        content = file.read(maximum_size + 1)

    if len(content) > maximum_size:
        raise ValueError(
            f"{filename} exceeds its permitted size."
        )

    return content


# ============================================================
# METADATA VALIDATION
# ============================================================

def validate_metadata(metadata, file_records):
    """
    Validate metadata.json and its declared package files.
    """

    errors = []
    warnings = []

    if not isinstance(metadata, dict):
        return None, [
            "metadata.json must contain a JSON object."
        ], warnings

    framework = normalize_framework(
        metadata.get("framework")
    )

    if framework is None:
        return None, [
            "Metadata specifies an unknown or unsupported framework."
        ], warnings

    if not isinstance(metadata.get("model_name"), str):
        errors.append("model_name must be a string.")

    elif not metadata["model_name"].strip():
        errors.append("model_name cannot be empty.")

    if not isinstance(metadata.get("task"), str):
        errors.append("task must be a string.")

    elif not metadata["task"].strip():
        errors.append("task cannot be empty.")

    model_file = metadata.get("model_file")

    if not isinstance(model_file, str) or not model_file.strip():
        errors.append("metadata.json must specify model_file.")

    else:

        try:
            model_file = validate_archive_path(model_file)

            if not archive_path_exists(model_file, file_records):
                errors.append(
                    f"Declared model artifact was not found: {model_file}"
                )

            else:

                extension = PurePosixPath(
                    model_file
                ).suffix.lower()

                allowed_extensions = FRAMEWORKS[
                    framework
                ]["extensions"]

                if (
                    allowed_extensions
                    and extension not in allowed_extensions
                ):
                    warnings.append(
                        f"Model artifact extension '{extension}' "
                        f"is unusual for {framework}."
                    )

        except ValueError as exc:
            errors.append(str(exc))

    entry_point = metadata.get("entry_point")

    if framework != "onnx":

        if not isinstance(entry_point, str) or not entry_point.strip():

            errors.append(
                "metadata.json must specify a Python entry_point."
            )

        else:

            try:
                entry_point = validate_archive_path(entry_point)

                if not archive_path_exists(
                    entry_point,
                    file_records
                ):
                    errors.append(
                        f"Declared entry point was not found: "
                        f"{entry_point}"
                    )

                elif not entry_point.lower().endswith(".py"):
                    errors.append(
                        "Python entry_point must reference a .py file."
                    )

            except ValueError as exc:
                errors.append(str(exc))

    return framework, errors, warnings


def archive_path_exists(path, file_records):
    """
    Check whether an exact file exists in the archive.
    """

    return any(
        record["path"].casefold() == path.casefold()
        for record in file_records
    )


# ============================================================
# FRAMEWORK DETECTION
# ============================================================

def detect_framework(metadata, file_records, requirements):
    """
    Detect framework from metadata first, then supporting evidence.
    """

    if isinstance(metadata, dict):

        framework = normalize_framework(
            metadata.get("framework")
        )

        if framework:
            return framework

        if metadata.get("framework"):
            return None

    evidence = set()

    requirement_names = {
        item["name"] for item in requirements
    }

    for framework, definition in FRAMEWORKS.items():

        if framework == "custom":
            continue

        if requirement_names.intersection(
            definition["packages"]
        ):
            evidence.add(framework)

    all_paths = [
        record["path"].lower()
        for record in file_records
    ]

    for record in file_records:

        extension = record["extension"]

        if extension in {".joblib", ".pkl", ".pickle"}:
            evidence.add("scikit-learn")

        elif extension in {".pt", ".pth", ".torchscript"}:
            evidence.add("pytorch")

        elif extension == ".onnx":
            evidence.add("onnx")

        elif extension in {".keras", ".h5", ".hdf5"}:
            evidence.add("keras")

    if any(
        path.endswith(".ubj") or path.endswith(".model")
        for path in all_paths
    ):
        if "xgboost" in requirement_names:
            evidence.add("xgboost")

        if "lightgbm" in requirement_names:
            evidence.add("lightgbm")

    if len(evidence) == 1:
        return next(iter(evidence))

    return None


# ============================================================
# REQUIREMENTS INSPECTION
# ============================================================

REQUIREMENT_PATTERN = re.compile(
    r"^\s*([A-Za-z0-9][A-Za-z0-9._-]*)"
    r"(?:\[[A-Za-z0-9_,.-]+\])?"
    r"\s*(?:(?:===|==|~=|!=|<=|>=|<|>)\s*"
    r"[A-Za-z0-9.*+!_-]+)?"
    r"\s*$"
)


def inspect_requirements(archive, file_records):
    """
    Parse requirements.txt without installing anything.

    Installation directives, URLs and editable dependencies
    are rejected for static inspection.
    """

    warnings = []
    requirements = []

    requirements_file = find_root_file(
        file_records,
        "requirements.txt"
    )

    if requirements_file is None:

        warnings.append(
            "requirements.txt is missing."
        )

        return requirements, warnings

    try:

        content = read_small_file(
            archive,
            requirements_file,
            MAX_REQUIREMENTS_SIZE
        )

        text = content.decode("utf-8")

    except (ValueError, UnicodeDecodeError) as exc:

        warnings.append(
            f"Could not safely read requirements.txt: {exc}"
        )

        return requirements, warnings

    for line_number, line in enumerate(
        text.splitlines(),
        start=1
    ):

        line = line.strip()

        if not line or line.startswith("#"):
            continue

        if (
            line.startswith("-")
            or "://" in line
            or line.startswith("git+")
            or line.startswith(".")
        ):
            warnings.append(
                f"Unsupported dependency directive on line "
                f"{line_number}."
            )
            continue

        match = REQUIREMENT_PATTERN.match(line)

        if not match:
            warnings.append(
                f"Could not parse dependency on line {line_number}."
            )
            continue

        name = match.group(1).lower().replace("_", "-")

        requirements.append({
            "name": name,
            "declaration": line
        })

    return requirements, warnings


# ============================================================
# PACKAGE INSPECTION
# ============================================================

def inspect_package(zip_path):
    """
    Main inspection function.

    Returns a structured dictionary containing:
    - status
    - detected framework
    - metadata
    - package files
    - requirements
    - errors
    - warnings
    """

    if not isinstance(zip_path, (str, os.PathLike)):
        return failure_result(
            "Invalid ZIP file path."
        )

    zip_path = os.fspath(zip_path)

    if not os.path.isfile(zip_path):
        return failure_result(
            "Uploaded ZIP file does not exist."
        )

    try:

        archive, file_records = inspect_archive_structure(
            zip_path
        )

        with archive:

            metadata = None
            metadata_errors = []
            warnings = []

            metadata_file = find_root_file(
                file_records,
                "metadata.json"
            )

            if metadata_file:

                try:

                    content = read_small_file(
                        archive,
                        metadata_file,
                        MAX_METADATA_SIZE
                    )

                    metadata = json.loads(
                        content.decode("utf-8")
                    )

                except (
                    ValueError,
                    UnicodeDecodeError,
                    json.JSONDecodeError
                ) as exc:

                    return failure_result(
                        f"Invalid metadata.json: {exc}"
                    )

            else:

                warnings.append(
                    "metadata.json is missing."
                )

            requirements, requirement_warnings = (
                inspect_requirements(
                    archive,
                    file_records
                )
            )

            warnings.extend(requirement_warnings)

            framework = detect_framework(
                metadata,
                file_records,
                requirements
            )

            if framework is None:

                return make_result(
                    status="UNSUPPORTED",
                    errors=[
                        "Could not identify a supported model framework."
                    ],
                    warnings=warnings,
                    metadata=metadata,
                    files=file_records,
                    requirements=requirements
                )

            if framework == "custom":
                warnings.append(
                    "Custom Python packages require runtime "
                    "configuration and isolated validation."
                )

            if metadata is None:

                return make_result(
                    status="NEEDS_CONFIGURATION",
                    framework=framework,
                    warnings=warnings + [
                        "A valid metadata.json file is required."
                    ],
                    files=file_records,
                    requirements=requirements
                )

            validated_framework, metadata_errors, metadata_warnings = (
                validate_metadata(
                    metadata,
                    file_records
                )
            )

            warnings.extend(metadata_warnings)

            if validated_framework is None:

                return make_result(
                    status="UNSUPPORTED",
                    framework=framework,
                    errors=metadata_errors,
                    warnings=warnings,
                    metadata=metadata,
                    files=file_records,
                    requirements=requirements
                )

            if metadata_errors:

                return make_result(
                    status="NEEDS_CONFIGURATION",
                    framework=framework,
                    errors=metadata_errors,
                    warnings=warnings,
                    metadata=metadata,
                    files=file_records,
                    requirements=requirements
                )

            required_packages = set(
                FRAMEWORKS[framework]["packages"]
            )

            installed_packages = {
                item["name"] for item in requirements
            }

            if (
                required_packages
                and not required_packages.intersection(
                    installed_packages
                )
            ):

                warnings.append(
                    f"No explicit {framework} dependency was "
                    "found in requirements.txt."
                )

            if not requirements:

                warnings.append(
                    "No valid dependencies were detected."
                )

            if warnings:

                status = "NEEDS_CONFIGURATION"

            else:

                status = "READY"

            return make_result(
                status=status,
                framework=framework,
                errors=[],
                warnings=warnings,
                metadata=metadata,
                files=file_records,
                requirements=requirements
            )

    except zipfile.BadZipFile:

        return failure_result(
            "Uploaded file is not a valid ZIP archive."
        )

    except (
        ValueError,
        OSError,
        RuntimeError,
        zipfile.LargeZipFile
    ) as exc:

        return failure_result(
            str(exc)
        )

    except Exception as exc:

        return failure_result(
            f"Unexpected inspection error: {type(exc).__name__}: {exc}"
        )
