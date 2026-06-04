<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/lib/db.php';

$apply = in_array('--apply', $argv, true);

try {
    $pdo = db($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$tasks = [
    [
        'name' => 'otp_codes',
        'sql' => "DELETE FROM otp_codes WHERE (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) OR (expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY))",
        'preview' => "SELECT COUNT(*) FROM otp_codes WHERE (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) OR (expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY))",
    ],
    [
        'name' => 'email_verification_challenges',
        'sql' => "DELETE FROM email_verification_challenges WHERE (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) OR (expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY))",
        'preview' => "SELECT COUNT(*) FROM email_verification_challenges WHERE (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) OR (expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY))",
    ],
    [
        'name' => 'rate_limit_events',
        'sql' => "DELETE FROM rate_limit_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
        'preview' => "SELECT COUNT(*) FROM rate_limit_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
    ],
    [
        'name' => 'notification_jobs',
        'sql' => "DELETE FROM notification_jobs WHERE status IN ('sent','failed') AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        'preview' => "SELECT COUNT(*) FROM notification_jobs WHERE status IN ('sent','failed') AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    ],
    [
        'name' => 'notification_inbox',
        'sql' => "DELETE FROM notification_inbox WHERE status IN ('processed','failed') AND received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        'preview' => "SELECT COUNT(*) FROM notification_inbox WHERE status IN ('processed','failed') AND received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    ],
];

echo $apply ? "Running cleanup (apply mode)" . PHP_EOL : "Running cleanup preview (dry run)" . PHP_EOL;

foreach ($tasks as $task) {
    $countStmt = $pdo->query($task['preview']);
    $count = (int)$countStmt->fetchColumn();

    if (!$apply) {
        echo '[DRY] ' . $task['name'] . ': ' . $count . ' rows' . PHP_EOL;
        continue;
    }

    if ($count <= 0) {
        echo '[APPLY] ' . $task['name'] . ': 0 rows' . PHP_EOL;
        continue;
    }

    $delStmt = $pdo->exec($task['sql']);
    $deleted = is_int($delStmt) ? $delStmt : 0;
    echo '[APPLY] ' . $task['name'] . ': ' . $deleted . ' rows' . PHP_EOL;
}

exit(0);
