<?php
/**
 * NuraHub Auth & User Profile System
 * Authenticated User Profile Endpoint
 * 
 * GET: Retrieve authenticated user's MySQL account details & MongoDB profile data.
 * POST / PUT: Update permitted profile fields in MongoDB (full_name, age, bio, interests).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// Verify session and extract user ID solely from the server session
$userId = require_authenticated_user();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Allow method override via _method parameter if client sends POST with _method=PUT
if ($method === 'POST' && isset($_POST['_method']) && strtoupper($_POST['_method']) === 'PUT') {
    $method = 'PUT';
}

if ($method === 'GET') {
    // -------------------------------------------------------------------------
    // 1. Fetch Core Account Info from MySQL
    // -------------------------------------------------------------------------
    $account = null;
    try {
        $pdo = Database::getMySQL();
        $stmt = $pdo->prepare('SELECT id, username, email, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $account = $stmt->fetch();

        if (!$account) {
            send_json_error('Account not found.', [], 404);
        }
    } catch (Throwable $e) {
        error_log('[Profile MySQL Error] ' . $e->getMessage());
        send_json_error('Database error while retrieving account details.', [], 500);
    }

    // -------------------------------------------------------------------------
    // 2. Fetch Extended Profile Data from MongoDB
    // -------------------------------------------------------------------------
    $profileData = [
        'full_name' => '',
        'age' => null,
        'bio' => '',
        'interests' => [],
        'updated_at' => null
    ];

    try {
        $collection = Database::getUserProfilesCollection();
        $doc = $collection->findOne(['user_id' => $userId]);

        if ($doc) {
            $profileData['full_name'] = (string) ($doc['full_name'] ?? '');
            $profileData['age'] = isset($doc['age']) && is_numeric($doc['age']) ? (int) $doc['age'] : null;
            $profileData['bio'] = (string) ($doc['bio'] ?? '');

            // Convert array or Traversable BSONArray to plain PHP array
            $rawInterests = $doc['interests'] ?? [];
            if (is_array($rawInterests)) {
                $profileData['interests'] = array_values($rawInterests);
            } elseif ($rawInterests instanceof Traversable) {
                $profileData['interests'] = iterator_to_array($rawInterests);
            } else {
                $profileData['interests'] = [];
            }

            if (isset($doc['updated_at']) && $doc['updated_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $profileData['updated_at'] = $doc['updated_at']->toDateTime()->format('Y-m-d H:i:s');
            }
        } else {
            // Auto-heal missing profile document
            $now = new MongoDB\BSON\UTCDateTime();
            $collection->insertOne([
                'user_id' => $userId,
                'full_name' => '',
                'age' => null,
                'bio' => '',
                'interests' => [],
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }
    } catch (Throwable $e) {
        error_log('[Profile MongoDB Error] ' . $e->getMessage());
        // Do not crash the entire endpoint if MongoDB is momentarily lagging; return default structure
    }

    send_json_success('Profile retrieved successfully.', [
        'user' => [
            'id' => (int) $account['id'],
            'username' => $account['username'],
            'email' => $account['email'],
            'created_at' => $account['created_at']
        ],
        'profile' => $profileData,
        'csrf_token' => get_csrf_token()
    ]);

} elseif ($method === 'POST' || $method === 'PUT') {
    // -------------------------------------------------------------------------
    // Profile Update Handler
    // -------------------------------------------------------------------------
    require_csrf_protection();

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

    $errors = [];

    // Validate full_name
    $fullName = trim((string) ($input['full_name'] ?? ''));
    if (strlen($fullName) > 100) {
        $errors['full_name'] = 'Full name cannot exceed 100 characters.';
    }

    // Validate age
    $rawAge = $input['age'] ?? null;
    $age = null;
    if ($rawAge !== null && $rawAge !== '') {
        if (!is_numeric($rawAge) || (int) $rawAge != $rawAge) {
            $errors['age'] = 'Age must be a valid whole number.';
        } else {
            $ageInt = (int) $rawAge;
            if ($ageInt < 1 || $ageInt > 130) {
                $errors['age'] = 'Age must be between 1 and 130.';
            } else {
                $age = $ageInt;
            }
        }
    }

    // Validate bio
    $bio = trim((string) ($input['bio'] ?? ''));
    if (strlen($bio) > 1000) {
        $errors['bio'] = 'Bio cannot exceed 1000 characters.';
    }

    // Validate & normalize interests (can be array or comma-separated string)
    $rawInterests = $input['interests'] ?? [];
    $interests = [];
    if (is_string($rawInterests)) {
        $split = explode(',', $rawInterests);
        foreach ($split as $item) {
            $cleaned = trim($item);
            if ($cleaned !== '') {
                $interests[] = $cleaned;
            }
        }
    } elseif (is_array($rawInterests)) {
        foreach ($rawInterests as $item) {
            if (is_string($item) || is_numeric($item)) {
                $cleaned = trim((string) $item);
                if ($cleaned !== '') {
                    $interests[] = $cleaned;
                }
            }
        }
    }

    // Deduplicate and enforce reasonable limits
    $interests = array_values(array_unique($interests));
    if (count($interests) > 25) {
        $errors['interests'] = 'You can specify at most 25 interests.';
    }
    foreach ($interests as $tag) {
        if (strlen($tag) > 40) {
            $errors['interests'] = 'Each interest tag cannot exceed 40 characters.';
            break;
        }
    }

    if (!empty($errors)) {
        send_json_error('Validation error. Please verify the submitted profile data.', $errors, 422);
    }

    // Update MongoDB document
    try {
        $collection = Database::getUserProfilesCollection();
        $now = new MongoDB\BSON\UTCDateTime();

        $updatePayload = [
            'full_name' => $fullName,
            'age' => $age,
            'bio' => $bio,
            'interests' => $interests,
            'updated_at' => $now
        ];

        $collection->updateOne(
            ['user_id' => $userId],
            [
                '$set' => $updatePayload,
                '$setOnInsert' => [
                    'created_at' => $now
                ]
            ],
            ['upsert' => true]
        );

        send_json_success('Profile updated successfully.', [
            'profile' => [
                'full_name' => $fullName,
                'age' => $age,
                'bio' => $bio,
                'interests' => $interests,
                'updated_at' => (new DateTime())->format('Y-m-d H:i:s')
            ],
            'csrf_token' => get_csrf_token()
        ]);

    } catch (Exception $e) {
        error_log('[Profile Update MongoDB Error] ' . $e->getMessage());
        send_json_error('Unable to save profile changes. Please try again later.', [], 500);
    }

} else {
    send_json_error('Method Not Allowed', [], 405);
}
