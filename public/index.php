<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/lib/db.php';
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
  require dirname(__DIR__) . '/vendor/autoload.php';
}
require dirname(__DIR__) . '/app/lib/notify.php';
require dirname(__DIR__) . '/app/lib/email_verification.php';
require dirname(__DIR__) . '/app/lib/profile.php';
require dirname(__DIR__) . '/app/lib/phone_codes.php';
require dirname(__DIR__) . '/app/lib/form_builder.php';
require dirname(__DIR__) . '/app/controllers/profile_controller.php';

use Shuchkin\SimpleXLSX;

session_name($config['session_name']);
$forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
$requestIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
  || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
  || $forwardedProto === 'https';
$cookieSecure = $requestIsHttps || strtolower((string)($config['app_env'] ?? 'local')) === 'production';
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => $cookieSecure,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function lock_badge_html(bool $locked): string
{
  return $locked ? '<span class="lock-badge" title="Read only for your role">Locked</span>' : '';
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(422);
        exit('Invalid CSRF token');
    }
}

function json_response(int $statusCode, array $payload): void
{
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function request_header_value(string $name): string
{
  $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  $value = $_SERVER[$serverKey] ?? null;
  if (is_string($value) && $value !== '') {
    return trim($value);
  }

  if (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
      foreach ($headers as $key => $headerValue) {
        if (strcasecmp((string)$key, $name) === 0 && is_string($headerValue)) {
          return trim($headerValue);
        }
      }
    }
  }

  return '';
}

function csv_safe_cell(string $value): string
{
  if (preg_match('/^\s*[=+\-@]/', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1) {
    return "'" . $value;
  }

  return $value;
}

function password_strength_error(string $password): ?string
{
  if (strlen($password) < 8) {
    return 'Password must be at least 8 characters.';
  }

  if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    return 'Password must contain at least one letter and one number.';
  }

  return null;
}

function write_audit_log(PDO $pdo, int $tenantId, int $actorUserId, string $eventName, string $entityType, ?int $entityId, array $details = []): void
{
  try {
    $audit = $pdo->prepare('INSERT INTO audit_logs (tenant_id, actor_user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?)');
    $audit->execute([
      $tenantId,
      $actorUserId,
      $eventName,
      $entityType,
      ($entityId !== null && $entityId > 0) ? $entityId : null,
      json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);
  } catch (Throwable) {
    // Audit failure must not block user-facing flows.
  }
}

function captcha_is_enabled(array $config): bool
{
  $captcha = $config['captcha'] ?? [];
  return ($captcha['enabled'] ?? false) === true
    && trim((string)($captcha['site_key'] ?? '')) !== ''
    && trim((string)($captcha['secret_key'] ?? '')) !== '';
}

function captcha_provider(array $config): string
{
  $provider = strtolower(trim((string)($config['captcha']['provider'] ?? 'turnstile')));
  return in_array($provider, ['turnstile', 'recaptcha'], true) ? $provider : 'turnstile';
}

function captcha_site_key(array $config): string
{
  return trim((string)($config['captcha']['site_key'] ?? ''));
}

function captcha_verify_url(array $config): string
{
  $configured = trim((string)($config['captcha']['verify_url'] ?? ''));
  if ($configured !== '') {
    return $configured;
  }

  return captcha_provider($config) === 'recaptcha'
    ? 'https://www.google.com/recaptcha/api/siteverify'
    : 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
}

function captcha_script_url(array $config): string
{
  return captcha_provider($config) === 'recaptcha'
    ? 'https://www.google.com/recaptcha/api.js'
    : 'https://challenges.cloudflare.com/turnstile/v0/api.js';
}

function captcha_token_from_post(array $config, array $post): string
{
  if (captcha_provider($config) === 'recaptcha') {
    return trim((string)($post['g-recaptcha-response'] ?? $post['captcha_token'] ?? ''));
  }

  return trim((string)($post['cf-turnstile-response'] ?? $post['captcha_token'] ?? ''));
}

function captcha_widget_markup(array $config): string
{
  if (!captcha_is_enabled($config)) {
    return '';
  }

  $siteKey = h(captcha_site_key($config));
  if (captcha_provider($config) === 'recaptcha') {
    return '<div class="g-recaptcha" data-sitekey="' . $siteKey . '"></div>';
  }

  return '<div class="cf-turnstile" data-sitekey="' . $siteKey . '"></div>';
}

function captcha_verify_submission(array $config, array $post): bool
{
  if (!captcha_is_enabled($config)) {
    return true;
  }

  $token = captcha_token_from_post($config, $post);
  if ($token === '') {
    return false;
  }

  $verifyUrl = captcha_verify_url($config);
  $secret = trim((string)($config['captcha']['secret_key'] ?? ''));
  if ($verifyUrl === '' || $secret === '' || !function_exists('curl_init')) {
    return false;
  }

  $payload = http_build_query([
    'secret' => $secret,
    'response' => $token,
    'remoteip' => client_ip(),
  ]);

  $ch = curl_init($verifyUrl);
  if ($ch === false) {
    return false;
  }

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 10,
  ]);

  $response = curl_exec($ch);
  if ($response === false) {
    curl_close($ch);
    return false;
  }
  curl_close($ch);

  $decoded = json_decode((string)$response, true);
  return is_array($decoded) && ($decoded['success'] ?? false) === true;
}

function login_attempts_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'login_attempts'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function login_attempt_ip_token(array $config): string
{
  $ip = client_ip();
  $pepper = trim((string)($config['security']['login_attempts']['ip_hash_pepper'] ?? ''));
  if ($pepper === '') {
    $pepper = (string)($config['session_name'] ?? 'tkif_session');
  }

  return 'h:' . substr(hash_hmac('sha256', $ip, $pepper), 0, 40);
}

function log_login_attempt(PDO $pdo, array $config, string $email, bool $success): void
{
  if (!login_attempts_ready($pdo)) {
    return;
  }

  $emailNorm = normalize_email($email);
  $stmt = $pdo->prepare('INSERT INTO login_attempts (email_normalized, ip_address, success) VALUES (?, ?, ?)');
  $stmt->execute([
    $emailNorm,
    login_attempt_ip_token($config),
    $success ? 1 : 0,
  ]);
}

function login_lockout_state(PDO $pdo, array $config, string $email): array
{
  $emailNorm = normalize_email($email);
  if ($emailNorm === '' || !login_attempts_ready($pdo)) {
    return ['locked' => false, 'retry_after_seconds' => 0];
  }

  $policy = is_array($config['security']['login_lockout'] ?? null) ? $config['security']['login_lockout'] : [];
  $enabled = ($policy['enabled'] ?? true) === true;
  if (!$enabled) {
    return ['locked' => false, 'retry_after_seconds' => 0];
  }

  $threshold = max(1, (int)($policy['failure_threshold'] ?? 8));
  $windowSeconds = max(60, (int)($policy['window_seconds'] ?? 900));

  $countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM login_attempts
     WHERE email_normalized = ?
       AND success = 0
       AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
  );
  $countStmt->execute([$emailNorm, $windowSeconds]);
  $failedCount = (int)$countStmt->fetchColumn();

  if ($failedCount < $threshold) {
    return ['locked' => false, 'retry_after_seconds' => 0];
  }

  $lastFailedStmt = $pdo->prepare(
    'SELECT UNIX_TIMESTAMP(MAX(created_at))
     FROM login_attempts
     WHERE email_normalized = ?
       AND success = 0
       AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
  );
  $lastFailedStmt->execute([$emailNorm, $windowSeconds]);
  $lastFailedTs = (int)$lastFailedStmt->fetchColumn();
  $retryAfter = max(0, ($lastFailedTs + $windowSeconds) - time());

  return ['locked' => true, 'retry_after_seconds' => $retryAfter];
}

function clear_login_attempts_for_email(PDO $pdo, string $email): void
{
  $emailNorm = normalize_email($email);
  if ($emailNorm === '' || !login_attempts_ready($pdo)) {
    return;
  }

  $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email_normalized = ?');
  $stmt->execute([$emailNorm]);
}

function run_security_retention_cleanup(PDO $pdo, array $config): array
{
  $retention = is_array($config['security']['retention'] ?? null) ? $config['security']['retention'] : [];
  if (($retention['enabled'] ?? true) !== true) {
    return ['enabled' => false, 'login_attempts_deleted' => 0, 'password_resets_deleted' => 0];
  }

  $loginAttemptsDeleted = 0;
  $passwordResetsDeleted = 0;

  $loginDays = max(1, (int)($retention['login_attempts_days'] ?? 30));
  $resetDays = max(1, (int)($retention['password_resets_days'] ?? 30));

  if (login_attempts_ready($pdo)) {
    $loginCutoff = (new DateTimeImmutable('now'))->sub(new DateInterval('P' . (string)$loginDays . 'D'))->format('Y-m-d H:i:s');
    $deleteLogin = $pdo->prepare('DELETE FROM login_attempts WHERE created_at < ?');
    $deleteLogin->execute([$loginCutoff]);
    $loginAttemptsDeleted = (int)$deleteLogin->rowCount();
  }

  if (password_resets_ready($pdo)) {
    $resetCutoff = (new DateTimeImmutable('now'))->sub(new DateInterval('P' . (string)$resetDays . 'D'))->format('Y-m-d H:i:s');

    $deleteConsumed = $pdo->prepare('DELETE FROM password_resets WHERE consumed_at IS NOT NULL AND created_at < ?');
    $deleteConsumed->execute([$resetCutoff]);
    $passwordResetsDeleted += (int)$deleteConsumed->rowCount();

    $deleteExpired = $pdo->prepare('DELETE FROM password_resets WHERE consumed_at IS NULL AND expires_at < ?');
    $deleteExpired->execute([$resetCutoff]);
    $passwordResetsDeleted += (int)$deleteExpired->rowCount();
  }

  return [
    'enabled' => true,
    'login_attempts_deleted' => $loginAttemptsDeleted,
    'password_resets_deleted' => $passwordResetsDeleted,
  ];
}

function password_resets_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'password_resets'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function client_ip(): string
{
  $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
  foreach ($keys as $key) {
    $value = trim((string)($_SERVER[$key] ?? ''));
    if ($value === '') {
      continue;
    }
    if ($key === 'HTTP_X_FORWARDED_FOR') {
      $parts = explode(',', $value);
      $value = trim((string)($parts[0] ?? ''));
    }
    if ($value !== '') {
      return substr($value, 0, 64);
    }
  }
  return 'unknown';
}

function rate_limit_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'rate_limit_events'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function rate_limit_check(PDO $pdo, string $actionKey, string $clientKey, int $maxAttempts, int $windowSeconds): bool
{
  if (!rate_limit_ready($pdo)) {
    return true;
  }

  $maxAttempts = max(1, $maxAttempts);
  $windowSeconds = max(1, $windowSeconds);

  $countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM rate_limit_events
     WHERE action_key = ? AND client_key = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
  );
  $countStmt->execute([$actionKey, $clientKey, $windowSeconds]);
  $attemptCount = (int)$countStmt->fetchColumn();

  if ($attemptCount >= $maxAttempts) {
    return false;
  }

  $insertStmt = $pdo->prepare('INSERT INTO rate_limit_events (action_key, client_key) VALUES (?, ?)');
  $insertStmt->execute([$actionKey, $clientKey]);

  return true;
}

function rate_limit_allow_request(PDO $pdo, array $config, string $actionKey, string $identityKey = ''): bool
{
  $limits = $config['security']['rate_limits'] ?? [];
  $limit = $limits[$actionKey] ?? null;
  if (!is_array($limit)) {
    return true;
  }

  $max = max(1, (int)($limit['max'] ?? 10));
  $windowSeconds = max(1, (int)($limit['window_seconds'] ?? 300));
  $ip = client_ip();

  $clientKey = $actionKey . '|ip:' . $ip;
  if ($identityKey !== '') {
    $clientKey .= '|id:' . substr($identityKey, 0, 120);
  }

  return rate_limit_check($pdo, $actionKey, $clientKey, $max, $windowSeconds);
}

function app_base_path(): string
{
  $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
  $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

  $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
  $requestPath = (string)parse_url($requestUri, PHP_URL_PATH);
  $requestPath = rtrim(str_replace('\\', '/', $requestPath), '/');

  if ($basePath !== '' && str_ends_with($basePath, '/public')) {
    $publicBase = $basePath;
    $visibleHasPublic = $requestPath === $publicBase || str_starts_with($requestPath . '/', $publicBase . '/');
    if (!$visibleHasPublic) {
      $basePath = rtrim(substr($basePath, 0, -7), '/');
    }
  }

  if ($basePath === '' || $basePath === '/' || $basePath === '.') {
    return '';
  }

  return $basePath;
}

function app_route(string $page): string
{
  return app_base_path() . '/?page=' . rawurlencode($page);
}

function app_asset(string $path): string
{
  $trimmed = ltrim($path, '/');
  return app_base_path() . '/' . $trimmed;
}

function dashboard_automation_audit_dir(): string
{
  return dirname(__DIR__) . '/n8n/workflows/audit';
}

function dashboard_automation_safe_task_id(string $raw): string
{
  $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', trim($raw));
  $safe = trim((string)$safe, '_-');
  if ($safe === '') {
    $safe = 'task_' . (string)time();
  }
  return substr($safe, 0, 80);
}

function dashboard_automation_is_safe_patch_file(string $candidatePath, string $auditDir): bool
{
  if (!is_file($candidatePath) || !str_ends_with($candidatePath, '.diff')) {
    return false;
  }

  $auditReal = realpath($auditDir);
  $fileReal = realpath($candidatePath);
  if (!is_string($auditReal) || !is_string($fileReal) || $auditReal === '' || $fileReal === '') {
    return false;
  }

  $prefix = rtrim($auditReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  return str_starts_with($fileReal, $prefix);
}

function scholarship_node_upload_relative_dir(string $kind): string
{
  $safeKind = $kind === 'pdfs' ? 'pdfs' : 'images';
  return 'uploads/scholarship_nodes/' . $safeKind;
}

function scholarship_node_upload_abs_dir(string $kind): string
{
  return dirname(__DIR__) . '/public/' . scholarship_node_upload_relative_dir($kind);
}

function make_safe_upload_basename(string $originalName): string
{
  $base = pathinfo($originalName, PATHINFO_FILENAME);
  $base = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)$base);
  $base = trim((string)$base, '_-');
  if ($base === '') {
    $base = 'node_media';
  }
  return strtolower($base);
}

function harden_upload_directory(string $absDir): void
{
  if (!is_dir($absDir)) {
    return;
  }

  $htaccessPath = rtrim($absDir, '/\\') . '/.htaccess';
  if (is_file($htaccessPath)) {
    return;
  }

  $rules = "Options -Indexes\n"
    . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar|cgi|pl)$\">\n"
    . "  Require all denied\n"
    . "</FilesMatch>\n"
    . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar .cgi .pl\n"
    . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar .cgi .pl\n";

  @file_put_contents($htaccessPath, $rules, LOCK_EX);
}

function users_blacklist_column_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'blacklist'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function oauth_state_cookie_name(string $provider): string
{
  return 'tkif_oauth_state_' . strtolower(trim($provider));
}

function oauth_cookie_secure(array $config): bool
{
  $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
  $requestIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || $forwardedProto === 'https';

  return $requestIsHttps || strtolower((string)($config['app_env'] ?? 'local')) === 'production';
}

function oauth_state_signing_key(array $config, string $provider): string
{
  $provider = strtolower(trim($provider));
  if ($provider === 'google') {
    $secret = trim((string)($config['google']['client_secret'] ?? ''));
    if ($secret !== '') {
      return 'google|' . $secret;
    }
  }

  if ($provider === 'microsoft') {
    $secret = trim((string)($config['microsoft']['client_secret'] ?? ''));
    if ($secret !== '') {
      return 'microsoft|' . $secret;
    }
  }

  return 'fallback|' . (string)($config['session_name'] ?? 'tkif_session');
}

function oauth_signed_state(array $config, string $provider): string
{
  $nonce = bin2hex(random_bytes(16));
  $issuedAt = (string)time();
  $payload = $provider . '|' . $nonce . '|' . $issuedAt;
  $signature = hash_hmac('sha256', $payload, oauth_state_signing_key($config, $provider));
  return $nonce . '.' . $issuedAt . '.' . $signature;
}

function oauth_is_signed_state_valid(array $config, string $provider, string $state): bool
{
  $parts = explode('.', trim($state));
  if (count($parts) !== 3) {
    return false;
  }

  [$nonce, $issuedAtRaw, $signature] = $parts;
  if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
    return false;
  }
  if (!preg_match('/^\d{10}$/', $issuedAtRaw)) {
    return false;
  }
  if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
    return false;
  }

  $issuedAt = (int)$issuedAtRaw;
  if ($issuedAt <= 0 || abs(time() - $issuedAt) > 900) {
    return false;
  }

  $payload = $provider . '|' . $nonce . '|' . $issuedAtRaw;
  $expected = hash_hmac('sha256', $payload, oauth_state_signing_key($config, $provider));
  return hash_equals($expected, $signature);
}

