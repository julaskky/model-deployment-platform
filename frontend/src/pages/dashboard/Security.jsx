import { useMemo, useState } from "react";
import {
  AlertCircle,
  CheckCircle2,
  Eye,
  EyeOff,
  KeyRound,
  LockKeyhole,
  Save,
  ShieldCheck,
} from "lucide-react";
import "../../styles/security.css";

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

async function changePassword(payload) {
  const csrfToken = await getCsrfToken();

  if (!csrfToken) {
    throw new Error("Unable to obtain a security token.");
  }

  const response = await fetch(`${API_BASE}/settings/change_password.php`, {
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

function PasswordField({
  id,
  label,
  value,
  onChange,
  visible,
  onToggle,
  placeholder,
}) {
  return (
    <div className="security-field">
      <label htmlFor={id}>{label}</label>

      <div className="security-input-wrapper">
        <LockKeyhole size={17} />

        <input
          id={id}
          type={visible ? "text" : "password"}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
          autoComplete="new-password"
          required
        />

        <button
          type="button"
          className="security-visibility-button"
          onClick={onToggle}
          aria-label={visible ? "Hide password" : "Show password"}
        >
          {visible ? <EyeOff size={17} /> : <Eye size={17} />}
        </button>
      </div>
    </div>
  );
}

export default function Security() {
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");

  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const passwordChecks = useMemo(() => ({
    length: newPassword.length >= 12,
    uppercase: /[A-Z]/.test(newPassword),
    lowercase: /[a-z]/.test(newPassword),
    number: /[0-9]/.test(newPassword),
    special: /[^A-Za-z0-9]/.test(newPassword),
  }), [newPassword]);

  const strength = Object.values(passwordChecks).filter(Boolean).length;

  const handleSubmit = async (event) => {
    event.preventDefault();

    setError("");
    setSuccess("");

    if (newPassword.length < 12) {
      setError("Your new password must contain at least 12 characters.");
      return;
    }

    if (newPassword !== confirmPassword) {
      setError("The new password and confirmation do not match.");
      return;
    }

    if (newPassword === currentPassword) {
      setError("Your new password must be different from your current password.");
      return;
    }

    try {
      setSaving(true);

      const result = await changePassword({
        current_password: currentPassword,
        new_password: newPassword,
        confirm_password: confirmPassword,
      });

      setSuccess(result.message || "Password changed successfully.");

      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
    } catch (err) {
      setError(err.message || "Unable to change your password.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="security-page">
      <div className="security-card">
        <div className="security-card-header">
          <div className="security-card-icon">
            <ShieldCheck size={21} />
          </div>

          <div>
            <h2>Password and security</h2>
            <p>
              Update your password to help keep your Modelport account secure.
            </p>
          </div>
        </div>

        <div className="security-information">
          <ShieldCheck size={18} />
          <div>
            <strong>Protect your account</strong>
            <p>
              Use a strong password that you do not use for other websites
              or applications.
            </p>
          </div>
        </div>

        {error && (
          <div className="security-alert security-alert-error">
            <AlertCircle size={18} />
            <span>{error}</span>
          </div>
        )}

        {success && (
          <div className="security-alert security-alert-success">
            <CheckCircle2 size={18} />
            <span>{success}</span>
          </div>
        )}

        <form className="security-form" onSubmit={handleSubmit}>
          <PasswordField
            id="current-password"
            label="Current password"
            value={currentPassword}
            onChange={setCurrentPassword}
            visible={showCurrent}
            onToggle={() => setShowCurrent(!showCurrent)}
            placeholder="Enter your current password"
          />

          <PasswordField
            id="new-password"
            label="New password"
            value={newPassword}
            onChange={setNewPassword}
            visible={showNew}
            onToggle={() => setShowNew(!showNew)}
            placeholder="Enter your new password"
          />

          {newPassword && (
            <div className="security-strength">
              <div className="security-strength-heading">
                <span>Password strength</span>
                <strong>
                  {strength <= 2
                    ? "Weak"
                    : strength <= 4
                      ? "Moderate"
                      : "Strong"}
                </strong>
              </div>

              <div className="security-strength-track">
                <div
                  className={`security-strength-fill strength-${strength}`}
                />
              </div>

              <div className="security-requirements">
                <span className={passwordChecks.length ? "passed" : ""}>
                  At least 12 characters
                </span>
                <span className={passwordChecks.uppercase ? "passed" : ""}>
                  Uppercase letter
                </span>
                <span className={passwordChecks.lowercase ? "passed" : ""}>
                  Lowercase letter
                </span>
                <span className={passwordChecks.number ? "passed" : ""}>
                  Number
                </span>
                <span className={passwordChecks.special ? "passed" : ""}>
                  Special character
                </span>
              </div>
            </div>
          )}

          <PasswordField
            id="confirm-password"
            label="Confirm new password"
            value={confirmPassword}
            onChange={setConfirmPassword}
            visible={showConfirm}
            onToggle={() => setShowConfirm(!showConfirm)}
            placeholder="Re-enter your new password"
          />

          {confirmPassword && (
            <div className={`security-match ${
              newPassword === confirmPassword ? "matched" : "unmatched"
            }`}>
              {newPassword === confirmPassword
                ? "Passwords match."
                : "Passwords do not match."}
            </div>
          )}

          <div className="security-form-actions">
            <button
              type="submit"
              className="security-save-button"
              disabled={saving}
            >
              <Save size={17} />
              {saving ? "Updating password..." : "Update password"}
            </button>
          </div>
        </form>

        <div className="security-footer">
          <KeyRound size={16} />
          <span>
            Your password is securely hashed before it is stored.
          </span>
        </div>
      </div>
    </div>
  );
}
