<?php

declare(strict_types=1);

const PROFILE_REQUIRED_USER_FIELDS = [
  'email',
  'user_type',
];

const PROFILE_REQUIRED_PROFILE_FIELDS = [
  'first_name',
  'middle_name',
  'last_name',
  'date_of_birth',
  'nationality',
  'phone_country_code',
  'phone_number',
  'whatsapp_number',
  'address_country',
  'address_city',
  'address_zip_code',
  'address_text',
];

const ROLE_HIERARCHY = [
  'student' => 1,
  'manager' => 2,
  'admin' => 3,
  'it' => 4,
];

function ensure_user_profile_exists(PDO $pdo, int $userId, string $fallbackFullName = ''): void
{
  $check = $pdo->prepare('SELECT user_id FROM user_profiles WHERE user_id = ? LIMIT 1');
  $check->execute([$userId]);
  if ($check->fetch()) {
    return;
  }

  $firstName = '';
  $lastName = '';
  $full = trim($fallbackFullName);
  if ($full !== '') {
    $parts = preg_split('/\s+/', $full) ?: [];
    if ($parts !== []) {
      $firstName = (string)array_shift($parts);
      $lastName = trim(implode(' ', $parts));
    }
  }

  $defaultPhoneCode = '';
  if (function_exists('phone_country_codes_ready') && function_exists('phone_default_country_code') && phone_country_codes_ready($pdo)) {
    $defaultPhoneCode = phone_default_country_code($pdo);
  }

  $ins = $pdo->prepare('INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone_country_code) VALUES (?, ?, ?, ?, ?)');
  $ins->execute([$userId, $firstName, '', $lastName, $defaultPhoneCode !== '' ? $defaultPhoneCode : null]);
}

function get_user_profile(PDO $pdo, int $userId): ?array
{
  $stmt = $pdo->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
  $stmt->execute([$userId]);
  $profile = $stmt->fetch();

  if ($profile && trim((string)($profile['phone_country_code'] ?? '')) === '' && function_exists('phone_country_codes_ready') && function_exists('phone_default_country_code') && phone_country_codes_ready($pdo)) {
    $defaultPhoneCode = phone_default_country_code($pdo);
    if ($defaultPhoneCode !== '') {
      $update = $pdo->prepare('UPDATE user_profiles SET phone_country_code = ? WHERE user_id = ?');
      $update->execute([$defaultPhoneCode, $userId]);
      $profile['phone_country_code'] = $defaultPhoneCode;
    }
  }

  return $profile ?: null;
}

function profile_missing_required_fields(array $user, array $profile): array
{
  $missing = [];
  $requiredUserFields = [
    'email' => (string)($user['email'] ?? ''),
    'user_type' => (string)($user['role'] ?? ''),
  ];

  foreach (PROFILE_REQUIRED_USER_FIELDS as $field) {
    $value = trim((string)($requiredUserFields[$field] ?? ''));
    if ($value === '') {
      $missing[] = $field;
    }
  }

  foreach (PROFILE_REQUIRED_PROFILE_FIELDS as $field) {
    $value = trim((string)($profile[$field] ?? ''));
    if ($value === '') {
      $missing[] = $field;
    }
  }

  return $missing;
}

function is_profile_complete(array $user, array $profile): bool
{
  return profile_missing_required_fields($user, $profile) === [];
}

function can_manage_profiles(array $user): bool
{
  return in_array((string)$user['role'], ['manager', 'admin', 'it'], true);
}

function can_view_profiles(array $user): bool
{
  return in_array((string)$user['role'], ['admin', 'manager', 'it'], true);
}

function role_rank(string $role): int
{
  return ROLE_HIERARCHY[$role] ?? 0;
}

function assignable_roles_for_actor(array $actor): array
{
  $role = (string)($actor['role'] ?? '');
  if ($role === 'it') {
    return ['it', 'admin', 'manager', 'student'];
  }
  if ($role === 'admin') {
    return ['manager', 'student'];
  }
  if ($role === 'manager') {
    return ['student'];
  }
  return [];
}

function can_control_role(array $actor, string $targetRole): bool
{
  return in_array($targetRole, assignable_roles_for_actor($actor), true);
}

function can_access_profile_target(array $actor, array $targetUser): bool
{
  $actorId = (int)($actor['id'] ?? 0);
  $targetId = (int)($targetUser['id'] ?? 0);
  if ($actorId > 0 && $actorId === $targetId) {
    return true;
  }

  return can_control_role($actor, (string)($targetUser['role'] ?? ''));
}

function normalize_application_filters(array $query): array
{
  $status = strtolower(trim((string)($query['app_status'] ?? '')));
  if (!in_array($status, ['', 'submitted', 'in_review', 'approved', 'rejected'], true)) {
    $status = '';
  }

  $from = trim((string)($query['app_from'] ?? ''));
  if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
  }

  $to = trim((string)($query['app_to'] ?? ''));
  if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
  }

  return [
    'status' => $status,
    'from' => $from,
    'to' => $to,
    'scholarship_id' => max(0, (int)($query['app_scholarship_id'] ?? 0)),
  ];
}

function normalize_profile_application_filters(array $query): array
{
  return normalize_application_filters($query);
}

function fetch_tenant_scholarship_options(PDO $pdo, int $tenantId): array
{
  $stmt = $pdo->prepare('SELECT id, title FROM scholarships WHERE tenant_id = ? ORDER BY title ASC');
  $stmt->execute([$tenantId]);
  return $stmt->fetchAll();
}

function fetch_profile_student_applications(PDO $pdo, int $tenantId, int $studentId, array $filters): array
{
  $query = 'SELECT a.id, s.title AS scholarship_title, a.status, a.created_at
    FROM applications a
    JOIN scholarships s ON s.id = a.scholarship_id
    WHERE a.tenant_id = ? AND a.student_id = ?';
  $params = [$tenantId, $studentId];

  if ((string)$filters['status'] !== '') {
    $query .= ' AND a.status = ?';
    $params[] = (string)$filters['status'];
  }

  if ((string)$filters['from'] !== '') {
    $query .= ' AND a.created_at >= ?';
    $params[] = (string)$filters['from'] . ' 00:00:00';
  }

  if ((string)$filters['to'] !== '') {
    $query .= ' AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = (string)$filters['to'] . ' 00:00:00';
  }

  if ((int)$filters['scholarship_id'] > 0) {
    $query .= ' AND a.scholarship_id = ?';
    $params[] = (int)$filters['scholarship_id'];
  }

  $query .= ' ORDER BY a.id DESC';
  $stmt = $pdo->prepare($query);
  $stmt->execute($params);
  return $stmt->fetchAll();
}

function summarize_application_statuses(array $applications): array
{
  $summary = [
    'submitted' => 0,
    'in_review' => 0,
    'approved' => 0,
    'rejected' => 0,
  ];

  foreach ($applications as $row) {
    $status = (string)($row['status'] ?? '');
    if (isset($summary[$status])) {
      $summary[$status]++;
    }
  }

  return $summary;
}