function oauth_store_state(array $config, string $provider, string $sessionKey, string $state): void
{
  $_SESSION[$sessionKey] = $state;
  setcookie(oauth_state_cookie_name($provider), $state, [
    'expires' => time() + 600,
    'path' => '/',
    'secure' => oauth_cookie_secure($config),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function oauth_read_state(string $provider, string $sessionKey): string
{
  $sessionState = trim((string)($_SESSION[$sessionKey] ?? ''));
  if ($sessionState !== '') {
    return $sessionState;
  }

  $cookieState = trim((string)($_COOKIE[oauth_state_cookie_name($provider)] ?? ''));
  return $cookieState;
}

function oauth_clear_state(array $config, string $provider, string $sessionKey): void
{
  unset($_SESSION[$sessionKey]);
  setcookie(oauth_state_cookie_name($provider), '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => oauth_cookie_secure($config),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
      header('Location: ' . app_route('login'));
        exit;
    }
    return $user;
}

function set_user_session(array $user): void
{
  $_SESSION['user'] = [
    'id' => (int)$user['id'],
    'register_id' => (int)($user['register_id'] ?? $user['id']),
    'tenant_id' => (int)$user['tenant_id'],
    'name' => $user['full_name'],
    'email' => $user['email'],
    'role' => $user['role'],
  ];
}

function find_active_user_by_email(PDO $pdo, string $email): ?array
{
  $normalized = normalize_email($email);
  $stmt = $pdo->prepare('SELECT id, register_id, tenant_id, full_name, email, role, password_hash, email_verified_at FROM users WHERE LOWER(TRIM(email)) = ? AND is_active = 1 LIMIT 1');
  $stmt->execute([$normalized]);
  $user = $stmt->fetch();
  return $user ?: null;
}

function find_active_user_by_id(PDO $pdo, int $userId): ?array
{
  $stmt = $pdo->prepare('SELECT id, register_id, tenant_id, full_name, email, role, password_hash, email_verified_at FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
  $stmt->execute([$userId]);
  $user = $stmt->fetch();
  return $user ?: null;
}

function user_email_exists(PDO $pdo, string $email): bool
{
  $normalized = normalize_email($email);
  $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
  $stmt->execute([$normalized]);
  return (bool)$stmt->fetchColumn();
}

function resolve_registration_tenant_id(PDO $pdo, array $config): ?int
{
  $registration = $config['registration'] ?? [];
  $tenantCodes = [];

  $preferredCode = trim((string)($registration['default_tenant_code'] ?? ''));
  if ($preferredCode !== '') {
    $tenantCodes[] = $preferredCode;
  }

  $msCode = trim((string)($config['microsoft']['default_tenant_code'] ?? ''));
  if ($msCode !== '') {
    $tenantCodes[] = $msCode;
  }

  $googleCode = trim((string)($config['google']['default_tenant_code'] ?? ''));
  if ($googleCode !== '') {
    $tenantCodes[] = $googleCode;
  }

  $tenantCodes = array_values(array_unique($tenantCodes));
  if ($tenantCodes !== []) {
    $tenantByCode = $pdo->prepare('SELECT id FROM tenants WHERE code = ? AND is_active = 1 LIMIT 1');
    foreach ($tenantCodes as $code) {
      $tenantByCode->execute([$code]);
      $tenant = $tenantByCode->fetch();
      if ($tenant) {
        return (int)$tenant['id'];
      }
    }
  }

  $fallback = $pdo->query('SELECT id FROM tenants WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
  if ($fallback !== false) {
    $tenantId = $fallback->fetchColumn();
    if ($tenantId !== false) {
      return (int)$tenantId;
    }
  }

  return null;
}

function identity_table_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_identities'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function scholarship_form_versioning_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'scholarship_form_versions'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function application_form_snapshot_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'application_form_snapshots'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function notification_inbox_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'notification_inbox'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function notification_hmac_verification(array $config, string $rawBody): array
{
  $secret = trim((string)($config['notifications']['internal_secret'] ?? ''));
  if ($secret === '') {
    return ['ok' => false, 'error' => 'notification_secret_not_configured'];
  }

  $timestampHeader = request_header_value('X-TKIF-Timestamp');
  $signatureHeader = request_header_value('X-TKIF-Signature');
  if ($timestampHeader === '' || $signatureHeader === '') {
    return ['ok' => false, 'error' => 'missing_signature_headers'];
  }

  if (!preg_match('/^\d{10}$/', $timestampHeader)) {
    return ['ok' => false, 'error' => 'invalid_signature_timestamp'];
  }

  $tolerance = max(30, (int)($config['notifications']['hmac_tolerance_seconds'] ?? 300));
  $requestTs = (int)$timestampHeader;
  if (abs(time() - $requestTs) > $tolerance) {
    return ['ok' => false, 'error' => 'signature_timestamp_expired'];
  }

  $candidateBodies = [$rawBody];
  $trimmedBody = rtrim($rawBody, "\r\n");
  if ($trimmedBody !== $rawBody) {
    $candidateBodies[] = $trimmedBody;
  }

  $decodedBody = json_decode($rawBody, true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $canonicalBody = json_encode($decodedBody, JSON_UNESCAPED_SLASHES);
    if (is_string($canonicalBody) && !in_array($canonicalBody, $candidateBodies, true)) {
      $candidateBodies[] = $canonicalBody;
    }
  }

  $signatureValid = false;
  foreach ($candidateBodies as $candidateBody) {
    $expectedSignature = 'sha256=' . hash_hmac('sha256', $timestampHeader . '.' . $candidateBody, $secret);
    if (hash_equals($expectedSignature, $signatureHeader)) {
      $signatureValid = true;
      break;
    }
  }

  if (!$signatureValid) {
    return ['ok' => false, 'error' => 'invalid_signature'];
  }

  return ['ok' => true, 'error' => ''];
}

function resolve_active_scholarship_schema(PDO $pdo, array $scholarship): array
{
  $schemaJson = (string)($scholarship['form_schema_json'] ?? '[]');
  $version = 1;

  if (scholarship_form_versioning_ready($pdo)) {
    $stmt = $pdo->prepare(
      'SELECT version_no, form_schema_json
       FROM scholarship_form_versions
       WHERE scholarship_id = ? AND tenant_id = ?
       ORDER BY version_no DESC
       LIMIT 1'
    );
    $stmt->execute([(int)$scholarship['id'], (int)$scholarship['tenant_id']]);
    $row = $stmt->fetch();
    if ($row) {
      $schemaJson = (string)($row['form_schema_json'] ?? $schemaJson);
      $version = max(1, (int)($row['version_no'] ?? 1));
    }
  }

  $rawSchema = json_decode($schemaJson, true);
  $nodes = normalize_scholarship_nodes($rawSchema);
  $settings = scholarship_form_settings_from_raw($rawSchema);

  return [
    'schema' => flatten_scholarship_nodes($nodes),
    'settings' => $settings,
    'version' => $version,
  ];
}

function normalize_form_settings($settings): array
{
  $input = is_array($settings) ? $settings : [];

  $oneResponsePerUser = ($input['one_response_per_user'] ?? false) === true;
  $allowEditAfterSubmit = ($input['allow_edit_after_submit'] ?? false) === true;

  $autosaveSeconds = (int)($input['autosave_interval_seconds'] ?? 30);
  $autosaveSeconds = max(5, min(300, $autosaveSeconds));

  $submissionStartAt = trim((string)($input['submission_start_at'] ?? ''));
  if ($submissionStartAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $submissionStartAt)) {
    $submissionStartAt = '';
  }

  $submissionEndAt = trim((string)($input['submission_end_at'] ?? ''));
  if ($submissionEndAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $submissionEndAt)) {
    $submissionEndAt = '';
  }

  if ($submissionStartAt !== '' && $submissionEndAt !== '' && strcmp($submissionEndAt, $submissionStartAt) < 0) {
    $submissionEndAt = $submissionStartAt;
  }

  return [
    'one_response_per_user' => $oneResponsePerUser,
    'allow_edit_after_submit' => $allowEditAfterSubmit,
    'autosave_interval_seconds' => $autosaveSeconds,
    'submission_start_at' => $submissionStartAt,
    'submission_end_at' => $submissionEndAt,
  ];
}

function scholarship_schema_payload_parts($rawSchema): array
{
  if (!is_array($rawSchema)) {
    return [
      'nodes' => [],
      'settings' => normalize_form_settings([]),
    ];
  }

  if (array_key_exists('nodes', $rawSchema) || array_key_exists('settings', $rawSchema)) {
    return [
      'nodes' => is_array($rawSchema['nodes'] ?? null) ? $rawSchema['nodes'] : [],
      'settings' => normalize_form_settings($rawSchema['settings'] ?? []),
    ];
  }

  return [
    'nodes' => $rawSchema,
    'settings' => normalize_form_settings([]),
  ];
}

function scholarship_form_settings_from_raw($rawSchema): array
{
  $parts = scholarship_schema_payload_parts($rawSchema);
  return $parts['settings'];
}

function scholarship_schema_payload_json(array $nodes, array $settings): string
{
  $payload = [
    'nodes' => $nodes,
    'settings' => normalize_form_settings($settings),
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  return is_string($json) ? $json : '[]';
}

function normalize_scholarship_nodes($rawSchema): array
{
  $parts = scholarship_schema_payload_parts($rawSchema);
  $nodes = $parts['nodes'];
  if (!is_array($nodes)) {
    return [];
  }

  if ($nodes !== [] && isset($nodes[0]) && is_array($nodes[0]) && array_key_exists('node_type', $nodes[0])) {
    return normalize_form_schema($nodes);
  }

  return normalize_form_schema($nodes);
}

function flatten_scholarship_nodes(array $nodes): array
{
  $flat = [];
  foreach ($nodes as $node) {
    $type = (string)($node['type'] ?? '');
    if (in_array($type, ['welcome', 'agreement', 'section', 'form', 'thank_you'], true)) {
      $flat[] = $node;
      continue;
    }
    $flat[] = $node;
  }
  return $flat;
}

function count_input_fields(array $schema): int
{
  $count = 0;
  foreach ($schema as $field) {
    $type = (string)($field['type'] ?? '');
    if (in_array($type, ['welcome', 'agreement', 'section', 'form', 'thank_you'], true)) {
      continue;
    }
    $count++;
  }
  return $count;
}

function snapshot_application_form(PDO $pdo, int $tenantId, int $applicationId, int $scholarshipId, int $formVersion, array $formSchema, array $answers): void
{
  if (!application_form_snapshot_ready($pdo)) {
    return;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO application_form_snapshots (application_id, tenant_id, scholarship_id, form_version_no, form_schema_json, answers_json)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       form_version_no = VALUES(form_version_no),
       form_schema_json = VALUES(form_schema_json),
       answers_json = VALUES(answers_json)'
  );
  $stmt->execute([
    $applicationId,
    $tenantId,
    $scholarshipId,
    max(1, $formVersion),
    json_encode($formSchema, JSON_UNESCAPED_UNICODE),
    json_encode($answers, JSON_UNESCAPED_UNICODE),
  ]);
}

function find_active_user_by_identity(PDO $pdo, string $provider, string $providerUserId): ?array
{
  $provider = trim(strtolower($provider));
  $providerUserId = trim($providerUserId);
  if ($provider === '' || $providerUserId === '' || !identity_table_ready($pdo)) {
    return null;
  }

  $stmt = $pdo->prepare(
    'SELECT u.id, u.tenant_id, u.full_name, u.email, u.role, u.password_hash, u.email_verified_at
     FROM user_identities i
     INNER JOIN users u ON u.id = i.user_id
     WHERE i.provider = ? AND i.provider_user_id = ? AND u.is_active = 1
     LIMIT 1'
  );
  $stmt->execute([$provider, $providerUserId]);
  $user = $stmt->fetch();
  return $user ?: null;
}

function upsert_user_identity(PDO $pdo, int $userId, string $provider, string $providerUserId, string $providerEmail): bool
{
  $provider = trim(strtolower($provider));
  $providerUserId = trim($providerUserId);
  if ($provider === '' || $providerUserId === '' || !identity_table_ready($pdo)) {
    return true;
  }

  $providerEmail = normalize_email($providerEmail);

  $existing = $pdo->prepare('SELECT user_id FROM user_identities WHERE provider = ? AND provider_user_id = ? LIMIT 1');
  $existing->execute([$provider, $providerUserId]);
  $existingRow = $existing->fetch();
  if ($existingRow && (int)$existingRow['user_id'] !== $userId) {
    return false;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO user_identities (user_id, provider, provider_user_id, provider_email)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       provider_user_id = VALUES(provider_user_id),
       provider_email = VALUES(provider_email),
       updated_at = CURRENT_TIMESTAMP'
  );
  $stmt->execute([$userId, $provider, $providerUserId, $providerEmail]);

  return true;
}

function otp_ready(array $config): bool
{
  return ($config['otp']['enabled'] ?? false) && smtp_is_ready($config);
}

function generate_numeric_otp(int $length): string
{
  $length = max(4, min(10, $length));
  $code = '';
  for ($i = 0; $i < $length; $i++) {
    $code .= (string)random_int(0, 9);
  }
  return $code;
}

function issue_email_otp(PDO $pdo, array $config, array $user): bool
{
  if (!otp_ready($config)) {
    return false;
  }

  $code = generate_numeric_otp((int)$config['otp']['length']);
  $hash = password_hash($code, PASSWORD_DEFAULT);
  if ($hash === false) {
    return false;
  }

  $ttlMinutes = max(1, (int)$config['otp']['ttl_minutes']);

  $stmt = $pdo->prepare('INSERT INTO otp_codes (tenant_id, user_id, email, otp_hash, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))');
  $stmt->execute([
    (int)$user['tenant_id'],
    (int)$user['id'],
    (string)$user['email'],
    $hash,
    $ttlMinutes,
  ]);

  return send_smtp_mail(
    $config,
    (string)$user['email'],
    (string)$user['full_name'],
    'Your OTP Login Code',
    '<p>Your OTP code is <strong>' . $code . '</strong>.</p><p>This code expires in ' . $ttlMinutes . ' minutes.</p>'
  );
}

function verify_email_otp(PDO $pdo, int $userId, string $code): bool
{
  $stmt = $pdo->prepare('SELECT id, otp_hash FROM otp_codes WHERE user_id = ? AND consumed_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 5');
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll();

  foreach ($rows as $row) {
    if (password_verify($code, (string)$row['otp_hash'])) {
      $consume = $pdo->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?');
      $consume->execute([(int)$row['id']]);
      return true;
    }
  }

  return false;
}

function microsoft_oauth_ready(array $config): bool
{
  $ms = $config['microsoft'] ?? [];
  return trim((string)($ms['client_id'] ?? '')) !== ''
    && trim((string)($ms['tenant_id'] ?? '')) !== ''
    && trim((string)($ms['redirect_uri'] ?? '')) !== ''
    && trim((string)($ms['client_secret'] ?? '')) !== '';
}

function microsoft_authorize_url(array $config): string
{
  $tenantId = (string)$config['microsoft']['tenant_id'];
  $state = oauth_signed_state($config, 'microsoft');
  oauth_store_state($config, 'microsoft', 'ms_oauth_state', $state);

  $query = http_build_query([
    'client_id' => (string)$config['microsoft']['client_id'],
    'response_type' => 'code',
    'redirect_uri' => (string)$config['microsoft']['redirect_uri'],
    'response_mode' => 'query',
    'scope' => 'openid profile email offline_access',
    'state' => $state,
  ]);

  return 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/authorize?' . $query;
}

function decode_jwt_claims(?string $jwt): ?array
{
  if (!is_string($jwt) || trim($jwt) === '') {
    return null;
  }

  $parts = explode('.', $jwt);
  if (count($parts) < 2) {
    return null;
  }

  $payload = $parts[1];
  $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
  $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
  if (!is_string($decoded)) {
    return null;
  }

  $claims = json_decode($decoded, true);
  return is_array($claims) ? $claims : null;
}

  function microsoft_extract_email(?array $profile, ?array $tokenData): string
  {
    $candidates = [];
    if (is_array($profile)) {
      foreach (['email', 'preferred_username', 'upn', 'unique_name'] as $key) {
        if (!empty($profile[$key])) {
          $candidates[] = (string)$profile[$key];
        }
      }
    }

    $claims = decode_jwt_claims(is_array($tokenData) ? (string)($tokenData['id_token'] ?? '') : null);
    if (is_array($claims)) {
      foreach (['email', 'preferred_username', 'upn', 'unique_name'] as $key) {
        if (!empty($claims[$key])) {
          $candidates[] = (string)$claims[$key];
        }
      }
    }

    foreach ($candidates as $value) {
      $normalized = normalize_email($value);
      if (str_contains($normalized, '@')) {
        return $normalized;
      }
    }

    return '';
  }

function microsoft_extract_subject(?array $profile, ?array $tokenData): string
{
  $candidates = [];
  if (is_array($profile)) {
    foreach (['sub', 'oid', 'id'] as $key) {
      if (!empty($profile[$key])) {
        $candidates[] = trim((string)$profile[$key]);
      }
    }
  }

  $claims = decode_jwt_claims(is_array($tokenData) ? (string)($tokenData['id_token'] ?? '') : null);
  if (is_array($claims)) {
    foreach (['sub', 'oid', 'id'] as $key) {
      if (!empty($claims[$key])) {
        $candidates[] = trim((string)$claims[$key]);
      }
    }
  }

  foreach ($candidates as $value) {
    if ($value !== '') {
      return $value;
    }
  }

  return '';
}

  function microsoft_provision_user_if_allowed(PDO $pdo, array $config, string $email, string $displayName): ?array
  {
    $ms = $config['microsoft'] ?? [];
    if (($ms['auto_provision'] ?? false) !== true) {
      return null;
    }

    $normalized = normalize_email($email);
    $allowedRaw = strtolower(trim((string)($ms['allowed_domain'] ?? '')));
    $allowedDomains = array_values(array_filter(array_map(static fn(string $d): string => trim($d), preg_split('/[,;\s]+/', $allowedRaw ?: '') ?: [])));
    if ($allowedDomains !== []) {
      $atPos = strrpos($normalized, '@');
      $domain = $atPos === false ? '' : substr($normalized, $atPos + 1);
      if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
        return null;
      }
    }

    $tenantCode = trim((string)($ms['default_tenant_code'] ?? ''));
    $tenantStmt = $pdo->prepare('SELECT id FROM tenants WHERE code = ? AND is_active = 1 LIMIT 1');
    $tenantStmt->execute([$tenantCode]);
    $tenant = $tenantStmt->fetch();
    if (!$tenant) {
      return null;
    }

    $role = trim((string)($ms['default_role'] ?? 'student'));
    if (!in_array($role, ['student', 'admin', 'manager', 'it'], true)) {
      $role = 'student';
    }

    $fullName = trim($displayName);
    if ($fullName === '') {
      $fullName = strstr($normalized, '@', true) ?: 'Microsoft User';
    }

    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    if ($passwordHash === false) {
      return null;
    }

    $insert = $pdo->prepare('INSERT INTO users (tenant_id, full_name, email, password_hash, role, is_active, email_verified_at) VALUES (?, ?, ?, ?, ?, 1, NOW())');
    $insert->execute([
      (int)$tenant['id'],
      $fullName,
      $normalized,
      $passwordHash,
      $role,
    ]);

    $createdId = (int)$pdo->lastInsertId();
    $registerUpdate = $pdo->prepare('UPDATE users SET register_id = ? WHERE id = ?');
    $registerUpdate->execute([$createdId, $createdId]);
    $select = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash, email_verified_at FROM users WHERE id = ? LIMIT 1');
    $select->execute([$createdId]);
    $newUser = $select->fetch();
    return $newUser ?: null;
  }

function google_oauth_ready(array $config): bool
{
  $g = $config['google'] ?? [];
  return trim((string)($g['client_id'] ?? '')) !== ''
    && trim((string)($g['redirect_uri'] ?? '')) !== ''
    && trim((string)($g['client_secret'] ?? '')) !== '';
}

function google_authorize_url(array $config): string
{
  $state = oauth_signed_state($config, 'google');
  oauth_store_state($config, 'google', 'google_oauth_state', $state);

  $query = http_build_query([
    'client_id' => (string)$config['google']['client_id'],
    'redirect_uri' => (string)$config['google']['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'online',
    'prompt' => 'select_account',
  ]);

  return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
}

function google_extract_subject(?array $profile, ?array $tokenData): string
{
  if (is_array($profile) && !empty($profile['sub'])) {
    return trim((string)$profile['sub']);
  }

  $claims = decode_jwt_claims(is_array($tokenData) ? (string)($tokenData['id_token'] ?? '') : null);
  if (is_array($claims) && !empty($claims['sub'])) {
    return trim((string)$claims['sub']);
  }

  return '';
}

function google_provision_user_if_allowed(PDO $pdo, array $config, string $email, string $displayName): ?array
{
  $g = $config['google'] ?? [];
  if (($g['auto_provision'] ?? false) !== true) {
    return null;
  }

  $normalized = normalize_email($email);
  $allowedRaw = strtolower(trim((string)($g['allowed_domain'] ?? '')));
  $allowedDomains = array_values(array_filter(array_map(static fn(string $d): string => trim($d), preg_split('/[,;\s]+/', $allowedRaw ?: '') ?: [])));
  if ($allowedDomains !== []) {
    $atPos = strrpos($normalized, '@');
    $domain = $atPos === false ? '' : substr($normalized, $atPos + 1);
    if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
      return null;
    }
  }

  $tenantCode = trim((string)($g['default_tenant_code'] ?? ''));
  $tenantStmt = $pdo->prepare('SELECT id FROM tenants WHERE code = ? AND is_active = 1 LIMIT 1');
  $tenantStmt->execute([$tenantCode]);
  $tenant = $tenantStmt->fetch();
  if (!$tenant) {
    return null;
  }

  $role = trim((string)($g['default_role'] ?? 'student'));
  if (!in_array($role, ['student', 'admin', 'manager', 'it'], true)) {
    $role = 'student';
  }

  $fullName = trim($displayName);
  if ($fullName === '') {
    $fullName = strstr($normalized, '@', true) ?: 'Google User';
  }

  $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
  if ($passwordHash === false) {
    return null;
  }

  $insert = $pdo->prepare('INSERT INTO users (tenant_id, full_name, email, password_hash, role, is_active, email_verified_at) VALUES (?, ?, ?, ?, ?, 1, NOW())');
  $insert->execute([
    (int)$tenant['id'],
    $fullName,
    $normalized,
    $passwordHash,
    $role,
  ]);

  $createdId = (int)$pdo->lastInsertId();
  $registerUpdate = $pdo->prepare('UPDATE users SET register_id = ? WHERE id = ?');
  $registerUpdate->execute([$createdId, $createdId]);
  $select = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash, email_verified_at FROM users WHERE id = ? LIMIT 1');
  $select->execute([$createdId]);
  $newUser = $select->fetch();
  return $newUser ?: null;
}

function normalize_form_schema(mixed $schema): array
{
  if (!is_array($schema)) {
    return [];
  }

  $allowedTypes = ['text', 'textarea', 'number', 'email', 'date', 'time', 'select', 'radio', 'checkbox', 'phone', 'linear_scale', 'welcome', 'agreement', 'section', 'form', 'thank_you'];
  $nodeTypes = ['welcome', 'agreement', 'section', 'form', 'thank_you'];
  $normalized = [];
  $nodeCounter = 0;

  foreach ($schema as $field) {
    if (!is_array($field)) {
      continue;
    }

    $name = trim((string)($field['name'] ?? ''));
    $label = trim((string)($field['label'] ?? ''));
    $type = trim((string)($field['type'] ?? 'text'));
    $required = (bool)($field['required'] ?? false);

    if (!in_array($type, $allowedTypes, true)) {
      $type = 'text';
    }

    $isNode = in_array($type, $nodeTypes, true);

    if ($isNode) {
      $nodeCounter++;
      if ($label === '') {
        $label = ucfirst(str_replace('_', ' ', $type)) . ' Section';
      }
      if ($name === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $name)) {
        $name = 'node_' . (string)$nodeCounter;
      }
    } elseif ($name === '' || $label === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $name)) {
      continue;
    }

    $normalizedField = [
      'name' => $name,
      'label' => $label,
      'type' => $type,
      'required' => $required,
    ];

    $helpText = trim((string)($field['help_text'] ?? ''));
    $contentHtml = sanitize_simple_rich_html((string)($field['content_html'] ?? ''));
    if ($contentHtml === '' && $helpText !== '') {
      $contentHtml = sanitize_simple_rich_html($helpText);
    }
    if ($contentHtml !== '') {
      $normalizedField['content_html'] = $contentHtml;
      $normalizedField['help_text'] = $contentHtml;
    }

    if ($isNode) {
      $imagePath = trim((string)($field['image_path'] ?? $field['image_url'] ?? ''));
      if ($imagePath !== '') {
        $normalizedField['image_path'] = ltrim($imagePath, '/');
      }

      $nextNode = trim((string)($field['next_node'] ?? $field['jump_to'] ?? ''));
      if ($nextNode !== '' && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $nextNode) && $nextNode !== $name) {
        $normalizedField['next_node'] = $nextNode;
      }

      $branchIf = $field['branch_if'] ?? null;
      if (is_array($branchIf)) {
        $branchField = trim((string)($branchIf['field'] ?? ''));
        $branchOperator = trim((string)($branchIf['operator'] ?? ''));
        $branchValue = trim((string)($branchIf['value'] ?? ''));
        $branchTarget = trim((string)($branchIf['target_node'] ?? $branchIf['target'] ?? ''));
        $allowedBranchOperators = ['equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte'];
        if (
          $branchField !== ''
          && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $branchField)
          && in_array($branchOperator, $allowedBranchOperators, true)
          && $branchValue !== ''
          && $branchTarget !== ''
          && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $branchTarget)
          && $branchTarget !== $name
        ) {
          $normalizedField['branch_if'] = [
            'field' => $branchField,
            'operator' => $branchOperator,
            'value' => $branchValue,
            'target_node' => $branchTarget,
          ];
        }
      }

      if ($type === 'agreement') {
        $normalizedField['required'] = ($field['required'] ?? true) !== false;
        $agreementMode = trim((string)($field['agreement_mode'] ?? 'text'));
        if (!in_array($agreementMode, ['text', 'pdf'], true)) {
          $agreementMode = 'text';
        }
        $normalizedField['agreement_mode'] = $agreementMode;

        $agreementPdfPath = trim((string)($field['agreement_pdf_path'] ?? $field['agreement_pdf_url'] ?? ''));
        if ($agreementPdfPath !== '') {
          $normalizedField['agreement_pdf_path'] = ltrim($agreementPdfPath, '/');
        }
      } else {
        $normalizedField['required'] = false;
      }
      $normalized[] = $normalizedField;
      continue;
    }

    if (in_array($type, ['text', 'textarea'], true)) {
      $textRule = normalize_text_rule((string)($field['text_rule'] ?? ''));
      if ($textRule !== '') {
        $normalizedField['text_rule'] = $textRule;
      }

      $minLength = (int)($field['min_length'] ?? 0);
      $maxLength = (int)($field['max_length'] ?? 0);
      if ($minLength > 0) {
        $normalizedField['min_length'] = $minLength;
      }
      if ($maxLength > 0) {
        $normalizedField['max_length'] = max($maxLength, $minLength > 0 ? $minLength : 1);
      }

      $regexPattern = trim((string)($field['regex_pattern'] ?? ''));
      if ($regexPattern !== '') {
        $regexIsValid = @preg_match($regexPattern, 'test');
        if ($regexIsValid !== false) {
          $normalizedField['regex_pattern'] = $regexPattern;
        }
      }
    }

    if (in_array($type, ['number', 'linear_scale'], true)) {
      $minValueRaw = trim((string)($field['min_value'] ?? ''));
      $maxValueRaw = trim((string)($field['max_value'] ?? ''));
      if ($minValueRaw !== '' && is_numeric($minValueRaw)) {
        $normalizedField['min_value'] = (float)$minValueRaw;
      }
      if ($maxValueRaw !== '' && is_numeric($maxValueRaw)) {
        $maxValue = (float)$maxValueRaw;
        if (isset($normalizedField['min_value']) && $maxValue < (float)$normalizedField['min_value']) {
          $maxValue = (float)$normalizedField['min_value'];
        }
        $normalizedField['max_value'] = $maxValue;
      }

      if ($type === 'linear_scale') {
        if (!isset($normalizedField['min_value'])) {
          $normalizedField['min_value'] = 1;
        }
        if (!isset($normalizedField['max_value'])) {
          $normalizedField['max_value'] = 5;
        }
      }
    }

    if ($type === 'date') {
      $minDate = trim((string)($field['min_value'] ?? ''));
      $maxDate = trim((string)($field['max_value'] ?? ''));
      if ($minDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
        $normalizedField['min_value'] = $minDate;
      }
      if ($maxDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $maxDate)) {
        if (isset($normalizedField['min_value']) && strcmp($maxDate, (string)$normalizedField['min_value']) < 0) {
          $maxDate = (string)$normalizedField['min_value'];
        }
        $normalizedField['max_value'] = $maxDate;
      }
    }

    if ($type === 'time') {
      $minTime = trim((string)($field['min_value'] ?? ''));
      $maxTime = trim((string)($field['max_value'] ?? ''));
      if ($minTime !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $minTime)) {
        $normalizedField['min_value'] = $minTime;
      }
      if ($maxTime !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $maxTime)) {
        if (isset($normalizedField['min_value']) && strcmp($maxTime, (string)$normalizedField['min_value']) < 0) {
          $maxTime = (string)$normalizedField['min_value'];
        }
        $normalizedField['max_value'] = $maxTime;
      }
    }

    if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
      $rawOptions = $field['options'] ?? [];
      $options = [];
      if (is_array($rawOptions)) {
        foreach ($rawOptions as $opt) {
          $option = trim((string)$opt);
          if ($option !== '') {
            $options[] = $option;
          }
        }
      }

      $options = array_values(array_unique($options));
      if ($options === []) {
        continue;
      }
      $normalizedField['options'] = $options;
    }

    if ($type === 'phone') {
      $defaultCountryCode = trim((string)($field['default_country_code'] ?? '+90'));
      if (!preg_match('/^\+[0-9]{1,4}$/', $defaultCountryCode)) {
        $defaultCountryCode = '+90';
      }
      $normalizedField['default_country_code'] = $defaultCountryCode;
      $normalizedField['allow_country_change'] = ($field['allow_country_change'] ?? true) !== false;
      $normalizedField['validation_mode'] = 'country_strict';
    }

    $allowedOperators = ['equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte'];

    $visibleIfGroup = $field['visible_if_group'] ?? null;
    if (is_array($visibleIfGroup)) {
      $groupMode = strtoupper(trim((string)($visibleIfGroup['mode'] ?? 'AND')));
      if (!in_array($groupMode, ['AND', 'OR'], true)) {
        $groupMode = 'AND';
      }

      $rawConditions = $visibleIfGroup['conditions'] ?? [];
      $normalizedConditions = [];
      if (is_array($rawConditions)) {
        foreach ($rawConditions as $condition) {
          if (!is_array($condition)) {
            continue;
          }
          $dependsOn = trim((string)($condition['field'] ?? ''));
          $operator = trim((string)($condition['operator'] ?? ''));
          $value = trim((string)($condition['value'] ?? ''));
          if ($operator === '' && isset($condition['equals'])) {
            $operator = 'equals';
            $value = trim((string)$condition['equals']);
          }
          if (
            $dependsOn !== ''
            && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $dependsOn)
            && in_array($operator, $allowedOperators, true)
            && $value !== ''
            && $dependsOn !== $name
          ) {
            $normalizedConditions[] = [
              'field' => $dependsOn,
              'operator' => $operator,
              'value' => $value,
            ];
          }
          if (count($normalizedConditions) >= 5) {
            break;
          }
        }
      }

      if (count($normalizedConditions) >= 2) {
        $normalizedField['visible_if_group'] = [
          'mode' => $groupMode,
          'conditions' => $normalizedConditions,
        ];
      }
    }

    if (!isset($normalizedField['visible_if_group'])) {
      $visibleIf = $field['visible_if'] ?? null;
      if (is_array($visibleIf)) {
        $dependsOn = trim((string)($visibleIf['field'] ?? ''));
        $operator = trim((string)($visibleIf['operator'] ?? ''));
        $value = trim((string)($visibleIf['value'] ?? ''));
        if ($operator === '' && isset($visibleIf['equals'])) {
          $operator = 'equals';
          $value = trim((string)$visibleIf['equals']);
        }
        if (
          $dependsOn !== ''
          && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $dependsOn)
          && in_array($operator, $allowedOperators, true)
          && $value !== ''
          && $dependsOn !== $name
        ) {
          $normalizedField['visible_if'] = [
            'field' => $dependsOn,
            'operator' => $operator,
            'value' => $value,
          ];
        }
      }
    }

    $normalized[] = $normalizedField;
  }

  return $normalized;
}

function normalize_text_rule(string $textRule): string
{
  $rule = strtolower(trim($textRule));
  if ($rule === 'latin_arabic') {
    $rule = 'english_or_arabic';
  }

  if ($rule === '') {
    return '';
  }

  $allowed = ['none', 'arabic_only', 'english_only', 'turkish_latin_only', 'english_or_arabic'];
  return in_array($rule, $allowed, true) ? $rule : '';
}

function text_matches_rule(string $value, string $textRule): bool
{
  $rule = normalize_text_rule($textRule);
  $trimmed = trim($value);
  if ($trimmed === '' || $rule === '' || $rule === 'none') {
    return true;
  }

  if ($rule === 'arabic_only') {
    return (bool)preg_match("/^[\\p{Arabic}\\p{M}\\s'\\-]+$/u", $trimmed);
  }
  if ($rule === 'english_only') {
    return (bool)preg_match("/^[A-Za-z\\s'\\-]+$/", $trimmed);
  }
  if ($rule === 'turkish_latin_only') {
    return (bool)preg_match("/^[A-Za-zÇĞİÖŞÜçğıöşü\\s'\\-]+$/u", $trimmed);
  }

  return (bool)preg_match("/^[\\p{Latin}\\p{Arabic}\\p{M}\\s'\\-]+$/u", $trimmed);
}

function text_rule_error_message(string $textRule): string
{
  $rule = normalize_text_rule($textRule);
  if ($rule === 'none' || $rule === '') {
    return '';
  }
  if ($rule === 'arabic_only') {
    return 'This field accepts Arabic letters only.';
  }
  if ($rule === 'english_only') {
    return 'This field accepts English letters only.';
  }
  if ($rule === 'turkish_latin_only') {
    return 'This field accepts Turkish Latin letters only.';
  }
  if ($rule === 'english_or_arabic') {
    return 'This field accepts English or Arabic letters only.';
  }
  return 'Invalid text format.';
}

function form_schema_text_rules(array $schema): array
{
  $rules = [];
  foreach ($schema as $field) {
    if (!is_array($field)) {
      continue;
    }
    $fieldName = trim((string)($field['name'] ?? ''));
    if ($fieldName === '') {
      continue;
    }
    $rule = normalize_text_rule((string)($field['text_rule'] ?? ''));
    if ($rule === '') {
      continue;
    }
    $rules[$fieldName] = $rule;
  }
  return $rules;
}

function field_is_visible(array $field, array $answers): bool
{
  $evaluateSingleCondition = static function (array $condition, array $answers): bool {
    $dependsOn = trim((string)($condition['field'] ?? ''));
    $operator = trim((string)($condition['operator'] ?? ''));
    $value = trim((string)($condition['value'] ?? ''));
    if ($operator === '' && isset($condition['equals'])) {
      $operator = 'equals';
      $value = trim((string)$condition['equals']);
    }
    if ($dependsOn === '' || $operator === '' || $value === '') {
      return true;
    }

    $actual = $answers[$dependsOn] ?? null;
    if (is_array($actual)) {
      $actualValues = array_map('strval', $actual);
      if ($operator === 'contains') {
        return in_array($value, $actualValues, true);
      }
      if ($operator === 'not_equals') {
        return !in_array($value, $actualValues, true);
      }
      if ($operator === 'equals') {
        return in_array($value, $actualValues, true);
      }
      return false;
    }

    $actualStr = trim((string)$actual);
    $compare = static function (string $left, string $right): int {
      if (is_numeric($left) && is_numeric($right)) {
        $leftNum = (float)$left;
        $rightNum = (float)$right;
        if ($leftNum < $rightNum) {
          return -1;
        }
        if ($leftNum > $rightNum) {
          return 1;
        }
        return 0;
      }

      $isDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $left) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $right);
      $isTime = (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $left) && (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $right);
      if ($isDate || $isTime) {
        return strcmp($left, $right);
      }

      return strcmp($left, $right);
    };

    switch ($operator) {
      case 'equals':
        return $actualStr === $value;
      case 'not_equals':
        return $actualStr !== $value;
      case 'contains':
        return $actualStr !== '' && mb_stripos($actualStr, $value) !== false;
      case 'gt':
      case 'gte':
      case 'lt':
      case 'lte':
        $cmp = $compare($actualStr, $value);
        if ($operator === 'gt') {
          return $cmp > 0;
        }
        if ($operator === 'gte') {
          return $cmp >= 0;
        }
        if ($operator === 'lt') {
          return $cmp < 0;
        }
        return $cmp <= 0;
      default:
        return true;
    }
  };

  $visibleIfGroup = $field['visible_if_group'] ?? null;
  if (is_array($visibleIfGroup) && is_array($visibleIfGroup['conditions'] ?? null)) {
    $groupMode = strtoupper(trim((string)($visibleIfGroup['mode'] ?? 'AND')));
    if (!in_array($groupMode, ['AND', 'OR'], true)) {
      $groupMode = 'AND';
    }

    $checks = [];
    foreach ($visibleIfGroup['conditions'] as $condition) {
      if (!is_array($condition)) {
        continue;
      }
      $checks[] = $evaluateSingleCondition($condition, $answers);
    }

    if ($checks !== []) {
      if ($groupMode === 'OR') {
        return in_array(true, $checks, true);
      }
      return !in_array(false, $checks, true);
    }
  }

  $visibleIf = $field['visible_if'] ?? null;
  if (!is_array($visibleIf)) {
    return true;
  }
  return $evaluateSingleCondition($visibleIf, $answers);
}

