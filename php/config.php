<?php
/**
 * NuraHub Auth & User Profile System
 * Central Configuration, Environment Loader, Redis Session Handler, and Security Helpers
 */

declare(strict_types=1);

// Prevent direct execution outside of PHP include / HTTP request
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Direct access forbidden']);
    exit;
}

// Locate and load Composer autoloader
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Composer autoloader not found. Please run "composer install".'
    ]);
    exit;
}
require_once $autoloadPath;

// Load environment variables from .env if available
$rootDir = dirname(__DIR__);
if (file_exists($rootDir . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($rootDir);
    $dotenv->safeLoad();
}

/**
 * Safe environment variable retrieval with default fallback
 */
function env(string $key, mixed $default = null): mixed {
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}

// Application environment settings
$appEnv = env('APP_ENV', 'production');
$appDebug = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);

// Production-safe PHP error configuration:
// Never display raw PHP errors in responses (prevents JSON corruption and information disclosure)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Configure and initialize secure PHP sessions backed by Redis
 */
function init_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $redisHost = env('REDIS_HOST', '127.0.0.1');
    $redisPort = env('REDIS_PORT', '6379');
    $redisPassword = env('REDIS_PASSWORD', '');
    $redisTimeout = env('REDIS_TIMEOUT', '2.5');
    $redisPrefix = env('REDIS_PREFIX', 'PHPREDIS_SESSION:');
    $lifetime = (int) env('SESSION_LIFETIME', 3600);
    $cookieName = env('SESSION_COOKIE_NAME', 'nurahub_session');
    $secureCookie = filter_var(env('SESSION_COOKIE_SECURE', false), FILTER_VALIDATE_BOOLEAN);
    $sameSite = env('SESSION_COOKIE_SAMESITE', 'Lax');

    // Auto-enable secure cookies if request is over HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $secureCookie = true;
    }

    // Configure Redis session handler if ext-redis is present
    if (extension_loaded('redis') && !empty($redisHost)) {
        ini_set('session.save_handler', 'redis');

        $authQuery = '';
        if (!empty($redisPassword)) {
            $authQuery = '&auth=' . rawurlencode($redisPassword);
        }
        $savePath = sprintf(
            'tcp://%s:%s?prefix=%s&timeout=%s%s',
            $redisHost,
            $redisPort,
            $redisPrefix,
            $redisTimeout,
            $authQuery
        );
        ini_set('session.save_path', $savePath);
    }

    // Strict session settings
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);

    session_name($cookieName);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    // Start session
    session_start();

    // Verify session lifetime integrity
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $lifetime)) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['LAST_ACTIVITY'] = time();
}

/**
 * Send uniform JSON responses with appropriate headers
 */
function send_json(array $payload, int $statusCode = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        http_response_code($statusCode);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send standard JSON error response
 */
function send_json_error(string $message, array $errors = [], int $statusCode = 400): void {
    $response = [
        'status' => 'error',
        'message' => $message
    ];
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    send_json($response, $statusCode);
}

/**
 * Send standard JSON success response
 */
function send_json_success(string $message, array $data = [], int $statusCode = 200): void {
    $response = [
        'status' => 'success',
        'message' => $message
    ];
    if (!empty($data)) {
        $response['data'] = $data;
    }
    send_json($response, $statusCode);
}

/**
 * Generate or retrieve session-bound CSRF token
 */
function get_csrf_token(): string {
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from header or request body
 */
function verify_csrf_token(?string $submittedToken = null): bool {
    init_session();
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) {
        return false;
    }

    // Read token from parameter or standard headers
    if ($submittedToken === null) {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] 
            ?? $_POST['csrf_token'] 
            ?? null;
    }

    if (!$submittedToken || !is_string($submittedToken)) {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

/**
 * Guard state-changing endpoints with CSRF verification
 */
function require_csrf_protection(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        if (!verify_csrf_token()) {
            send_json_error('Invalid or missing CSRF token. Please refresh the page and try again.', [], 403);
        }
    }
}

/**
 * Enforce authenticated user session
 */
function require_authenticated_user(): int {
    init_session();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId || !is_numeric($userId)) {
        send_json_error('Unauthorized. Please log in to continue.', [], 401);
    }
    return (int) $userId;
}

/**
 * Safe HTML string escaping for output
 */
function sanitize_html(?string $string): string {
    return htmlspecialchars((string) $string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
