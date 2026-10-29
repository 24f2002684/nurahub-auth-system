# NuraHub Secure Authentication & User Profile System

A production-ready, secure, and responsive authentication and user profile web application designed for a software development internship evaluation.

The application implements a multi-tier decoupled database architecture leveraging **MySQL**, **MongoDB**, and **Redis** to demonstrate modern separation of concerns, defensive security practices, and distributed session management without heavy full-stack frameworks.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Technology Stack](#2-technology-stack)
3. [Project Structure](#3-project-structure)
4. [Prerequisites & Required Software](#4-prerequisites--required-software)
5. [Local Development Setup](#5-local-development-setup)
   - [Option A: Running with Docker Compose](#option-a-running-with-docker-compose)
   - [Option B: Running on Native Host / WSL](#option-b-running-on-native-host--wsl)
6. [Environment Variables Reference](#6-environment-variables-reference)
7. [External Cloud Services Setup Guide](#7-external-cloud-services-setup-guide)
   - [Aiven Free MySQL Setup](#aiven-free-mysql-setup)
   - [MongoDB Atlas Free Cluster Setup](#mongodb-atlas-free-cluster-setup)
   - [Upstash Free Redis Setup](#upstash-free-redis-setup)
8. [GitHub Preparation](#8-github-preparation)
9. [Render Docker Deployment Guide](#9-render-docker-deployment-guide)
10. [Database Schema & Safe Initialization](#10-database-schema--safe-initialization)
11. [Health Checks & System Probes](#11-health-checks--system-probes)
12. [Testing & Verification](#12-testing--verification)
    - [Automated Integration Test Suite](#automated-integration-test-suite)
    - [Testing Redis Session Persistence](#testing-redis-session-persistence)
    - [Manual Browser Journey](#manual-browser-journey)
13. [Security Measures Implemented](#13-security-measures-implemented)
14. [Common Connection & TLS Troubleshooting](#14-common-connection--tls-troubleshooting)
15. [Free-Tier Limitations & Cold-Start Behavior](#15-free-tier-limitations--cold-start-behavior)

---

## 1. Architecture Overview

Modern distributed applications avoid single-database bottlenecks when application features have fundamentally different data models and performance requirements:

- **Relational Integrity (MySQL):** Core user accounts and authentication require strict schema constraints, uniqueness guarantees, and transactional ACID guarantees.
- **Flexible Document Storage (MongoDB):** Extended profiles, user bios, variable attributes, and interest tags benefit from dynamic, semi-structured document modeling.
- **High-Throughput Distributed State (Redis):** User sessions, rate-limiting counters, and CSRF states require sub-millisecond in-memory access and automatic time-to-live (TTL) expiration.

```
                    ┌───────────────────────────────┐
                    │  Browser / Client (Bootstrap) │
                    └───────────────┬───────────────┘
                                    │
                                    │ HTTPS / AJAX (X-CSRF-Token)
                                    ▼
                    ┌───────────────────────────────┐
                    │      Render Web Service       │
                    │   (Docker + Apache + PHP 8)   │
                    └───────┬───────┬───────┬───────┘
                            │       │       │
              TLS (Port 17702)      │       │ TLS (Port 6379)
             ┌──────────────┘       │       └─────────────┐
             ▼                      ▼                     ▼
┌──────────────────────┐ ┌────────────────────┐ ┌───────────────────┐
│     Aiven MySQL      │ │   MongoDB Atlas    │ │   Upstash Redis   │
│ (User Accounts & Auth)│ │ (Profile Documents)│ │ (Server Sessions) │
└──────────────────────┘ └────────────────────┘ └───────────────────┘
```

---

## 2. Technology Stack

| Layer | Component | Version / Specification | Purpose |
|---|---|---|---|
| **Structure** | HTML5 | Living Standard | Semantic, accessible page layouts |
| **Styling** | CSS3 & Bootstrap | Bootstrap 5.3 + Custom CSS | Responsive UI components, cards, forms |
| **Interactivity** | JavaScript & jQuery | jQuery 3.7.1 | Asynchronous AJAX form submission (no full-page reloads) |
| **Backend API** | PHP | 8.1+ (8.3 in Production) | JSON API endpoints, authentication, validation |
| **Relational DB** | MySQL | 8.0+ (Aiven Cloud / Local) | User accounts, credentials, unique constraints |
| **Document Store**| MongoDB | 7.0+ (Atlas Cloud / Local) | Rich biographical user profiles and interests |
| **Session Cache** | Redis | 7.x (Upstash TLS / Local) | Distributed server-side PHP sessions & rate-limiting |
| **Packaging** | Docker & Compose | Multi-stage Dockerfile | Container packaging for Render & local orchestration |
| **Dependencies** | Composer | 2.x | Official MongoDB driver, Predis (TLS), phpdotenv |

---

## 3. Project Structure

```
nurahub-auth-system/
├── assets/                  # Static assets and images
├── certs/                   # CA certificates (e.g., Aiven Project CA)
├── css/
│   └── style.css            # Custom responsive styling and theme overrides
├── js/
│   ├── auth.js              # Global session checker and CSRF distributor
│   ├── login.js             # Login form AJAX handler and validation
│   ├── profile.js           # Profile loader and update AJAX handler
│   └── register.js          # Registration AJAX handler & password strength meter
├── php/
│   ├── auth_check.php       # Session verification and CSRF token generator
│   ├── config.php           # Central environment loader & Redis session engine
│   ├── csrf.php             # Standalone CSRF token endpoint
│   ├── database.php         # Central database connection layer (MySQL/Mongo/Redis)
│   ├── login.php            # Secure authentication endpoint with rate limiting
│   ├── logout.php           # Session invalidation endpoint
│   ├── profile.php          # Protected profile retrieval and update endpoint
│   └── register.php         # User registration with compensation rollback
├── sql/
│   └── init.sql             # Safe, idempotent MySQL schema script
├── tests/
│   ├── test_api_e2e.php     # Comprehensive 18-step end-to-end integration test
│   ├── test_db.php          # Database connectivity and schema initialization
│   ├── test_predis_upstash.php # Diagnostic Upstash Redis TLS verification
│   └── test_remote_suite.php   # Verification suite for live cloud services
├── .dockerignore            # Docker build ignore rules (excludes secrets/vendor)
├── .env.example             # Clean configuration template with placeholders
├── .gitignore               # Git exclusions (blocks .env and runtime logs)
├── .htaccess                # Apache directory browsing prevention and headers
├── docker-compose.yml       # Local multi-service development orchestration
├── docker-entrypoint.sh     # Dynamic $PORT binder and runtime CA certificate setup
├── Dockerfile               # Production multi-stage Docker build for Render
├── health.php               # Lightweight, safe health-check endpoint
├── index.html               # Landing page
├── login.html               # Authentication page
├── profile.html             # User profile and profile editor
├── register.html            # User registration page
└── README.md                # Deployment and setup documentation
```

---

## 4. Prerequisites & Required Software

### For Local Native Development:
1. **PHP 8.1 or higher** (PHP 8.3 recommended) with extensions:
   - `pdo`, `pdo_mysql`
   - `mongodb` (via PECL: `pecl install mongodb`)
   - `redis` (via PECL: `pecl install redis` or via Composer `predis/predis`)
   - `zip`, `json`, `session`, `curl`
2. **Composer 2.x** installed globally (`composer --version`)
3. **MySQL Server 8.0+**
4. **MongoDB Community Server 7.0+**
5. **Redis Server 7.x**

### For Containerized Development:
- **Docker Engine** (24.x+) and **Docker Compose** (v2.x+) installed.

---

## 5. Local Development Setup

### Option A: Running with Docker Compose

Docker Compose runs PHP, Apache, MySQL, MongoDB, and Redis in isolated local containers:

1. **Clone the repository:**
   ```bash
   git clone https://github.com/24f2002684/nurahub-auth-system.git
   cd nurahub-auth-system
   ```

2. **Create local environment file:**
   ```bash
   cp .env.example .env
   ```

3. **Start all services:**
   ```bash
   docker compose up -d --build
   ```

4. **Verify container health and install dependencies:**
   ```bash
   docker compose exec app composer install
   docker compose exec app php tests/test_db.php
   ```

5. **Access the application:**
   - Web application: `http://localhost:8080`
   - Health check: `http://localhost:8080/health.php`

---

### Option B: Running on Native Host / WSL

1. **Install PHP dependencies:**
   ```bash
   composer install
   ```

2. **Configure your `.env`:**
   ```bash
   cp .env.example .env
   ```
   Ensure your local credentials match your local MySQL, MongoDB, and Redis services.

3. **Initialize the database:**
   ```bash
   php tests/test_db.php
   ```

4. **Start the local PHP development server:**
   ```bash
   php -S 127.0.0.1:8080
   ```
   Open `http://localhost:8080` in your web browser.

---

## 6. Environment Variables Reference

The application reads configuration from environment variables (or a local `.env` file). **Never hardcode secrets in source files or commit `.env` to Git.**

| Variable | Required? | Default / Example | Purpose |
|---|---|---|---|
| `APP_ENV` | Optional | `production` | Application mode (`development` or `production`). In production, secure cookies and strict error suppression are active. |
| `APP_DEBUG` | Optional | `false` | Enable or disable detailed error logging. Never enable in production. |
| `PORT` | Optional | `10000` | Port for Apache to listen on. Automatically set by Render. |
| `MYSQL_HOST` | **Required** | `mysql-xxx.aivencloud.com` | MySQL host address (local IP or Aiven hostname). |
| `MYSQL_PORT` | **Required** | `17702` (Aiven) / `3306` (Local) | MySQL service port. |
| `MYSQL_DATABASE`| **Required** | `defaultdb` (Aiven) / `nurahub_auth` | Database name for user accounts table. |
| `MYSQL_USER` | **Required** | `avnadmin` (Aiven) / `nurahub_user` | MySQL account username. |
| `MYSQL_PASSWORD`| **Required** | `secret_password` | MySQL account password. |
| `MYSQL_CHARSET` | Optional | `utf8mb4` | Database character set (UTF-8 multi-byte). |
| `MYSQL_SSL_MODE`| Optional | `REQUIRED` (Aiven) / `PREFERRED` | Enforces TLS connection to MySQL. Set to `REQUIRED` for Aiven. |
| `MYSQL_SSL_CA` | Optional | `certs/aiven-ca.pem` | Local filesystem path to the Aiven Project CA certificate. |
| `MYSQL_SSL_CA_CONTENT` | Optional | `-----BEGIN CERTIFICATE-----...` | Raw inline PEM certificate content for hosted environments like Render. |
| `MONGODB_URI` | **Required** | `mongodb+srv://user:pass@cluster.mongodb.net/?retryWrites=true&w=majority` | Full MongoDB connection string (Atlas or local). |
| `MONGODB_DATABASE`| **Required** | `nurahub_profiles` | MongoDB database name storing `user_profiles` collection. |
| `REDIS_HOST` | **Required** | `immune-eagle-xxx.upstash.io` | Redis hostname (Upstash endpoint or `127.0.0.1`). |
| `REDIS_PORT` | **Required** | `6379` | Redis TCP port. |
| `REDIS_PASSWORD`| **Required** | `your_upstash_tcp_token` | Redis authentication token / password. |
| `REDIS_TLS` | Optional | `true` (Upstash) / `false` (Local) | Enforces TLS encryption for Redis connections. |
| `REDIS_PREFIX` | Optional | `PHPREDIS_SESSION:` | Key namespace prefix for Redis-backed session data. |
| `SESSION_LIFETIME` | Optional | `3600` | Session lifetime in seconds (default 1 hour). |
| `SESSION_COOKIE_SECURE` | Optional | `true` (in production) | Enforces `Secure` flag on session cookies (HTTPS). |
| `SESSION_COOKIE_SAMESITE`| Optional | `Lax` | SameSite attribute for session cookies (`Lax` or `Strict`). |

---

## 7. External Cloud Services Setup Guide

This project is pre-configured to run with free-tier cloud services. Follow these instructions to configure each database:

### Aiven Free MySQL Setup

1. **Create an Aiven Account:** Sign up at [aiven.io](https://aiven.io).
2. **Create Service:** Click **Create Service** > select **MySQL** > choose the **Free Plan**.
3. **Retrieve Credentials:** In the service overview page:
   - Note the **Host**, **Port** (e.g. `17702`), **User** (`avnadmin`), and generated **Password**.
   - Default database is named `defaultdb`.
4. **Download CA Certificate:**
   - Under the **Connection information** section, click **Download CA Certificate**.
   - Save the certificate as `certs/aiven-ca.pem` in your project, or copy its plain text for the `MYSQL_SSL_CA_CONTENT` environment variable in Render.
5. **Verify TLS Requirement:** Aiven requires SSL mode `REQUIRED`. The application automatically configures `PDO::MYSQL_ATTR_SSL_CA` and verifies the server certificate.

---

### MongoDB Atlas Free Cluster Setup

1. **Create MongoDB Atlas Account:** Sign up at [mongodb.com/atlas](https://www.mongodb.com/atlas).
2. **Deploy Free Cluster (M0):** Select the **M0 Free Cluster** hosted on AWS, GCP, or Azure.
3. **Create Database User:**
   - Under **Security > Database Access**, click **Add New Database User**.
   - Select **Password Authentication**. Set a secure username (e.g., `nurahub_db_user`) and password.
   - Assign the **Read and write to any database** privilege.
4. **Configure Network Access (IP Access List):**
   - Under **Security > Network Access**, click **Add IP Address**.
   - **Production Note:** Render Free Web Services use dynamic egress IPs. To allow Render containers to connect, add `0.0.0.0/0` (Allow Access from Anywhere).
   - *Security Trade-off:* Because access is open to the internet, you must maintain a strong, random password and never share the database credentials.
5. **Obtain Connection String:**
   - Click **Connect** > **Drivers** > select **PHP**.
   - Copy the SRV URI:
     `mongodb+srv://<username>:<password>@<cluster>.mongodb.net/?retryWrites=true&w=majority`
   - Set `MONGODB_DATABASE=nurahub_profiles`.

---

### Upstash Free Redis Setup

1. **Create Upstash Account:** Sign up at [upstash.com](https://upstash.com).
2. **Create Database:**
   - Click **Create Database**.
   - Select **Regional / Serverless Redis**.
   - Enable **TLS (SSL)**.
3. **Obtain TCP Connection Details:**
   - Scroll down to the **Connect to your database** section and select the **PHP** or **Redis-cli** tab.
   - **Important:** Do *not* use the Upstash REST URL. The PHP application uses the native Redis TCP protocol with TLS.
   - Note the **Host** (e.g., `immune-eagle-xxxx.upstash.io`), **Port** (`6379`), and **Password** (the token).
4. **Configure Environment:**
   - Set `REDIS_HOST=<your-endpoint>.upstash.io`
   - Set `REDIS_PORT=6379`
   - Set `REDIS_PASSWORD=<your-token>`
   - Set `REDIS_TLS=true`

---

## 8. GitHub Preparation

Before deploying, verify that your repository is clean and ready for deployment:

1. **Verify `.gitignore`:** Ensure `.env`, `*.pem` (with private keys), and `vendor/` are ignored.
2. **Verify Composer Lock:** Ensure `composer.lock` is committed to git so Render builds the exact dependency tree:
   ```bash
   git status
   git add .
   git commit -m "Prepare codebase for Render Docker deployment"
   git push origin main
   ```

---

## 9. Render Docker Deployment Guide

Render will build and execute your Docker container automatically from your GitHub repository.

1. **Sign in to Render:** Go to [render.com](https://render.com) and log in.
2. **Create New Web Service:**
   - In the Render Dashboard, click **New +** > **Web Service**.
   - Select **Build and deploy from a Git repository**.
   - Connect your GitHub account and choose `nurahub-auth-system`.
3. **Configure Service Settings:**
   - **Name:** `nurahub-auth-system` (or your preferred name)
   - **Region:** Choose the region closest to your Aiven/Atlas/Upstash services (e.g., Oregon, Frankfurt, or Singapore).
   - **Branch:** `main`
   - **Runtime:** **Docker** (Render detects the root `Dockerfile`).
   - **Instance Type:** **Free**.
4. **Configure Environment Variables in Render Dashboard:**
   Click **Advanced** > **Add Environment Variable** and add:

   | Key | Value |
   |---|---|
   | `APP_ENV` | `production` |
   | `PORT` | `10000` |
   | `MYSQL_HOST` | `mysql-xxxx.aivencloud.com` |
   | `MYSQL_PORT` | `17702` |
   | `MYSQL_DATABASE` | `defaultdb` |
   | `MYSQL_USER` | `avnadmin` |
   | `MYSQL_PASSWORD` | `<your-aiven-password>` |
   | `MYSQL_SSL_MODE` | `REQUIRED` |
   | `MYSQL_SSL_CA_CONTENT` | *(Paste the full contents of `certs/aiven-ca.pem`)* |
   | `MONGODB_URI` | `mongodb+srv://<user>:<password>@<cluster>.mongodb.net/?retryWrites=true&w=majority` |
   | `MONGODB_DATABASE` | `nurahub_profiles` |
   | `REDIS_HOST` | `<your-upstash-redis>.upstash.io` |
   | `REDIS_PORT` | `6379` |
   | `REDIS_PASSWORD` | `<your-upstash-token>` |
   | `REDIS_TLS` | `true` |

5. **Deploy Service:**
   - Click **Create Web Service**.
   - Render will pull your repository, build the Docker image, run the entrypoint, and launch Apache on `0.0.0.0:$PORT`.
   - Monitor the deployment logs until you see `Your service is live at https://nurahub-auth-system.onrender.com`.

---

## 10. Database Schema & Safe Initialization

The application handles schema initialization automatically and safely.

### Automatic Initialization:
Visiting `/health.php?check=deep` or running `tests/test_db.php` verifies connectivity and creates:
- The `users` table in MySQL (if it does not already exist).
- The unique index `idx_unique_user_id` on the `user_profiles` collection in MongoDB.

### Manual SQL Initialization (Optional):
If you wish to initialize the table manually in Aiven MySQL Console or a MySQL client:
```sql
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  INDEX `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 11. Health Checks & System Probes

The application provides a lightweight, safe health check endpoint:

### Endpoint: `GET /health.php`
- **Purpose:** Used by Render and external uptime monitors to check process availability.
- **Characteristics:** Returns HTTP 200 with small JSON payload. Does **not** perform heavy database roundtrips on routine pings, avoiding unnecessary cloud egress and database overhead.
- **Sample Response:**
  ```json
  {
    "status": "healthy",
    "app": "nurahub-auth-system",
    "timestamp": 1730245200,
    "uptime_check": "passed"
  }
  ```

### Deep Diagnostic Check: `GET /health.php?check=deep`
- **Purpose:** Tests live connectivity across MySQL, MongoDB, and Redis without exposing credentials or internal stack traces.
- **Sample Response:**
  ```json
  {
    "status": "healthy",
    "app": "nurahub-auth-system",
    "timestamp": 1730245200,
    "uptime_check": "passed",
    "services": "healthy"
  }
  ```

---

## 12. Testing & Verification

### Automated Integration Test Suite

The project includes an automated end-to-end regression test suite (`tests/test_api_e2e.php`) that runs 18 validation checks against the live API:

```bash
php tests/test_api_e2e.php
```

Checks executed:
1. `GET /php/auth_check.php` - Returns unauthenticated status and CSRF token.
2. `POST /php/register.php` - Rejects request without CSRF token (HTTP 403).
3. `POST /php/register.php` - Rejects mismatched passwords (HTTP 422).
4. `POST /php/register.php` - Rejects weak password (HTTP 422).
5. `POST /php/register.php` - Creates new user in MySQL & initial profile in MongoDB (HTTP 201).
6. `POST /php/register.php` - Rejects duplicate email (HTTP 409).
7. `POST /php/register.php` - Rejects duplicate username (HTTP 409).
8. `POST /php/login.php` - Rejects incorrect password with generic message (HTTP 401).
9. `POST /php/login.php` - Rejects non-existent email with generic message (HTTP 401).
10. `POST /php/login.php` - Successfully authenticates, regenerates session ID, and issues cookie (HTTP 200).
11. `GET /php/profile.php` - Retrieves user account and MongoDB profile for authenticated session (HTTP 200).
12. `POST /php/profile.php` - Updates profile details (Full Name, Age, Bio, Interests) in MongoDB (HTTP 200).
13. `GET /php/profile.php` - Confirms updated MongoDB fields persist across requests (HTTP 200).
14. `POST /php/profile.php` - Rejects invalid age values (> 130 or < 1) (HTTP 422).
15. `POST /php/logout.php` - Invalidates session in Redis and destroys cookie (HTTP 200).
16. `GET /php/profile.php` - Verifies access is blocked after logout (HTTP 401).
17. Rate Limiting Check - Verifies brute-force protection triggers lockout after 5 failures (HTTP 429).
18. Health Check Verification - Verifies `/health.php` responds with HTTP 200.

---

### Testing Redis Session Persistence

To verify that Upstash Redis is storing sessions and persisting data across separate HTTP requests:

1. **Run the Upstash TLS diagnostic script:**
   ```bash
   php tests/test_predis_upstash.php
   ```
2. **Observe the test sequence:**
   - A session ID is generated: `go2qdmfgm4uvji561qvmu0ai73`
   - Session data (`user_id`, `username`, `authenticated`) is written.
   - The session is closed (`session_write_close()`).
   - The test reads the raw key directly from Upstash Redis:
     `Key found: PHPREDIS_SESSION:go2qdmfgm4uvji561qvmu0ai73`
   - In a second request, the session is resumed using the same session ID.
   - Data is verified to match exactly.

---

### Manual Browser Journey

1. Open `https://<your-render-url>.onrender.com/`.
2. Click **Create Account** (`register.html`):
   - Enter username, email, and password (e.g. `Password123!`). Observe the dynamic strength indicator.
   - Click **Create Account**. A green success notification confirms creation.
3. Click **Sign In** (`login.html`):
   - Enter incorrect credentials to verify generic error feedback.
   - Enter registered credentials. Upon success, browser redirects to `profile.html`.
4. Manage Profile (`profile.html`):
   - Observe account data loaded asynchronously from MySQL and MongoDB.
   - Click **Edit Profile**, update your Bio, Age, and Interests (e.g. `Cloud, PHP, Docker`).
   - Click **Save Changes**. The UI displays interest badges and saves to MongoDB.
5. Click **Logout**:
   - The session is deleted from Upstash Redis, and the browser redirects to `login.html`.
   - Attempting to revisit `profile.html` redirects to login.

---

## 13. Security Measures Implemented

1. **SQL Injection Defense:** All MySQL operations use **PDO prepared statements** with bound parameters. Emulated prepares are disabled (`PDO::ATTR_EMULATE_PREPARES => false`).
2. **Password Hashing:** Passwords are cryptographically hashed using `password_hash()` with `PASSWORD_DEFAULT` (Bcrypt). Passwords are never stored in plain text, never stored in session state, and never returned in API payloads.
3. **CSRF Mitigation:** Cryptographic 64-character tokens (`random_bytes(32)`) are tied to the server-side Redis session and validated on every state-changing HTTP request (`POST`, `PUT`, `DELETE`).
4. **Session Fixation Defense:** `session_regenerate_id(true)` is executed immediately upon successful authentication to invalidate old session identifiers.
5. **Strict Cookie Flags:** Session cookies are configured with:
   - `HttpOnly = true` (blocks JavaScript access via `document.cookie`).
   - `SameSite = Lax` (prevents cross-site request forgery).
   - `Secure = true` (automatically enforced in production and HTTPS requests).
6. **Brute-Force Rate Limiting:** Redis counters track failed attempts per IP and target email. Reaching 5 failures initiates a 5-minute lockout (HTTP 429).
7. **Cross-Database Rollback Compensation:** If MongoDB profile initialization fails after MySQL user insertion, a compensation routine removes the MySQL user row to prevent orphaned accounts.
8. **Information Disclosure Prevention:** Generic error responses prevent account enumeration. Database errors and stack traces are logged to `stderr` and never returned to the client.
9. **XSS Mitigation:** Dynamic values are sanitized using `htmlspecialchars()` on the server and context-safe text assignment in JavaScript.

---

## 14. Common Connection & TLS Troubleshooting

### 1. Aiven MySQL: `SSL operation failed with code 1` or `certificate verify failed`
- **Cause:** PHP cannot find or verify the Aiven CA certificate.
- **Solution:** Ensure `MYSQL_SSL_MODE=REQUIRED` is set and the full certificate content is pasted in `MYSQL_SSL_CA_CONTENT` in the Render environment variables. The entrypoint script saves this certificate to `/etc/ssl/certs/aiven-ca.pem`.

### 2. MongoDB Atlas: `No suitable servers found` or `connection timed out`
- **Cause:** Atlas Network Access IP Access List is blocking Render's outgoing traffic.
- **Solution:** In MongoDB Atlas > **Network Access**, add `0.0.0.0/0` (Allow access from anywhere). Ensure your database user password has no unencoded special characters in the connection string.

### 3. Upstash Redis: Connection Hang or TLS Handshake Failure
- **Cause:** Connecting to Upstash over plain TCP without TLS, or using legacy PhpRedis built without OpenSSL stream integration.
- **Solution:** The project bundles `predis/predis` (v3.7) to guarantee seamless TLS stream connections to Upstash. Ensure `REDIS_TLS=true` and `REDIS_PORT=6379`.

### 4. Render Port Mismatch: `Timed out waiting for port 10000`
- **Cause:** Apache configured to listen on port 80 instead of Render's dynamic `$PORT`.
- **Solution:** The project's `docker-entrypoint.sh` dynamically replaces Apache's listening port with `$PORT` (default 10000) on startup.

---

## 15. Free-Tier Limitations & Cold-Start Behavior

When hosting on free-tier cloud infrastructure, be aware of the following expected behaviors:

1. **Render Free Web Service Spindown:**
   - Free services on Render automatically spin down after 15 minutes of inactivity.
   - The first HTTP request after spindown initiates a cold start that takes approximately **45 to 60 seconds** while Render provisions the container. Subsequent requests respond instantly.
2. **Aiven Free MySQL Plan:**
   - 1 CPU and 1GB RAM limit. Suitable for assignment evaluation. Connection pooling is capped at 20 concurrent connections.
3. **MongoDB Atlas M0 Free Tier:**
   - Shared RAM and 512MB storage limit. Maximum 500 concurrent connections.
4. **Upstash Free Redis Tier:**
   - Serverless free tier supports up to 10,000 commands per day. More than sufficient for student evaluations and testing.

---

## License

This project is open-source and available under the [MIT License](LICENSE). Built for educational and internship evaluation purposes.
