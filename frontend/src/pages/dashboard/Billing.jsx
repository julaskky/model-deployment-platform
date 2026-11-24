
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  ArrowRight,
  Check,
  CheckCircle2,
  CreditCard,
  Download,
  FileText,
  Loader2,
  RefreshCw,
  ShieldCheck,
  Sparkles,
  XCircle,
} from "lucide-react";
import "../../styles/billing.css";

const API_BASE = "http://localhost/model-deployment-platform/api";

async function readResponse(response) {
  const result = await response.json().catch(() => ({}));

  if (response.status === 401) {
    window.location.href = "/login";
    throw new Error("Your session has expired. Please sign in again.");
  }

  if (!response.ok || result.success === false) {
    throw new Error(result.message || "The request could not be completed.");
  }

  return result.data ?? result;
}

async function billingGet(endpoint) {
  const response = await fetch(`${API_BASE}/billing/${endpoint}`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  return readResponse(response);
}

async function getCsrfToken() {
  const response = await fetch(`${API_BASE}/auth/csrf.php`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  const result = await readResponse(response);
  const token =
    result.csrf_token ||
    result.data?.csrf_token ||
    result.token ||
    result.data?.token;

  if (!token) {
    throw new Error("Unable to obtain a security token. Please refresh and try again.");
  }

  return token;
}

async function billingPost(endpoint, payload) {
  const csrfToken = await getCsrfToken();

  const response = await fetch(`${API_BASE}/billing/${endpoint}`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-CSRF-Token": csrfToken,
    },
    body: JSON.stringify(payload),
  });

  return readResponse(response);
}

function formatNumber(value) {
  return new Intl.NumberFormat("en-US").format(Number(value || 0));
}

function formatBytes(bytes) {
  const value = Number(bytes || 0);

  if (value <= 0) return "0 Bytes";

  const units = ["Bytes", "KB", "MB", "GB", "TB"];
  const index = Math.min(
    Math.floor(Math.log(value) / Math.log(1024)),
    units.length - 1
  );

  return `${(value / Math.pow(1024, index)).toFixed(2)} ${units[index]}`;
}

function formatDate(value) {
  if (!value) return "—";

  const parsed = new Date(String(value).replace(" ", "T"));

  if (Number.isNaN(parsed.getTime())) return "—";

  return parsed.toLocaleDateString("en-US", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function formatMoney(amount, currency = "USD") {
  if (amount === null || amount === undefined) return "Custom";

  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: currency || "USD",
    maximumFractionDigits: 2,
  }).format(Number(amount));
}

function formatLimit(value, suffix = "") {
  if (value === null || value === undefined) return "Unlimited";
  return `${formatNumber(value)}${suffix}`;
}

function usagePercentage(item) {
  const percentage = Number(item?.percentage || 0);
  return Math.max(0, Math.min(100, percentage));
}

function UsageMeter({ title, used, limit, percentage, detail }) {
  const value = usagePercentage({ percentage });

  return (
    <article className="billing-usage-card">
      <div className="billing-usage-heading">
        <span>{title}</span>
        <strong>{value.toFixed(percentage % 1 ? 1 : 0)}%</strong>
      </div>

      <div
        className="billing-progress-track"
        role="progressbar"
        aria-valuenow={value}
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label={`${title} usage`}
      >
        <span
          className={`billing-progress-fill ${value >= 80 ? "near-limit" : ""}`}
          style={{ width: `${value}%` }}
        />
      </div>

      <div className="billing-usage-values">
        <strong>{used}</strong>
        <span>{limit}</span>
      </div>

      {detail && <p>{detail}</p>}
    </article>
  );
}

function planFeatures(plan) {
  const features = plan?.features;

  if (Array.isArray(features)) {
    return features.map((feature) =>
      typeof feature === "string" ? feature : feature.label
    ).filter(Boolean);
  }

  if (!features || typeof features !== "object") return [];

  const labels = {
    api_documentation: "API documentation",
    basic_analytics: "Basic analytics",
    multiple_api_keys: "Multiple API keys",
    model_version_management: "Model version management",
    advanced_analytics: "Advanced analytics",
    priority_support: "Priority support",
    community_support: "Community support",
    email_support: "Email support",
    priority_deployment: "Priority deployment",
    dedicated_resources: "Dedicated resources",
    advanced_monitoring: "Advanced monitoring",
    custom_integrations: "Custom integrations",
  };

  return Object.entries(features)
    .filter(([, enabled]) => enabled === true || enabled === 1)
    .map(([key]) => labels[key] || key.replaceAll("_", " "));
}

