
import { useCallback, useEffect, useState } from "react";
import {
  Activity,
  AlertCircle,
  ArrowRight,
  ArrowUpRight,
  Bell,
  Code2,
  Boxes,
  CheckCircle2,
  ChevronDown,
  CircleDashed,
  CreditCard,
  Database,
  FileArchive,
  KeyRound,
  Layers3,
  LayoutDashboard,
  LogOut,
  Menu,
  Plus,
  Rocket,
  Search,
  Settings,
  ShieldCheck,
  X,
  Zap,
} from "lucide-react";

import {
  getCurrentUser,
  logoutUser,
} from "../../services/auth.js";

import APIIntegration from "../api/APIIntegration.jsx";
import Deployments from "./Deployments.jsx";
import Billing from "./Billing.jsx";
import SettingsPage from "./Settings.jsx";

import "../../styles/dashboard.css";


const API_BASE = "http://localhost/model-deployment-platform/api";


const navigation = [
  { id: "overview", label: "Overview", icon: LayoutDashboard },
  { id: "models", label: "Models", icon: Layers3 },
  { id: "deployments", label: "Deployments", icon: Rocket },
  { id: "api-docs", label: "API Documentation", icon: Code2 },
  { id: "api-keys", label: "API keys", icon: KeyRound },
  { id: "usage", label: "Usage", icon: Activity },
];



const secondaryNavigation = [
  { id: "billing", label: "Billing", icon: CreditCard },
  { id: "settings", label: "Settings", icon: Settings },
];

function formatNumber(value) {
  return new Intl.NumberFormat("en-US").format(
    Number(value || 0)
  );
}

function formatBytes(bytes) {
  const value = Number(bytes || 0);

  if (value === 0) return "0 Bytes";

  const units = ["Bytes", "KB", "MB", "GB", "TB"];

  const index = Math.min(
    Math.floor(Math.log(value) / Math.log(1024)),
    units.length - 1
  );

  return `${(value / Math.pow(1024, index)).toFixed(2)} ${units[index]}`;
}

