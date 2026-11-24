import { useCallback, useEffect, useState } from "react";
import {
  AlertCircle,
  Bell,
  CheckCircle2,
  Clock,
  Code2,
  Mail,
  Save,
  ShieldCheck,
  User,
  UserX,
  Trash2,
  X,
  LockKeyhole,
  AlertTriangle,
} from "lucide-react";
import "../../styles/settings.css";
import Security from "./Security.jsx";

const API_BASE =
  import.meta.env.VITE_API_BASE_URL ||
  "http://localhost/model-deployment-platform/api";

async function readResponse(response) {
  const result = await response.json().catch(() => ({}));

  if (response.status === 401) {
    window.location.href = "/login";
    throw new Error("Authentication required.");
  }

  if (!response.ok || result.success === false) {
    throw new Error(
      result.message || "Something went wrong. Please try again."
    );
  }

  return result;
}

async function getCsrfToken() {
  const response = await fetch(`${API_BASE}/auth/csrf.php`, {
    credentials: "include",
  });

  const result = await readResponse(response);

  return (
    result.csrf_token ||
    result.token ||
    result.data?.csrf_token ||
    result.data?.token ||
    ""
  );
}

async function getProfile() {
  const response = await fetch(`${API_BASE}/settings/profile.php`, {
    credentials: "include",
  });

  return readResponse(response);
}

async function updateProfile(name) {
  const csrfToken = await getCsrfToken();

  if (!csrfToken) {
    throw new Error("Unable to obtain a security token.");
  }

  const response = await fetch(`${API_BASE}/settings/profile.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": csrfToken,
    },
    body: JSON.stringify({ name }),
  });

  return readResponse(response);
}

async function getApiPreferences() {
  const response = await fetch(
    `${API_BASE}/settings/api_preferences.php`,
    { credentials: "include" }
  );

  return readResponse(response);
}

async function updateApiPreferences(preferences) {
  const csrfToken = await getCsrfToken();

  if (!csrfToken) {
    throw new Error("Unable to obtain a security token.");
  }

  const response = await fetch(
    `${API_BASE}/settings/api_preferences.php`,
    {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken,
      },
      body: JSON.stringify(preferences),
    }
  );

  return readResponse(response);
}

async function submitAccountAction(endpoint, payload) {
  const csrfToken = await getCsrfToken();

  if (!csrfToken) {
    throw new Error("Unable to obtain a security token.");
  }

  const response = await fetch(`${API_BASE}/settings/${endpoint}.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": csrfToken,
    },
    body: JSON.stringify(payload),
  });

  return readResponse(response);
}