function render_dynamic_field(array $field, array $old = [], array $phoneCountries = []): void
{
  $name = (string)$field['name'];
  $label = (string)$field['label'];
  $type = (string)$field['type'];
  $helpText = trim((string)($field['help_text'] ?? ''));
  $required = (bool)$field['required'];
  $value = $old[$name] ?? '';
  $requiredAttr = $required ? ' required' : '';
  $minValueAttr = isset($field['min_value']) ? ' min="' . h((string)$field['min_value']) . '"' : '';
  $maxValueAttr = isset($field['max_value']) ? ' max="' . h((string)$field['max_value']) . '"' : '';
  $minLengthAttr = isset($field['min_length']) ? ' minlength="' . h((string)$field['min_length']) . '"' : '';
  $maxLengthAttr = isset($field['max_length']) ? ' maxlength="' . h((string)$field['max_length']) . '"' : '';
  $patternAttr = isset($field['regex_pattern']) ? ' pattern="' . h((string)$field['regex_pattern']) . '"' : '';
  $visibleIf = is_array($field['visible_if'] ?? null) ? $field['visible_if'] : null;
  $visibleIfGroup = is_array($field['visible_if_group'] ?? null) ? $field['visible_if_group'] : null;
  $visibleIfField = $visibleIf ? (string)($visibleIf['field'] ?? '') : '';
  $visibleIfOperator = $visibleIf ? (string)($visibleIf['operator'] ?? '') : '';
  $visibleIfValue = $visibleIf ? (string)($visibleIf['value'] ?? '') : '';
  $visibleIfGroupJson = $visibleIfGroup ? (string)json_encode($visibleIfGroup, JSON_UNESCAPED_UNICODE) : '';
  if ($visibleIfOperator === '' && $visibleIf && isset($visibleIf['equals'])) {
    $visibleIfOperator = 'equals';
    $visibleIfValue = (string)$visibleIf['equals'];
  }
  $textRule = '';
  $nodeContentHtml = trim((string)($field['content_html'] ?? $helpText));
  $nodeImagePath = trim((string)($field['image_path'] ?? ''));
  $nodeNext = trim((string)($field['next_node'] ?? ''));
  $nodeBranchIf = is_array($field['branch_if'] ?? null) ? $field['branch_if'] : null;
  $nodeBranchField = $nodeBranchIf ? trim((string)($nodeBranchIf['field'] ?? '')) : '';
  $nodeBranchOperator = $nodeBranchIf ? trim((string)($nodeBranchIf['operator'] ?? '')) : '';
  $nodeBranchValue = $nodeBranchIf ? trim((string)($nodeBranchIf['value'] ?? '')) : '';
  $nodeBranchTarget = $nodeBranchIf ? trim((string)($nodeBranchIf['target_node'] ?? '')) : '';
  $nodeAgreementMode = trim((string)($field['agreement_mode'] ?? 'text'));
  $nodeAgreementPdfPath = trim((string)($field['agreement_pdf_path'] ?? ''));
  if (in_array($type, ['text', 'textarea'], true)) {
    if (array_key_exists('text_rule', $field)) {
      $textRule = normalize_text_rule((string)$field['text_rule']);
    } else {
      $textRule = 'english_or_arabic';
    }
    if ($textRule === '') {
      $textRule = 'english_or_arabic';
    }
  }

  echo '<div class="dynamic-field" data-field-name="' . h($name) . '" data-field-type="' . h($type) . '" data-text-rule="' . h($textRule) . '" data-visible-if-field="' . h($visibleIfField) . '" data-visible-if-operator="' . h($visibleIfOperator) . '" data-visible-if-value="' . h($visibleIfValue) . '" data-visible-if-group="' . h($visibleIfGroupJson) . '" data-next-node="' . h($nodeNext) . '" data-branch-if-field="' . h($nodeBranchField) . '" data-branch-if-operator="' . h($nodeBranchOperator) . '" data-branch-if-value="' . h($nodeBranchValue) . '" data-branch-target-node="' . h($nodeBranchTarget) . '">';

  if ($type === 'welcome' || $type === 'section' || $type === 'form' || $type === 'thank_you') {
    echo '<div class="form-node form-node-' . h($type) . '">';
    echo '<h4>' . h($label) . '</h4>';
    if ($nodeImagePath !== '') {
      echo '<p><img src="' . h(app_asset($nodeImagePath)) . '" alt="' . h($label) . '" style="max-width:100%; border-radius:8px;"></p>';
    }
    if ($nodeContentHtml !== '') {
      echo '<div>' . $nodeContentHtml . '</div>';
    }
    echo '</div>';
    echo '</div>';
    return;
  }

  if ($type === 'agreement') {
    $checked = trim((string)($_POST['agreements'][$name] ?? '')) === '1' ? ' checked' : '';
    echo '<div class="form-node form-node-agreement">';
    echo '<h4>' . h($label) . '</h4>';
    if ($nodeImagePath !== '') {
      echo '<p><img src="' . h(app_asset($nodeImagePath)) . '" alt="' . h($label) . '" style="max-width:100%; border-radius:8px;"></p>';
    }
    if ($nodeAgreementMode === 'pdf' && $nodeAgreementPdfPath !== '') {
      $pdfUrl = app_asset($nodeAgreementPdfPath);
      echo '<p><a class="btn" target="_blank" href="' . h($pdfUrl) . '">Open Agreement PDF</a></p>';
      echo '<iframe src="' . h($pdfUrl) . '" style="width:100%; min-height:320px; border:1px solid #dde7f0; border-radius:8px;"></iframe>';
    } elseif ($nodeContentHtml !== '') {
      echo '<div>' . $nodeContentHtml . '</div>';
    }
    echo '<label><input type="checkbox" name="agreements[' . h($name) . ']" value="1"' . $checked . $requiredAttr . '> I agree</label>';
    echo '</div>';
    echo '</div>';
    return;
  }

  echo '<label>' . h($label) . ($required ? ' *' : '') . '</label>';
  if ($type === 'textarea') {
    echo '<textarea name="answers[' . h($name) . ']" rows="4"' . $requiredAttr . '>' . h((string)$value) . '</textarea>';
    echo '</div>';
    return;
  }

  if ($type === 'select') {
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    echo '<select name="answers[' . h($name) . ']"' . $requiredAttr . '>';
    echo '<option value="">Select...</option>';
    foreach ($options as $option) {
      $optionValue = (string)$option;
      $selected = ((string)$value === $optionValue) ? ' selected' : '';
      echo '<option value="' . h($optionValue) . '"' . $selected . '>' . h($optionValue) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    return;
  }

  if ($type === 'radio') {
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    foreach ($options as $option) {
      $optionValue = (string)$option;
      $checked = ((string)$value === $optionValue) ? ' checked' : '';
      echo '<label><input type="radio" name="answers[' . h($name) . ']" value="' . h($optionValue) . '"' . $checked . $requiredAttr . '> ' . h($optionValue) . '</label>';
    }
    echo '</div>';
    return;
  }

  if ($type === 'linear_scale') {
    $minScale = isset($field['min_value']) ? (int)$field['min_value'] : 1;
    $maxScale = isset($field['max_value']) ? (int)$field['max_value'] : 5;
    if ($maxScale < $minScale) {
      $maxScale = $minScale;
    }
    echo '<select name="answers[' . h($name) . ']"' . $requiredAttr . '>';
    echo '<option value="">Select...</option>';
    for ($scaleValue = $minScale; $scaleValue <= $maxScale; $scaleValue++) {
      $selected = ((string)$value === (string)$scaleValue) ? ' selected' : '';
      echo '<option value="' . h((string)$scaleValue) . '"' . $selected . '>' . h((string)$scaleValue) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    return;
  }

  if ($type === 'checkbox') {
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    $selectedValues = is_array($value) ? array_map('strval', $value) : [];
    foreach ($options as $option) {
      $optionValue = (string)$option;
      $checked = in_array($optionValue, $selectedValues, true) ? ' checked' : '';
      echo '<label><input type="checkbox" name="answers[' . h($name) . '][]" value="' . h($optionValue) . '"' . $checked . '> ' . h($optionValue) . '</label>';
    }
    echo '</div>';
    return;
  }

  if ($type === 'phone') {
    $rawPhone = is_array($value) ? $value : [];
    $defaultCode = trim((string)($field['default_country_code'] ?? '+90'));
    $selectedCode = trim((string)($rawPhone['country_code'] ?? $defaultCode));
    $numberValue = trim((string)($rawPhone['number'] ?? ''));
    $allowCountryChange = ($field['allow_country_change'] ?? true) !== false;
    $countryDisabledAttr = $allowCountryChange ? '' : ' disabled';
    $hiddenSelected = !$allowCountryChange
      ? '<input type="hidden" name="answers[' . h($name) . '][country_code]" value="' . h($selectedCode) . '">'
      : '';

    echo '<div class="grid">';
    echo '<div>';
    echo '<label>' . h($label) . ' Country Code' . ($required ? ' *' : '') . '</label>';
    echo '<select name="answers[' . h($name) . '][country_code]"' . $requiredAttr . $countryDisabledAttr . '>';
    echo '<option value="">Select code...</option>';
    foreach ($phoneCountries as $country) {
      $dialCode = trim((string)($country['dial_code'] ?? ''));
      if ($dialCode === '') {
        continue;
      }
      $countryName = trim((string)($country['country_name'] ?? 'Country'));
      $selected = $selectedCode === $dialCode ? ' selected' : '';
      echo '<option value="' . h($dialCode) . '"' . $selected . '>' . h($dialCode . ' - ' . $countryName) . '</option>';
    }
    echo '</select>';
    echo $hiddenSelected;
    echo '</div>';

    echo '<div>';
    echo '<label>' . h($label) . ' Number' . ($required ? ' *' : '') . '</label>';
    echo '<input name="answers[' . h($name) . '][number]" type="text" inputmode="numeric" value="' . h($numberValue) . '"' . $requiredAttr . '>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    return;
  }

  $inputType = in_array($type, ['text', 'number', 'email', 'date', 'time'], true) ? $type : 'text';
  $extraAttrs = '';
  if (in_array($type, ['number', 'date', 'time'], true)) {
    $extraAttrs .= $minValueAttr . $maxValueAttr;
  }
  if (in_array($type, ['text', 'textarea'], true)) {
    $extraAttrs .= $minLengthAttr . $maxLengthAttr . $patternAttr;
  }
  echo '<input name="answers[' . h($name) . ']" type="' . h($inputType) . '" value="' . h((string)$value) . '"' . $requiredAttr . $extraAttrs . '>';
  echo '</div>';
}

function normalize_email(string $email): string
{
  return strtolower(trim($email));
}

function sanitize_simple_rich_html(string $html): string
{
  $trimmed = trim($html);
  if ($trimmed === '') {
    return '';
  }

  $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><blockquote><a>';
  $safe = strip_tags($trimmed, $allowedTags);
  $safe = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\1/iu', '', (string)$safe);
  $safe = preg_replace('/javascript:/iu', '', (string)$safe);
  return trim((string)$safe);
}

function blacklist_match(PDO $pdo, int $registerId, string $email): ?array
{
  $emailNorm = normalize_email($email);

  if (users_blacklist_column_ready($pdo)) {
    $userFlagStmt = $pdo->prepare(
      'SELECT id, ? AS register_id, ? AS email_normalized, ? AS reason
       FROM users
       WHERE blacklist = 1
         AND ((register_id IS NOT NULL AND register_id = ?) OR LOWER(TRIM(email)) = ?)
       ORDER BY id DESC LIMIT 1'
    );
    $userFlagStmt->execute([
      $registerId > 0 ? $registerId : null,
      $emailNorm,
      'User flag blacklist=1',
      $registerId,
      $emailNorm,
    ]);
    $flaggedUser = $userFlagStmt->fetch();
    if ($flaggedUser) {
      return $flaggedUser;
    }
  }

  $stmt = $pdo->prepare(
    'SELECT id, register_id, email_normalized, reason FROM blacklist_entries
     WHERE ((register_id IS NOT NULL AND register_id = ?) OR (email_normalized IS NOT NULL AND email_normalized = ?))
     ORDER BY id DESC LIMIT 1'
  );
  $stmt->execute([$registerId, $emailNorm]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function blacklist_reason_text(?string $reason): string
{
  $trimmed = trim((string)$reason);
  return $trimmed !== '' ? $trimmed : 'No reason provided';
}

function collect_blacklisted_user_ids(PDO $pdo, ?int $registerId, ?string $emailNorm): array
{
  if (!$registerId && !$emailNorm) {
    return [];
  }

  $sql = 'SELECT id FROM users WHERE (';
  $params = [];
  $conds = [];

  if ($registerId) {
    $conds[] = 'register_id = ?';
    $params[] = $registerId;
  }
  if ($emailNorm) {
    $conds[] = 'LOWER(TRIM(email)) = ?';
    $params[] = $emailNorm;
  }

  $sql .= implode(' OR ', $conds) . ')';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return array_map(static fn(array $row): int => (int)$row['id'], $stmt->fetchAll());
}

function reject_inflight_applications(PDO $pdo, int $tenantId, array $userIds, int $actorUserId, string $reason): int
{
  if ($userIds === []) {
    return 0;
  }

  $placeholders = implode(',', array_fill(0, count($userIds), '?'));
  $params = $userIds;

  $select = $pdo->prepare(
    'SELECT id, tenant_id FROM applications
     WHERE student_id IN (' . $placeholders . ')
       AND status IN ("submitted", "in_review")'
  );
  $select->execute($params);
  $rows = $select->fetchAll();
  if ($rows === []) {
    return 0;
  }

  $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
  $tenantByApplicationId = [];
  foreach ($rows as $row) {
    $tenantByApplicationId[(int)$row['id']] = (int)$row['tenant_id'];
  }

  $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
  $updateParams = array_merge([$reason], $ids);
  $update = $pdo->prepare(
    'UPDATE applications SET status = "rejected", rejection_reason = ?
     WHERE id IN (' . $idPlaceholders . ')'
  );
  $update->execute($updateParams);

  $audit = $pdo->prepare('INSERT INTO audit_logs (tenant_id, actor_user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?)');
  foreach ($ids as $applicationId) {
    $audit->execute([
      (int)($tenantByApplicationId[$applicationId] ?? $tenantId),
      $actorUserId,
      'application_rejected_blacklist',
      'application',
      $applicationId,
      json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
    ]);
  }

  return count($ids);
}

function set_users_blacklist_flag(PDO $pdo, array $userIds, int $flag): int
{
  if ($userIds === [] || !users_blacklist_column_ready($pdo)) {
    return 0;
  }

  $flag = $flag === 1 ? 1 : 0;
  $placeholders = implode(',', array_fill(0, count($userIds), '?'));
  $params = array_merge([$flag], $userIds);

  $stmt = $pdo->prepare(
    'UPDATE users
     SET blacklist = ?
     WHERE id IN (' . $placeholders . ')'
  );
  $stmt->execute($params);
  return $stmt->rowCount();
}

function notification_jobs_ready(PDO $pdo): bool
{
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'notification_jobs'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function enqueue_internal_notification(PDO $pdo, array $payload): void
{
  if (!notification_jobs_ready($pdo)) {
    return;
  }

  if (!isset($payload['event_version'])) {
    $payload['event_version'] = '1.0';
  }
  if (!isset($payload['emitted_at'])) {
    $payload['emitted_at'] = gmdate('c');
  }
  if (!isset($payload['notification_type']) || trim((string)$payload['notification_type']) === '') {
    $payload['notification_type'] = 'webhook';
  }
  if (!isset($payload['route']) || trim((string)$payload['route']) === '') {
    $payload['route'] = 'n8n_global';
  }
  if (!isset($payload['correlation_id']) || trim((string)$payload['correlation_id']) === '') {
    try {
      $payload['correlation_id'] = 'evt_' . bin2hex(random_bytes(8));
    } catch (Throwable) {
      $payload['correlation_id'] = 'evt_' . (string)time() . '_' . (string)random_int(1000, 9999);
    }
  }

  $tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : 0;
  if ($tenantId <= 0) {
    $tenantId = null;
  }

  $applicationId = isset($payload['application_id']) ? (int)$payload['application_id'] : 0;
  if ($applicationId <= 0) {
    $applicationId = null;
  }

  $eventName = trim((string)($payload['event'] ?? 'unknown'));
  $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($jsonPayload)) {
    return;
  }

  $insert = $pdo->prepare(
    'INSERT INTO notification_jobs (tenant_id, application_id, event_name, payload_json, status, attempts, max_attempts)
     VALUES (?, ?, ?, ?, "pending", 0, 5)'
  );
  $insert->execute([
    $tenantId,
    $applicationId,
    $eventName,
    $jsonPayload,
  ]);
}

function notification_outbound_target(array $config): array
{
  $endpoint = trim((string)($config['notifications']['outbound_endpoint'] ?? ''));
  $enabled = (($config['notifications']['outbound_enabled'] ?? false) === true) || $endpoint !== '';
  $secret = trim((string)($config['notifications']['outbound_secret'] ?? ''));
  if ($secret === '') {
    $secret = trim((string)($config['notifications']['internal_secret'] ?? ''));
  }

  $timeout = (int)($config['notifications']['outbound_timeout_seconds'] ?? 15);
  $timeout = max(3, min(60, $timeout));

  $route = trim((string)($config['notifications']['outbound_route'] ?? 'n8n_global'));
  if ($route === '') {
    $route = 'n8n_global';
  }

  return [
    'enabled' => $enabled,
    'endpoint' => $endpoint,
    'secret' => $secret,
    'timeout' => $timeout,
    'route' => $route,
  ];
}

function persist_notification_inbox(PDO $pdo, array $payload, int $authValid, string $status, ?string $errorMessage, array $headerSnapshot): void
{
  $tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : 0;
  if ($tenantId <= 0) {
    $tenantId = null;
  } else {
    $tenantExists = $pdo->prepare('SELECT id FROM tenants WHERE id = ? LIMIT 1');
    $tenantExists->execute([$tenantId]);
    if (!$tenantExists->fetch()) {
      $tenantId = null;
    }
  }

  $applicationId = isset($payload['application_id']) ? (int)$payload['application_id'] : 0;
  if ($applicationId <= 0) {
    $applicationId = null;
  } else {
    $appExists = $pdo->prepare('SELECT id FROM applications WHERE id = ? LIMIT 1');
    $appExists->execute([$applicationId]);
    if (!$appExists->fetch()) {
      $applicationId = null;
    }
  }

  $eventName = trim((string)($payload['event'] ?? 'unknown'));
  $notificationType = trim((string)($payload['notification_type'] ?? ''));
  $correlationId = trim((string)($payload['correlation_id'] ?? ''));
  $deliveryRoute = trim((string)($payload['route'] ?? ''));

  $insert = $pdo->prepare(
    'INSERT INTO notification_inbox (tenant_id, application_id, event_name, notification_type, correlation_id, delivery_route, auth_valid, source_ip, user_agent, headers_json, payload_json, status, error_message)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $insert->execute([
    $tenantId,
    $applicationId,
    $eventName,
    $notificationType !== '' ? $notificationType : null,
    $correlationId !== '' ? $correlationId : null,
    $deliveryRoute !== '' ? $deliveryRoute : null,
    $authValid,
    null,
    'internal-worker',
    json_encode($headerSnapshot, JSON_UNESCAPED_UNICODE),
    json_encode($payload, JSON_UNESCAPED_UNICODE),
    $status,
    $errorMessage,
  ]);
}

function dispatch_internal_notification(PDO $pdo, array $config, array $payload): array
{
  $target = notification_outbound_target($config);
  if (!isset($payload['route']) || trim((string)$payload['route']) === '') {
    $payload['route'] = (string)$target['route'];
  }

  if (($target['enabled'] ?? false) === true && (string)($target['endpoint'] ?? '') !== '') {
    if (!function_exists('curl_init')) {
      return ['ok' => false, 'error' => 'curl_not_available'];
    }

    $secret = (string)($target['secret'] ?? '');
    if ($secret === '') {
      return ['ok' => false, 'error' => 'outbound_notification_secret_not_configured'];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
      return ['ok' => false, 'error' => 'payload_json_encode_failed'];
    }

    $timestamp = (string)time();
    $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $json, $secret);
    $eventName = trim((string)($payload['event'] ?? 'unknown'));
    $correlationId = trim((string)($payload['correlation_id'] ?? ''));
    $route = trim((string)($payload['route'] ?? 'n8n_global'));

    $ch = curl_init((string)$target['endpoint']);
    if ($ch === false) {
      return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-TKIF-Timestamp: ' . $timestamp,
        'X-TKIF-Signature: ' . $signature,
        'X-TKIF-Event: ' . $eventName,
        'X-TKIF-Correlation-Id: ' . $correlationId,
        'X-TKIF-Route: ' . $route,
      ],
      CURLOPT_POSTFIELDS => $json,
      CURLOPT_TIMEOUT => (int)($target['timeout'] ?? 15),
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
      $error = curl_error($ch);
      curl_close($ch);
      if (notification_inbox_ready($pdo)) {
        persist_notification_inbox(
          $pdo,
          $payload,
          1,
          'failed',
          $error !== '' ? $error : 'dispatch_failed',
          [
            'delivery_mode' => 'outbound_push',
            'route' => $route,
            'endpoint' => (string)$target['endpoint'],
          ]
        );
      }
      return ['ok' => false, 'error' => $error !== '' ? $error : 'dispatch_failed'];
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
      if (notification_inbox_ready($pdo)) {
        persist_notification_inbox(
          $pdo,
          $payload,
          1,
          'processed',
          null,
          [
            'delivery_mode' => 'outbound_push',
            'route' => $route,
            'endpoint' => (string)$target['endpoint'],
            'http_code' => $httpCode,
          ]
        );
      }
      return ['ok' => true, 'error' => ''];
    }

    if (notification_inbox_ready($pdo)) {
      persist_notification_inbox(
        $pdo,
        $payload,
        1,
        'failed',
        'dispatch_http_' . (string)$httpCode,
        [
          'delivery_mode' => 'outbound_push',
          'route' => $route,
          'endpoint' => (string)$target['endpoint'],
          'http_code' => $httpCode,
        ]
      );
    }

    return ['ok' => false, 'error' => 'dispatch_http_' . (string)$httpCode];
  }

  if (notification_inbox_ready($pdo)) {
    persist_notification_inbox(
      $pdo,
      $payload,
      1,
      'received',
      null,
      [
        'delivery_mode' => 'internal_queue_direct',
      ]
    );
    return ['ok' => true, 'error' => ''];
  }

  $endpoint = trim((string)($config['notifications']['internal_endpoint'] ?? ''));
  if ($endpoint === '') {
    return ['ok' => false, 'error' => 'internal_notification_endpoint_not_configured'];
  }

  $secret = trim((string)($config['notifications']['internal_secret'] ?? ''));
  if ($secret === '') {
    return ['ok' => false, 'error' => 'internal_notification_secret_not_configured'];
  }

  if (!function_exists('curl_init')) {
    return ['ok' => false, 'error' => 'curl_not_available'];
  }

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json)) {
    return ['ok' => false, 'error' => 'payload_json_encode_failed'];
  }

  $timestamp = (string)time();
  $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $json, $secret);

  $ch = curl_init($endpoint);
  if ($ch === false) {
    return ['ok' => false, 'error' => 'curl_init_failed'];
  }

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'X-TKIF-Timestamp: ' . $timestamp,
      'X-TKIF-Signature: ' . $signature,
    ],
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_TIMEOUT => 15,
  ]);

  $response = curl_exec($ch);
  if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    return ['ok' => false, 'error' => $error !== '' ? $error : 'dispatch_failed'];
  }

  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode >= 200 && $httpCode < 300) {
    return ['ok' => true, 'error' => ''];
  }

  return ['ok' => false, 'error' => 'dispatch_http_' . (string)$httpCode];
}

function process_notification_queue(PDO $pdo, array $config, int $limit = 10): array
{
  if (!notification_jobs_ready($pdo)) {
    return ['processed' => 0, 'sent' => 0, 'failed' => 0];
  }

  $limit = max(1, min(100, $limit));
  $select = $pdo->prepare(
    'SELECT id, payload_json, attempts, max_attempts
     FROM notification_jobs
     WHERE status IN ("pending", "failed")
       AND attempts < max_attempts
       AND available_at <= NOW()
     ORDER BY id ASC
     LIMIT :limit'
  );
  $select->bindValue(':limit', $limit, PDO::PARAM_INT);
  $select->execute();
  $jobs = $select->fetchAll();

  $processed = 0;
  $sent = 0;
  $failed = 0;

  foreach ($jobs as $job) {
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0) {
      continue;
    }

    $attempts = (int)($job['attempts'] ?? 0) + 1;
    $lock = $pdo->prepare('UPDATE notification_jobs SET status = "processing", locked_at = NOW() WHERE id = ? AND status IN ("pending", "failed")');
    $lock->execute([$jobId]);
    if ($lock->rowCount() === 0) {
      continue;
    }

    $processed++;
    $payload = json_decode((string)($job['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
      $failed++;
      $markInvalid = $pdo->prepare(
        'UPDATE notification_jobs
         SET status = "failed", attempts = ?, last_error = ?, locked_at = NULL, updated_at = NOW(), available_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
         WHERE id = ?'
      );
      $markInvalid->execute([$attempts, 'invalid_payload_json', $jobId]);
      continue;
    }

    $result = dispatch_internal_notification($pdo, $config, $payload);
    if ($result['ok'] ?? false) {
      $sent++;
      $markSent = $pdo->prepare(
        'UPDATE notification_jobs
         SET status = "sent", attempts = ?, processed_at = NOW(), last_error = NULL, locked_at = NULL, updated_at = NOW()
         WHERE id = ?'
      );
      $markSent->execute([$attempts, $jobId]);
      continue;
    }

    $failed++;
    $delayMinutes = min(30, 1 << min(5, max(0, $attempts - 1)));
    $nextAttemptAt = (new DateTimeImmutable('now'))->add(new DateInterval('PT' . (string)$delayMinutes . 'M'));
    $markFailed = $pdo->prepare(
      'UPDATE notification_jobs
       SET status = "failed", attempts = ?, last_error = ?, available_at = ?, locked_at = NULL, updated_at = NOW()
       WHERE id = ?'
    );
    $markFailed->execute([
      $attempts,
      (string)($result['error'] ?? 'dispatch_failed'),
      $nextAttemptAt->format('Y-m-d H:i:s'),
      $jobId,
    ]);
  }

  return ['processed' => $processed, 'sent' => $sent, 'failed' => $failed];
}

$pdo = null;
$dbError = null;
try {
    $pdo = db($config);
} catch (Throwable $e) {
    $dbError = 'Database not connected yet. Import sql/schema.sql then sql/seed.sql.';
}

$page = $_GET['page'] ?? 'home';
$message = '';
$error = '';
$verifyChallengeMeta = null;
$adminSupportRows = [];
$adminSupportTargetEmail = '';
$adminLoginAttemptRows = [];
$adminLoginAttemptSummary = null;
$blacklistPreview = null;
$blacklistForm = [
  'register_id' => '',
  'email' => '',
  'reason' => '',
];
$formBuilderSelectedTemplate = 'basic_application';
$formBuilderDraftSchema = form_builder_starter_template_json('basic_application');
$formBuilderTemplateMeta = form_builder_starter_template('basic_application');
$formBuilderScholarships = [];
$formBuilderScholarshipId = 0;
$formBuilderScholarshipTitle = '';
$formBuilderScholarshipDescription = '';
$formBuilderScholarshipStatus = 'draft';
$formBuilderEntityType = 'scholarship';
$phoneCodeRows = [];
$phoneCodeEdit = null;
$registerOld = [
  'full_name' => '',
  'email' => '',
  'phone_country_code' => '+90',
  'phone_number' => '',
];
$resetTokenForForm = trim((string)($_GET['token'] ?? ''));

function blacklist_find_user_by_id(PDO $pdo, int $registerId): ?array
{
  if ($registerId <= 0) {
    return null;
  }

  $select = users_blacklist_column_ready($pdo)
    ? 'SELECT id, register_id, tenant_id, full_name, email, role, created_at, blacklist FROM users WHERE register_id = ? LIMIT 1'
    : 'SELECT id, register_id, tenant_id, full_name, email, role, created_at FROM users WHERE register_id = ? LIMIT 1';
  $stmt = $pdo->prepare($select);
  $stmt->execute([$registerId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function blacklist_find_user_by_email(PDO $pdo, string $emailNorm): ?array
{
  if ($emailNorm === '') {
    return null;
  }

  $select = users_blacklist_column_ready($pdo)
    ? 'SELECT id, register_id, tenant_id, full_name, email, role, created_at, blacklist FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1'
    : 'SELECT id, register_id, tenant_id, full_name, email, role, created_at FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1';
  $stmt = $pdo->prepare($select);
  $stmt->execute([$emailNorm]);
  $row = $stmt->fetch();
  return $row ?: null;
}

if ($page === 'notification_worker_run') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
  }

  if (!$pdo) {
    json_response(503, ['ok' => false, 'error' => 'database_unavailable']);
  }

  if (!notification_jobs_ready($pdo)) {
    json_response(503, ['ok' => false, 'error' => 'notification_jobs_table_missing']);
  }

  $workerToken = trim((string)($config['notifications']['worker_token'] ?? ''));
  $providedToken = request_header_value('X-Worker-Token');
  if ($providedToken === '') {
    $providedToken = trim((string)($_POST['token'] ?? ''));
  }

  if ($workerToken !== '' && !hash_equals($workerToken, $providedToken)) {
    json_response(401, ['ok' => false, 'error' => 'invalid_worker_token']);
  }

  $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
  $stats = process_notification_queue($pdo, $config, $limit);
  json_response(200, ['ok' => true, 'stats' => $stats]);
}

if ($page === 'security_retention_run') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
  }

  if (!$pdo) {
    json_response(503, ['ok' => false, 'error' => 'database_unavailable']);
  }

  $retention = is_array($config['security']['retention'] ?? null) ? $config['security']['retention'] : [];
  $workerToken = trim((string)($retention['worker_token'] ?? ''));
  if ($workerToken === '') {
    $workerToken = trim((string)($config['notifications']['worker_token'] ?? ''));
  }

  $providedToken = request_header_value('X-Worker-Token');
  if ($providedToken === '') {
    $providedToken = trim((string)($_POST['token'] ?? ''));
  }

  if ($workerToken !== '' && !hash_equals($workerToken, $providedToken)) {
    json_response(401, ['ok' => false, 'error' => 'invalid_worker_token']);
  }

  $stats = run_security_retention_cleanup($pdo, $config);
  json_response(200, ['ok' => true, 'stats' => $stats]);
}

if ($page === 'notification_inbox_receive') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
  }

  if (!$pdo) {
    json_response(503, ['ok' => false, 'error' => 'database_unavailable']);
  }

  if (!notification_inbox_ready($pdo)) {
    json_response(503, ['ok' => false, 'error' => 'notification_inbox_table_missing']);
  }

  $rawBody = file_get_contents('php://input');
  $rawBody = is_string($rawBody) ? $rawBody : '';

  $payload = json_decode($rawBody, true);
  if (!is_array($payload)) {
    $payload = ['_raw_body' => $rawBody];
  }

  $hmacCheck = notification_hmac_verification($config, $rawBody);
  if (!($hmacCheck['ok'] ?? false)) {
    $failedEventName = trim((string)($payload['event'] ?? 'unknown'));
    $failedNotificationType = trim((string)($payload['notification_type'] ?? ''));
    $failedCorrelationId = trim((string)($payload['correlation_id'] ?? ''));
    $failedDeliveryRoute = trim((string)($payload['route'] ?? ''));
    $failedHeaders = [
      'content_type' => request_header_value('Content-Type'),
      'x_tkif_signature' => request_header_value('X-TKIF-Signature'),
      'x_tkif_timestamp' => request_header_value('X-TKIF-Timestamp'),
    ];

    $failedInsert = $pdo->prepare(
      'INSERT INTO notification_inbox (tenant_id, application_id, event_name, notification_type, correlation_id, delivery_route, auth_valid, source_ip, user_agent, headers_json, payload_json, status, error_message)
       VALUES (NULL, NULL, ?, ?, ?, ?, 0, ?, ?, ?, ?, "failed", ?)'
    );
    $failedInsert->execute([
      $failedEventName,
      $failedNotificationType !== '' ? $failedNotificationType : null,
      $failedCorrelationId !== '' ? $failedCorrelationId : null,
      $failedDeliveryRoute !== '' ? $failedDeliveryRoute : null,
      trim((string)($_SERVER['REMOTE_ADDR'] ?? '')) ?: null,
      trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?: null,
      json_encode($failedHeaders, JSON_UNESCAPED_UNICODE),
      json_encode($payload, JSON_UNESCAPED_UNICODE),
      (string)($hmacCheck['error'] ?? 'invalid_signature'),
    ]);

    json_response(401, ['ok' => false, 'error' => (string)($hmacCheck['error'] ?? 'invalid_signature')]);
  }

  if (array_key_exists('_raw_body', $payload) && count($payload) === 1) {
    json_response(400, ['ok' => false, 'error' => 'invalid_json_payload']);
  }

  $tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : 0;
  if ($tenantId <= 0) {
    $tenantId = null;
  } else {
    $tenantExists = $pdo->prepare('SELECT id FROM tenants WHERE id = ? LIMIT 1');
    $tenantExists->execute([$tenantId]);
    if (!$tenantExists->fetch()) {
      $tenantId = null;
    }
  }

  $applicationId = isset($payload['application_id']) ? (int)$payload['application_id'] : 0;
  if ($applicationId <= 0) {
    $applicationId = null;
  } else {
    $appExists = $pdo->prepare('SELECT id FROM applications WHERE id = ? LIMIT 1');
    $appExists->execute([$applicationId]);
    if (!$appExists->fetch()) {
      $applicationId = null;
    }
  }

  $eventName = trim((string)($payload['event'] ?? 'unknown'));
  $notificationType = trim((string)($payload['notification_type'] ?? ''));
  $correlationId = trim((string)($payload['correlation_id'] ?? ''));
  $deliveryRoute = trim((string)($payload['route'] ?? ''));

  $headerSnapshot = [
    'content_type' => request_header_value('Content-Type'),
    'x_tkif_signature_present' => request_header_value('X-TKIF-Signature') !== '' ? '1' : '0',
    'x_tkif_timestamp_present' => request_header_value('X-TKIF-Timestamp') !== '' ? '1' : '0',
  ];

  $insert = $pdo->prepare(
    'INSERT INTO notification_inbox (tenant_id, application_id, event_name, notification_type, correlation_id, delivery_route, auth_valid, source_ip, user_agent, headers_json, payload_json, status)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, "received")'
  );
  $insert->execute([
    $tenantId,
    $applicationId,
    $eventName,
    $notificationType !== '' ? $notificationType : null,
    $correlationId !== '' ? $correlationId : null,
    $deliveryRoute !== '' ? $deliveryRoute : null,
    trim((string)($_SERVER['REMOTE_ADDR'] ?? '')) ?: null,
    trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?: null,
    json_encode($headerSnapshot, JSON_UNESCAPED_UNICODE),
    json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);

  json_response(202, ['ok' => true, 'notification_id' => (int)$pdo->lastInsertId()]);
}

