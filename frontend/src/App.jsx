
import { useState } from "react";
import {
  ArrowRight,
  ArrowUpRight,
  Menu,
  X,
  Zap,
  ShieldCheck,
  Code2,
  CloudUpload,
  Cpu,
  Activity,
  Layers3,
  Server,
  Gauge,
  Check,
  ChevronRight,
  BrainCircuit,
  Boxes,
  Workflow,
  LockKeyhole,
  Terminal,
  Sparkles,
} from "lucide-react";
import "./App.css";

const features = [
  {
    icon: CloudUpload,
    title: "Simple model uploads",
    description:
      "Upload your trained model package in a ZIP file. No Dockerfile, registry or infrastructure configuration required.",
  },
  {
    icon: Zap,
    title: "One-click deployment",
    description:
      "Our deployment engine prepares the runtime, builds your model environment and launches your inference service.",
  },
  {
    icon: Code2,
    title: "Production-ready APIs",
    description:
      "Get a stable HTTPS endpoint with predictable request and response formats for your applications.",
  },
  {
    icon: Activity,
    title: "Deployment monitoring",
    description:
      "Monitor deployment status, API activity and resource usage from one straightforward workspace.",
  },
  {
    icon: ShieldCheck,
    title: "Secure by design",
    description:
      "Manage API keys, control access and keep your model packages isolated from public access.",
  },
  {
    icon: Gauge,
    title: "Usage visibility",
    description:
      "Understand API requests and compute consumption so you can manage your deployments effectively.",
  },
];

const steps = [
  {
    number: "01",
    title: "Upload your model",
    description:
      "Provide a model name and upload your trained model package.",
    icon: CloudUpload,
  },
  {
    number: "02",
    title: "We prepare everything",
    description:
      "The platform inspects your package and prepares the required inference runtime.",
    icon: Workflow,
  },
  {
    number: "03",
    title: "Deploy your API",
    description:
      "Your model is launched and checked for availability and readiness.",
    icon: Boxes,
  },
  {
    number: "04",
    title: "Integrate and build",
    description:
      "Use your API endpoint and authentication key in your applications.",
    icon: Terminal,
  },
];

const frameworks = [
  "PyTorch",
  "TensorFlow",
  "Scikit-learn",
  "XGBoost",
  "LightGBM",
  "ONNX",
];

function SectionHeading({ eyebrow, title, description, centered = true }) {
  return (
    <div className={`section-heading ${centered ? "centered" : ""}`}>
      <span className="eyebrow">{eyebrow}</span>
      <h2>{title}</h2>
      {description && <p>{description}</p>}
    </div>
  );
}

