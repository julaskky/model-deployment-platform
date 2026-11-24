
import { useRef, useState } from "react";
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle2,
  CloudUpload,
  FileArchive,
  Loader2,
  ShieldCheck,
  X,
} from "lucide-react";

import "../../styles/models/upload-model.css";

const API_BASE =
  "http://localhost/model-deployment-platform/api";

const MAX_FILE_SIZE = 512 * 1024 * 1024;

function formatBytes(bytes) {
  if (!bytes) return "0 B";

  const units = ["B", "KB", "MB", "GB"];
  const index = Math.floor(
    Math.log(bytes) / Math.log(1024)
  );

  return `${(
    bytes / Math.pow(1024, index)
  ).toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
}

function navigateTo(path) {
  window.history.pushState({}, "", path);

  window.dispatchEvent(
    new PopStateEvent("popstate")
  );
}

export default function UploadModel() {
  const inputRef = useRef(null);

  const [modelName, setModelName] = useState("");
  const [selectedFile, setSelectedFile] = useState(null);
  const [dragging, setDragging] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(null);

  const selectFile = (file) => {
    setError("");
    setSuccess(null);

    if (!file) return;

    const extension = file.name
      .split(".")
      .pop()
      ?.toLowerCase();

    if (extension !== "zip") {
      setSelectedFile(null);
      setError("Only ZIP model packages are accepted.");
      return;
    }

    if (file.size <= 0) {
      setSelectedFile(null);
      setError("The selected file is empty.");
      return;
    }

    if (file.size > MAX_FILE_SIZE) {
      setSelectedFile(null);
      setError("The model package cannot exceed 512 MB.");
      return;
    }

    setSelectedFile(file);
  };

  const handleFileChange = (event) => {
    selectFile(event.target.files?.[0] || null);
  };

  const handleDrop = (event) => {
    event.preventDefault();
    setDragging(false);

    const file = event.dataTransfer.files?.[0];

    selectFile(file);
  };

  const removeFile = () => {
    setSelectedFile(null);
    setProgress(0);

    if (inputRef.current) {
      inputRef.current.value = "";
    }
  };

  const getCsrfToken = async () => {
    const response = await fetch(
      `${API_BASE}/auth/csrf.php`,
      {
        method: "GET",
        credentials: "include",
        headers: {
          Accept: "application/json",
        },
      }
    );

    const data = await response.json();

    if (!response.ok || !data.success) {
      throw new Error(
        data.message || "Unable to obtain security token."
      );
    }

    return data.csrf_token;
  };

  const uploadModel = async () => {
    setError("");
    setSuccess(null);

    const trimmedName = modelName.trim();

    if (!trimmedName) {
      setError("Please enter a name for the model.");
      return;
    }

    if (trimmedName.length < 2) {
      setError(
        "The model name must contain at least 2 characters."
      );
      return;
    }

    if (trimmedName.length > 150) {
      setError(
        "The model name cannot exceed 150 characters."
      );
      return;
    }

    if (!selectedFile) {
      setError("Please select a model ZIP package.");
      return;
    }

    try {
      setUploading(true);
      setProgress(5);

      const csrfToken = await getCsrfToken();

      setProgress(10);

      const formData = new FormData();

      formData.append("name", trimmedName);
      formData.append("package", selectedFile);

      const xhr = new XMLHttpRequest();

      xhr.open(
        "POST",
        `${API_BASE}/models/upload.php`
      );

      xhr.withCredentials = true;

      xhr.setRequestHeader(
        "X-CSRF-Token",
        csrfToken
      );

      xhr.upload.onprogress = (event) => {
        if (!event.lengthComputable) return;

        const uploadProgress =
          10 +
          Math.round(
            (event.loaded / event.total) * 85
          );

        setProgress(
          Math.min(uploadProgress, 95)
        );
      };

      xhr.onload = () => {
        let responseData = null;

        try {
          responseData = JSON.parse(xhr.responseText);
        } catch {
          responseData = null;
        }

        if (
          xhr.status >= 200 &&
          xhr.status < 300 &&
          responseData?.success
        ) {
          setProgress(100);
          setSuccess(responseData.data);

          setSelectedFile(null);
          setModelName("");

          if (inputRef.current) {
            inputRef.current.value = "";
          }

          setUploading(false);
          return;
        }

        setError(
          responseData?.message ||
          "The model package could not be uploaded."
        );

        setUploading(false);
        setProgress(0);
      };

      xhr.onerror = () => {
        setError(
          "A network error occurred while uploading the model package."
        );

        setUploading(false);
        setProgress(0);
      };

      xhr.onabort = () => {
        setError("The upload was cancelled.");
        setUploading(false);
        setProgress(0);
      };

      xhr.send(formData);

    } catch (uploadError) {
      setError(
        uploadError instanceof Error
          ? uploadError.message
          : "The upload failed."
      );

      setUploading(false);
      setProgress(0);
    }
  };

  return (
    <div className="upload-model-page">
      <div className="upload-model-shell">

        <button
          type="button"
          className="upload-back-button"
          onClick={() => navigateTo("/dashboard")}
          disabled={uploading}
        >
          <ArrowLeft size={18} />
          Back to Dashboard
        </button>

        <header className="upload-model-header">
          <div>
            <div className="upload-eyebrow">
              MODEL MANAGEMENT
            </div>

            <h1>Upload a model</h1>

            <p>
              Package your trained model as a ZIP file
              and prepare it for automated deployment.
            </p>
          </div>

          <div className="upload-security-badge">
            <ShieldCheck size={20} />
            <span>Secure package validation</span>
          </div>
        </header>

        {error && (
          <div className="upload-alert upload-alert-error">
            <AlertCircle size={19} />

            <div>
              <strong>Upload could not continue</strong>
              <span>{error}</span>
            </div>
          </div>
        )}

        {success && (
          <div className="upload-alert upload-alert-success">
            <CheckCircle2 size={20} />

            <div>
              <strong>Model uploaded successfully</strong>

              <span>
                Version 1 has been registered and is
                ready for analysis.
              </span>
            </div>
          </div>
        )}

        <div className="upload-model-grid">

          <section className="upload-card upload-main-card">

            <div className="upload-card-heading">
              <div>
                <span className="upload-card-label">
                  MODEL PACKAGE
                </span>

                <h2>Model details</h2>
              </div>

              <div className="upload-step">01</div>
            </div>

            <div className="upload-field">
              <label htmlFor="model-name">
                Model name
              </label>

              <input
                id="model-name"
                type="text"
                value={modelName}
                onChange={(event) => {
                  setModelName(event.target.value);
                  setError("");
                  setSuccess(null);
                }}
                placeholder="e.g. Customer Churn Predictor"
                maxLength={150}
                disabled={uploading}
              />

              <span className="upload-field-help">
                Give this model a clear name that
                identifies its purpose.
              </span>
            </div>

            <div className="upload-field">
              <label>Model package</label>

              <div
                className={`upload-dropzone ${
                  dragging ? "is-dragging" : ""
                } ${
                  selectedFile ? "has-file" : ""
                }`}
                onDragOver={(event) => {
                  event.preventDefault();
                  setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                onClick={() => {
                  if (!uploading) {
                    inputRef.current?.click();
                  }
                }}
              >
                <input
                  ref={inputRef}
                  type="file"
                  accept=".zip,application/zip"
                  onChange={handleFileChange}
                  disabled={uploading}
                  hidden
                />

                {!selectedFile ? (
                  <>
                    <div className="upload-icon">
                      <CloudUpload size={30} />
                    </div>

                    <h3>
                      Drop your model package here
                    </h3>

                    <p>
                      or click to browse your computer
                    </p>

                    <span className="upload-format">
                      ZIP only · Maximum 512 MB
                    </span>
                  </>
                ) : (
                  <div className="selected-file">
                    <div className="selected-file-icon">
                      <FileArchive size={26} />
                    </div>

                    <div className="selected-file-info">
                      <strong>{selectedFile.name}</strong>

                      <span>
                        {formatBytes(selectedFile.size)}
                      </span>
                    </div>

                    {!uploading && (
                      <button
                        type="button"
                        className="remove-file"
                        onClick={(event) => {
                          event.stopPropagation();
                          removeFile();
                        }}
                        aria-label="Remove file"
                      >
                        <X size={18} />
                      </button>
                    )}
                  </div>
                )}
              </div>
            </div>

            {uploading && (
              <div className="upload-progress-section">
                <div className="upload-progress-heading">
                  <span>Uploading package</span>
                  <strong>{progress}%</strong>
                </div>

                <div className="upload-progress-track">
                  <div
                    className="upload-progress-bar"
                    style={{ width: `${progress}%` }}
                  />
                </div>

                <span className="upload-progress-help">
                  Please keep this window open while
                  the package is being transferred.
                </span>
              </div>
            )}

            <button
              type="button"
              className="upload-submit-button"
              onClick={uploadModel}
              disabled={
                uploading ||
                !modelName.trim() ||
                !selectedFile
              }
            >
              {uploading ? (
                <>
                  <Loader2
                    size={19}
                    className="upload-spinner"
                  />
                  Uploading model...
                </>
              ) : (
                <>
                  <CloudUpload size={19} />
                  Upload model
                </>
              )}
            </button>

          </section>

          <aside className="upload-side-column">

            <section className="upload-card upload-info-card">

              <div className="upload-card-heading compact">
                <div>
                  <span className="upload-card-label">
                    HOW IT WORKS
                  </span>

                  <h2>Upload pipeline</h2>
                </div>
              </div>

              <div className="upload-pipeline">

                <div className="pipeline-item">
                  <span className="pipeline-number">01</span>

                  <div>
                    <strong>Secure upload</strong>

                    <p>
                      Your ZIP package is transferred
                      into private model storage.
                    </p>
                  </div>
                </div>

                <div className="pipeline-line" />

                <div className="pipeline-item">
                  <span className="pipeline-number">02</span>

                  <div>
                    <strong>Package validation</strong>

                    <p>
                      Archive structure, paths, size,
                      integrity and safety are checked.
                    </p>
                  </div>
                </div>

                <div className="pipeline-line" />

                <div className="pipeline-item">
                  <span className="pipeline-number">03</span>

                  <div>
                    <strong>Analysis</strong>

                    <p>
                      The Python analysis engine will
                      inspect framework and dependencies
                      in the next stage.
                    </p>
                  </div>
                </div>

              </div>
            </section>

            <section className="upload-card upload-requirements-card">

              <div className="requirements-icon">
                <ShieldCheck size={22} />
              </div>

              <div>
                <h3>Package requirements</h3>

                <ul>
                  <li>ZIP archive</li>
                  <li>Maximum 512 MB</li>
                  <li>No symbolic links</li>
                  <li>No unsafe file paths</li>
                  <li>
                    Package is never executed during upload
                  </li>
                </ul>
              </div>

            </section>

          </aside>
        </div>

        {success && (
          <div className="upload-success-footer">
            <div>
              <strong>
                {success.model?.name || modelName}
              </strong>

              <span>
                Version {success.version?.version ?? 1}
                {" · "}
                Awaiting analysis
              </span>
            </div>

            <button
              type="button"
              onClick={() => navigateTo("/dashboard")}
            >
              Return to dashboard
            </button>
          </div>
        )}

      </div>
    </div>
  );
}