function statusClass(status) {
  const value = String(status || "").toLowerCase();

  if (["paid", "succeeded", "active"].includes(value)) return "success";
  if (["pending", "open", "trialing"].includes(value)) return "pending";
  if (["failed", "void", "uncollectible", "canceled", "expired"].includes(value)) {
    return "danger";
  }

  return "neutral";
}

function EmptyHistory({ title, description }) {
  return (
    <div className="billing-empty-state">
      <span><FileText size={23} /></span>
      <strong>{title}</strong>
      <p>{description}</p>
    </div>
  );
}

export default function Billing() {
  const [plans, setPlans] = useState([]);
  const [overview, setOverview] = useState(null);
  const [invoices, setInvoices] = useState([]);
  const [payments, setPayments] = useState([]);
  const [activeTab, setActiveTab] = useState("invoices");

  const [loading, setLoading] = useState(true);
  const [changingPlan, setChangingPlan] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const loadBilling = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      const [plansResult, overviewResult, invoicesResult, paymentsResult] =
        await Promise.all([
          billingGet("plans.php"),
          billingGet("overview.php"),
          billingGet("invoices.php"),
          billingGet("payments.php"),
        ]);

      setPlans(plansResult.plans || []);
      setOverview(overviewResult);
      setInvoices(invoicesResult.invoices || []);
      setPayments(paymentsResult.payments || []);
    } catch (err) {
      setError(err.message || "Unable to load billing information.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadBilling();
  }, [loadBilling]);

  const subscription = overview?.subscription || null;
  const pendingSubscription = overview?.pending_subscription || null;
  const usage = overview?.usage || {};

  const currentPlanCode = subscription?.plan_code || "free";
  const currentPlan = useMemo(
    () => plans.find((plan) => plan.plan_code === currentPlanCode),
    [plans, currentPlanCode]
  );

  async function handlePlanChange(plan) {
    if (!plan?.plan_code || plan.plan_code === currentPlanCode) return;

    if (pendingSubscription) {
      setError("You already have a pending plan-change request. Please wait for it to be reviewed.");
      return;
    }

    const confirmed = window.confirm(
      `Submit a request to change your plan to ${plan.name}?\n\nThis will not charge you or activate the plan. Your request will be recorded for review.`
    );

    if (!confirmed) return;

    setChangingPlan(plan.plan_code);
    setError("");
    setNotice("");

    try {
      const result = await billingPost("change_plan.php", {
        plan_code: plan.plan_code,
      });

      setNotice(
        result.message ||
        `Your request to change to the ${plan.name} plan has been submitted. The plan is not active yet.`
      );

      await loadBilling();
    } catch (err) {
      setError(err.message || "Unable to submit your plan-change request.");
    } finally {
      setChangingPlan("");
    }
  }

  if (loading) {
    return (
      <div className="billing-loading">
        <Loader2 size={27} className="billing-spinner" />
        <p>Loading your billing information...</p>
      </div>
    );
  }

  if (error && !overview) {
    return (
      <section className="billing-error">
        <AlertCircle size={28} />
        <h2>Billing information unavailable</h2>
        <p>{error}</p>
        <button type="button" onClick={loadBilling}>
          <RefreshCw size={16} /> Try again
        </button>
      </section>
    );
  }

  const deployed = usage.deployed_models || {};
  const running = usage.running_deployments || {};
  const requests = usage.api_requests || {};
  const storage = usage.storage || {};

  return (
    <div className="billing-page">
      <div className="billing-page-heading">
        <div>
          <div className="billing-eyebrow">ACCOUNT</div>
          <h1>Billing and plans</h1>
          <p>Manage your subscription, monitor usage and review billing history.</p>
        </div>

        <button
          type="button"
          className="billing-refresh-button"
          onClick={loadBilling}
        >
          <RefreshCw size={16} /> Refresh
        </button>
      </div>

      {error && (
        <div className="billing-alert billing-alert-error" role="alert">
          <AlertCircle size={18} />
          <span>{error}</span>
          <button type="button" onClick={() => setError("")} aria-label="Dismiss error">
            <XCircle size={17} />
          </button>
        </div>
      )}

      {notice && (
        <div className="billing-alert billing-alert-success" role="status">
          <CheckCircle2 size={18} />
          <span>{notice}</span>
          <button type="button" onClick={() => setNotice("")} aria-label="Dismiss message">
            <XCircle size={17} />
          </button>
        </div>
      )}

      {pendingSubscription && (
        <div className="billing-alert billing-alert-pending">
          <AlertCircle size={18} />
          <span>
            A plan-change request is pending. Your current plan remains active until the request is processed.
          </span>
        </div>
      )}

      <section className="billing-current-plan">
        <div className="billing-current-icon">
          <CreditCard size={23} />
        </div>

        <div className="billing-current-details">
          <span className="billing-section-kicker">CURRENT SUBSCRIPTION</span>
          <div className="billing-current-title">
            <h2>{subscription?.plan_name || "No active plan"}</h2>
            <span className={`billing-status ${statusClass(subscription?.status)}`}>
              {subscription?.status || "Unavailable"}
            </span>
          </div>
          <p>
            {subscription
              ? `${formatMoney(subscription.price_monthly, subscription.currency)} / month`
              : "Choose a plan to get started."}
          </p>
          <small>
            Billing period: {formatDate(overview?.billing_period?.start)} –{" "}
            {formatDate(overview?.billing_period?.end)}
          </small>
        </div>

        <div className="billing-current-action">
          <ShieldCheck size={17} />
          <span>Session-secured account</span>
        </div>
      </section>

      <section className="billing-section">
        <div className="billing-section-heading">
          <div>
            <span className="billing-section-kicker">RESOURCE CONSUMPTION</span>
            <h2>Usage this billing period</h2>
            <p>Monitor your consumption against your current plan limits.</p>
          </div>
        </div>

        <div className="billing-usage-grid">
          <UsageMeter
            title="Deployed models"
            used={formatNumber(deployed.used)}
            limit={formatLimit(deployed.limit)}
            percentage={deployed.percentage}
          />
          <UsageMeter
            title="Running deployments"
            used={formatNumber(running.used)}
            limit={formatLimit(running.limit)}
            percentage={running.percentage}
          />
          <UsageMeter
            title="API requests"
            used={formatNumber(requests.used)}
            limit={formatLimit(requests.limit)}
            percentage={requests.percentage}
            detail={`${formatNumber(requests.successful)} successful · ${formatNumber(requests.failed)} failed`}
          />
          <UsageMeter
            title="Storage"
            used={formatBytes(storage.used_bytes)}
            limit={storage.limit_bytes == null ? "Unlimited" : formatBytes(storage.limit_bytes)}
            percentage={storage.percentage}
          />
        </div>

        <p className="billing-usage-note">
          Usage figures reflect the records currently available in your workspace. Reaching a plan limit does not automatically stop a running deployment.
        </p>
      </section>

      <section className="billing-section">
        <div className="billing-section-heading">
          <div>
            <span className="billing-section-kicker">FIND YOUR FIT</span>
            <h2>Available plans</h2>
            <p>Choose the capacity and features that match your deployment needs.</p>
          </div>
        </div>

        <div className="billing-plans-grid">
          {plans.map((plan) => {
            const isCurrent = plan.plan_code === currentPlanCode;
            const isPending = pendingSubscription?.plan_code === plan.plan_code;
            const features = planFeatures(plan);

            return (
              <article
                key={plan.id || plan.plan_code}
                className={`billing-plan-card ${isCurrent ? "current" : ""} ${plan.plan_code === "professional" ? "featured" : ""}`}
              >
                {plan.plan_code === "professional" && (
                  <span className="billing-popular-label">
                    <Sparkles size={13} /> Popular
                  </span>
                )}

                <div className="billing-plan-name">{plan.name}</div>
                <p className="billing-plan-description">
                  {plan.description || "A plan for your deployment workflow."}
                </p>

                <div className="billing-plan-price">
                  <strong>{formatMoney(plan.price_monthly, plan.currency)}</strong>
                  {plan.price_monthly !== null && <span>/ month</span>}
                </div>

                <div className="billing-plan-limits">
                  <div>
                    <span>Deployed models</span>
                    <strong>{formatLimit(plan.max_deployed_models)}</strong>
                  </div>
                  <div>
                    <span>Running deployments</span>
                    <strong>{formatLimit(plan.max_running_deployments)}</strong>
                  </div>
                  <div>
                    <span>API requests / month</span>
                    <strong>{formatLimit(plan.max_api_requests_monthly)}</strong>
                  </div>
                  <div>
                    <span>Storage</span>
                    <strong>
                      {plan.max_storage_bytes == null
                        ? "Custom"
                        : formatBytes(plan.max_storage_bytes)}
                    </strong>
                  </div>
                </div>

                <div className="billing-plan-features">
                  <span className="billing-feature-heading">Included features</span>
                  {features.map((feature) => (
                    <div key={feature}>
                      <Check size={15} />
                      <span>{feature}</span>
                    </div>
                  ))}
                </div>

                <button
                  type="button"
                  className={`billing-plan-button ${isCurrent ? "current-button" : ""}`}
                  disabled={isCurrent || Boolean(changingPlan) || Boolean(pendingSubscription)}
                  onClick={() => handlePlanChange(plan)}
                >
                  {changingPlan === plan.plan_code ? (
                    <><Loader2 size={16} className="billing-spinner" /> Submitting...</>
                  ) : isCurrent ? (
                    "Current plan"
                  ) : isPending ? (
                    "Request pending"
                  ) : pendingSubscription ? (
                    "Request pending"
                  ) : (
                    <>Request this plan <ArrowRight size={16} /></>
                  )}
                </button>
              </article>
            );
          })}
        </div>

        <p className="billing-plan-disclaimer">
          Plan prices and limits are configurable. Plan changes submitted here are requests only; no payment is collected and no new plan is activated by this page.
        </p>
      </section>

      <section className="billing-section billing-history-section">
        <div className="billing-section-heading">
          <div>
            <span className="billing-section-kicker">TRANSACTION RECORDS</span>
            <h2>Billing history</h2>
            <p>Review invoices and payment records associated with your account.</p>
          </div>
        </div>

        <div className="billing-history-tabs" role="tablist" aria-label="Billing history">
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === "invoices"}
            className={activeTab === "invoices" ? "active" : ""}
            onClick={() => setActiveTab("invoices")}
          >
            <FileText size={16} /> Invoices ({invoices.length})
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === "payments"}
            className={activeTab === "payments" ? "active" : ""}
            onClick={() => setActiveTab("payments")}
          >
            <CreditCard size={16} /> Payments ({payments.length})
          </button>
        </div>

        {activeTab === "invoices" ? (
          invoices.length === 0 ? (
            <EmptyHistory
              title="No invoices yet"
              description="Invoices will appear here when they are generated for your account."
            />
          ) : (
            <div className="billing-table-wrap">
              <table className="billing-table">
                <thead>
                  <tr>
                    <th>INVOICE</th>
                    <th>ISSUED</th>
                    <th>AMOUNT</th>
                    <th>STATUS</th>
                    <th>DUE DATE</th>
                  </tr>
                </thead>
                <tbody>
                  {invoices.map((invoice) => (
                    <tr key={invoice.id}>
                      <td><strong>{invoice.invoice_number || `#${invoice.id}`}</strong></td>
                      <td>{formatDate(invoice.issued_at)}</td>
                      <td>{formatMoney(invoice.total, invoice.currency)}</td>
                      <td>
                        <span className={`billing-status ${statusClass(invoice.status)}`}>
                          {invoice.status || "Unknown"}
                        </span>
                      </td>
                      <td>{formatDate(invoice.due_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        ) : payments.length === 0 ? (
          <EmptyHistory
            title="No payment records"
            description="Payments will appear here after transactions have been recorded."
          />
        ) : (
          <div className="billing-table-wrap">
            <table className="billing-table">
              <thead>
                <tr>
                  <th>PAYMENT</th>
                  <th>DATE</th>
                  <th>PROVIDER</th>
                  <th>AMOUNT</th>
                  <th>STATUS</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment) => (
                  <tr key={payment.id}>
                    <td><strong>#{payment.id}</strong></td>
                    <td>{formatDate(payment.paid_at || payment.created_at)}</td>
                    <td>{payment.payment_provider || "—"}</td>
                    <td>{formatMoney(payment.amount, payment.currency)}</td>
                    <td>
                      <span className={`billing-status ${statusClass(payment.status)}`}>
                        {payment.status || "Unknown"}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <div className="billing-security-note">
        <ShieldCheck size={19} />
        <div>
          <strong>Your billing information is account-protected.</strong>
          <p>Only records associated with your authenticated account are displayed.</p>
        </div>
      </div>
    </div>
  );
}