function DashboardPreview() {
  return (
    <div className="preview-shell">
      <div className="preview-window">
        <div className="preview-topbar">
          <div className="window-dots">
            <i />
            <i />
            <i />
          </div>
          <div className="preview-address">
            <LockKeyhole size={11} />
            app.modelport.ai/dashboard
          </div>
          <div className="preview-avatar">JD</div>
        </div>

        <div className="preview-content">
          <aside className="preview-sidebar">
            <div className="preview-brand">
              <div className="brand-symbol small">
                <BrainCircuit size={16} />
              </div>
              <span>modelport</span>
            </div>

            <div className="preview-menu active">
              <Layers3 size={14} /> Models
            </div>
            <div className="preview-menu">
              <Boxes size={14} /> Deployments
            </div>
            <div className="preview-menu">
              <Code2 size={14} /> API keys
            </div>
            <div className="preview-menu">
              <Activity size={14} /> Usage
            </div>
          </aside>

          <div className="preview-main">
            <div className="preview-heading">
              <div>
                <span className="preview-muted">WORKSPACE / MODELS</span>
                <h3>My models</h3>
              </div>
              <span className="preview-add">+ Deploy model</span>
            </div>

            <div className="preview-stats">
              <div className="preview-stat">
                <span>Models</span>
                <strong>04</strong>
                <small>In your workspace</small>
              </div>
              <div className="preview-stat">
                <span>Active deployments</span>
                <strong>03</strong>
                <small className="positive">● Services running</small>
              </div>
              <div className="preview-stat">
                <span>API requests</span>
                <strong>12.8k</strong>
                <small>Illustrative activity</small>
              </div>
            </div>

            <div className="preview-table">
              <div className="preview-table-head">
                <span>MODEL</span>
                <span>FRAMEWORK</span>
                <span>STATUS</span>
              </div>
              <div className="preview-table-row">
                <div className="model-cell">
                  <div className="model-icon purple">
                    <BrainCircuit size={15} />
                  </div>
                  <span>
                    <strong>Image classifier</strong>
                    <small>v1.0.0</small>
                  </span>
                </div>
                <span>PyTorch</span>
                <span className="status-running">● Running</span>
              </div>
              <div className="preview-table-row">
                <div className="model-cell">
                  <div className="model-icon blue">
                    <Cpu size={15} />
                  </div>
                  <span>
                    <strong>Customer prediction</strong>
                    <small>v1.0.0</small>
                  </span>
                </div>
                <span>Scikit-learn</span>
                <span className="status-running">● Running</span>
              </div>
              <div className="preview-table-row">
                <div className="model-cell">
                  <div className="model-icon green">
                    <Activity size={15} />
                  </div>
                  <span>
                    <strong>Forecasting model</strong>
                    <small>v2.1.0</small>
                  </span>
                </div>
                <span>XGBoost</span>
                <span className="status-running">● Running</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="floating-card floating-top">
        <div className="floating-icon">
          <ShieldCheck size={17} />
        </div>
        <div>
          <strong>Deployment ready</strong>
          <span>Health check passed</span>
        </div>
        <Check size={16} className="floating-check" />
      </div>

      <div className="floating-card floating-bottom">
        <div className="request-icon">
          <Activity size={17} />
        </div>
        <div>
          <strong>API endpoint</strong>
          <span>HTTPS · Authenticated</span>
        </div>
      </div>
    </div>
  );
}

