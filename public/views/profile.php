<?php
$isManager = can_manage_profiles($user);
$canViewProfiles = can_view_profiles($user);
$targetUserId = (int)($_GET['user_id'] ?? $user['id']);
if (!$canViewProfiles) {
  $targetUserId = (int)$user['id'];
}

$targetStmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, is_active, created_at FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
$targetStmt->execute([$targetUserId, $user['tenant_id']]);
$targetUser = $targetStmt->fetch();

if (!$targetUser):
?>
  <div class="alert err">Profile target user not found.</div>
<?php
else:
  ensure_user_profile_exists($pdo, (int)$targetUser['id'], (string)$targetUser['full_name']);
  $profileData = get_user_profile($pdo, (int)$targetUser['id']) ?: [];
  $profileComplete = is_profile_complete($targetUser, $profileData);
  $missingFields = profile_missing_required_fields($targetUser, $profileData);
  $canEditCurrentProfile = $isManager || ((int)$targetUser['id'] === (int)$user['id']);
?>
  <h2>Profile</h2>
  <p>Register ID: <strong><?= (int)$targetUser['id'] ?></strong> | Role: <strong><?= h($targetUser['role']) ?></strong> | Status: <strong><?= $targetUser['is_active'] ? 'Active' : 'Inactive' ?></strong></p>
  <p>Created At: <strong><?= h((string)$targetUser['created_at']) ?></strong></p>
  <p><strong>Profile fields are independent from scholarship-specific application fields.</strong></p>
  <?php if (!$profileComplete): ?>
    <div class="alert err">Profile incomplete. Missing: <?= h(implode(', ', $missingFields)) ?></div>
  <?php endif; ?>

  <?php if ($canViewProfiles): ?>
    <?php
      $usersStmt = $pdo->prepare('SELECT id, full_name, email, role, is_active FROM users WHERE tenant_id = ? ORDER BY id DESC LIMIT 100');
      $usersStmt->execute([$user['tenant_id']]);
      $manageUsers = $usersStmt->fetchAll();
    ?>
    <div class="card" style="margin-bottom: 14px;">
      <h3>Profile Browser</h3>
      <table class="table">
        <thead><tr><th>Register ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($manageUsers as $mu): ?>
          <tr>
            <td><?= (int)$mu['id'] ?></td>
            <td><?= h($mu['full_name']) ?></td>
            <td><?= h($mu['email']) ?></td>
            <td><?= h($mu['role']) ?></td>
            <td><?= $mu['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td><a class="btn" href="/?page=profile&user_id=<?= (int)$mu['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($canViewProfiles): ?>
    <?php
      $appFilters = normalize_profile_application_filters($_GET);
      $appStatusFilter = (string)$appFilters['status'];
      $appFromFilter = (string)$appFilters['from'];
      $appToFilter = (string)$appFilters['to'];
      $appScholarshipFilter = (int)$appFilters['scholarship_id'];

      $scholarshipFilterOptions = fetch_tenant_scholarship_options($pdo, (int)$user['tenant_id']);
      $studentApplications = fetch_profile_student_applications($pdo, (int)$user['tenant_id'], (int)$targetUser['id'], $appFilters);
      $statusSummary = summarize_application_statuses($studentApplications);

      $exportParams = [
        'page' => 'profile_export',
        'user_id' => (string)(int)$targetUser['id'],
        'app_status' => $appStatusFilter,
        'app_from' => $appFromFilter,
        'app_to' => $appToFilter,
        'app_scholarship_id' => (string)$appScholarshipFilter,
      ];
      $exportUrl = '/?' . http_build_query($exportParams);
    ?>
    <div class="card" style="margin-bottom: 14px;">
      <h3>Student Scholarship Applications</h3>
      <p>Total Applied: <strong><?= count($studentApplications) ?></strong></p>
      <p>
        <span class="badge">Submitted: <?= (int)$statusSummary['submitted'] ?></span>
        <span class="badge">In Review: <?= (int)$statusSummary['in_review'] ?></span>
        <span class="badge">Approved: <?= (int)$statusSummary['approved'] ?></span>
        <span class="badge">Rejected: <?= (int)$statusSummary['rejected'] ?></span>
      </p>
      <form method="get" action="/" style="margin-bottom: 10px;">
        <input type="hidden" name="page" value="profile">
        <input type="hidden" name="user_id" value="<?= (int)$targetUser['id'] ?>">
        <div class="grid">
          <div>
            <label>Status</label>
            <select name="app_status">
              <option value="" <?= $appStatusFilter === '' ? 'selected' : '' ?>>All</option>
              <option value="submitted" <?= $appStatusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
              <option value="in_review" <?= $appStatusFilter === 'in_review' ? 'selected' : '' ?>>In Review</option>
              <option value="approved" <?= $appStatusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
              <option value="rejected" <?= $appStatusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
          </div>
          <div>
            <label>From Date</label>
            <input type="date" name="app_from" value="<?= h($appFromFilter) ?>">
          </div>
          <div>
            <label>To Date</label>
            <input type="date" name="app_to" value="<?= h($appToFilter) ?>">
          </div>
          <div>
            <label>Scholarship</label>
            <select name="app_scholarship_id">
              <option value="0">All</option>
              <?php foreach ($scholarshipFilterOptions as $opt): ?>
                <option value="<?= (int)$opt['id'] ?>" <?= $appScholarshipFilter === (int)$opt['id'] ? 'selected' : '' ?>><?= h((string)$opt['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <br>
        <button class="btn" type="submit">Apply Filters</button>
        <a class="btn" href="/?page=profile&user_id=<?= (int)$targetUser['id'] ?>">Reset</a>
        <a class="btn" href="<?= h($exportUrl) ?>">Export CSV</a>
      </form>
      <table class="table">
        <thead><tr><th>Application ID</th><th>Scholarship</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        <?php foreach ($studentApplications as $sa): ?>
          <tr>
            <td><?= (int)$sa['id'] ?></td>
            <td><?= h((string)$sa['scholarship_title']) ?></td>
            <td><span class="badge"><?= h((string)$sa['status']) ?></span></td>
            <td><?= h((string)$sa['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <form method="post" action="/?page=profile_save">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="user_id" value="<?= (int)$targetUser['id'] ?>">

    <div class="grid">
      <div class="card">
        <h3>System & Identity</h3>
        <label>Primary Email <?= lock_badge_html(!$isManager) ?></label>
        <input name="primary_email" value="<?= h((string)$targetUser['email']) ?>" <?= $isManager ? '' : 'readonly' ?>>

        <label>Authentication Provider ID <?= lock_badge_html(!$isManager) ?></label>
        <input name="auth_provider_id" value="<?= h((string)($profileData['auth_provider_id'] ?? '')) ?>" <?= $isManager ? '' : 'readonly' ?>>

        <label>User Type <?= lock_badge_html(!$isManager) ?></label>
        <select name="user_type" <?= $isManager ? '' : 'disabled' ?> required>
          <option value="student" <?= ((string)$targetUser['role'] === 'student') ? 'selected' : '' ?>>Student</option>
          <option value="admin" <?= ((string)$targetUser['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
          <option value="manager" <?= ((string)$targetUser['role'] === 'manager') ? 'selected' : '' ?>>Management</option>
          <option value="it" <?= ((string)$targetUser['role'] === 'it') ? 'selected' : '' ?>>IT</option>
        </select>

        <label>Profile Status <?= lock_badge_html(!$isManager) ?></label>
        <select name="profile_status" <?= $isManager ? '' : 'disabled' ?>>
          <option value="active" <?= ((int)$targetUser['is_active'] === 1) ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ((int)$targetUser['is_active'] === 0) ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>

      <div class="card">
        <h3>Names</h3>
        <label>First Name <?= lock_badge_html(!$isManager) ?></label>
        <input name="first_name" value="<?= h((string)($profileData['first_name'] ?? '')) ?>" <?= $isManager ? '' : 'readonly' ?>>
        <label>Middle Name <?= lock_badge_html(!$isManager) ?></label>
        <input name="middle_name" value="<?= h((string)($profileData['middle_name'] ?? '')) ?>" <?= $isManager ? '' : 'readonly' ?>>
        <label>Last Name <?= lock_badge_html(!$isManager) ?></label>
        <input name="last_name" value="<?= h((string)($profileData['last_name'] ?? '')) ?>" <?= $isManager ? '' : 'readonly' ?>>
      </div>

      <div class="card">
        <h3>Personal Information</h3>
        <label>Date of Birth <?= lock_badge_html(!$isManager) ?></label>
        <input type="date" name="date_of_birth" value="<?= h((string)($profileData['date_of_birth'] ?? '')) ?>" <?= $isManager ? '' : 'readonly' ?>>
        <label>Nationality <?= lock_badge_html(!$isManager) ?></label>
        <input name="nationality" value="<?= h((string)($profileData['nationality'] ?? '')) ?>" <?= $isManager ? '' : 'readonly' ?>>
      </div>

      <div class="card">
        <h3>Contact Information</h3>
        <label>Phone Country Code <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <input name="phone_country_code" value="<?= h((string)($profileData['phone_country_code'] ?? '')) ?>" <?= $canEditCurrentProfile ? '' : 'readonly' ?>>
        <label>Phone Number <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <input name="phone_number" value="<?= h((string)($profileData['phone_number'] ?? '')) ?>" <?= $canEditCurrentProfile ? '' : 'readonly' ?>>
        <label>WhatsApp Number <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <input name="whatsapp_number" value="<?= h((string)($profileData['whatsapp_number'] ?? '')) ?>" <?= $canEditCurrentProfile ? '' : 'readonly' ?>>
        <label>Secondary Email Address <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <input name="secondary_email" type="email" value="<?= h((string)($profileData['secondary_email'] ?? '')) ?>" <?= $canEditCurrentProfile ? '' : 'readonly' ?>>
      </div>

      <div class="card">
        <h3>Address</h3>
        <label>Address Country <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <input name="address_country" value="<?= h((string)($profileData['address_country'] ?? '')) ?>" <?= $canEditCurrentProfile ? '' : 'readonly' ?>>
        <label>Address City <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <input name="address_city" value="<?= h((string)($profileData['address_city'] ?? '')) ?>" <?= $canEditCurrentProfile ? '' : 'readonly' ?>>
        <label>Address Zip Code <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <input name="address_zip_code" value="<?= h((string)($profileData['address_zip_code'] ?? '')) ?>" <?= $canEditCurrentProfile ? '' : 'readonly' ?>>
        <label>Address <?= lock_badge_html(!$canEditCurrentProfile) ?></label>
        <textarea name="address_text" rows="3" <?= $canEditCurrentProfile ? '' : 'readonly' ?>><?= h((string)($profileData['address_text'] ?? '')) ?></textarea>
      </div>
    </div>

    <?php if ($canEditCurrentProfile): ?>
      <br>
      <button class="btn primary" type="submit">Save Profile</button>
    <?php endif; ?>
  </form>
<?php endif; ?>
