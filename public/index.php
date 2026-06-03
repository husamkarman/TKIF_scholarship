<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/lib/db.php';
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
  require dirname(__DIR__) . '/vendor/autoload.php';
}
require dirname(__DIR__) . '/app/lib/notify.php';
require dirname(__DIR__) . '/app/lib/profile.php';
require dirname(__DIR__) . '/app/controllers/profile_controller.php';

use Shuchkin\SimpleXLSX;

session_name($config['session_name']);
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
    'tenant_id' => (int)$user['tenant_id'],
    'name' => $user['full_name'],
    'email' => $user['email'],
    'role' => $user['role'],
  ];
}

function find_active_user_by_email(PDO $pdo, string $email): ?array
{
  $normalized = normalize_email($email);
  $stmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash FROM users WHERE LOWER(TRIM(email)) = ? AND is_active = 1 LIMIT 1');
  $stmt->execute([$normalized]);
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

  return [
    'schema' => normalize_form_schema(json_decode($schemaJson, true)),
    'version' => $version,
  ];
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
    'SELECT u.id, u.tenant_id, u.full_name, u.email, u.role, u.password_hash
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
  $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

  $stmt = $pdo->prepare('INSERT INTO otp_codes (tenant_id, user_id, email, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?)');
  $stmt->execute([
    (int)$user['tenant_id'],
    (int)$user['id'],
    (string)$user['email'],
    $hash,
    $expiresAt,
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
  $state = bin2hex(random_bytes(16));
  $_SESSION['ms_oauth_state'] = $state;

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

    $insert = $pdo->prepare('INSERT INTO users (tenant_id, full_name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    $insert->execute([
      (int)$tenant['id'],
      $fullName,
      $normalized,
      $passwordHash,
      $role,
    ]);

    $createdId = (int)$pdo->lastInsertId();
    $select = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash FROM users WHERE id = ? LIMIT 1');
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
  $state = bin2hex(random_bytes(16));
  $_SESSION['google_oauth_state'] = $state;

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

  $insert = $pdo->prepare('INSERT INTO users (tenant_id, full_name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)');
  $insert->execute([
    (int)$tenant['id'],
    $fullName,
    $normalized,
    $passwordHash,
    $role,
  ]);

  $createdId = (int)$pdo->lastInsertId();
  $select = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash FROM users WHERE id = ? LIMIT 1');
  $select->execute([$createdId]);
  $newUser = $select->fetch();
  return $newUser ?: null;
}

function normalize_form_schema(mixed $schema): array
{
  if (!is_array($schema)) {
    return [];
  }

  $allowedTypes = ['text', 'textarea', 'number', 'email', 'date', 'select', 'radio', 'checkbox'];
  $normalized = [];

  foreach ($schema as $field) {
    if (!is_array($field)) {
      continue;
    }

    $name = trim((string)($field['name'] ?? ''));
    $label = trim((string)($field['label'] ?? ''));
    $type = trim((string)($field['type'] ?? 'text'));
    $required = (bool)($field['required'] ?? false);

    if ($name === '' || $label === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $name)) {
      continue;
    }

    if (!in_array($type, $allowedTypes, true)) {
      $type = 'text';
    }

    $normalizedField = [
      'name' => $name,
      'label' => $label,
      'type' => $type,
      'required' => $required,
    ];

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

    $visibleIf = $field['visible_if'] ?? null;
    if (is_array($visibleIf)) {
      $dependsOn = trim((string)($visibleIf['field'] ?? ''));
      $operator = trim((string)($visibleIf['operator'] ?? ''));
      $value = trim((string)($visibleIf['value'] ?? ''));
      if ($operator === '' && isset($visibleIf['equals'])) {
        $operator = 'equals';
        $value = trim((string)$visibleIf['equals']);
      }
      $allowedOperators = ['equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte'];
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

    $normalized[] = $normalizedField;
  }

  return $normalized;
}

function field_is_visible(array $field, array $answers): bool
{
  $visibleIf = $field['visible_if'] ?? null;
  if (!is_array($visibleIf)) {
    return true;
  }

  $dependsOn = trim((string)($visibleIf['field'] ?? ''));
  $operator = trim((string)($visibleIf['operator'] ?? ''));
  $value = trim((string)($visibleIf['value'] ?? ''));
  if ($operator === '' && isset($visibleIf['equals'])) {
    $operator = 'equals';
    $value = trim((string)$visibleIf['equals']);
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
      if (!is_numeric($actualStr) || !is_numeric($value)) {
        return false;
      }
      $actualNum = (float)$actualStr;
      $targetNum = (float)$value;
      if ($operator === 'gt') {
        return $actualNum > $targetNum;
      }
      if ($operator === 'gte') {
        return $actualNum >= $targetNum;
      }
      if ($operator === 'lt') {
        return $actualNum < $targetNum;
      }
      return $actualNum <= $targetNum;
    default:
      return true;
  }
}

function is_latin_or_arabic_text(string $value): bool
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return true;
  }

  return (bool)preg_match("/^[\\p{Latin}\\p{Arabic}\\p{M}\\s'\\-]+$/u", $trimmed);
}

