<?php

declare(strict_types=1);

function email_verification_enabled(array $config): bool
{
    return ($config['email_verification']['enabled'] ?? false) === true;
}

function email_verification_method(array $config): string
{
    $method = strtolower(trim((string)($config['email_verification']['method'] ?? 'code')));
    return in_array($method, ['code', 'link'], true) ? $method : 'code';
}

function email_verification_ttl_minutes(array $config): int
{
    return max(1, min(60, (int)($config['email_verification']['ttl_minutes'] ?? 3)));
}

function email_verification_resend_cooldown_seconds(array $config): int
{
    return max(0, min(600, (int)($config['email_verification']['resend_cooldown_seconds'] ?? 30)));
}

function email_verification_ready(array $config): bool
{
    if (!email_verification_enabled($config)) {
        return false;
    }

    return smtp_is_ready($config);
}

function email_verification_requires_login_gate(array $config): bool
{
    return ($config['email_verification']['require_for_login'] ?? false) === true;
}

function email_verification_user_needs_verification(array $config, array $user): bool
{
    if (!email_verification_enabled($config) || !email_verification_requires_login_gate($config)) {
        return false;
    }

    return trim((string)($user['email_verified_at'] ?? '')) === '';
}

function email_verification_issue(PDO $pdo, array $config, array $user, string $verifyLinkBase): array
{
    if (!email_verification_ready($config)) {
        return ['ok' => false, 'reason' => 'Email verification mail is not configured'];
    }

    $method = email_verification_method($config);
    $ttlMinutes = email_verification_ttl_minutes($config);

    $clearPending = $pdo->prepare('UPDATE email_verification_challenges SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');
    $clearPending->execute([(int)$user['id']]);

    $code = null;
    $token = null;
    $codeHash = null;
    $tokenHash = null;

    if ($method === 'code') {
        $codeLength = max(4, min(10, (int)($config['email_verification']['code_length'] ?? 6)));
        $code = '';
        for ($i = 0; $i < $codeLength; $i++) {
            $code .= (string)random_int(0, 9);
        }

        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if ($codeHash === false) {
            return ['ok' => false, 'reason' => 'Unable to generate verification code'];
        }
    } else {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
    }

    $insert = $pdo->prepare(
        'INSERT INTO email_verification_challenges (user_id, email, channel, code_hash, token_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
    );
    $insert->execute([
        (int)$user['id'],
        (string)$user['email'],
        $method,
        $codeHash,
        $tokenHash,
        $ttlMinutes,
    ]);

    if ($method === 'code') {
        $sent = send_smtp_mail(
            $config,
            (string)$user['email'],
            (string)$user['full_name'],
            'Verify your email address',
            '<p>Your verification code is <strong>' . $code . '</strong>.</p><p>This code expires in ' . $ttlMinutes . ' minutes.</p>'
        );

        if (!$sent) {
            $smtpError = trim(smtp_get_last_error());
            $reason = $smtpError !== '' ? 'Verification email could not be sent (' . $smtpError . ')' : 'Verification email could not be sent';
            return ['ok' => false, 'reason' => $reason];
        }

        return ['ok' => true, 'method' => 'code'];
    }

    $verificationUrl = $verifyLinkBase . '&token=' . rawurlencode((string)$token);
    $sent = send_smtp_mail(
        $config,
        (string)$user['email'],
        (string)$user['full_name'],
        'Verify your email address',
        '<p>Click the link below to verify your email address.</p><p><a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '">Verify Email</a></p><p>This link expires in ' . $ttlMinutes . ' minutes.</p>'
    );

    if (!$sent) {
        $smtpError = trim(smtp_get_last_error());
        $reason = $smtpError !== '' ? 'Verification email could not be sent (' . $smtpError . ')' : 'Verification email could not be sent';
        return ['ok' => false, 'reason' => $reason];
    }

    return ['ok' => true, 'method' => 'link'];
}

function email_verification_latest_pending_challenge(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, created_at, expires_at
         FROM email_verification_challenges
         WHERE user_id = ? AND consumed_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $createdTs = strtotime((string)$row['created_at']);
    $expiresTs = strtotime((string)$row['expires_at']);

    return [
        'id' => (int)$row['id'],
        'created_at' => (string)$row['created_at'],
        'expires_at' => (string)$row['expires_at'],
        'created_at_ts' => $createdTs !== false ? $createdTs : 0,
        'expires_at_ts' => $expiresTs !== false ? $expiresTs : 0,
    ];
}

function email_verification_mark_verified(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?');
    $stmt->execute([$userId]);
}

function email_verification_verify_code(PDO $pdo, int $userId, string $code): bool
{
    $stmt = $pdo->prepare(
        "SELECT id, code_hash
         FROM email_verification_challenges
         WHERE user_id = ?
           AND channel = 'code'
           AND consumed_at IS NULL
           AND expires_at >= NOW()
         ORDER BY id DESC
         LIMIT 5"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        if (password_verify($code, (string)$row['code_hash'])) {
            $consume = $pdo->prepare('UPDATE email_verification_challenges SET consumed_at = NOW() WHERE id = ?');
            $consume->execute([(int)$row['id']]);
            email_verification_mark_verified($pdo, $userId);
            return true;
        }
    }

    return false;
}

function email_verification_verify_token(PDO $pdo, string $token): ?int
{
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        "SELECT id, user_id
         FROM email_verification_challenges
         WHERE token_hash = ?
           AND channel = 'link'
           AND consumed_at IS NULL
           AND expires_at >= NOW()
         LIMIT 1"
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $consume = $pdo->prepare('UPDATE email_verification_challenges SET consumed_at = NOW() WHERE id = ?');
    $consume->execute([(int)$row['id']]);

    $userId = (int)$row['user_id'];
    email_verification_mark_verified($pdo, $userId);
    return $userId;
}
