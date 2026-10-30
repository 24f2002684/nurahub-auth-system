<?php
/**
 * Live Smoke Test for Render Deployment
 */
$baseUrl = 'https://nurahub-auth-system.onrender.com';
$cookieFile = tempnam(sys_get_temp_dir(), 'nura_cookie_');

function httpReq(string $method, string $url, array $headers = [], $data = null) {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
    }

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($raw, true) ?: $raw];
}

echo "=== Testing Live Render Deployment ===\n";

// 1. Auth check
$auth = httpReq('GET', "$baseUrl/php/auth_check.php");
echo "1. Auth check: HTTP {$auth['status']}\n";
$csrf = $auth['body']['data']['csrf_token'] ?? null;
echo "   CSRF: " . substr($csrf, 0, 10) . "...\n";

// Generate unique test user credentials
$uniqueSuffix = time() . '_' . rand(100, 999);
$testUsername = "live_user_{$uniqueSuffix}";
$testEmail = "live_{$uniqueSuffix}@example.com";
$testPassword = "LiveSecurePass123!";

// 2. Register new user
$reg = httpReq('POST', "$baseUrl/php/register.php", [
    'Content-Type: application/json',
    "X-CSRF-Token: $csrf"
], [
    'username' => $testUsername,
    'email' => $testEmail,
    'password' => $testPassword,
    'confirm_password' => $testPassword,
    'csrf_token' => $csrf
]);
echo "2. Register: HTTP {$reg['status']} - " . ($reg['body']['message'] ?? '') . "\n";
$regCsrf = $reg['body']['data']['csrf_token'] ?? $csrf;

// 3. Login
$login = httpReq('POST', "$baseUrl/php/login.php", [
    'Content-Type: application/json',
    "X-CSRF-Token: $regCsrf"
], [
    'email' => $testEmail,
    'password' => $testPassword,
    'csrf_token' => $regCsrf
]);
echo "3. Login: HTTP {$login['status']} - " . ($login['body']['message'] ?? '') . "\n";
$loginCsrf = $login['body']['data']['csrf_token'] ?? $regCsrf;

// 4. GET Profile
$profile = httpReq('GET', "$baseUrl/php/profile.php");
echo "4. Profile GET: HTTP {$profile['status']} - " . ($profile['body']['message'] ?? '') . "\n";
echo "   Current User: " . ($profile['body']['data']['account']['username'] ?? 'none') . "\n";
$profileCsrf = $profile['body']['data']['csrf_token'] ?? $loginCsrf;

// 5. POST Update Profile
$updatedBio = "Intern Candidate profile updated live at " . date('Y-m-d H:i:s');
$update = httpReq('POST', "$baseUrl/php/profile.php", [
    'Content-Type: application/json',
    "X-CSRF-Token: $profileCsrf"
], [
    'full_name' => 'Demo Candidate',
    'age' => 24,
    'bio' => $updatedBio,
    'interests' => ['Full-Stack', 'MongoDB', 'Docker', 'Redis', 'Aiven'],
    'csrf_token' => $profileCsrf
]);
echo "5. Profile POST (Update): HTTP {$update['status']} - " . ($update['body']['message'] ?? '') . "\n";
if (isset($update['body']['data']['profile'])) {
    echo "   Saved Bio: " . $update['body']['data']['profile']['bio'] . "\n";
    echo "   Interests: " . implode(', ', $update['body']['data']['profile']['interests']) . "\n";
}

@unlink($cookieFile);
echo "\n=== Live Verification Complete ===\n";
