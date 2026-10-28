<?php
/**
 * NuraHub Auth & User Profile System
 * Secure Logout Endpoint (Session Invalidation and Cookie Destruction)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Accept only POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    send_json_error('Method Not Allowed', [], 405);
}

init_session();
require_csrf_protection();

// Check if user was actually logged in
$wasAuthenticated = !empty($_SESSION['authenticated']) || !empty($_SESSION['user_id']);

// Clear all session variables
$_SESSION = [];

// Invalidate session cookie in browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $cookieName = session_name();
    setcookie(
        $cookieName,
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => $params['secure'] ?? false,
            'httponly' => $params['httponly'] ?? true,
            'samesite' => $params['samesite'] ?? 'Lax'
        ]
    );
}

// Destroy session data from Redis/server store
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

send_json_success('Successfully logged out.', [
    'redirect' => 'login.html'
]);