export default function App() {
  const [menuOpen, setMenuOpen] = useState(false);

  const closeMenu = () => setMenuOpen(false);

  return (
    <div className="site-wrapper" id="top">
      <header className="site-header">
        <div className="container nav-container">
          <a className="brand" href="#top" onClick={closeMenu}>
            <span className="brand-symbol">
              <BrainCircuit size={23} />
            </span>
            <span>modelport<span className="brand-period">.</span></span>
          </a>

          <button
            className="mobile-menu-button"
            onClick={() => setMenuOpen(!menuOpen)}
            aria-label="Toggle navigation"
            aria-expanded={menuOpen}
          >
            {menuOpen ? <X /> : <Menu />}
          </button>

          <nav className={`main-nav ${menuOpen ? "nav-open" : ""}`}>
            <a href="#features" onClick={closeMenu}>Features</a>
            <a href="#how-it-works" onClick={closeMenu}>How it works</a>
            <a href="#frameworks" onClick={closeMenu}>Frameworks</a>
            <a href="#pricing" onClick={closeMenu}>Pricing</a>
            <div className="mobile-nav-actions">
              <a href="/login" className="nav-login">Sign in</a>
              <a href="/register" className="button button-primary">
                Get started <ArrowUpRight size={16} />
              </a>
            </div>
          </nav>

          <div className="nav-actions">
            <a href="/login" className="nav-login">Sign in</a>
            <a href="/register" className="button button-primary nav-cta">
              Get started <ArrowUpRight size={16} />
            </a>
          </div>
        </div>
      </header>

      <main>
        <section className="hero-section">
          <div className="hero-grid-pattern" />
          <div className="hero-glow hero-glow-one" />
          <div className="hero-glow hero-glow-two" />

          <div className="container hero-layout">
            <div className="hero-copy">
              <div className="hero-pill">
                <span className="pill-dot" />
                BUILT FOR MACHINE LEARNING ENGINEERS
                <ArrowUpRight size={13} />
              </div>

              <h1>
                Your model.
                <br />
                Your API.
                <br />
                <span>No infrastructure headaches.</span>
              </h1>

              <p className="hero-description">
                Turn your trained machine learning models into production-ready
                APIs. Upload your model, deploy it and start building — without
                managing servers, Docker or Kubernetes.
              </p>

              <div className="hero-actions">
                <a href="/register" className="button button-primary button-large">
                  Deploy your first model <ArrowRight size={18} />
                </a>
                <a href="#how-it-works" className="button button-outline button-large">
                  Explore how it works
                </a>
              </div>

              <div className="hero-assurance">
                <span><Check size={14} /> No Docker knowledge required</span>
                <span><Check size={14} /> Built for ML workflows</span>
              </div>
            </div>

            <div className="hero-visual">
              <DashboardPreview />
            </div>
          </div>

          <div className="container hero-bottom">
            <div className="hero-bottom-line" />
            <span>FROM TRAINED MODEL TO LIVE API</span>
            <div className="hero-bottom-line" />
          </div>
        </section>

        <section className="framework-strip" id="frameworks">
          <div className="container">
            <p>DESIGNED FOR THE TOOLS YOU ALREADY BUILD WITH</p>
            <div className="framework-list">
              {frameworks.map((framework) => (
                <span key={framework}>{framework}</span>
              ))}
            </div>
            <small>Framework availability depends on package compatibility and runtime support.</small>
          </div>
        </section>

        <section className="features-section section-space" id="features">
          <div className="container">
            <SectionHeading
              eyebrow="A BETTER WAY TO DEPLOY"
              title={<>Everything your model needs.<br /><span>Nothing you don't.</span></>}
              description="A focused deployment platform that takes care of the infrastructure details, so you can focus on building intelligent applications."
            />

            <div className="features-grid">
              {features.map((feature, index) => {
                const Icon = feature.icon;
                return (
                  <article className="feature-card" key={feature.title}>
                    <div className="feature-icon">
                      <Icon size={21} />
                    </div>
                    <span className="feature-number">0{index + 1}</span>
                    <h3>{feature.title}</h3>
                    <p>{feature.description}</p>
                    <div className="feature-card-line" />
                  </article>
                );
              })}
            </div>
          </div>
        </section>

        <section className="workflow-section section-space" id="how-it-works">
          <div className="container">
            <SectionHeading
              eyebrow="A SIMPLE WORKFLOW"
              title={<>From your notebook<br /><span>to a live endpoint.</span></>}
              description="You have already done the hard work of training your model. Getting it online should be the easy part."
            />

            <div className="workflow-grid">
              {steps.map((step, index) => {
                const Icon = step.icon;
                return (
                  <article className="workflow-card" key={step.number}>
                    <div className="workflow-card-top">
                      <span>{step.number}</span>
                      <Icon size={23} />
                    </div>
                    <h3>{step.title}</h3>
                    <p>{step.description}</p>
                    {index < steps.length - 1 && (
                      <ChevronRight className="workflow-arrow" size={20} />
                    )}
                  </article>
                );
              })}
            </div>

            <div className="workflow-note">
              <Sparkles size={17} />
              <span>Infrastructure complexity stays behind the scenes. You stay in control of your models.</span>
            </div>
          </div>
        </section>

        <section className="developer-section section-space">
          <div className="container developer-layout">
            <div className="developer-copy">
              <span className="eyebrow">MADE FOR BUILDERS</span>
              <h2>
                Spend less time
                <br />
                on infrastructure.
                <br />
                <span>More time on AI.</span>
              </h2>
              <p>
                No wrestling with deployment scripts or manually configuring
                inference servers. Modelport is designed around the workflow
                ML engineers actually need.
              </p>

              <ul className="developer-checklist">
                <li><Check size={17} /> Upload a model package</li>
                <li><Check size={17} /> Configure only what is necessary</li>
                <li><Check size={17} /> Get a stable model endpoint</li>
                <li><Check size={17} /> Manage versions and deployments</li>
              </ul>

              <a href="/register" className="text-link">
                Start deploying <ArrowRight size={17} />
              </a>
            </div>

            <div className="code-visual">
              <div className="code-header">
                <div className="code-window-dots"><i /><i /><i /></div>
                <span>your-application.py</span>
                <span className="python-label">PYTHON</span>
              </div>
              <div className="code-body">
                <div><span className="line-number">01</span><span className="code-purple">import</span> requests</div>
                <div className="code-empty"><span className="line-number">02</span></div>
                <div><span className="line-number">03</span>endpoint = <span className="code-green">"https://api.modelport.ai/v1/..."</span></div>
                <div><span className="line-number">04</span></div>
                <div><span className="line-number">05</span>response = requests.<span className="code-blue">post</span>(</div>
                <div><span className="line-number">06</span>    endpoint,</div>
                <div><span className="line-number">07</span>    headers={`{"Authorization": "Bearer YOUR_API_KEY"},`}</div>
                <div><span className="line-number">08</span>    json={`{"data": [0.2, 0.5, 0.8]}`}</div>
                <div><span className="line-number">09</span>)</div>
                <div className="code-empty"><span className="line-number">10</span></div>
                <div><span className="line-number">11</span><span className="code-purple">print</span>(response.<span className="code-blue">json</span>())</div>
              </div>
              <div className="code-footer">
                <span><span className="terminal-dot" /> Example integration</span>
                <span>HTTPS · REST API</span>
              </div>
              <div className="code-decoration"><Terminal size={25} /></div>
            </div>
          </div>
        </section>

        <section className="pricing-section section-space" id="pricing">
          <div className="container pricing-layout">
            <div>
              <span className="eyebrow">STRAIGHTFORWARD PRICING</span>
              <h2>Start building.<br /><span>Scale when you're ready.</span></h2>
              <p>
                Explore the platform and understand your deployment needs.
                Choose a plan based on the resources and usage your models require.
              </p>
            </div>

            <div className="pricing-card">
              <div className="pricing-card-top">
                <span className="pricing-icon"><Zap size={20} /></span>
                <span className="pricing-label">PLATFORM ACCESS</span>
              </div>
              <h3>Choose your plan</h3>
              <p>Flexible plans for individual ML engineers and growing workloads.</p>
              <ul>
                <li><Check size={16} /> Model management workspace</li>
                <li><Check size={16} /> API deployment tools</li>
                <li><Check size={16} /> Usage and deployment visibility</li>
                <li><Check size={16} /> Secure API key management</li>
              </ul>
              <a href="/register" className="button button-primary pricing-button">
                Create your account <ArrowRight size={17} />
              </a>
              <small>Plan details and resource limits will be shown before subscription.</small>
            </div>
          </div>
        </section>

        <section className="cta-section">
          <div className="cta-orb cta-orb-one" />
          <div className="cta-orb cta-orb-two" />
          <div className="container cta-content">
            <span className="eyebrow">YOUR NEXT MODEL IS READY</span>
            <h2>Stop configuring infrastructure.<br /><span>Start deploying intelligence.</span></h2>
            <p>Your model has done its job in training. Let's give it an API.</p>
            <a href="/register" className="button button-light button-large">
              Get started with Modelport <ArrowRight size={18} />
            </a>
            <span className="cta-footnote">Built for machine learning engineers.</span>
          </div>
        </section>
      </main>

      <footer className="site-footer">
        <div className="container">
          <div className="footer-main">
            <div className="footer-brand-column">
              <a className="brand footer-brand" href="#top">
                <span className="brand-symbol">
                  <BrainCircuit size={22} />
                </span>
                <span>modelport<span className="brand-period">.</span></span>
              </a>
              <p>
                From trained model to live API.
                <br />
                Deployment without the infrastructure headache.
              </p>
            </div>

            <div className="footer-links">
              <div>
                <strong>PLATFORM</strong>
                <a href="#features">Features</a>
                <a href="#how-it-works">How it works</a>
                <a href="#frameworks">Frameworks</a>
                <a href="#pricing">Pricing</a>
              </div>
              <div>
                <strong>ACCOUNT</strong>
                <a href="/register">Create account</a>
                <a href="/login">Sign in</a>
              </div>
              <div>
                <strong>RESOURCES</strong>
                <a href="#how-it-works">Getting started</a>
                <a href="#features">Security</a>
              </div>
            </div>
          </div>

          <div className="footer-bottom">
            <span>© {new Date().getFullYear()} Modelport. All rights reserved.</span>
            <span>Built for the people building AI.</span>
          </div>
        </div>
      </footer>
    </div>
  );
}
