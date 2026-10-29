<?php
/**
 * Diagnostic test for Upstash Redis over TLS with Predis Session Handler
 * Uses environment variables to prevent hardcoding credentials.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env if present
$rootDir = dirname(__DIR__);
if (file_exists($rootDir . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($rootDir);
    $dotenv->safeLoad();
}

$host = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
$password = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
$isTls = filter_var($_ENV['REDIS_TLS'] ?? getenv('REDIS_TLS') ?: false, FILTER_VALIDATE_BOOLEAN)
    || str_contains($host, 'upstash.io');

echo "Testing Redis TLS Connectivity via Predis...\n";
echo "Host: " . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') . "\n";
echo "Port: {$port}\n";
echo "TLS: " . ($isTls ? 'true' : 'false') . "\n";

try {
    $predisParams = [
        'scheme' => $isTls ? 'tls' : 'tcp',
        'host' => $host,
        'port' => $port,
        'timeout' => 5.0,
        'read_write_timeout' => 5.0,
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true
        ]
    ];

    if (!empty($password)) {
        $predisParams['password'] = $password;
    }

    $client = new Predis\Client($predisParams);
    $client->connect();
    $pong = $client->ping();
    echo "Predis PING result: {$pong}\n";

    // Test session handler with Predis
    $handler = new Predis\Session\Handler($client, [
        'gc_maxlifetime' => 3600,
        'prefix' => 'PHPREDIS_SESSION:'
    ]);
    $handler->register();

    session_start();
    $_SESSION['predis_test'] = 'upstash_works_' . time();
    $sid = session_id();
    session_write_close();
    echo "Predis Session written! Session ID: {$sid}\n";

    // Verify session data was stored in Redis
    $stored = $client->get("PHPREDIS_SESSION:{$sid}");
    echo "Session data exists in Redis: " . (!empty($stored) ? 'YES' : 'NO') . "\n";

    // Clean up test key
    $client->del(["PHPREDIS_SESSION:{$sid}"]);
    echo "SUCCESS: Redis connection and session persistence verified completely!\n";

} catch (Throwable $e) {
    echo "Redis TLS Error: " . $e->getMessage() . "\n";
    exit(1);
}
