import { useCallback, useEffect, useMemo, useState } from "react";

import {
  Activity,
  AlertCircle,
  BookOpen,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Code2,
  Copy,
  ExternalLink,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  Play,
  Plus,
  RefreshCw,
  ShieldCheck,
  Terminal,
  Trash2,
  X,
  XCircle,
} from "lucide-react";

import {
  API_BASE,
  generateApiKey,
  getApiUsage,
  getModelVersions,
  getRuntimeStatus,
  listApiKeys,
  revokeApiKey,
  testPublicPrediction,
} from "../../services/apiIntegration.js";

import "../../styles/api/api-integration.css";


const tabs = [
  { id: "api-docs", label: "Documentation", icon: BookOpen },
  { id: "api-keys", label: "API keys", icon: KeyRound },
  { id: "usage", label: "Usage", icon: Activity },
];

const defaultPayload = JSON.stringify(
  {
    features: [5.1, 3.5, 1.4, 0.2],
  },
  null,
  2
);


function formatNumber(value) {
  return new Intl.NumberFormat("en-US").format(
    Number(value || 0)
  );
}


function formatDate(value) {
  if (!value) return "—";

  const date = new Date(
    String(value).replace(" ", "T")
  );

  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString();
}


function StatusPill({ status, success }) {
  const ok =
    success !== undefined
      ? success
      : Number(status) < 400;

  return (
    <span
      className={`api-status-pill ${
        ok ? "is-success" : "is-error"
      }`}
    >
      {ok ? (
        <CheckCircle2 size={13} />
      ) : (
        <XCircle size={13} />
      )}

      {status || (ok ? "Active" : "Failed")}
    </span>
  );
}


function MetricCard({
  label,
  value,
  description,
  icon: Icon,
}) {
  return (
    <article className="api-metric-card">
      <div className="api-metric-icon">
        <Icon size={19} />
      </div>

      <span>{label}</span>
      <strong>{value}</strong>
      <small>{description}</small>
    </article>
  );
}


function CodeExample({
  title,
  language,
  children,
}) {
  const [copied, setCopied] = useState(false);

  const source = String(children);

  async function copyCode() {
    try {
      await navigator.clipboard.writeText(source);

      setCopied(true);

      window.setTimeout(() => {
        setCopied(false);
      }, 1800);
    } catch {
      setCopied(false);
    }
  }

  return (
    <section className="api-code-card">
      <div className="api-code-heading">
        <div>
          <strong>{title}</strong>
          <span>{language}</span>
        </div>

        <button
          type="button"
          onClick={copyCode}
        >
          <Copy size={14} />
          {copied ? "Copied" : "Copy"}
        </button>
      </div>

      <pre>
        <code>{source}</code>
      </pre>
    </section>
  );
}


