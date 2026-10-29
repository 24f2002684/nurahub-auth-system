<?php
/**
 * NuraHub Auth & User Profile System
 * Central Database Access Layer for MySQL (Aiven/Local), MongoDB (Atlas/Local), and Redis (Upstash/Local)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

use MongoDB\Client as MongoClient;
use MongoDB\Collection as MongoCollection;
use MongoDB\Database as MongoDatabase;

class Database {
    private static ?PDO $mysqlPdo = null;
    private static ?MongoClient $mongoClient = null;
    private static ?object $redisClient = null;
    private static bool $mongoIndexEnsured = false;

    /**
     * Get singleton MySQL PDO connection with TLS/SSL support for Aiven & prepared statements
     */
    public static function getMySQL(): PDO {
        if (self::$mysqlPdo !== null) {
            return self::$mysqlPdo;
        }

        $host = (string) env('MYSQL_HOST', '127.0.0.1');
        $port = (int) env('MYSQL_PORT', 3306);
        $dbName = (string) env('MYSQL_DATABASE', 'nurahub_auth');
        $user = (string) env('MYSQL_USER', 'nurahub_user');
        $password = (string) env('MYSQL_PASSWORD', '');
        $charset = (string) env('MYSQL_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci"
        ];

        // ---------------------------------------------------------------------
        // Aiven / Remote MySQL TLS Configuration
        // ---------------------------------------------------------------------
        $caPath = null;
        $envCa = env('MYSQL_SSL_CA', '');
        $envCaContent = env('MYSQL_SSL_CA_CONTENT', '');

        if (!empty($envCa) && file_exists((string) $envCa)) {
            $caPath = (string) $envCa;
        } elseif (!empty($envCaContent)) {
            // Write certificate content to secure temp file if provided via environment
            $tempCa = sys_get_temp_dir() . '/aiven-ca.pem';
            if (!file_exists($tempCa) || file_get_contents($tempCa) !== $envCaContent) {
                file_put_contents($tempCa, $envCaContent);
            }
            $caPath = $tempCa;
        } else {
            // Check default certs/aiven-ca.pem in project root
            $defaultCa = dirname(__DIR__) . '/certs/aiven-ca.pem';
            if (file_exists($defaultCa) && (str_contains($host, 'aivencloud.com') || env('MYSQL_SSL_MODE') === 'REQUIRED')) {
                $caPath = $defaultCa;
            }
        }

        if ($caPath !== null) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        try {
            self::$mysqlPdo = new PDO($dsn, $user, $password, $options);
            return self::$mysqlPdo;
        } catch (PDOException $e) {
            error_log('[MySQL Connection Error] ' . $e->getMessage());
            throw new RuntimeException('Database service temporarily unavailable.');
        }
    }

    /**
     * Get singleton MongoDB Client instance with support for Atlas (mongodb+srv)
     */
    public static function getMongoClient(): MongoClient {
        if (self::$mongoClient !== null) {
            return self::$mongoClient;
        }

        // Support both MONGODB_URI and MONGO_URI
        $uri = env('MONGODB_URI', env('MONGO_URI', ''));

        if (empty($uri)) {
            $host = env('MONGO_HOST', '127.0.0.1');
            $port = env('MONGO_PORT', '27017');
            $user = env('MONGO_USER', '');
            $password = env('MONGO_PASSWORD', '');
            $authSource = env('MONGO_AUTH_SOURCE', 'admin');

            $authPart = '';
            if (!empty($user) && !empty($password)) {
                $authPart = rawurlencode($user) . ':' . rawurlencode($password) . '@';
            }

            $optionsPart = '';
            if (!empty($authPart) && !empty($authSource)) {
                $optionsPart = '?authSource=' . rawurlencode($authSource);
            }

            $uri = "mongodb://{$authPart}{$host}:{$port}/{$optionsPart}";
        }

        try {
            self::$mongoClient = new MongoClient($uri, [], [
                'serverSelectionTimeoutMS' => 5000,
                'typeMap' => [
                    'root' => 'array',
                    'document' => 'array',
                    'array' => 'array'
                ]
            ]);
            return self::$mongoClient;
        } catch (Exception $e) {
            error_log('[MongoDB Connection Error] ' . $e->getMessage());
            throw new RuntimeException('Profile storage service temporarily unavailable.');
        }
    }

    /**
     * Get MongoDB Database instance
     */
    public static function getMongoDatabase(): MongoDatabase {
        // Support both MONGODB_DATABASE and MONGO_DATABASE
        $dbName = (string) env('MONGODB_DATABASE', env('MONGO_DATABASE', 'nurahub_profiles'));
        return self::getMongoClient()->selectDatabase($dbName);
    }

    /**
     * Get user_profiles MongoDB Collection and ensure unique index on user_id
     */
    public static function getUserProfilesCollection(): MongoCollection {
        $db = self::getMongoDatabase();
        $collection = $db->selectCollection('user_profiles');

        if (!self::$mongoIndexEnsured) {
            try {
                // Ensure unique index on user_id to prevent duplicates
                $collection->createIndex(
                    ['user_id' => 1],
                    ['unique' => true, 'name' => 'idx_unique_user_id']
                );
                self::$mongoIndexEnsured = true;
            } catch (Exception $e) {
                error_log('[MongoDB Index Notice] ' . $e->getMessage());
            }
        }

        return $collection;
    }

    /**
     * Get unified Redis client instance (supports Upstash TLS via Predis & local Redis via PhpRedis)
     */
    public static function getRedis(): ?object {
        if (self::$redisClient !== null) {
            return self::$redisClient;
        }

        $host = (string) env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $password = (string) env('REDIS_PASSWORD', '');
        $timeout = (float) env('REDIS_TIMEOUT', 3.0);
        $redisUrl = (string) env('REDIS_URL', '');
        $isTls = filter_var(env('REDIS_TLS', false), FILTER_VALIDATE_BOOLEAN)
            || str_contains($host, 'upstash.io')
            || str_starts_with($redisUrl, 'rediss://');

        // If TLS (Upstash) or Predis is preferred:
        if ($isTls && class_exists('Predis\Client')) {
            try {
                $predisParams = [
                    'scheme' => 'tls',
                    'host' => $host,
                    'port' => $port,
                    'timeout' => $timeout,
                    'read_write_timeout' => $timeout,
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'SNI_enabled' => true
                    ]
                ];
                if (!empty($password)) {
                    $predisParams['password'] = $password;
                }
                if (!empty($redisUrl)) {
                    $parsed = parse_url($redisUrl);
                    if ($parsed) {
                        $predisParams['host'] = $parsed['host'] ?? $host;
                        $predisParams['port'] = $parsed['port'] ?? $port;
                        if (!empty($parsed['pass'])) {
                            $predisParams['password'] = $parsed['pass'];
                        }
                    }
                }
                $client = new Predis\Client($predisParams);
                $client->connect();
                self::$redisClient = $client;
                return self::$redisClient;
            } catch (Exception $e) {
                error_log('[Redis Predis TLS Error] ' . $e->getMessage());
                return null;
            }
        }

        // Standard local PhpRedis client
        if (extension_loaded('redis')) {
            try {
                $redis = new Redis();
                $scheme = $isTls ? 'tls://' : '';
                $connected = $redis->connect($scheme . $host, $port, $timeout);
                if (!$connected) {
                    error_log("[Redis Connection Error] Could not connect to {$host}:{$port}");
                    return null;
                }

                if (!empty($password)) {
                    $redis->auth($password);
                }

                self::$redisClient = $redis;
                return self::$redisClient;
            } catch (Exception $e) {
                error_log('[Redis Connection Exception] ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Execute full schema verification and table/index creation
     */
    public static function initializeDatabases(): array {
        $results = [
            'mysql' => false,
            'mongodb' => false,
            'redis' => false,
            'errors' => []
        ];

        // 1. Initialize MySQL table
        try {
            $pdo = self::getMySQL();
            $sql = "CREATE TABLE IF NOT EXISTS `users` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `username` VARCHAR(50) NOT NULL,
                `email` VARCHAR(191) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_users_username` (`username`),
                UNIQUE KEY `uk_users_email` (`email`),
                INDEX `idx_users_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $pdo->exec($sql);
            $results['mysql'] = true;
        } catch (Exception $e) {
            $results['errors'][] = 'MySQL: ' . $e->getMessage();
        }

        // 2. Initialize MongoDB collection & index
        try {
            $collection = self::getUserProfilesCollection();
            self::getMongoDatabase()->command(['ping' => 1]);
            $results['mongodb'] = true;
        } catch (Exception $e) {
            $results['errors'][] = 'MongoDB: ' . $e->getMessage();
        }

        // 3. Verify Redis connectivity
        try {
            $redis = self::getRedis();
            if ($redis !== null) {
                $ping = $redis->ping();
                if ($ping) {
                    $results['redis'] = true;
                } else {
                    $results['errors'][] = 'Redis: Unable to ping Redis server.';
                }
            } else {
                $results['errors'][] = 'Redis: Client not initialized.';
            }
        } catch (Exception $e) {
            $results['errors'][] = 'Redis: ' . $e->getMessage();
        }

        return $results;
    }
}