function render_dynamic_field(array $field, array $old = []): void
{
  $name = (string)$field['name'];
  $label = (string)$field['label'];
  $type = (string)$field['type'];
  $required = (bool)$field['required'];
  $value = $old[$name] ?? '';
  $requiredAttr = $required ? ' required' : '';
  $visibleIf = is_array($field['visible_if'] ?? null) ? $field['visible_if'] : null;
  $visibleIfField = $visibleIf ? (string)($visibleIf['field'] ?? '') : '';
  $visibleIfOperator = $visibleIf ? (string)($visibleIf['operator'] ?? '') : '';
  $visibleIfValue = $visibleIf ? (string)($visibleIf['value'] ?? '') : '';
  if ($visibleIfOperator === '' && $visibleIf && isset($visibleIf['equals'])) {
    $visibleIfOperator = 'equals';
    $visibleIfValue = (string)$visibleIf['equals'];
  }
  $textRule = in_array($type, ['text', 'textarea'], true) ? 'latin_arabic' : '';

  echo '<div class="dynamic-field" data-field-name="' . h($name) . '" data-field-type="' . h($type) . '" data-text-rule="' . h($textRule) . '" data-visible-if-field="' . h($visibleIfField) . '" data-visible-if-operator="' . h($visibleIfOperator) . '" data-visible-if-value="' . h($visibleIfValue) . '">';

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

  $inputType = in_array($type, ['text', 'number', 'email', 'date'], true) ? $type : 'text';
  echo '<input name="answers[' . h($name) . ']" type="' . h($inputType) . '" value="' . h((string)$value) . '"' . $requiredAttr . '>';
  echo '</div>';
}

function normalize_email(string $email): string
{
  return strtolower(trim($email));
}

