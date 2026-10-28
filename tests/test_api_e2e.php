<?php
/**
 * NuraHub Auth & User Profile System
 * Automated End-to-End API and Database Integration Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/../php/database.php';

$baseUrl = 'http://127.0.0.1:8080';
$cookieJar = tempnam(sys_get_temp_dir(), 'nurahub_test_cookie_');
register_shutdown_function(function() use ($cookieJar) {
    if (file_exists($cookieJar)) {
        @unlink($cookieJar);
    }
});

$testsPassed = 0;
$testsFailed = 0;

function run_test(string $name, callable $fn): void {
    global $testsPassed, $testsFailed;
    echo "Running: [{$name}] ... ";
    try {
        $result = $fn();
        if ($result === true) {
            echo "\033[32mPASSED\033[0m\n";
            $testsPassed++;
        } else {
            echo "\033[31mFAILED\033[0m (" . (is_string($result) ? $result : 'Assertion failed') . ")\n";
            $testsFailed++;
        }
    } catch (Throwable $e) {
        echo "\033[31mERROR\033[0m (" . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . ")\n";
        $testsFailed++;
    }
}

function http_request(string $method, string $path, array $data = [], array $headers = [], ?string $customCookieJar = null): array {
    global $baseUrl, $cookieJar;
    $targetJar = $customCookieJar ?? $cookieJar;

    $url = $baseUrl . $path;
    $ch = curl_init();

    $headerLines = [
        'Accept: application/json'
    ];
    foreach ($headers as $k => $v) {
        $headerLines[] = "{$k}: {$v}";
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $targetJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $targetJar);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $json = json_encode($data);
        $headerLines[] = 'Content-Type: application/json; charset=utf-8';
        $headerLines[] = 'Content-Length: ' . strlen($json);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $jsonDecoded = json_decode((string) $rawResponse, true);

    return [
        'code' => $httpCode,
        'body' => $rawResponse,
        'json' => is_array($jsonDecoded) ? $jsonDecoded : null,
        'error' => $curlError
    ];
}

echo "====================================================================\n";
echo "NuraHub Authentication & Profile System - Test Suite Execution\n";
echo "====================================================================\n\n";

// Test 1: Verify all 3 databases
run_test("1. Verify MySQL, MongoDB, and Redis Connections", function() {
    $res = Database::initializeDatabases();
    if (!$res['mysql']) return "MySQL connection failed";
    if (!$res['mongodb']) return "MongoDB connection failed";
    if (!$res['redis']) return "Redis connection failed";
    return true;
});

// Test 2: CSRF Fetch
$activeCsrfToken = '';
run_test("2. Fetch CSRF token via auth_check.php", function() use (&$activeCsrfToken) {
    $res = http_request('GET', '/php/auth_check.php');
    if ($res['code'] !== 200) return "Expected 200, got {$res['code']}";
    if (empty($res['json']['data']['csrf_token'])) return "Missing csrf_token in response";
    $activeCsrfToken = $res['json']['data']['csrf_token'];
    return true;
});

// Test 3: CSRF Guard Rejection
run_test("3. State-changing request without CSRF token is rejected (HTTP 403)", function() {
    $res = http_request('POST', '/php/register.php', [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'Pass12345!'
    ]);
    if ($res['code'] !== 403) return "Expected 403 Forbidden, got {$res['code']}";
    return true;
});

// Test 4: Validation - Password Mismatch
run_test("4. Registration with mismatched passwords rejected (HTTP 422)", function() use (&$activeCsrfToken) {
    $res = http_request('POST', '/php/register.php', [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'Password123!',
        'confirm_password' => 'Different123!',
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 422) return "Expected 422, got {$res['code']}";
    if (empty($res['json']['errors']['confirm_password'])) return "Missing confirm_password error";
    return true;
});

// Test 5: Validation - Weak Password
run_test("5. Registration with weak password rejected (HTTP 422)", function() use (&$activeCsrfToken) {
    $res = http_request('POST', '/php/register.php', [
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'weak',
        'confirm_password' => 'weak',
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 422) return "Expected 422, got {$res['code']}";
    return true;
});

// Test 6: Registration Success & Dual-Database Population
$testUser = 'intern_' . bin2hex(random_bytes(4));
$testEmail = $testUser . '@example.com';
$testPassword = 'SecurePassword123!';
$registeredUserId = null;

run_test("6. Valid Registration creates MySQL user and MongoDB profile (HTTP 201)", function() use ($testUser, $testEmail, $testPassword, &$activeCsrfToken, &$registeredUserId) {
    $res = http_request('POST', '/php/register.php', [
        'username' => $testUser,
        'email' => $testEmail,
        'password' => $testPassword,
        'confirm_password' => $testPassword,
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 201) return "Expected 201 Created, got {$res['code']}: " . $res['body'];
    $registeredUserId = $res['json']['data']['user']['id'] ?? null;
    if (!$registeredUserId) return "Missing user ID in registration response";

    // Direct Database Assertions
    $pdo = Database::getMySQL();
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = :id');
    $stmt->execute([':id' => $registeredUserId]);
    $mysqlRow = $stmt->fetch();
    if (!$mysqlRow || $mysqlRow['username'] !== $testUser) return "MySQL record not found or mismatched";

    $collection = Database::getUserProfilesCollection();
    $mongoDoc = $collection->findOne(['user_id' => (int) $registeredUserId]);
    if (!$mongoDoc) return "MongoDB profile document was not created";

    return true;
});

// Test 7: Duplicate Username Conflict
run_test("7. Duplicate username registration rejected (HTTP 409)", function() use ($testUser, $testPassword, &$activeCsrfToken) {
    $res = http_request('POST', '/php/register.php', [
        'username' => $testUser,
        'email' => 'different_' . bin2hex(random_bytes(4)) . '@example.com',
        'password' => $testPassword,
        'confirm_password' => $testPassword,
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 409) return "Expected 409 Conflict, got {$res['code']}";
    return true;
});

// Test 8: Duplicate Email Conflict
run_test("8. Duplicate email registration rejected (HTTP 409)", function() use ($testEmail, $testPassword, &$activeCsrfToken) {
    $res = http_request('POST', '/php/register.php', [
        'username' => 'unique_' . bin2hex(random_bytes(4)),
        'email' => $testEmail,
        'password' => $testPassword,
        'confirm_password' => $testPassword,
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 409) return "Expected 409 Conflict, got {$res['code']}";
    return true;
});

// Test 9: Unauthenticated Profile Access Denied
run_test("9. Unauthenticated access to profile.php rejected (HTTP 401)", function() {
    $emptyCookieJar = tempnam(sys_get_temp_dir(), 'empty_jar_');
    $res = http_request('GET', '/php/profile.php', [], [], $emptyCookieJar);
    @unlink($emptyCookieJar);

    if ($res['code'] !== 401) return "Expected 401 Unauthorized, got {$res['code']}";
    return true;
});

// Test 10: Invalid Password Login
run_test("10. Login with invalid password rejected with generic message (HTTP 401)", function() use ($testEmail, &$activeCsrfToken) {
    $res = http_request('POST', '/php/login.php', [
        'email' => $testEmail,
        'password' => 'WrongPassword123!',
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 401) return "Expected 401, got {$res['code']}";
    if ($res['json']['message'] !== 'Invalid email or password.') return "Expected generic message";
    return true;
});

// Test 11: Unknown Email Login
run_test("11. Login with unknown email produces identical generic error (HTTP 401)", function() use (&$activeCsrfToken) {
    $res = http_request('POST', '/php/login.php', [
        'email' => 'does_not_exist_' . bin2hex(random_bytes(4)) . '@example.com',
        'password' => 'AnyPassword123!',
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 401) return "Expected 401, got {$res['code']}";
    if ($res['json']['message'] !== 'Invalid email or password.') return "Expected generic message";
    return true;
});

// Test 12: Successful Login & Redis Session Creation
$newCsrfToken = '';
run_test("12. Valid Login succeeds and regenerates session (HTTP 200)", function() use ($testEmail, $testPassword, &$activeCsrfToken, &$newCsrfToken) {
    $res = http_request('POST', '/php/login.php', [
        'email' => $testEmail,
        'password' => $testPassword,
        'csrf_token' => $activeCsrfToken
    ], ['X-CSRF-Token' => $activeCsrfToken]);

    if ($res['code'] !== 200) return "Expected 200, got {$res['code']}: " . $res['body'];
    if (empty($res['json']['data']['user']['id'])) return "Missing user ID in login response";
    if (isset($res['json']['data']['user']['password_hash'])) return "SECURITY LEAK: password_hash exposed in response!";
    
    $newCsrfToken = $res['json']['data']['csrf_token'] ?? '';
    return true;
});

// Test 13: Authenticated Profile Fetch
run_test("13. Authenticated user can fetch their profile (HTTP 200)", function() use ($testUser, $testEmail) {
    $res = http_request('GET', '/php/profile.php');
    if ($res['code'] !== 200) return "Expected 200, got {$res['code']}: " . $res['body'];
    
    $userData = $res['json']['data']['user'] ?? [];
    if ($userData['username'] !== $testUser) return "Username mismatch";
    if ($userData['email'] !== $testEmail) return "Email mismatch";

    $profileData = $res['json']['data']['profile'] ?? [];
    if (!isset($profileData['full_name'])) return "Missing profile full_name field";
    return true;
});

// Test 14: Profile Update in MongoDB
run_test("14. Authenticated user can update profile in MongoDB (HTTP 200)", function() use (&$newCsrfToken, $registeredUserId) {
    $updatePayload = [
        'full_name' => 'Suhail Akthar',
        'age' => 24,
        'bio' => 'Full-stack software development intern and cloud architecture enthusiast.',
        'interests' => ['PHP', 'MySQL', 'MongoDB', 'Redis', 'Docker'],
        'csrf_token' => $newCsrfToken
    ];

    $res = http_request('POST', '/php/profile.php', $updatePayload, ['X-CSRF-Token' => $newCsrfToken]);
    if ($res['code'] !== 200) return "Expected 200, got {$res['code']}: " . $res['body'];

    $profile = $res['json']['data']['profile'] ?? [];
    if ($profile['full_name'] !== 'Suhail Akthar') return "Full name not updated in response";
    if ($profile['age'] !== 24) return "Age not updated in response";

    // Direct MongoDB verification
    $collection = Database::getUserProfilesCollection();
    $doc = $collection->findOne(['user_id' => (int) $registeredUserId]);
    if (!$doc || $doc['full_name'] !== 'Suhail Akthar' || (int)$doc['age'] !== 24) {
        return "MongoDB document was not updated in database";
    }

    if (isset($res['json']['data']['csrf_token'])) {
        $newCsrfToken = $res['json']['data']['csrf_token'];
    }

    return true;
});

// Test 15: Profile Update Validation (Invalid Age)
run_test("15. Profile update with invalid age rejected (HTTP 422)", function() use (&$newCsrfToken) {
    $res = http_request('POST', '/php/profile.php', [
        'full_name' => 'Valid Name',
        'age' => 999, // Exceeds max 130
        'bio' => 'Valid bio',
        'interests' => ['Valid'],
        'csrf_token' => $newCsrfToken
    ], ['X-CSRF-Token' => $newCsrfToken]);

    if ($res['code'] !== 422) return "Expected 422, got {$res['code']}";
    return true;
});

// Test 16: Secure Logout
run_test("16. Logout invalidates server session and destroys cookie (HTTP 200)", function() use (&$newCsrfToken) {
    $res = http_request('POST', '/php/logout.php', [
        'csrf_token' => $newCsrfToken
    ], ['X-CSRF-Token' => $newCsrfToken]);

    if ($res['code'] !== 200) return "Expected 200, got {$res['code']}";
    return true;
});

// Test 17: Post-Logout Profile Access Denied
run_test("17. Access to profile.php rejected after logout (HTTP 401)", function() {
    $res = http_request('GET', '/php/profile.php');
    if ($res['code'] !== 401) return "Expected 401 Unauthorized after logout, got {$res['code']}";
    return true;
});

// Test 18: Brute-Force Rate Limiting Lockout
run_test("18. Repeated failed login attempts trigger HTTP 429 lockout via Redis", function() {
    $authCheck = http_request('GET', '/php/auth_check.php');
    $token = $authCheck['json']['data']['csrf_token'] ?? '';
    $attackEmail = 'target_' . bin2hex(random_bytes(4)) . '@example.com';

    $hitRateLimit = false;
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $res = http_request('POST', '/php/login.php', [
            'email' => $attackEmail,
            'password' => 'WrongPass' . $attempt,
            'csrf_token' => $token
        ], ['X-CSRF-Token' => $token]);

        if ($res['code'] === 429) {
            $hitRateLimit = true;
            break;
        }
    }

    if (!$hitRateLimit) return "Failed to trigger HTTP 429 rate limit after repeated invalid attempts";
    return true;
});

echo "\n====================================================================\n";
echo "Test Execution Summary: {$testsPassed} Passed, {$testsFailed} Failed.\n";
echo "====================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
