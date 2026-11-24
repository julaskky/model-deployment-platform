import { useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowLeft,
  CheckCircle2,
  CircleAlert,
  Clock3,
  FileArchive,
  FileCode2,
  FileJson,
  FileText,
  Package,
  Server,
  ShieldCheck,
  UploadCloud,
  X,
  XCircle,
  Loader2,
  History,
  ChevronRight,
} from "lucide-react";
import RuntimeDeploymentPanel from "../../components/models/RuntimeDeploymentPanel";
import "../../styles/models/runtime-deployment-panel.css";
import "../../styles/models/model-details.css";

const API_BASE =
  "http://localhost/model-deployment-platform/api";

const MAX_FILE_SIZE = 512 * 1024 * 1024;

function formatBytes(bytes) {
  if (!Number.isFinite(Number(bytes))) {
    return "—";
  }

  const value = Number(bytes);

  if (value < 1024) return `${value} B`;

  if (value < 1024 * 1024) {
    return `${(value / 1024).toFixed(1)} KB`;
  }

  if (value < 1024 * 1024 * 1024) {
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
  }

  return `${(value / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

function formatDate(value) {
  if (!value) return "—";

  const date = new Date(value.replace(" ", "T"));

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString();
}

function getFileIcon(extension) {
  const ext = String(extension || "").toLowerCase();

  if (ext === ".py") return <FileCode2 size={18} />;
  if (ext === ".json") return <FileJson size={18} />;
  if (ext === ".txt") return <FileText size={18} />;
  if (ext === ".zip") return <FileArchive size={18} />;

  return <Package size={18} />;
}

function StatusBadge({ status }) {
  const normalized = String(status || "").toLowerCase();

  let icon = <Clock3 size={15} />;
  let label = status || "Unknown";
  let className = "status-neutral";

  if (
    normalized === "ready" ||
    normalized === "deployed"
  ) {
    icon = <CheckCircle2 size={15} />;
    className = "status-success";
  } else if (
    normalized === "failed" ||
    normalized === "unsupported" ||
    normalized === "deployment_failed"
  ) {
    icon = <XCircle size={15} />;
    className = "status-danger";
  } else if (
    normalized === "needs_configuration"
  ) {
    icon = <CircleAlert size={15} />;
    className = "status-warning";
  } else if (
    normalized === "analyzing" ||
    normalized === "uploaded"
  ) {
    icon = <Clock3 size={15} />;
    className = "status-info";
  }

  label = String(label).replaceAll("_", " ");

  return (
    <span className={`model-status ${className}`}>
      {icon}
      <span>{label}</span>
    </span>
  );
}

function MetadataRow({ label, value }) {
  return (
    <div className="metadata-row">
      <span className="metadata-label">{label}</span>

      <span className="metadata-value">
        {value === null ||
        value === undefined ||
        value === ""
          ? "—"
          : String(value)}
      </span>
    </div>
  );
}

function navigateTo(path) {
  window.history.pushState({}, "", path);
  window.dispatchEvent(new PopStateEvent("popstate"));
}

export default function ModelDetails() {
  const inputRef = useRef(null);

  const [modelData, setModelData] = useState(null);
  const [versions, setVersions] = useState([]);
  const [selectedVersionId, setSelectedVersionId] =
    useState(null);

  const [loading, setLoading] = useState(true);
  const [versionLoading, setVersionLoading] =
    useState(false);

  const [historyLoading, setHistoryLoading] =
    useState(true);

  const [error, setError] = useState("");
  const [historyError, setHistoryError] = useState("");

  const [showUploadPanel, setShowUploadPanel] =
    useState(false);

  const [selectedFile, setSelectedFile] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);

  const [uploadError, setUploadError] = useState("");
  const [uploadSuccess, setUploadSuccess] = useState("");

  const modelId = useMemo(() => {
    const params = new URLSearchParams(window.location.search);
    return params.get("id");
  }, []);

  /*
  |--------------------------------------------------------------------------
  | Fetch helpers
  |--------------------------------------------------------------------------
  */

  async function fetchJson(url) {
    const response = await fetch(url, {
      method: "GET",
      credentials: "include",
      headers: {
        Accept: "application/json",
      },
    });

    const result = await response.json();

    if (!response.ok || !result.success) {
      throw new Error(
        result.message || "The request could not be completed."
      );
    }

    return result.data;
  }

  async function loadVersionHistory() {
    setHistoryLoading(true);
    setHistoryError("");

    try {
      const result = await fetchJson(
        `${API_BASE}/models/versions.php?id=${encodeURIComponent(
          modelId
        )}`
      );

      setVersions(result.versions || []);

      return result.versions || [];
    } catch (requestError) {
      setHistoryError(
        requestError.message ||
          "Unable to load version history."
      );

      throw requestError;
    } finally {
      setHistoryLoading(false);
    }
  }

  async function loadVersionDetails(versionId) {
    const url =
      `${API_BASE}/models/details.php?id=${encodeURIComponent(
        modelId
      )}` +
      (versionId
        ? `&version_id=${encodeURIComponent(versionId)}`
        : "");

    const result = await fetchJson(url);

    setModelData(result);
    setSelectedVersionId(result.version.id);

    return result;
  }

  /*
  |--------------------------------------------------------------------------
  | Initial loading
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    let cancelled = false;

    async function initialize() {
      if (!modelId || !/^\d+$/.test(modelId)) {
        setError("A valid model ID is required.");
        setLoading(false);
        setHistoryLoading(false);
        return;
      }

      try {
        setLoading(true);
        setError("");

        const history = await fetchJson(
          `${API_BASE}/models/versions.php?id=${encodeURIComponent(
            modelId
          )}`
        );

        if (cancelled) return;

        const versionList = history.versions || [];

        setVersions(versionList);
        setHistoryError("");
        setHistoryLoading(false);

        if (versionList.length === 0) {
          throw new Error(
            "No versions are available for this model."
          );
        }

        const latestVersionId = versionList[0].id;

        const details = await fetchJson(
          `${API_BASE}/models/details.php?id=${encodeURIComponent(
            modelId
          )}&version_id=${encodeURIComponent(
            latestVersionId
          )}`
        );

        if (cancelled) return;

        setModelData(details);
        setSelectedVersionId(details.version.id);
      } catch (requestError) {
        if (!cancelled) {
          setError(
            requestError.message ||
              "Unable to load model details."
          );
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
          setHistoryLoading(false);
        }
      }
    }

    initialize();

    return () => {
      cancelled = true;
    };
  }, [modelId]);

  /*
  |--------------------------------------------------------------------------
  | Version selection
  |--------------------------------------------------------------------------
  */

  async function selectVersion(versionId) {
    if (
      versionId === selectedVersionId ||
      versionLoading ||
      uploading
    ) {
      return;
    }

    try {
      setVersionLoading(true);
      setError("");

      await loadVersionDetails(versionId);
    } catch (requestError) {
      setError(
        requestError.message ||
          "Unable to load the selected version."
      );
    } finally {
      setVersionLoading(false);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | File validation
  |--------------------------------------------------------------------------
  */

  function selectFile(file) {
    setUploadError("");
    setUploadSuccess("");

    if (!file) return;

    const extension = file.name
      .split(".")
      .pop()
      ?.toLowerCase();

    if (extension !== "zip") {
      setSelectedFile(null);
      setUploadError(
        "Only ZIP model packages are accepted."
      );
      return;
    }

    if (file.size <= 0) {
      setSelectedFile(null);
      setUploadError(
        "The selected file is empty."
      );
      return;
    }

    if (file.size > MAX_FILE_SIZE) {
      setSelectedFile(null);
      setUploadError(
        "The model package cannot exceed 512 MB."
      );
      return;
    }

    setSelectedFile(file);
  }

  function removeSelectedFile() {
    setSelectedFile(null);
    setUploadProgress(0);

    if (inputRef.current) {
      inputRef.current.value = "";
    }
  }

  /*
  |--------------------------------------------------------------------------
  | CSRF token
  |--------------------------------------------------------------------------
  */

  async function getCsrfToken() {
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

    const result = await response.json();

    if (!response.ok || !result.success) {
      throw new Error(
        result.message ||
          "Unable to obtain security token."
      );
    }

    return result.csrf_token;
  }

  /*
  |--------------------------------------------------------------------------
  | Upload new model version
  |--------------------------------------------------------------------------
  */

  async function uploadNewVersion() {
    setUploadError("");
    setUploadSuccess("");

    if (!selectedFile) {
      setUploadError(
        "Please select a model ZIP package."
      );
      return;
    }

    try {
      setUploading(true);
      setUploadProgress(5);

      const csrfToken = await getCsrfToken();

      const formData = new FormData();

      formData.append("model_id", modelId);
      formData.append("package", selectedFile);

      setUploadProgress(10);

      const result = await new Promise(
        (resolve, reject) => {
          const xhr = new XMLHttpRequest();

          xhr.open(
            "POST",
            `${API_BASE}/models/upload_version.php`
          );

          xhr.withCredentials = true;

          xhr.setRequestHeader(
            "X-CSRF-Token",
            csrfToken
          );

          xhr.upload.onprogress = (event) => {
            if (!event.lengthComputable) return;

            const progress =
              10 +
              Math.round(
                (event.loaded / event.total) * 85
              );

            setUploadProgress(
              Math.min(progress, 95)
            );
          };

          xhr.onload = () => {
            let responseData = null;

            try {
              responseData = JSON.parse(
                xhr.responseText
              );
            } catch {
              responseData = null;
            }

            if (
              xhr.status >= 200 &&
              xhr.status < 300 &&
              responseData?.success
            ) {
              resolve(responseData);
              return;
            }

            reject(
              new Error(
                responseData?.message ||
                  "The new model version could not be uploaded."
              )
            );
          };

          xhr.onerror = () => {
            reject(
              new Error(
                "A network error occurred during upload."
              )
            );
          };

          xhr.onabort = () => {
            reject(
              new Error("The upload was cancelled.")
            );
          };

          xhr.send(formData);
        }
      );

      setUploadProgress(100);

      const newVersionId = result.data?.version?.id;

      if (!newVersionId) {
        throw new Error(
          "Upload completed, but the new version ID was not returned."
        );
      }

      /*
      |--------------------------------------------------------------------------
      | Refresh history and load newly uploaded version
      |--------------------------------------------------------------------------
      */

      setUploadSuccess(
        `Version ${result.data.version.version} uploaded successfully.`
      );

      setSelectedFile(null);

      if (inputRef.current) {
        inputRef.current.value = "";
      }

      const refreshedHistory = await fetchJson(
        `${API_BASE}/models/versions.php?id=${encodeURIComponent(
          modelId
        )}`
      );

      setVersions(refreshedHistory.versions || []);
      setHistoryError("");

      await loadVersionDetails(newVersionId);

      setShowUploadPanel(false);
    } catch (requestError) {
      setUploadError(
        requestError.message ||
          "Unable to upload the new version."
      );
    } finally {
      setUploading(false);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Navigation
  |--------------------------------------------------------------------------
  */

  function goBack() {
    window.history.back();
  }

  function goToDashboard() {
    navigateTo("/dashboard");
  }

  /*
  |--------------------------------------------------------------------------
  | Loading and error states
  |--------------------------------------------------------------------------
  */

  if (loading) {
    return (
      <div className="model-details-page">
        <div className="model-details-loading">
          <div className="loading-spinner" />

          <h2>Loading model details</h2>

          <p>
            Retrieving model versions and inspection information...
          </p>
        </div>
      </div>
    );
  }

  if (error && !modelData) {
    return (
      <div className="model-details-page">
        <div className="model-details-shell">
          <button
            type="button"
            className="details-back-button"
            onClick={goBack}
          >
            <ArrowLeft size={18} />
            Back
          </button>

          <div className="model-details-error">
            <div className="error-icon">
              <CircleAlert size={28} />
            </div>

            <h1>Unable to load model</h1>

            <p>{error}</p>

            <div className="error-actions">
              <button
                type="button"
                onClick={() => window.location.reload()}
                className="primary-action"
              >
                Try Again
              </button>

              <button
                type="button"
                onClick={goToDashboard}
                className="secondary-action"
              >
                Return to Dashboard
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (!modelData) return null;

  const {
    model,
    version,
    file,
    inspection,
  } = modelData;

  const metadata = inspection?.metadata || {};
  const files = inspection?.files || [];
  const requirements = inspection?.requirements || [];
  const errors = inspection?.errors || [];
  const warnings = inspection?.warnings || [];

  const inputSchema = metadata?.input_schema || {};
  const features = inputSchema?.features || {};
  const training = metadata?.training || {};

  return (
    <div className="model-details-page">
      <div className="model-details-shell">

        {/* Header */}

        <header className="model-details-header">
          <div className="header-navigation">
            <button
              type="button"
              className="details-back-button"
              onClick={goBack}
            >
              <ArrowLeft size={18} />
              Back
            </button>

            <span className="header-divider" />

            <button
              type="button"
              className="breadcrumb-button"
              onClick={goToDashboard}
            >
              Dashboard
            </button>

            <span className="breadcrumb-separator">
              /
            </span>

            <span>Model Details</span>
          </div>

          <div className="model-heading">
            <div className="model-heading-icon">
              <Server size={28} />
            </div>

            <div className="model-heading-content">
              <div className="model-heading-top">
                <h1>{model.name}</h1>

                <StatusBadge status={model.status} />
              </div>

              <p>
                Model version {version.version} ·{" "}
                {metadata.framework || "Unknown framework"}
              </p>
            </div>
          </div>
        </header>

        {/* Version management */}

        <section className="details-card version-management-card">
          <div className="section-heading version-heading">
            <div>
              <span className="section-eyebrow">
                Model Management
              </span>

              <h2>
                <History size={20} />
                Version History
              </h2>

              <p className="version-description">
                Browse previous model versions and inspect
                their individual package information.
              </p>
            </div>

            <button
              type="button"
              className="primary-action upload-version-button"
              onClick={() => {
                setShowUploadPanel(!showUploadPanel);
                setUploadError("");
                setUploadSuccess("");
              }}
              disabled={uploading}
            >
              {showUploadPanel ? (
                <>
                  <X size={17} />
                  Close Upload
                </>
              ) : (
                <>
                  <UploadCloud size={17} />
                  Upload New Version
                </>
              )}
            </button>
          </div>

          {historyError && (
            <div className="version-history-error">
              <CircleAlert size={17} />
              <span>{historyError}</span>

              <button
                type="button"
                onClick={loadVersionHistory}
              >
                Retry
              </button>
            </div>
          )}

          {historyLoading ? (
            <div className="version-loading">
              <Loader2
                size={19}
                className="upload-spinner"
              />
              Loading version history...
            </div>
          ) : (
            <div className="version-history-list">
              {versions.map((item) => {
                const active =
                  Number(item.id) ===
                  Number(selectedVersionId);

                return (
                  <button
                    type="button"
                    key={item.id}
                    className={`version-history-item ${
                      active ? "active" : ""
                    }`}
                    onClick={() => selectVersion(item.id)}
                    disabled={
                      versionLoading ||
                      uploading
                    }
                  >
                    <div className="version-history-icon">
                      <Package size={20} />
                    </div>

                    <div className="version-history-info">
                      <div className="version-history-title">
                        <strong>
                          Version {item.version}
                        </strong>

                        {active && (
                          <span className="current-version-label">
                            Selected
                          </span>
                        )}
                      </div>

                      <span className="version-history-date">
                        {formatDate(item.created_at)}
                      </span>

                      <span className="version-history-framework">
                        {item.inspection?.framework ||
                          "Framework not detected"}
                      </span>
                    </div>

                    <div className="version-history-status">
                      <StatusBadge
                        status={
                          item.inspection?.status ||
                          item.status
                        }
                      />
                    </div>

                    <ChevronRight
                      size={18}
                      className="version-chevron"
                    />
                  </button>
                );
              })}

              {versions.length === 0 && (
                <p className="empty-state">
                  No versions are available.
                </p>
              )}
            </div>
          )}

          {versionLoading && (
            <div className="version-switching">
              <Loader2
                size={17}
                className="upload-spinner"
              />
              Loading selected version...
            </div>
          )}

          {/* Upload panel */}

          {showUploadPanel && (
            <div className="version-upload-panel">
              <div className="version-upload-heading">
                <div>
                  <span className="section-eyebrow">
                    NEW VERSION
                  </span>

                  <h3>Upload a new model package</h3>

                  <p>
                    A new version number will be assigned
                    automatically. Your existing versions
                    will remain unchanged.
                  </p>
                </div>
              </div>

              {uploadError && (
                <div className="upload-version-alert error">
                  <CircleAlert size={18} />
                  <span>{uploadError}</span>
                </div>
              )}

              {uploadSuccess && (
                <div className="upload-version-alert success">
                  <CheckCircle2 size={18} />
                  <span>{uploadSuccess}</span>
                </div>
              )}

              <div
                className={`version-upload-dropzone ${
                  selectedFile ? "has-file" : ""
                }`}
                onDragOver={(event) => {
                  event.preventDefault();
                }}
                onDrop={(event) => {
                  event.preventDefault();

                  if (!uploading) {
                    selectFile(
                      event.dataTransfer.files?.[0]
                    );
                  }
                }}
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
                  hidden
                  disabled={uploading}
                  onChange={(event) => {
                    selectFile(
                      event.target.files?.[0]
                    );
                  }}
                />

                {!selectedFile ? (
                  <>
                    <UploadCloud size={29} />

                    <strong>
                      Drop a new ZIP package here
                    </strong>

                    <span>
                      or click to browse your computer
                    </span>

                    <small>
                      ZIP only · Maximum 512 MB
                    </small>
                  </>
                ) : (
                  <div className="version-selected-file">
                    <FileArchive size={24} />

                    <div>
                      <strong>{selectedFile.name}</strong>

                      <span>
                        {formatBytes(selectedFile.size)}
                      </span>
                    </div>

                    {!uploading && (
                      <button
                        type="button"
                        onClick={(event) => {
                          event.stopPropagation();
                          removeSelectedFile();
                        }}
                        aria-label="Remove selected file"
                      >
                        <X size={18} />
                      </button>
                    )}
                  </div>
                )}
              </div>

              {uploading && (
                <div className="version-upload-progress">
                  <div className="upload-progress-heading">
                    <span>Uploading model package</span>
                    <strong>{uploadProgress}%</strong>
                  </div>

                  <div className="upload-progress-track">
                    <div
                      className="upload-progress-bar"
                      style={{
                        width: `${uploadProgress}%`,
                      }}
                    />
                  </div>

                  <small>
                    Please keep this page open during upload
                    and inspection.
                  </small>
                </div>
              )}

              <div className="version-upload-actions">
                <button
                  type="button"
                  className="secondary-action"
                  onClick={() => {
                    setShowUploadPanel(false);
                    setUploadError("");
                    removeSelectedFile();
                  }}
                  disabled={uploading}
                >
                  Cancel
                </button>

                <button
                  type="button"
                  className="primary-action"
                  onClick={uploadNewVersion}
                  disabled={
                    uploading || !selectedFile
                  }
                >
                  {uploading ? (
                    <>
                      <Loader2
                        size={17}
                        className="upload-spinner"
                      />
                      Uploading...
                    </>
                  ) : (
                    <>
                      <UploadCloud size={17} />
                      Upload New Version
                    </>
                  )}
                </button>
              </div>
            </div>
          )}
        </section>

        {/* Overview cards */}

        <section className="overview-grid">
          <div className="overview-card">
            <div className="overview-card-icon">
              <ShieldCheck size={20} />
            </div>

            <div>
              <span>Inspection</span>

              <strong>
                {inspection?.status
                  ? inspection.status.replaceAll("_", " ")
                  : "Not inspected"}
              </strong>
            </div>
          </div>

          <div className="overview-card">
            <div className="overview-card-icon">
              <Package size={20} />
            </div>

            <div>
              <span>Framework</span>

              <strong>
                {inspection?.framework || "Unknown"}
              </strong>
            </div>
          </div>

          <div className="overview-card">
            <div className="overview-card-icon">
              <UploadCloud size={20} />
            </div>

            <div>
              <span>Package Size</span>

              <strong>
                {formatBytes(file?.file_size)}
              </strong>
            </div>
          </div>

          <div className="overview-card">
            <div className="overview-card-icon">
              <Clock3 size={20} />
            </div>

            <div>
              <span>Inspected</span>

              <strong>
                {formatDate(inspection?.inspected_at)}
              </strong>
            </div>
          </div>
        </section>

        {/* Inspection summary */}

        <section className="details-card inspection-summary-card">
          <div className="section-heading">
            <div>
              <span className="section-eyebrow">
                Static Analysis
              </span>

              <h2>Inspection Summary</h2>
            </div>

            {inspection && (
              <StatusBadge status={inspection.status} />
            )}
          </div>

          <div className="inspection-summary">
            <div className="summary-check">
              {inspection?.status === "ready" ? (
                <CheckCircle2 size={26} />
              ) : (
                <CircleAlert size={26} />
              )}
            </div>

            <div>
              <h3>
                {inspection?.summary ||
                  "No inspection summary is available."}
              </h3>

              <p>
                The uploaded package was analyzed without
                executing the submitted model code.
              </p>
            </div>
          </div>
        </section>

        {/* Main content */}

        <div className="details-columns">

          {/* Left column */}

          <div className="details-column">

            {/* Metadata */}

            <section className="details-card">
              <div className="section-heading">
                <div>
                  <span className="section-eyebrow">
                    Model Information
                  </span>

                  <h2>Model Metadata</h2>
                </div>
              </div>

              <div className="metadata-grid">
                <MetadataRow
                  label="Model Name"
                  value={metadata.model_name}
                />

                <MetadataRow
                  label="Framework"
                  value={metadata.framework}
                />

                <MetadataRow
                  label="Framework Version"
                  value={metadata.framework_version}
                />

                <MetadataRow
                  label="Task"
                  value={metadata.task}
                />

                <MetadataRow
                  label="Algorithm"
                  value={metadata.algorithm}
                />

                <MetadataRow
                  label="Entry Point"
                  value={metadata.entry_point}
                />

                <MetadataRow
                  label="Model Loader"
                  value={metadata.model_loader}
                />

                <MetadataRow
                  label="Model File"
                  value={metadata.model_file}
                />

                <MetadataRow
                  label="Prediction Function"
                  value={metadata.prediction_function}
                />

                <MetadataRow
                  label="Input Format"
                  value={metadata.input_format}
                />

                <MetadataRow
                  label="Output Format"
                  value={metadata.output_format}
                />
              </div>
            </section>

            {/* Input / Output */}

            <section className="details-card">
              <div className="section-heading">
                <div>
                  <span className="section-eyebrow">
                    Interface
                  </span>

                  <h2>Input & Output</h2>
                </div>
              </div>

              <div className="schema-block">
                <div className="schema-title">
                  Input Schema
                </div>

                <div className="schema-content">
                  <div className="schema-item">
                    <span className="schema-key">
                      features
                    </span>

                    <span className="schema-type">
                      {features.type || "—"}
                    </span>
                  </div>

                  <MetadataRow
                    label="Items"
                    value={features.items}
                  />

                  <MetadataRow
                    label="Length"
                    value={features.length}
                  />

                  {Array.isArray(features.description) && (
                    <div className="feature-list">
                      <span className="metadata-label">
                        Features
                      </span>

                      <div className="feature-tags">
                        {features.description.map(
                          (feature) => (
                            <span
                              className="feature-tag"
                              key={feature}
                            >
                              {feature}
                            </span>
                          )
                        )}
                      </div>
                    </div>
                  )}
                </div>
              </div>

              <div className="schema-block">
                <div className="schema-title">
                  Output Classes
                </div>

                <div className="class-tags">
                  {Array.isArray(metadata.output_classes) &&
                  metadata.output_classes.length > 0 ? (
                    metadata.output_classes.map((item) => (
                      <span
                        className="class-tag"
                        key={item}
                      >
                        {item}
                      </span>
                    ))
                  ) : (
                    <span className="muted-text">
                      No output classes specified.
                    </span>
                  )}
                </div>
              </div>
            </section>

            {/* Training */}

            <section className="details-card">
              <div className="section-heading">
                <div>
                  <span className="section-eyebrow">
                    Training Context
                  </span>

                  <h2>Training Information</h2>
                </div>
              </div>

              <div className="metadata-grid">
                <MetadataRow
                  label="Dataset"
                  value={training.dataset}
                />

                <MetadataRow
                  label="Test Size"
                  value={training.test_size}
                />

                <MetadataRow
                  label="Random State"
                  value={training.random_state}
                />

                <MetadataRow
                  label="Accuracy"
                  value={training.accuracy}
                />
              </div>
            </section>
          </div>

          {/* Right column */}

          <div className="details-column">

            {/* Package */}

            <section className="details-card">
              <div className="section-heading">
                <div>
                  <span className="section-eyebrow">
                    Uploaded Artifact
                  </span>

                  <h2>Package Information</h2>
                </div>
              </div>

              <div className="package-file">
                <div className="package-file-icon">
                  <FileArchive size={24} />
                </div>

                <div className="package-file-info">
                  <strong>
                    {file?.original_name || "Package unavailable"}
                  </strong>

                  <span>
                    {formatBytes(file?.file_size)}
                  </span>
                </div>
              </div>

              <div className="metadata-list">
                <MetadataRow
                  label="MIME Type"
                  value={file?.mime_type}
                />

                <MetadataRow
                  label="Upload Status"
                  value={file?.upload_status}
                />

                <MetadataRow
                  label="Uploaded"
                  value={formatDate(file?.created_at)}
                />

                <div className="metadata-row checksum-row">
                  <span className="metadata-label">
                    SHA-256
                  </span>

                  <code>
                    {file?.checksum || "—"}
                  </code>
                </div>
              </div>
            </section>

            {/* Package contents */}

            <section className="details-card">
              <div className="section-heading">
                <div>
                  <span className="section-eyebrow">
                    Archive Contents
                  </span>

                  <h2>Package Files</h2>
                </div>

                <span className="section-count">
                  {files.length}
                </span>
              </div>

              <div className="package-file-list">
                {files.length > 0 ? (
                  files.map((item) => (
                    <div
                      className="package-list-item"
                      key={item.path}
                    >
                      <div className="package-list-icon">
                        {getFileIcon(item.extension)}
                      </div>

                      <div className="package-list-info">
                        <strong>{item.path}</strong>

                        <span>
                          {formatBytes(item.size)}
                          {" · "}
                          {formatBytes(item.compressed_size)}
                          {" compressed"}
                        </span>
                      </div>

                      <span className="file-extension">
                        {item.extension || "file"}
                      </span>
                    </div>
                  ))
                ) : (
                  <p className="empty-state">
                    No package file information is available.
                  </p>
                )}
              </div>
            </section>

            {/* Requirements */}

            <section className="details-card">
              <div className="section-heading">
                <div>
                  <span className="section-eyebrow">
                    Runtime Dependencies
                  </span>

                  <h2>Requirements</h2>
                </div>

                <span className="section-count">
                  {requirements.length}
                </span>
              </div>

              <div className="requirements-list">
                {requirements.length > 0 ? (
                  requirements.map((requirement, index) => (
                    <div
                      className="requirement-item"
                      key={`${requirement.declaration}-${index}`}
                    >
                      <span className="requirement-name">
                        {requirement.name}
                      </span>

                      <code>
                        {requirement.declaration}
                      </code>
                    </div>
                  ))
                ) : (
                  <p className="empty-state">
                    No requirements were detected.
                  </p>
                )}
              </div>
            </section>
          </div>
        </div>

        {/* Validation messages */}

        {(errors.length > 0 || warnings.length > 0) && (
          <section className="details-card validation-card">
            <div className="section-heading">
              <div>
                <span className="section-eyebrow">
                  Validation
                </span>

                <h2>Inspection Messages</h2>
              </div>
            </div>

            {errors.length > 0 && (
              <div className="message-group">
                <div className="message-group-title error-title">
                  <XCircle size={18} />
                  Errors
                </div>

                {errors.map((item, index) => (
                  <div
                    className="message-item error-message"
                    key={`error-${index}`}
                  >
                    {typeof item === "string"
                      ? item
                      : JSON.stringify(item)}
                  </div>
                ))}
              </div>
            )}

            {warnings.length > 0 && (
              <div className="message-group">
                <div className="message-group-title warning-title">
                  <CircleAlert size={18} />
                  Warnings
                </div>

                {warnings.map((item, index) => (
                  <div
                    className="message-item warning-message"
                    key={`warning-${index}`}
                  >
                    {typeof item === "string"
                      ? item
                      : JSON.stringify(item)}
                  </div>
                ))}
              </div>
            )}
          </section>
        )}

 	{/* Runtime Deployment */}

        <RuntimeDeploymentPanel
          modelVersionId={selectedVersionId}
        />

        {/* Footer */}

        <footer className="model-details-footer">
          <div>
            <strong>
              Version {version.version}
            </strong>

            <span>
              Created {formatDate(version.created_at)}
            </span>
          </div>

          <button
            type="button"
            className="secondary-action"
            onClick={goToDashboard}
          >
            <ArrowLeft size={17} />
            Back to Dashboard
          </button>
        </footer>

      </div>
    </div>
  );
}
