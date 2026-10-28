# Database Schema & Storage Architecture

This document details the database schemas, indexing strategies, constraints, and data models across **MySQL**, **MongoDB**, and **Redis**.

---

## 1. MySQL: User Accounts & Authentication Engine

MySQL acts as the primary relational store for authoritative user account identities, authentication credentials, and strict uniqueness constraints.

### Mermaid Entity-Relationship (ER) Diagram

```mermaid
erDiagram
    USERS {
        int unsigned id PK "Auto-increment primary key"
        varchar(50) username UK "Unique alphanumeric handle"
        varchar(191) email UK "Unique lowercase email address"
        varchar(255) password_hash "Bcrypt or Argon2 default hash"
        timestamp created_at "Account creation timestamp"
    }

    USER_PROFILES_MONGO {
        ObjectId _id PK "MongoDB internal identifier"
        int user_id FK "Logical reference to MySQL USERS.id"
        string full_name "Full display name (max 100)"
        int age "User age (1 - 130 or null)"
        string bio "Biographical summary (max 1000)"
        array interests "Array of interest tags"
        utc_datetime created_at "Initial profile creation date"
        utc_datetime updated_at "Last update timestamp"
    }

    USERS ||--|| USER_PROFILES_MONGO : "references 1:1 via user_id"
```

### Table Definition (`users`)

```sql
CREATE TABLE `users` (
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

#### Field Explanations & Design Rationale
- `id`: Unsigned 32-bit integer, providing efficient primary key lookup and foreign key referencing.
- `username`: Enforces unique usernames up to 50 characters with `uk_users_username`.
- `email`: Enforces case-insensitive uniqueness with `uk_users_email`. Limited to 191 characters to ensure full UTF-8 mb4 indexing compatibility on all MySQL engines.
- `password_hash`: 255-character buffer to accommodate modern password hashing standards (bcrypt, argon2id).
- `created_at`: Tracks registration time. Indexed to optimize analytical or chronological sorting queries.

---

## 2. MongoDB: Extended User Profiles

MongoDB is chosen for user profile information because biographical attributes (e.g. variable-length tags, arrays of interests, optional biographical notes) map naturally to flexible BSON documents.

### Collection: `user_profiles`

#### Document Schema Example

```json
{
  "_id": { "$oid": "66804a11f974cb3d702319c1" },
  "user_id": 1,
  "full_name": "Suhail Akthar",
  "age": 24,
  "bio": "Full-stack software developer passionate about high-performance cloud architectures, database design, and web security.",
  "interests": [
    "PHP",
    "MySQL",
    "MongoDB",
    "Redis",
    "Docker",
    "Security"
  ],
  "created_at": { "$date": "2026-10-28T10:35:00.000Z" },
  "updated_at": { "$date": "2026-10-28T10:45:00.000Z" }
}
```

#### BSON Data Types & Logical Representation
| Field | BSON Type | Description |
|---|---|---|
| `_id` | `ObjectId` | MongoDB automatic unique primary key. |
| `user_id` | `Int32` (`int`) | **Foreign Key:** Logical reference matching `users.id` from MySQL. Stored strictly as a native integer. |
| `full_name`| `String` | User's full name (max 100 characters). |
| `age` | `Int32` or `Null` | Numerical age (validated strictly between 1 and 130). |
| `bio` | `String` | Multi-line biography text (max 1000 characters). |
| `interests`| `Array` of `String`| Set of normalized interest strings (max 25 tags, each max 40 chars). |
| `created_at`| `UTCDateTime` | Timestamp representing when the profile was generated. |
| `updated_at`| `UTCDateTime` | Timestamp updated automatically whenever a user alters their profile. |

#### MongoDB Indexes
```javascript
db.user_profiles.createIndex(
  { "user_id": 1 },
  { "unique": true, "name": "idx_unique_user_id" }
);
```
- **Uniqueness Guarantee:** The unique index on `user_id` guarantees at the database level that no duplicate profile documents can be created for the same MySQL user.
- **Lookup Performance:** Direct index scans on `user_id` achieve $O(1)$ read and update query performance.

---

## 3. Redis: Distributed PHP Session & Rate Limiting

Redis provides high-throughput, low-latency in-memory data structures to handle session state without touching disk databases on every HTTP request.

### Key Namespaces and Storage Patterns

#### 1. PHP Session Namespace (`PHPREDIS_SESSION:*`)
- **Key Pattern:** `PHPREDIS_SESSION:<session_id>` (e.g. `PHPREDIS_SESSION:8d91c7f1a30b429188d5e821`)
- **Value:** Serialized PHP session string managed by `ext-redis`.
- **TTL (Time-To-Live):** Configurable via `SESSION_LIFETIME` (default: 3600 seconds / 1 hour).
- **Contents:**
  ```text
  user_id|i:1;username|s:13:"suhail_akthar";email|s:18:"suhail@example.com";authenticated|b:1;logged_in_at|i:1793183700;csrf_token|s:64:"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
  ```
- **Security Constraints:**
  - Strict session mode (`session.use_strict_mode = 1`) ensures uninitialized session IDs are rejected.
  - Fixation defense via `session_regenerate_id(true)` replaces the session key on authentication.
  - Password hashes are **never** stored in Redis sessions.

#### 2. Brute-Force Rate Limiting Namespace (`login_ratelimit:*`)
- **Key Pattern:** `login_ratelimit:<md5(client_ip + '|' + lowercase_email)>`
- **Type:** String (Integer counter via `INCR`).
- **TTL:** 300 seconds (5 minutes rolling window).
- **Behavior:**
  - Initial failed attempt initializes the key with a 300-second TTL.
  - Each subsequent failure increments the counter.
  - When the counter reaches $\ge 5$, subsequent attempts are blocked immediately with `HTTP 429 Too Many Requests`.
  - A successful authentication immediately calls `DEL` to clear the counter.
