
import { useEffect, useState } from "react";
import {
  ArrowLeft,
  ArrowRight,
  BrainCircuit,
  Eye,
  EyeOff,
  LoaderCircle,
  LockKeyhole,
  Mail,
} from "lucide-react";
import { getCurrentUser, loginUser } from "../../services/auth.js";

export default function Login() {
  const [form, setForm] = useState({
    email: "",
    password: "",
  });

  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [checkingSession, setCheckingSession] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;

    async function checkSession() {
      try {
        await getCurrentUser();

        if (active) {
          window.location.replace("/dashboard");
        }
      } catch {
        // No active session: show the login form.
      } finally {
        if (active) {
          setCheckingSession(false);
        }
      }
    }

    checkSession();

    return () => {
      active = false;
    };
  }, []);

  function updateField(event) {
    setForm((previous) => ({
      ...previous,
      [event.target.name]: event.target.value,
    }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setError("");

    const email = form.email.trim().toLowerCase();

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError("Please enter a valid email address.");
      return;
    }

    if (!form.password) {
      setError("Please enter your password.");
      return;
    }

    try {
      setLoading(true);

      await loginUser({
        email,
        password: form.password,
      });

      // Confirm that the server recognises the new session.
      await getCurrentUser();

      window.location.href = "/dashboard";
    } catch (err) {
      setError(
        err.message || "Unable to sign in. Check your credentials."
      );
      setLoading(false);
    }
  }

  if (checkingSession) {
    return (
      <div className="auth-loading-state">
        <LoaderCircle className="auth-spinner" size={25} />
        <p>Checking your session...</p>
      </div>
    );
  }

  return (
    <main className="auth-page">
      <section className="auth-visual">
        <a href="/" className="auth-brand">
          <span className="auth-brand-icon">
            <BrainCircuit size={23} />
          </span>
          <span>modelport</span>
        </a>

        <div className="auth-visual-content">
          <span className="auth-eyebrow">YOUR MODEL. YOUR API.</span>

          <h1>
            Everything you need
            <span> to deploy with confidence.</span>
          </h1>

          <p>
            Bring your machine learning models into production through a
            streamlined deployment and API workflow.
          </p>

          <div className="auth-benefits">
            <div>
              <BrainCircuit size={18} />
              A dedicated model workspace
            </div>
            <div>
              <LockKeyhole size={18} />
              Secure account authentication
            </div>
            <div>
              <ArrowRight size={18} />
              A straightforward deployment workflow
            </div>
          </div>
        </div>

        <div className="auth-visual-footer">
          © 2026 Modelport. Built for the people who build intelligence.
        </div>
      </section>

      <section className="auth-form-section">
        <a href="/" className="auth-mobile-brand">
          <BrainCircuit size={22} />
          modelport
        </a>

        <div className="auth-form-wrapper">
          <a href="/" className="auth-back-link">
            <ArrowLeft size={16} />
            Back to home
          </a>

          <div className="auth-heading">
            <span className="auth-eyebrow">WELCOME BACK</span>
            <h2>Sign in to Modelport</h2>
            <p>
              Enter your account details to access your workspace.
            </p>
          </div>

          {error && (
            <div className="auth-error" role="alert">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="auth-form">
            <label htmlFor="login-email">Email address</label>

            <div className="auth-input-wrap">
              <Mail size={18} />
              <input
                id="login-email"
                name="email"
                type="email"
                placeholder="you@example.com"
                value={form.email}
                onChange={updateField}
                autoComplete="email"
                required
              />
            </div>

            <label htmlFor="login-password">Password</label>

            <div className="auth-input-wrap">
              <LockKeyhole size={18} />
              <input
                id="login-password"
                name="password"
                type={showPassword ? "text" : "password"}
                placeholder="Enter your password"
                value={form.password}
                onChange={updateField}
                autoComplete="current-password"
                required
              />

              <button
                type="button"
                className="auth-password-toggle"
                onClick={() => setShowPassword(!showPassword)}
                aria-label={showPassword ? "Hide password" : "Show password"}
              >
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>

            <button
              type="submit"
              className="auth-submit-button"
              disabled={loading}
            >
              {loading ? (
                <>
                  <LoaderCircle className="auth-spinner" size={18} />
                  Signing in...
                </>
              ) : (
                <>
                  Sign in
                  <ArrowRight size={17} />
                </>
              )}
            </button>
          </form>

          <p className="auth-switch">
            Don't have an account? <a href="/register">Create one</a>
          </p>

          <p className="auth-legal">
            Your session is protected using server-side authentication
            and CSRF protection.
          </p>
        </div>
      </section>
    </main>
  );
}
