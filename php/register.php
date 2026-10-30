<?php
/**
 * NuraHub Auth & User Profile System
 * User Registration Endpoint (MySQL User + MongoDB Initial Profile)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// Only allow POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    send_json_error('Method Not Allowed', [], 405);
}

// Ensure session is started and verify CSRF token
init_session();
require_csrf_protection();

// Parse request payload (supports JSON payloads and application/x-www-form-urlencoded)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];
if (stripos($contentType, 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
} else {
    $input = $_POST;
}

// Extract and trim inputs
$username = trim((string) ($input['username'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$confirmPassword = (string) ($input['confirm_password'] ?? '');

$errors = [];

// 1. Validate Username
if ($username === '') {
    $errors['username'] = 'Username is required.';
} elseif (strlen($username) < 3 || strlen($username) > 30) {
    $errors['username'] = 'Username must be between 3 and 30 characters.';
} elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    $errors['username'] = 'Username may only contain letters, numbers, and underscores.';
}

// 2. Validate Email
if ($email === '') {
    $errors['email'] = 'Email address is required.';
} elseif (strlen($email) > 191) {
    $errors['email'] = 'Email address cannot exceed 191 characters.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please provide a valid email address.';
} else {
    $email = strtolower($email);
}

// 3. Validate Password
if ($password === '') {
    $errors['password'] = 'Password is required.';
} elseif (strlen($password) < 8) {
    $errors['password'] = 'Password must be at least 8 characters long.';
} elseif (strlen($password) > 128) {
    $errors['password'] = 'Password cannot exceed 128 characters.';
} elseif (!preg_match('/[A-Z]/', $password)) {
    $errors['password'] = 'Password must contain at least one uppercase letter.';
} elseif (!preg_match('/[a-z]/', $password)) {
    $errors['password'] = 'Password must contain at least one lowercase letter.';
} elseif (!preg_match('/[0-9]/', $password)) {
    $errors['password'] = 'Password must contain at least one digit.';
}

// 4. Validate Confirm Password
if ($confirmPassword === '') {
    $errors['confirm_password'] = 'Please confirm your password.';
} elseif ($password !== $confirmPassword) {
    $errors['confirm_password'] = 'Passwords do not match.';
}

if (!empty($errors)) {
    send_json_error('Validation failed. Please correct the errors and try again.', $errors, 422);
}

// Hash password with PHP standard algorithm (Bcrypt / Argon2 default)
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$pdo = null;
$newUserId = null;

try {
    $pdo = Database::getMySQL();

    // Check for duplicate username or email with prepared statement
    $checkStmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = :username OR email = :email LIMIT 1');
    $checkStmt->execute([
        ':username' => $username,
        ':email' => $email
    ]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        $conflictErrors = [];
        if (strcasecmp($existing['username'], $username) === 0) {
            $conflictErrors['username'] = 'This username is already taken.';
        }
        if (strcasecmp($existing['email'], $email) === 0) {
            $conflictErrors['email'] = 'An account with this email already exists.';
        }
        send_json_error('Conflict detected.', $conflictErrors, 409);
    }

    // Insert user into MySQL
    $insertStmt = $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, created_at) VALUES (:username, :email, :password_hash, NOW())'
    );
    $insertStmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash
    ]);

    $newUserId = (int) $pdo->lastInsertId();

} catch (Throwable $e) {
    error_log('[Registration MySQL Error] ' . $e->getMessage());
    if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
        send_json_error('Username or email is already registered.', [], 409);
    }
    send_json_error('Unable to create account at this time. Please try again later.', [], 500);
}

// Now initialize MongoDB user profile document
try {
    $profilesCollection = Database::getUserProfilesCollection();

    $now = new MongoDB\BSON\UTCDateTime();
    $profileDocument = [
        'user_id' => $newUserId,
        'full_name' => '',
        'age' => null,
        'bio' => '',
        'interests' => [],
        'created_at' => $now,
        'updated_at' => $now
    ];

    $profilesCollection->insertOne($profileDocument);

} catch (Throwable $e) {
    // Compensation Strategy: If MongoDB profile creation fails, roll back (delete) the MySQL user record
    // to prevent partial ghost accounts.
    error_log('[Registration MongoDB Error] ' . $e->getMessage());
    if ($pdo !== null && $newUserId !== null) {
        try {
            $compensationStmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $compensationStmt->execute([':id' => $newUserId]);
            error_log("[Registration Compensation] Rolled back MySQL user ID {$newUserId} due to MongoDB failure.");
        } catch (Throwable $compEx) {
            error_log('[Registration Compensation Failure] ' . $compEx->getMessage());
        }
    }

    send_json_error('Profile storage initialization failed. Please try again later.', [], 500);
}

// Return success response with HTTP 201 Created
send_json_success('Registration completed successfully! You can now log in.', [
    'user' => [
        'id' => $newUserId,
        'username' => $username,
        'email' => $email
    ]
], 201);
