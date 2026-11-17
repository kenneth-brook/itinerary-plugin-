<?php
declare(strict_types=1);

function trip_visitor_start(): void
{
    session_name('trip_forge_visitor');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => rtrim(eventforge_public_path('itinerary'), '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (empty($_SESSION['trip_csrf'])) {
        $_SESSION['trip_csrf'] = bin2hex(random_bytes(32));
    }
}

function trip_visitor_id(): int
{
    return (int) ($_SESSION['trip_user_id'] ?? 0);
}

function trip_csrf_valid($token): bool
{
    return is_string($token) && hash_equals($_SESSION['trip_csrf'], $token);
}

function trip_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function trip_authenticate(mysqli $db, string $mode, string $email, string $password): void
{
    $email = strtolower(trim($email));
    // Rate-limit both IP and email buckets so changing one does not bypass the limit.
    $buckets = [hash('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
        hash('sha256', 'email:' . $email)];
    foreach ($buckets as $bucket) {
        itinerary_query($db, "INSERT INTO trip_forge_login_attempts (bucket, attempts, started_at)
            VALUES ('{$bucket}', 1, NOW()) ON DUPLICATE KEY UPDATE
            attempts = IF(started_at < NOW() - INTERVAL 15 MINUTE, 1, attempts + 1),
            started_at = IF(started_at < NOW() - INTERVAL 15 MINUTE, NOW(), started_at)");
        $count = itinerary_query($db, "SELECT attempts FROM trip_forge_login_attempts WHERE bucket = '{$bucket}'")->fetch_row()[0];
        if ((int) $count > 15) {
            throw new InvalidArgumentException('Too many attempts. Please try again in 15 minutes.');
        }
    }
    itinerary_query($db, 'DELETE FROM trip_forge_login_attempts WHERE started_at < NOW() - INTERVAL 1 DAY LIMIT 100');
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 72) {
        throw new InvalidArgumentException('Check your email and password.');
    }
    if ($mode === 'register') {
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('Choose a password containing 12 to 72 bytes.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO trip_forge_users (email, password_hash) VALUES (?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare account creation: ' . $db->error);
        }
        $stmt->bind_param('ss', $email, $hash);
        if (!$stmt->execute()) {
            if ($stmt->errno === 1062) {
                throw new InvalidArgumentException('Could not create this account. If you already registered, sign in.');
            }
            throw new RuntimeException('Account creation failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
    } else {
        $stmt = $db->prepare('SELECT id, password_hash FROM trip_forge_users WHERE email=?');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare sign-in: ' . $db->error);
        }
        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not read account: ' . $stmt->error);
        }
        $stmt->bind_result($id, $hash);
        $found = $stmt->fetch();
        $stmt->close();
        // Verify a fixed hash for unknown accounts as well.
        $hash = $found ? $hash : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || !$found) {
            throw new InvalidArgumentException('Email or password was not recognized.');
        }
    }
    session_regenerate_id(true);
    $_SESSION['trip_user_id'] = (int) $id;
    $_SESSION['trip_csrf'] = bin2hex(random_bytes(32));
}
