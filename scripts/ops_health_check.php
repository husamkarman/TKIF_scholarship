<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/lib/db.php';

$result = [
    'ok' => true,
    'checks' => [],
    'warnings' => [],
    'generated_at' => gmdate('c'),
];

try {
    $pdo = db($config);
    $result['checks']['db_connection'] = 'ok';
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['checks']['db_connection'] = 'failed';
    $result['warnings'][] = 'DB connection failed: ' . $e->getMessage();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$requiredTables = [
    'users',
    'notification_jobs',
    'notification_inbox',
    'email_verification_challenges',
    'rate_limit_events',
];

foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $exists = (bool)$stmt->fetchColumn();
    $result['checks']['table_' . $table] = $exists ? 'ok' : 'missing';
    if (!$exists) {
        $result['ok'] = false;
        $result['warnings'][] = 'Missing required table: ' . $table;
    }
}

$pendingOldStmt = $pdo->query("SELECT COUNT(*) FROM notification_jobs WHERE status IN ('pending','processing') AND available_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$pendingOld = (int)$pendingOldStmt->fetchColumn();
$result['checks']['pending_jobs_older_15m'] = $pendingOld;
if ($pendingOld > 50) {
    $result['warnings'][] = 'High pending notification backlog: ' . $pendingOld;
}

$failed24Stmt = $pdo->query("SELECT COUNT(*) FROM notification_jobs WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$failed24 = (int)$failed24Stmt->fetchColumn();
$result['checks']['failed_jobs_last_24h'] = $failed24;
if ($failed24 > 20) {
    $result['warnings'][] = 'High failed notification jobs in last 24h: ' . $failed24;
}

$smtpEnabled = ($config['smtp']['enabled'] ?? false) === true;
$result['checks']['smtp_enabled'] = $smtpEnabled ? 'yes' : 'no';
if ($smtpEnabled) {
    $requiredSmtp = ['host', 'port', 'from_email'];
    foreach ($requiredSmtp as $key) {
        $value = trim((string)($config['smtp'][$key] ?? ''));
        $result['checks']['smtp_' . $key] = $value !== '' ? 'ok' : 'missing';
        if ($value === '') {
            $result['warnings'][] = 'SMTP setting missing: ' . $key;
        }
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
