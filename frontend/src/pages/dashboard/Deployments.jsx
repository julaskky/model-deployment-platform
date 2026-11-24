
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  Activity,
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  Clock3,
  FileArchive,
  Layers3,
  Loader2,
  RefreshCw,
  Rocket,
  Search,
  Server,
  ShieldCheck,
  UploadCloud,
} from "lucide-react";

import "../../styles/dashboard/deployments.css";

const API_BASE =
  "http://localhost/model-deployment-platform/api";

async function readApiResponse(response) {
  const result = await response.json().catch(() => ({}));

  if (response.status === 401) {
    window.location.href = "/login";
    throw new Error("Your session has expired. Please sign in again.");
  }

  if (!response.ok || result.success === false) {
    throw new Error(
      result.message ||
      `Request failed with HTTP ${response.status}.`
    );
  }

  return result;
}

function formatDate(value) {
  if (!value) return "—";

  const date = new Date(String(value).replace(" ", "T"));

  if (Number.isNaN(date.getTime())) return "—";

  return date.toLocaleDateString("en-US", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function formatBytes(bytes) {
  const value = Number(bytes || 0);

  if (!value) return "—";
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) {
    return `${(value / 1024).toFixed(1)} KB`;
  }

  return `${(value / (1024 * 1024)).toFixed(2)} MB`;
}

function getStatus(status) {
  const value = String(status || "unknown").toLowerCase();

  const labels = {
    ready: "Ready",
    deployed: "Deployed",
    uploaded: "Uploaded",
    analyzing: "Analyzing",
    pending_upload: "Pending upload",
    needs_configuration: "Needs configuration",
    unsupported: "Unsupported",
    deployment_failed: "Deployment failed",
    failed: "Failed",
  };

  let tone = "neutral";

  if (["ready", "deployed"].includes(value)) {
    tone = "success";
  } else if (["analyzing", "uploaded"].includes(value)) {
    tone = "processing";
  } else if (value === "needs_configuration") {
    tone = "warning";
  } else if (
    ["unsupported", "deployment_failed", "failed"].includes(value)
  ) {
    tone = "danger";
  }

  return {
    label: labels[value] || value.replaceAll("_", " "),
    tone,
  };
}

function StatusBadge({ status }) {
  const item = getStatus(status);

  return (
    <span className={`deployment-status deployment-status-${item.tone}`}>
      <span className="deployment-status-dot" />
      {item.label}
    </span>
  );
}

function EmptyDeployments({ onUpload }) {
  return (
    <div className="deployments-empty">
      <div className="deployments-empty-icon">
        <Rocket size={30} />
      </div>

      <h3>No models available yet</h3>

      <p>
        Upload a trained model package to prepare your first deployment.
        Your models and their deployment readiness will appear here.
      </p>

      <button
        type="button"
        className="deployments-primary-button"
        onClick={onUpload}
      >
        <UploadCloud size={17} />
        Upload a model
      </button>
    </div>
  );
}

export default function Deployments() {
  const [models, setModels] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");

  const loadModels = useCallback(async (showLoader = true) => {
    if (showLoader) {
      setLoading(true);
    } else {
      setRefreshing(true);
    }

    setError("");

    try {
      const response = await fetch(
        `${API_BASE}/models/all.php`,
        {
          method: "GET",
          credentials: "include",
          headers: {
            Accept: "application/json",
          },
        }
      );

      const result = await readApiResponse(response);
      const rows = result.data?.models;

      setModels(Array.isArray(rows) ? rows : []);
    } catch (err) {
      setError(
        err.message || "Unable to retrieve your models."
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadModels();
  }, [loadModels]);

  const filteredModels = useMemo(() => {
    const term = search.trim().toLowerCase();

    if (!term) return models;

    return models.filter((model) =>
      [
        model.name,
        model.slug,
        model.original_name,
        model.status,
        model.version_status,
      ]
        .filter(Boolean)
        .some((value) =>
          String(value).toLowerCase().includes(term)
        )
    );
  }, [models, search]);

  const readyCount = models.filter(
    (model) =>
      String(model.status).toLowerCase() === "ready" &&
      String(model.version_status).toLowerCase() === "ready"
  ).length;

  const uploadedCount = models.filter(
    (model) =>
      String(model.upload_status).toLowerCase() === "completed"
  ).length;

  const attentionCount = models.length - readyCount;

  function openModel(modelId) {
    if (!modelId) return;

    const path = `/models/view?id=${encodeURIComponent(modelId)}`;

    window.history.pushState({}, "", path);
    window.dispatchEvent(new PopStateEvent("popstate"));
  }

  function handleUpload() {
    window.history.pushState({}, "", "/models/upload");
    window.dispatchEvent(new PopStateEvent("popstate"));
  }

  if (loading) {
    return (
      <section className="deployments-page">
        <div className="deployments-loading">
          <Loader2 size={23} className="deployments-spinner" />
          <span>Loading your deployment workspace...</span>
        </div>
      </section>
    );
  }

  return (
    <section className="deployments-page">
      <header className="deployments-header">
        <div>
          <div className="deployments-eyebrow">
            <Rocket size={15} />
            MODEL OPERATIONS
          </div>

          <h1>Deployments</h1>

          <p>
            Manage your model versions and access their runtime
            deployment controls.
          </p>
        </div>

        <button
          type="button"
          className="deployments-refresh-button"
          onClick={() => loadModels(false)}
          disabled={refreshing}
        >
          {refreshing ? (
            <Loader2 size={17} className="deployments-spinner" />
          ) : (
            <RefreshCw size={17} />
          )}

          {refreshing ? "Refreshing..." : "Refresh"}
        </button>
      </header>

      {error && (
        <div className="deployments-alert">
          <AlertCircle size={19} />
          <span>{error}</span>

          <button
            type="button"
            onClick={() => loadModels()}
          >
            Try again
          </button>
        </div>
      )}

      <div className="deployments-stats">
        <article className="deployments-stat-card">
          <div className="deployments-stat-icon blue">
            <Layers3 size={20} />
          </div>

          <div>
            <span>Total models</span>
            <strong>{models.length}</strong>
            <small>Models in your workspace</small>
          </div>
        </article>

        <article className="deployments-stat-card">
          <div className="deployments-stat-icon green">
            <CheckCircle2 size={20} />
          </div>

          <div>
            <span>Ready for deployment</span>
            <strong>{readyCount}</strong>
            <small>Models with ready versions</small>
          </div>
        </article>

        <article className="deployments-stat-card">
          <div className="deployments-stat-icon purple">
            <FileArchive size={20} />
          </div>

          <div>
            <span>Uploaded packages</span>
            <strong>{uploadedCount}</strong>
            <small>Completed model uploads</small>
          </div>
        </article>

        <article className="deployments-stat-card">
          <div className="deployments-stat-icon orange">
            <Activity size={20} />
          </div>

          <div>
            <span>Needs attention</span>
            <strong>{attentionCount}</strong>
            <small>Models not yet ready</small>
          </div>
        </article>
      </div>

      <section className="deployments-models-panel">
        <div className="deployments-panel-heading">
          <div>
            <h2>Your models</h2>
            <p>
              Select a model to inspect its runtime and manage inference.
            </p>
          </div>

          <div className="deployments-search">
            <Search size={17} />

            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search models..."
            />
          </div>
        </div>

        {models.length === 0 ? (
          <EmptyDeployments onUpload={handleUpload} />
        ) : filteredModels.length === 0 ? (
          <div className="deployments-no-results">
            <Search size={25} />
            <strong>No matching models</strong>
            <span>Try another model name or search term.</span>
          </div>
        ) : (
          <div className="deployments-model-list">
            {filteredModels.map((model) => {
              const ready =
                String(model.status).toLowerCase() === "ready" &&
                String(model.version_status).toLowerCase() === "ready";

              return (
                <article
                  className="deployment-model-card"
                  key={model.id}
                >
                  <div className="deployment-model-main">
                    <div className="deployment-model-icon">
                      <FileArchive size={21} />
                    </div>

                    <div className="deployment-model-info">
                      <h3>{model.name}</h3>

                      <p>
                        {model.original_name || "Model package unavailable"}
                      </p>

                      <div className="deployment-model-meta">
                        <span>Version {model.version}</span>
                        <span>Model #{model.id}</span>
                        <span>
                          Updated {formatDate(model.updated_at)}
                        </span>
                      </div>
                    </div>
                  </div>

                  <div className="deployment-model-state">
                    <div>
                      <span className="deployment-field-label">
                        Model status
                      </span>
                      <StatusBadge status={model.status} />
                    </div>

                    <div>
                      <span className="deployment-field-label">
                        Version status
                      </span>
                      <StatusBadge status={model.version_status} />
                    </div>
                  </div>

                  <div className="deployment-model-footer">
                    <div className="deployment-package-info">
                      <ShieldCheck size={16} />

                      <span>
                        {model.upload_status === "completed"
                          ? `Package uploaded · ${formatBytes(model.file_size)}`
                          : "Package not confirmed"}
                      </span>
                    </div>

                    <button
                      type="button"
                      className="deployment-manage-button"
                      onClick={() => openModel(model.id)}
                      disabled={!model.model_version_id}
                      title={
                        model.model_version_id
                          ? "Open runtime deployment management"
                          : "A model version is required"
                      }
                    >
                      <Server size={16} />
                      {ready ? "Manage deployment" : "View model"}
                      <ArrowRight size={16} />
                    </button>
                  </div>
                </article>
              );
            })}
          </div>
        )}
      </section>

      <div className="deployments-information">
        <div className="deployments-information-icon">
          <ShieldCheck size={21} />
        </div>

        <div>
          <strong>Runtime status is verified separately</strong>
          <p>
            Model readiness does not necessarily mean that an inference
            service is running. Open a model to inspect its runtime,
            environment, dependencies, and live health status.
          </p>
        </div>
      </div>
    </section>
  );
}
