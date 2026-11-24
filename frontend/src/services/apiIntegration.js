
const API_BASE = "http://localhost/model-deployment-platform/api";

async function readResponse(response) {
  const text = await response.text();
  let result;

  try {
    result = text ? JSON.parse(text) : {};
  } catch {
    throw new Error("The server returned an invalid response.");
  }

  if (!response.ok || result.success === false) {
    throw new Error(
      result.message || `Request failed with HTTP ${response.status}.`
    );
  }

  return result;
}

async function getCsrfToken() {
  const response = await fetch(`${API_BASE}/auth/csrf.php`, {
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  const result = await readResponse(response);
  const token =
    result.csrf_token ||
    result.data?.csrf_token ||
    result.token;

  if (!token) {
    throw new Error("Unable to initialise the security token.");
  }

  return token;
}

async function get(url) {
  const response = await fetch(url, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  return readResponse(response);
}

async function post(url, payload) {
  const token = await getCsrfToken();

  const response = await fetch(url, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-CSRF-Token": token,
    },
    body: JSON.stringify(payload),
  });

  return readResponse(response);
}

export async function getModelVersions(modelId) {
  return get(
    `${API_BASE}/models/versions.php?id=${encodeURIComponent(modelId)}`
  );
}

export async function getRuntimeStatus(versionId) {
  return get(
    `${API_BASE}/models/runtime_status.php?model_version_id=${encodeURIComponent(versionId)}`
  );
}

export async function listApiKeys(runtimeId) {
  return get(
    `${API_BASE}/models/list_api_keys.php?runtime_id=${encodeURIComponent(runtimeId)}`
  );
}

export async function generateApiKey(runtimeId, name) {
  return post(`${API_BASE}/models/generate_api_key.php`, {
    runtime_id: runtimeId,
    name,
  });
}

export async function revokeApiKey(apiKeyId) {
  return post(`${API_BASE}/models/revoke_api_key.php`, {
    api_key_id: apiKeyId,
  });
}

export async function getApiUsage({ runtimeId, page = 1, limit = 20 } = {}) {
  const params = new URLSearchParams({
    page: String(page),
    limit: String(limit),
  });

  if (runtimeId) {
    params.set("runtime_id", String(runtimeId));
  }

  return get(`${API_BASE}/models/api_usage.php?${params.toString()}`);
}

export async function testPublicPrediction(slug, apiKey, payload) {
  const response = await fetch(
    `${API_BASE}/v1/models/${encodeURIComponent(slug)}/predict`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${apiKey}`,
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(payload),
    }
  );

  const text = await response.text();
  let result;

  try {
    result = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(
      `The prediction endpoint returned an invalid response (HTTP ${response.status}).`
    );
  }

  return {
    status: response.status,
    ok: response.ok && result.success !== false,
    body: result,
  };
}

export { API_BASE };