function blacklist_match(PDO $pdo, int $tenantId, int $registerId, string $email): ?array
{
  $emailNorm = normalize_email($email);
  $stmt = $pdo->prepare(
    'SELECT id, register_id, email_normalized, reason FROM blacklist_entries
     WHERE tenant_id = ?
       AND ((register_id IS NOT NULL AND register_id = ?) OR (email_normalized IS NOT NULL AND email_normalized = ?))
     ORDER BY id DESC LIMIT 1'
  );
  $stmt->execute([$tenantId, $registerId, $emailNorm]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function blacklist_reason_text(?string $reason): string
{
  $trimmed = trim((string)$reason);
  return $trimmed !== '' ? $trimmed : 'No reason provided';
}

function collect_blacklisted_user_ids(PDO $pdo, int $tenantId, ?int $registerId, ?string $emailNorm): array
{
  if (!$registerId && !$emailNorm) {
    return [];
  }

  $sql = 'SELECT id FROM users WHERE tenant_id = ? AND (';
  $params = [$tenantId];
  $conds = [];

  if ($registerId) {
    $conds[] = 'id = ?';
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
  $params = array_merge([$tenantId], $userIds);

  $select = $pdo->prepare(
    'SELECT id FROM applications
     WHERE tenant_id = ?
       AND student_id IN (' . $placeholders . ')
       AND status IN ("submitted", "in_review")'
  );
  $select->execute($params);
  $ids = array_map(static fn(array $row): int => (int)$row['id'], $select->fetchAll());
  if ($ids === []) {
    return 0;
  }

  $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
  $updateParams = array_merge([$reason, $tenantId], $ids);
  $update = $pdo->prepare(
    'UPDATE applications SET status = "rejected", rejection_reason = ?
     WHERE tenant_id = ? AND id IN (' . $idPlaceholders . ')'
  );
  $update->execute($updateParams);

  $audit = $pdo->prepare('INSERT INTO audit_logs (tenant_id, actor_user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?)');
  foreach ($ids as $applicationId) {
    $audit->execute([
      $tenantId,
      $actorUserId,
      'application_rejected_blacklist',
      'application',
      $applicationId,
      json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
    ]);
  }

  return count($ids);
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
$registerOld = [
  'full_name' => '',
  'email' => '',
];

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

if ($page === 'logout') {
    session_destroy();
  header('Location: ' . app_route('login'));
    exit;
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $user = find_active_user_by_email($pdo, $email);

    if ($user && password_verify($password, $user['password_hash'])) {
      ensure_user_profile_exists($pdo, (int)$user['id'], (string)$user['full_name']);
      set_user_session($user);
        header('Location: ' . app_route('dashboard'));
        exit;
    }

    $error = 'Invalid credentials';
}

if ($page === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();

    if (($config['registration']['enabled'] ?? true) !== true) {
      $error = 'Registration is currently disabled.';
      $page = 'login';
    } else {
      $fullName = trim((string)($_POST['full_name'] ?? ''));
      $email = normalize_email((string)($_POST['email'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      $confirmPassword = (string)($_POST['confirm_password'] ?? '');

      $registerOld = [
        'full_name' => $fullName,
        'email' => $email,
      ];

      if ($fullName === '') {
        $error = 'Full name is required.';
      } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Valid email is required.';
      } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
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
            $insert = $pdo->prepare('INSERT INTO users (tenant_id, full_name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)');
            $insert->execute([
              $tenantId,
              $fullName,
              $email,
              $passwordHash,
              $role,
            ]);

            $newUserId = (int)$pdo->lastInsertId();
            $userStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$newUserId]);
            $newUser = $userStmt->fetch();
            if (!$newUser) {
              $error = 'Registration succeeded but login bootstrap failed.';
            } else {
              ensure_user_profile_exists($pdo, (int)$newUser['id'], (string)$newUser['full_name']);
              set_user_session($newUser);
              header('Location: ' . app_route('dashboard'));
              exit;
            }
          }
        }
      }

      $page = 'register';
    }
}

  if ($page === 'login_otp_request' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $email = trim((string)($_POST['email'] ?? ''));

    if (!otp_ready($config)) {
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

    if ($otpUserId <= 0) {
      $error = 'Request OTP first.';
    } elseif ($code === '') {
      $error = 'Enter the OTP code.';
    } elseif (!verify_email_otp($pdo, $otpUserId, $code)) {
      $error = 'Invalid or expired OTP code.';
    } else {
      $stmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
      $stmt->execute([$otpUserId]);
      $user = $stmt->fetch();
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
    $savedState = (string)($_SESSION['ms_oauth_state'] ?? '');

    if ($state === '' || $savedState === '' || !hash_equals($savedState, $state)) {
      $error = 'OAuth state validation failed.';
      $page = 'login';
    } elseif ($code === '') {
      $error = 'Microsoft OAuth code is missing.';
      $page = 'login';
    } else {
      unset($_SESSION['ms_oauth_state']);
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
    $savedState = (string)($_SESSION['google_oauth_state'] ?? '');

    if ($state === '' || $savedState === '' || !hash_equals($savedState, $state)) {
      $error = 'OAuth state validation failed.';
      $page = 'login';
    } elseif ($code === '') {
      $error = 'Google OAuth code is missing.';
      $page = 'login';
    } else {
      unset($_SESSION['google_oauth_state']);
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

  $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, role FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
  $targetStmt->execute([$targetUserId, (int)$actor['tenant_id']]);
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
  $applications = fetch_profile_student_applications($pdo, (int)$actor['tenant_id'], (int)$targetUserId, $filters);

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
      (string)(int)$row['id'],
      (string)$row['scholarship_title'],
      (string)$row['status'],
      (string)$row['created_at'],
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
      (string)(int)$row['id'],
      (string)$row['full_name'],
      (string)$row['email'],
      (string)$row['role'],
      (string)$row['tenant_code'],
      (string)$row['inferred_provider'],
      (string)$row['auth_provider_id'],
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

if ($page === 'profile' && $pdo) {
  $actor = require_login();
  $targetUserId = (int)($_GET['user_id'] ?? $actor['id']);
  if ($targetUserId !== (int)$actor['id']) {
    $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, is_active FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
    $targetStmt->execute([$targetUserId, (int)$actor['tenant_id']]);
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

    $blacklist = blacklist_match($pdo, (int)$user['tenant_id'], (int)$user['id'], (string)$user['email']);
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
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
        'scholarship_id' => $scholarshipId,
        'scholarship_title' => (string)($scholarship['title'] ?? ''),
        'student_email' => $user['email'],
        'reason' => $reason,
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
    $formVersion = (int)$resolved['version'];
    if ($schema === []) {
      http_response_code(422);
      exit('Invalid scholarship form schema');
    }

    $answersInput = $_POST['answers'] ?? [];
    $answers = [];
    foreach ($schema as $field) {
      $fieldName = (string)$field['name'];
      $fieldType = (string)($field['type'] ?? 'text');

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

      $value = trim((string)($answersInput[$fieldName] ?? ''));
      if (in_array($fieldType, ['select', 'radio'], true)) {
        $allowedOptions = array_map('strval', (array)($field['options'] ?? []));
        if ($value !== '' && !in_array($value, $allowedOptions, true)) {
          $value = '';
        }
      }
      if (in_array($fieldType, ['text', 'textarea'], true) && !is_latin_or_arabic_text($value)) {
        $error = 'Text fields accept only English/Latin or Arabic letters.';
        $page = 'dashboard';
        break;
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
      $stmt = $pdo->prepare('INSERT INTO applications (tenant_id, scholarship_id, student_id, answers_json, status) VALUES (?, ?, ?, ?, "submitted")');
      $stmt->execute([
        $user['tenant_id'],
        $scholarshipId,
        $user['id'],
        json_encode($answers, JSON_UNESCAPED_UNICODE),
      ]);

      $applicationId = (int)$pdo->lastInsertId();
      enqueue_internal_notification($pdo, [
        'event' => 'application_submitted',
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
        'scholarship_id' => $scholarshipId,
        'scholarship_title' => (string)($scholarship['title'] ?? ''),
        'student_email' => $user['email'],
        'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE),
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

      $message = 'Application submitted successfully.';
      $page = 'dashboard';
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

  $registerId = (int)($_POST['register_id'] ?? 0);
  $email = trim((string)($_POST['email'] ?? ''));
  $emailNorm = $email !== '' ? normalize_email($email) : null;
  $reason = trim((string)($_POST['reason'] ?? ''));

  if ($registerId <= 0 && $emailNorm === null) {
    $error = 'Provide register_id or email.';
  } else {
    $insert = $pdo->prepare('INSERT INTO blacklist_entries (tenant_id, register_id, email_original, email_normalized, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([
      $user['tenant_id'],
      $registerId > 0 ? $registerId : null,
      $email !== '' ? $email : null,
      $emailNorm,
      $reason !== '' ? $reason : null,
      $user['id'],
    ]);

    $matchedUserIds = collect_blacklisted_user_ids($pdo, (int)$user['tenant_id'], $registerId > 0 ? $registerId : null, $emailNorm);
    $rejectedCount = reject_inflight_applications($pdo, (int)$user['tenant_id'], $matchedUserIds, (int)$user['id'], 'Blacklisted: ' . blacklist_reason_text($reason));
    $message = 'Blacklist entry added. Auto-rejected in-flight applications: ' . (string)$rejectedCount;
    $page = 'dashboard';
  }
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

          $matchedUserIds = collect_blacklisted_user_ids($pdo, (int)$user['tenant_id'], $registerId > 0 ? $registerId : null, $emailNorm);
          $rejectedTotal += reject_inflight_applications($pdo, (int)$user['tenant_id'], $matchedUserIds, (int)$user['id'], 'Blacklisted: ' . blacklist_reason_text($reason));
        }
        $message = 'Blacklist import complete. Inserted: ' . (string)$inserted . ', Auto-rejected in-flight: ' . (string)$rejectedTotal;
        $page = 'dashboard';
      }
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
    $schema = normalize_form_schema($rawSchema);

    if ($title === '') {
      $error = 'Scholarship title is required.';
    } elseif ($schema === []) {
      $error = 'At least one valid form field is required.';
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
      $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE);
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
        $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE);
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

    $metaStmt = $pdo->prepare('SELECT a.scholarship_id, s.title AS scholarship_title, st.email AS student_email
      FROM applications a
      JOIN scholarships s ON s.id = a.scholarship_id
      JOIN users st ON st.id = a.student_id
      WHERE a.id = ? AND a.tenant_id = ? LIMIT 1');
    $metaStmt->execute([$applicationId, $user['tenant_id']]);
    $decisionMeta = $metaStmt->fetch() ?: [];

    enqueue_internal_notification($pdo, [
        'event' => 'application_' . $status,
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
      'scholarship_id' => (int)($decisionMeta['scholarship_id'] ?? 0),
      'scholarship_title' => (string)($decisionMeta['scholarship_title'] ?? ''),
      'student_email' => (string)($decisionMeta['student_email'] ?? ''),
      'status' => $status,
      'reason' => $reason,
        'by' => $user['email'],
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

$user = current_user();
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($config['app_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">
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
            <a href="<?= h(app_route('identity_diagnostics')) ?>">Identity Diagnostics</a>
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
        <br><br>
        <button class="btn primary" type="submit">Sign in</button>
      </form>

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

    <?php elseif ($page === 'register'): ?>
      <h2>Create Account</h2>
      <?php if (($config['registration']['enabled'] ?? true) !== true): ?>
        <p>Registration is currently disabled.</p>
        <a class="btn" href="<?= h(app_route('login')) ?>">Back to Login</a>
      <?php else: ?>
        <form method="post" action="<?= h(app_route('register')) ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <label>Full Name</label>
          <input name="full_name" type="text" value="<?= h((string)$registerOld['full_name']) ?>" required>
          <label>Email</label>
          <input name="email" type="email" value="<?= h((string)$registerOld['email']) ?>" required>
          <label>Password</label>
          <input name="password" type="password" minlength="8" required>
          <label>Confirm Password</label>
          <input name="confirm_password" type="password" minlength="8" required>
          <br><br>
          <button class="btn primary" type="submit">Create Account</button>
          <a class="btn" href="<?= h(app_route('login')) ?>">Back to Login</a>
        </form>
      <?php endif; ?>

    <?php elseif ($page === 'profile' && $user && $pdo): ?>
      <?php require __DIR__ . '/views/profile.php'; ?>

    <?php elseif ($page === 'dashboard' && $user): ?>
      <?php require __DIR__ . '/views/dashboard.php'; ?>

    <?php elseif ($page === 'identity_diagnostics' && $user && $pdo): ?>
      <?php require __DIR__ . '/views/identity_diagnostics.php'; ?>

    <?php else: ?>
      <h2>Not found</h2>
      <a href="/">Back</a>
    <?php endif; ?>
  </div>
</body>
<script>
(function () {
  function initApplicationForms() {
    const applyForms = document.querySelectorAll('form.scholarship-apply-form');
    if (!applyForms.length) {
      return;
    }

    function getFieldValue(form, fieldName) {
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
        const a = Number(actualStr);
        const b = Number(value);
        if (Number.isNaN(a) || Number.isNaN(b)) {
          return false;
        }
        if (operator === 'gt') return a > b;
        if (operator === 'gte') return a >= b;
        if (operator === 'lt') return a < b;
        return a <= b;
      }
      return true;
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

    applyForms.forEach(function (form) {
      const scholarshipId = form.getAttribute('data-scholarship-id') || '0';
      const draftKey = 'scholarship_form_draft_' + scholarshipId;

      function updateVisibility() {
        form.querySelectorAll('.dynamic-field').forEach(function (wrapper) {
          const dependsOn = (wrapper.getAttribute('data-visible-if-field') || '').trim();
          const operator = (wrapper.getAttribute('data-visible-if-operator') || '').trim();
          const value = (wrapper.getAttribute('data-visible-if-value') || '').trim();
          if (!dependsOn || !operator || !value) {
            wrapper.style.display = '';
            wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
              el.disabled = false;
            });
            return;
          }

          const actual = getFieldValue(form, dependsOn);
          const visible = isVisibleByCondition(actual, operator, value);

          wrapper.style.display = visible ? '' : 'none';
          wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = !visible;
          });
        });
      }

      function validateTextRules() {
        let isValid = true;
        form.querySelectorAll('.dynamic-field').forEach(function (wrapper) {
          if (wrapper.style.display === 'none') {
            return;
          }
          const textRule = (wrapper.getAttribute('data-text-rule') || '').trim();
          if (textRule !== 'latin_arabic') {
            return;
          }
          const fieldName = wrapper.getAttribute('data-field-name') || '';
          if (!fieldName) {
            return;
          }
          const value = getFieldValue(form, fieldName);
          if (!isLatinArabicText(Array.isArray(value) ? value.join(' ') : value)) {
            isValid = false;
          }
        });
        return isValid;
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

      form.addEventListener('change', function () {
        updateVisibility();
        saveDraft();
      });
      form.addEventListener('keyup', function () {
        saveDraft();
      });
      form.addEventListener('submit', function (event) {
        if (!validateTextRules()) {
          window.alert('Text fields accept only English/Latin or Arabic letters.');
          event.preventDefault();
          return;
        }
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
  const addBtn = document.getElementById('add-field-btn');
  const scholarshipIdInput = document.getElementById('scholarship_id');
  const titleInput = document.getElementById('scholarship_title_input');
  const descriptionInput = document.getElementById('scholarship_description_input');
  const statusInput = document.getElementById('scholarship_status_input');
  const modeLabel = document.getElementById('scholarship-editor-mode');
  const resetBtn = document.getElementById('reset-scholarship-editor');

  function fieldRow(defaults) {
    const row = document.createElement('div');
    row.className = 'field-row';
    row.innerHTML =
      '<input placeholder="field_name" class="f-name" value="' + (defaults.name || '') + '">' +
      '<input placeholder="Label" class="f-label" value="' + (defaults.label || '') + '">' +
      '<select class="f-type">' +
        '<option value="text">text</option>' +
        '<option value="textarea">textarea</option>' +
        '<option value="number">number</option>' +
        '<option value="email">email</option>' +
        '<option value="date">date</option>' +
        '<option value="select">select</option>' +
        '<option value="radio">radio</option>' +
        '<option value="checkbox">checkbox</option>' +
      '</select>' +
      '<input placeholder="Options (comma separated)" class="f-options" value="' + ((defaults.options || []).join(', ')) + '">' +
      '<input placeholder="Show when field (optional)" class="f-visible-if-field" value="' + ((defaults.visible_if && defaults.visible_if.field) || '') + '">' +
      '<select class="f-visible-if-operator">' +
        '<option value="equals">equals</option>' +
        '<option value="not_equals">not equals</option>' +
        '<option value="contains">contains</option>' +
        '<option value="gt">greater than</option>' +
        '<option value="gte">greater or equal</option>' +
        '<option value="lt">less than</option>' +
        '<option value="lte">less or equal</option>' +
      '</select>' +
      '<input placeholder="... condition value" class="f-visible-if-value" value="' + ((defaults.visible_if && (defaults.visible_if.value || defaults.visible_if.equals)) || '') + '">' +
      '<label><input type="checkbox" class="f-required"> Required</label>' +
      '<button type="button" class="btn remove-field">Remove</button>';
    row.querySelector('.f-type').value = defaults.type || 'text';
    row.querySelector('.f-required').checked = !!defaults.required;
    row.querySelector('.f-visible-if-operator').value = (defaults.visible_if && (defaults.visible_if.operator || 'equals')) || 'equals';

    function syncOptionsVisibility() {
      const type = row.querySelector('.f-type').value;
      const optionsInput = row.querySelector('.f-options');
      optionsInput.style.display = ['select', 'radio', 'checkbox'].includes(type) ? '' : 'none';
    }

    row.querySelector('.remove-field').addEventListener('click', function () {
      row.remove();
      syncSchema();
    });
    row.querySelectorAll('input,select').forEach(function (el) {
      el.addEventListener('change', syncSchema);
      el.addEventListener('keyup', syncSchema);
    });
    row.querySelector('.f-type').addEventListener('change', syncOptionsVisibility);
    syncOptionsVisibility();
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
      container.appendChild(fieldRow({
        name: field.name || '',
        label: field.label || '',
        type: field.type || 'text',
        required: !!field.required,
        options: Array.isArray(field.options) ? field.options : [],
        visible_if: (field.visible_if && typeof field.visible_if === 'object') ? field.visible_if : null
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
    renderSchemaRows([]);
  }

  function syncSchema() {
    const schema = [];
    container.querySelectorAll('.field-row').forEach(function (row) {
      const name = row.querySelector('.f-name').value.trim();
      const label = row.querySelector('.f-label').value.trim();
      const type = row.querySelector('.f-type').value;
      const optionsRaw = row.querySelector('.f-options').value;
      const required = row.querySelector('.f-required').checked;
      if (name && label) {
        const field = { name: name, label: label, type: type, required: required };
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
        if (visibleIfField && visibleIfValue) {
          field.visible_if = { field: visibleIfField, operator: visibleIfOperator, value: visibleIfValue };
        }
        schema.push(field);
      }
    });
    hiddenSchema.value = JSON.stringify(schema);
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
      try {
        schema = JSON.parse(rawSchema);
      } catch (e) {
        schema = [];
      }

      if (scholarshipIdInput) scholarshipIdInput.value = scholarshipId;
      if (titleInput) titleInput.value = title;
      if (descriptionInput) descriptionInput.value = description;
      if (statusInput) statusInput.value = status;
      if (modeLabel) modeLabel.innerHTML = '<strong>Mode:</strong> Editing scholarship #' + scholarshipId + ' (new version will be saved)';

      renderSchemaRows(schema);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  resetEditor();
})();
</script>
</html>
