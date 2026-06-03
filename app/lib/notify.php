<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function smtp_is_ready(array $config): bool
{
    $smtp = $config['smtp'] ?? [];
    if (($smtp['enabled'] ?? false) !== true) {
        return false;
    }

    $required = ['host', 'port', 'username', 'password', 'from_email'];
    foreach ($required as $key) {
        if (trim((string)($smtp[$key] ?? '')) === '') {
            return false;
        }
    }

    return true;
}

function send_smtp_mail(array $config, string $toEmail, string $toName, string $subject, string $bodyHtml): bool
{
    if (!smtp_is_ready($config)) {
        return false;
    }

    if (!class_exists(PHPMailer::class)) {
        return false;
    }

    $smtp = $config['smtp'];
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = (string)$smtp['host'];
        $mail->Port = (int)$smtp['port'];
        $mail->SMTPAuth = true;
        $mail->Username = (string)$smtp['username'];
        $mail->Password = (string)$smtp['password'];
        $mail->Timeout = (int)$smtp['timeout'];

        $enc = strtolower(trim((string)$smtp['encryption']));
        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom((string)$smtp['from_email'], (string)$smtp['from_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $bodyHtml;
        $mail->AltBody = strip_tags($bodyHtml);

        return $mail->send();
    } catch (Exception) {
        return false;
    }
}

function tenant_users_by_roles(PDO $pdo, int $tenantId, array $roles): array
{
    if ($roles === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $params = array_merge([$tenantId], $roles);

    $stmt = $pdo->prepare(
        'SELECT full_name, email FROM users WHERE tenant_id = ? AND role IN (' . $placeholders . ') AND is_active = 1'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}
