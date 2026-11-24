const API_BASE = "http://localhost/model-deployment-platform/api";

let csrfToken = null;

async function parseResponse(response) {
  const data = await response.json().catch(() => ({}));

  if (!response.ok || data.success === false) {
    throw new Error(
      data.message ||
      data.error ||
      `Request failed with status ${response.status}`
    );
  }

  return data;
}

export async function getCsrfToken() {
  const response = await fetch(`${API_BASE}/auth/csrf.php`, {
    method: "GET",
    credentials: "include",
    headers: {
      Accept: "application/json",
    },
  });

  const data = await parseResponse(response);

  if (!data.csrf_token) {
    throw new Error("Could not initialise security token. Please try again.");
  }

  csrfToken = data.csrf_token;
  return csrfToken;
}

async function postAuth(endpoint, payload = {}) {
  const token = await getCsrfToken();

  const response = await fetch(`${API_BASE}/auth/${endpoint}.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-CSRF-Token": token,
    },
    body: JSON.stringify(payload),
  });

  const data = await parseResponse(response);

  // Use a rotated token if the PHP endpoint returns one.
  if (data.csrf_token) {
    csrfToken = data.csrf_token;
  }

  return data;
}

export async function registerUser({ name, email, password }) {
  return postAuth("register", { name, email, password });
}

export async function loginUser({ email, password }) {
  return postAuth("login", { email, password });
}

export async function logoutUser() {
  return postAuth("logout");
}

export async function getCurrentUser() {
  const response = await fetch(`${API_BASE}/auth/me.php`, {
    method: "GET",
    credentials: "include",
    headers: {
      Accept: "application/json",
    },
  });

  const data = await parseResponse(response);
  return data.user;
}