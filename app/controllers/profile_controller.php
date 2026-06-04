<?php

declare(strict_types=1);

function audit_profile_security_event(PDO $pdo, array $actor, int $targetUserId, string $eventName, array $details): void
{
  $audit = $pdo->prepare('INSERT INTO audit_logs (tenant_id, actor_user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?)');
  $audit->execute([
    (int)$actor['tenant_id'],
    (int)$actor['id'],
    $eventName,
    'user_profile',
    $targetUserId,
    json_encode($details, JSON_UNESCAPED_UNICODE),
  ]);
}

function handle_profile_save_request(PDO $pdo, array $actor, array $post): array
{
  $targetUserId = (int)($post['user_id'] ?? $actor['id']);
  $canManageAny = can_manage_profiles($actor);

  if (!$canManageAny && $targetUserId !== (int)$actor['id']) {
    http_response_code(403);
    exit('Forbidden');
  }

  $targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, is_active FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
  $targetStmt->execute([$targetUserId, $actor['tenant_id']]);
  $targetUser = $targetStmt->fetch();

  if (!$targetUser) {
    return [
      'error' => 'Target user not found.',
      'message' => '',
      'page' => 'dashboard',
      'target_user_id' => null,
    ];
  }

  $canAccessTarget = can_access_profile_target($actor, $targetUser);
  $canControlTarget = can_control_role($actor, (string)$targetUser['role']);
  if (!$canAccessTarget && $targetUserId !== (int)$actor['id']) {
    audit_profile_security_event($pdo, $actor, $targetUserId, 'profile_hierarchy_access_denied', [
      'target_role' => (string)$targetUser['role'],
      'actor_role' => (string)$actor['role'],
    ]);
    http_response_code(403);
    exit('Forbidden');
  }

  ensure_user_profile_exists($pdo, (int)$targetUser['id'], (string)$targetUser['full_name']);
  $currentProfile = get_user_profile($pdo, $targetUserId) ?: [];

  if (!$canControlTarget) {
    $attemptedRole = strtolower(trim((string)($post['user_type'] ?? '')));
    $attemptedStatus = strtolower(trim((string)($post['profile_status'] ?? '')));
    $attemptedEmail = normalize_email(trim((string)($post['primary_email'] ?? '')));
    $attemptedProviderId = trim((string)($post['auth_provider_id'] ?? ''));

    $currentRole = strtolower((string)$targetUser['role']);
    $currentStatus = ((int)$targetUser['is_active'] === 1) ? 'active' : 'inactive';
    $currentEmail = normalize_email((string)$targetUser['email']);
    $currentProviderId = trim((string)($currentProfile['auth_provider_id'] ?? ''));

    $violations = [];
    if ($attemptedRole !== '' && $attemptedRole !== $currentRole) {
      $violations['user_type'] = $attemptedRole;
    }
    if ($attemptedStatus !== '' && $attemptedStatus !== $currentStatus) {
      $violations['profile_status'] = $attemptedStatus;
    }
    if ($attemptedEmail !== '' && $attemptedEmail !== $currentEmail) {
      $violations['primary_email'] = $attemptedEmail;
    }
    if ($attemptedProviderId !== '' && $attemptedProviderId !== $currentProviderId) {
      $violations['auth_provider_id'] = $attemptedProviderId;
    }

    if ($violations !== []) {
      audit_profile_security_event($pdo, $actor, $targetUserId, 'profile_hierarchy_violation', [
        'target_role' => (string)$targetUser['role'],
        'actor_role' => (string)$actor['role'],
        'attempted_fields' => $violations,
      ]);
      http_response_code(403);
      exit('Forbidden');
    }
  }

  $profileFields = [
    'phone_country_code' => trim((string)($post['phone_country_code'] ?? '')),
    'phone_number' => trim((string)($post['phone_number'] ?? '')),
    'whatsapp_number' => trim((string)($post['whatsapp_number'] ?? '')),
    'secondary_email' => trim((string)($post['secondary_email'] ?? '')),
    'address_country' => trim((string)($post['address_country'] ?? '')),
    'address_city' => trim((string)($post['address_city'] ?? '')),
    'address_zip_code' => trim((string)($post['address_zip_code'] ?? '')),
    'address_text' => trim((string)($post['address_text'] ?? '')),
  ];

  if (phone_country_codes_ready($pdo)) {
    $phoneValidation = phone_validate_input(
      $pdo,
      (string)$profileFields['phone_country_code'],
      (string)$profileFields['phone_number'],
      false
    );
    if (!($phoneValidation['ok'] ?? false)) {
      return [
        'error' => (string)($phoneValidation['error'] ?? 'Invalid phone format.'),
        'message' => '',
        'page' => 'profile',
        'target_user_id' => $targetUserId,
      ];
    }

    $profileFields['phone_number'] = (string)($phoneValidation['number'] ?? '');
  }

  if ($canControlTarget) {
    $profileFields['first_name'] = trim((string)($post['first_name'] ?? ''));
    $profileFields['middle_name'] = trim((string)($post['middle_name'] ?? ''));
    $profileFields['last_name'] = trim((string)($post['last_name'] ?? ''));
    $profileFields['date_of_birth'] = trim((string)($post['date_of_birth'] ?? ''));
    $profileFields['nationality'] = trim((string)($post['nationality'] ?? ''));
    $profileFields['auth_provider_id'] = trim((string)($post['auth_provider_id'] ?? ''));
  }

  $updates = [];
  $params = [];
  foreach ($profileFields as $field => $value) {
    $updates[] = $field . ' = ?';
    $params[] = $value !== '' ? $value : null;
  }
  $params[] = $targetUserId;
  $updStmt = $pdo->prepare('UPDATE user_profiles SET ' . implode(', ', $updates) . ' WHERE user_id = ?');
  $updStmt->execute($params);

  if ($canControlTarget) {
    $newEmail = trim((string)($post['primary_email'] ?? ''));
    if ($newEmail !== '') {
      $emailUpdate = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
      $emailUpdate->execute([normalize_email($newEmail), $targetUserId]);
    }

    $status = strtolower(trim((string)($post['profile_status'] ?? '')));
    if (in_array($status, ['active', 'inactive'], true)) {
      $statusUpdate = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
      $statusUpdate->execute([$status === 'active' ? 1 : 0, $targetUserId]);
    }

    $userType = strtolower(trim((string)($post['user_type'] ?? '')));
    $assignableRoles = assignable_roles_for_actor($actor);
    if ($userType !== '' && !in_array($userType, $assignableRoles, true)) {
      audit_profile_security_event($pdo, $actor, $targetUserId, 'profile_hierarchy_violation', [
        'target_role' => (string)$targetUser['role'],
        'actor_role' => (string)$actor['role'],
        'attempted_user_type' => $userType,
      ]);
      http_response_code(403);
      exit('Forbidden');
    }
    if (in_array($userType, $assignableRoles, true)) {
      $roleUpdate = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
      $roleUpdate->execute([$userType, $targetUserId]);
    }

    $nameStmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM user_profiles WHERE user_id = ? LIMIT 1');
    $nameStmt->execute([$targetUserId]);
    $nameRow = $nameStmt->fetch();
    if ($nameRow) {
      $full = trim(implode(' ', array_filter([(string)$nameRow['first_name'], (string)$nameRow['middle_name'], (string)$nameRow['last_name']])));
      if ($full !== '') {
        $fu = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?');
        $fu->execute([$full, $targetUserId]);
      }
    }
  }

  $audit = $pdo->prepare('INSERT INTO audit_logs (tenant_id, actor_user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?)');
  $audit->execute([
    (int)$actor['tenant_id'],
    (int)$actor['id'],
    'profile_updated',
    'user_profile',
    $targetUserId,
    json_encode(['target_user_id' => $targetUserId], JSON_UNESCAPED_UNICODE),
  ]);

  return [
    'error' => '',
    'message' => 'Profile updated successfully.',
    'page' => 'profile',
    'target_user_id' => $targetUserId,
  ];
}
