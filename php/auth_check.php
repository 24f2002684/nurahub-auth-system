<?php
/**
 * NuraHub Auth & User Profile System
 * Lightweight Session & Authentication Status Verification Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Only GET method is allowed for checking status
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json_error('Method Not Allowed', [], 405);
}

init_session();

$isAuthenticated = !empty($_SESSION['authenticated']) && !empty($_SESSION['user_id']);
$csrfToken = get_csrf_token();

if ($isAuthenticated) {
    send_json_success('Authenticated session active', [
        'authenticated' => true,
        'user' => [
            'id' => (int) $_SESSION['user_id'],
            'username' => (string) ($_SESSION['username'] ?? ''),
            'email' => (string) ($_SESSION['email'] ?? '')
        ],
        'csrf_token' => $csrfToken
    ]);
} else {
    send_json_success('No authenticated session', [
        'authenticated' => false,
        'csrf_token' => $csrfToken
    ]);
}
