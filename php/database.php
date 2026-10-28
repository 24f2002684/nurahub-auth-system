<?php
/**
 * NuraHub Auth & User Profile System
 * Central Database Access Layer for MySQL, MongoDB, and Redis
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

use MongoDB\Client as MongoClient;
use MongoDB\Collection as MongoCollection;
use MongoDB\Database as MongoDatabase;

class Database {
    private static ?PDO $mysqlPdo = null;
    private static ?MongoClient $mongoClient = null;
    private static ?Redis $redisClient = null;
    private static bool $mongoIndexEnsured = false;

    /**
     * Get singleton MySQL PDO connection with exception handling & prepared statements
     */
    public static function getMySQL(): PDO {
        if (self::$mysqlPdo !== null) {
            return self::$mysqlPdo;
        }

        $host = env('MYSQL_HOST', '127.0.0.1');
        $port = env('MYSQL_PORT', '3306');
        $dbName = env('MYSQL_DATABASE', 'nurahub_auth');
        $user = env('MYSQL_USER', 'nurahub_user');
        $password = env('MYSQL_PASSWORD', '');
        $charset = env('MYSQL_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci"
        ];

        try {
            self::$mysqlPdo = new PDO($dsn, $user, $password, $options);
            return self::$mysqlPdo;
        } catch (PDOException $e) {
            error_log('[MySQL Connection Error] ' . $e->getMessage());
            throw new RuntimeException('Database service temporarily unavailable.');
        }
    }

    /**
     * Get singleton MongoDB Client instance
     */
    public static function getMongoClient(): MongoClient {
        if (self::$mongoClient !== null) {
            return self::$mongoClient;
        }

        $customUri = env('MONGO_URI', '');
        if (!empty($customUri)) {
            $uri = $customUri;
        } else {
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
        $dbName = env('MONGO_DATABASE', 'nurahub_profiles');
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
                // Ensure a unique index on user_id so no duplicate profiles exist
                $collection->createIndex(
                    ['user_id' => 1],
                    ['unique' => true, 'name' => 'idx_unique_user_id']
                );
                self::$mongoIndexEnsured = true;
            } catch (Exception $e) {
                // If index already exists or creation errors, log warning
                error_log('[MongoDB Index Notice] ' . $e->getMessage());
            }
        }

        return $collection;
    }

    /**
     * Get native Redis connection instance
     */
    public static function getRedis(): ?Redis {
        if (!extension_loaded('redis')) {
            return null;
        }

        if (self::$redisClient !== null) {
            return self::$redisClient;
        }

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD', '');
        $timeout = (float) env('REDIS_TIMEOUT', 2.5);

        try {
            $redis = new Redis();
            $connected = $redis->connect($host, $port, $timeout);
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
            // Ping database to verify connectivity
            self::getMongoDatabase()->command(['ping' => 1]);
            $results['mongodb'] = true;
        } catch (Exception $e) {
            $results['errors'][] = 'MongoDB: ' . $e->getMessage();
        }

        // 3. Verify Redis connectivity
        try {
            $redis = self::getRedis();
            if ($redis && $redis->ping()) {
                $results['redis'] = true;
            } else {
                $results['errors'][] = 'Redis: Unable to ping Redis server.';
            }
        } catch (Exception $e) {
            $results['errors'][] = 'Redis: ' . $e->getMessage();
        }

        return $results;
    }
}
