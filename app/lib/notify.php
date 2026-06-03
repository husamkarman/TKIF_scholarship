<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function smtp_set_last_error(string $message): void
{
    $GLOBALS['tkif_smtp_last_error'] = $message;
}

function smtp_get_last_error(): string
{
    return (string)($GLOBALS['tkif_smtp_last_error'] ?? '');
}

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
    smtp_set_last_error('');

    if (!smtp_is_ready($config)) {
        smtp_set_last_error('SMTP is not fully configured');
        return false;
    }

    if (!class_exists(PHPMailer::class)) {
        smtp_set_last_error('PHPMailer is not available');
        return false;
    }

    $smtp = $config['smtp'];
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = (string)$smtp['host'];
        $mail->Port = (int)$smtp['port'];
        $mail->SMTPAuth = ($smtp['auth'] ?? true) === true;
        if ($mail->SMTPAuth) {
            $mail->Username = (string)$smtp['username'];
            $mail->Password = (string)$smtp['password'];
        }
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

        $sent = $mail->send();
        if (!$sent) {
            $error = trim((string)$mail->ErrorInfo);
            smtp_set_last_error($error !== '' ? $error : 'Unknown SMTP send failure');
        }
        return $sent;
    } catch (Exception $e) {
        $mailError = trim((string)$mail->ErrorInfo);
        $exError = trim($e->getMessage());
        $combined = trim($mailError . ($mailError !== '' && $exError !== '' ? ' | ' : '') . $exError);
        smtp_set_last_error($combined !== '' ? $combined : 'SMTP exception');
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