if ($page === 'dashboard_automation_apply') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
  }

  $workerToken = trim((string)($config['notifications']['worker_token'] ?? ''));
  if ($workerToken === '') {
    $workerToken = trim((string)($config['notifications']['internal_secret'] ?? ''));
  }

  $providedToken = request_header_value('X-Worker-Token');
  if ($providedToken === '') {
    $providedToken = trim((string)($_POST['token'] ?? ''));
  }

  if ($workerToken === '' || !hash_equals($workerToken, $providedToken)) {
    json_response(401, ['ok' => false, 'error' => 'invalid_worker_token']);
  }

  $rawBody = file_get_contents('php://input');
  $rawBody = is_string($rawBody) ? $rawBody : '';
  $payload = json_decode($rawBody, true);
  if (!is_array($payload)) {
    $payload = $_POST;
  }

  $mode = strtolower(trim((string)($payload['mode'] ?? 'register_deploy')));
  if (!in_array($mode, ['register_deploy', 'check', 'apply', 'rollback'], true)) {
    json_response(422, ['ok' => false, 'error' => 'invalid_mode']);
  }

  $taskId = dashboard_automation_safe_task_id((string)($payload['task_id'] ?? 'task_' . (string)time()));
  $workspacePath = dirname(__DIR__);
  $auditDir = dashboard_automation_audit_dir();
  if (!is_dir($auditDir)) {
    @mkdir($auditDir, 0775, true);
  }

  if (!is_dir($auditDir)) {
    json_response(500, ['ok' => false, 'error' => 'audit_dir_unavailable']);
  }

  $stamp = gmdate('Y-m-d_H-i-s');
  $auditLog = $auditDir . '/apply_audit.log';
  $patchFile = trim((string)($payload['patch_file'] ?? ''));
  $patchDiff = (string)($payload['patch_diff'] ?? '');

  if ($mode !== 'register_deploy') {
    if ($patchFile !== '') {
      if (!dashboard_automation_is_safe_patch_file($patchFile, $auditDir)) {
        json_response(422, ['ok' => false, 'error' => 'unsafe_or_missing_patch_file']);
      }
    } elseif (trim($patchDiff) !== '') {
      $patchFile = $auditDir . '/incoming_patch_' . $taskId . '_' . $stamp . '.diff';
      file_put_contents($patchFile, $patchDiff, LOCK_EX);
      if (!dashboard_automation_is_safe_patch_file($patchFile, $auditDir)) {
        json_response(500, ['ok' => false, 'error' => 'failed_to_create_patch_file']);
      }
    } else {
      json_response(422, ['ok' => false, 'error' => 'missing_patch_data']);
    }
  }

  $result = [
    'ok' => true,
    'mode' => $mode,
    'task_id' => $taskId,
    'workspace' => $workspacePath,
    'patch_file' => $patchFile,
  ];

  if (in_array($mode, ['check', 'apply', 'rollback'], true)) {
    $checkOut = [];
    $checkCode = 0;
    $checkCmd = 'cd ' . escapeshellarg($workspacePath) . ' && git apply --check ' . escapeshellarg($patchFile) . ' 2>&1';
    exec($checkCmd, $checkOut, $checkCode);
    $checkOutput = trim(implode("\n", $checkOut));
    $result['preflight_ok'] = ($checkCode === 0);
    $result['preflight_output'] = $checkOutput;

    if ($checkCode !== 0) {
      $result['ok'] = false;
      $result['error'] = 'apply_check_failed';
    } elseif ($mode === 'apply' || $mode === 'rollback') {
      $applyOut = [];
      $applyCode = 0;
      $applyCmd = $mode === 'rollback'
        ? ('cd ' . escapeshellarg($workspacePath) . ' && git apply -R ' . escapeshellarg($patchFile) . ' 2>&1')
        : ('cd ' . escapeshellarg($workspacePath) . ' && git apply ' . escapeshellarg($patchFile) . ' 2>&1');
      exec($applyCmd, $applyOut, $applyCode);
      $applyOutput = trim(implode("\n", $applyOut));
      $result['apply_ok'] = ($applyCode === 0);
      $result['apply_output'] = $applyOutput;
      if ($applyCode !== 0) {
        $result['ok'] = false;
        $result['error'] = $mode === 'rollback' ? 'rollback_failed' : 'apply_failed';
      }
    }
  }

  $auditEntry = [
    'timestamp' => gmdate('c'),
    'event' => 'dashboard_automation_' . $mode,
    'task_id' => $taskId,
    'ok' => (bool)($result['ok'] ?? false),
    'patch_file' => $patchFile,
    'source_ip' => trim((string)($_SERVER['REMOTE_ADDR'] ?? '')),
  ];
  file_put_contents($auditLog, json_encode($auditEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

  if ($pdo && function_exists('notification_inbox_ready') && notification_inbox_ready($pdo)) {
    persist_notification_inbox(
      $pdo,
      [
        'event' => 'dashboard_automation_' . $mode,
        'notification_type' => 'webhook',
        'route' => 'dashboard_automation',
        'task_id' => $taskId,
        'result' => $result,
      ],
      1,
      (bool)($result['ok'] ?? false) ? 'processed' : 'failed',
      (string)($result['error'] ?? ''),
      [
        'content_type' => request_header_value('Content-Type'),
        'x_worker_token_present' => request_header_value('X-Worker-Token') !== '' ? '1' : '0',
      ]
    );
  }

  json_response((bool)($result['ok'] ?? false) ? 200 : 422, ['ok' => (bool)($result['ok'] ?? false), 'result' => $result]);
}

if ($page === 'logout') {
    session_destroy();
  header('Location: ' . app_route('login'));
    exit;
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!captcha_verify_submission($config, $_POST)) {
      $error = 'CAPTCHA verification failed. Please try again.';
      $page = 'login';
    } elseif (!rate_limit_allow_request($pdo, $config, 'login', normalize_email($email))) {
      $error = 'Too many login attempts. Please try again later.';
      $page = 'login';
    } else {
      $lockout = login_lockout_state($pdo, $config, $email);
      if (($lockout['locked'] ?? false) === true) {
        $retryAfterSeconds = max(1, (int)($lockout['retry_after_seconds'] ?? 0));
        $retryAfterMinutes = (int)ceil($retryAfterSeconds / 60);
        $error = 'Account temporarily locked due to failed logins. Try again in about ' . (string)$retryAfterMinutes . ' minute(s).';
        $page = 'login';
      }
    }

    if ($error === '') {
    
    
    $user = find_active_user_by_email($pdo, $email);

    if ($user && password_verify($password, $user['password_hash'])) {
      if (email_verification_user_needs_verification($config, $user)) {
        $_SESSION['pending_email_verification_user_id'] = (int)$user['id'];
        $_SESSION['pending_email_verification_email'] = (string)$user['email'];

        $issue = email_verification_issue($pdo, $config, $user, app_route('verify_email'));
        if (($issue['ok'] ?? false) === true) {
          if (($issue['method'] ?? 'code') === 'code') {
            $message = 'Please verify your email with the code we sent.';
          } else {
            $message = 'Please verify your email using the link we sent.';
          }
        } else {
          $error = (string)($issue['reason'] ?? 'Please verify your email before login.');
        }

        $page = 'verify_email';
      } else {
        ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['full_name']);
        set_user_session($user);
        log_login_attempt($pdo, $config, $email, true);
        header('Location: ' . app_route('dashboard'));
        exit;
      }
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
      log_login_attempt($pdo, $config, $email, false);
      $error = 'Invalid credentials';
    } else {
      log_login_attempt($pdo, $config, $email, true);
    }
    }
}

if ($page === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $candidateEmail = normalize_email((string)($_POST['email'] ?? ''));

    if (!captcha_verify_submission($config, $_POST)) {
      $error = 'CAPTCHA verification failed. Please try again.';
      $page = 'register';
    } elseif (!rate_limit_allow_request($pdo, $config, 'register', $candidateEmail)) {
      $error = 'Too many registration attempts. Please try again later.';
      $page = 'register';
    } else {

    if (($config['registration']['enabled'] ?? true) !== true) {
      $error = 'Registration is currently disabled.';
      $page = 'login';
    } else {
      $fullName = trim((string)($_POST['full_name'] ?? ''));
      $email = normalize_email((string)($_POST['email'] ?? ''));
      $phoneCountryCode = trim((string)($_POST['phone_country_code'] ?? ''));
      $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      $confirmPassword = (string)($_POST['confirm_password'] ?? '');

      $registerOld = [
        'full_name' => $fullName,
        'email' => $email,
        'phone_country_code' => $phoneCountryCode,
        'phone_number' => $phoneNumber,
      ];

      if ($fullName === '') {
        $error = 'Full name is required.';
      } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Valid email is required.';
      } elseif (password_strength_error($password) !== null) {
        $error = (string)password_strength_error($password);
        } elseif (!phone_country_codes_ready($pdo)) {
          $error = 'Phone country code list is not ready. Run latest migrations.';
        } else {
          $phoneValidation = phone_validate_input($pdo, $phoneCountryCode, $phoneNumber, true);
          if (!($phoneValidation['ok'] ?? false)) {
            $error = (string)($phoneValidation['error'] ?? 'Invalid phone details.');
          }
        }

        if ($error === '') {
        $passwordError = password_strength_error($password);
        if ($passwordError !== null) {
          $error = $passwordError;
        } elseif (!hash_equals($password, $confirmPassword)) {
          $error = 'Password confirmation does not match.';
        } elseif (user_email_exists($pdo, $email)) {
          $error = 'This email is already registered.';
        } else {
          $tenantId = resolve_registration_tenant_id($pdo, $config);
          if (!$tenantId) {
            $error = 'No active tenant is available for registration.';
          } else {
            $role = trim((string)($config['registration']['default_role'] ?? 'student'));
            if (!in_array($role, ['student', 'admin', 'manager', 'it'], true)) {
              $role = 'student';
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
              $error = 'Unable to process registration password.';
            } else {
              $emailVerifiedAt = email_verification_enabled($config) ? null : date('Y-m-d H:i:s');
              $insert = $pdo->prepare('INSERT INTO users (tenant_id, full_name, email, password_hash, role, is_active, email_verified_at) VALUES (?, ?, ?, ?, ?, 1, ?)');
              $insert->execute([
                $tenantId,
                $fullName,
                $email,
                $passwordHash,
                $role,
                $emailVerifiedAt,
              ]);

              $newUserId = (int)$pdo->lastInsertId();
              $registerUpdate = $pdo->prepare('UPDATE users SET register_id = ? WHERE id = ?');
              $registerUpdate->execute([$newUserId, $newUserId]);
              $userStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash, email_verified_at FROM users WHERE id = ? LIMIT 1');
              $userStmt->execute([$newUserId]);
              $newUser = $userStmt->fetch();
              if (!$newUser) {
                $error = 'Registration succeeded but login bootstrap failed.';
              } else {
                ensure_user_profile_exists($pdo, (int)$newUser['id'], (string)$newUser['full_name']);

                if (isset($phoneValidation) && ($phoneValidation['ok'] ?? false)) {
                  $normalizedPhone = (string)($phoneValidation['number'] ?? '');
                  $updateProfile = $pdo->prepare('UPDATE user_profiles SET phone_country_code = ?, phone_number = ? WHERE user_id = ?');
                  $updateProfile->execute([$phoneCountryCode, $normalizedPhone, (int)$newUser['id']]);
                }

                if (email_verification_enabled($config)) {
                  $_SESSION['pending_email_verification_user_id'] = (int)$newUser['id'];
                  $_SESSION['pending_email_verification_email'] = (string)$newUser['email'];

                  $issue = email_verification_issue($pdo, $config, $newUser, app_route('verify_email'));
                  if (($issue['ok'] ?? false) === true) {
                    if (($issue['method'] ?? 'code') === 'code') {
                      $message = 'Account created. Enter the verification code sent to your email.';
                    } else {
                      $message = 'Account created. Use the verification link sent to your email.';
                    }
                  } else {
                    $reason = trim((string)($issue['reason'] ?? ''));
                    if ($reason !== '') {
                      $error = 'Account created, but verification email could not be sent: ' . $reason . '. Use resend below.';
                    } else {
                      $error = 'Account created, but verification email could not be sent yet. Use resend below.';
                    }
                  }

                  $page = 'verify_email';
                } else {
                  set_user_session($newUser);
                  header('Location: ' . app_route('dashboard'));
                  exit;
                }
              }
            }
          }
        }
        }
      if ($page !== 'verify_email') {
        $page = 'register';
      }
    }
    }
}

if ($page === 'forgot_password' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();

  $email = normalize_email((string)($_POST['email'] ?? ''));
  $passwordResetEnabled = ($config['password_reset']['enabled'] ?? true) === true;

  if (!$passwordResetEnabled || !password_resets_ready($pdo) || !smtp_is_ready($config)) {
    $error = 'Password reset is currently unavailable.';
    $page = 'forgot_password';
  } elseif (!captcha_verify_submission($config, $_POST)) {
    $error = 'CAPTCHA verification failed. Please try again.';
    $page = 'forgot_password';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $message = 'If an account exists for that email, a reset link has been sent.';
    $page = 'login';
  } elseif (!rate_limit_allow_request($pdo, $config, 'reset_request', $email)) {
    $error = 'Too many reset requests. Please try again later.';
    $page = 'forgot_password';
  } else {
    $user = find_active_user_by_email($pdo, $email);
    if ($user) {
      $resetToken = bin2hex(random_bytes(32));
      $resetHash = hash('sha256', $resetToken);
      $ttlMinutes = max(5, (int)($config['password_reset']['ttl_minutes'] ?? 30));
      $expiresAt = (new DateTimeImmutable('now'))->add(new DateInterval('PT' . (string)$ttlMinutes . 'M'));

      $insert = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
      $insert->execute([
        (int)$user['id'],
        $resetHash,
        $expiresAt->format('Y-m-d H:i:s'),
      ]);

      $resetUrl = app_route('reset_password') . '&token=' . rawurlencode($resetToken);
      send_smtp_mail(
        $config,
        (string)$user['email'],
        (string)$user['full_name'],
        'Reset Your Password',
        '<p>We received a request to reset your password.</p>'
        . '<p><a href="' . e($resetUrl) . '">Click here to reset your password</a></p>'
        . '<p>This link expires in ' . (string)$ttlMinutes . ' minutes.</p>'
        . '<p>If you did not request this, you can safely ignore this email.</p>'
      );
    }

    $message = 'If an account exists for that email, a reset link has been sent.';
    $page = 'login';
  }
}

if ($page === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();

  $token = trim((string)($_POST['token'] ?? ''));
  $resetTokenForForm = $token;
  $password = (string)($_POST['password'] ?? '');
  $confirmPassword = (string)($_POST['confirm_password'] ?? '');
  $passwordResetEnabled = ($config['password_reset']['enabled'] ?? true) === true;

  if (!$passwordResetEnabled || !password_resets_ready($pdo)) {
    $error = 'Password reset is currently unavailable.';
    $page = 'forgot_password';
  } elseif (!captcha_verify_submission($config, $_POST)) {
    $error = 'CAPTCHA verification failed. Please try again.';
    $page = 'reset_password';
  } elseif ($token === '') {
    $error = 'Reset token is missing.';
    $page = 'forgot_password';
  } elseif (!rate_limit_allow_request($pdo, $config, 'reset_submit', substr($token, 0, 32))) {
    $error = 'Too many reset attempts. Please try again later.';
    $page = 'reset_password';
  } else {
    $passwordError = password_strength_error($password);
    if ($passwordError !== null) {
      $error = $passwordError;
      $page = 'reset_password';
    } elseif (!hash_equals($password, $confirmPassword)) {
      $error = 'Password confirmation does not match.';
      $page = 'reset_password';
    } else {
      $tokenHash = hash('sha256', $token);
      $resetStmt = $pdo->prepare(
        'SELECT pr.id, pr.user_id
         FROM password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ?
           AND pr.consumed_at IS NULL
           AND pr.expires_at >= NOW()
           AND u.is_active = 1
         LIMIT 1'
      );
      $resetStmt->execute([$tokenHash]);
      $resetRow = $resetStmt->fetch();

      if (!$resetRow) {
        $error = 'Reset link is invalid or expired.';
        $page = 'forgot_password';
      } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
          $error = 'Unable to update password right now.';
          $page = 'reset_password';
        } else {
          $pdo->beginTransaction();
          try {
            $updateUser = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updateUser->execute([$passwordHash, (int)$resetRow['user_id']]);

            $consumeOne = $pdo->prepare('UPDATE password_resets SET consumed_at = NOW() WHERE id = ?');
            $consumeOne->execute([(int)$resetRow['id']]);

            $consumeAll = $pdo->prepare('UPDATE password_resets SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');
            $consumeAll->execute([(int)$resetRow['user_id']]);

            $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $emailStmt->execute([(int)$resetRow['user_id']]);
            $resetUserEmail = (string)($emailStmt->fetchColumn() ?: '');
            if ($resetUserEmail !== '') {
              clear_login_attempts_for_email($pdo, $resetUserEmail);
            }

            $pdo->commit();
            $message = 'Password updated successfully. Please login with your new password.';
            $page = 'login';
          } catch (Throwable) {
            if ($pdo->inTransaction()) {
              $pdo->rollBack();
            }
            $error = 'Unable to update password right now.';
            $page = 'reset_password';
          }
        }
      }
    }
  }
}

if ($page === 'reset_password' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  if ($resetTokenForForm === '') {
    $error = 'Reset link is invalid or incomplete.';
    $page = 'forgot_password';
  }
}

if ($page === 'verify_email' && isset($_GET['token']) && $pdo) {
  $token = trim((string)($_GET['token'] ?? ''));
  if ($token === '') {
    $error = 'Verification token is missing.';
  } else {
    $verifiedUserId = email_verification_verify_token($pdo, $token);
    if ($verifiedUserId === null) {
      $error = 'Verification link is invalid or expired.';
    } else {
      $user = find_active_user_by_id($pdo, $verifiedUserId);
      if (!$user) {
        $error = 'Account is unavailable.';
      } else {
        ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['full_name']);
        set_user_session($user);
        unset($_SESSION['pending_email_verification_user_id'], $_SESSION['pending_email_verification_email']);
        header('Location: ' . app_route('dashboard'));
        exit;
      }
    }
  }
}

if ($page === 'verify_email' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $pdo) {
  require_csrf();
  $pendingUserId = (int)($_SESSION['pending_email_verification_user_id'] ?? 0);

  if ($pendingUserId <= 0) {
    $error = 'No pending verification request. Please login again.';
    $page = 'login';
  } else {
    $pendingUser = find_active_user_by_id($pdo, $pendingUserId);
    if (!$pendingUser) {
      $error = 'Account is unavailable.';
      $page = 'login';
    } else {
      $action = trim((string)($_POST['action'] ?? 'verify_code'));
      if ($action === 'resend') {
        if (!rate_limit_allow_request($pdo, $config, 'verify_resend', (string)$pendingUser['email'])) {
          $error = 'Too many resend requests. Please wait before trying again.';
        } else {
        $issue = email_verification_issue($pdo, $config, $pendingUser, app_route('verify_email'));
        if (($issue['ok'] ?? false) === true) {
          $message = (($issue['method'] ?? 'code') === 'code')
            ? 'A new verification code has been sent.'
            : 'A new verification link has been sent.';
        } else {
          $error = (string)($issue['reason'] ?? 'Unable to resend verification right now.');
        }
        }
      } else {
        if (!rate_limit_allow_request($pdo, $config, 'verify_code', (string)$pendingUser['email'])) {
          $error = 'Too many verification attempts. Please try again later.';
        } else {
        if (email_verification_method($config) !== 'code') {
          $error = 'Use the verification link sent to your email.';
        } else {
          $code = trim((string)($_POST['verification_code'] ?? ''));
          if ($code === '') {
            $error = 'Enter the verification code.';
          } elseif (!email_verification_verify_code($pdo, $pendingUserId, $code)) {
            $error = 'Invalid or expired verification code.';
          } else {
            $user = find_active_user_by_id($pdo, $pendingUserId);
            if (!$user) {
              $error = 'Account is unavailable.';
              $page = 'login';
            } else {
              ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['full_name']);
              set_user_session($user);
              unset($_SESSION['pending_email_verification_user_id'], $_SESSION['pending_email_verification_email']);
              header('Location: ' . app_route('dashboard'));
              exit;
            }
          }
        }
        }
      }
      $page = 'verify_email';
    }
  }
}

  if ($page === 'login_otp_request' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $email = trim((string)($_POST['email'] ?? ''));

    if (!rate_limit_allow_request($pdo, $config, 'otp_request', normalize_email($email))) {
      $error = 'Too many OTP requests. Please try again later.';
    } elseif (!otp_ready($config)) {
      $error = 'OTP login is not configured yet.';
    } else {
      $user = find_active_user_by_email($pdo, $email);
      if ($user) {
        issue_email_otp($pdo, $config, $user);
        $_SESSION['otp_user_id'] = (int)$user['id'];
        $_SESSION['otp_user_email'] = (string)$user['email'];
      }
      $message = 'If the account exists, an OTP code has been sent to email.';
    }
    $page = 'login';
  }

  if ($page === 'login_otp_verify' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $code = trim((string)($_POST['otp_code'] ?? ''));
    $otpUserId = (int)($_SESSION['otp_user_id'] ?? 0);
    $otpUserEmail = trim((string)($_SESSION['otp_user_email'] ?? ''));

    if (!rate_limit_allow_request($pdo, $config, 'otp_verify', normalize_email($otpUserEmail))) {
      $error = 'Too many OTP verification attempts. Please try again later.';
    } elseif ($otpUserId <= 0) {
      $error = 'Request OTP first.';
    } elseif ($code === '') {
      $error = 'Enter the OTP code.';
    } elseif (!verify_email_otp($pdo, $otpUserId, $code)) {
      $error = 'Invalid or expired OTP code.';
    } else {
      $user = find_active_user_by_id($pdo, $otpUserId);
      if (!$user) {
        $error = 'Account is unavailable.';
      } else {
          ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['full_name']);
        set_user_session($user);
        unset($_SESSION['otp_user_id'], $_SESSION['otp_user_email']);
        header('Location: ' . app_route('dashboard'));
        exit;
      }
    }
    $page = 'login';
  }

  if ($page === 'auth_microsoft_start') {
    if (!microsoft_oauth_ready($config)) {
      $error = 'Microsoft OAuth is not configured.';
      $page = 'login';
    } else {
      header('Location: ' . microsoft_authorize_url($config));
      exit;
    }
  }

  if ($page === 'auth_microsoft_callback' && $pdo) {
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    $savedState = oauth_read_state('microsoft', 'ms_oauth_state');

    $stateMatchesStored = $state !== '' && $savedState !== '' && hash_equals($savedState, $state);
    $stateMatchesSigned = $state !== '' && oauth_is_signed_state_valid($config, 'microsoft', $state);

    if (!$stateMatchesStored && !$stateMatchesSigned) {
      $error = 'OAuth state validation failed. Retry sign-in from the login page and confirm callback URL/domain is consistent.';
      $page = 'login';
    } elseif ($code === '') {
      $error = 'Microsoft OAuth code is missing.';
      $page = 'login';
    } else {
      oauth_clear_state($config, 'microsoft', 'ms_oauth_state');
      $tenantId = (string)$config['microsoft']['tenant_id'];
      $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

      $tokenPayload = http_build_query([
        'client_id' => (string)$config['microsoft']['client_id'],
        'scope' => 'openid profile email offline_access',
        'code' => $code,
        'redirect_uri' => (string)$config['microsoft']['redirect_uri'],
        'grant_type' => 'authorization_code',
        'client_secret' => (string)$config['microsoft']['client_secret'],
      ]);

      $ch = curl_init($tokenUrl);
      if ($ch === false) {
        $error = 'OAuth initialization failed.';
        $page = 'login';
      } else {
        curl_setopt_array($ch, [
          CURLOPT_POST => true,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
          CURLOPT_POSTFIELDS => $tokenPayload,
          CURLOPT_TIMEOUT => 10,
        ]);
        $tokenResponse = curl_exec($ch);
        $tokenData = is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;
        $accessToken = is_array($tokenData) ? (string)($tokenData['access_token'] ?? '') : '';

        if ($accessToken === '') {
          $error = 'Microsoft token exchange failed.';
          $page = 'login';
        } else {
          curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_POST => false,
            CURLOPT_URL => 'https://graph.microsoft.com/oidc/userinfo',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_POSTFIELDS => null,
          ]);
          $profileResponse = curl_exec($ch);
          $profile = is_string($profileResponse) ? json_decode($profileResponse, true) : null;
          $email = microsoft_extract_email(is_array($profile) ? $profile : null, is_array($tokenData) ? $tokenData : null);
          $displayName = is_array($profile) ? (string)($profile['name'] ?? '') : '';
          $subject = microsoft_extract_subject(is_array($profile) ? $profile : null, is_array($tokenData) ? $tokenData : null);

          if ($email === '') {
            $error = 'Microsoft account email not available.';
            $page = 'login';
          } else {
            $user = $subject !== '' ? find_active_user_by_identity($pdo, 'microsoft', $subject) : null;
            if (!$user) {
              $user = find_active_user_by_email($pdo, $email);
            }
            if (!$user) {
              $user = microsoft_provision_user_if_allowed($pdo, $config, $email, $displayName);
            }

            if (!$user) {
              $error = 'No local account found for this Microsoft email, and auto-provisioning is not allowed for this domain.';
              $page = 'login';
            } elseif ($subject !== '' && !upsert_user_identity($pdo, (int)$user['id'], 'microsoft', $subject, $email)) {
              $error = 'This Microsoft identity is already linked to another local account.';
              $page = 'login';
            } else {
              ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['full_name']);
              set_user_session($user);
              header('Location: ' . app_route('dashboard'));
              exit;
            }
          }
        }
      }
    }
  }

  if ($page === 'auth_google_start') {
    if (!google_oauth_ready($config)) {
      $error = 'Google OAuth is not configured.';
      $page = 'login';
    } else {
      header('Location: ' . google_authorize_url($config));
      exit;
    }
  }

  if ($page === 'auth_google_callback' && $pdo) {
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    $savedState = oauth_read_state('google', 'google_oauth_state');

    $stateMatchesStored = $state !== '' && $savedState !== '' && hash_equals($savedState, $state);
    $stateMatchesSigned = $state !== '' && oauth_is_signed_state_valid($config, 'google', $state);

    if (!$stateMatchesStored && !$stateMatchesSigned) {
      $error = 'OAuth state validation failed. Retry sign-in from the login page and confirm callback URL/domain is consistent.';
      $page = 'login';
    } elseif ($code === '') {
      $error = 'Google OAuth code is missing.';
      $page = 'login';
    } else {
      oauth_clear_state($config, 'google', 'google_oauth_state');
      $tokenUrl = 'https://oauth2.googleapis.com/token';
      $tokenPayload = http_build_query([
        'client_id' => (string)$config['google']['client_id'],
        'client_secret' => (string)$config['google']['client_secret'],
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => (string)$config['google']['redirect_uri'],
      ]);

      $ch = curl_init($tokenUrl);
      if ($ch === false) {
        $error = 'OAuth initialization failed.';
        $page = 'login';
      } else {
        curl_setopt_array($ch, [
          CURLOPT_POST => true,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
          CURLOPT_POSTFIELDS => $tokenPayload,
          CURLOPT_TIMEOUT => 10,
        ]);
        $tokenResponse = curl_exec($ch);
        $tokenData = is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;
        $accessToken = is_array($tokenData) ? (string)($tokenData['access_token'] ?? '') : '';

        if ($accessToken === '') {
          $error = 'Google token exchange failed.';
          $page = 'login';
        } else {
          curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_POST => false,
            CURLOPT_URL => 'https://openidconnect.googleapis.com/v1/userinfo',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_POSTFIELDS => null,
          ]);
          $profileResponse = curl_exec($ch);
          $profile = is_string($profileResponse) ? json_decode($profileResponse, true) : null;
          $email = microsoft_extract_email(is_array($profile) ? $profile : null, is_array($tokenData) ? $tokenData : null);
          $displayName = is_array($profile) ? (string)($profile['name'] ?? '') : '';
          $subject = google_extract_subject(is_array($profile) ? $profile : null, is_array($tokenData) ? $tokenData : null);

          if ($email === '') {
            $error = 'Google account email not available.';
            $page = 'login';
          } else {
            $user = $subject !== '' ? find_active_user_by_identity($pdo, 'google', $subject) : null;
            if (!$user) {
              $user = find_active_user_by_email($pdo, $email);
            }
            if (!$user) {
              $user = google_provision_user_if_allowed($pdo, $config, $email, $displayName);
            }

            if (!$user) {
              $error = 'No local account found for this Google email, and auto-provisioning is not allowed for this domain.';
              $page = 'login';
            } elseif ($subject !== '' && !upsert_user_identity($pdo, (int)$user['id'], 'google', $subject, $email)) {
              $error = 'This Google identity is already linked to another local account.';
              $page = 'login';
            } else {
              ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['full_name']);
              set_user_session($user);
              header('Location: ' . app_route('dashboard'));
              exit;
            }
          }
        }
      }
    }
  }

