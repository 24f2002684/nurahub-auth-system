# NuraHub Secure Authentication & User Profile System

A production-grade, secure, responsive authentication and user profile web application built for a software development internship evaluation.

The system features a multi-tier decoupled database architecture leveraging **MySQL**, **MongoDB**, and **Redis** to demonstrate modern separation of concerns, defensive security practices, and distributed session management without heavy full-stack frameworks.

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Key Features](#key-features)
3. [Technology Stack](#technology-stack)
4. [Architecture Explanation](#architecture-explanation)
5. [Folder Structure](#folder-structure)
6. [Prerequisites](#prerequisites)
7. [Installation & Local Setup](#installation--local-setup)
   - [Option A: Running with Docker Compose (Recommended)](#option-a-running-with-docker-compose-recommended)
   - [Option B: Running on Native Environment (Host / WSL)](#option-b-running-on-native-environment-host--wsl)
8. [Database Initialization](#database-initialization)
9. [How to Test the Application](#how-to-test-the-application)
   - [Automated Integration Test Suite](#automated-integration-test-suite)
   - [Manual End-to-End Browser Journey](#manual-end-to-end-browser-journey)
10. [Security Measures Implemented](#security-measures-implemented)
11. [Production Deployment Guide](#production-deployment-guide)
12. [Troubleshooting & Known Limitations](#troubleshooting--known-limitations)

---

## Project Overview

Modern enterprise applications avoid monolithic single-database designs when different application capabilities have fundamentally different access patterns and data models:
- **Relational Integrity:** Accounts and authentication require strict schema constraints, uniqueness guarantees, and ACID transactions (**MySQL**).
- **Flexible Document Storage:** Extended user profiles, biographical notes, and variable tagging benefit from dynamic, semi-structured document modeling (**MongoDB**).
- **High-Throughput State:** User sessions, token caches, and rate-limiting counters require sub-millisecond in-memory access and automatic time-to-live expirations (**Redis**).

NuraHub demonstrates how to integrate these three systems seamlessly in PHP 8.x using a clean, explainable architecture suitable for technical interviews.

---

## Key Features

- **Decoupled Database Architecture:**
  - **MySQL:** Stores core credentials (`users` table) with prepared statements and Bcrypt hashing.
  - **MongoDB:** Stores rich, semi-structured profiles (`user_profiles` collection) indexed uniquely by MySQL `user_id`.
  - **Redis:** Manages distributed PHP sessions (`PHPREDIS_SESSION:*`) and brute-force rate-limiting (`login_ratelimit:*`).
- **Defensive Security Controls:**
  - Session-bound CSRF token validation on all state-changing endpoints (`POST`, `PUT`, `DELETE`).
  - Session fixation defense (`session_regenerate_id(true)` upon privilege elevation).
  - Strict cookies (`HttpOnly`, `SameSite=Lax`, and conditional `Secure`).
  - Brute-force lockout: Blocks repeated invalid login attempts (5 failures in 5 minutes).
  - Cross-database compensation rollback: Deletes orphaned MySQL user records if MongoDB profile creation fails.
  - Contextual HTML output escaping to prevent Cross-Site Scripting (XSS).
  - Information leakage prevention: Generic authentication errors prevent account enumeration.
- **Responsive & Modern User Interface:**
  - Modern, minimal styling built on **Bootstrap 5.3** and custom CSS.
  - Asynchronous form submissions using **jQuery AJAX** (no full-page reloads).
  - Real-time password strength meter and match confirmation.
  - Skeleton loading states and dismissible notification banners.
  - Disabled submit buttons with spinner indicators during active network requests.

---

## Technology Stack

| Layer | Technology | Purpose |
|---|---|---|
| **Frontend Structure** | HTML5 (Semantic) | Accessible document structure and meta tags |
| **Frontend Styling** | CSS3 & Bootstrap 5.3 | Responsive layouts, typography, and card components |
| **Frontend Interactions**| Vanilla JavaScript & jQuery 3.7 | DOM events, input validation, and asynchronous AJAX |
| **Backend API** | PHP 8.1+ (8.3 tested) | RESTful JSON API endpoints, routing, and validation |
| **Relational Database** | MySQL 8.0 | User accounts, credentials, unique constraints |
| **NoSQL Document Store**| MongoDB 7.0 / 8.0 | Extended biographical profiles and interests |
| **In-Memory Cache** | Redis 7.x | Server-side PHP session storage and rate limiting |
| **Dependency Manager** | Composer 2.x | Official MongoDB PHP library & `vlucas/phpdotenv` |
| **Containerization** | Docker & Docker Compose | Multi-container local development orchestration |

---

## Architecture Explanation

```
[ Browser / Frontend Client ]
           │
           │ jQuery AJAX (JSON / Form Data + X-CSRF-Token)
           ▼
[ PHP 8.x Backend API ]
   ├── php/config.php      -> Environment variables, Redis session handler, CSRF engine
   ├── php/database.php    -> Singleton PDO (MySQL), MongoClient (MongoDB), Redis
   ├── php/register.php    -> Input validation, Bcrypt, MySQL insert + Mongo profile + Compensation
   ├── php/login.php       -> Redis rate limit, MySQL lookup, password_verify, session regeneration
   ├── php/profile.php     -> Protected GET (MySQL+Mongo) & POST/PUT profile updater
   ├── php/auth_check.php  -> Session status & CSRF token distributor
   └── php/logout.php      -> Session destruction & cookie invalidation
           │
 ┌─────────┼─────────────────────────┐
 │         │                         │
 ▼         ▼                         ▼
[ MySQL ]  [ MongoDB ]               [ Redis ]
users      user_profiles             PHPREDIS_SESSION:*
table      collection                login_ratelimit:*
```

For complete sequence and ER diagrams, refer to:
- [Application Flow Documentation](docs/application-flow.md)
- [Database Schema Documentation](docs/database-schema.md)

---

## Folder Structure

```text
nurahub-auth-system/
├── assets/
│   └── favicon.svg              # Brand icon
├── css/
│   └── style.css                # Custom UI styling, badges, and animations
├── js/
│   ├── login.js                 # AJAX login submission & rate-limit feedback
│   ├── profile.js               # Dashboard data loader, profile editor & logout
│   └── register.js              # Validation, password strength meter & AJAX registration
├── php/
│   ├── auth_check.php           # Session status and CSRF token endpoint
│   ├── config.php               # Environment loading, Redis sessions & helpers
│   ├── csrf.php                 # Dedicated CSRF token bootstrap endpoint
│   ├── database.php             # Unified database connection layer (MySQL, Mongo, Redis)
│   ├── login.php                # Authentication, rate limiting & session initiation
│   ├── logout.php               # Session destruction & cookie expiration
│   ├── profile.php              # Authenticated profile retrieval and update
│   └── register.php             # Account registration & cross-DB compensation
├── sql/
│   └── init.sql                 # MySQL users table schema initialization
├── tests/
│   ├── test_db.php              # Automated database connection verification script
│   └── test_api_e2e.php         # Automated end-to-end API test suite
├── docs/
│   ├── application-flow.md      # Detailed Mermaid architecture and request flow diagrams
│   └── database-schema.md       # MySQL ER diagram, MongoDB BSON schema & Redis keys
├── index.html                   # Public welcome page with auth-check auto-redirect
├── login.html                   # Sign-in page
├── profile.html                 # Authenticated user profile dashboard
├── register.html                # User registration page
├── Dockerfile                   # PHP 8.3 Apache container with extensions
├── docker-compose.yml           # Multi-service Docker configuration
├── composer.json                # Composer dependencies and metadata
├── .env.example                 # Environment configuration template
├── .gitignore                   # Version control exclusion rules
└── README.md                    # Comprehensive project documentation
```

---

## Prerequisites

To run this project locally, ensure you have either:

1. **Docker & Docker Compose** (Recommended):
   - Docker Desktop 24+ or Docker Engine with Docker Compose v2.
   **OR**
2. **Native Environment / WSL:**
   - PHP 8.1 or higher with extensions: `pdo_mysql`, `mongodb`, `redis`, `session`, `json`, `curl`, `mbstring`.
   - Composer 2.x.
   - MySQL 8.0 Server running on port 3306.
   - MongoDB 7.0/8.0 Server running on port 27017.
   - Redis 6.x/7.x Server running on port 6379.

---

## Installation & Local Setup

### Option A: Running with Docker Compose (Recommended)

1. **Clone the repository:**
   ```bash
   git clone https://github.com/24f2002684/nurahub-auth-system.git
   cd nurahub-auth-system
   ```

2. **Prepare the environment file:**
   ```bash
   cp .env.example .env
   ```
   *(The default docker-compose.yml overrides host addresses to use container service names: `mysql`, `mongo`, `redis`).*

3. **Build and launch the containers:**
   ```bash
   docker compose up -d --build
   ```

4. **Verify container health:**
   ```bash
   docker compose ps
   ```
   All 4 services (`nurahub_app`, `nurahub_mysql`, `nurahub_mongo`, `nurahub_redis`) will report `healthy` or `running`.

5. **Install Composer dependencies inside the container (first run):**
   ```bash
   docker compose exec app composer install --no-interaction
   ```

6. **Access the application:**
   Open your browser to: **[http://localhost:8080](http://localhost:8080)**

---

### Option B: Running on Native Environment (Host / WSL)

1. **Install PHP Extensions and Services (Ubuntu / Debian example):**
   ```bash
   sudo apt-get update
   sudo apt-get install -y php php-cli php-mysql php-redis php-mongodb php-curl php-mbstring composer mysql-server redis-server
   ```

2. **Start the Database Services:**
   ```bash
   sudo service mysql start
   sudo service redis-server start
   sudo service mongod start
   ```

3. **Install Composer dependencies:**
   ```bash
   composer install
   ```

4. **Initialize Environment Configuration:**
   Copy `.env.example` to `.env` and verify database credentials:
   ```bash
   cp .env.example .env
   ```

5. **Start PHP's Built-in Web Server:**
   ```bash
   php -S 127.0.0.1:8080
   ```
   Navigate to: **[http://127.0.0.1:8080](http://127.0.0.1:8080)**

---

## Database Initialization

If running via **Docker Compose**, `sql/init.sql` is automatically mounted into `/docker-entrypoint-initdb.d/init.sql` and initialized upon the first startup.

If running on **Native Environment**, run the following commands:
```bash
mysql -u root -p < sql/init.sql
```
Or execute the automated setup script:
```bash
php tests/test_db.php
```
This script tests connectivity to MySQL, MongoDB, and Redis, creating tables and indexes automatically.

---

## How to Test the Application

### Automated Integration Test Suite

The repository includes a comprehensive, automated CLI test suite (`tests/test_api_e2e.php`) that executes and verifies:
1. **CSRF Protection:** State-changing requests without CSRF tokens are rejected (HTTP 403).
2. **Registration Validation:** Rejection of password mismatch, weak passwords, and invalid emails (HTTP 422).
3. **Account Creation:** Successful insertion of user in MySQL and initial profile document in MongoDB (HTTP 201).
4. **Duplicate Prevention:** Rejection of duplicate email and duplicate username (HTTP 409).
5. **Authentication Verification:**
   - Invalid password rejected (HTTP 401).
   - Unknown email rejected with generic message (HTTP 401).
   - Valid credentials succeed, regenerate session ID, and issue cookie (HTTP 200).
6. **Rate Limiting:** Repeated invalid attempts trigger lockout (HTTP 429).
7. **Profile Access Control:**
   - Unauthenticated GET to `profile.php` rejected (HTTP 401).
   - Authenticated GET returns only the current user's profile.
8. **Profile Updates:**
   - Authenticated user can update `full_name`, `age`, `bio`, and `interests` in MongoDB.
   - Non-permitted fields (such as `username` or `password_hash`) are strictly ignored.
   - Invalid age range (> 130 or < 1) rejected (HTTP 422).
9. **Secure Logout:** Session is invalidated from Redis and cookie is expired (HTTP 200).
10. **Post-Logout Access:** Accessing `profile.php` after logout is rejected (HTTP 401).

To run the automated suite:
```bash
php tests/test_api_e2e.php
```

### Manual End-to-End Browser Journey

1. **Visit Home Page (`index.html`):**
   - Click **"Create Account"**.
2. **Register a New Account (`register.html`):**
   - Enter a username (e.g. `alex_morgan`).
   - Enter an email (e.g. `alex@example.com`).
   - Enter password `Password123!` (observe the dynamic strength meter turn green).
   - Re-type matching password.
   - Click **"Create Account"** (button disables with a spinner).
   - Green confirmation banner appears with link to **"Go to Login"**.
3. **Sign In (`login.html`):**
   - Enter invalid password to observe generic error: *"Invalid email or password."*
   - Enter registered credentials and click **"Sign In"**.
   - Immediate redirect to `profile.html`.
4. **View & Edit Profile (`profile.html`):**
   - Observe skeleton loader resolve to account details (`username`, `email`, `created_at`).
   - Click **"Edit Profile"**.
   - Enter Full Name (e.g. `Alex Morgan`), Age (`28`), Bio, and comma-separated interests (`Cloud Architecture, PHP, Security, AI`).
   - Click **"Save Changes"**.
   - Profile card updates immediately with styled interest badges and MongoDB confirmation banner.
5. **Log Out:**
   - Click **"Logout"** in the top navigation.
   - Session is invalidated from Redis and browser redirects to `login.html`.
   - Attempting to navigate back to `profile.html` redirects back to `login.html`.

---

## Security Measures Implemented

1. **SQL Injection Defense:** All database queries involving user input use **PDO prepared statements** with bound parameters. Emulated prepares are explicitly disabled (`PDO::ATTR_EMULATE_PREPARES => false`).
2. **Password Hashing:** Passwords are cryptographically hashed using PHP `password_hash()` with `PASSWORD_DEFAULT` (Bcrypt with cost factor 10 or Argon2id). Plain-text passwords and password hashes are never stored in sessions or returned in JSON responses.
3. **Cross-Site Request Forgery (CSRF) Defense:** Session-bound 64-character cryptographic tokens (`random_bytes(32)`) are verified on all state-changing HTTP requests (`POST`, `PUT`, `DELETE`).
4. **Session Fixation Prevention:** Upon privilege elevation during login, `session_regenerate_id(true)` destroys the previous session ID and assigns a fresh, unguessable token.
5. **Strict Cookie Flags:** Session cookies are configured with:
   - `HttpOnly = true` (prevents JavaScript access via `document.cookie`).
   - `SameSite = Lax` (mitigates cross-site request attacks).
   - `Secure = true` (enforced when HTTPS is detected or configured).
6. **Brute-Force Rate Limiting:** Redis counters track failed attempts per IP and target email. Reaching 5 failures initiates a 5-minute lockout (HTTP 429).
7. **Cross-Database Consistency Compensation:** If MongoDB profile initialization encounters a failure after MySQL account creation, a compensation rollback routine automatically deletes the inserted MySQL row, preventing orphaned ghost accounts.
8. **Information Disclosure Prevention:** Public API responses do not expose internal database errors, SQL syntax, file paths, or whether an email address exists in the system.
9. **XSS Mitigation:** All dynamic variables rendered in the DOM are escaped using `htmlspecialchars()` on the backend and context-safe replacement in JavaScript.

---

## Production Deployment Guide

Deploying this multi-database application in a production environment requires configuring PHP, MySQL, MongoDB, and Redis.

### Architectural Hosting Options

Because few single hosting platforms provide all three databases out of the box, practical production deployments typically utilize one of the following architectures:

#### Option 1: Container Orchestration (Render, AWS ECS, DigitalOcean App Platform, or GCP Cloud Run)
- Deploy the Docker container built from the provided `Dockerfile`.
- Attach managed cloud databases:
  - **MySQL:** AWS RDS MySQL, DigitalOcean Managed MySQL, or PlanetScale.
  - **MongoDB:** MongoDB Atlas (M0 Free Tier or Dedicated Cluster).
  - **Redis:** Upstash Redis (Serverless) or AWS ElastiCache.
- Set environment variables (`MYSQL_HOST`, `MONGO_URI`, `REDIS_HOST`, `REDIS_PASSWORD`, etc.) in the platform's dashboard.

#### Option 2: Traditional Cloud VPS (Ubuntu 22.04 / 24.04 on DigitalOcean, Linode, or AWS EC2)
- Install Nginx, PHP 8.3-FPM, MySQL Server, MongoDB, and Redis Server.
- Point Nginx web root to `/var/www/nurahub-auth-system`.
- Secure databases behind `127.0.0.1` and enable firewall rules (`ufw`).
- Obtain a free SSL certificate via Let's Encrypt (`certbot --nginx`).
- Configure `APP_ENV=production` and `SESSION_COOKIE_SECURE=true` in `.env`.

---

## Troubleshooting & Known Limitations

### Common Issues and Resolutions

1. **"Composer autoloader not found"**
   - *Cause:* `composer install` has not been executed yet.
   - *Fix:* Run `composer install` locally or `docker compose exec app composer install`.

2. **"Database service temporarily unavailable"**
   - *Cause:* MySQL server is stopped or credentials in `.env` are mismatched.
   - *Fix:* Verify MySQL is running (`service mysql status`) and test connection credentials with `php tests/test_db.php`.

3. **"Failed to connect to Redis server"**
   - *Cause:* Redis service is inactive or `ext-redis` is missing.
   - *Fix:* Start Redis (`sudo service redis-server start`) or install the extension (`sudo apt-get install php-redis`).

4. **"Invalid or missing CSRF token"**
   - *Cause:* Session expired or request sent without token.
   - *Fix:* Refresh the webpage; the frontend will automatically fetch a new session token via `php/auth_check.php`.

---

## License

This project is licensed under the MIT License. Designed for educational and internship evaluation purposes.
