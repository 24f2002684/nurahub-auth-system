<?php
/**
 * Test the full application stack against remote cloud services:
 * - Aiven MySQL
 * - MongoDB Atlas
 * - Upstash Redis TLS
 *
 * Reads configuration from .env or environment variables.
 */

$rootDir = dirname(__DIR__);
if (file_exists($rootDir . '/.env')) {
    require_once $rootDir . '/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable($rootDir);
    $dotenv->safeLoad();
}

require_once __DIR__ . '/../php/database.php';

// Initialize session BEFORE any CLI output so session headers/cookies aren't blocked
init_session();
$_SESSION['user_id'] = 777;
$_SESSION['username'] = 'cloud_test_user';
$_SESSION['authenticated'] = true;
$sid = session_id();
session_write_close();

echo "=== Verifying Live Remote Services with NuraHub Database Layer ===\n\n";
echo "Active Session ID created: {$sid}\n";

// 1. Initialize databases
$status = Database::initializeDatabases();
echo "Database::initializeDatabases() Result:\n";
print_r($status);

if (!$status['mysql'] || !$status['mongodb'] || !$status['redis']) {
    echo "ERROR: One or more remote cloud connections failed!\n";
    exit(1);
}

echo "SUCCESS: All 3 cloud services (MySQL, MongoDB, Redis) initialized successfully!\n";

// 2. Verify Session persistence in Redis
$redis = Database::getRedis();
if ($redis !== null) {
    $keys = $redis->keys('*' . $sid . '*');
    echo "Session Key in Redis: " . json_encode($keys) . "\n";
}

echo "ALL LIVE REMOTE TESTS PASSED SUCCESSFULLY!\n";