function formatDate(date) {
  if (!date) return "—";

  const parsed = new Date(date.replace(" ", "T"));

  if (Number.isNaN(parsed.getTime())) return "—";

  return parsed.toLocaleDateString("en-US", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function StatusBadge({ status }) {
  const labels = {
    pending_upload: "Pending upload",
    uploaded: "Uploaded",
    analyzing: "Analyzing",
    ready: "Ready",
    needs_configuration: "Needs configuration",
    unsupported: "Unsupported",
    deployment_failed: "Deployment failed",
    deployed: "Deployed",
  };

  const classes = {
    ready: "status-ready",
    deployed: "status-deployed",
    analyzing: "status-processing",
    uploaded: "status-processing",
    pending_upload: "status-pending",
    needs_configuration: "status-warning",
    unsupported: "status-danger",
    deployment_failed: "status-danger",
  };

  return (
    <span
      className={`model-status ${
        classes[status] || "status-pending"
      }`}
    >
      <span className="status-dot" />
      {labels[status] || status || "Unknown"}
    </span>
  );
}

function StatCard({
  icon: Icon,
  label,
  value,
  description,
  tone,
}) {
  return (
    <article className="dashboard-stat-card">
      <div className="stat-card-top">
        <span className={`stat-icon ${tone}`}>
          <Icon size={20} />
        </span>

        <span className="stat-period">All time</span>
      </div>

      <div className="stat-value">{value}</div>
      <div className="stat-label">{label}</div>

      <p className="stat-description">
        {description}
      </p>
    </article>
  );
}

function EmptyModels({ onUpload }) {
  return (
    <div className="dashboard-empty">
      <div className="empty-icon">
        <Boxes size={30} />
      </div>

      <h3>Your model workspace is ready</h3>

      <p>
        You haven't added any models yet. Upload your first
        trained model package to get started.
      </p>

      <button
        type="button"
        className="dashboard-primary-button"
        onClick={onUpload}
      >
        <Plus size={17} />
        Upload your first model
      </button>
    </div>
  );
}

export default function Dashboard() {
  const [data, setData] = useState(null);
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [activeSection, setActiveSection] = useState("overview");
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [loggingOut, setLoggingOut] = useState(false);

  const loadDashboard = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      const currentUser = await getCurrentUser();

      setUser(currentUser);

      const response = await fetch(
        `${API_BASE}/dashboard/overview.php`,
        {
          method: "GET",
          credentials: "include",
          headers: {
            Accept: "application/json",
          },
        }
      );

      const result = await response.json().catch(() => ({}));

      if (response.status === 401) {
        window.location.href = "/login";
        return;
      }

      if (!response.ok || !result.success) {
        throw new Error(
          result.message || "Unable to load your dashboard."
        );
      }

      setData(result);
    } catch (err) {
      if (
        err.message?.toLowerCase().includes("authentication")
      ) {
        window.location.href = "/login";
        return;
      }

      setError(
        err.message ||
        "Something went wrong while loading the dashboard."
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadDashboard();
  }, [loadDashboard]);

  async function handleLogout() {
    try {
      setLoggingOut(true);

      await logoutUser();

      window.location.href = "/login";
    } catch (err) {
      setError(
        err.message || "Unable to sign out. Please try again."
      );

      setLoggingOut(false);
    }
  }

  // Navigate using the existing custom AppRouter architecture.
  function navigateTo(path) {
    if (window.location.pathname === path) {
      return;
    }

    window.history.pushState({}, "", path);

    window.dispatchEvent(
      new PopStateEvent("popstate")
    );
  }

  // Upload entry point used throughout the dashboard.
  function handleUpload() {
    setSidebarOpen(false);
    navigateTo("/models/upload");
  }

  // Open the details page for a selected model.
  function handleModelOpen(modelId) {
    if (!modelId) {
      return;
    }

    setSidebarOpen(false);

    const detailsPath = `/models/view?id=${encodeURIComponent(
      modelId
    )}`;

    window.history.pushState({}, "", detailsPath);

    window.dispatchEvent(
      new PopStateEvent("popstate")
    );
  }

  // Support keyboard navigation on clickable model rows.
  function handleModelRowKeyDown(event, modelId) {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      handleModelOpen(modelId);
    }
  }

  const stats = data?.stats || {};
  const recentModels = data?.recent_models || [];

  const filteredModels = recentModels.filter((model) =>
    String(model.name || "")
      .toLowerCase()
      .includes(search.toLowerCase())
  );

  const sectionTitles = {
    overview: "Overview",
    models: "Models",
    deployments: "Deployments",
    "api-keys": "API keys",
    usage: "Usage",
    billing: "Billing",
    settings: "Settings",
    "api-docs": "API Documentation",
  };

  function renderSection() {
    if (activeSection === "overview") {
      return (
        <>
          <div className="dashboard-welcome">
            <div>
              <div className="dashboard-eyebrow">
                YOUR WORKSPACE
              </div>

              <h1>
                Welcome back,{" "}
                {user?.name?.split(" ")[0] || "Engineer"}
              </h1>

              <p>
                Here's what's happening with your machine
                learning models.
              </p>
            </div>

            <button
              type="button"
              className="dashboard-primary-button"
              onClick={handleUpload}
            >
              <Plus size={17} />
              Add a model
            </button>
          </div>

          <div className="dashboard-stats-grid">
            <StatCard
              icon={Layers3}
              label="Total models"
              value={formatNumber(stats.total_models)}
              description="Models in your workspace"
              tone="blue"
            />

            <StatCard
              icon={CheckCircle2}
              label="Ready to deploy"
              value={formatNumber(stats.ready_models)}
              description="Models that passed analysis"
              tone="green"
            />

            <StatCard
              icon={Rocket}
              label="Deployed models"
              value={formatNumber(stats.deployed_models)}
              description="Models marked as deployed"
              tone="purple"
            />

            <StatCard
              icon={Database}
              label="Storage used"
              value={formatBytes(stats.storage_bytes)}
              description="Completed model uploads"
              tone="orange"
            />
          </div>

          <div className="dashboard-content-grid">
            <section className="dashboard-panel recent-models-panel">
              <div className="panel-heading">
                <div>
                  <h2>Recent models</h2>
                  <p>Your latest model activity</p>
                </div>

                <button
                  type="button"
                  className="text-action"
                  onClick={() => setActiveSection("models")}
                >
                  View all <ArrowRight size={15} />
                </button>
              </div>

              {recentModels.length === 0 ? (
                <EmptyModels onUpload={handleUpload} />
              ) : (
                <div className="models-table-wrap">
                  <table className="models-table">
                    <thead>
                      <tr>
                        <th>MODEL</th>
                        <th>VERSION</th>
                        <th>STATUS</th>
                        <th>CREATED</th>
                      </tr>
                    </thead>

                    <tbody>
                      {recentModels.slice(0, 5).map((model) => (
                        <tr
                          key={model.id}
                          className="clickable-model-row"
                          onClick={() => handleModelOpen(model.id)}
                          onKeyDown={(event) =>
                            handleModelRowKeyDown(event, model.id)
                          }
                          tabIndex={0}
                          role="link"
                          aria-label={`View details for ${model.name}`}
                          title={`View ${model.name} details`}
                        >
                          <td>
                            <div className="model-name-cell">
                              <span className="model-file-icon">
                                <FileArchive size={17} />
                              </span>

                              <span>
                                <strong>{model.name}</strong>
                                <small>
                                  {model.original_name ||
                                    "No file uploaded"}
                                </small>
                              </span>
                            </div>
                          </td>

                          <td>v{model.version}</td>

                          <td>
                            <StatusBadge status={model.status} />
                          </td>

                          <td>{formatDate(model.created_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>

            <section className="dashboard-panel activity-panel">
              <div className="panel-heading">
                <div>
                  <h2>Model health</h2>
                  <p>Current model status overview</p>
                </div>

                <Activity
                  size={19}
                  className="panel-heading-icon"
                />
              </div>

              <div className="health-list">
                <div>
                  <span>
                    <CheckCircle2 size={17} />
                    Ready
                  </span>

                  <strong>
                    {formatNumber(stats.ready_models)}
                  </strong>
                </div>

                <div>
                  <span>
                    <CircleDashed size={17} />
                    Processing
                  </span>

                  <strong>
                    {formatNumber(stats.processing_models)}
                  </strong>
                </div>

                <div>
                  <span>
                    <AlertCircle size={17} />
                    Needs configuration
                  </span>

                  <strong>
                    {formatNumber(stats.needs_configuration)}
                  </strong>
                </div>

                <div>
                  <span>
                    <X size={17} />
                    Unsupported
                  </span>

                  <strong>
                    {formatNumber(stats.unsupported_models)}
                  </strong>
                </div>
              </div>

              <div className="health-note">
                <ShieldCheck size={18} />

                <p>
                  Model analysis and deployment checks will
                  appear here as you add models to your
                  workspace.
                </p>
              </div>
            </section>
          </div>

          <section className="dashboard-info-banner">
            <div className="info-banner-icon">
              <Zap size={22} />
            </div>

            <div>
              <h3>Ready to deploy your first model?</h3>

              <p>
                Upload a trained model package. Modelport will
                inspect the package and prepare the required
                runtime.
              </p>
            </div>

            <button
              type="button"
              onClick={handleUpload}
            >
              Get started <ArrowUpRight size={16} />
            </button>
          </section>
        </>
      );
    }

    if (activeSection === "models") {
      return (
        <>
          <div className="dashboard-welcome">
            <div>
              <div className="dashboard-eyebrow">
                MODEL WORKSPACE
              </div>

              <h1>My models</h1>

              <p>
                Manage your uploaded machine learning models.
              </p>
            </div>

            <button
              type="button"
              className="dashboard-primary-button"
              onClick={handleUpload}
            >
              <Plus size={17} />
              Upload model
            </button>
          </div>

          <section className="dashboard-panel full-panel">
            <div className="panel-heading">
              <div>
                <h2>All models</h2>

                <p>
                  {formatNumber(stats.total_models)} models
                  in your workspace
                </p>
              </div>

              <div className="dashboard-search">
                <Search size={16} />

                <input
                  value={search}
                  onChange={(event) =>
                    setSearch(event.target.value)
                  }
                  placeholder="Search models..."
                />
              </div>
            </div>

            {filteredModels.length === 0 ? (
              <EmptyModels onUpload={handleUpload} />
            ) : (
              <div className="models-table-wrap">
                <table className="models-table">
                  <thead>
                    <tr>
                      <th>MODEL</th>
                      <th>VERSION</th>
                      <th>STATUS</th>
                      <th>CREATED</th>
                    </tr>
                  </thead>

                  <tbody>
                    {filteredModels.map((model) => (
                      <tr
                        key={model.id}
                        className="clickable-model-row"
                        onClick={() => handleModelOpen(model.id)}
                        onKeyDown={(event) =>
                          handleModelRowKeyDown(event, model.id)
                        }
                        tabIndex={0}
                        role="link"
                        aria-label={`View details for ${model.name}`}
                        title={`View ${model.name} details`}
                      >
                        <td>
                          <div className="model-name-cell">
                            <span className="model-file-icon">
                              <FileArchive size={17} />
                            </span>

                            <span>
                              <strong>{model.name}</strong>

                              <small>
                                {model.original_name ||
                                  "No file uploaded"}
                              </small>
                            </span>
                          </div>
                        </td>

                        <td>v{model.version}</td>

                        <td>
                          <StatusBadge status={model.status} />
                        </td>

                        <td>{formatDate(model.created_at)}</td>
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

    if (
      ["api-docs", "api-keys", "usage"].includes(activeSection)
    ) {
      return (
        <APIIntegration
          initialTab={activeSection}
          models={recentModels}
        />
      );
    }

	if (activeSection === "deployments") {
  		return <Deployments />;
	}

	if (activeSection === "billing") {
    		return <Billing />;
	}


	if (activeSection === "settings") {
  		return <SettingsPage />;
	}
	
    const descriptions = {
      deployments:
        "Your model deployment services will be managed here.",

      "api-keys":
        "Create and manage secure keys for accessing your model APIs.",

      usage:
        "Track API requests, compute consumption and resource usage.",

      billing:
        "Manage your subscription, invoices and payment information.",

      settings:
        "Manage your account and workspace preferences.",
    };

    return (
      <section className="dashboard-panel coming-soon-panel">
        <div className="coming-soon-icon">
          {activeSection === "deployments" && (
            <Rocket size={28} />
          )}

          {activeSection === "api-keys" && (
            <KeyRound size={28} />
          )}

          {activeSection === "usage" && (
            <Activity size={28} />
          )}

          {activeSection === "billing" && (
            <CreditCard size={28} />
          )}

          {activeSection === "settings" && (
            <Settings size={28} />
          )}
        </div>

        <span className="dashboard-eyebrow">
          WORKSPACE MODULE
        </span>

        <h2>{sectionTitles[activeSection]}</h2>

        <p>{descriptions[activeSection]}</p>

        <span className="coming-soon-label">
          Coming in a future module
        </span>
      </section>
    );
  }

  if (loading) {
    return (
      <div className="dashboard-loading">
        <div className="dashboard-loading-logo">
          <Layers3 size={25} />
        </div>

        <div className="dashboard-loading-spinner" />

        <p>Preparing your workspace...</p>
      </div>
    );
  }

  if (error && !data) {
    return (
      <div className="dashboard-error-page">
        <AlertCircle size={35} />

        <h2>We couldn't load your workspace</h2>

        <p>{error}</p>

        <button
          type="button"
          className="dashboard-primary-button"
          onClick={loadDashboard}
        >
          Try again
        </button>

        <button
          type="button"
          className="dashboard-secondary-button"
          onClick={handleLogout}
        >
          Sign out
        </button>
      </div>
    );
  }

  return (
    <div className="dashboard-layout">
      {sidebarOpen && (
        <button
          type="button"
          className="sidebar-backdrop"
          onClick={() => setSidebarOpen(false)}
          aria-label="Close navigation"
        />
      )}

      <aside
        className={`dashboard-sidebar ${
          sidebarOpen ? "sidebar-open" : ""
        }`}
      >
        <a href="/" className="dashboard-brand">
          <span className="dashboard-brand-icon">
            <Layers3 size={21} />
          </span>

          <span>modelport</span>
        </a>

        <div className="workspace-switcher">
          <div className="workspace-avatar">
            {(user?.name || "E")
              .charAt(0)
              .toUpperCase()}
          </div>

          <div className="workspace-name">
            <strong>Personal workspace</strong>
            <small>{user?.name || "Engineer"}</small>
          </div>

          <ChevronDown size={15} />
        </div>

        <div className="sidebar-section-label">
          WORKSPACE
        </div>

        <nav className="dashboard-nav">
          {navigation.map((item) => {
            const Icon = item.icon;

            return (
              <button
                type="button"
                key={item.id}
                className={`dashboard-nav-item ${
                  activeSection === item.id ? "active" : ""
                }`}
                onClick={() => {
                  setActiveSection(item.id);
                  setSidebarOpen(false);
                }}
              >
                <Icon size={18} />

                <span>{item.label}</span>

                {item.id === "models" &&
                  stats.total_models > 0 && (
                    <small>{stats.total_models}</small>
                  )}
              </button>
            );
          })}
        </nav>

        <div className="sidebar-section-label sidebar-secondary-label">
          ACCOUNT
        </div>

        <nav className="dashboard-nav">
          {secondaryNavigation.map((item) => {
            const Icon = item.icon;

            return (
              <button
                type="button"
                key={item.id}
                className={`dashboard-nav-item ${
                  activeSection === item.id ? "active" : ""
                }`}
                onClick={() => {
                  setActiveSection(item.id);
                  setSidebarOpen(false);
                }}
              >
                <Icon size={18} />

                <span>{item.label}</span>
              </button>
            );
          })}
        </nav>

        <div className="sidebar-bottom">
          <div className="sidebar-help-card">
            <div>
              <ShieldCheck size={19} />
            </div>

            <strong>Your models, protected.</strong>

            <p>
              Your workspace is private to your account.
            </p>
          </div>

          <button
            type="button"
            className="sidebar-account"
            onClick={() => setAccountOpen(!accountOpen)}
          >
            <div className="account-avatar">
              {(user?.name || "E")
                .charAt(0)
                .toUpperCase()}
            </div>

            <div className="account-details">
              <strong>{user?.name}</strong>
              <small>{user?.email}</small>
            </div>

            <ChevronDown size={16} />
          </button>

          {accountOpen && (
            <div className="account-popover">
              <div className="account-popover-user">
                <strong>{user?.name}</strong>
                <small>{user?.email}</small>
              </div>

              <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
              >
                <LogOut size={16} />

                {loggingOut
                  ? "Signing out..."
                  : "Sign out"}
              </button>
            </div>
          )}
        </div>
      </aside>

      <main className="dashboard-main">
        <header className="dashboard-topbar">
          <div className="topbar-left">
            <button
              type="button"
              className="mobile-menu-button"
              onClick={() => setSidebarOpen(true)}
              aria-label="Open navigation"
            >
              <Menu size={21} />
            </button>

            <div className="breadcrumb">
              <span>Workspace</span>
              <span>/</span>
              <strong>{sectionTitles[activeSection]}</strong>
            </div>
          </div>

          <div className="topbar-actions">
            <span className="topbar-plan">
              <span />
              Workspace
            </span>

            <button
              type="button"
              className="topbar-icon-button"
              title="Notifications"
            >
              <Bell size={18} />
              <i />
            </button>

            <button
              type="button"
              className="topbar-user-button"
              onClick={() => setAccountOpen(!accountOpen)}
            >
              <span className="account-avatar">
                {(user?.name || "E")
                  .charAt(0)
                  .toUpperCase()}
              </span>

              <ChevronDown size={15} />
            </button>
          </div>
        </header>

        <div className="dashboard-page-content">
          {error && (
            <div
              className="dashboard-inline-error"
              role="alert"
            >
              <AlertCircle size={17} />
              {error}
            </div>
          )}

          {renderSection()}

          <footer className="dashboard-footer">
            <span>© 2026 Modelport</span>

            <span>
              Built for machine learning engineers
            </span>
          </footer>
        </div>
      </main>
    </div>
  );
}