function formatDate(value) {
  if (!value) return "—";

  const date = new Date(value.replace(" ", "T"));

  if (Number.isNaN(date.getTime())) return value;

  return date.toLocaleDateString(undefined, {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

const defaultPreferences = {
  default_api_version: "v1",
  request_timeout_seconds: 30,
  notify_usage: true,
  notify_errors: true,
};

export default function Settings() {
  const [activeTab, setActiveTab] = useState("profile");

  const [profile, setProfile] = useState(null);
  const [name, setName] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const [apiPreferences, setApiPreferences] = useState(
    defaultPreferences
  );
  const [preferencesLoading, setPreferencesLoading] = useState(false);
  const [preferencesSaving, setPreferencesSaving] = useState(false);
  const [preferencesLoaded, setPreferencesLoaded] = useState(false);
  const [preferencesError, setPreferencesError] = useState("");
  const [preferencesSuccess, setPreferencesSuccess] = useState("");

  // Account management state.
  const [accountDialog, setAccountDialog] = useState("");
  const [accountPassword, setAccountPassword] = useState("");
  const [deleteConfirmation, setDeleteConfirmation] = useState("");
  const [accountSubmitting, setAccountSubmitting] = useState(false);
  const [accountError, setAccountError] = useState("");

  const loadProfile = useCallback(async () => {
    try {
      setLoading(true);
      setError("");

      const result = await getProfile();
      const data = result.data ?? result;
      const currentProfile = data.profile;

      setProfile(currentProfile);
      setName(currentProfile?.name || "");
    } catch (err) {
      setError(err.message || "Unable to load your profile.");
    } finally {
      setLoading(false);
    }
  }, []);

  const loadApiPreferences = useCallback(async () => {
    try {
      setPreferencesLoading(true);
      setPreferencesError("");

      const result = await getApiPreferences();
      const data = result.data ?? result;

      setApiPreferences({
        ...defaultPreferences,
        ...(data.preferences || {}),
      });

      setPreferencesLoaded(true);
    } catch (err) {
      setPreferencesError(
        err.message || "Unable to load API preferences."
      );
    } finally {
      setPreferencesLoading(false);
    }
  }, []);

  useEffect(() => {
    loadProfile();
  }, [loadProfile]);

  useEffect(() => {
    if (activeTab === "api" && !preferencesLoaded) {
      loadApiPreferences();
    }
  }, [activeTab, preferencesLoaded, loadApiPreferences]);

  const handleSubmit = async (event) => {
    event.preventDefault();

    setSuccess("");
    setError("");

    const trimmedName = name.trim();

    if (!trimmedName) {
      setError("Name is required.");
      return;
    }

    try {
      setSaving(true);

      const result = await updateProfile(trimmedName);
      const data = result.data ?? result;

      if (data.profile) {
        setProfile(data.profile);
        setName(data.profile.name || "");
      }

      setSuccess(result.message || "Profile updated successfully.");
    } catch (err) {
      setError(err.message || "Unable to update your profile.");
    } finally {
      setSaving(false);
    }
  };

  const handlePreferenceChange = (field, value) => {
    setApiPreferences((current) => ({
      ...current,
      [field]: value,
    }));

    setPreferencesSuccess("");
    setPreferencesError("");
  };

  const handlePreferencesSubmit = async (event) => {
    event.preventDefault();

    setPreferencesSuccess("");
    setPreferencesError("");

    try {
      setPreferencesSaving(true);

      const result = await updateApiPreferences(apiPreferences);
      const data = result.data ?? result;

      if (data.preferences) {
        setApiPreferences({
          ...defaultPreferences,
          ...data.preferences,
        });
      }

      setPreferencesSuccess(
        result.message || "API preferences saved successfully."
      );
    } catch (err) {
      setPreferencesError(
        err.message || "Unable to save API preferences."
      );
    } finally {
      setPreferencesSaving(false);
    }
  };

  const openAccountDialog = (type) => {
    setAccountPassword("");
    setDeleteConfirmation("");
    setAccountError("");
    setAccountDialog(type);
  };

  const closeAccountDialog = () => {
    if (accountSubmitting) return;

    setAccountDialog("");
    setAccountPassword("");
    setDeleteConfirmation("");
    setAccountError("");
  };

  const handleAccountAction = async (event) => {
    event.preventDefault();

    setAccountError("");

    if (!accountPassword) {
      setAccountError("Enter your current password.");
      return;
    }

    if (
      accountDialog === "delete" &&
      deleteConfirmation !== "DELETE MY ACCOUNT"
    ) {
      setAccountError(
        "Enter DELETE MY ACCOUNT exactly to confirm deletion."
      );
      return;
    }

    const isDeletion = accountDialog === "delete";

    try {
      setAccountSubmitting(true);

      const result = await submitAccountAction(
        isDeletion ? "delete_account" : "deactivate_account",
        isDeletion
          ? {
              current_password: accountPassword,
              confirmation: deleteConfirmation,
            }
          : {
              current_password: accountPassword,
            }
      );

      window.alert(
        result.message ||
          (isDeletion
            ? "Your account has been closed."
            : "Your account has been deactivated.")
      );

      window.location.href = "/login";
    } catch (err) {
      setAccountError(
        err.message || "The account operation could not be completed."
      );
    } finally {
      setAccountSubmitting(false);
    }
  };

  const switchTab = (tab) => {
    setActiveTab(tab);
    setError("");
    setSuccess("");
    setPreferencesError("");
    setPreferencesSuccess("");
  };

  return (
    <section className="settings-page">
      <div className="settings-header">
        <div>
          <span className="settings-eyebrow">ACCOUNT SETTINGS</span>
          <h1>Settings</h1>
          <p>
            Manage your account information, security and API preferences.
          </p>
        </div>
      </div>

      {activeTab === "profile" && (
        <>
          {error && (
            <div className="settings-alert settings-alert-error">
              <AlertCircle size={18} />
              <span>{error}</span>
            </div>
          )}

          {success && (
            <div className="settings-alert settings-alert-success">
              <CheckCircle2 size={18} />
              <span>{success}</span>
            </div>
          )}
        </>
      )}

      <div className="settings-layout">
        <aside className="settings-navigation">
          <button
            type="button"
            className={`settings-nav-item ${
              activeTab === "profile" ? "active" : ""
            }`}
            onClick={() => switchTab("profile")}
          >
            <User size={18} />
            <div>
              <strong>Profile</strong>
              <span>Personal information</span>
            </div>
          </button>

          <button
            type="button"
            className={`settings-nav-item ${
              activeTab === "security" ? "active" : ""
            }`}
            onClick={() => switchTab("security")}
          >
            <ShieldCheck size={18} />
            <div>
              <strong>Security</strong>
              <span>Password and account security</span>
            </div>
          </button>

          <button
            type="button"
            className={`settings-nav-item ${
              activeTab === "api" ? "active" : ""
            }`}
            onClick={() => switchTab("api")}
          >
            <Code2 size={18} />
            <div>
              <strong>API Preferences</strong>
              <span>API defaults and notifications</span>
            </div>
          </button>

          <button
            type="button"
            className={`settings-nav-item ${
              activeTab === "account" ? "active" : ""
            }`}
            onClick={() => switchTab("account")}
          >
            <UserX size={18} />
            <div>
              <strong>Account Management</strong>
              <span>Deactivate or delete account</span>
            </div>
          </button>
        </aside>

        <div className="settings-content">
          {activeTab === "profile" && (
            <>
              {loading ? (
                <div className="settings-loading">
                  Loading your profile...
                </div>
              ) : (
                <div className="settings-card">
                  <div className="settings-card-header">
                    <div className="settings-card-icon">
                      <User size={20} />
                    </div>

                    <div>
                      <h2>Profile information</h2>
                      <p>
                        Update the name associated with your Modelport
                        account.
                      </p>
                    </div>
                  </div>

                  <form onSubmit={handleSubmit} className="settings-form">
                    <div className="settings-field">
                      <label htmlFor="settings-name">Full name</label>

                      <div className="settings-input-wrapper">
                        <User size={17} />
                        <input
                          id="settings-name"
                          type="text"
                          value={name}
                          onChange={(event) =>
                            setName(event.target.value)
                          }
                          placeholder="Enter your full name"
                          maxLength={150}
                          autoComplete="name"
                        />
                      </div>
                    </div>

                    <div className="settings-field">
                      <label htmlFor="settings-email">
                        Email address
                      </label>

                      <div className="settings-input-wrapper settings-input-disabled">
                        <Mail size={17} />
                        <input
                          id="settings-email"
                          type="email"
                          value={profile?.email || ""}
                          disabled
                          readOnly
                        />
                      </div>

                      <small>
                        Email changes require a separate verification
                        process.
                      </small>
                    </div>

                    <div className="settings-account-details">
                      <div>
                        <span>Account status</span>
                        <strong className="settings-status">
                          <CheckCircle2 size={15} />
                          {profile?.status || "Active"}
                        </strong>
                      </div>

                      <div>
                        <span>Member since</span>
                        <strong>
                          {formatDate(profile?.created_at)}
                        </strong>
                      </div>
                    </div>

                    <div className="settings-form-actions">
                      <button
                        type="submit"
                        className="settings-save-button"
                        disabled={saving}
                      >
                        <Save size={17} />
                        {saving ? "Saving..." : "Save changes"}
                      </button>
                    </div>
                  </form>
                </div>
              )}
            </>
          )}

          {activeTab === "security" && <Security />}

          {activeTab === "api" && (
            <div className="settings-card">
              <div className="settings-card-header">
                <div className="settings-card-icon">
                  <Code2 size={20} />
                </div>

                <div>
                  <h2>API Preferences</h2>
                  <p>
                    Configure your preferred defaults for API integrations.
                  </p>
                </div>
              </div>

              {preferencesError && (
                <div className="settings-alert settings-alert-error">
                  <AlertCircle size={18} />
                  <span>{preferencesError}</span>
                </div>
              )}

              {preferencesSuccess && (
                <div className="settings-alert settings-alert-success">
                  <CheckCircle2 size={18} />
                  <span>{preferencesSuccess}</span>
                </div>
              )}

              {preferencesLoading ? (
                <div className="settings-loading">
                  Loading API preferences...
                </div>
              ) : (
                <form
                  className="settings-form"
                  onSubmit={handlePreferencesSubmit}
                >
                  <div className="api-preference-section">
                    <div className="api-preference-heading">
                      <Code2 size={19} />
                      <div>
                        <h3>API configuration</h3>
                        <p>
                          Set the default values used when preparing
                          your API integrations.
                        </p>
                      </div>
                    </div>

                    <div className="settings-field">
                      <label htmlFor="default-api-version">
                        Default API version
                      </label>
                      <select
                        id="default-api-version"
                        value={apiPreferences.default_api_version}
                        onChange={(event) =>
                          handlePreferenceChange(
                            "default_api_version",
                            event.target.value
                          )
                        }
                      >
                        <option value="v1">Version 1 (v1)</option>
                      </select>
                      <small>
                        Version 1 is currently the supported public API
                        version.
                      </small>
                    </div>

                    <div className="settings-field">
                      <label htmlFor="api-timeout">
                        Preferred request timeout
                      </label>
                      <div className="settings-input-wrapper">
                        <Clock size={17} />
                        <select
                          id="api-timeout"
                          value={apiPreferences.request_timeout_seconds}
                          onChange={(event) =>
                            handlePreferenceChange(
                              "request_timeout_seconds",
                              Number(event.target.value)
                            )
                          }
                        >
                          <option value={10}>10 seconds</option>
                          <option value={30}>30 seconds</option>
                          <option value={60}>60 seconds</option>
                          <option value={120}>120 seconds</option>
                        </select>
                      </div>
                      <small>
                        A preferred timeout for your client-side API
                        integrations. It does not change server-side
                        execution limits.
                      </small>
                    </div>
                  </div>

                  <div className="api-preference-section">
                    <div className="api-preference-heading">
                      <Bell size={19} />
                      <div>
                        <h3>Notification preferences</h3>
                        <p>
                          Choose which API-related notifications you
                          would like to receive when notification
                          delivery is enabled.
                        </p>
                      </div>
                    </div>

                    <label className="api-preference-toggle">
                      <span>
                        <strong>API usage notifications</strong>
                        <small>
                          Receive updates about API usage and limits.
                        </small>
                      </span>
                      <input
                        type="checkbox"
                        checked={Boolean(apiPreferences.notify_usage)}
                        onChange={(event) =>
                          handlePreferenceChange(
                            "notify_usage",
                            event.target.checked
                          )
                        }
                      />
                    </label>

                    <label className="api-preference-toggle">
                      <span>
                        <strong>API error notifications</strong>
                        <small>
                          Receive notifications about failed API requests.
                        </small>
                      </span>
                      <input
                        type="checkbox"
                        checked={Boolean(apiPreferences.notify_errors)}
                        onChange={(event) =>
                          handlePreferenceChange(
                            "notify_errors",
                            event.target.checked
                          )
                        }
                      />
                    </label>
                  </div>

                  <div className="settings-form-actions">
                    <button
                      type="submit"
                      className="settings-save-button"
                      disabled={preferencesSaving}
                    >
                      <Save size={17} />
                      {preferencesSaving
                        ? "Saving..."
                        : "Save API preferences"}
                    </button>
                  </div>
                </form>
              )}
            </div>
          )}

          {activeTab === "account" && (
            <div className="settings-card account-management-card">
              <div className="settings-card-header">
                <div className="settings-card-icon">
                  <ShieldCheck size={20} />
                </div>

                <div>
                  <h2>Account Management</h2>
                  <p>
                    Manage the lifecycle of your Modelport account.
                  </p>
                </div>
              </div>

              <div className="account-management-intro">
                <AlertTriangle size={19} />
                <p>
                  These actions affect your account access and deployed
                  services. Read each description carefully before
                  proceeding.
                </p>
              </div>

              <div className="account-action-card">
                <div className="account-action-icon">
                  <UserX size={21} />
                </div>

                <div className="account-action-content">
                  <h3>Deactivate account</h3>
                  <p>
                    Temporarily disable your account. Your account data
                    will be retained, but you will lose access. Active
                    API keys will be revoked and running inference
                    services will be stopped.
                  </p>
                  <button
                    type="button"
                    className="account-deactivate-button"
                    onClick={() => openAccountDialog("deactivate")}
                  >
                    Deactivate account
                  </button>
                </div>
              </div>

              <div className="account-action-divider" />

              <div className="account-action-card account-action-danger">
                <div className="account-action-icon">
                  <Trash2 size={21} />
                </div>

                <div className="account-action-content">
                  <h3>Delete account permanently</h3>
                  <p>
                    Permanently close your account and anonymise its
                    personal account details. This action cannot be
                    reversed through normal login. Certain billing,
                    audit and model records may be retained where
                    required.
                  </p>
                  <button
                    type="button"
                    className="account-delete-button"
                    onClick={() => openAccountDialog("delete")}
                  >
                    Delete account
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {accountDialog && (
        <div
          className="account-dialog-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeAccountDialog();
            }
          }}
        >
          <div
            className="account-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="account-dialog-title"
          >
            <div className="account-dialog-header">
              <div
                className={`account-dialog-icon ${
                  accountDialog === "delete" ? "danger" : "warning"
                }`}
              >
                {accountDialog === "delete" ? (
                  <Trash2 size={23} />
                ) : (
                  <UserX size={23} />
                )}
              </div>

              <button
                type="button"
                className="account-dialog-close"
                onClick={closeAccountDialog}
                disabled={accountSubmitting}
                aria-label="Close dialog"
              >
                <X size={20} />
              </button>
            </div>

            <h2 id="account-dialog-title">
              {accountDialog === "delete"
                ? "Permanently delete your account?"
                : "Deactivate your account?"}
            </h2>

            <p className="account-dialog-description">
              {accountDialog === "delete"
                ? "This will close your account and anonymise personal account details. This action cannot be undone through normal login."
                : "Your account will be disabled, active API keys revoked and running inference services stopped. Your account data will be retained."}
            </p>

            <form
              className="account-dialog-form"
              onSubmit={handleAccountAction}
            >
              <div className="settings-field">
                <label htmlFor="account-current-password">
                  Confirm your current password
                </label>

                <div className="settings-input-wrapper">
                  <LockKeyhole size={17} />
                  <input
                    id="account-current-password"
                    type="password"
                    value={accountPassword}
                    onChange={(event) =>
                      setAccountPassword(event.target.value)
                    }
                    placeholder="Enter your current password"
                    autoComplete="current-password"
                    required
                  />
                </div>
              </div>

              {accountDialog === "delete" && (
                <div className="settings-field">
                  <label htmlFor="delete-account-confirmation">
                    Type DELETE MY ACCOUNT to confirm
                  </label>

                  <input
                    id="delete-account-confirmation"
                    className="account-confirmation-input"
                    type="text"
                    value={deleteConfirmation}
                    onChange={(event) =>
                      setDeleteConfirmation(event.target.value)
                    }
                    placeholder="DELETE MY ACCOUNT"
                    autoComplete="off"
                    required
                  />
                </div>
              )}

              {accountError && (
                <div className="settings-alert settings-alert-error">
                  <AlertCircle size={18} />
                  <span>{accountError}</span>
                </div>
              )}

              <div className="account-dialog-actions">
                <button
                  type="button"
                  className="account-cancel-button"
                  onClick={closeAccountDialog}
                  disabled={accountSubmitting}
                >
                  Cancel
                </button>

                <button
                  type="submit"
                  className={
                    accountDialog === "delete"
                      ? "account-dialog-delete-button"
                      : "account-dialog-deactivate-button"
                  }
                  disabled={
                    accountSubmitting ||
                    !accountPassword ||
                    (accountDialog === "delete" &&
                      deleteConfirmation !== "DELETE MY ACCOUNT")
                  }
                >
                  {accountSubmitting
                    ? "Processing..."
                    : accountDialog === "delete"
                      ? "Permanently delete"
                      : "Deactivate account"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </section>
  );
}
