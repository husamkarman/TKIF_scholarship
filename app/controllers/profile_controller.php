<?php

declare(strict_types=1);

function handle_profile_save_request(PDO $pdo, array $actor, array $post): array
{
  $targetUserId = (int)($post['user_id'] ?? $actor['id']);
  $isManager = can_manage_profiles($actor);

  if (!$isManager && $targetUserId !== (int)$actor['id']) {
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

  ensure_user_profile_exists($pdo, (int)$targetUser['id'], (string)$targetUser['full_name']);

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

  if ($isManager) {
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

  if ($isManager) {
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
    if (in_array($userType, ['student', 'admin', 'manager', 'it'], true)) {
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
