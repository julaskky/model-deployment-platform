import { useState } from "react";
import {
  ArrowLeft,
  ArrowRight,
  BrainCircuit,
  CheckCircle2,
  Eye,
  EyeOff,
  LoaderCircle,
  LockKeyhole,
  Mail,
  UserRound,
} from "lucide-react";
import { registerUser } from "../../services/auth.js";

export default function Register() {
  const [form, setForm] = useState({
    name: "",
    email: "",
    password: "",
    confirmPassword: "",
  });

  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);

  function updateField(event) {
    setForm({
      ...form,
      [event.target.name]: event.target.value,
    });
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setError("");

    const name = form.name.trim();
    const email = form.email.trim().toLowerCase();

    if (name.length < 2) {
      setError("Please enter your full name.");
      return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError("Please enter a valid email address.");
      return;
    }

    if (form.password.length < 8) {
      setError("Your password must contain at least 8 characters.");
      return;
    }

    if (form.password !== form.confirmPassword) {
      setError("Your passwords do not match.");
      return;
    }

    try {
      setLoading(true);

      await registerUser({
        name,
        email,
        password: form.password,
      });

      setSuccess(true);
    } catch (err) {
      setError(err.message || "Registration failed. Please try again.");
    } finally {
      setLoading(false);
    }
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
          <span className="auth-eyebrow">BUILT FOR ML ENGINEERS</span>
          <h1>
            Your models deserve
            <span> a production home.</span>
          </h1>
          <p>
            Turn your trained machine learning models into production-ready
            APIs without managing infrastructure.
          </p>

          <div className="auth-benefits">
            <div>
              <CheckCircle2 size={18} />
              Upload your trained models
            </div>
            <div>
              <CheckCircle2 size={18} />
              Deploy with a managed runtime
            </div>
            <div>
              <CheckCircle2 size={18} />
              Integrate through secure APIs
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

          {success ? (
            <div className="auth-success">
              <div className="auth-success-icon">
                <CheckCircle2 size={34} />
              </div>
              <h2>Account created</h2>
              <p>
                Your account has been created successfully. You can now sign
                in using your email address and password.
              </p>
              <a href="/login" className="auth-submit-button">
                Continue to sign in
                <ArrowRight size={17} />
              </a>
            </div>
          ) : (
            <>
              <div className="auth-heading">
                <span className="auth-eyebrow">GET STARTED</span>
                <h2>Create your account</h2>
                <p>
                  Set up your workspace and start deploying your models.
                </p>
              </div>

              {error && (
                <div className="auth-error" role="alert">
                  {error}
                </div>
              )}

              <form onSubmit={handleSubmit} className="auth-form">
                <label htmlFor="register-name">Full name</label>
                <div className="auth-input-wrap">
                  <UserRound size={18} />
                  <input
                    id="register-name"
                    name="name"
                    type="text"
                    placeholder="Your full name"
                    value={form.name}
                    onChange={updateField}
                    autoComplete="name"
                    required
                  />
                </div>

                <label htmlFor="register-email">Email address</label>
                <div className="auth-input-wrap">
                  <Mail size={18} />
                  <input
                    id="register-email"
                    name="email"
                    type="email"
                    placeholder="you@example.com"
                    value={form.email}
                    onChange={updateField}
                    autoComplete="email"
                    required
                  />
                </div>

                <label htmlFor="register-password">Password</label>
                <div className="auth-input-wrap">
                  <LockKeyhole size={18} />
                  <input
                    id="register-password"
                    name="password"
                    type={showPassword ? "text" : "password"}
                    placeholder="At least 8 characters"
                    value={form.password}
                    onChange={updateField}
                    autoComplete="new-password"
                    minLength={8}
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

                <label htmlFor="register-confirm-password">
                  Confirm password
                </label>
                <div className="auth-input-wrap">
                  <LockKeyhole size={18} />
                  <input
                    id="register-confirm-password"
                    name="confirmPassword"
                    type={showPassword ? "text" : "password"}
                    placeholder="Re-enter your password"
                    value={form.confirmPassword}
                    onChange={updateField}
                    autoComplete="new-password"
                    required
                  />
                </div>

                <button
                  type="submit"
                  className="auth-submit-button"
                  disabled={loading}
                >
                  {loading ? (
                    <>
                      <LoaderCircle className="auth-spinner" size={18} />
                      Creating account...
                    </>
                  ) : (
                    <>
                      Create account
                      <ArrowRight size={17} />
                    </>
                  )}
                </button>
              </form>

              <p className="auth-switch">
                Already have an account? <a href="/login">Sign in</a>
              </p>

              <p className="auth-legal">
                By creating an account, you agree to use the platform
                responsibly and keep your account credentials secure.
              </p>
            </>
          )}
        </div>
      </section>
    </main>
  );
}