if ($page === 'profile_save' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $actor = require_login();
  $result = handle_profile_save_request($pdo, $actor, $_POST);
  $error = (string)$result['error'];
  $message = (string)$result['message'];
  $page = (string)$result['page'];
  if ($result['target_user_id'] !== null) {
    $_GET['user_id'] = (string)$result['target_user_id'];
  }
}

if ($page === 'profile_export' && $pdo) {
  $actor = require_login();
  $canViewProfiles = can_view_profiles($actor);
  $targetUserId = (int)($_GET['user_id'] ?? $actor['id']);
  if (!$canViewProfiles) {
    $targetUserId = (int)$actor['id'];
  }

  if ((string)$actor['role'] === 'it') {
    $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, role FROM users WHERE id = ? LIMIT 1');
    $targetStmt->execute([$targetUserId]);
  } else {
    $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, role FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
    $targetStmt->execute([$targetUserId, (int)$actor['tenant_id']]);
  }
  $targetUser = $targetStmt->fetch();
  if (!$targetUser) {
    http_response_code(404);
    exit('Profile target user not found');
  }
  if (!can_access_profile_target($actor, $targetUser)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $filters = normalize_profile_application_filters($_GET);
  $applications = fetch_profile_student_applications($pdo, (int)$targetUser['tenant_id'], (int)$targetUserId, $filters);

  $filename = 'student-applications-user-' . (string)(int)$targetUserId . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');

  $out = fopen('php://output', 'wb');
  if ($out === false) {
    http_response_code(500);
    exit('Unable to export CSV');
  }

  fputcsv($out, ['application_id', 'scholarship', 'status', 'created_at'], ',', '"', '\\');
  foreach ($applications as $row) {
    fputcsv($out, [
      csv_safe_cell((string)(int)$row['id']),
      csv_safe_cell((string)$row['scholarship_title']),
      csv_safe_cell((string)$row['status']),
      csv_safe_cell((string)$row['created_at']),
    ], ',', '"', '\\');
  }
  fclose($out);
  exit;
}

if ($page === 'identity_diagnostics_export' && $pdo) {
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $stmt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.email, u.role, t.code AS tenant_code, up.auth_provider_id,
            CASE
              WHEN t.code LIKE "TKIFGO%" THEN "google"
              WHEN t.code LIKE "TKIFMS%" THEN "microsoft"
              ELSE ""
            END AS inferred_provider
     FROM users u
     INNER JOIN tenants t ON t.id = u.tenant_id
     INNER JOIN user_profiles up ON up.user_id = u.id
     LEFT JOIN user_identities i ON i.user_id = u.id
     WHERE u.tenant_id = ?
       AND TRIM(COALESCE(up.auth_provider_id, "")) <> ""
       AND i.id IS NULL
     ORDER BY u.id DESC'
  );
  $stmt->execute([(int)$actor['tenant_id']]);
  $rows = $stmt->fetchAll();

  $filename = 'identity-backfill-candidates-tenant-' . (string)(int)$actor['tenant_id'] . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');

  $out = fopen('php://output', 'wb');
  if ($out === false) {
    http_response_code(500);
    exit('Unable to export CSV');
  }

  fputcsv($out, ['user_id', 'full_name', 'email', 'role', 'tenant_code', 'inferred_provider', 'auth_provider_id'], ',', '"', '\\');
  foreach ($rows as $row) {
    fputcsv($out, [
      csv_safe_cell((string)(int)$row['id']),
      csv_safe_cell((string)$row['full_name']),
      csv_safe_cell((string)$row['email']),
      csv_safe_cell((string)$row['role']),
      csv_safe_cell((string)$row['tenant_code']),
      csv_safe_cell((string)$row['inferred_provider']),
      csv_safe_cell((string)$row['auth_provider_id']),
    ], ',', '"', '\\');
  }

  fclose($out);
  exit;
}

if ($page === 'identity_diagnostics' && $pdo) {
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }
}

if ($page === 'phone_codes' && $pdo) {
  $actor = require_login();
  if ((string)$actor['role'] !== 'it') {
    http_response_code(403);
    exit('Forbidden');
  }
}

if ($page === 'phone_codes_save' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $actor = require_login();
  if ((string)$actor['role'] !== 'it') {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!phone_country_codes_ready($pdo)) {
    $error = 'Phone country code table is not ready. Run latest migrations.';
    $page = 'phone_codes';
  } else {
    $phoneCodeId = (int)($_POST['phone_code_id'] ?? 0);
    $iso2 = strtoupper(trim((string)($_POST['iso2'] ?? '')));
    $countryName = trim((string)($_POST['country_name'] ?? ''));
    $dialCode = trim((string)($_POST['dial_code'] ?? ''));
    $minLength = max(4, min(15, (int)($_POST['min_length'] ?? 6)));
    $maxLength = max($minLength, min(15, (int)($_POST['max_length'] ?? 12)));
    $regexPattern = trim((string)($_POST['regex_pattern'] ?? ''));
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = max(0, (int)($_POST['sort_order'] ?? 100));

    if (!preg_match('/^[A-Z]{2}$/', $iso2)) {
      $error = 'ISO2 must be exactly 2 letters.';
    } elseif ($countryName === '') {
      $error = 'Country name is required.';
    } elseif (!preg_match('/^\+[0-9]{1,4}$/', $dialCode)) {
      $error = 'Dial code must be in +NNN format.';
    } else {
      if ($regexPattern === '') {
        $regexPattern = '/^[0-9]{' . $minLength . ',' . $maxLength . '}$/';
      }

      if ($phoneCodeId > 0) {
        $stmt = $pdo->prepare('UPDATE phone_country_codes SET iso2 = ?, country_name = ?, dial_code = ?, min_length = ?, max_length = ?, regex_pattern = ?, is_default = ?, is_active = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$iso2, $countryName, $dialCode, $minLength, $maxLength, $regexPattern, $isDefault, $isActive, $sortOrder, $phoneCodeId]);
      } else {
        $stmt = $pdo->prepare('INSERT INTO phone_country_codes (iso2, country_name, dial_code, min_length, max_length, regex_pattern, is_default, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$iso2, $countryName, $dialCode, $minLength, $maxLength, $regexPattern, $isDefault, $isActive, $sortOrder]);
      }

      if ($isDefault === 1) {
        $currentId = $phoneCodeId > 0 ? $phoneCodeId : (int)$pdo->lastInsertId();
        $clearStmt = $pdo->prepare('UPDATE phone_country_codes SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END');
        $clearStmt->execute([$currentId]);
      }

      $message = 'Phone country code saved.';
      $page = 'phone_codes';
    }
  }
}

if (($page === 'scholarship_node_upload_image' || $page === 'scholarship_node_upload_pdf') && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$pdo) {
    json_response(503, ['ok' => false, 'error' => 'database_unavailable']);
  }

  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    json_response(403, ['ok' => false, 'error' => 'forbidden']);
  }

  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $csrf)) {
    json_response(422, ['ok' => false, 'error' => 'invalid_csrf']);
  }

  $isPdfUpload = $page === 'scholarship_node_upload_pdf';
  $fileKey = $isPdfUpload ? 'pdf_file' : 'image_file';
  if (!isset($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) {
    json_response(422, ['ok' => false, 'error' => 'missing_file']);
  }

  $upload = $_FILES[$fileKey];
  $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($errorCode !== UPLOAD_ERR_OK) {
    json_response(422, ['ok' => false, 'error' => 'upload_failed', 'code' => $errorCode]);
  }

  $tmpName = (string)($upload['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    json_response(422, ['ok' => false, 'error' => 'invalid_upload']);
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = $finfo ? (string)finfo_file($finfo, $tmpName) : '';
  if ($finfo) {
    finfo_close($finfo);
  }

  $kind = $isPdfUpload ? 'pdfs' : 'images';
  $ext = '';
  if ($isPdfUpload) {
    if ($mime !== 'application/pdf') {
      json_response(422, ['ok' => false, 'error' => 'invalid_pdf_type']);
    }
    $ext = 'pdf';
  } else {
    $imageExtMap = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/gif' => 'gif',
    ];
    if (!isset($imageExtMap[$mime])) {
      json_response(422, ['ok' => false, 'error' => 'invalid_image_type']);
    }
    if (@getimagesize($tmpName) === false) {
      json_response(422, ['ok' => false, 'error' => 'invalid_image_file']);
    }
    $ext = $imageExtMap[$mime];
  }

  $maxBytes = $isPdfUpload ? 8 * 1024 * 1024 : 5 * 1024 * 1024;
  $fileSize = (int)($upload['size'] ?? 0);
  if ($fileSize <= 0 || $fileSize > $maxBytes) {
    json_response(422, ['ok' => false, 'error' => 'file_size_out_of_range']);
  }

  $targetDir = scholarship_node_upload_abs_dir($kind);
  if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    json_response(500, ['ok' => false, 'error' => 'failed_to_create_upload_dir']);
  }
  harden_upload_directory($targetDir);

  $safeBase = make_safe_upload_basename((string)($upload['name'] ?? 'node_media'));
  $filename = $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $targetAbsPath = rtrim($targetDir, '/') . '/' . $filename;
  if (!move_uploaded_file($tmpName, $targetAbsPath)) {
    json_response(500, ['ok' => false, 'error' => 'failed_to_store_upload']);
  }

  $relativePath = scholarship_node_upload_relative_dir($kind) . '/' . $filename;
  json_response(200, [
    'ok' => true,
    'relative_path' => $relativePath,
    'asset_url' => app_asset($relativePath),
  ]);
}

if ($page === 'form_builder_save' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $scholarshipId = (int)($_POST['scholarship_id'] ?? 0);
  $title = trim((string)($_POST['title'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $saveAction = trim((string)($_POST['save_action'] ?? 'save_draft'));
  $builderType = strtolower(trim((string)($_POST['builder_type'] ?? $formBuilderEntityType)));
  if (!in_array($builderType, ['scholarship', 'survey', 'quiz'], true)) {
    $builderType = 'scholarship';
  }
  $selectedTemplate = trim((string)($_POST['selected_template'] ?? 'basic_application'));
  $rawSchema = json_decode((string)($_POST['form_schema_json'] ?? '[]'), true);
  $rawSettings = json_decode((string)($_POST['form_settings_json'] ?? '{}'), true);
  $schemaNodes = normalize_scholarship_nodes($rawSchema);
  $schema = flatten_scholarship_nodes($schemaNodes);
  $formSettings = normalize_form_settings($rawSettings);

  $builderTemplates = form_builder_templates_for_builder_type($builderType);
  $builderTemplateKeys = array_keys($builderTemplates);
  if ($selectedTemplate === '' || !in_array($selectedTemplate, $builderTemplateKeys, true)) {
    $selectedTemplate = (string)array_key_first($builderTemplates);
    if ($selectedTemplate === '') {
      $selectedTemplate = 'basic_application';
    }
  }

  $targetStatus = 'draft';
  if ($builderType === 'scholarship') {
    $targetStatus = $saveAction === 'publish' ? 'published' : 'draft';
    if (!in_array($targetStatus, ['draft', 'published'], true)) {
      $targetStatus = 'draft';
    }
  } elseif ($saveAction === 'publish') {
    $error = 'Form Builder: publish is available for scholarship mode only.';
  }

  if ($title === '') {
    $error = 'Form Builder: title is required.';
  } elseif ($schema === [] || count_input_fields($schema) < 1) {
    $error = 'Form Builder: add at least one valid field inside a form section.';
  } elseif ($builderType === 'scholarship' && $scholarshipId > 0) {
    $existsStmt = $pdo->prepare('SELECT id FROM scholarships WHERE id = ? AND tenant_id = ? LIMIT 1');
    $existsStmt->execute([$scholarshipId, (int)$actor['tenant_id']]);
    if (!$existsStmt->fetch()) {
      $error = 'Form Builder: selected scholarship was not found.';
    }
  }

  if ($error === '' && $builderType !== 'scholarship') {
    $message = 'Form Builder: ' . $builderType . ' draft is ready in the editor. Persistent save is currently enabled for scholarships only.';
  }

  if ($error === '' && $builderType === 'scholarship') {
    $schemaJson = scholarship_schema_payload_json($schemaNodes, $formSettings);
    if (!is_string($schemaJson)) {
      $error = 'Form Builder: failed to serialize schema JSON.';
    } elseif ($scholarshipId > 0) {
      $updateStmt = $pdo->prepare('UPDATE scholarships SET title = ?, description = ?, status = ?, form_schema_json = ? WHERE id = ? AND tenant_id = ?');
      $updateStmt->execute([
        $title,
        $description !== '' ? $description : null,
        $targetStatus,
        $schemaJson,
        $scholarshipId,
        (int)$actor['tenant_id'],
      ]);

      if (scholarship_form_versioning_ready($pdo)) {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) AS max_version FROM scholarship_form_versions WHERE scholarship_id = ? AND tenant_id = ?');
        $maxStmt->execute([$scholarshipId, (int)$actor['tenant_id']]);
        $maxVersion = (int)($maxStmt->fetch()['max_version'] ?? 0);
        $nextVersion = max(1, $maxVersion + 1);

        $insertVersion = $pdo->prepare(
          'INSERT INTO scholarship_form_versions (scholarship_id, tenant_id, version_no, status, form_schema_json, created_by)
           VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertVersion->execute([
          $scholarshipId,
          (int)$actor['tenant_id'],
          $nextVersion,
          $targetStatus,
          $schemaJson,
          (int)$actor['id'],
        ]);

        if ($targetStatus === 'published') {
          $archiveStmt = $pdo->prepare(
            'UPDATE scholarship_form_versions
             SET status = "archived"
             WHERE scholarship_id = ? AND tenant_id = ? AND version_no <> ? AND status = "published"'
          );
          $archiveStmt->execute([$scholarshipId, (int)$actor['tenant_id'], $nextVersion]);
          $message = 'Form Builder: published and saved version v' . (string)$nextVersion . '.';
        } else {
          $message = 'Form Builder: draft saved as version v' . (string)$nextVersion . '.';
        }
      } else {
        $message = $targetStatus === 'published'
          ? 'Form Builder: scholarship form published.'
          : 'Form Builder: scholarship form draft saved.';
      }
    } else {
      $insertStmt = $pdo->prepare('INSERT INTO scholarships (tenant_id, title, description, status, form_schema_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
      $insertStmt->execute([
        (int)$actor['tenant_id'],
        $title,
        $description !== '' ? $description : null,
        $targetStatus,
        $schemaJson,
        (int)$actor['id'],
      ]);
      $scholarshipId = (int)$pdo->lastInsertId();

      if (scholarship_form_versioning_ready($pdo)) {
        $versionStmt = $pdo->prepare(
          'INSERT INTO scholarship_form_versions (scholarship_id, tenant_id, version_no, status, form_schema_json, created_by)
           VALUES (?, ?, 1, ?, ?, ?)'
        );
        $versionStmt->execute([
          $scholarshipId,
          (int)$actor['tenant_id'],
          $targetStatus,
          $schemaJson,
          (int)$actor['id'],
        ]);
      }

      $message = $targetStatus === 'published'
        ? 'Form Builder: scholarship created and published.'
        : 'Form Builder: scholarship created as draft.';
    }
  }

  $page = 'form_builder';
  $_GET['scholarship_id'] = (string)$scholarshipId;
  $_GET['template'] = $selectedTemplate;
  $_GET['builder_type'] = $builderType;
  $formBuilderSelectedTemplate = $selectedTemplate;
  $formBuilderTemplateMeta = form_builder_starter_template($selectedTemplate);
  $formBuilderDraftSchema = json_encode($schema ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if (!is_string($formBuilderDraftSchema) || trim($formBuilderDraftSchema) === '') {
    $formBuilderDraftSchema = form_builder_starter_template_json($selectedTemplate);
  }
  $formBuilderScholarshipId = $scholarshipId;
  $formBuilderScholarshipTitle = $title;
  $formBuilderScholarshipDescription = $description;
  $formBuilderScholarshipStatus = $targetStatus;
  $formBuilderEntityType = $builderType;
}

if ($page === 'form_builder' && $pdo) {
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $selectedBuilderType = strtolower(trim((string)($_GET['builder_type'] ?? $formBuilderEntityType)));
  if (!in_array($selectedBuilderType, ['scholarship', 'survey', 'quiz'], true)) {
    $selectedBuilderType = 'scholarship';
  }
  $formBuilderEntityType = $selectedBuilderType;

  if ($selectedBuilderType === 'scholarship') {
    $schStmt = $pdo->prepare('SELECT id, title, description, status, form_schema_json FROM scholarships WHERE tenant_id = ? ORDER BY id DESC LIMIT 100');
    $schStmt->execute([(int)$actor['tenant_id']]);
    $formBuilderScholarships = $schStmt->fetchAll();
  } else {
    $formBuilderScholarships = [];
  }

  $selectedTemplate = trim((string)($_GET['template'] ?? $formBuilderSelectedTemplate));
  $selectedScholarshipId = (int)($_GET['scholarship_id'] ?? $formBuilderScholarshipId);

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'switch_builder_type') {
      $selectedBuilderType = strtolower(trim((string)($_POST['builder_type'] ?? $selectedBuilderType)));
      if (!in_array($selectedBuilderType, ['scholarship', 'survey', 'quiz'], true)) {
        $selectedBuilderType = 'scholarship';
      }
      $formBuilderEntityType = $selectedBuilderType;
      if ($selectedBuilderType !== 'scholarship') {
        $selectedScholarshipId = 0;
      }
    } elseif ($action === 'load_template') {
      $selectedTemplate = trim((string)($_POST['template'] ?? ''));
    } elseif ($action === 'load_scholarship') {
      $selectedScholarshipId = (int)($_POST['load_scholarship_id'] ?? 0);
    }
  }

  $builderTemplates = form_builder_templates_for_builder_type($selectedBuilderType);
  $builderTemplateKeys = array_keys($builderTemplates);
  if ($selectedTemplate === '' || !in_array($selectedTemplate, $builderTemplateKeys, true)) {
    $selectedTemplate = (string)array_key_first($builderTemplates);
    if ($selectedTemplate === '') {
      $selectedTemplate = 'basic_application';
    }
  }

  $formBuilderSelectedTemplate = $selectedTemplate;
  $formBuilderTemplateMeta = form_builder_starter_template($selectedTemplate);
  if (trim($formBuilderDraftSchema) === '') {
    $formBuilderDraftSchema = form_builder_starter_template_json($selectedTemplate);
  }

  if ($selectedBuilderType === 'scholarship' && $selectedScholarshipId > 0) {
    foreach ($formBuilderScholarships as $scholarshipRow) {
      if ((int)($scholarshipRow['id'] ?? 0) === $selectedScholarshipId) {
        $formBuilderScholarshipId = $selectedScholarshipId;
        $formBuilderScholarshipTitle = (string)($scholarshipRow['title'] ?? '');
        $formBuilderScholarshipDescription = (string)($scholarshipRow['description'] ?? '');
        $status = (string)($scholarshipRow['status'] ?? 'draft');
        $formBuilderScholarshipStatus = in_array($status, ['draft', 'published', 'closed'], true) ? $status : 'draft';
        $schemaJson = (string)($scholarshipRow['form_schema_json'] ?? '[]');
        $decoded = json_decode($schemaJson, true);
        $normalized = normalize_scholarship_nodes($decoded);
        $pretty = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (is_string($pretty) && trim($pretty) !== '') {
          $formBuilderDraftSchema = $pretty;
        }
        break;
      }
    }
  } else {
    $formBuilderScholarshipId = 0;
    $formBuilderScholarshipStatus = 'draft';
  }
}

if ($page === 'phone_codes' && $pdo) {
  $actor = require_login();
  if ((string)$actor['role'] !== 'it') {
    http_response_code(403);
    exit('Forbidden');
  }

  if (phone_country_codes_ready($pdo)) {
    $phoneCodeRows = phone_country_code_rows($pdo, false);
    $editId = (int)($_GET['edit_phone_code_id'] ?? 0);
    if ($editId > 0) {
      foreach ($phoneCodeRows as $row) {
        if ((int)($row['id'] ?? 0) === $editId) {
          $phoneCodeEdit = $row;
          break;
        }
      }
    }
  }
}

if ($page === 'profile' && $pdo) {
  $actor = require_login();
  $targetUserId = (int)($_GET['user_id'] ?? $actor['id']);
  if ($targetUserId !== (int)$actor['id']) {
    if ((string)$actor['role'] === 'it') {
      $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, is_active FROM users WHERE id = ? LIMIT 1');
      $targetStmt->execute([$targetUserId]);
    } else {
      $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, is_active FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
      $targetStmt->execute([$targetUserId, (int)$actor['tenant_id']]);
    }
    $targetUser = $targetStmt->fetch();
    if (!$targetUser) {
      http_response_code(404);
      exit('Profile target user not found');
    }
    if (!can_access_profile_target($actor, $targetUser)) {
      http_response_code(403);
      exit('Forbidden');
    }
  }
}

if ($page === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $user = require_login();
    if ($user['role'] !== 'student') {
        http_response_code(403);
        exit('Forbidden');
    }

    if (!captcha_verify_submission($config, $_POST)) {
      $error = 'CAPTCHA verification failed. Please try again.';
      $page = 'dashboard';
    }

    if ($error === '') {
    ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['name']);
    $selfProfile = get_user_profile($pdo, (int)$user['id']);
    if (!$selfProfile || !is_profile_complete($user, $selfProfile)) {
      $missing = $selfProfile ? profile_missing_required_fields($user, $selfProfile) : ['profile'];
      $error = 'Complete profile first. Missing fields: ' . implode(', ', $missing);
      $page = 'profile';
      $_GET['user_id'] = (string)$user['id'];
    } else {
    $scholarshipId = (int)($_POST['scholarship_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, tenant_id, title, form_schema_json FROM scholarships WHERE id = ? AND tenant_id = ? AND status = "published" LIMIT 1');
    $stmt->execute([$scholarshipId, $user['tenant_id']]);
    $scholarship = $stmt->fetch();
    if (!$scholarship) {
      http_response_code(404);
      exit('Scholarship not found');
    }

    $blacklist = blacklist_match($pdo, (int)($user['register_id'] ?? $user['id']), (string)$user['email']);
    if ($blacklist) {
      $reason = 'Blacklisted: ' . blacklist_reason_text($blacklist['reason'] ?? null);
      $resolved = resolve_active_scholarship_schema($pdo, $scholarship);
      $schema = $resolved['schema'];
      $formVersion = (int)$resolved['version'];

      $stmt = $pdo->prepare('INSERT INTO applications (tenant_id, scholarship_id, student_id, answers_json, status, rejection_reason) VALUES (?, ?, ?, ?, "rejected", ?)');
      $stmt->execute([
        $user['tenant_id'],
        $scholarshipId,
        $user['id'],
        json_encode(['blocked' => true], JSON_UNESCAPED_UNICODE),
        $reason,
      ]);

      $applicationId = (int)$pdo->lastInsertId();
      $audit = $pdo->prepare('INSERT INTO audit_logs (tenant_id, actor_user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?)');
      $audit->execute([
        $user['tenant_id'],
        $user['id'],
        'application_rejected_blacklist',
        'application',
        $applicationId,
        json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
      ]);

      enqueue_internal_notification($pdo, [
        'event' => 'application_rejected_blacklist',
        'notification_type' => 'webhook',
        'route' => 'n8n_global',
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
        'scholarship_id' => $scholarshipId,
        'scholarship_title' => (string)($scholarship['title'] ?? ''),
        'student_id' => (int)$user['id'],
        'student_email' => $user['email'],
        'reason' => $reason,
        'form_schema_version' => $formVersion,
        'form_text_rules' => form_schema_text_rules($schema),
        'rejected_at' => gmdate('c'),
      ]);
      process_notification_queue($pdo, $config, 5);

      snapshot_application_form(
        $pdo,
        (int)$user['tenant_id'],
        $applicationId,
        $scholarshipId,
        $formVersion,
        $schema,
        ['blocked' => true]
      );

      $error = 'Application auto-rejected because this registration is blacklisted.';
      $page = 'dashboard';
    } else {
    $resolved = resolve_active_scholarship_schema($pdo, $scholarship);
    $schema = $resolved['schema'];
    $formSettings = is_array($resolved['settings'] ?? null) ? $resolved['settings'] : normalize_form_settings([]);
    $formVersion = (int)$resolved['version'];
    if ($schema === []) {
      http_response_code(422);
      exit('Invalid scholarship form schema');
    }

    $now = new DateTimeImmutable('now');
    $submissionStartAt = trim((string)($formSettings['submission_start_at'] ?? ''));
    if ($submissionStartAt !== '') {
      $startAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $submissionStartAt);
      if ($startAt instanceof DateTimeImmutable && $now < $startAt) {
        $error = 'This form is not open yet.';
        $page = 'dashboard';
      }
    }

    $submissionEndAt = trim((string)($formSettings['submission_end_at'] ?? ''));
    if ($error === '' && $submissionEndAt !== '') {
      $endAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $submissionEndAt);
      if ($endAt instanceof DateTimeImmutable && $now > $endAt) {
        $error = 'This form is closed.';
        $page = 'dashboard';
      }
    }

    $existingApplication = null;
    $oneResponsePerUser = ($formSettings['one_response_per_user'] ?? false) === true;
    $allowEditAfterSubmit = ($formSettings['allow_edit_after_submit'] ?? false) === true;
    if ($error === '' && $oneResponsePerUser) {
      $existingStmt = $pdo->prepare(
        'SELECT id, status
         FROM applications
         WHERE tenant_id = ? AND scholarship_id = ? AND student_id = ?
         ORDER BY id DESC
         LIMIT 1'
      );
      $existingStmt->execute([(int)$user['tenant_id'], $scholarshipId, (int)$user['id']]);
      $existingApplication = $existingStmt->fetch() ?: null;
      if ($existingApplication && !$allowEditAfterSubmit) {
        $error = 'Only one response is allowed for this form.';
        $page = 'dashboard';
      }
    }

    $answersInput = $_POST['answers'] ?? [];
    $agreementInput = $_POST['agreements'] ?? [];
    $answers = [];
    foreach ($schema as $field) {
      $fieldName = (string)$field['name'];
      $fieldType = (string)($field['type'] ?? 'text');

      if (in_array($fieldType, ['welcome', 'section', 'form', 'thank_you'], true)) {
        continue;
      }

      if ($fieldType === 'agreement') {
        $agreed = trim((string)(is_array($agreementInput) ? ($agreementInput[$fieldName] ?? '') : '')) === '1';
        if ((bool)($field['required'] ?? true) && !$agreed) {
          $error = 'Please accept the agreement section to continue.';
          $page = 'dashboard';
          break;
        }
        $answers[$fieldName] = $agreed ? '1' : '0';
        continue;
      }

      if (!field_is_visible($field, $answers)) {
        $answers[$fieldName] = $fieldType === 'checkbox' ? [] : '';
        continue;
      }

      if ($fieldType === 'checkbox') {
        $raw = $answersInput[$fieldName] ?? [];
        $values = is_array($raw) ? $raw : [$raw];
        $allowedOptions = array_map('strval', (array)($field['options'] ?? []));
        $clean = [];
        foreach ($values as $value) {
          $candidate = trim((string)$value);
          if ($candidate !== '' && in_array($candidate, $allowedOptions, true)) {
            $clean[] = $candidate;
          }
        }
        $clean = array_values(array_unique($clean));
        if ((bool)$field['required'] && $clean === []) {
          $error = 'Please fill all required fields.';
          $page = 'dashboard';
          break;
        }
        $answers[$fieldName] = $clean;
        continue;
      }

      if ($fieldType === 'phone') {
        $rawPhone = $answersInput[$fieldName] ?? [];
        $countryCode = trim((string)(is_array($rawPhone) ? ($rawPhone['country_code'] ?? '') : ''));
        $phoneNumber = trim((string)(is_array($rawPhone) ? ($rawPhone['number'] ?? '') : ''));
        $requiredPhone = (bool)($field['required'] ?? false);

        $phoneValidation = phone_validate_input($pdo, $countryCode, $phoneNumber, $requiredPhone);
        if (!($phoneValidation['ok'] ?? false)) {
          $error = (string)($phoneValidation['error'] ?? 'Invalid phone number.');
          $page = 'dashboard';
          break;
        }

        $normalizedNumber = (string)($phoneValidation['number'] ?? '');
        if ($countryCode === '' && $normalizedNumber === '') {
          $answers[$fieldName] = [
            'country_code' => '',
            'number' => '',
            'full' => '',
            'country_iso2' => '',
          ];
        } else {
          $country = is_array($phoneValidation['country'] ?? null) ? $phoneValidation['country'] : [];
          $answers[$fieldName] = [
            'country_code' => $countryCode,
            'number' => $normalizedNumber,
            'full' => (string)($phoneValidation['combined'] ?? ($countryCode . $normalizedNumber)),
            'country_iso2' => strtoupper(trim((string)($country['iso2'] ?? ''))),
          ];
        }
        continue;
      }

      $value = trim((string)($answersInput[$fieldName] ?? ''));
      if (in_array($fieldType, ['select', 'radio'], true)) {
        $allowedOptions = array_map('strval', (array)($field['options'] ?? []));
        if ($value !== '' && !in_array($value, $allowedOptions, true)) {
          $value = '';
        }
      }

      if ($fieldType === 'linear_scale') {
        $minScale = isset($field['min_value']) ? (float)$field['min_value'] : 1.0;
        $maxScale = isset($field['max_value']) ? (float)$field['max_value'] : 5.0;
        if (!is_numeric($value)) {
          if ((bool)$field['required']) {
            $error = 'Please select a valid scale value.';
            $page = 'dashboard';
            break;
          }
          $value = '';
        } else {
          $numValue = (float)$value;
          if ($numValue < $minScale || $numValue > $maxScale) {
            $error = 'Scale answer is out of allowed range.';
            $page = 'dashboard';
            break;
          }
          $value = (string)(int)$numValue;
        }
      }

      if ($fieldType === 'number' && $value !== '') {
        if (!is_numeric($value)) {
          $error = 'Please enter a valid number.';
          $page = 'dashboard';
          break;
        }
        $numValue = (float)$value;
        if (isset($field['min_value']) && $numValue < (float)$field['min_value']) {
          $error = 'Number is below minimum allowed value.';
          $page = 'dashboard';
          break;
        }
        if (isset($field['max_value']) && $numValue > (float)$field['max_value']) {
          $error = 'Number exceeds maximum allowed value.';
          $page = 'dashboard';
          break;
        }
      }

      if ($fieldType === 'date' && $value !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
          $error = 'Please enter a valid date.';
          $page = 'dashboard';
          break;
        }
        if (isset($field['min_value']) && strcmp($value, (string)$field['min_value']) < 0) {
          $error = 'Date is earlier than allowed range.';
          $page = 'dashboard';
          break;
        }
        if (isset($field['max_value']) && strcmp($value, (string)$field['max_value']) > 0) {
          $error = 'Date is later than allowed range.';
          $page = 'dashboard';
          break;
        }
      }

      if ($fieldType === 'time' && $value !== '') {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
          $error = 'Please enter a valid time.';
          $page = 'dashboard';
          break;
        }
        if (isset($field['min_value']) && strcmp($value, (string)$field['min_value']) < 0) {
          $error = 'Time is earlier than allowed range.';
          $page = 'dashboard';
          break;
        }
        if (isset($field['max_value']) && strcmp($value, (string)$field['max_value']) > 0) {
          $error = 'Time is later than allowed range.';
          $page = 'dashboard';
          break;
        }
      }

      $fieldTextRule = normalize_text_rule((string)($field['text_rule'] ?? 'english_or_arabic'));
      if (in_array($fieldType, ['text', 'textarea'], true) && !text_matches_rule($value, $fieldTextRule)) {
        $error = text_rule_error_message($fieldTextRule);
        if ($error === '') {
          $error = 'Invalid text format.';
        }
        $page = 'dashboard';
        break;
      }
      if (in_array($fieldType, ['text', 'textarea'], true) && $value !== '') {
        $valueLen = mb_strlen($value);
        if (isset($field['min_length']) && $valueLen < (int)$field['min_length']) {
          $error = 'Text is shorter than minimum length.';
          $page = 'dashboard';
          break;
        }
        if (isset($field['max_length']) && $valueLen > (int)$field['max_length']) {
          $error = 'Text exceeds maximum length.';
          $page = 'dashboard';
          break;
        }
        $regexPattern = trim((string)($field['regex_pattern'] ?? ''));
        if ($regexPattern !== '' && @preg_match($regexPattern, $value) !== 1) {
          $error = 'Text does not match required pattern.';
          $page = 'dashboard';
          break;
        }
      }
      if ((bool)$field['required'] && $value === '') {
        $error = 'Please fill all required fields.';
        $page = 'dashboard';
        break;
      }
      $answers[$fieldName] = $value;
    }

      if ($error !== '') {
        $message = '';
      } else {
      $answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE);
      if (!is_string($answersJson)) {
        $answersJson = '{}';
      }

      $isEditSubmission = false;
      if ($oneResponsePerUser && $allowEditAfterSubmit && is_array($existingApplication)) {
        $applicationId = (int)($existingApplication['id'] ?? 0);
        if ($applicationId > 0) {
          $updateApp = $pdo->prepare(
            'UPDATE applications
             SET answers_json = ?, status = "submitted", rejection_reason = NULL, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?'
          );
          $updateApp->execute([$answersJson, $applicationId, (int)$user['tenant_id']]);
          $isEditSubmission = true;
        }
      }

      if (!$isEditSubmission) {
        $stmt = $pdo->prepare('INSERT INTO applications (tenant_id, scholarship_id, student_id, answers_json, status) VALUES (?, ?, ?, ?, "submitted")');
        $stmt->execute([
          $user['tenant_id'],
          $scholarshipId,
          $user['id'],
          $answersJson,
        ]);
        $applicationId = (int)$pdo->lastInsertId();
      }

      enqueue_internal_notification($pdo, [
        'event' => $isEditSubmission ? 'application_resubmitted' : 'application_submitted',
        'notification_type' => 'webhook',
        'route' => 'n8n_global',
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
        'tenant_code' => (string)($user['tenant_code'] ?? ''),
        'scholarship_id' => $scholarshipId,
        'scholarship_title' => (string)($scholarship['title'] ?? ''),
        'student_id' => (int)$user['id'],
        'student_email' => $user['email'],
        'student_name' => (string)($user['name'] ?? ''),
        'answers' => $answers,
        'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        'form_schema_version' => $formVersion,
        'form_text_rules' => form_schema_text_rules($schema),
        'submitted_at' => gmdate('c'),
        'submission_mode' => $isEditSubmission ? 'edit' : 'new',
      ]);
      process_notification_queue($pdo, $config, 5);

      snapshot_application_form(
        $pdo,
        (int)$user['tenant_id'],
        $applicationId,
        $scholarshipId,
        $formVersion,
        $schema,
        $answers
      );

      if (smtp_is_ready($config)) {
        send_smtp_mail(
          $config,
          $user['email'],
          $user['name'],
          'Application Submitted #' . $applicationId,
          '<p>Your application was submitted successfully.</p><p>Application ID: <strong>' . (string)$applicationId . '</strong></p>'
        );

        $reviewers = tenant_users_by_roles($pdo, (int)$user['tenant_id'], ['admin', 'manager']);
        foreach ($reviewers as $reviewer) {
          send_smtp_mail(
            $config,
            (string)$reviewer['email'],
            (string)$reviewer['full_name'],
            'New Scholarship Application #' . $applicationId,
            '<p>A new scholarship application requires review.</p><p>Application ID: <strong>' . (string)$applicationId . '</strong></p>'
          );
        }
      }

      $message = $isEditSubmission
        ? 'Application updated successfully.'
        : 'Application submitted successfully.';
      $page = 'dashboard';
    }
    }
    }
    }
}

