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

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /?page=login');
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

  $allowedTypes = ['text', 'textarea', 'number', 'email', 'date'];
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

    $normalized[] = [
      'name' => $name,
      'label' => $label,
      'type' => $type,
      'required' => $required,
    ];
  }

  return $normalized;
}

function render_dynamic_field(array $field, array $old = []): void
{
  $name = (string)$field['name'];
  $label = (string)$field['label'];
  $type = (string)$field['type'];
  $required = (bool)$field['required'];
  $value = (string)($old[$name] ?? '');
  $requiredAttr = $required ? ' required' : '';

  echo '<label>' . h($label) . ($required ? ' *' : '') . '</label>';
  if ($type === 'textarea') {
    echo '<textarea name="answers[' . h($name) . ']" rows="4"' . $requiredAttr . '>' . h($value) . '</textarea>';
    return;
  }

  $inputType = in_array($type, ['text', 'number', 'email', 'date'], true) ? $type : 'text';
  echo '<input name="answers[' . h($name) . ']" type="' . h($inputType) . '" value="' . h($value) . '"' . $requiredAttr . '>';
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

function post_to_n8n(array $config, array $payload): void
{
    $url = rtrim($config['n8n']['base_url'], '/') . $config['n8n']['submit_hook'];
    $json = json_encode($payload);
    if ($json === false || !function_exists('curl_init')) {
        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }

    $headers = ['Content-Type: application/json'];
    if (!empty($config['n8n']['api_key'])) {
        $headers[] = 'X-N8N-API-KEY: ' . $config['n8n']['api_key'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 3,
    ]);
    curl_exec($ch);
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

if ($page === 'logout') {
    session_destroy();
    header('Location: /?page=login');
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
        header('Location: /?page=dashboard');
        exit;
    }

    $error = 'Invalid credentials';
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
        header('Location: /?page=dashboard');
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
              header('Location: /?page=dashboard');
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
              header('Location: /?page=dashboard');
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
    $stmt = $pdo->prepare('SELECT id, form_schema_json FROM scholarships WHERE id = ? AND tenant_id = ? AND status = "published" LIMIT 1');
    $stmt->execute([$scholarshipId, $user['tenant_id']]);
    $scholarship = $stmt->fetch();
    if (!$scholarship) {
      http_response_code(404);
      exit('Scholarship not found');
    }

    $blacklist = blacklist_match($pdo, (int)$user['tenant_id'], (int)$user['id'], (string)$user['email']);
    if ($blacklist) {
      $reason = 'Blacklisted: ' . blacklist_reason_text($blacklist['reason'] ?? null);
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

      $error = 'Application auto-rejected because this registration is blacklisted.';
      $page = 'dashboard';
    } else {

    $rawSchema = json_decode((string)$scholarship['form_schema_json'], true);
    $schema = normalize_form_schema($rawSchema);
    if ($schema === []) {
      http_response_code(422);
      exit('Invalid scholarship form schema');
    }

    $answersInput = $_POST['answers'] ?? [];
    $answers = [];
    foreach ($schema as $field) {
      $fieldName = (string)$field['name'];
      $value = trim((string)($answersInput[$fieldName] ?? ''));
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
      post_to_n8n($config, [
        'event' => 'application_submitted',
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
        'student_email' => $user['email'],
      ]);

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
    if ($user['role'] !== 'admin') {
      http_response_code(403);
      exit('Forbidden');
    }

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
    } else {
      $stmt = $pdo->prepare('INSERT INTO scholarships (tenant_id, title, description, status, form_schema_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
      $stmt->execute([
        $user['tenant_id'],
        $title,
        $description !== '' ? $description : null,
        $status,
        json_encode($schema, JSON_UNESCAPED_UNICODE),
        $user['id'],
      ]);
      $message = 'Scholarship created successfully.';
      $page = 'dashboard';
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

    post_to_n8n($config, [
        'event' => 'application_' . $status,
        'application_id' => $applicationId,
        'tenant_id' => $user['tenant_id'],
        'by' => $user['email'],
    ]);

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
          <a href="/?page=dashboard">Dashboard</a>
          <a href="/?page=profile">Profile</a>
          <?php if (in_array((string)$user['role'], ['admin', 'it'], true)): ?>
            <a href="/?page=identity_diagnostics">Identity Diagnostics</a>
          <?php endif; ?>
          <a href="/?page=logout">Logout</a>
        <?php else: ?>
          <a href="/?page=login">Login</a>
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
      <p>This starter includes role dashboards and a basic application workflow connected to n8n webhook.</p>
      <a class="btn primary" href="/?page=login">Start</a>

    <?php elseif ($page === 'login'): ?>
      <h2>Login</h2>
      <p>Demo password for seeded users: <strong>Password123!</strong></p>
      <form method="post" action="/?page=login">
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
      <form method="post" action="/?page=login_otp_request">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label>Email</label>
        <input name="email" type="email" required>
        <br><br>
        <button class="btn" type="submit">Send OTP</button>
      </form>

      <?php if (!empty($_SESSION['otp_user_email'])): ?>
        <form method="post" action="/?page=login_otp_verify" style="margin-top: 10px;">
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
        <a class="btn" href="/?page=auth_microsoft_start">Sign in with Microsoft</a>
      <?php else: ?>
        <p>Microsoft OAuth is not fully configured yet.</p>
      <?php endif; ?>

      <hr>
      <h3>Google Login</h3>
      <?php if (google_oauth_ready($config)): ?>
        <a class="btn" href="/?page=auth_google_start">Sign in with Google</a>
      <?php else: ?>
        <p>Google OAuth is not fully configured yet.</p>
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
  const form = document.getElementById('create-scholarship-form');
  if (!form) {
    return;
  }

  const container = document.getElementById('fields-builder');
  const hiddenSchema = document.getElementById('form_schema_json');
  const addBtn = document.getElementById('add-field-btn');

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
      '</select>' +
      '<label><input type="checkbox" class="f-required"> Required</label>' +
      '<button type="button" class="btn remove-field">Remove</button>';
    row.querySelector('.f-type').value = defaults.type || 'text';
    row.querySelector('.f-required').checked = !!defaults.required;
    row.querySelector('.remove-field').addEventListener('click', function () {
      row.remove();
      syncSchema();
    });
    row.querySelectorAll('input,select').forEach(function (el) {
      el.addEventListener('change', syncSchema);
      el.addEventListener('keyup', syncSchema);
    });
    return row;
  }

  function syncSchema() {
    const schema = [];
    container.querySelectorAll('.field-row').forEach(function (row) {
      const name = row.querySelector('.f-name').value.trim();
      const label = row.querySelector('.f-label').value.trim();
      const type = row.querySelector('.f-type').value;
      const required = row.querySelector('.f-required').checked;
      if (name && label) {
        schema.push({ name: name, label: label, type: type, required: required });
      }
    });
    hiddenSchema.value = JSON.stringify(schema);
  }

  addBtn.addEventListener('click', function () {
    container.appendChild(fieldRow({}));
    syncSchema();
  });

  container.appendChild(fieldRow({ name: 'full_name', label: 'Full Name', type: 'text', required: true }));
  syncSchema();
})();
</script>
</html>
