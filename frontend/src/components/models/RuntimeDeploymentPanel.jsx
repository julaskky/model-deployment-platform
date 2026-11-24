
import { useCallback, useEffect, useState } from "react";
import {
  Activity,
  AlertCircle,
  CheckCircle2,
  Clock3,
  Server,
  RefreshCw,
  Play,
  Loader2,
  Package,
  ShieldCheck,
  BrainCircuit,
  Zap,
  Send,
  Square,
  KeyRound,
  Copy,
  Ban,
} from "lucide-react";

import "../../styles/models/runtime-deployment-panel.css";

const API_BASE =
  "http://localhost/model-deployment-platform/api";

async function readApiResponse(response) {
  const responseText = await response.text();

  let result;

  try {
    result = responseText ? JSON.parse(responseText) : {};
  } catch {
    throw new Error(
      "The server returned an invalid response. Check the PHP error log."
    );
  }

  if (!response.ok || result.success === false) {
    throw new Error(
      result.message ||
      `Request failed with HTTP ${response.status}.`
    );
  }

  return result;
}

export default function RuntimeDeploymentPanel({ modelVersionId }) {
  const [runtimeData, setRuntimeData] = useState(null);
  const [health, setHealth] = useState(null);
  const [healthRefreshKey, setHealthRefreshKey] = useState(0);

  const [loading, setLoading] = useState(true);
  const [preparingDeployment, setPreparingDeployment] = useState(false);
  const [installingDependencies, setInstallingDependencies] = useState(false);
  const [starting, setStarting] = useState(false);
  const [stopping, setStopping] = useState(false);
  const [predicting, setPredicting] = useState(false);

  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const [features, setFeatures] = useState([
    "5.1",
    "3.5",
    "1.4",
    "0.2",
  ]);

  const [prediction, setPrediction] = useState(null);

  // API key management
  const [apiKeys, setApiKeys] = useState([]);
  const [apiKeysLoading, setApiKeysLoading] = useState(false);
  const [apiKeyGenerating, setApiKeyGenerating] = useState(false);
  const [revokingKeyId, setRevokingKeyId] = useState(null);
  const [apiKeyName, setApiKeyName] = useState("");
  const [newApiKey, setNewApiKey] = useState(null);
  const [apiKeyCopied, setApiKeyCopied] = useState(false);

  // --------------------------------------------------
  // CSRF TOKEN
  // --------------------------------------------------

  const getCsrfToken = useCallback(async () => {
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

    const result = await readApiResponse(response);

    const token =
      result.data?.csrf_token ||
      result.csrf_token ||
      result.data?.token ||
      result.token;

    if (!token || typeof token !== "string") {
      throw new Error(
        "The authentication API did not return a valid CSRF token."
      );
    }

    return token;
  }, []);

  // --------------------------------------------------
  // FETCH INFERENCE HEALTH
  // --------------------------------------------------

  const fetchHealth = useCallback(async (runtimeId) => {
    if (!runtimeId) {
      setHealth(null);
      return;
    }

    try {
      const response = await fetch(
        `${API_BASE}/models/runtime_health.php?runtime_id=${encodeURIComponent(
          runtimeId
        )}`,
        {
          method: "GET",
          credentials: "include",
          headers: {
            Accept: "application/json",
          },
        }
      );

      const result = await readApiResponse(response);

      setHealth(result.data || null);
    } catch {
      setHealth(null);
    }
  }, []);

  // --------------------------------------------------
  // FETCH RUNTIME STATUS
  // --------------------------------------------------

  const fetchRuntimeStatus = useCallback(
    async (showLoader = true) => {
      if (!modelVersionId) {
        setRuntimeData(null);
        setHealth(null);
        setLoading(false);
        return null;
      }

      if (showLoader) {
        setLoading(true);
      }

      setError("");
      setHealth(null);

      try {
        const response = await fetch(
          `${API_BASE}/models/runtime_status.php?model_version_id=${encodeURIComponent(
            modelVersionId
          )}`,
          {
            method: "GET",
            credentials: "include",
            headers: {
              Accept: "application/json",
            },
          }
        );

        const result = await readApiResponse(response);
        const data = result.data || null;

        setRuntimeData(data);
        setHealthRefreshKey((current) => current + 1);

        return data;
      } catch (err) {
        setRuntimeData(null);
        setHealth(null);

        setError(
          err.message || "Unable to retrieve runtime status."
        );

        return null;
      } finally {
        if (showLoader) {
          setLoading(false);
        }
      }
    },
    [modelVersionId]
  );

  // --------------------------------------------------
  // AUTOMATIC DEPLOYMENT PREPARATION
  // --------------------------------------------------

  const prepareDeployment = useCallback(async () => {
    if (!modelVersionId) {
      throw new Error("A valid model version is required.");
    }

    const modelId = new URLSearchParams(
      window.location.search
    ).get("id");

    if (!modelId || !/^\d+$/.test(modelId)) {
      throw new Error(
        "A valid model ID is required to prepare the runtime."
      );
    }

    setPreparingDeployment(true);
    setError("");
    setSuccess("");

    try {
      // Read the exact model version being displayed.
      const detailsResponse = await fetch(
        `${API_BASE}/models/details.php?id=${encodeURIComponent(
          modelId
        )}&version_id=${encodeURIComponent(modelVersionId)}`,
        {
          method: "GET",
          credentials: "include",
          headers: {
            Accept: "application/json",
          },
        }
      );

      const detailsResult = await readApiResponse(
        detailsResponse
      );

      const details = detailsResult.data || {};
      const inspection = details.inspection || {};
      const metadata = inspection.metadata || {};

      const pythonVersionCandidates = [
        inspection.runtime?.python_version,
        inspection.manifest?.runtime?.python_version,
        metadata.runtime?.python_version,
        metadata.python_version,
      ];

      const detectedPythonVersion = pythonVersionCandidates.find(
        (value) =>
          /^\d+\.\d+$/.test(String(value || "").trim())
      );

      const pythonVersion = detectedPythonVersion || "3.14";

      // Step 1: generate the runtime manifest.
      const manifestToken = await getCsrfToken();

      const manifestResponse = await fetch(
        `${API_BASE}/models/runtime_manifest.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": manifestToken,
          },
          body: JSON.stringify({
            model_id: Number(modelId),
            model_version_id: Number(modelVersionId),
            python_version: String(pythonVersion).trim(),
          }),
        }
      );

      await readApiResponse(manifestResponse);

      // Step 2: register the runtime.
      const runtimeToken = await getCsrfToken();

      const runtimeResponse = await fetch(
        `${API_BASE}/models/create_runtime.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": runtimeToken,
          },
          body: JSON.stringify({
            model_version_id: Number(modelVersionId),
          }),
        }
      );

      const runtimeResult = await readApiResponse(
        runtimeResponse
      );

      const runtimeId = Number(
        runtimeResult.data?.runtime?.id || 0
      );

      if (!runtimeId) {
        throw new Error(
          "The runtime was registered, but no runtime ID was returned."
        );
      }

      // Step 3: prepare the runtime workspace.
      const workspaceToken = await getCsrfToken();

      const workspaceResponse = await fetch(
        `${API_BASE}/models/prepare_runtime_workspace.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": workspaceToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
          }),
        }
      );

      await readApiResponse(workspaceResponse);

      // Step 4: create the isolated Python environment.
      const environmentToken = await getCsrfToken();

      const environmentResponse = await fetch(
        `${API_BASE}/models/create_runtime_environment.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": environmentToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
          }),
        }
      );

      await readApiResponse(environmentResponse);

      // Step 5: install and verify dependencies.
      const dependencyToken = await getCsrfToken();

      const dependencyResponse = await fetch(
        `${API_BASE}/models/install_runtime_dependencies.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": dependencyToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
          }),
        }
      );

      await readApiResponse(dependencyResponse);

      setSuccess(
        "Deployment runtime prepared successfully. The inference service is ready to start."
      );

      return runtimeId;
    } finally {
      setPreparingDeployment(false);
    }
  }, [modelVersionId, getCsrfToken]);

  // --------------------------------------------------
  // API KEY MANAGEMENT
  // --------------------------------------------------

  const fetchApiKeys = useCallback(async (runtimeId) => {
    if (!runtimeId) {
      setApiKeys([]);
      return;
    }

    setApiKeysLoading(true);

    try {
      const response = await fetch(
        `${API_BASE}/models/list_api_keys.php?runtime_id=${encodeURIComponent(
          runtimeId
        )}`,
        {
          method: "GET",
          credentials: "include",
          headers: {
            Accept: "application/json",
          },
        }
      );

      const result = await readApiResponse(response);
      const rows = result.data?.api_keys;

      setApiKeys(Array.isArray(rows) ? rows : []);
    } catch (err) {
      setError(err.message || "Unable to retrieve API keys.");
    } finally {
      setApiKeysLoading(false);
    }
  }, []);

  const generateApiKey = async (event) => {
    event.preventDefault();

    const runtimeId = runtimeData?.runtime?.id;

    if (!runtimeId) {
      setError("No registered runtime was found.");
      return;
    }

    const trimmedName = apiKeyName.trim();

    if (!trimmedName) {
      setError("Enter a name for this API key.");
      return;
    }

    setApiKeyGenerating(true);
    setError("");
    setSuccess("");
    setNewApiKey(null);
    setApiKeyCopied(false);

    try {
      const csrfToken = await getCsrfToken();

      const response = await fetch(
        `${API_BASE}/models/generate_api_key.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": csrfToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
            name: trimmedName,
          }),
        }
      );

      const result = await readApiResponse(response);
      const generated = result.data || {};

      if (!generated.api_key) {
        throw new Error(
          "The server did not return the newly generated API key. Check the API response."
        );
      }

      setNewApiKey(generated);
      setApiKeyName("");

      setSuccess(
        result.message || "API key generated successfully."
      );

      await fetchApiKeys(runtimeId);
    } catch (err) {
      setError(err.message || "Unable to generate an API key.");
    } finally {
      setApiKeyGenerating(false);
    }
  };

  const copyApiKey = async () => {
    if (!newApiKey?.api_key) {
      return;
    }

    try {
      await navigator.clipboard.writeText(newApiKey.api_key);

      setApiKeyCopied(true);
      setSuccess("API key copied to clipboard. Store it securely.");
    } catch {
      setError(
        "Unable to copy automatically. Select and copy the API key manually."
      );
    }
  };

  const revokeApiKey = async (apiKey) => {
    if (!apiKey?.id) {
      return;
    }

    const confirmed = window.confirm(
      `Revoke "${apiKey.name}"? Applications using this key will no longer be able to authenticate.`
    );

    if (!confirmed) {
      return;
    }

    setRevokingKeyId(apiKey.id);
    setError("");
    setSuccess("");

    try {
      const csrfToken = await getCsrfToken();

      const response = await fetch(
        `${API_BASE}/models/revoke_api_key.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": csrfToken,
          },
          body: JSON.stringify({
            api_key_id: apiKey.id,
          }),
        }
      );

      const result = await readApiResponse(response);

      setSuccess(
        result.message || "API key revoked successfully."
      );

      if (newApiKey?.id === apiKey.id) {
        setNewApiKey(null);
      }

      await fetchApiKeys(runtimeData?.runtime?.id);
    } catch (err) {
      setError(err.message || "Unable to revoke the API key.");
    } finally {
      setRevokingKeyId(null);
    }
  };

  // Load API keys when the selected runtime changes.
  useEffect(() => {
    const runtimeId = runtimeData?.runtime?.id;

    if (runtimeId) {
      fetchApiKeys(runtimeId);
    } else {
      setApiKeys([]);
      setNewApiKey(null);
    }
  }, [runtimeData?.runtime?.id, fetchApiKeys]);

  // --------------------------------------------------
  // INITIAL STATUS LOAD + AUTOMATIC PREPARATION
  // --------------------------------------------------

  useEffect(() => {
    let cancelled = false;

    async function initialiseDeployment() {
      try {
        const data = await fetchRuntimeStatus();

        // An existing runtime must not be recreated automatically.
        // A failed dependency installation is handled by the retry action.
        if (cancelled || data?.runtime?.id) {
          return;
        }

        await prepareDeployment();

        if (cancelled) {
          return;
        }

        await fetchRuntimeStatus(false);
      } catch (err) {
        if (!cancelled) {
          setError(
            err.message ||
            "Unable to prepare the deployment runtime."
          );
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    initialiseDeployment();

    return () => {
      cancelled = true;
    };
  }, [fetchRuntimeStatus, prepareDeployment]);

  // --------------------------------------------------
  // AUTOMATIC HEALTH MONITORING
  // --------------------------------------------------

  useEffect(() => {
    const runtimeId = runtimeData?.runtime?.id;

    if (!runtimeId) {
      setHealth(null);
      return undefined;
    }

    fetchHealth(runtimeId);

    const interval = window.setInterval(() => {
      fetchHealth(runtimeId);
    }, 5000);

    return () => {
      window.clearInterval(interval);
    };
  }, [
    runtimeData?.runtime?.id,
    healthRefreshKey,
    fetchHealth,
  ]);

  // --------------------------------------------------
  // DERIVED RUNTIME DATA
  // --------------------------------------------------

  const runtime = runtimeData?.runtime;
  const environment = runtimeData?.environment;
  const configuration = runtimeData?.configuration;
  const model = runtimeData?.model;

  const dependencyInstallation =
    environment?.dependency_installation ||
    runtimeData?.dependency_installation ||
    {};

  const installedPackages =
    dependencyInstallation?.installed_packages ||
    dependencyInstallation?.packages ||
    [];

  const workspaceReady =
    String(runtime?.workspace_status || "").toLowerCase() ===
    "ready";

  const environmentStatus =
    String(environment?.status || "").toLowerCase();

  const environmentReady =
    ["created", "ready"].includes(environmentStatus);

  const dependencyStatus =
    String(dependencyInstallation?.status || "").toLowerCase();

  const dependenciesReady =
    dependencyStatus === "installed";

  const runtimeReady =
    Boolean(runtime?.id) &&
    workspaceReady &&
    environmentReady &&
    dependenciesReady;

  const serviceHealth = health?.service || health;

  const serviceStatus =
    String(serviceHealth?.status || "").toLowerCase();

  const serviceHealthStatus =
    String(serviceHealth?.health || "").toLowerCase();

  const serviceRunning =
    serviceStatus === "running" &&
    serviceHealthStatus === "healthy";

  const apiKeyReady =
    runtimeReady &&
    serviceRunning &&
    Boolean(prediction);

  const framework = configuration?.framework
    ? `${configuration.framework} ${
        configuration.framework_version || ""
      }`.trim()
    : "—";

  const pythonVersion =
    configuration?.python_version ||
    dependencyInstallation?.python_version ||
    "—";

  const packageList = Array.isArray(installedPackages)
    ? installedPackages
    : [];

  const getPackageDetails = (pkg) => {
    if (typeof pkg === "string") {
      return {
        name: pkg,
        version: "",
      };
    }

    return {
      name:
        pkg?.name ||
        pkg?.package ||
        pkg?.distribution ||
        "Package",
      version: pkg?.version || "",
    };
  };

  // --------------------------------------------------
  // RETRY DEPENDENCY INSTALLATION
  // --------------------------------------------------

  const retryDependencyInstallation = async () => {
    const runtimeId = runtimeData?.runtime?.id;

    if (!runtimeId) {
      setError("No registered runtime was found.");
      return;
    }

    if (!workspaceReady || !environmentReady) {
      setError(
        "The runtime workspace and Python environment must be ready before installing dependencies."
      );
      return;
    }

    setInstallingDependencies(true);
    setError("");
    setSuccess("");

    try {
      const csrfToken = await getCsrfToken();

      const response = await fetch(
        `${API_BASE}/models/install_runtime_dependencies.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": csrfToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
          }),
        }
      );

      const result = await readApiResponse(response);

      // Refresh the authoritative server-side deployment state.
      await fetchRuntimeStatus(false);

      setSuccess(
        result.message ||
        "Runtime dependencies were installed successfully."
      );
    } catch (err) {
      setError(
        err.message ||
        "Dependency installation failed. Check the server response."
      );

      await fetchRuntimeStatus(false);
    } finally {
      setInstallingDependencies(false);
    }
  };

  // --------------------------------------------------
  // START INFERENCE SERVICE
  // --------------------------------------------------

  const startInference = async () => {
    const runtimeId = runtimeData?.runtime?.id;

    if (!runtimeId) {
      setError("No registered runtime was found.");
      return;
    }

    if (!runtimeReady) {
      setError(
        "The workspace, environment and dependencies must be ready before starting inference."
      );
      return;
    }

    setStarting(true);
    setError("");
    setSuccess("");
    setPrediction(null);
    setHealth(null);

    try {
      const csrfToken = await getCsrfToken();

      const response = await fetch(
        `${API_BASE}/models/start_runtime.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": csrfToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
          }),
        }
      );

      const result = await readApiResponse(response);

      setSuccess(
        result.message ||
        "The inference service started successfully."
      );

      await fetchRuntimeStatus(false);
    } catch (err) {
      setError(
        err.message ||
        "Unable to start the inference service."
      );

      await fetchHealth(runtimeId);
    } finally {
      setStarting(false);
    }
  };

  // --------------------------------------------------
  // STOP INFERENCE SERVICE
  // --------------------------------------------------

  const stopInference = async () => {
    const runtimeId = runtimeData?.runtime?.id;

    if (!runtimeId) {
      setError("No registered runtime was found.");
      return;
    }

    const confirmed = window.confirm(
      "Are you sure you want to stop this inference service? " +
      "The deployed model will no longer accept prediction requests."
    );

    if (!confirmed) {
      return;
    }

    setStopping(true);
    setError("");
    setSuccess("");
    setPrediction(null);

    try {
      const csrfToken = await getCsrfToken();

      const response = await fetch(
        `${API_BASE}/models/stop_runtime.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": csrfToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
          }),
        }
      );

      const result = await readApiResponse(response);

      setSuccess(
        result.message ||
        "The inference service stopped successfully."
      );

      await fetchRuntimeStatus(false);
    } catch (err) {
      setError(
        err.message ||
        "Unable to stop the inference service."
      );

      await fetchHealth(runtimeId);
    } finally {
      setStopping(false);
    }
  };

  // --------------------------------------------------
  // RUN MODEL PREDICTION
  // --------------------------------------------------

  const runPrediction = async () => {
    const runtimeId = runtimeData?.runtime?.id;

    if (!runtimeId) {
      setError("Runtime is not available.");
      return;
    }

    const numericFeatures = features.map((value) =>
      Number(value)
    );

    if (
      numericFeatures.length !== 4 ||
      numericFeatures.some(
        (value) => !Number.isFinite(value)
      )
    ) {
      setError(
        "Enter exactly four valid numerical features."
      );
      return;
    }

    setPredicting(true);
    setError("");
    setSuccess("");
    setPrediction(null);

    try {
      const csrfToken = await getCsrfToken();

      const response = await fetch(
        `${API_BASE}/models/runtime_predict.php`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": csrfToken,
          },
          body: JSON.stringify({
            runtime_id: runtimeId,
            input: {
              features: numericFeatures,
            },
          }),
        }
      );

      const result = await readApiResponse(response);

      setPrediction(result.data || null);

      setSuccess(
        result.message ||
        "Prediction completed successfully."
      );
    } catch (err) {
      setError(
        err.message ||
        "The prediction request failed."
      );

      await fetchHealth(runtimeId);
    } finally {
      setPredicting(false);
    }
  };

  // --------------------------------------------------
  // UPDATE INPUT FEATURES
  // --------------------------------------------------

  const updateFeature = (index, value) => {
    setFeatures((current) =>
      current.map((item, i) =>
        i === index ? value : item
      )
    );
  };

  // --------------------------------------------------
  // LOADING STATE
  // --------------------------------------------------

  if (loading) {
    return (
      <section className="runtime-deployment-panel">
        <div className="runtime-loading">
          <Loader2
            size={22}
            className="runtime-spinner"
          />

          <span>
            {preparingDeployment
              ? "Preparing deployment runtime..."
              : "Loading runtime deployment..."}
          </span>
        </div>
      </section>
    );
  }

  // --------------------------------------------------
  // INTERFACE
  // --------------------------------------------------

  return (
    <section className="runtime-deployment-panel">

      {/* HEADER */}

      <div className="runtime-panel-header">
        <div>
          <div className="runtime-eyebrow">
            <Server size={14} />
            Runtime Deployment
          </div>

          <h2>Model Loading &amp; Inference</h2>

          <p>
            Load the trained model and test real predictions
            from the deployment runtime.
          </p>
        </div>

        <div
          style={{
            display: "flex",
            alignItems: "center",
            gap: "10px",
            flexWrap: "wrap",
          }}
        >
          {serviceRunning && (
            <button
              type="button"
              className="runtime-refresh-button"
              onClick={stopInference}
              disabled={
                stopping ||
                starting ||
                predicting ||
                installingDependencies
              }
              title="Stop the inference service"
            >
              {stopping ? (
                <Loader2
                  size={16}
                  className="runtime-spinner"
                />
              ) : (
                <Square size={15} />
              )}

              {stopping ? "Stopping..." : "Stop Runtime"}
            </button>
          )}

          <button
            type="button"
            className="runtime-refresh-button"
            onClick={() => fetchRuntimeStatus()}
            disabled={
              loading ||
              starting ||
              stopping ||
              installingDependencies
            }
          >
            <RefreshCw size={16} />
            Refresh
          </button>
        </div>
      </div>

      {/* ALERTS */}

      {error && (
        <div className="runtime-alert runtime-alert-error">
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {success && (
        <div className="runtime-alert runtime-alert-success">
          <CheckCircle2 size={18} />
          <span>{success}</span>
        </div>
      )}

      {/* STATUS CARDS */}

      <div className="runtime-status-grid">

        <div className="runtime-status-card">
          <div className="runtime-status-icon">
            <CheckCircle2 size={20} />
          </div>

          <div>
            <span>Runtime</span>
            <strong>
              {runtime ? "Registered" : "Not available"}
            </strong>
          </div>
        </div>

        <div className="runtime-status-card">
          <div className="runtime-status-icon">
            {workspaceReady
              ? <CheckCircle2 size={20} />
              : <Clock3 size={20} />}
          </div>

          <div>
            <span>Workspace</span>
            <strong>
              {workspaceReady ? "Ready" : "Not ready"}
            </strong>
          </div>
        </div>

        <div className="runtime-status-card">
          <div className="runtime-status-icon">
            {environmentReady
              ? <CheckCircle2 size={20} />
              : <Clock3 size={20} />}
          </div>

          <div>
            <span>Environment</span>
            <strong>
              {environment?.status || "Not available"}
            </strong>
          </div>
        </div>

        <div className="runtime-status-card">
          <div className="runtime-status-icon">
            {serviceRunning
              ? <CheckCircle2 size={20} />
              : <Clock3 size={20} />}
          </div>

          <div>
            <span>Inference Service</span>
            <strong>
              {stopping
                ? "Stopping..."
                : serviceRunning
                  ? "Running"
                  : "Not Running"}
            </strong>
          </div>
        </div>

      </div>

      {/* RUNTIME AND ENVIRONMENT DETAILS */}

      <div className="runtime-details-grid">

        <div className="runtime-detail-card">
          <div className="runtime-detail-heading">
            <Server size={18} />
            <span>Runtime Details</span>
          </div>

          <div className="runtime-detail-list">

            <div className="runtime-detail-item">
              <span>Runtime Name</span>
              <strong>
                {runtime?.runtime_name || "—"}
              </strong>
            </div>

            <div className="runtime-detail-item">
              <span>Runtime Key</span>
              <strong className="runtime-monospace">
                {runtime?.runtime_key || "—"}
              </strong>
            </div>

            <div className="runtime-detail-item">
              <span>Engine</span>
              <strong>
                {runtime?.engine_version || "—"}
              </strong>
            </div>

            <div className="runtime-detail-item">
              <span>Workspace</span>
              <strong>
                {workspaceReady ? "Ready" : "Not ready"}
              </strong>
            </div>

          </div>
        </div>

        <div className="runtime-detail-card">
          <div className="runtime-detail-heading">
            <ShieldCheck size={18} />
            <span>Environment Details</span>
          </div>

          <div className="runtime-detail-list">

            <div className="runtime-detail-item">
              <span>Python</span>
              <strong>{pythonVersion}</strong>
            </div>

            <div className="runtime-detail-item">
              <span>Framework</span>
              <strong>{framework}</strong>
            </div>

            <div className="runtime-detail-item">
              <span>Dependencies</span>
              <strong>
                {dependenciesReady ? "Installed" : "Not installed"}
              </strong>
            </div>

            <div className="runtime-detail-item">
              <span>Environment Status</span>
              <strong>
                {environment?.status || "—"}
              </strong>
            </div>

          </div>
        </div>

      </div>

      {/* MODEL LOADING */}

      {!serviceRunning && (
        <div className="runtime-inference-card">

          <div className="runtime-inference-header">
            <div className="runtime-inference-icon">
              <BrainCircuit size={24} />
            </div>

            <div>
              <h3>Load Model &amp; Start Inference</h3>

              <p>
                Load the trained model into the prepared runtime
                and start its live inference service.
              </p>
            </div>
          </div>

          {!runtimeReady && (
            <div className="runtime-warning">
              <AlertCircle size={18} />

              <span>
                Complete workspace, environment and dependency
                preparation before starting the model.
              </span>
            </div>
          )}

          {runtimeReady && (
            <div className="runtime-ready-message">
              <CheckCircle2 size={18} />

              <span>
                Runtime workspace, environment and dependencies
                are ready. The model can now be loaded.
              </span>
            </div>
          )}

          <button
            type="button"
            className="runtime-start-button"
            onClick={startInference}
            disabled={
              !runtimeReady ||
              starting ||
              stopping ||
              installingDependencies
            }
          >
            {starting ? (
              <>
                <Loader2
                  size={18}
                  className="runtime-spinner"
                />
                Loading Model...
              </>
            ) : (
              <>
                <Play size={18} />
                Load Model &amp; Start Inference
              </>
            )}
          </button>

        </div>
      )}

      {/* RUNNING SERVICE AND PREDICTION */}

      {serviceRunning && (
        <>
          <div className="runtime-running-banner">
            <div className="runtime-running-icon">
              <Zap size={20} />
            </div>

            <div>
              <strong>Inference Service Running</strong>

              <span>
                The trained model has been loaded and is ready
                to receive prediction requests.
              </span>
            </div>
          </div>

          <div className="runtime-inference-card runtime-live-inference">

            <div className="runtime-inference-header">
              <div className="runtime-inference-icon">
                <BrainCircuit size={24} />
              </div>

              <div>
                <h3>Run Model Inference</h3>

                <p>
                  Send input data directly to the deployed model.
                </p>
              </div>
            </div>

            <div className="runtime-model-info">

              <div>
                <span>Model</span>
                <strong>
                  {model?.name || "—"}
                </strong>
              </div>

              <div>
                <span>Task</span>
                <strong>
                  {configuration?.manifest?.task ||
                    model?.task ||
                    "Classification"}
                </strong>
              </div>

              <div>
                <span>Framework</span>
                <strong>{framework}</strong>
              </div>

            </div>

            <div className="runtime-prediction-form">

              <div className="runtime-prediction-title">
                <Activity size={18} />
                <span>Input Features</span>
              </div>

              <div className="runtime-feature-grid">
                {[
                  "Sepal Length",
                  "Sepal Width",
                  "Petal Length",
                  "Petal Width",
                ].map((label, index) => (
                  <label
                    key={label}
                    className="runtime-feature-field"
                  >
                    <span>{label}</span>

                    <input
                      type="number"
                      step="any"
                      value={features[index]}
                      disabled={stopping || predicting}
                      onChange={(event) =>
                        updateFeature(
                          index,
                          event.target.value
                        )
                      }
                    />
                  </label>
                ))}
              </div>

              <button
                type="button"
                className="runtime-predict-button"
                onClick={runPrediction}
                disabled={predicting || stopping}
              >
                {predicting ? (
                  <>
                    <Loader2
                      size={18}
                      className="runtime-spinner"
                    />
                    Running Prediction...
                  </>
                ) : (
                  <>
                    <Send size={18} />
                    Run Prediction
                  </>
                )}
              </button>

            </div>

            {/* PREDICTION RESULT */}

            {prediction && (
              <div className="runtime-prediction-result">

                <div className="runtime-result-heading">
                  <CheckCircle2 size={20} />
                  <span>Prediction Result</span>
                </div>

                <div className="runtime-prediction-main">
                  <span>Predicted Class</span>

                  <strong>
                    {prediction.prediction ||
                      prediction.result?.prediction ||
                      "—"}
                  </strong>
                </div>

                {prediction.class_id !== undefined && (
                  <div className="runtime-class-id">
                    Class ID: {prediction.class_id}
                  </div>
                )}

                {prediction.probabilities && (
                  <div className="runtime-probabilities">

                    <div className="runtime-probability-title">
                      Class Probabilities
                    </div>

                    {Object.entries(
                      prediction.probabilities
                    ).map(([className, probability]) => {
                      const percentage =
                        Number(probability) * 100;

                      return (
                        <div
                          className="runtime-probability-row"
                          key={className}
                        >
                          <div className="runtime-probability-label">
                            <span>{className}</span>

                            <strong>
                              {percentage.toFixed(2)}%
                            </strong>
                          </div>

                          <div className="runtime-probability-track">
                            <div
                              className="runtime-probability-fill"
                              style={{
                                width: `${Math.max(
                                  0,
                                  Math.min(100, percentage)
                                )}%`,
                              }}
                            />
                          </div>
                        </div>
                      );
                    })}

                  </div>
                )}

              </div>
            )}

          </div>
        </>
      )}

      {/* API KEY MANAGEMENT */}

      {runtime?.id && (
        <div
          className="runtime-packages-card"
          style={{ marginTop: "24px" }}
        >
          <div className="runtime-packages-header">
            <div>
              <div className="runtime-detail-heading">
                <KeyRound size={18} />
                <span>API Key Management</span>
              </div>

              <p>
                Create and manage credentials for applications that will
                access this deployed model through its prediction API.
              </p>
            </div>

            <button
              type="button"
              className="runtime-refresh-button"
              onClick={() => fetchApiKeys(runtime.id)}
              disabled={
                apiKeysLoading ||
                apiKeyGenerating ||
                Boolean(revokingKeyId)
              }
              title="Refresh API keys"
            >
              {apiKeysLoading ? (
                <Loader2
                  size={16}
                  className="runtime-spinner"
                />
              ) : (
                <RefreshCw size={16} />
              )}

              Refresh
            </button>
          </div>

          {!apiKeyReady && (
            <div
              className="runtime-alert runtime-alert-info"
              style={{ marginTop: "18px" }}
            >
              <strong>Deployment readiness required</strong>

              <p style={{ marginBottom: 0 }}>
                To generate an API key, the workspace must be ready,
                the Python environment and dependencies must be prepared,
                the inference service must be healthy, and at least one
                real prediction must succeed.
              </p>

              <ul style={{ margin: "10px 0 0", paddingLeft: "20px" }}>
                <li>
                  Workspace: {workspaceReady ? "Ready" : "Not ready"}
                </li>
                <li>
                  Environment: {environmentReady ? "Ready" : "Not ready"}
                </li>
                <li>
                  Dependencies: {dependenciesReady ? "Installed" : "Not installed"}
                </li>
                <li>
                  Inference service: {serviceRunning ? "Healthy" : "Not healthy"}
                </li>
                <li>
                  Successful prediction: {prediction ? "Completed" : "Not completed"}
                </li>
              </ul>
            </div>
          )}

          <form
            onSubmit={generateApiKey}
            style={{
              display: "flex",
              alignItems: "flex-end",
              gap: "12px",
              flexWrap: "wrap",
              marginTop: "20px",
            }}
          >
            <label
              className="runtime-feature-field"
              style={{ flex: "1 1 260px" }}
            >
              <span>API Key Name</span>

              <input
                type="text"
                value={apiKeyName}
                maxLength={100}
                minLength={1}
                required
                autoComplete="off"
                placeholder="e.g. Production API Key"
                onChange={(event) =>
                  setApiKeyName(event.target.value)
                }
                disabled={
                  apiKeyGenerating ||
                  Boolean(revokingKeyId)
                }
              />
            </label>

            <button
              type="submit"
              className="runtime-start-button"
              disabled={
                apiKeyGenerating ||
                !apiKeyName.trim() ||
                !apiKeyReady ||
                Boolean(revokingKeyId)
              }
            >
              {apiKeyGenerating ? (
                <>
                  <Loader2
                    size={17}
                    className="runtime-spinner"
                  />
                  Generating...
                </>
              ) : (
                <>
                  <KeyRound size={17} />
                  Generate API Key
                </>
              )}
            </button>
          </form>

          {newApiKey?.api_key && (
            <div
              className="runtime-alert runtime-alert-success"
              style={{
                display: "block",
                marginTop: "18px",
                overflowWrap: "anywhere",
              }}
            >
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: "8px",
                }}
              >
                <CheckCircle2 size={18} />
                <strong>New API key — copy it now</strong>
              </div>

              <p style={{ margin: "10px 0 8px" }}>
                This is the only time the complete key will be displayed.
                Store it in a secure password manager.
              </p>

              <code
                style={{
                  display: "block",
                  padding: "12px",
                  borderRadius: "8px",
                  background: "rgba(0, 0, 0, 0.08)",
                  overflowWrap: "anywhere",
                  userSelect: "all",
                }}
              >
                {newApiKey.api_key}
              </code>

              <button
                type="button"
                className="runtime-refresh-button"
                onClick={copyApiKey}
                style={{ marginTop: "12px" }}
              >
                <Copy size={16} />
                {apiKeyCopied ? "Copied" : "Copy API Key"}
              </button>

              <button
                type="button"
                onClick={() => {
                  setNewApiKey(null);
                  setApiKeyCopied(false);
                }}
                style={{
                  marginLeft: "10px",
                  border: 0,
                  background: "transparent",
                  cursor: "pointer",
                  fontWeight: 600,
                  padding: "8px",
                }}
              >
                Hide key
              </button>
            </div>
          )}

          <div style={{ marginTop: "24px" }}>
            <div
              className="runtime-detail-heading"
              style={{ marginBottom: "12px" }}
            >
              <ShieldCheck size={18} />
              <span>Existing API Keys ({apiKeys.length})</span>
            </div>

            {apiKeysLoading && apiKeys.length === 0 ? (
              <div className="runtime-empty-packages">
                <Loader2
                  size={17}
                  className="runtime-spinner"
                  style={{
                    verticalAlign: "middle",
                    marginRight: "8px",
                  }}
                />
                Loading API keys...
              </div>
            ) : apiKeys.length === 0 ? (
              <div className="runtime-empty-packages">
                No API keys have been generated for this runtime.
              </div>
            ) : (
              <div className="runtime-detail-list">
                {apiKeys.map((apiKey) => {
                  const active = apiKey.status === "active";

                  return (
                    <div
                      key={apiKey.id}
                      style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        gap: "14px",
                        flexWrap: "wrap",
                        padding: "14px 0",
                        borderBottom: "1px solid rgba(128, 128, 128, 0.18)",
                      }}
                    >
                      <div
                        style={{
                          minWidth: 0,
                          flex: "1 1 240px",
                        }}
                      >
                        <strong style={{ display: "block" }}>
                          {apiKey.name}
                        </strong>

                        <code
                          style={{
                            display: "block",
                            marginTop: "5px",
                            overflowWrap: "anywhere",
                          }}
                        >
                          {apiKey.key_prefix}••••••••
                        </code>

                        <span
                          style={{
                            display: "block",
                            marginTop: "5px",
                            fontSize: "12px",
                            opacity: 0.75,
                          }}
                        >
                          Created: {apiKey.created_at || "—"}
                          {apiKey.last_used_at
                            ? ` · Last used: ${apiKey.last_used_at}`
                            : " · Never used"}
                        </span>
                      </div>

                      <div
                        style={{
                          display: "flex",
                          alignItems: "center",
                          gap: "10px",
                        }}
                      >
                        <span
                          style={{
                            fontSize: "12px",
                            fontWeight: 700,
                            padding: "5px 9px",
                            borderRadius: "20px",
                            background: active
                              ? "rgba(22, 163, 74, 0.12)"
                              : "rgba(128, 128, 128, 0.14)",
                            color: active ? "#15803d" : "inherit",
                          }}
                        >
                          {apiKey.status}
                        </span>

                        {active && (
                          <button
                            type="button"
                            className="runtime-refresh-button"
                            onClick={() => revokeApiKey(apiKey)}
                            disabled={
                              Boolean(revokingKeyId) ||
                              apiKeyGenerating
                            }
                            title="Revoke this API key"
                            style={{
                              color: "#b42318",
                              borderColor: "rgba(180, 35, 24, 0.3)",
                            }}
                          >
                            {revokingKeyId === apiKey.id ? (
                              <Loader2
                                size={15}
                                className="runtime-spinner"
                              />
                            ) : (
                              <Ban size={15} />
                            )}

                            {revokingKeyId === apiKey.id
                              ? "Revoking..."
                              : "Revoke"}
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      )}

      {/* INSTALLED DEPENDENCIES */}

      <div className="runtime-packages-card">

        <div className="runtime-packages-header">
          <div>
            <div className="runtime-detail-heading">
              <Package size={18} />
              <span>Installed Dependencies</span>
            </div>

            <p>
              Packages installed and verified in the runtime
              environment.
            </p>
          </div>

          <div
            style={{
              display: "flex",
              alignItems: "center",
              gap: "12px",
              flexWrap: "wrap",
            }}
          >
            {runtime?.id &&
              workspaceReady &&
              environmentReady &&
              !dependenciesReady && (
                <button
                  type="button"
                  className="runtime-start-button"
                  onClick={retryDependencyInstallation}
                  disabled={
                    installingDependencies ||
                    preparingDeployment ||
                    starting ||
                    stopping
                  }
                >
                  {installingDependencies ? (
                    <>
                      <Loader2
                        size={17}
                        className="runtime-spinner"
                      />
                      Installing Dependencies...
                    </>
                  ) : (
                    <>
                      <RefreshCw size={17} />
                      Retry Dependency Installation
                    </>
                  )}
                </button>
              )}

            <div className="runtime-package-count">
              {packageList.length}
            </div>
          </div>
        </div>

        {packageList.length > 0 ? (
          <div className="runtime-packages-list">
            {packageList.map((pkg, index) => {
              const { name, version } =
                getPackageDetails(pkg);

              return (
                <div
                  className="runtime-package-item"
                  key={`${name}-${index}`}
                >
                  <CheckCircle2 size={16} />

                  <span>{name}</span>

                  {version && (
                    <strong>{version}</strong>
                  )}
                </div>
              );
            })}
          </div>
        ) : (
          <div className="runtime-empty-packages">
            {dependenciesReady
              ? "Dependency installation is verified, but no package list was returned."
              : "No installed packages were returned."}
          </div>
        )}

        {dependencyInstallation?.verified_at && (
          <div className="runtime-package-verification">
            Verified: {dependencyInstallation.verified_at}
          </div>
        )}

      </div>

    </section>
  );
}
