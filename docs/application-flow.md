# Application Flow & Architecture Documentation

This document describes the end-to-end request lifecycle and authentication workflows implemented in the **NuraHub Secure Authentication and User Profile System**.

---

## 1. High-Level System Architecture

The application adopts a decoupled multi-tier architecture where three specialized databases handle specific domains:
- **MySQL (RDBMS):** Manages relational user account entities, constraints, and hashed credentials.
- **MongoDB (NoSQL Document Store):** Manages dynamic, semi-structured profile data (bio, age, interests tags).
- **Redis (In-Memory Key-Value Store):** Serves as the distributed session backend and enforces brute-force rate-limiting.

```mermaid
graph TD
    Client["Browser Client (HTML5 / Bootstrap 5 / jQuery AJAX)"]
    
    subgraph "Backend API (PHP 8.x Runtime)"
        AuthCheck["php/auth_check.php"]
        Register["php/register.php"]
        Login["php/login.php"]
        Profile["php/profile.php"]
        Logout["php/logout.php"]
        Config["php/config.php & Security Layer"]
    end
    
    subgraph "Data Storage Tier"
        MySQL[("MySQL 8.0<br/>(users table)")]
        MongoDB[("MongoDB 8.0<br/>(user_profiles collection)")]
        Redis[("Redis 7.x<br/>(PHP sessions & rate-limits)")]
    end
    
    Client -->|Check Session / Get CSRF| AuthCheck
    Client -->|POST Credentials| Register
    Client -->|POST Login + CSRF| Login
    Client -->|GET / POST Profile Data| Profile
    Client -->|POST Logout + CSRF| Logout
    
    AuthCheck -.-> Redis
    Register --> MySQL
    Register --> MongoDB
    Login --> MySQL
    Login --> Redis
    Profile --> MySQL
    Profile --> MongoDB
    Profile -.-> Redis
    Logout --> Redis
```

---

## 2. Detailed Workflows

### A. User Registration Flow

The registration endpoint guarantees cross-database consistency through an automated compensation rollback:

```mermaid
sequenceDiagram
    autonumber
    actor User as User Browser
    participant JS as register.js
    participant API as php/register.php
    participant MySQL as MySQL (nurahub_auth)
    participant Mongo as MongoDB (nurahub_profiles)

    User->>JS: Submits username, email, password, confirm_password
    JS->>JS: Client-side regex & strength validation
    JS->>API: AJAX POST (JSON + X-CSRF-Token)
    API->>API: Verify CSRF token & sanitize input
    API->>MySQL: Prepared statement check (duplicate email/username)
    alt Conflict Found
        MySQL-->>API: Row exists
        API-->>JS: HTTP 409 Conflict JSON error
        JS-->>User: Displays inline field conflict warning
    else Unique Credentials
        API->>API: Hash password via password_hash() (PASSWORD_DEFAULT)
        API->>MySQL: INSERT INTO users (prepared statement)
        MySQL-->>API: Returns auto-increment new user ID ($newUserId)
        
        API->>Mongo: insertOne({ user_id: $newUserId, full_name: '', interests: [], ... })
        alt MongoDB Success
            Mongo-->>API: Insert acknowledged
            API-->>JS: HTTP 201 Created JSON
            JS-->>User: Success banner & navigation link to login.html
        else MongoDB Failure
            Mongo-->>API: Exception (Connection or write failure)
            API->>MySQL: Compensation: DELETE FROM users WHERE id = $newUserId
            MySQL-->>API: Deleted rolled-back row
            API-->>JS: HTTP 500 Internal Error (Generic message)
            JS-->>User: Display error notification (No ghost records left)
        end
    end
```

---

### B. User Login & Session Establishment Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as User Browser
    participant JS as login.js
    participant API as php/login.php
    participant Redis as Redis (Rate Limit & Session Store)
    participant MySQL as MySQL (users)

    User->>JS: Enters email and password
    JS->>API: AJAX POST credentials with X-CSRF-Token
    API->>API: Validate CSRF and inputs
    API->>Redis: Check login_ratelimit key
    alt Rate Limit Exceeded (>= 5 attempts in 300s)
        Redis-->>API: Attempts count >= 5
        API-->>JS: HTTP 429 Too Many Requests
        JS-->>User: "Too many failed attempts. Please wait 5 minutes."
    else Allowed Attempt
        API->>MySQL: SELECT id, username, email, password_hash WHERE email = :email
        MySQL-->>API: User record (or null)
        API->>API: password_verify(submittedPassword, password_hash)
        alt Invalid Password or Email Not Found
            API->>Redis: INCR login_ratelimit (TTL 300s)
            API-->>JS: HTTP 401 "Invalid email or password" (Generic)
            JS-->>User: Generic authentication error
        else Credentials Verified
            API->>Redis: DEL login_ratelimit
            API->>API: session_regenerate_id(true) [Fixation Prevention]
            API->>Redis: Store $_SESSION[user_id, username, email, authenticated]
            API->>API: Re-issue fresh CSRF token
            API-->>JS: HTTP 200 Success + Cookie (HttpOnly, SameSite=Lax)
            JS-->>User: "Login successful" & redirect to profile.html
        end
    end
```

---

### C. Authenticated Profile Access & Update Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as Authenticated User
    participant JS as profile.js
    participant API as php/profile.php
    participant Redis as Redis Session
    participant MySQL as MySQL (users)
    participant Mongo as MongoDB (user_profiles)

    User->>JS: Navigates to profile.html
    JS->>API: AJAX GET php/profile.php
    API->>Redis: Inspect session cookie (Strict mode)
    alt Unauthenticated / Expired
        Redis-->>API: No active session
        API-->>JS: HTTP 401 Unauthorized
        JS->>User: Immediate redirect to login.html
    else Authenticated Session Found
        Redis-->>API: Returns $_SESSION['user_id']
        API->>MySQL: SELECT username, email, created_at WHERE id = $userId
        MySQL-->>API: Account metadata
        API->>Mongo: findOne({ user_id: $userId })
        Mongo-->>API: Profile document (full_name, age, bio, interests)
        API-->>JS: HTTP 200 JSON with account and profile data
        JS->>User: Renders profile dashboard & enables Edit mode
    end

    opt User Submits Profile Updates
        User->>JS: Edits full_name, age, bio, interests & clicks Save
        JS->>API: AJAX POST/PUT with X-CSRF-Token and JSON payload
        API->>API: Verify session & validate field limits
        API->>Mongo: updateOne({ user_id: $userId }, { $set: {...} }, { upsert: true })
        Mongo-->>API: Acknowledged
        API-->>JS: HTTP 200 Success + updated profile payload
        JS->>User: Displays success notification & refreshes view card
    end
```

---

### D. Secure Logout Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as Authenticated User
    participant JS as profile.js
    participant API as php/logout.php
    participant Redis as Redis Session

    User->>JS: Clicks "Logout"
    JS->>API: AJAX POST php/logout.php (with X-CSRF-Token)
    API->>API: Validate CSRF token
    API->>Redis: Invalidate & delete session keys
    API->>API: Expire session cookie (Set-Cookie: max-age=-3600)
    API-->>JS: HTTP 200 { status: "success", redirect: "login.html" }
    JS->>User: Redirects browser to login.html
```
