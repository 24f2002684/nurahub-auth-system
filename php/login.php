<?php
/**
 * NuraHub Auth & User Profile System
 * User Login Endpoint with Rate Limiting, Password Verification, and Session Regeneration
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// Accept only POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    send_json_error('Method Not Allowed', [], 405);
}

// Initialize Redis-backed session and enforce CSRF verification
init_session();
require_csrf_protection();

// Parse input payload (JSON or form data)
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

$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

$errors = [];
if ($email === '') {
    $errors['email'] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please provide a valid email address.';
} else {
    $email = strtolower($email);
}

if ($password === '') {
    $errors['password'] = 'Password is required.';
}

if (!empty($errors)) {
    send_json_error('Please fill in all required fields.', $errors, 422);
}

// -----------------------------------------------------------------------------
// Rate Limiting Protection via Redis (Fallback to session if Redis unavailable)
// -----------------------------------------------------------------------------
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateLimitKey = 'login_ratelimit:' . md5($clientIp . '|' . $email);
$maxAttempts = 5;
$decaySeconds = 300; // 5 minutes lockout

$redis = Database::getRedis();
$currentAttempts = 0;

if ($redis !== null) {
    try {
        $currentAttempts = (int) ($redis->get($rateLimitKey) ?: 0);
    } catch (Exception $e) {
        error_log('[RateLimit Redis Read Error] ' . $e->getMessage());
    }
} else {
    // Session fallback for rate limiting
    $currentAttempts = (int) ($_SESSION['login_attempts'][$rateLimitKey] ?? 0);
}

if ($currentAttempts >= $maxAttempts) {
    send_json_error(
        'Too many failed login attempts. Please wait 5 minutes before trying again.',
        [],
        429
    );
}

// -----------------------------------------------------------------------------
// Authenticate User Against MySQL
// -----------------------------------------------------------------------------
try {
    $pdo = Database::getMySQL();
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    $authSuccess = false;
    if ($user && is_array($user) && !empty($user['password_hash'])) {
        if (password_verify($password, $user['password_hash'])) {
            $authSuccess = true;
        }
    }

    if (!$authSuccess) {
        // Increment rate limit attempts
        if ($redis !== null) {
            try {
                $newAttempts = $redis->incr($rateLimitKey);
                if ($newAttempts === 1) {
                    $redis->expire($rateLimitKey, $decaySeconds);
                }
            } catch (Exception $e) {
                error_log('[RateLimit Redis Write Error] ' . $e->getMessage());
            }
        } else {
            $_SESSION['login_attempts'][$rateLimitKey] = $currentAttempts + 1;
        }

        // Generic error message: do not disclose whether email exists
        send_json_error('Invalid email or password.', [], 401);
    }

    // Authentication succeeded: clear rate limit key
    if ($redis !== null) {
        try {
            $redis->del($rateLimitKey);
        } catch (Exception $e) {
            error_log('[RateLimit Redis Del Error] ' . $e->getMessage());
        }
    } else {
        unset($_SESSION['login_attempts'][$rateLimitKey]);
    }

    // Prevent Session Fixation: Regenerate session ID upon privilege elevation
    session_regenerate_id(true);

    // Populate server-side Redis session data (never store password hashes)
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['authenticated'] = true;
    $_SESSION['logged_in_at'] = time();

    // Re-issue a fresh CSRF token for the authenticated session
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    send_json_success('Login successful. Welcome back!', [
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email']
        ],
        'csrf_token' => $_SESSION['csrf_token'],
        'redirect' => 'profile.html'
    ]);

} catch (Throwable $e) {
    error_log('[Login MySQL Error] ' . $e->getMessage());
    send_json_error('Authentication service is currently unavailable. Please try again later.', [], 500);
}