export default function APIIntegration({
  initialTab = "api-docs",
  models = [],
}) {
  // --------------------------------------------------
  // NAVIGATION
  // --------------------------------------------------

  const [activeTab, setActiveTab] = useState(initialTab);


  // --------------------------------------------------
  // MODEL AND RUNTIME
  // --------------------------------------------------

  const [modelOptions, setModelOptions] = useState([]);

  const [selectedModelId, setSelectedModelId] =
    useState("");

  const [runtimeOptions, setRuntimeOptions] =
    useState([]);

  const [selectedRuntimeId, setSelectedRuntimeId] =
    useState("");

  const [loadingModels, setLoadingModels] =
    useState(false);


  // --------------------------------------------------
  // API KEYS
  // --------------------------------------------------

  const [keys, setKeys] = useState([]);

  const [keyName, setKeyName] = useState("");

  const [newKey, setNewKey] = useState("");

  const [showNewKey, setShowNewKey] =
    useState(true);

  const [generatingKey, setGeneratingKey] =
    useState(false);

  const [loadingKeys, setLoadingKeys] =
    useState(false);

  const [revokingId, setRevokingId] =
    useState(null);


  // --------------------------------------------------
  // USAGE
  // --------------------------------------------------

  const [usage, setUsage] = useState(null);

  const [usagePage, setUsagePage] =
    useState(1);

  const [loadingUsage, setLoadingUsage] =
    useState(false);


  // --------------------------------------------------
  // INTERACTIVE API TESTER
  // --------------------------------------------------

  const [testerKey, setTesterKey] = useState("");

  const [showTesterKey, setShowTesterKey] =
    useState(false);

  const [payloadText, setPayloadText] =
    useState(defaultPayload);

  const [predictionResult, setPredictionResult] =
    useState(null);

  const [testing, setTesting] = useState(false);


  // --------------------------------------------------
  // NOTIFICATIONS
  // --------------------------------------------------

  const [notice, setNotice] = useState("");

  const [error, setError] = useState("");


  // --------------------------------------------------
  // SYNCHRONISE TAB
  // --------------------------------------------------

  useEffect(() => {
    const validTabs = tabs.map((tab) => tab.id);

    setActiveTab(
      validTabs.includes(initialTab)
        ? initialTab
        : "api-docs"
    );
  }, [initialTab]);


  // --------------------------------------------------
  // SELECTED MODEL
  // --------------------------------------------------

  const selectedModel = useMemo(() => {
    return modelOptions.find(
      (item) =>
        String(item.id) === String(selectedModelId)
    );
  }, [modelOptions, selectedModelId]);


  // --------------------------------------------------
  // SELECTED RUNTIME
  // --------------------------------------------------

  const selectedRuntime = useMemo(() => {
    return runtimeOptions.find(
      (item) =>
        String(item.runtimeId) ===
        String(selectedRuntimeId)
    );
  }, [runtimeOptions, selectedRuntimeId]);


  // --------------------------------------------------
  // PUBLIC API ENDPOINT
  // --------------------------------------------------

  const endpoint = selectedModel?.slug
    ? `${API_BASE}/v1/models/${selectedModel.slug}/predict`
    : "";


  // --------------------------------------------------
  // LOAD MODELS
  // --------------------------------------------------

  const loadModels = useCallback(async () => {
    setLoadingModels(true);
    setError("");

    try {
      const available = (
        Array.isArray(models) ? models : []
      )
        .filter((model) => model?.id)
        .map((model) => ({
          id: model.id,
          name:
            model.name ||
            model.model_name ||
            `Model ${model.id}`,
          slug:
            model.slug ||
            model.model_slug ||
            "",
        }));

      setModelOptions(available);

      setSelectedModelId((current) => {
        if (
          current &&
          available.some(
            (item) =>
              String(item.id) === String(current)
          )
        ) {
          return current;
        }

        return available.length
          ? String(available[0].id)
          : "";
      });

      if (!available.length) {
        setRuntimeOptions([]);
        setSelectedRuntimeId("");
        setKeys([]);
      }
    } catch (err) {
      setError(
        err.message ||
        "Unable to load your models."
      );
    } finally {
      setLoadingModels(false);
    }
  }, [models]);


  useEffect(() => {
    loadModels();
  }, [loadModels]);


  // --------------------------------------------------
  // LOAD DEPLOYED RUNTIMES FOR SELECTED MODEL
  // --------------------------------------------------

  const loadModelRuntimes = useCallback(async () => {
    if (!selectedModelId) {
      setRuntimeOptions([]);
      setSelectedRuntimeId("");
      setKeys([]);
      return;
    }

    setLoadingModels(true);

    setRuntimeOptions([]);
    setSelectedRuntimeId("");
    setKeys([]);

    try {
      const versionResult =
        await getModelVersions(selectedModelId);

      const versions =
        versionResult.versions ||
        versionResult.data?.versions ||
        [];

      if (!Array.isArray(versions)) {
        throw new Error(
          "The server returned an invalid model version list."
        );
      }

      const options = [];

      for (const version of versions) {
        if (!version?.id) continue;

        try {
          const statusResult =
            await getRuntimeStatus(version.id);

          const runtimeData =
            statusResult.data || statusResult;

          const runtime =
            runtimeData.runtime ||
            runtimeData.data?.runtime ||
            null;

          if (!runtime?.id) continue;

          options.push({
            runtimeId: runtime.id,
            versionId: version.id,

            version:
              version.version ||
              version.version_number ||
              version.id,

            modelName:
              selectedModel?.name || "Model",

            slug:
              runtimeData.model?.slug ||
              runtimeData.model?.model_slug ||
              selectedModel?.slug ||
              "",

            runtimeStatus:
              runtime.status || "unknown",

            workspaceStatus:
              runtime.workspace_status || "unknown",
          });
        } catch (runtimeError) {
          // Versions without a registered runtime
          // are not available for API key management.
        }
      }

      setRuntimeOptions(options);

      if (options.length) {
        setSelectedRuntimeId(
          String(options[0].runtimeId)
        );
      }
    } catch (err) {
      setError(
        err.message ||
        "Unable to retrieve model deployments."
      );
    } finally {
      setLoadingModels(false);
    }
  }, [selectedModelId, selectedModel]);


  useEffect(() => {
    loadModelRuntimes();
  }, [loadModelRuntimes]);


  // --------------------------------------------------
  // LOAD API KEYS
  // --------------------------------------------------

  const loadKeys = useCallback(async () => {
    if (!selectedRuntimeId) {
      setKeys([]);
      return;
    }

    setLoadingKeys(true);

    try {
      const result =
        await listApiKeys(selectedRuntimeId);

      const availableKeys =
        result.data?.api_keys ||
        result.api_keys ||
        [];

      setKeys(
        Array.isArray(availableKeys)
          ? availableKeys
          : []
      );
    } catch (err) {
      setError(
        err.message ||
        "Unable to retrieve API keys."
      );
    } finally {
      setLoadingKeys(false);
    }
  }, [selectedRuntimeId]);


  useEffect(() => {
    loadKeys();
  }, [loadKeys]);


  // --------------------------------------------------
  // LOAD USAGE
  // --------------------------------------------------

  const loadUsage = useCallback(async () => {
    setLoadingUsage(true);

    try {
      const result = await getApiUsage({
        runtimeId:
          selectedRuntimeId || undefined,

        page: usagePage,
        limit: 20,
      });

      setUsage(result.data || result || null);
    } catch (err) {
      setError(
        err.message ||
        "Unable to retrieve API usage."
      );
    } finally {
      setLoadingUsage(false);
    }
  }, [selectedRuntimeId, usagePage]);


  useEffect(() => {
    if (activeTab === "usage") {
      loadUsage();
    }
  }, [activeTab, loadUsage]);


  // --------------------------------------------------
  // GENERATE API KEY
  // --------------------------------------------------

  async function handleGenerateKey(event) {
    event.preventDefault();

    if (generatingKey) return;

    setError("");
    setNotice("");

    if (!selectedModelId) {
      setError("Select a model first.");
      return;
    }

    if (!selectedRuntimeId) {
      setError(
        "Select a deployed model runtime first."
      );
      return;
    }

    if (!keyName.trim()) {
      setError("Enter a name for this API key.");
      return;
    }

    setGeneratingKey(true);

    try {
      const result = await generateApiKey(
        selectedRuntimeId,
        keyName.trim()
      );

      const generated =
        result.data || result;

      const returnedKey =
        generated.api_key ||
        generated.key ||
        "";

      if (!returnedKey) {
        throw new Error(
          result.message ||
          "The server did not return the new API key."
        );
      }

      setNewKey(returnedKey);
      setShowNewKey(true);

      setKeyName("");

      setNotice(
        "API key generated successfully. Copy and store it securely. The complete key will not be shown again."
      );

      await loadKeys();
    } catch (err) {
      setError(
        err.message ||
        "Unable to generate an API key."
      );
    } finally {
      setGeneratingKey(false);
    }
  }


  // --------------------------------------------------
  // REVOKE API KEY
  // --------------------------------------------------

  async function handleRevokeKey(apiKey) {
    const confirmed = window.confirm(
      `Revoke "${apiKey.name}"? Applications using this key will lose access.`
    );

    if (!confirmed) return;

    setError("");
    setNotice("");

    setRevokingId(apiKey.id);

    try {
      await revokeApiKey(apiKey.id);

      setNotice(
        "API key revoked successfully."
      );

      await loadKeys();
    } catch (err) {
      setError(
        err.message ||
        "Unable to revoke this API key."
      );
    } finally {
      setRevokingId(null);
    }
  }


  // --------------------------------------------------
  // COPY TEXT
  // --------------------------------------------------

  async function copyText(
    value,
    successMessage
  ) {
    try {
      await navigator.clipboard.writeText(value);

      setNotice(successMessage);
      setError("");
    } catch {
      setError(
        "Clipboard access failed. Select and copy the text manually."
      );
    }
  }


  // --------------------------------------------------
  // INTERACTIVE PREDICTION TESTER
  // --------------------------------------------------

  async function handleTest(event) {
    event.preventDefault();

    setError("");
    setNotice("");
    setPredictionResult(null);

    if (!selectedModel?.slug) {
      setError(
        "The selected model does not have a public API slug."
      );
      return;
    }

    if (!testerKey.trim()) {
      setError(
        "Enter an API key to authenticate the test request."
      );
      return;
    }

    let payload;

    try {
      payload = JSON.parse(payloadText);
    } catch {
      setError(
        "The request body must contain valid JSON."
      );
      return;
    }

    setTesting(true);

    try {
      const result = await testPublicPrediction(
        selectedModel.slug,
        testerKey.trim(),
        payload
      );

      setPredictionResult(result);

      if (result.ok) {
        setNotice(
          "Prediction request completed successfully."
        );
      } else {
        setError(
          result.body?.message ||
          `Prediction request failed with HTTP ${result.status}.`
        );
      }
    } catch (err) {
      setError(
        err.message ||
        "The prediction request failed."
      );
    } finally {
      setTesting(false);
    }
  }


  // --------------------------------------------------
  // USAGE DATA
  // --------------------------------------------------

  const summary = usage?.summary || {};

  const history = Array.isArray(usage?.history)
    ? usage.history
    : [];

  const pagination =
    usage?.pagination || {};

  const statusDistribution =
    Array.isArray(usage?.status_distribution)
      ? usage.status_distribution
      : [];


  // --------------------------------------------------
  // CODE EXAMPLES
  // --------------------------------------------------

  const exampleEndpoint =
    endpoint ||
    "https://your-domain.com/api/v1/models/YOUR_MODEL_SLUG/predict";


  const pythonExample = `import requests

url = "${exampleEndpoint}"

headers = {
    "Authorization": "Bearer YOUR_API_KEY",
    "Content-Type": "application/json"
}

payload = {
    "features": [5.1, 3.5, 1.4, 0.2]
}

response = requests.post(
    url,
    headers=headers,
    json=payload,
    timeout=30
)

response.raise_for_status()
print(response.json())`;


  const javascriptExample = `const response = await fetch(
  "${exampleEndpoint}",
  {
    method: "POST",
    headers: {
      "Authorization": "Bearer YOUR_API_KEY",
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      features: [5.1, 3.5, 1.4, 0.2]
    })
  }
);

const result = await response.json();

if (!response.ok) {
  throw new Error(result.message || "Prediction failed");
}

console.log(result);`;


  const curlExample = `curl -X POST "${exampleEndpoint}" \\
  -H "Authorization: Bearer YOUR_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{"features":[5.1,3.5,1.4,0.2]}'`;


  // --------------------------------------------------
  // MODEL/RUNTIME SELECTORS
  // --------------------------------------------------

  function renderModelSelectors() {
    return (
      <>
        <div className="api-selection-grid">
          <label>
            Model

            <select
              value={selectedModelId}
              onChange={(event) => {
                setSelectedModelId(event.target.value);
                setError("");
                setNotice("");
              }}
              disabled={
                loadingModels ||
                !modelOptions.length
              }
            >
              <option value="">
                Select a model
              </option>

              {modelOptions.map((model) => (
                <option
                  key={model.id}
                  value={model.id}
                >
                  {model.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Deployment runtime

            <select
              value={selectedRuntimeId}
              onChange={(event) => {
                setSelectedRuntimeId(event.target.value);
                setError("");
                setNotice("");
              }}
              disabled={
                loadingModels ||
                !runtimeOptions.length
              }
            >
              <option value="">
                Select a deployed runtime
              </option>

              {runtimeOptions.map((runtime) => (
                <option
                  key={runtime.runtimeId}
                  value={runtime.runtimeId}
                >
                  Version {runtime.version}
                  {" · "}
                  Runtime #{runtime.runtimeId}
                </option>
              ))}
            </select>
          </label>
        </div>

        {loadingModels && (
          <p className="api-muted">
            <Loader2
              className="api-spin"
              size={15}
            />
            Loading models and deployments...
          </p>
        )}

        {!loadingModels &&
          !modelOptions.length && (
            <div className="api-empty-state">
              <AlertCircle size={21} />

              <div>
                <strong>No models found</strong>
                <p>
                  Upload a model before configuring its public API.
                </p>
              </div>
            </div>
          )}

        {!loadingModels &&
          modelOptions.length > 0 &&
          !runtimeOptions.length && (
            <div className="api-empty-state">
              <AlertCircle size={21} />

              <div>
                <strong>No registered runtime found</strong>
                <p>
                  This model has no registered deployment
                  runtime available for API key management.
                  Complete its deployment first.
                </p>
              </div>
            </div>
          )}
      </>
    );
  }


  // --------------------------------------------------
  // DOCUMENTATION TAB
  // --------------------------------------------------

  function renderDocumentation() {
    return (
      <>
        <div className="api-intro">
          <div>
            <span className="api-eyebrow">
              DEVELOPER RESOURCES
            </span>

            <h2>
              Connect your application to your model
            </h2>

            <p>
              Send prediction requests to your deployed
              machine learning models using a secure API key.
            </p>
          </div>

          <div className="api-intro-icon">
            <Code2 size={30} />
          </div>
        </div>


        <section className="api-panel">
          <div className="api-panel-heading">
            <div>
              <h3>Choose a deployed model</h3>
              <p>
                Select the model whose endpoint you want to integrate.
              </p>
            </div>

            <button
              type="button"
              className="api-quiet-button"
              onClick={loadModels}
              disabled={loadingModels}
            >
              <RefreshCw size={15} />
              Refresh
            </button>
          </div>

          {renderModelSelectors()}
        </section>


        {selectedModel?.slug && (
          <>
            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>Prediction endpoint</h3>
                  <p>
                    Use this endpoint to submit model inference requests.
                  </p>
                </div>

                <span className="api-method">
                  POST
                </span>
              </div>

              <div className="api-endpoint-row">
                <code>{endpoint}</code>

                <button
                  type="button"
                  onClick={() =>
                    copyText(
                      endpoint,
                      "Endpoint copied."
                    )
                  }
                  aria-label="Copy endpoint"
                >
                  <Copy size={16} />
                </button>
              </div>

              <div className="api-info-grid">
                <div>
                  <span>Authentication</span>
                  <strong>Bearer API key</strong>
                </div>

                <div>
                  <span>Content type</span>
                  <strong>application/json</strong>
                </div>

                <div>
                  <span>HTTP method</span>
                  <strong>POST</strong>
                </div>
              </div>
            </section>


            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>Authentication</h3>
                  <p>
                    Include your API key in the Authorization header.
                  </p>
                </div>

                <ShieldCheck size={21} />
              </div>

              <CodeExample
                title="HTTP header"
                language="HTTP"
              >
                {`Authorization: Bearer YOUR_API_KEY
Content-Type: application/json`}
              </CodeExample>

              <p className="api-security-note">
                Never expose your API key in public frontend
                code, source repositories or browser applications
                distributed to users. Store it in a secure
                server-side environment variable.
              </p>
            </section>


            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>Request body</h3>
                  <p>
                    The input fields depend on the model's
                    expected input schema. The following is an
                    illustrative Iris classifier example.
                  </p>
                </div>
              </div>

              <CodeExample
                title="Example JSON request"
                language="JSON"
              >
                {JSON.stringify(
                  {
                    features: [
                      5.1,
                      3.5,
                      1.4,
                      0.2,
                    ],
                  },
                  null,
                  2
                )}
              </CodeExample>

              <div className="api-schema-note">
                <strong>Input schema</strong>

                <p>
                  <code>features</code>: an array of numeric
                  feature values in the exact order expected
                  by the deployed model. Replace this example
                  with the input structure required by your model.
                </p>
              </div>
            </section>


            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>Example response</h3>
                  <p>
                    A successful prediction returns the model
                    and prediction data.
                  </p>
                </div>

                <StatusPill
                  status="200 OK"
                  success
                />
              </div>

              <CodeExample
                title="Successful response"
                language="JSON"
              >
                {JSON.stringify(
                  {
                    success: true,
                    data: {
                      model: {
                        name: selectedModel.name,
                        slug: selectedModel.slug,
                        version: 2,
                      },
                      prediction: {
                        prediction: "setosa",
                        class_id: 0,
                        probabilities: {
                          setosa: 1,
                          versicolor: 0,
                          virginica: 0,
                        },
                      },
                    },
                    message:
                      "Prediction completed successfully.",
                  },
                  null,
                  2
                )}
              </CodeExample>
            </section>


            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>Code examples</h3>
                  <p>
                    Use the language or command-line tool of your choice.
                  </p>
                </div>
              </div>

              <CodeExample
                title="Python"
                language="PYTHON"
              >
                {pythonExample}
              </CodeExample>

              <CodeExample
                title="JavaScript"
                language="JAVASCRIPT"
              >
                {javascriptExample}
              </CodeExample>

              <CodeExample
                title="cURL"
                language="SHELL"
              >
                {curlExample}
              </CodeExample>
            </section>


            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>Interactive request tester</h3>
                  <p>
                    Send a real request to the public prediction endpoint.
                  </p>
                </div>

                <Terminal size={21} />
              </div>

              <form
                onSubmit={handleTest}
                className="api-tester-form"
              >
                <label>
                  API key

                  <div className="api-secret-input">
                    <input
                      type={
                        showTesterKey
                          ? "text"
                          : "password"
                      }
                      value={testerKey}
                      onChange={(event) =>
                        setTesterKey(event.target.value)
                      }
                      placeholder="mdp_..."
                      autoComplete="off"
                    />

                    <button
                      type="button"
                      onClick={() =>
                        setShowTesterKey(
                          !showTesterKey
                        )
                      }
                      aria-label={
                        showTesterKey
                          ? "Hide API key"
                          : "Show API key"
                      }
                    >
                      {showTesterKey ? (
                        <EyeOff size={17} />
                      ) : (
                        <Eye size={17} />
                      )}
                    </button>
                  </div>
                </label>


                <label>
                  JSON request body

                  <textarea
                    rows={8}
                    value={payloadText}
                    onChange={(event) =>
                      setPayloadText(event.target.value)
                    }
                    spellCheck="false"
                  />
                </label>


                <button
                  className="api-primary-button"
                  type="submit"
                  disabled={
                    testing ||
                    !selectedModel?.slug
                  }
                >
                  {testing ? (
                    <Loader2
                      className="api-spin"
                      size={17}
                    />
                  ) : (
                    <Play size={16} />
                  )}

                  {testing
                    ? "Sending request..."
                    : "Send prediction request"}
                </button>
              </form>


              {predictionResult && (
                <div className="api-result">
                  <div className="api-result-heading">
                    <strong>Response</strong>

                    <StatusPill
                      status={`HTTP ${predictionResult.status}`}
                      success={predictionResult.ok}
                    />
                  </div>

                  <pre>
                    <code>
                      {JSON.stringify(
                        predictionResult.body,
                        null,
                        2
                      )}
                    </code>
                  </pre>
                </div>
              )}
            </section>
          </>
        )}

        {!loadingModels &&
          selectedModel &&
          !selectedModel.slug && (
            <div className="api-empty-state">
              <AlertCircle size={21} />
              <div>
                <strong>Public API slug unavailable</strong>
                <p>
                  The selected model does not include a public
                  API slug. Its model record must expose the
                  slug before documentation and testing can
                  be configured.
                </p>
              </div>
            </div>
          )}
      </>
    );
  }


  // --------------------------------------------------
  // API KEY MANAGEMENT TAB
  // --------------------------------------------------

  function renderKeys() {
    return (
      <>
        <div className="api-intro">
          <div>
            <span className="api-eyebrow">
              ACCESS CONTROL
            </span>

            <h2>API key management</h2>

            <p>
              Create and revoke credentials used by your applications.
            </p>
          </div>

          <div className="api-intro-icon">
            <KeyRound size={30} />
          </div>
        </div>


        <section className="api-panel">
          <div className="api-panel-heading">
            <div>
              <h3>Generate a new API key</h3>
              <p>
                Keys are linked to the selected model runtime.
              </p>
            </div>

            <ShieldCheck size={21} />
          </div>


          {/* MODEL AND RUNTIME SELECTION */}

          {renderModelSelectors()}


          {/* KEY NAME AND GENERATION */}

          <form
            className="api-key-form"
            onSubmit={handleGenerateKey}
          >
            <label>
              Key name

              <input
                type="text"
                value={keyName}
                onChange={(event) =>
                  setKeyName(event.target.value)
                }
                placeholder="e.g. Python Integration"
                maxLength={100}
                autoComplete="off"
                required
              />
            </label>

            <button
              className="api-primary-button"
              type="submit"
              disabled={
                generatingKey ||
                loadingModels ||
                !selectedRuntimeId ||
                !keyName.trim()
              }
            >
              {generatingKey ? (
                <Loader2
                  className="api-spin"
                  size={17}
                />
              ) : (
                <Plus size={17} />
              )}

              {generatingKey
                ? "Generating API key..."
                : "Generate API key"}
            </button>
          </form>


          {!selectedRuntimeId &&
            !loadingModels &&
            modelOptions.length > 0 &&
            runtimeOptions.length === 0 && (
              <p className="api-muted">
                No available deployment runtime was found
                for the selected model.
              </p>
            )}


          {/* GENERATED KEY */}

          {newKey && (
            <div className="api-new-key">
              <div className="api-new-key-heading">
                <ShieldCheck size={19} />

                <strong>Your new API key</strong>
              </div>

              <p>
                Copy this key now. For security, the complete
                key is shown only once.
              </p>

              <div className="api-new-key-value">
                <code>
                  {showNewKey
                    ? newKey
                    : "••••••••••••••••••••••••"}
                </code>

                <button
                  type="button"
                  onClick={() =>
                    setShowNewKey(!showNewKey)
                  }
                  aria-label={
                    showNewKey
                      ? "Hide API key"
                      : "Show API key"
                  }
                >
                  {showNewKey ? (
                    <EyeOff size={16} />
                  ) : (
                    <Eye size={16} />
                  )}
                </button>

                <button
                  type="button"
                  onClick={() =>
                    copyText(
                      newKey,
                      "API key copied. Store it securely."
                    )
                  }
                  aria-label="Copy API key"
                >
                  <Copy size={16} />
                </button>
              </div>

              <button
                type="button"
                className="api-dismiss-key"
                onClick={() => {
                  setNewKey("");
                  setShowNewKey(true);
                }}
              >
                I have saved this key
              </button>
            </div>
          )}
        </section>


        {/* EXISTING API KEYS */}

        <section className="api-panel">
          <div className="api-panel-heading">
            <div>
              <h3>Existing API keys</h3>

              <p>
                Only key prefixes are displayed.
                Plaintext keys are never retrieved.
              </p>
            </div>

            <button
              type="button"
              className="api-quiet-button"
              onClick={loadKeys}
              disabled={
                loadingKeys ||
                !selectedRuntimeId
              }
            >
              <RefreshCw size={15} />
              Refresh
            </button>
          </div>


          {loadingKeys ? (
            <div className="api-loading">
              <Loader2 className="api-spin" />
              Loading API keys...
            </div>
          ) : !selectedRuntimeId ? (
            <div className="api-empty-state">
              <KeyRound size={22} />

              <p>
                Select a deployed runtime to manage its API keys.
              </p>
            </div>
          ) : !keys.length ? (
            <div className="api-empty-state">
              <KeyRound size={22} />

              <p>
                No API keys have been generated for this runtime.
              </p>
            </div>
          ) : (
            <div className="api-table-wrap">
              <table className="api-table">
                <thead>
                  <tr>
                    <th>NAME</th>
                    <th>KEY PREFIX</th>
                    <th>STATUS</th>
                    <th>LAST USED</th>
                    <th>CREATED</th>
                    <th></th>
                  </tr>
                </thead>

                <tbody>
                  {keys.map((key) => (
                    <tr key={key.id}>
                      <td>
                        <strong>{key.name}</strong>
                      </td>

                      <td>
                        <code>
                          {key.key_prefix}••••••
                        </code>
                      </td>

                      <td>
                        <StatusPill
                          status={key.status}
                          success={
                            key.status === "active"
                          }
                        />
                      </td>

                      <td>
                        {formatDate(key.last_used_at)}
                      </td>

                      <td>
                        {formatDate(key.created_at)}
                      </td>

                      <td>
                        {key.status === "active" && (
                          <button
                            type="button"
                            className="api-revoke-button"
                            onClick={() =>
                              handleRevokeKey(key)
                            }
                            disabled={
                              revokingId === key.id
                            }
                          >
                            {revokingId === key.id ? (
                              <Loader2
                                className="api-spin"
                                size={15}
                              />
                            ) : (
                              <Trash2 size={15} />
                            )}

                            Revoke
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </>
    );
  }


  // --------------------------------------------------
  // USAGE AND ANALYTICS TAB
  // --------------------------------------------------

  function renderUsage() {
    return (
      <>
        <div className="api-intro">
          <div>
            <span className="api-eyebrow">
              API ANALYTICS
            </span>

            <h2>Usage and request history</h2>

            <p>
              Monitor prediction traffic, response times
              and failed requests.
            </p>
          </div>

          <div className="api-intro-icon">
            <Activity size={30} />
          </div>
        </div>


        <div className="api-usage-toolbar">
          <span>
            {selectedModel?.name
              ? `Runtime: ${selectedModel.name}`
              : "All your API requests"}
          </span>

          <button
            type="button"
            className="api-quiet-button"
            onClick={() => {
              setUsagePage(1);
              loadUsage();
            }}
            disabled={loadingUsage}
          >
            <RefreshCw size={15} />
            Refresh
          </button>
        </div>


        {loadingUsage && !usage ? (
          <div className="api-loading">
            <Loader2 className="api-spin" />
            Loading usage statistics...
          </div>
        ) : (
          <>
            <div className="api-metrics-grid">
              <MetricCard
                icon={Activity}
                label="Total requests"
                value={formatNumber(
                  summary.total_requests
                )}
                description="All recorded API requests"
              />

              <MetricCard
                icon={CheckCircle2}
                label="Successful"
                value={formatNumber(
                  summary.successful_requests
                )}
                description="Requests completed successfully"
              />

              <MetricCard
                icon={XCircle}
                label="Failed"
                value={formatNumber(
                  summary.failed_requests
                )}
                description="Requests that returned errors"
              />

              <MetricCard
                icon={Activity}
                label="Average response"
                value={`${formatNumber(
                  summary.average_response_time_ms
                )} ms`}
                description="Average inference response time"
              />

              <MetricCard
                icon={Activity}
                label="Requests today"
                value={formatNumber(
                  summary.requests_today
                )}
                description="Recorded today"
              />

              <MetricCard
                icon={CheckCircle2}
                label="Successful today"
                value={formatNumber(
                  summary.successful_today
                )}
                description="Successful requests today"
              />
            </div>


            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>HTTP status distribution</h3>

                  <p>
                    Request counts grouped by HTTP response status.
                  </p>
                </div>
              </div>

              {!statusDistribution.length ? (
                <p className="api-muted">
                  No status information is available yet.
                </p>
              ) : (
                <div className="api-status-distribution">
                  {statusDistribution.map((item) => (
                    <div key={item.http_status}>
                      <span className="api-status-code">
                        {item.http_status}
                      </span>

                      <span>
                        {formatNumber(
                          item.request_count
                        )}{" "}
                        requests
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </section>


            <section className="api-panel">
              <div className="api-panel-heading">
                <div>
                  <h3>Request history</h3>

                  <p>
                    Recent requests made using your API keys.
                  </p>
                </div>

                <span className="api-record-count">
                  {formatNumber(
                    pagination.total_records
                  )}{" "}
                  records
                </span>
              </div>


              {!history.length ? (
                <div className="api-empty-state">
                  <Activity size={22} />

                  <p>
                    No API requests have been recorded for this selection.
                  </p>
                </div>
              ) : (
                <div className="api-table-wrap">
                  <table className="api-table">
                    <thead>
                      <tr>
                        <th>MODEL</th>
                        <th>API KEY</th>
                        <th>METHOD</th>
                        <th>STATUS</th>
                        <th>RESPONSE TIME</th>
                        <th>REQUEST SIZE</th>
                        <th>DATE</th>
                      </tr>
                    </thead>

                    <tbody>
                      {history.map((item) => (
                        <tr key={item.id}>
                          <td>
                            <code>
                              {item.model_slug}
                            </code>
                          </td>

                          <td>
                            {item.api_key_name ||
                              `Key #${item.api_key_id || "—"}`}
                          </td>

                          <td>
                            <span className="api-method">
                              {item.http_method}
                            </span>
                          </td>

                          <td>
                            <StatusPill
                              status={item.http_status}
                              success={
                                Number(item.success) === 1
                              }
                            />
                          </td>

                          <td>
                            {formatNumber(
                              item.response_time_ms
                            )}{" "}
                            ms
                          </td>

                          <td>
                            {formatNumber(
                              item.request_size_bytes
                            )}{" "}
                            B
                          </td>

                          <td>
                            {formatDate(item.created_at)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}


              <div className="api-pagination">
                <span>
                  Page {pagination.page || usagePage} of{" "}
                  {pagination.total_pages || 1}
                </span>

                <div>
                  <button
                    type="button"
                    disabled={
                      loadingUsage ||
                      usagePage <= 1
                    }
                    onClick={() =>
                      setUsagePage((page) =>
                        Math.max(1, page - 1)
                      )
                    }
                  >
                    <ChevronLeft size={16} />
                    Previous
                  </button>

                  <button
                    type="button"
                    disabled={
                      loadingUsage ||
                      usagePage >=
                        Number(
                          pagination.total_pages || 1
                        )
                    }
                    onClick={() =>
                      setUsagePage((page) => page + 1)
                    }
                  >
                    Next
                    <ChevronRight size={16} />
                  </button>
                </div>
              </div>
            </section>
          </>
        )}
      </>
    );
  }


  // --------------------------------------------------
  // MAIN PAGE
  // --------------------------------------------------

  return (
    <div className="api-integration">

      <div className="api-page-header">
        <div>
          <span className="api-eyebrow">
            MODELPORT DEVELOPER PLATFORM
          </span>

          <h1>
            API Documentation &amp; Integration
          </h1>

          <p>
            Deploy your model, connect your application
            and monitor API usage.
          </p>
        </div>

        <a
          href="#api-endpoint"
          onClick={(event) => {
            event.preventDefault();
            setActiveTab("api-docs");
          }}
        >
          <ExternalLink size={15} />
          API reference
        </a>
      </div>


      {/* TABS */}

      <nav className="api-tabs">
        {tabs.map((tab) => {
          const Icon = tab.icon;

          return (
            <button
              type="button"
              key={tab.id}
              className={
                activeTab === tab.id
                  ? "active"
                  : ""
              }
              onClick={() => {
                setActiveTab(tab.id);
                setError("");
                setNotice("");
              }}
            >
              <Icon size={17} />
              {tab.label}
            </button>
          );
        })}
      </nav>


      {/* ERROR MESSAGE */}

      {error && (
        <div
          className="api-alert api-alert-error"
          role="alert"
        >
          <AlertCircle size={17} />

          <span>{error}</span>

          <button
            type="button"
            onClick={() => setError("")}
            aria-label="Dismiss error"
          >
            <X size={16} />
          </button>
        </div>
      )}


      {/* SUCCESS MESSAGE */}

      {notice && (
        <div
          className="api-alert api-alert-success"
          role="status"
        >
          <CheckCircle2 size={17} />

          <span>{notice}</span>

          <button
            type="button"
            onClick={() => setNotice("")}
            aria-label="Dismiss notification"
          >
            <X size={16} />
          </button>
        </div>
      )}


      {/* TAB CONTENT */}

      {activeTab === "api-docs" &&
        renderDocumentation()}

      {activeTab === "api-keys" &&
        renderKeys()}

      {activeTab === "usage" &&
        renderUsage()}


      {/* TROUBLESHOOTING */}

      <section className="api-panel api-troubleshooting">
        <div className="api-panel-heading">
          <div>
            <h3>Troubleshooting</h3>

            <p>
              Common API errors and what to check.
            </p>
          </div>

          <ShieldCheck size={21} />
        </div>

        <div className="api-troubleshooting-grid">
          <div>
            <strong>401 — Unauthorised</strong>

            <p>
              Check that the Bearer API key is valid,
              active, unexpired and belongs to the selected model.
            </p>
          </div>

          <div>
            <strong>400 — Bad request</strong>

            <p>
              Check that the request body contains valid
              JSON and follows the model's input schema.
            </p>
          </div>

          <div>
            <strong>404 — Not found</strong>

            <p>
              Verify the model slug and confirm that
              the public endpoint URL is correct.
            </p>
          </div>

          <div>
            <strong>409 — Runtime not ready</strong>

            <p>
              Check the model runtime workspace and
              deployment readiness.
            </p>
          </div>

          <div>
            <strong>413 — Payload too large</strong>

            <p>
              Reduce the request body. The current
              public endpoint limit is 1 MB.
            </p>
          </div>

          <div>
            <strong>500 / 503 — Server or service error</strong>

            <p>
              Check the inference service health,
              runtime status and server logs.
            </p>
          </div>
        </div>
      </section>
    </div>
  );
}
