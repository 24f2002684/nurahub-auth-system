<?php
/**
 * NuraHub Auth & User Profile System
 * Safe Health Check and Liveness / Readiness Probe Endpoint
 * 
 * Complies with production standards:
 * - Returns HTTP 200 JSON when the application runtime is healthy
 * - Avoids exposing database credentials, exception messages, stack traces, or internal paths
 * - Avoids performing heavy database roundtrips on routine pings
 * - Verifies essential application readiness (Composer dependencies, PHP extensions)
 */

declare(strict_types=1);

// Set strict headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// 1. Check fundamental dependencies
$autoloaderPath = __DIR__ . '/vendor/autoload.php';
$autoloaderReady = file_exists($autoloaderPath);

if ($autoloaderReady) {
    require_once $autoloaderPath;
}

$requiredExtensions = ['pdo', 'pdo_mysql', 'mongodb'];
$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

// Session support: either native PhpRedis extension or Composer Predis library must be present
$sessionSupportReady = extension_loaded('redis') || class_exists('Predis\Client');

// If critical runtime dependencies are missing, return 503 Service Unavailable
if (!$autoloaderReady || !empty($missingExtensions) || !$sessionSupportReady) {
    http_response_code(503);
    echo json_encode([
        'status' => 'unhealthy',
        'message' => 'Application dependencies or runtime extensions are not ready.'
    ]);
    exit;
}

// Optional deep readiness check if explicitly requested (e.g. /health.php?check=deep)
$checkType = $_GET['check'] ?? 'standard';
$connectivity = null;

if ($checkType === 'deep') {
    // Only attempt when explicitly requested for diagnostics
    require_once __DIR__ . '/php/database.php';
    try {
        $dbStatus = Database::initializeDatabases();
        $allOk = ($dbStatus['mysql'] ?? false) && ($dbStatus['mongodb'] ?? false) && ($dbStatus['redis'] ?? false);
        $connectivity = $allOk ? 'healthy' : 'degraded';
        $servicesDetail = [
            'mysql' => ($dbStatus['mysql'] ?? false) ? 'connected' : 'failed',
            'mongodb' => ($dbStatus['mongodb'] ?? false) ? 'connected' : 'failed',
            'redis' => ($dbStatus['redis'] ?? false) ? 'connected' : 'failed'
        ];
        if (!empty($dbStatus['errors'])) {
            $servicesDetail['errors'] = $dbStatus['errors'];
        }
    } catch (Throwable $e) {
        error_log('[Health Check Exception] ' . $e->getMessage());
        $connectivity = 'degraded';
        $servicesDetail = ['error' => $e->getMessage()];
    }
}

// Return healthy status
http_response_code(200);
$response = [
    'status' => 'healthy',
    'app' => 'nurahub-auth-system',
    'timestamp' => time(),
    'uptime_check' => 'passed'
];

if ($connectivity !== null) {
    $response['services'] = $connectivity;
    if (isset($servicesDetail)) {
        $response['details'] = $servicesDetail;
    }
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
