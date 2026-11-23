# Model Deployment Platform

A full-stack machine learning model deployment and management platform that simplifies the process of taking trained machine learning models from development environments to deployable, API-accessible inference services.

**Repository:** [github.com/julaskky/model-deployment-platform](https://github.com/julaskky/model-deployment-platform)

---

## Overview

The Model Deployment Platform provides an integrated environment for uploading, managing, configuring, and deploying machine learning models. It combines a React-based web interface, a PHP REST API, a Python runtime and inference engine, and a MySQL database to support model lifecycle management and API-based prediction services.

The platform is designed to address a common challenge in machine learning development: making trained models accessible to applications and external consumers without requiring them to manage the underlying model execution environment manually.

Users can upload models, inspect their dependencies, prepare isolated runtime environments, manage deployments, generate API keys, and expose prediction functionality through REST endpoints.

Beyond model deployment, the platform incorporates account management, API usage monitoring, billing functionality, and security controls.

## Key Features

### 1. User Authentication and Account Management
- User registration and authentication.
- Session-based authentication.
- CSRF protection for supported state-changing requests.
- User profile management.
- Password management.
- Account deactivation and deletion.

### 2. Machine Learning Model Management
- Upload and manage machine learning models.
- Organise model resources and metadata.
- Retrieve model details.
- Manage model versions.
- Support model-specific deployment configurations.

### 3. Automated Dependency Inspection
- Inspect uploaded model packages.
- Identify package and dependency requirements.
- Generate runtime manifests.
- Verify runtime dependency information.
- Support dependency preparation for model execution.

### 4. Runtime Environment Management
- Create model-specific runtime environments.
- Prepare isolated runtime workspaces.
- Install required dependencies.
- Verify runtime packages.
- Manage runtime configurations and status.
- Start and stop model runtimes.
- Monitor runtime health.

### 5. Model Inference and API Deployment
- Expose deployed models through REST API endpoints.
- Support programmatic prediction requests.
- Integrate API key-based authentication.
- Record API request activity.
- Manage API access credentials.

### 6. API Management and Monitoring
- Generate API keys.
- List and revoke API keys.
- Monitor API usage.
- Manage API preferences.
- Provide an API integration interface for connecting external applications.

### 7. Billing and Subscription Management
- Subscription plan management.
- Billing overview.
- Payment records.
- Invoice management.
- Plan changes and subscription-related operations.

### 8. Dashboard and Security
- Centralised dashboard.
- Deployment management interface.
- Security settings.
- Account and profile settings.
- Runtime deployment controls.

## Technology Stack

| Component | Technology |
|---|---|
| Frontend | React.js |
| Frontend build tool | Vite |
| Styling | CSS3 |
| Backend | PHP |
| API architecture | REST |
| Machine learning runtime | Python |
| Database | MySQL / MariaDB |
| Local development environment | XAMPP |
| Version control | Git |
| Repository hosting | GitHub |

## System Architecture

The platform uses a modular architecture in which the frontend, backend API, database, and Python inference engine perform separate responsibilities.

```text
                 USER / EXTERNAL APPLICATION
                            |
                            |
                  React Frontend (Vite)
                            |
                            |
                     PHP REST API
                            |
             +--------------+--------------+
             |              |              |
         MySQL DB      Model Manager    API Security
             |              |              |
             +--------------+--------------+
                            |
                   Python Runtime Engine
                            |
             +--------------+--------------+
             |              |              |
          Package       Environment      Inference
         Inspection      Management       Services
             |              |              |
             +--------------+--------------+
                            |
                    Deployed ML Models
                            |
                    Prediction Results
```

### Architectural Components

**Frontend:** Provides the user interface for authentication, model management, runtime deployment, API integration, billing, and account settings.

**PHP Backend:** Handles application requests, authentication, model operations, API key management, billing operations, and communication with the database.

**Python Runtime Engine:** Handles package inspection, dependency preparation, runtime environment creation, and model inference operations.

**MySQL Database:** Stores application data, user information, model metadata, deployment information, API credentials, and related records.

## Project Structure

```text
model-deployment-platform/
│
├── api/
│   ├── auth/
│   │   ├── csrf.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── me.php
│   │   └── register.php
│   │
│   ├── billing/
│   │   ├── billing_common.php
│   │   ├── change_plan.php
│   │   ├── invoices.php
│   │   ├── overview.php
│   │   ├── payments.php
│   │   └── plans.php
│   │
│   ├── config/
│   │   └── database.php
│   │
│   ├── dashboard/
│   │   └── overview.php
│   │
│   ├── models/
│   │   ├── all.php
│   │   ├── details.php
│   │   ├── upload.php
│   │   ├── upload_version.php
│   │   ├── versions.php
│   │   ├── runtime_service.php
│   │   ├── runtime_predict.php
│   │   ├── runtime_status.php
│   │   ├── runtime_health.php
│   │   └── ...
│   │
│   ├── settings/
│   │   ├── profile.php
│   │   ├── change_password.php
│   │   └── api_preferences.php
│   │
│   ├── storage/
│   └── v1/
│       └── models/
│           ├── predict.php
│           └── request_logger.php
│
├── database/
│   └── schema.sql
│
├── frontend/
│   ├── public/
│   ├── src/
│   │   ├── components/
│   │   ├── pages/
│   │   │   ├── api/
│   │   │   ├── auth/
│   │   │   ├── dashboard/
│   │   │   └── models/
│   │   ├── services/
│   │   ├── styles/
│   │   ├── App.jsx
│   │   ├── AppRouter.jsx
│   │   └── main.jsx
│   ├── package.json
│   └── vite.config.js
│
├── python_engine/
│   ├── inference/
│   │   ├── inference_server.py
│   │   └── service_manager.py
│   ├── package_inspection/
│   │   └── inspector.py
│   ├── runtime_environment/
│   │   ├── dependency_installer.py
│   │   ├── dependency_policy.py
│   │   ├── environment_builder.py
│   │   ├── manifest_builder.py
│   │   ├── wheelhouse_lock.py
│   │   └── wheelhouse_verify.py
│   ├── build_runtime_manifest.py
│   ├── create_runtime_environment.py
│   ├── inspect_package.py
│   └── prepare_runtime_workspace.py
│
├── check_api_key_hash.py
├── test_public_api.py
├── .gitignore
└── README.md
```

## Getting Started

The following instructions describe the local development setup using XAMPP on Windows.

### Prerequisites

Install the following software:

- [XAMPP](https://www.apachefriends.org/) with Apache, PHP, and MySQL/MariaDB.
- [Node.js](https://nodejs.org/) and npm.
- [Python](https://www.python.org/).
- [Git](https://git-scm.com/).

Ensure that Apache and MySQL are running through the XAMPP Control Panel.

### 1. Clone the Repository

```bash
git clone https://github.com/julaskky/model-deployment-platform.git
```

Navigate into the project directory:

```bash
cd model-deployment-platform
```

### 2. Configure the Backend

Place the project inside your XAMPP `htdocs` directory.

For example:

```text
C:\xampp\htdocs\model-deployment-platform
```

Configure the database connection in:

```text
api/config/database.php
```

Update the database host, database name, username, and password according to your local environment.

**Security note:** The repository configuration is intended for local development. Use environment-based configuration and secure credentials for production deployments.

### 3. Set Up the Database

1. Start Apache and MySQL in XAMPP.
2. Open phpMyAdmin at:

   `http://localhost/phpmyadmin`

3. Create a database named:

   `model_deployment_platform`

4. Import the SQL schema:

   `database/schema.sql`

5. Confirm that the database connection settings match your local MySQL configuration.

### 4. Configure the Frontend

Open a terminal and navigate to the frontend directory:

```bash
cd frontend
```

Install the dependencies:

```bash
npm install
```

Start the Vite development server:

```bash
npm run dev
```

Open the frontend using the local URL displayed by Vite, typically:

```text
http://localhost:5173
```

### 5. Access the Backend API

The backend API is accessible through:

```text
http://localhost/model-deployment-platform/api
```

The public prediction API is located at:

```text
http://localhost/model-deployment-platform/api/v1/models/predict.php
```

Actual API requests should use the authentication and request format implemented by the corresponding endpoint.

### 6. Configure the Python Runtime

Ensure Python is installed and available through the Windows command line.

Verify the installation:

```bash
python --version
```

The Python runtime components are located in:

```text
python_engine/
```

The runtime engine contains modules for package inspection, runtime environment preparation, dependency management, and model inference.

Configure the Python executable and runtime settings according to the backend's runtime configuration before using deployment functionality.

## API Integration

The platform provides API functionality for accessing deployed machine learning models.

The public API component is located at:

```text
api/v1/models/
```

The prediction endpoint is:

```text
POST /api/v1/models/predict.php
```

API consumers should use the API key generated through the platform and submit requests in the format expected by the deployed model.

The API integration functionality supports:

- Authenticated API access.
- Model prediction requests.
- API key management.
- Request logging.
- API usage tracking.

Refer to the backend implementation for the exact request payload, headers, response structure, and model-specific input requirements.

## Security Considerations

Security is an important consideration in model deployment and API management.

The application incorporates several security-related mechanisms, including:

- Session-based authentication.
- CSRF protection.
- API key generation and management.
- API key hashing support.
- Protected model and account operations.
- Runtime dependency inspection.
- Runtime storage access restrictions.

For production deployment, additional configuration should include:

- HTTPS enforcement.
- Environment-based secrets management.
- Strong database credentials.
- Restricted database privileges.
- Appropriate file upload validation and storage permissions.
- API rate limiting and abuse protection.
- Runtime process isolation and resource limits.
- Regular dependency and security updates.

Never commit production passwords, private API keys, access tokens, or other sensitive credentials to the repository.

## Testing

The repository includes test-related scripts for checking public API functionality and Python runtime manifest operations.

Relevant files include:

```text
test_public_api.py
python_engine/test_runtime_manifest.py
python_engine/test_runtime_manifest_cli.py
```

Run the tests according to their dependencies and the local environment configuration.

## Intended Use Cases

The Model Deployment Platform can support several machine learning deployment scenarios:

- Deploying trained classification and regression models.
- Exposing prediction services to web applications.
- Managing multiple model versions.
- Preparing model-specific Python environments.
- Integrating machine learning predictions into external systems.
- Managing API access to deployed models.
- Monitoring model API consumption.

## Future Development

Potential areas for continued development include:

- Containerised model execution.
- Cloud-based deployment infrastructure.
- Advanced model performance monitoring.
- Automated deployment pipelines.
- Model benchmarking and evaluation.
- Scalable inference orchestration.
- Advanced usage analytics.
- Expanded framework and model-format support.

## Contributing

Contributions, suggestions, and technical feedback are welcome.

To contribute:

1. Fork the repository.
2. Create a feature branch.
3. Implement and test your changes.
4. Commit your changes with a descriptive message.
5. Push your branch.
6. Open a pull request.

## Author

**Dr. Julius Olaniyan**

Machine Learning Researcher | AI Engineer | Software Developer

GitHub: [@julaskky](https://github.com/julaskky)

## Repository

[https://github.com/julaskky/model-deployment-platform](https://github.com/julaskky/model-deployment-platform)

## License

No license has currently been specified. Unless a license is added to this repository, all rights remain reserved by the copyright holder.

---

**Model Deployment Platform — Bridging Machine Learning Development and Production Deployment.**
