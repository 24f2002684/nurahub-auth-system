<?php
/**
 * NuraHub Auth & User Profile System
 * CSRF Token Bootstrap and Refresh Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Accept only GET requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    send_json_error('Method Not Allowed', [], 405);
}

// Generate or retrieve current session-bound CSRF token
$token = get_csrf_token();

send_json_success('CSRF token retrieved', [
    'csrf_token' => $token
]);
