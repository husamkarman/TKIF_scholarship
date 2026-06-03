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

  $ins = $pdo->prepare('INSERT INTO user_profiles (user_id, first_name, middle_name, last_name) VALUES (?, ?, ?, ?)');
  $ins->execute([$userId, $firstName, '', $lastName]);
}

function get_user_profile(PDO $pdo, int $userId): ?array
{
  $stmt = $pdo->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
  $stmt->execute([$userId]);
  $profile = $stmt->fetch();
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
  return in_array((string)$user['role'], ['admin', 'it'], true);
}

function can_view_profiles(array $user): bool
{
  return in_array((string)$user['role'], ['admin', 'manager', 'it'], true);
}

function normalize_profile_application_filters(array $query): array
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
    $query .= ' AND DATE(a.created_at) >= ?';
    $params[] = (string)$filters['from'];
  }

  if ((string)$filters['to'] !== '') {
    $query .= ' AND DATE(a.created_at) <= ?';
    $params[] = (string)$filters['to'];
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