if ($page === 'blacklist_add' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $user = require_login();
  if (!in_array($user['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $addType = strtolower(trim((string)($_POST['add_type'] ?? '')));
  $addValue = trim((string)($_POST['add_value'] ?? ''));

  $registerId = (int)($_POST['register_id'] ?? 0);
  $email = trim((string)($_POST['email'] ?? ''));
  if ($addValue !== '') {
    if ($addType === 'register_id') {
      $registerId = (int)$addValue;
    } elseif ($addType === 'email') {
      $email = $addValue;
    } elseif ($addType === 'reason') {
      $error = 'Reason cannot identify a person by itself. Use email or registrar ID in Add New tab.';
    }
  }

  $emailNorm = $email !== '' ? normalize_email($email) : null;
  $reason = trim((string)($_POST['reason'] ?? ''));
  $blacklistForm = [
    'register_id' => $registerId > 0 ? (string)$registerId : '',
    'email' => $email,
    'reason' => $reason,
  ];

  if ($registerId <= 0 && $emailNorm === null) {
    $error = 'Provide register_id or email.';
  }

  $userById = $registerId > 0 ? blacklist_find_user_by_id($pdo, $registerId) : null;
  if ($error === '' && $registerId > 0 && $userById === null) {
    $error = 'Registration ID was not found. For non-registered persons, use email only.';
  }

  if ($error === '' && $registerId > 0 && $emailNorm !== null && $userById !== null && normalize_email((string)$userById['email']) !== $emailNorm) {
    $error = 'Registration ID and email point to different users. Fix the input and try again.';
  }

  if ($error === '') {
    $registerIdForInsert = $registerId > 0 ? $registerId : null;
    $insert = $pdo->prepare('INSERT INTO blacklist_entries (tenant_id, register_id, email_original, email_normalized, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([
      $user['tenant_id'],
      $registerIdForInsert,
      $email !== '' ? $email : null,
      $emailNorm,
      $reason !== '' ? $reason : null,
      $user['id'],
    ]);

    $matchedUserIds = collect_blacklisted_user_ids($pdo, $registerIdForInsert, $emailNorm);
    set_users_blacklist_flag($pdo, $matchedUserIds, 1);
    $rejectedCount = reject_inflight_applications($pdo, (int)$user['tenant_id'], $matchedUserIds, (int)$user['id'], 'Blacklisted: ' . blacklist_reason_text($reason));
    $message = 'Blacklist entry added. Auto-rejected in-flight applications: ' . (string)$rejectedCount;
    $blacklistForm = ['register_id' => '', 'email' => '', 'reason' => ''];
    $page = 'dashboard';
  } else {
    $page = 'dashboard';
  }
}

if ($page === 'blacklist_preview' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $user = require_login();
  if (!in_array($user['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $addType = strtolower(trim((string)($_POST['add_type'] ?? '')));
  $addValue = trim((string)($_POST['add_value'] ?? ''));
  $registerId = (int)($_POST['register_id'] ?? 0);
  $email = trim((string)($_POST['email'] ?? ''));
  if ($addValue !== '') {
    if ($addType === 'register_id') {
      $registerId = (int)$addValue;
    } elseif ($addType === 'email') {
      $email = $addValue;
    } elseif ($addType === 'reason') {
      $error = 'Reason cannot identify a person by itself. Use email or registrar ID for preview.';
    }
  }
  $emailNorm = $email !== '' ? normalize_email($email) : null;
  $reason = trim((string)($_POST['reason'] ?? ''));
  $blacklistForm = [
    'register_id' => $registerId > 0 ? (string)$registerId : '',
    'email' => $email,
    'reason' => $reason,
  ];

  if ($registerId <= 0 && $emailNorm === null) {
    $error = 'Provide register_id or email for preview.';
  } else {
    $userById = $registerId > 0 ? blacklist_find_user_by_id($pdo, $registerId) : null;
    $userByEmail = $emailNorm !== null ? blacklist_find_user_by_email($pdo, $emailNorm) : null;

    if ($registerId > 0 && $userById === null) {
      $blacklistPreview = [
        'mode' => 'not_found_id',
        'register_id' => $registerId,
        'email' => $email,
      ];
    } elseif ($registerId > 0 && $emailNorm !== null && $userById !== null && normalize_email((string)$userById['email']) !== $emailNorm) {
      $blacklistPreview = [
        'mode' => 'mismatch',
        'register_id' => $registerId,
        'email' => $email,
        'user_by_id' => $userById,
        'user_by_email' => $userByEmail,
      ];
    } elseif ($userById !== null || $userByEmail !== null) {
      $blacklistPreview = [
        'mode' => 'found',
        'user' => $userById ?? $userByEmail,
        'matched_by' => $userById !== null ? 'register_id' : 'email',
      ];
    } else {
      $blacklistPreview = [
        'mode' => 'email_only',
        'email' => $email,
      ];
    }
  }

  $page = 'dashboard';
}

if ($page === 'blacklist_import' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $user = require_login();
  if (!in_array($user['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!isset($_FILES['blacklist_file']) || (int)$_FILES['blacklist_file']['error'] !== UPLOAD_ERR_OK) {
    $error = 'Upload a valid blacklist file (.csv or .xlsx).';
  } else {
    $tmpPath = (string)$_FILES['blacklist_file']['tmp_name'];
    $name = strtolower((string)$_FILES['blacklist_file']['name']);
    $rows = [];

    if (str_ends_with($name, '.csv')) {
      if (($fh = fopen($tmpPath, 'rb')) !== false) {
        while (($data = fgetcsv($fh)) !== false) {
          $rows[] = $data;
        }
        fclose($fh);
      }
    } elseif (str_ends_with($name, '.xlsx')) {
      $xlsx = SimpleXLSX::parse($tmpPath);
      if ($xlsx) {
        $rows = $xlsx->rows();
      }
    }

    if (count($rows) < 2) {
      $error = 'No data rows found. Expected headers: register_id,email,reason';
    } else {
      $headers = array_map(static fn($h): string => strtolower(trim((string)$h)), $rows[0]);
      $idxRegister = array_search('register_id', $headers, true);
      $idxEmail = array_search('email', $headers, true);
      $idxReason = array_search('reason', $headers, true);

      if ($idxRegister === false && $idxEmail === false) {
        $error = 'Missing required headers. Use register_id and/or email';
      } else {
        $inserted = 0;
        $rejectedTotal = 0;
        $insert = $pdo->prepare('INSERT INTO blacklist_entries (tenant_id, register_id, email_original, email_normalized, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i < count($rows); $i++) {
          $row = $rows[$i];
          $registerId = $idxRegister !== false ? (int)trim((string)($row[$idxRegister] ?? '0')) : 0;
          $email = $idxEmail !== false ? trim((string)($row[$idxEmail] ?? '')) : '';
          $emailNorm = $email !== '' ? normalize_email($email) : null;
          $reason = $idxReason !== false ? trim((string)($row[$idxReason] ?? '')) : '';

          if ($registerId <= 0 && $emailNorm === null) {
            continue;
          }

          $insert->execute([
            $user['tenant_id'],
            $registerId > 0 ? $registerId : null,
            $email !== '' ? $email : null,
            $emailNorm,
            $reason !== '' ? $reason : null,
            $user['id'],
          ]);
          $inserted++;

          $matchedUserIds = collect_blacklisted_user_ids($pdo, $registerId > 0 ? $registerId : null, $emailNorm);
          set_users_blacklist_flag($pdo, $matchedUserIds, 1);
          $rejectedTotal += reject_inflight_applications($pdo, (int)$user['tenant_id'], $matchedUserIds, (int)$user['id'], 'Blacklisted: ' . blacklist_reason_text($reason));
        }
        $message = 'Blacklist import complete. Inserted: ' . (string)$inserted . ', Auto-rejected in-flight: ' . (string)$rejectedTotal;
        $page = 'dashboard';
      }
    }
  }
}

if ($page === 'blacklist_whitelist_entry' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $entryId = (int)($_POST['entry_id'] ?? 0);
  if ($entryId <= 0) {
    $error = 'Invalid blacklist entry.';
    $page = 'dashboard';
  } else {
    if ((string)$actor['role'] === 'it') {
      $entryStmt = $pdo->prepare('SELECT id, register_id, email_normalized FROM blacklist_entries WHERE id = ? LIMIT 1');
      $entryStmt->execute([$entryId]);
    } else {
      $entryStmt = $pdo->prepare('SELECT id, register_id, email_normalized FROM blacklist_entries WHERE id = ? AND tenant_id = ? LIMIT 1');
      $entryStmt->execute([$entryId, (int)$actor['tenant_id']]);
    }
    $entry = $entryStmt->fetch();

    if (!$entry) {
      $error = 'Blacklist entry not found.';
      $page = 'dashboard';
    } else {
      $registerId = (int)($entry['register_id'] ?? 0);
      $emailNorm = trim((string)($entry['email_normalized'] ?? ''));
      $matchedUserIds = collect_blacklisted_user_ids($pdo, $registerId > 0 ? $registerId : null, $emailNorm !== '' ? $emailNorm : null);
      $whitelistCount = set_users_blacklist_flag($pdo, $matchedUserIds, 0);

      $deleteStmt = $pdo->prepare('DELETE FROM blacklist_entries WHERE id = ?');
      $deleteStmt->execute([$entryId]);

      $message = 'Entry moved to whitelist. User flags cleared: ' . (string)$whitelistCount . '.';
      $page = 'dashboard';
    }
  }
}

if ($page === 'blacklist_export' && $pdo) {
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
  if (!in_array($format, ['csv', 'xls'], true)) {
    $format = 'csv';
  }

  $searchType = strtolower(trim((string)($_GET['search_type'] ?? 'email')));
  if (!in_array($searchType, ['email', 'register_id', 'reason'], true)) {
    $searchType = 'email';
  }
  $searchTerm = trim((string)($_GET['search_term'] ?? ''));

  if ((string)$actor['role'] === 'it') {
    $sql = 'SELECT id, tenant_id, register_id, email_original, email_normalized, reason, created_at FROM blacklist_entries';
    $params = [];
  } else {
    $sql = 'SELECT id, tenant_id, register_id, email_original, email_normalized, reason, created_at FROM blacklist_entries WHERE tenant_id = ?';
    $params = [(int)$actor['tenant_id']];
  }

  if ($searchTerm !== '') {
    $column = $searchType === 'register_id' ? 'register_id' : ($searchType === 'reason' ? 'reason' : 'email_normalized');
    if ($column === 'register_id') {
      $sql .= strpos($sql, ' WHERE ') === false ? ' WHERE ' : ' AND ';
      $sql .= 'register_id = ?';
      $params[] = (int)$searchTerm;
    } else {
      $sql .= strpos($sql, ' WHERE ') === false ? ' WHERE ' : ' AND ';
      $sql .= 'LOWER(COALESCE(' . $column . ', "")) LIKE ?';
      $params[] = '%' . strtolower($searchTerm) . '%';
    }
  }
  $sql .= ' ORDER BY id DESC LIMIT 1000';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  $filenameBase = 'blacklist_export_' . date('Ymd_His');
  $headers = ['id', 'tenant_id', 'register_id', 'email_original', 'email_normalized', 'reason', 'created_at'];

  if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1"><tr>';
    foreach ($headers as $headerLabel) {
      echo '<th>' . h($headerLabel) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $row) {
      echo '<tr>';
      foreach ($headers as $key) {
        echo '<td>' . h((string)($row[$key] ?? '')) . '</td>';
      }
      echo '</tr>';
    }
    echo '</table>';
    exit;
  }

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
  $output = fopen('php://output', 'wb');
  if ($output === false) {
    http_response_code(500);
    exit('Failed to stream export');
  }
  fputcsv($output, $headers);
  foreach ($rows as $row) {
    fputcsv($output, [
      (string)($row['id'] ?? ''),
      (string)($row['tenant_id'] ?? ''),
      (string)($row['register_id'] ?? ''),
      (string)($row['email_original'] ?? ''),
      (string)($row['email_normalized'] ?? ''),
      (string)($row['reason'] ?? ''),
      (string)($row['created_at'] ?? ''),
    ]);
  }
  fclose($output);
  exit;
}

if ($page === 'user_blacklist_toggle' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  if (!users_blacklist_column_ready($pdo)) {
    $error = 'Blacklist flag column is missing. Run sql/migrations/20260605_add_users_blacklist_flag.sql first.';
    $page = 'dashboard';
  } else {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $targetFlag = (int)($_POST['blacklist'] ?? 0) === 1 ? 1 : 0;

    if ($targetUserId <= 0) {
      $error = 'Invalid user for blacklist toggle.';
      $page = 'dashboard';
    } else {
      $targetStmt = $pdo->prepare('SELECT id, full_name, tenant_id, blacklist FROM users WHERE id = ? LIMIT 1');
        $targetStmt->execute([$targetUserId]);
      $targetUser = $targetStmt->fetch();

      if (!$targetUser) {
          $error = 'Target user was not found.';
      } else {
          $updateStmt = $pdo->prepare('UPDATE users SET blacklist = ? WHERE id = ?');
          $updateStmt->execute([$targetFlag, $targetUserId]);

        $message = $targetFlag === 1
            ? 'User moved to blacklist: ' . (string)$targetUser['full_name'] . ' (tenant #' . (string)((int)$targetUser['tenant_id']) . ')'
            : 'User moved to whitelist: ' . (string)$targetUser['full_name'] . ' (tenant #' . (string)((int)$targetUser['tenant_id']) . ')';
      }

      $page = 'dashboard';
    }
  }
}

if ($page === 'user_role_status_update' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $targetUserId = (int)($_POST['user_id'] ?? 0);
  $targetRole = strtolower(trim((string)($_POST['role'] ?? '')));
  $targetActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
  $targetEmailVerificationStatus = strtolower(trim((string)($_POST['email_verification_status'] ?? '')));
  $allowedRoles = ['student', 'manager', 'admin', 'it'];
  $allowedVerificationStatuses = ['verified', 'unverified'];

  if ($targetUserId <= 0) {
    $error = 'Invalid user for role/status update.';
    $page = 'dashboard';
  } elseif (!in_array($targetRole, $allowedRoles, true)) {
    $error = 'Invalid target role.';
    $page = 'dashboard';
  } elseif ($targetEmailVerificationStatus !== '' && !in_array($targetEmailVerificationStatus, $allowedVerificationStatuses, true)) {
    $error = 'Invalid email verification status.';
    $page = 'dashboard';
  } else {
    if ((string)$actor['role'] === 'it') {
      $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, is_active, email_verified_at FROM users WHERE id = ? LIMIT 1');
      $targetStmt->execute([$targetUserId]);
    } else {
      $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, is_active, email_verified_at FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
      $targetStmt->execute([$targetUserId, (int)$actor['tenant_id']]);
    }
    $targetUser = $targetStmt->fetch();

    if (!$targetUser) {
      $error = (string)$actor['role'] === 'it'
        ? 'Target user was not found.'
        : 'Target user was not found in your tenant.';
    } else {
      if (!can_access_profile_target($actor, $targetUser)) {
        http_response_code(403);
        exit('Forbidden');
      }

      $assignableRoles = assignable_roles_for_actor($actor);
      if (!in_array($targetRole, $assignableRoles, true)) {
        $error = 'You are not allowed to assign this target role.';
        $page = 'dashboard';
      } else {
      if ((string)$actor['role'] === 'it') {
        $updateStmt = $pdo->prepare('UPDATE users SET role = ?, is_active = ? WHERE id = ?');
        $updateStmt->execute([$targetRole, $targetActive, $targetUserId]);
      } else {
        $updateStmt = $pdo->prepare('UPDATE users SET role = ?, is_active = ? WHERE id = ? AND tenant_id = ?');
        $updateStmt->execute([$targetRole, $targetActive, $targetUserId, (int)$actor['tenant_id']]);
      }

      if ($targetEmailVerificationStatus === 'verified') {
        if ((string)$actor['role'] === 'it') {
          $verificationStmt = $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?');
          $verificationStmt->execute([$targetUserId]);
        } else {
          $verificationStmt = $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ? AND tenant_id = ?');
          $verificationStmt->execute([$targetUserId, (int)$actor['tenant_id']]);
        }
      } elseif ($targetEmailVerificationStatus === 'unverified') {
        if ((string)$actor['role'] === 'it') {
          $verificationStmt = $pdo->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?');
          $verificationStmt->execute([$targetUserId]);
        } else {
          $verificationStmt = $pdo->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ? AND tenant_id = ?');
          $verificationStmt->execute([$targetUserId, (int)$actor['tenant_id']]);
        }
      }

      write_audit_log(
        $pdo,
        (int)$actor['tenant_id'],
        (int)$actor['id'],
        'admin_support_update_role_status',
        'user',
        $targetUserId,
        [
          'target_email' => (string)($targetUser['email'] ?? ''),
          'new_role' => $targetRole,
          'new_is_active' => $targetActive,
          'new_email_verification_status' => $targetEmailVerificationStatus,
        ]
      );

      $emailStatusForMessage = $targetEmailVerificationStatus !== ''
        ? $targetEmailVerificationStatus
        : (trim((string)($targetUser['email_verified_at'] ?? '')) !== '' ? 'verified' : 'unverified');
      $message = 'User updated: role=' . $targetRole . ', status=' . ($targetActive === 1 ? 'active' : 'disabled') . ', email=' . $emailStatusForMessage . '.';
      }
    }

    $page = 'dashboard';
  }
}

if ($page === 'admin_user_support' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $actor = require_login();
  if (!in_array((string)$actor['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $action = trim((string)($_POST['support_action'] ?? ''));
  $targetEmail = normalize_email((string)($_POST['target_email'] ?? ''));
  $adminSupportTargetEmail = $targetEmail;

  if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    $error = 'Provide a valid target email.';
    $page = 'dashboard';
  } else {
    if ((string)$actor['role'] === 'it') {
      $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, is_active, email_verified_at FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
      $targetStmt->execute([$targetEmail]);
    } else {
      $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, is_active, email_verified_at FROM users WHERE tenant_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1');
      $targetStmt->execute([(int)$actor['tenant_id'], $targetEmail]);
    }
    $targetUser = $targetStmt->fetch();

    if (!$targetUser) {
      $error = (string)$actor['role'] === 'it'
        ? 'Target user not found.'
        : 'Target user not found in your tenant.';
      $page = 'dashboard';
    } else {
      if (!can_access_profile_target($actor, $targetUser)) {
        http_response_code(403);
        exit('Forbidden');
      }

      if ($action === 'unlock_user') {
        $unlockStmt = $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?');
        $unlockStmt->execute([(int)$targetUser['id']]);
        write_audit_log(
          $pdo,
          (int)$actor['tenant_id'],
          (int)$actor['id'],
          'admin_support_unlock_user',
          'user',
          (int)$targetUser['id'],
          ['target_email' => $targetEmail]
        );
        $message = 'User account unlocked (if it was locked).';
      } elseif ($action === 'clear_login_lockout') {
        clear_login_attempts_for_email($pdo, (string)$targetUser['email']);
        write_audit_log(
          $pdo,
          (int)$actor['tenant_id'],
          (int)$actor['id'],
          'admin_support_clear_login_lockout',
          'user',
          (int)$targetUser['id'],
          ['target_email' => $targetEmail]
        );
        $message = 'Login lockout counters cleared for this user.';
      } elseif ($action === 'resend_verification') {
        if (trim((string)($targetUser['email_verified_at'] ?? '')) !== '') {
          $message = 'User email is already verified.';
        } else {
          $issue = email_verification_issue($pdo, $config, $targetUser, app_route('verify_email'));
          if (($issue['ok'] ?? false) === true) {
            write_audit_log(
              $pdo,
              (int)$actor['tenant_id'],
              (int)$actor['id'],
              'admin_support_resend_verification',
              'user',
              (int)$targetUser['id'],
              ['target_email' => $targetEmail, 'method' => (string)($issue['method'] ?? 'unknown')]
            );
            $message = (($issue['method'] ?? 'code') === 'code')
              ? 'Verification code re-sent to user email.'
              : 'Verification link re-sent to user email.';
          } else {
            $error = (string)($issue['reason'] ?? 'Failed to resend verification.');
          }
        }
      } elseif ($action === 'verification_attempts') {
        $attemptStmt = $pdo->prepare(
          'SELECT id, channel, created_at, expires_at, consumed_at
           FROM email_verification_challenges
           WHERE user_id = ?
           ORDER BY id DESC
           LIMIT 20'
        );
        $attemptStmt->execute([(int)$targetUser['id']]);
        $adminSupportRows = $attemptStmt->fetchAll();
        write_audit_log(
          $pdo,
          (int)$actor['tenant_id'],
          (int)$actor['id'],
          'admin_support_view_verification_attempts',
          'user',
          (int)$targetUser['id'],
          ['target_email' => $targetEmail, 'rows' => count($adminSupportRows)]
        );
        $message = 'Loaded verification attempts for ' . $targetEmail . '.';
      } elseif ($action === 'login_lockout_status') {
        if (!login_attempts_ready($pdo)) {
          $error = 'Login attempts table is not available. Run sql/migrations/20260605_add_login_attempts.sql.';
        } else {
          $policy = is_array($config['security']['login_lockout'] ?? null) ? $config['security']['login_lockout'] : [];
          $lockoutEnabled = ($policy['enabled'] ?? true) === true;
          $lockoutThreshold = max(1, (int)($policy['failure_threshold'] ?? 8));
          $lockoutWindowSeconds = max(60, (int)($policy['window_seconds'] ?? 900));

          $summaryStmt = $pdo->prepare(
            'SELECT
               SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed_count,
               SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success_count,
               MAX(CASE WHEN success = 0 THEN created_at END) AS last_failed_at
             FROM login_attempts
             WHERE email_normalized = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
          );
          $summaryStmt->execute([$targetEmail, $lockoutWindowSeconds]);
          $summary = $summaryStmt->fetch() ?: [];

          $failedCount = (int)($summary['failed_count'] ?? 0);
          $successCount = (int)($summary['success_count'] ?? 0);
          $lastFailedAt = trim((string)($summary['last_failed_at'] ?? ''));
          $isLocked = $lockoutEnabled && $failedCount >= $lockoutThreshold;

          $adminLoginAttemptSummary = [
            'email' => $targetEmail,
            'failed_count' => $failedCount,
            'success_count' => $successCount,
            'window_seconds' => $lockoutWindowSeconds,
            'threshold' => $lockoutThreshold,
            'enabled' => $lockoutEnabled,
            'is_locked' => $isLocked,
            'last_failed_at' => $lastFailedAt,
          ];

          $rowsStmt = $pdo->prepare(
            'SELECT id, ip_address, success, created_at
             FROM login_attempts
             WHERE email_normalized = ?
             ORDER BY id DESC
             LIMIT 20'
          );
          $rowsStmt->execute([$targetEmail]);
          $adminLoginAttemptRows = $rowsStmt->fetchAll();
          write_audit_log(
            $pdo,
            (int)$actor['tenant_id'],
            (int)$actor['id'],
            'admin_support_view_login_lockout',
            'user',
            (int)$targetUser['id'],
            [
              'target_email' => $targetEmail,
              'failed_count' => $failedCount,
              'success_count' => $successCount,
              'window_seconds' => $lockoutWindowSeconds,
              'threshold' => $lockoutThreshold,
              'is_locked' => $isLocked,
            ]
          );
          $message = 'Loaded login lockout status for ' . $targetEmail . '.';
        }
      } else {
        $error = 'Unsupported admin support action.';
      }

      $page = 'dashboard';
    }
  }
}

  if ($page === 'create_scholarship' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $user = require_login();
    if (!in_array((string)$user['role'], ['admin', 'it'], true)) {
      http_response_code(403);
      exit('Forbidden');
    }

    $scholarshipId = (int)($_POST['scholarship_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = (string)($_POST['status'] ?? 'draft');
    $rawSchema = json_decode((string)($_POST['form_schema_json'] ?? '[]'), true);
    $rawSettings = json_decode((string)($_POST['form_settings_json'] ?? '{}'), true);
    $schemaNodes = normalize_scholarship_nodes($rawSchema);
    $schema = flatten_scholarship_nodes($schemaNodes);
    $formSettings = normalize_form_settings($rawSettings);

    if ($title === '') {
      $error = 'Scholarship title is required.';
    } elseif ($schema === [] || count_input_fields($schema) < 1) {
      $error = 'At least one valid form field is required inside a form section.';
    } elseif (!in_array($status, ['draft', 'published', 'closed'], true)) {
      $error = 'Invalid scholarship status.';
    } elseif ($scholarshipId > 0) {
      $existsStmt = $pdo->prepare('SELECT id FROM scholarships WHERE id = ? AND tenant_id = ? LIMIT 1');
      $existsStmt->execute([$scholarshipId, $user['tenant_id']]);
      if (!$existsStmt->fetch()) {
        $error = 'Scholarship not found.';
      }
    }

    if ($error === '' && $scholarshipId > 0) {
      $updateStmt = $pdo->prepare('UPDATE scholarships SET title = ?, description = ?, status = ?, form_schema_json = ? WHERE id = ? AND tenant_id = ?');
      $schemaJson = scholarship_schema_payload_json($schemaNodes, $formSettings);
      $updateStmt->execute([
        $title,
        $description !== '' ? $description : null,
        $status,
        $schemaJson,
        $scholarshipId,
        $user['tenant_id'],
      ]);

      if (scholarship_form_versioning_ready($pdo)) {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) AS max_version FROM scholarship_form_versions WHERE scholarship_id = ? AND tenant_id = ?');
        $maxStmt->execute([$scholarshipId, $user['tenant_id']]);
        $maxVersion = (int)($maxStmt->fetch()['max_version'] ?? 0);
        $nextVersion = max(1, $maxVersion + 1);
        $versionStatus = $status === 'published' ? 'published' : 'draft';

        $insertVersion = $pdo->prepare(
          'INSERT INTO scholarship_form_versions (scholarship_id, tenant_id, version_no, status, form_schema_json, created_by)
           VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertVersion->execute([
          $scholarshipId,
          (int)$user['tenant_id'],
          $nextVersion,
          $versionStatus,
          $schemaJson,
          (int)$user['id'],
        ]);

        if ($versionStatus === 'published') {
          $archiveStmt = $pdo->prepare(
            'UPDATE scholarship_form_versions
             SET status = "archived"
             WHERE scholarship_id = ? AND tenant_id = ? AND version_no <> ? AND status = "published"'
          );
          $archiveStmt->execute([$scholarshipId, (int)$user['tenant_id'], $nextVersion]);
        }

        $message = 'Scholarship updated. New form version v' . (string)$nextVersion . ' saved.';
      } else {
        $message = 'Scholarship updated successfully.';
      }
      $page = 'dashboard';
    } else {
      if ($error === '') {
        $schemaJson = scholarship_schema_payload_json($schemaNodes, $formSettings);
        $stmt = $pdo->prepare('INSERT INTO scholarships (tenant_id, title, description, status, form_schema_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
          $user['tenant_id'],
          $title,
          $description !== '' ? $description : null,
          $status,
          $schemaJson,
          $user['id'],
        ]);
        $scholarshipId = (int)$pdo->lastInsertId();

        if (scholarship_form_versioning_ready($pdo)) {
          $versionStmt = $pdo->prepare(
            'INSERT INTO scholarship_form_versions (scholarship_id, tenant_id, version_no, status, form_schema_json, created_by)
             VALUES (?, ?, 1, ?, ?, ?)'
          );
          $versionStmt->execute([
            $scholarshipId,
            (int)$user['tenant_id'],
            $status === 'published' ? 'published' : 'draft',
            $schemaJson,
            (int)$user['id'],
          ]);
        }

        $message = 'Scholarship created successfully.';
        $page = 'dashboard';
      }
    }
  }

if ($page === 'publish_scholarship_version' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
  require_csrf();
  $user = require_login();
  if (!in_array((string)$user['role'], ['admin', 'it'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }

  $scholarshipId = (int)($_POST['scholarship_id'] ?? 0);
  $versionNo = (int)($_POST['version_no'] ?? 0);

  if ($scholarshipId <= 0 || $versionNo <= 0) {
    $error = 'Invalid scholarship version selection.';
    $page = 'dashboard';
  } elseif (!scholarship_form_versioning_ready($pdo)) {
    $error = 'Form versioning table is not available. Run migrations first.';
    $page = 'dashboard';
  } else {
    $versionStmt = $pdo->prepare(
      'SELECT form_schema_json FROM scholarship_form_versions
       WHERE scholarship_id = ? AND tenant_id = ? AND version_no = ?
       LIMIT 1'
    );
    $versionStmt->execute([$scholarshipId, (int)$user['tenant_id'], $versionNo]);
    $versionRow = $versionStmt->fetch();

    if (!$versionRow) {
      $error = 'Selected version not found.';
      $page = 'dashboard';
    } else {
      $schemaJson = (string)$versionRow['form_schema_json'];

      $updateScholarship = $pdo->prepare('UPDATE scholarships SET status = "published", form_schema_json = ? WHERE id = ? AND tenant_id = ?');
      $updateScholarship->execute([$schemaJson, $scholarshipId, (int)$user['tenant_id']]);

      $archiveStmt = $pdo->prepare(
        'UPDATE scholarship_form_versions
         SET status = CASE WHEN version_no = ? THEN "published" WHEN status = "published" THEN "archived" ELSE status END
         WHERE scholarship_id = ? AND tenant_id = ?'
      );
      $archiveStmt->execute([$versionNo, $scholarshipId, (int)$user['tenant_id']]);

      $message = 'Published scholarship form version v' . (string)$versionNo . '.';
      $page = 'dashboard';
    }
  }
}

if ($page === 'decide' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $user = require_login();
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $applicationId = (int)($_POST['application_id'] ?? 0);
    $status = (string)($_POST['status'] ?? 'in_review');
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (!in_array($status, ['approved', 'rejected'], true)) {
        $status = 'in_review';
    }

    $stmt = $pdo->prepare('UPDATE applications SET status = ?, rejection_reason = ? WHERE id = ?');
    $stmt->execute([$status, $reason !== '' ? $reason : null, $applicationId]);

    $audit = $pdo->prepare('INSERT INTO audit_logs (tenant_id, actor_user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?)');
    $audit->execute([
        $user['tenant_id'],
        $user['id'],
        'application_' . $status,
        'application',
        $applicationId,
        json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
    ]);

    $metaStmt = $pdo->prepare('SELECT a.scholarship_id, s.title AS scholarship_title, s.form_schema_json, st.email AS student_email
      FROM applications a
      JOIN scholarships s ON s.id = a.scholarship_id
      JOIN users st ON st.id = a.student_id
      WHERE a.id = ? AND a.tenant_id = ? LIMIT 1');
    $metaStmt->execute([$applicationId, $user['tenant_id']]);
    $decisionMeta = $metaStmt->fetch() ?: [];

    $decisionNodes = normalize_scholarship_nodes(json_decode((string)($decisionMeta['form_schema_json'] ?? '[]'), true));
    $decisionSchema = flatten_scholarship_nodes($decisionNodes);

    enqueue_internal_notification($pdo, [
        'event' => 'application_' . $status,
        'notification_type' => 'webhook',
        'route' => 'n8n_global',
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
      'scholarship_id' => (int)($decisionMeta['scholarship_id'] ?? 0),
      'scholarship_title' => (string)($decisionMeta['scholarship_title'] ?? ''),
      'student_email' => (string)($decisionMeta['student_email'] ?? ''),
      'status' => $status,
      'reason' => $reason,
      'form_text_rules' => form_schema_text_rules($decisionSchema),
        'by' => $user['email'],
      'changed_at' => gmdate('c'),
    ]);
    process_notification_queue($pdo, $config, 5);

    if (smtp_is_ready($config)) {
      $studentStmt = $pdo->prepare('SELECT u.full_name, u.email FROM applications a JOIN users u ON u.id = a.student_id WHERE a.id = ? AND a.tenant_id = ? LIMIT 1');
      $studentStmt->execute([$applicationId, $user['tenant_id']]);
      $studentRecipient = $studentStmt->fetch();
      if ($studentRecipient) {
        $statusText = strtoupper($status);
        $note = $reason !== '' ? '<p>Reason: ' . h($reason) . '</p>' : '';
        send_smtp_mail(
          $config,
          (string)$studentRecipient['email'],
          (string)$studentRecipient['full_name'],
          'Application ' . $statusText . ' #' . $applicationId,
          '<p>Your application status changed to <strong>' . $statusText . '</strong>.</p>' . $note
        );
      }
    }

    $message = 'Application updated.';
    $page = 'dashboard';
}

if ($page === 'verify_email' && $pdo) {
  $pendingUserId = (int)($_SESSION['pending_email_verification_user_id'] ?? 0);
  if ($pendingUserId > 0) {
    $verifyChallengeMeta = email_verification_latest_pending_challenge($pdo, $pendingUserId);
  }
}

$captchaEnabled = captcha_is_enabled($config);
$captchaScriptUrl = captcha_script_url($config);

$user = current_user();
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($config['app_name']) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= h(app_asset('assets/favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= h(app_asset('assets/css/style.css')) ?>">
</head>
<body>
  <div class="container">
    <header>
      <h1><?= h($config['app_name']) ?></h1>
      <nav>
        <?php if ($user): ?>
          <a href="<?= h(app_route('dashboard')) ?>">Dashboard</a>
          <a href="<?= h(app_route('profile')) ?>">Profile</a>
          <?php if (in_array((string)$user['role'], ['admin', 'it'], true)): ?>
            <a href="<?= h(app_route('form_builder')) ?>">Form Builder</a>
            <a href="<?= h(app_route('identity_diagnostics')) ?>">Identity Diagnostics</a>
            <?php if ((string)$user['role'] === 'it'): ?>
              <a href="<?= h(app_route('phone_codes')) ?>">Phone Codes</a>
            <?php endif; ?>
          <?php endif; ?>
          <a href="<?= h(app_route('logout')) ?>">Logout</a>
        <?php else: ?>
          <a href="<?= h(app_route('login')) ?>">Login</a>
          <?php if (($config['registration']['enabled'] ?? true) === true): ?>
            <a href="<?= h(app_route('register')) ?>">Register</a>
          <?php endif; ?>
        <?php endif; ?>
      </nav>
    </header>

    <?php if ($dbError): ?>
      <div class="alert err"><?= h($dbError) ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
      <div class="alert ok"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert err"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($page === 'home'): ?>
      <h2>Local MVP Foundation</h2>
      <p>This starter includes role dashboards and a basic application workflow powered by an internal notification queue.</p>
      <a class="btn primary" href="<?= h(app_route('login')) ?>">Start</a>
      <?php if (($config['registration']['enabled'] ?? true) === true): ?>
        <a class="btn" href="<?= h(app_route('register')) ?>">Create Account</a>
      <?php endif; ?>

    <?php elseif ($page === 'login'): ?>
      <h2>Login</h2>
      <p>Demo password for seeded users: <strong>Password123!</strong></p>
      <form method="post" action="<?= h(app_route('login')) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label>Email</label>
        <input name="email" type="email" required>
        <label>Password</label>
        <input name="password" type="password" required>
        <?php if ($captchaEnabled): ?>
          <?= captcha_widget_markup($config) ?>
        <?php endif; ?>
        <br><br>
        <button class="btn primary" type="submit">Sign in</button>
      </form>

      <p><a href="<?= h(app_route('forgot_password')) ?>">Forgot password?</a></p>

      <hr>
      <h3>Email OTP Login</h3>
      <form method="post" action="<?= h(app_route('login_otp_request')) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label>Email</label>
        <input name="email" type="email" required>
        <br><br>
        <button class="btn" type="submit">Send OTP</button>
      </form>

      <?php if (!empty($_SESSION['otp_user_email'])): ?>
        <form method="post" action="<?= h(app_route('login_otp_verify')) ?>" style="margin-top: 10px;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <p>OTP sent to: <strong><?= h((string)$_SESSION['otp_user_email']) ?></strong></p>
          <label>OTP Code</label>
          <input name="otp_code" type="text" inputmode="numeric" maxlength="10" required>
          <br><br>
          <button class="btn primary" type="submit">Verify OTP</button>
        </form>
      <?php endif; ?>

      <hr>
      <h3>Microsoft Login</h3>
      <?php if (microsoft_oauth_ready($config)): ?>
        <a class="btn" href="<?= h(app_route('auth_microsoft_start')) ?>">Sign in with Microsoft</a>
      <?php else: ?>
        <p>Microsoft OAuth is not fully configured yet.</p>
      <?php endif; ?>

      <hr>
      <h3>Google Login</h3>
      <?php if (google_oauth_ready($config)): ?>
        <a class="btn" href="<?= h(app_route('auth_google_start')) ?>">Sign in with Google</a>
      <?php else: ?>
        <p>Google OAuth is not fully configured yet.</p>
      <?php endif; ?>

      <?php if (($config['registration']['enabled'] ?? true) === true): ?>
        <hr>
        <p>New here? <a href="<?= h(app_route('register')) ?>">Create an account</a></p>
      <?php endif; ?>

    <?php elseif ($page === 'forgot_password'): ?>
      <h2>Forgot Password</h2>
      <p>Enter your account email and we will send a secure reset link.</p>
      <form method="post" action="<?= h(app_route('forgot_password')) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label>Email</label>
        <input name="email" type="email" required>
        <?php if ($captchaEnabled): ?>
          <?= captcha_widget_markup($config) ?>
        <?php endif; ?>
        <br><br>
        <button class="btn primary" type="submit">Send Reset Link</button>
        <a class="btn" href="<?= h(app_route('login')) ?>">Back to Login</a>
      </form>

    <?php elseif ($page === 'reset_password'): ?>
      <h2>Reset Password</h2>
      <form method="post" action="<?= h(app_route('reset_password')) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= h($resetTokenForForm) ?>">
        <label>New Password</label>
        <input name="password" type="password" minlength="8" required>
        <label>Confirm Password</label>
        <input name="confirm_password" type="password" minlength="8" required>
        <?php if ($captchaEnabled): ?>
          <?= captcha_widget_markup($config) ?>
        <?php endif; ?>
        <br><br>
        <button class="btn primary" type="submit">Update Password</button>
        <a class="btn" href="<?= h(app_route('login')) ?>">Back to Login</a>
      </form>

    <?php elseif ($page === 'verify_email'): ?>
      <?php
        $verifyExpiresTs = (int)($verifyChallengeMeta['expires_at_ts'] ?? 0);
        $verifyCreatedTs = (int)($verifyChallengeMeta['created_at_ts'] ?? 0);
        $resendCooldown = email_verification_resend_cooldown_seconds($config);
      ?>
      <h2>Verify Email</h2>
      <p>Account: <strong><?= h((string)($_SESSION['pending_email_verification_email'] ?? '')) ?></strong></p>
      <?php if ($verifyExpiresTs > 0): ?>
        <p id="verify-expiry-timer" data-expires-at="<?= h((string)$verifyExpiresTs) ?>">Code expires in --:--</p>
      <?php endif; ?>

      <?php if (email_verification_method($config) === 'code'): ?>
        <form method="post" action="<?= h(app_route('verify_email')) ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="verify_code">
          <label>Verification Code</label>
          <input name="verification_code" type="text" inputmode="numeric" maxlength="10" required>
          <br><br>
          <button class="btn primary" type="submit">Verify Email</button>
        </form>
      <?php else: ?>
        <p>Check your inbox and click the verification link to continue.</p>
      <?php endif; ?>

      <form method="post" action="<?= h(app_route('verify_email')) ?>" style="margin-top: 10px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend">
        <button
          class="btn"
          type="submit"
          id="resend-verification-btn"
          data-created-at="<?= h((string)$verifyCreatedTs) ?>"
          data-cooldown-seconds="<?= h((string)$resendCooldown) ?>"
        >Resend Verification</button>
        <span id="resend-cooldown-text"></span>
        <a class="btn" href="<?= h(app_route('login')) ?>">Back to Login</a>
      </form>

    <?php elseif ($page === 'register'): ?>
      <h2>Create Account</h2>
      <?php if (($config['registration']['enabled'] ?? true) !== true): ?>
        <p>Registration is currently disabled.</p>
        <a class="btn" href="<?= h(app_route('login')) ?>">Back to Login</a>
      <?php else: ?>
        <?php
          $registerPhoneCountries = [];
          $registerDefaultCode = '+90';
          if ($pdo && phone_country_codes_ready($pdo)) {
            $registerPhoneCountries = phone_country_code_rows($pdo, true);
            $registerDefaultCode = phone_default_country_code($pdo, '+90');
          }
          $registerSelectedCode = trim((string)($registerOld['phone_country_code'] ?? $registerDefaultCode));
        ?>
        <form method="post" action="<?= h(app_route('register')) ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <label>Full Name</label>
          <input name="full_name" type="text" value="<?= h((string)$registerOld['full_name']) ?>" required>
          <label>Email</label>
          <input name="email" type="email" value="<?= h((string)$registerOld['email']) ?>" required>
          <label>Phone Country Code</label>
          <select name="phone_country_code" required>
            <option value="">Select code...</option>
            <?php foreach ($registerPhoneCountries as $country): ?>
              <?php $dialCode = trim((string)($country['dial_code'] ?? '')); ?>
              <?php if ($dialCode === '') { continue; } ?>
              <option value="<?= h($dialCode) ?>" <?= $registerSelectedCode === $dialCode ? 'selected' : '' ?>>
                <?= h($dialCode . ' - ' . (string)($country['country_name'] ?? 'Country')) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <label>Phone Number</label>
          <input name="phone_number" type="text" inputmode="numeric" value="<?= h((string)($registerOld['phone_number'] ?? '')) ?>" required>
          <label>Password</label>
          <input name="password" type="password" minlength="8" required>
          <label>Confirm Password</label>
          <input name="confirm_password" type="password" minlength="8" required>
          <?php if ($captchaEnabled): ?>
            <?= captcha_widget_markup($config) ?>
          <?php endif; ?>
          <br><br>
          <button class="btn primary" type="submit">Create Account</button>
          <a class="btn" href="<?= h(app_route('login')) ?>">Back to Login</a>
        </form>
      <?php endif; ?>

    <?php elseif ($page === 'profile' && $user && $pdo): ?>
      <?php require __DIR__ . '/views/profile.php'; ?>

    <?php elseif ($page === 'dashboard' && $user): ?>
      <?php require __DIR__ . '/views/dashboard.php'; ?>

    <?php elseif ($page === 'form_builder' && $user && $pdo): ?>
      <?php require __DIR__ . '/views/form_builder.php'; ?>

    <?php elseif ($page === 'phone_codes' && $user && $pdo): ?>
      <?php require __DIR__ . '/views/phone_codes.php'; ?>

    <?php elseif ($page === 'identity_diagnostics' && $user && $pdo): ?>
      <?php require __DIR__ . '/views/identity_diagnostics.php'; ?>

    <?php else: ?>
      <h2>Not found</h2>
      <a href="/">Back</a>
    <?php endif; ?>
  </div>
  <?php if ($captchaEnabled): ?>
    <script src="<?= h($captchaScriptUrl) ?>" async defer></script>
  <?php endif; ?>
</body>
<script>
(function () {
  function initApplicationForms() {
    const applyForms = document.querySelectorAll('form.scholarship-apply-form');
    if (!applyForms.length) {
      return;
    }

    function getFieldValue(form, fieldName) {
      const phoneCode = form.querySelector('[name="answers[' + fieldName + '][country_code]"]');
      const phoneNumber = form.querySelector('[name="answers[' + fieldName + '][number]"]');
      if (phoneCode || phoneNumber) {
        return {
          country_code: phoneCode ? phoneCode.value : '',
          number: phoneNumber ? phoneNumber.value : '',
        };
      }

      const radios = form.querySelectorAll('input[type="radio"][name="answers[' + fieldName + ']"]');
      if (radios.length) {
        const checked = form.querySelector('input[type="radio"][name="answers[' + fieldName + ']"]:checked');
        return checked ? checked.value : '';
      }

      const checks = form.querySelectorAll('input[type="checkbox"][name="answers[' + fieldName + '][]"]');
      if (checks.length) {
        const out = [];
        checks.forEach(function (cb) {
          if (cb.checked) {
            out.push(cb.value);
          }
        });
        return out;
      }

      const input = form.querySelector('[name="answers[' + fieldName + ']"]');
      return input ? input.value : '';
    }

    function setFieldValue(form, fieldName, value) {
      const phoneCode = form.querySelector('[name="answers[' + fieldName + '][country_code]"]');
      const phoneNumber = form.querySelector('[name="answers[' + fieldName + '][number]"]');
      if (phoneCode || phoneNumber) {
        const phoneValue = (value && typeof value === 'object') ? value : {};
        if (phoneCode) {
          phoneCode.value = String(phoneValue.country_code || '');
        }
        if (phoneNumber) {
          phoneNumber.value = String(phoneValue.number || '');
        }
        return;
      }

      const radios = form.querySelectorAll('input[type="radio"][name="answers[' + fieldName + ']"]');
      if (radios.length) {
        radios.forEach(function (r) {
          r.checked = String(value) === r.value;
        });
        return;
      }

      const checks = form.querySelectorAll('input[type="checkbox"][name="answers[' + fieldName + '][]"]');
      if (checks.length) {
        const values = Array.isArray(value) ? value.map(String) : [];
        checks.forEach(function (cb) {
          cb.checked = values.includes(cb.value);
        });
        return;
      }

      const input = form.querySelector('[name="answers[' + fieldName + ']"]');
      if (input) {
        input.value = typeof value === 'string' ? value : '';
      }
    }

    function isVisibleByCondition(actual, operator, value) {
      if (!operator || !value) {
        return true;
      }

      if (Array.isArray(actual)) {
        if (operator === 'contains' || operator === 'equals') {
          return actual.includes(value);
        }
        if (operator === 'not_equals') {
          return !actual.includes(value);
        }
        return false;
      }

      const actualStr = String(actual || '').trim();

      function compareValues(left, right) {
        const leftNum = Number(left);
        const rightNum = Number(right);
        if (!Number.isNaN(leftNum) && !Number.isNaN(rightNum)) {
          if (leftNum < rightNum) return -1;
          if (leftNum > rightNum) return 1;
          return 0;
        }

        const dateRe = /^\d{4}-\d{2}-\d{2}$/;
        const timeRe = /^([01]\d|2[0-3]):[0-5]\d$/;
        if ((dateRe.test(left) && dateRe.test(right)) || (timeRe.test(left) && timeRe.test(right))) {
          return left.localeCompare(right);
        }

        return left.localeCompare(right);
      }

      if (operator === 'equals') {
        return actualStr === value;
      }
      if (operator === 'not_equals') {
        return actualStr !== value;
      }
      if (operator === 'contains') {
        return actualStr.indexOf(value) !== -1;
      }
      if (['gt', 'gte', 'lt', 'lte'].includes(operator)) {
        const cmp = compareValues(actualStr, value);
        if (operator === 'gt') return cmp > 0;
        if (operator === 'gte') return cmp >= 0;
        if (operator === 'lt') return cmp < 0;
        return cmp <= 0;
      }
      return true;
    }

    function isVisibleByGroup(form, groupData) {
      if (!groupData || typeof groupData !== 'object') {
        return true;
      }
      const mode = String(groupData.mode || 'AND').toUpperCase() === 'OR' ? 'OR' : 'AND';
      const conditions = Array.isArray(groupData.conditions) ? groupData.conditions : [];
      if (!conditions.length) {
        return true;
      }

      const results = [];
      conditions.forEach(function (cond) {
        if (!cond || typeof cond !== 'object') {
          return;
        }
        const field = String(cond.field || '').trim();
        const operator = String(cond.operator || '').trim();
        const value = String(cond.value || '').trim();
        if (!field || !operator || !value) {
          return;
        }
        const actual = getFieldValue(form, field);
        results.push(isVisibleByCondition(actual, operator, value));
      });

      if (!results.length) {
        return true;
      }
      if (mode === 'OR') {
        return results.some(Boolean);
      }
      return results.every(Boolean);
    }

    function isLatinArabicText(value) {
      const trimmed = String(value || '').trim();
      if (!trimmed) {
        return true;
      }
      try {
        return /^[\p{Script=Latin}\p{Script=Arabic}\p{M}\s'\-]+$/u.test(trimmed);
      } catch (e) {
        return true;
      }
    }

    function isArabicOnlyText(value) {
      const trimmed = String(value || '').trim();
      if (!trimmed) {
        return true;
      }
      try {
        return /^[\p{Script=Arabic}\p{M}\s'\-]+$/u.test(trimmed);
      } catch (e) {
        return true;
      }
    }

    function isEnglishOnlyText(value) {
      const trimmed = String(value || '').trim();
      if (!trimmed) {
        return true;
      }
      return /^[A-Za-z\s'\-]+$/.test(trimmed);
    }

    function isTurkishLatinOnlyText(value) {
      const trimmed = String(value || '').trim();
      if (!trimmed) {
        return true;
      }
      try {
        return /^[A-Za-zÇĞİÖŞÜçğıöşü\s'\-]+$/u.test(trimmed);
      } catch (e) {
        return true;
      }
    }

    function textRuleErrorMessage(rule) {
      if (rule === 'arabic_only') {
        return 'This field accepts Arabic letters only.';
      }
      if (rule === 'english_only') {
        return 'This field accepts English letters only.';
      }
      if (rule === 'turkish_latin_only') {
        return 'This field accepts Turkish Latin letters only.';
      }
      return 'This field accepts English or Arabic letters only.';
    }

    applyForms.forEach(function (form) {
      const scholarshipId = form.getAttribute('data-scholarship-id') || '0';
      const autosaveSeconds = Math.max(5, Math.min(300, Number(form.getAttribute('data-autosave-seconds') || '30')));
      const autosaveDelayMs = autosaveSeconds * 1000;
      const draftKey = 'scholarship_form_draft_' + scholarshipId;
      const nodeTypes = ['welcome', 'agreement', 'section', 'form', 'thank_you'];
      let steps = [];
      let activeStepIndex = 0;
      let stepNav = null;
      let saveDraftTimer = null;

      function updateVisibility() {
        form.querySelectorAll('.dynamic-field').forEach(function (wrapper) {
          const groupRaw = (wrapper.getAttribute('data-visible-if-group') || '').trim();
          if (groupRaw) {
            try {
              const groupData = JSON.parse(groupRaw);
              const visible = isVisibleByGroup(form, groupData);
              wrapper.style.display = visible ? '' : 'none';
              wrapper.setAttribute('data-condition-visible', visible ? '1' : '0');
              wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
                el.disabled = !visible;
              });
              return;
            } catch (e) {
            }
          }

          const dependsOn = (wrapper.getAttribute('data-visible-if-field') || '').trim();
          const operator = (wrapper.getAttribute('data-visible-if-operator') || '').trim();
          const value = (wrapper.getAttribute('data-visible-if-value') || '').trim();
          if (!dependsOn || !operator || !value) {
            wrapper.style.display = '';
            wrapper.setAttribute('data-condition-visible', '1');
            wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
              el.disabled = false;
            });
            return;
          }

          const actual = getFieldValue(form, dependsOn);
          const visible = isVisibleByCondition(actual, operator, value);
          wrapper.setAttribute('data-condition-visible', visible ? '1' : '0');

          wrapper.style.display = visible ? '' : 'none';
          wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = !visible;
          });
        });
      }

      function splitSteps() {
        const wrappers = Array.from(form.querySelectorAll('.dynamic-field'));
        const grouped = [];
        let current = [];

        wrappers.forEach(function (wrapper) {
          const type = (wrapper.getAttribute('data-field-type') || '').trim();
          if (nodeTypes.includes(type)) {
            if (current.length) {
              grouped.push(current);
            }
            current = [wrapper];
          } else {
            current.push(wrapper);
          }
        });

        if (current.length) {
          grouped.push(current);
        }

        steps = grouped.length ? grouped : [wrappers];
      }

      function ensureStepNav() {
        if (stepNav) {
          return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        if (!submitBtn) {
          return;
        }

        submitBtn.style.display = 'none';
        stepNav = document.createElement('div');
        stepNav.className = 'step-nav';
        stepNav.innerHTML =
          '<button type="button" class="btn step-prev">Back</button>' +
          '<span class="step-indicator"></span>' +
          '<button type="button" class="btn step-next">Next</button>' +
          '<button type="submit" class="btn primary step-submit">Submit Application</button>';
        submitBtn.parentNode.insertBefore(stepNav, submitBtn.nextSibling);

        stepNav.querySelector('.step-prev').addEventListener('click', function () {
          if (activeStepIndex > 0) {
            activeStepIndex -= 1;
            renderStep();
          }
        });

        stepNav.querySelector('.step-next').addEventListener('click', function () {
          if (activeStepIndex < steps.length - 1) {
            activeStepIndex += 1;
            renderStep();
          }
        });
      }

      function renderStep() {
        if (!steps.length) {
          return;
        }

        if (activeStepIndex < 0) {
          activeStepIndex = 0;
        }
        if (activeStepIndex > steps.length - 1) {
          activeStepIndex = steps.length - 1;
        }

        const activeSet = new Set(steps[activeStepIndex]);
        form.querySelectorAll('.dynamic-field').forEach(function (wrapper) {
          const isInStep = activeSet.has(wrapper);
          const visibleByCondition = (wrapper.getAttribute('data-condition-visible') || '1') === '1';
          if (isInStep && visibleByCondition) {
            wrapper.style.display = '';
            wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
              el.disabled = false;
            });
          } else {
            wrapper.style.display = 'none';
            wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
              el.disabled = true;
            });
          }
        });

        if (stepNav) {
          const prev = stepNav.querySelector('.step-prev');
          const next = stepNav.querySelector('.step-next');
          const submit = stepNav.querySelector('.step-submit');
          const indicator = stepNav.querySelector('.step-indicator');
          const isFirst = activeStepIndex === 0;
          const isLast = activeStepIndex === steps.length - 1;

          prev.style.display = isFirst ? 'none' : '';
          next.style.display = isLast ? 'none' : '';
          submit.style.display = isLast ? '' : 'none';
          indicator.textContent = 'Page ' + (activeStepIndex + 1) + ' of ' + steps.length;
        }
      }

      function validateTextRules() {
        let errorMessage = '';
        form.querySelectorAll('.dynamic-field').forEach(function (wrapper) {
          if (errorMessage !== '' || wrapper.style.display === 'none') {
            return;
          }
          const textRule = (wrapper.getAttribute('data-text-rule') || '').trim();
          if (!textRule || textRule === 'none') {
            return;
          }
          const fieldName = wrapper.getAttribute('data-field-name') || '';
          if (!fieldName) {
            return;
          }
          const rawValue = getFieldValue(form, fieldName);
          const value = Array.isArray(rawValue) ? rawValue.join(' ') : rawValue;
          let valid = true;

          if (textRule === 'arabic_only') {
            valid = isArabicOnlyText(value);
          } else if (textRule === 'english_only') {
            valid = isEnglishOnlyText(value);
          } else if (textRule === 'turkish_latin_only') {
            valid = isTurkishLatinOnlyText(value);
          } else {
            valid = isLatinArabicText(value);
          }

          if (!valid) {
            errorMessage = textRuleErrorMessage(textRule);
          }
        });
        return errorMessage;
      }

      function saveDraft() {
        const answers = {};
        form.querySelectorAll('.dynamic-field').forEach(function (wrapper) {
          const fieldName = wrapper.getAttribute('data-field-name') || '';
          if (!fieldName) {
            return;
          }
          answers[fieldName] = getFieldValue(form, fieldName);
        });
        localStorage.setItem(draftKey, JSON.stringify(answers));
      }

      function scheduleSaveDraft() {
        if (saveDraftTimer) {
          clearTimeout(saveDraftTimer);
        }
        saveDraftTimer = setTimeout(function () {
          saveDraft();
        }, autosaveDelayMs);
      }

      const rawDraft = localStorage.getItem(draftKey);
      if (rawDraft) {
        try {
          const draft = JSON.parse(rawDraft);
          if (draft && typeof draft === 'object') {
            Object.keys(draft).forEach(function (fieldName) {
              setFieldValue(form, fieldName, draft[fieldName]);
            });
          }
        } catch (e) {
          localStorage.removeItem(draftKey);
        }
      }

      updateVisibility();
      splitSteps();
      ensureStepNav();
      renderStep();

      form.addEventListener('change', function () {
        updateVisibility();
        splitSteps();
        renderStep();
        scheduleSaveDraft();
      });
      form.addEventListener('keyup', function () {
        scheduleSaveDraft();
      });
      form.addEventListener('submit', function (event) {
        const textRuleError = validateTextRules();
        if (textRuleError) {
          window.alert(textRuleError);
          event.preventDefault();
          return;
        }
        form.querySelectorAll('.dynamic-field input, .dynamic-field select, .dynamic-field textarea').forEach(function (el) {
          el.disabled = false;
        });
        localStorage.removeItem(draftKey);
      });
    });
  }

  initApplicationForms();

  const form = document.getElementById('create-scholarship-form');
  if (!form) {
    return;
  }

  const container = document.getElementById('fields-builder');
  const hiddenSchema = document.getElementById('form_schema_json');
  const hiddenSettings = document.getElementById('form_settings_json');
  const addBtn = document.getElementById('add-field-btn');
  const scholarshipIdInput = document.getElementById('scholarship_id');
  const titleInput = document.getElementById('scholarship_title_input');
  const descriptionInput = document.getElementById('scholarship_description_input');
  const statusInput = document.getElementById('scholarship_status_input');
  const modeLabel = document.getElementById('scholarship-editor-mode');
  const resetBtn = document.getElementById('reset-scholarship-editor');
  const previewEl = document.getElementById('scholarship-form-preview');
  const settingOneResponse = document.getElementById('setting_one_response_per_user');
  const settingAllowEditAfterSubmit = document.getElementById('setting_allow_edit_after_submit');
  const settingAutosaveSeconds = document.getElementById('setting_autosave_interval_seconds');
  const settingSubmissionStartAt = document.getElementById('setting_submission_start_at');
  const settingSubmissionEndAt = document.getElementById('setting_submission_end_at');
  const csrfInput = form.querySelector('input[name="csrf"]');
  const csrfToken = csrfInput ? csrfInput.value : '';
  const defaultFormSettings = {
    one_response_per_user: false,
    allow_edit_after_submit: false,
    autosave_interval_seconds: 30,
    submission_start_at: '',
    submission_end_at: ''
  };
  const uploadEndpoints = {
    image: '<?= h(app_route('scholarship_node_upload_image')) ?>',
    pdf: '<?= h(app_route('scholarship_node_upload_pdf')) ?>'
  };

  function uploadNodeFile(kind, file) {
    if (!file) {
      return Promise.reject(new Error('No file selected'));
    }
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append(kind === 'pdf' ? 'pdf_file' : 'image_file', file);
    return fetch(kind === 'pdf' ? uploadEndpoints.pdf : uploadEndpoints.image, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json().catch(function () {
        return { ok: false, error: 'invalid_json_response' };
      });
    });
  }

  function normalizedBuilderSettings(input) {
    const source = (input && typeof input === 'object') ? input : {};
    const out = {
      one_response_per_user: !!source.one_response_per_user,
      allow_edit_after_submit: !!source.allow_edit_after_submit,
      autosave_interval_seconds: Number(source.autosave_interval_seconds || 30),
      submission_start_at: String(source.submission_start_at || '').trim(),
      submission_end_at: String(source.submission_end_at || '').trim()
    };
    if (!Number.isFinite(out.autosave_interval_seconds)) {
      out.autosave_interval_seconds = 30;
    }
    out.autosave_interval_seconds = Math.max(5, Math.min(300, Math.round(out.autosave_interval_seconds)));
    const dtRe = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
    if (out.submission_start_at && !dtRe.test(out.submission_start_at)) {
      out.submission_start_at = '';
    }
    if (out.submission_end_at && !dtRe.test(out.submission_end_at)) {
      out.submission_end_at = '';
    }
    if (out.submission_start_at && out.submission_end_at && out.submission_end_at < out.submission_start_at) {
      out.submission_end_at = out.submission_start_at;
    }
    return out;
  }

  function readSettingsFromControls() {
    return normalizedBuilderSettings({
      one_response_per_user: !!(settingOneResponse && settingOneResponse.checked),
      allow_edit_after_submit: !!(settingAllowEditAfterSubmit && settingAllowEditAfterSubmit.checked),
      autosave_interval_seconds: settingAutosaveSeconds ? settingAutosaveSeconds.value : 30,
      submission_start_at: settingSubmissionStartAt ? settingSubmissionStartAt.value : '',
      submission_end_at: settingSubmissionEndAt ? settingSubmissionEndAt.value : ''
    });
  }

  function writeSettingsToControls(settings) {
    const normalized = normalizedBuilderSettings(settings);
    if (settingOneResponse) settingOneResponse.checked = !!normalized.one_response_per_user;
    if (settingAllowEditAfterSubmit) settingAllowEditAfterSubmit.checked = !!normalized.allow_edit_after_submit;
    if (settingAutosaveSeconds) settingAutosaveSeconds.value = String(normalized.autosave_interval_seconds);
    if (settingSubmissionStartAt) settingSubmissionStartAt.value = normalized.submission_start_at;
    if (settingSubmissionEndAt) settingSubmissionEndAt.value = normalized.submission_end_at;
    if (hiddenSettings) hiddenSettings.value = JSON.stringify(normalized);
  }

  function syncSettings() {
    if (!hiddenSettings) {
      return;
    }
    hiddenSettings.value = JSON.stringify(readSettingsFromControls());
  }

  function fieldRow(defaults) {
    const esc = function (value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    };
    const row = document.createElement('div');
    row.className = 'field-row';
    row.innerHTML =
      '<input placeholder="field_name" class="f-name" value="' + esc(defaults.name || '') + '">' +
      '<input placeholder="Label" class="f-label" value="' + esc(defaults.label || '') + '">' +
      '<textarea placeholder="Simple editor content (HTML allowed)" class="f-content" rows="2">' + esc(defaults.content_html || defaults.help_text || '') + '</textarea>' +
      '<select class="f-type">' +
        '<option value="text">text</option>' +
        '<option value="textarea">textarea</option>' +
        '<option value="number">number</option>' +
        '<option value="email">email</option>' +
        '<option value="date">date</option>' +
        '<option value="time">time</option>' +
        '<option value="phone">phone</option>' +
        '<option value="linear_scale">linear scale</option>' +
        '<option value="select">select</option>' +
        '<option value="radio">radio</option>' +
        '<option value="checkbox">checkbox</option>' +
        '<option value="welcome">welcome node</option>' +
        '<option value="agreement">agreement node</option>' +
        '<option value="section">section node</option>' +
        '<option value="form">form node</option>' +
        '<option value="thank_you">thank-you node</option>' +
      '</select>' +
      '<select class="f-text-rule">' +
        '<option value="none">No letter restriction</option>' +
        '<option value="arabic_only">Arabic only</option>' +
        '<option value="english_only">English only</option>' +
        '<option value="turkish_latin_only">Turkish Latin only</option>' +
        '<option value="english_or_arabic">English or Arabic</option>' +
      '</select>' +
      '<input placeholder="Options (comma separated)" class="f-options" value="' + esc((defaults.options || []).join(', ')) + '">' +
      '<input placeholder="Min (length/value/date/time)" class="f-min" value="' + esc(defaults.min_value ?? defaults.min_length ?? '') + '">' +
      '<input placeholder="Max (length/value/date/time)" class="f-max" value="' + esc(defaults.max_value ?? defaults.max_length ?? '') + '">' +
      '<input placeholder="Regex pattern (text only)" class="f-regex" value="' + esc(defaults.regex_pattern || '') + '">' +
      '<input placeholder="Show when field (optional)" class="f-visible-if-field" value="' + esc((defaults.visible_if && defaults.visible_if.field) || '') + '">' +
      '<select class="f-visible-if-operator">' +
        '<option value="equals">equals</option>' +
        '<option value="not_equals">not equals</option>' +
        '<option value="contains">contains</option>' +
        '<option value="gt">greater than</option>' +
        '<option value="gte">greater or equal</option>' +
        '<option value="lt">less than</option>' +
        '<option value="lte">less or equal</option>' +
      '</select>' +
      '<input placeholder="... condition value" class="f-visible-if-value" value="' + esc((defaults.visible_if && (defaults.visible_if.value || defaults.visible_if.equals)) || '') + '">' +
      '<select class="f-visible-group-mode">' +
        '<option value="AND">AND group</option>' +
        '<option value="OR">OR group</option>' +
      '</select>' +
      '<input placeholder="Second condition field" class="f-visible-if2-field" value="' + esc((defaults.visible_if_group && defaults.visible_if_group.conditions && defaults.visible_if_group.conditions[1] && defaults.visible_if_group.conditions[1].field) || '') + '">' +
      '<select class="f-visible-if2-operator">' +
        '<option value="equals">equals</option>' +
        '<option value="not_equals">not equals</option>' +
        '<option value="contains">contains</option>' +
        '<option value="gt">greater than</option>' +
        '<option value="gte">greater or equal</option>' +
        '<option value="lt">less than</option>' +
        '<option value="lte">less or equal</option>' +
      '</select>' +
      '<input placeholder="... second value" class="f-visible-if2-value" value="' + esc((defaults.visible_if_group && defaults.visible_if_group.conditions && defaults.visible_if_group.conditions[1] && defaults.visible_if_group.conditions[1].value) || '') + '">' +
      '<input placeholder="Image path (auto after upload)" class="f-image-path" value="' + esc(defaults.image_path || '') + '">' +
      '<input type="file" class="f-image-file" accept="image/*">' +
      '<button type="button" class="btn f-upload-image">Upload Image</button>' +
      '<input placeholder="Agreement PDF path" class="f-agreement-pdf" value="' + esc(defaults.agreement_pdf_path || '') + '">' +
      '<input type="file" class="f-pdf-file" accept="application/pdf">' +
      '<button type="button" class="btn f-upload-pdf">Upload PDF</button>' +
      '<label><input type="checkbox" class="f-required"> Required</label>' +
      '<button type="button" class="btn remove-field">Remove</button>';
    row.querySelector('.f-type').value = defaults.type || 'text';
    row.querySelector('.f-required').checked = !!defaults.required;
    row.querySelector('.f-visible-if-operator').value = (defaults.visible_if && (defaults.visible_if.operator || 'equals')) || 'equals';
    row.querySelector('.f-visible-group-mode').value = ((defaults.visible_if_group && defaults.visible_if_group.mode) || 'AND');
    row.querySelector('.f-visible-if2-operator').value = ((defaults.visible_if_group && defaults.visible_if_group.conditions && defaults.visible_if_group.conditions[1] && defaults.visible_if_group.conditions[1].operator) || 'equals');
    row.querySelector('.f-text-rule').value = defaults.text_rule || 'none';

    function syncFieldOptionVisibility() {
      const type = row.querySelector('.f-type').value;
      const optionsInput = row.querySelector('.f-options');
      const textRuleSelect = row.querySelector('.f-text-rule');
      const nameInput = row.querySelector('.f-name');
      const requiredInput = row.querySelector('.f-required');
      const visibleFieldInput = row.querySelector('.f-visible-if-field');
      const visibleOperatorInput = row.querySelector('.f-visible-if-operator');
      const visibleValueInput = row.querySelector('.f-visible-if-value');
      const visibleGroupModeInput = row.querySelector('.f-visible-group-mode');
      const visible2FieldInput = row.querySelector('.f-visible-if2-field');
      const visible2OperatorInput = row.querySelector('.f-visible-if2-operator');
      const visible2ValueInput = row.querySelector('.f-visible-if2-value');
      const minInput = row.querySelector('.f-min');
      const maxInput = row.querySelector('.f-max');
      const regexInput = row.querySelector('.f-regex');
      const contentInput = row.querySelector('.f-content');
      const imagePathInput = row.querySelector('.f-image-path');
      const imageFileInput = row.querySelector('.f-image-file');
      const imageUploadBtn = row.querySelector('.f-upload-image');
      const agreementPdfPathInput = row.querySelector('.f-agreement-pdf');
      const agreementPdfFileInput = row.querySelector('.f-pdf-file');
      const agreementPdfUploadBtn = row.querySelector('.f-upload-pdf');
      const isNodeType = ['welcome', 'agreement', 'section', 'form', 'thank_you'].includes(type);
      const allowImage = ['welcome', 'section', 'form', 'thank_you'].includes(type);
      const allowAgreementPdf = type === 'agreement';

      optionsInput.style.display = ['select', 'radio', 'checkbox'].includes(type) ? '' : 'none';
      textRuleSelect.style.display = ['text', 'textarea'].includes(type) ? '' : 'none';
      minInput.style.display = ['text', 'textarea', 'number', 'date', 'time', 'linear_scale'].includes(type) ? '' : 'none';
      maxInput.style.display = ['text', 'textarea', 'number', 'date', 'time', 'linear_scale'].includes(type) ? '' : 'none';
      regexInput.style.display = ['text', 'textarea'].includes(type) ? '' : 'none';
      contentInput.style.display = isNodeType ? '' : 'none';
      imagePathInput.style.display = allowImage ? '' : 'none';
      imageFileInput.style.display = allowImage ? '' : 'none';
      imageUploadBtn.style.display = allowImage ? '' : 'none';
      agreementPdfPathInput.style.display = allowAgreementPdf ? '' : 'none';
      agreementPdfFileInput.style.display = allowAgreementPdf ? '' : 'none';
      agreementPdfUploadBtn.style.display = allowAgreementPdf ? '' : 'none';
      if (!['text', 'textarea'].includes(type)) {
        textRuleSelect.value = 'none';
        regexInput.value = '';
      }

      if (isNodeType) {
        if (type !== 'agreement') {
          requiredInput.checked = false;
        }
        nameInput.placeholder = 'optional_node_name';
      } else {
        nameInput.placeholder = 'field_name';
      }

      requiredInput.disabled = (type !== 'agreement' && isNodeType);
      visibleFieldInput.disabled = isNodeType;
      visibleOperatorInput.disabled = isNodeType;
      visibleValueInput.disabled = isNodeType;
      visibleGroupModeInput.disabled = isNodeType;
      visible2FieldInput.disabled = isNodeType;
      visible2OperatorInput.disabled = isNodeType;
      visible2ValueInput.disabled = isNodeType;
      if (!allowImage) {
        imagePathInput.value = '';
      }
      if (!allowAgreementPdf) {
        agreementPdfPathInput.value = '';
      }
    }

    row.querySelector('.f-upload-image').addEventListener('click', function () {
      const fileInput = row.querySelector('.f-image-file');
      const pathInput = row.querySelector('.f-image-path');
      if (!fileInput.files || !fileInput.files[0]) {
        window.alert('Select an image first.');
        return;
      }
      uploadNodeFile('image', fileInput.files[0]).then(function (payload) {
        if (!payload || payload.ok !== true) {
          window.alert('Image upload failed: ' + String((payload && payload.error) || 'unknown_error'));
          return;
        }
        pathInput.value = String(payload.relative_path || '');
        syncSchema();
      }).catch(function () {
        window.alert('Image upload failed.');
      });
    });

    row.querySelector('.f-upload-pdf').addEventListener('click', function () {
      const fileInput = row.querySelector('.f-pdf-file');
      const pathInput = row.querySelector('.f-agreement-pdf');
      if (!fileInput.files || !fileInput.files[0]) {
        window.alert('Select a PDF first.');
        return;
      }
      uploadNodeFile('pdf', fileInput.files[0]).then(function (payload) {
        if (!payload || payload.ok !== true) {
          window.alert('PDF upload failed: ' + String((payload && payload.error) || 'unknown_error'));
          return;
        }
        pathInput.value = String(payload.relative_path || '');
        syncSchema();
      }).catch(function () {
        window.alert('PDF upload failed.');
      });
    });

    row.querySelector('.remove-field').addEventListener('click', function () {
      row.remove();
      syncSchema();
    });
    row.querySelectorAll('input,select,textarea').forEach(function (el) {
      el.addEventListener('change', syncSchema);
      el.addEventListener('keyup', syncSchema);
    });
    row.querySelector('.f-type').addEventListener('change', syncFieldOptionVisibility);
    syncFieldOptionVisibility();
    return row;
  }

  function renderSchemaRows(schema) {
    container.innerHTML = '';
    if (!Array.isArray(schema) || schema.length === 0) {
      container.appendChild(fieldRow({ name: 'full_name', label: 'Full Name', type: 'text', required: true }));
      syncSchema();
      return;
    }

    schema.forEach(function (field) {
      const visibleIfGroup = (field.visible_if_group && typeof field.visible_if_group === 'object') ? field.visible_if_group : null;
      const groupConditions = Array.isArray(visibleIfGroup && visibleIfGroup.conditions) ? visibleIfGroup.conditions : [];
      const visibleIfPrimary = (field.visible_if && typeof field.visible_if === 'object')
        ? field.visible_if
        : (groupConditions[0] && typeof groupConditions[0] === 'object' ? groupConditions[0] : null);
      container.appendChild(fieldRow({
        name: field.name || '',
        label: field.label || '',
        help_text: field.help_text || '',
        content_html: field.content_html || '',
        type: field.type || 'text',
        required: !!field.required,
        image_path: field.image_path || '',
        agreement_pdf_path: field.agreement_pdf_path || '',
        options: Array.isArray(field.options) ? field.options : [],
        text_rule: field.text_rule || '',
        min_length: field.min_length,
        max_length: field.max_length,
        min_value: field.min_value,
        max_value: field.max_value,
        regex_pattern: field.regex_pattern || '',
        visible_if_group: visibleIfGroup,
        visible_if: visibleIfPrimary
      }));
    });
    syncSchema();
  }

  function resetEditor() {
    if (scholarshipIdInput) scholarshipIdInput.value = '0';
    if (titleInput) titleInput.value = '';
    if (descriptionInput) descriptionInput.value = '';
    if (statusInput) statusInput.value = 'draft';
    if (modeLabel) modeLabel.innerHTML = '<strong>Mode:</strong> Create new scholarship';
    writeSettingsToControls(defaultFormSettings);
    renderSchemaRows([]);
  }

  function syncSchema() {
    const schema = [];
    container.querySelectorAll('.field-row').forEach(function (row, idx) {
      const name = row.querySelector('.f-name').value.trim();
      const label = row.querySelector('.f-label').value.trim();
      const type = row.querySelector('.f-type').value;
      const contentHtml = row.querySelector('.f-content').value.trim();
      const helpText = contentHtml;
      const optionsRaw = row.querySelector('.f-options').value;
      const required = row.querySelector('.f-required').checked;
      const textRule = row.querySelector('.f-text-rule').value;
      const minRaw = row.querySelector('.f-min').value.trim();
      const maxRaw = row.querySelector('.f-max').value.trim();
      const regexPattern = row.querySelector('.f-regex').value.trim();
      const imagePath = row.querySelector('.f-image-path').value.trim();
      const agreementPdfPath = row.querySelector('.f-agreement-pdf').value.trim();
      const isNodeType = ['welcome', 'agreement', 'section', 'form', 'thank_you'].includes(type);
      const generatedName = name || ('node_' + String(idx + 1));

      if ((name && label) || (isNodeType && label)) {
        const field = { name: isNodeType ? generatedName : name, label: label, type: type, required: required };
        if (helpText) {
          field.help_text = helpText;
          field.content_html = helpText;
        }
        if (isNodeType && imagePath) {
          field.image_path = imagePath;
        }
        if (type === 'agreement') {
          field.agreement_mode = agreementPdfPath ? 'pdf' : 'text';
          if (agreementPdfPath) {
            field.agreement_pdf_path = agreementPdfPath;
          }
        }
        if (['text', 'textarea'].includes(type) && textRule) {
          field.text_rule = textRule;
        }
        if (['text', 'textarea'].includes(type)) {
          if (minRaw !== '' && !isNaN(Number(minRaw))) {
            field.min_length = Math.max(0, parseInt(minRaw, 10));
          }
          if (maxRaw !== '' && !isNaN(Number(maxRaw))) {
            field.max_length = Math.max(1, parseInt(maxRaw, 10));
          }
          if (regexPattern !== '') {
            field.regex_pattern = regexPattern;
          }
        }
        if (['number', 'linear_scale', 'date', 'time'].includes(type)) {
          if (minRaw !== '') {
            field.min_value = minRaw;
          }
          if (maxRaw !== '') {
            field.max_value = maxRaw;
          }
        }
        if (type === 'phone') {
          field.default_country_code = '+90';
          field.allow_country_change = true;
          field.validation_mode = 'country_strict';
        }
        if (['select', 'radio', 'checkbox'].includes(type)) {
          field.options = optionsRaw.split(',').map(function (opt) {
            return opt.trim();
          }).filter(function (opt) {
            return opt !== '';
          });
        }
        const visibleIfField = row.querySelector('.f-visible-if-field').value.trim();
        const visibleIfOperator = row.querySelector('.f-visible-if-operator').value;
        const visibleIfValue = row.querySelector('.f-visible-if-value').value.trim();
        const visibleGroupMode = row.querySelector('.f-visible-group-mode').value;
        const visibleIf2Field = row.querySelector('.f-visible-if2-field').value.trim();
        const visibleIf2Operator = row.querySelector('.f-visible-if2-operator').value;
        const visibleIf2Value = row.querySelector('.f-visible-if2-value').value.trim();
        if (!isNodeType) {
          const visibilityConditions = [];
          if (visibleIfField && visibleIfValue) {
            visibilityConditions.push({ field: visibleIfField, operator: visibleIfOperator, value: visibleIfValue });
          }
          if (visibleIf2Field && visibleIf2Value) {
            visibilityConditions.push({ field: visibleIf2Field, operator: visibleIf2Operator, value: visibleIf2Value });
          }

          if (visibilityConditions.length >= 2) {
            field.visible_if_group = {
              mode: visibleGroupMode === 'OR' ? 'OR' : 'AND',
              conditions: visibilityConditions
            };
          } else if (visibilityConditions.length === 1) {
            field.visible_if = visibilityConditions[0];
          }
        }

        if (type === 'agreement' && field.required !== false) {
          field.required = true;
        }
        schema.push(field);
      }
    });
    hiddenSchema.value = JSON.stringify(schema);
    syncSettings();
    renderBuilderPreview(schema);
  }

  function renderBuilderPreview(schema) {
    if (!previewEl) {
      return;
    }

    if (!Array.isArray(schema) || schema.length === 0) {
      previewEl.innerHTML = '<p>Add nodes or fields to preview your page-by-page flow.</p>';
      return;
    }

    const nodeTypes = ['welcome', 'agreement', 'section', 'form', 'thank_you'];
    const steps = [];
    let currentStep = [];

    schema.forEach(function (field) {
      const type = String(field.type || 'text');
      if (nodeTypes.includes(type)) {
        if (currentStep.length) {
          steps.push(currentStep);
        }
        currentStep = [field];
      } else {
        currentStep.push(field);
      }
    });
    if (currentStep.length) {
      steps.push(currentStep);
    }

    if (steps.length === 0) {
      steps.push(schema);
    }

    let html = '<p><strong>Pages:</strong> ' + steps.length + '</p>';
    steps.forEach(function (step, idx) {
      html += '<div class="card" style="margin-bottom:8px;">';
      html += '<h4>Page ' + (idx + 1) + '</h4>';
      step.forEach(function (field) {
        const type = String(field.type || 'text');
        const label = String(field.label || 'Untitled');
        const help = String(field.help_text || '');
        if (nodeTypes.includes(type)) {
          html += '<p><strong>' + label + '</strong> <span class="badge">' + type + '</span></p>';
          if (help) {
            html += '<p>' + help + '</p>';
          }
          if (field.image_path) {
            html += '<p>Image: ' + String(field.image_path) + '</p>';
          }
          if (type === 'agreement' && field.agreement_pdf_path) {
            html += '<p>PDF: ' + String(field.agreement_pdf_path) + '</p>';
          }
        } else {
          html += '<p>' + label + ' <span class="badge">' + type + '</span></p>';
          if (field.min_length || field.max_length) {
            html += '<p>Length: ' + String(field.min_length || 0) + ' - ' + String(field.max_length || 'any') + '</p>';
          }
          if (field.min_value || field.max_value) {
            html += '<p>Range: ' + String(field.min_value || 'any') + ' - ' + String(field.max_value || 'any') + '</p>';
          }
          if (field.regex_pattern) {
            html += '<p>Pattern: ' + String(field.regex_pattern) + '</p>';
          }
        }
      });
      html += '</div>';
    });
    previewEl.innerHTML = html;
  }

  addBtn.addEventListener('click', function () {
    container.appendChild(fieldRow({}));
    syncSchema();
  });

  if (resetBtn) {
    resetBtn.addEventListener('click', resetEditor);
  }

  document.querySelectorAll('.load-scholarship-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const scholarshipId = btn.getAttribute('data-id') || '0';
      const title = btn.getAttribute('data-title') || '';
      const description = btn.getAttribute('data-description') || '';
      const status = btn.getAttribute('data-status') || 'draft';
      const rawSchema = btn.getAttribute('data-schema') || '[]';

      let schema = [];
      let settings = defaultFormSettings;
      try {
        const parsed = JSON.parse(rawSchema);
        if (Array.isArray(parsed)) {
          schema = parsed;
        } else if (parsed && typeof parsed === 'object') {
          schema = Array.isArray(parsed.nodes) ? parsed.nodes : [];
          settings = normalizedBuilderSettings(parsed.settings || {});
        } else {
          schema = [];
        }
      } catch (e) {
        schema = [];
      }

      if (scholarshipIdInput) scholarshipIdInput.value = scholarshipId;
      if (titleInput) titleInput.value = title;
      if (descriptionInput) descriptionInput.value = description;
      if (statusInput) statusInput.value = status;
      if (modeLabel) modeLabel.innerHTML = '<strong>Mode:</strong> Editing scholarship #' + scholarshipId + ' (new version will be saved)';
      writeSettingsToControls(settings);

      renderSchemaRows(schema);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  [settingOneResponse, settingAllowEditAfterSubmit, settingAutosaveSeconds, settingSubmissionStartAt, settingSubmissionEndAt].forEach(function (el) {
    if (!el) {
      return;
    }
    el.addEventListener('change', syncSettings);
    el.addEventListener('keyup', syncSettings);
  });

  resetEditor();
})();

(function () {
  const expiryEl = document.getElementById('verify-expiry-timer');
  if (expiryEl) {
    const expiresAt = Number(expiryEl.getAttribute('data-expires-at') || '0');
    const updateExpiry = function () {
      const now = Math.floor(Date.now() / 1000);
      const remaining = Math.max(0, expiresAt - now);
      const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
      const ss = String(remaining % 60).padStart(2, '0');
      expiryEl.textContent = remaining > 0
        ? ('Code expires in ' + mm + ':' + ss)
        : 'Code expired. Request a new code.';
    };
    updateExpiry();
    setInterval(updateExpiry, 1000);
  }

  const resendBtn = document.getElementById('resend-verification-btn');
  const resendText = document.getElementById('resend-cooldown-text');
  if (!resendBtn || !resendText) {
    return;
  }

  const createdAt = Number(resendBtn.getAttribute('data-created-at') || '0');
  const cooldown = Number(resendBtn.getAttribute('data-cooldown-seconds') || '0');
  if (createdAt <= 0 || cooldown <= 0) {
    return;
  }

  const updateCooldown = function () {
    const now = Math.floor(Date.now() / 1000);
    const unlockAt = createdAt + cooldown;
    const remaining = unlockAt - now;

    if (remaining > 0) {
      resendBtn.disabled = true;
      resendText.textContent = ' Resend available in ' + remaining + 's';
    } else {
      resendBtn.disabled = false;
      resendText.textContent = '';
    }
  };

  updateCooldown();
  setInterval(updateCooldown, 1000);
})();
</script>
</html>
