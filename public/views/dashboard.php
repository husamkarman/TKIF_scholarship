<h2><?= h(ucfirst($user['role'])) ?> Dashboard</h2>
<p>Welcome, <?= h($user['name']) ?> (Tenant #<?= (int)$user['tenant_id'] ?>)</p>

<?php if ($pdo): ?>
  <?php
    $applicationPhoneCountries = phone_country_codes_ready($pdo) ? phone_country_code_rows($pdo, true) : [];
    $usersBlacklistReady = users_blacklist_column_ready($pdo);
  ?>
  <?php if ($user['role'] === 'student'): ?>
    <?php
      $stmt = $pdo->prepare('SELECT id, title, description, form_schema_json FROM scholarships WHERE tenant_id = ? AND status = "published" ORDER BY id DESC');
      $stmt->execute([$user['tenant_id']]);
      $scholarships = $stmt->fetchAll();

      $studentProfile = get_user_profile($pdo, (int)$user['id']) ?: [];
      $studentApplications = fetch_profile_student_applications($pdo, (int)$user['tenant_id'], (int)$user['id'], normalize_profile_application_filters([]));

      $studentStatusCounts = [
        'submitted' => 0,
        'in_review' => 0,
        'approved' => 0,
        'rejected' => 0,
      ];
      foreach ($studentApplications as $applicationRow) {
        $status = (string)($applicationRow['status'] ?? '');
        if (array_key_exists($status, $studentStatusCounts)) {
          $studentStatusCounts[$status]++;
        }
      }

      $missingProfileFields = profile_missing_required_fields($user, $studentProfile);
      $profileCompletion = (int)round(((count(PROFILE_REQUIRED_USER_FIELDS) + count(PROFILE_REQUIRED_PROFILE_FIELDS) - count($missingProfileFields)) / max(1, count(PROFILE_REQUIRED_USER_FIELDS) + count(PROFILE_REQUIRED_PROFILE_FIELDS))) * 100);

      $upcomingDeadlines = [];
      foreach ($scholarships as $scholarshipRow) {
        $schemaPayload = json_decode((string)($scholarshipRow['form_schema_json'] ?? '[]'), true);
        $settings = scholarship_form_settings_from_raw($schemaPayload);
        $deadlineValue = trim((string)($settings['submission_end_at'] ?? ''));
        if ($deadlineValue === '') {
          continue;
        }
        $deadline = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $deadlineValue) ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $deadlineValue);
        if ($deadline instanceof DateTimeImmutable && $deadline >= new DateTimeImmutable('now')) {
          $upcomingDeadlines[] = [
            'id' => (int)$scholarshipRow['id'],
            'title' => (string)$scholarshipRow['title'],
            'deadline' => $deadline,
          ];
        }
      }
      usort($upcomingDeadlines, static function (array $left, array $right): int {
        return ($left['deadline'] <=> $right['deadline']);
      });
      $upcomingDeadlines = array_slice($upcomingDeadlines, 0, 5);
    ?>
    <div class="grid" style="margin-bottom: 14px;">
      <div class="card"><strong><?= count($scholarships) ?></strong><br><span class="muted">Available Scholarships</span></div>
      <div class="card"><strong><?= count($studentApplications) ?></strong><br><span class="muted">My Applications</span></div>
      <div class="card"><strong><?= (int)$studentStatusCounts['submitted'] + (int)$studentStatusCounts['in_review'] ?></strong><br><span class="muted">Applications In Progress</span></div>
      <div class="card"><strong><?= (int)$studentStatusCounts['approved'] ?></strong><br><span class="muted">Approved Applications</span></div>
      <div class="card"><strong><?= (int)$studentStatusCounts['rejected'] ?></strong><br><span class="muted">Rejected Applications</span></div>
      <div class="card"><strong><?= $profileCompletion ?>%</strong><br><span class="muted">Profile Completion</span></div>
    </div>

    <div class="grid" style="margin-bottom: 14px;">
      <div class="card">
        <h3>Profile Status</h3>
        <p><?= $profileCompletion ?>% complete</p>
        <?php if ($missingProfileFields !== []): ?>
          <p class="muted">Missing: <?= h(implode(', ', array_slice($missingProfileFields, 0, 6))) ?></p>
        <?php endif; ?>
      </div>
      <div class="card">
        <h3>Upcoming Deadlines</h3>
        <?php if ($upcomingDeadlines === []): ?>
          <p class="muted">No deadlines tracked from current scholarship settings.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($upcomingDeadlines as $deadlineRow): ?>
              <li><?= h((string)$deadlineRow['title']) ?> - <?= h($deadlineRow['deadline']->format('Y-m-d H:i')) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-bottom: 14px;">
      <h3>My Applications</h3>
      <?php if ($studentApplications === []): ?>
        <p class="muted">You have not submitted any applications yet.</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>ID</th><th>Scholarship</th><th>Status</th><th>Submitted</th></tr></thead>
          <tbody>
          <?php foreach ($studentApplications as $applicationRow): ?>
            <tr>
              <td><?= (int)$applicationRow['id'] ?></td>
              <td><?= h((string)$applicationRow['scholarship_title']) ?></td>
              <td><span class="badge"><?= h((string)$applicationRow['status']) ?></span></td>
              <td><?= h((string)$applicationRow['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-bottom: 14px;">
      <h3>Applications by Status</h3>
      <div class="grid">
        <div class="card"><strong><?= (int)$studentStatusCounts['submitted'] ?></strong><br><span class="muted">Submitted</span></div>
        <div class="card"><strong><?= (int)$studentStatusCounts['in_review'] ?></strong><br><span class="muted">In Review</span></div>
        <div class="card"><strong><?= (int)$studentStatusCounts['approved'] ?></strong><br><span class="muted">Approved</span></div>
        <div class="card"><strong><?= (int)$studentStatusCounts['rejected'] ?></strong><br><span class="muted">Rejected</span></div>
      </div>
    </div>

    <div class="grid">
      <?php foreach ($scholarships as $s): ?>
        <div class="card">
          <h3><?= h($s['title']) ?></h3>
          <p><?= h((string)$s['description']) ?></p>
          <?php
            $rawSchemaPayload = json_decode((string)$s['form_schema_json'], true);
            $settings = scholarship_form_settings_from_raw($rawSchemaPayload);
            $autosaveSeconds = (int)($settings['autosave_interval_seconds'] ?? 30);
            if ($autosaveSeconds < 5) {
              $autosaveSeconds = 30;
            }
          ?>
          <form method="post" action="<?= h(app_route('apply')) ?>" class="scholarship-apply-form" data-scholarship-id="<?= (int)$s['id'] ?>" data-autosave-seconds="<?= (int)$autosaveSeconds ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="scholarship_id" value="<?= (int)$s['id'] ?>">
            <?php
              $nodes = normalize_scholarship_nodes($rawSchemaPayload);
              $schema = flatten_scholarship_nodes($nodes);
              foreach ($schema as $field) {
                  render_dynamic_field($field, [], $applicationPhoneCountries);
              }
            ?>
            <?php if (captcha_is_enabled($config)): ?>
              <?= captcha_widget_markup($config) ?>
            <?php endif; ?>
            <br>
            <button class="btn primary" type="submit">Submit Application</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <?php
      $schStmt = $pdo->prepare('SELECT id, title, description, status, form_schema_json, created_at FROM scholarships WHERE tenant_id = ? ORDER BY id DESC LIMIT 50');
      $schStmt->execute([$user['tenant_id']]);
      $scholarshipsForAdmin = $schStmt->fetchAll();

      $notificationJobs = [];
      if (function_exists('notification_jobs_ready') && notification_jobs_ready($pdo)) {
        $jobStmt = $pdo->prepare(
          'SELECT id, event_name, status, attempts, max_attempts, last_error, updated_at
           FROM notification_jobs
           WHERE tenant_id = ?
           ORDER BY id DESC
           LIMIT 30'
        );
        $jobStmt->execute([$user['tenant_id']]);
        $notificationJobs = $jobStmt->fetchAll();
      }

      $notificationDeliveries = [];
      if (function_exists('notification_inbox_ready') && notification_inbox_ready($pdo)) {
        $deliveryStmt = $pdo->prepare(
          'SELECT id, event_name, delivery_route, status, error_message, received_at
           FROM notification_inbox
           WHERE tenant_id = ? AND user_agent = "internal-worker"
           ORDER BY id DESC
           LIMIT 30'
        );
        $deliveryStmt->execute([$user['tenant_id']]);
        $notificationDeliveries = $deliveryStmt->fetchAll();
      }

      $scholarshipVersions = [];
      if (function_exists('scholarship_form_versioning_ready') && scholarship_form_versioning_ready($pdo)) {
        $verStmt = $pdo->prepare(
          'SELECT v.scholarship_id, s.title AS scholarship_title, v.version_no, v.status, v.created_at
           FROM scholarship_form_versions v
           INNER JOIN scholarships s ON s.id = v.scholarship_id
           WHERE v.tenant_id = ?
           ORDER BY v.scholarship_id DESC, v.version_no DESC
           LIMIT 200'
        );
        $verStmt->execute([$user['tenant_id']]);
        $scholarshipVersions = $verStmt->fetchAll();
      }
    ?>
    <?php if ($user['role'] === 'manager'): ?>
      <?php
        $managementStatsStmt = $pdo->prepare(
          'SELECT
             COUNT(*) AS total_applications,
             SUM(CASE WHEN a.status = "submitted" THEN 1 ELSE 0 END) AS submitted_count,
             SUM(CASE WHEN a.status = "in_review" THEN 1 ELSE 0 END) AS review_count,
             SUM(CASE WHEN a.status = "approved" THEN 1 ELSE 0 END) AS approved_count,
             SUM(CASE WHEN a.status = "rejected" THEN 1 ELSE 0 END) AS rejected_count
           FROM applications a
           WHERE a.tenant_id = ?'
        );
        $managementStatsStmt->execute([$user['tenant_id']]);
        $managementStats = $managementStatsStmt->fetch() ?: [];

        $managementApplications = [];
        $managementAppStmt = $pdo->prepare(
          'SELECT a.id, a.status, a.created_at, u.id AS student_id, u.full_name AS student_name, s.title AS scholarship_title
           FROM applications a
           INNER JOIN users u ON u.id = a.student_id
           INNER JOIN scholarships s ON s.id = a.scholarship_id
           WHERE a.tenant_id = ?
           ORDER BY a.id DESC
           LIMIT 20'
        );
        $managementAppStmt->execute([$user['tenant_id']]);
        $managementApplications = $managementAppStmt->fetchAll();
      ?>
      <div class="grid" style="margin-bottom: 14px;">
        <div class="card"><strong><?= (int)($managementStats['total_applications'] ?? 0) ?></strong><br><span class="muted">Total Applications</span></div>
        <div class="card"><strong><?= (int)($managementStats['submitted_count'] ?? 0) ?></strong><br><span class="muted">Submitted</span></div>
        <div class="card"><strong><?= (int)($managementStats['review_count'] ?? 0) ?></strong><br><span class="muted">In Review</span></div>
        <div class="card"><strong><?= (int)($managementStats['approved_count'] ?? 0) ?></strong><br><span class="muted">Approved</span></div>
        <div class="card"><strong><?= (int)($managementStats['rejected_count'] ?? 0) ?></strong><br><span class="muted">Rejected</span></div>
        <div class="card"><strong><?= count($scholarshipsForAdmin) ?></strong><br><span class="muted">Scholarships</span></div>
      </div>

      <div class="card" style="margin-bottom: 14px;">
        <h3>Management Drilldown</h3>
        <?php if ($managementApplications === []): ?>
          <p class="muted">No applications available yet.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>ID</th><th>Student</th><th>Scholarship</th><th>Status</th><th>Created</th><th>Export</th></tr></thead>
            <tbody>
            <?php foreach ($managementApplications as $applicationRow): ?>
              <tr>
                <td><?= (int)$applicationRow['id'] ?></td>
                <td><?= h((string)$applicationRow['student_name']) ?></td>
                <td><?= h((string)$applicationRow['scholarship_title']) ?></td>
                <td><span class="badge"><?= h((string)$applicationRow['status']) ?></span></td>
                <td><?= h((string)$applicationRow['created_at']) ?></td>
                <td><a class="btn" href="<?= h(app_route('profile_export') . '?user_id=' . (int)$applicationRow['student_id']) ?>">CSV</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (in_array($user['role'], ['admin', 'it'], true)): ?>
      <div class="card" style="margin-bottom: 14px;">
        <h3>Form Builder Workspace</h3>
        <p>Step 12 has started. Open the dedicated workspace to load starter templates and shape schema drafts.</p>
        <a class="btn" href="<?= h(app_route('form_builder')) ?>">Open Form Builder</a>
      </div>

      <div class="card" style="margin-bottom: 14px;">
        <h3>Create Scholarship</h3>
        <form method="post" action="<?= h(app_route('create_scholarship')) ?>" id="create-scholarship-form">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="scholarship_id" id="scholarship_id" value="0">
          <input type="hidden" name="form_schema_json" id="form_schema_json" value="[]">
          <input type="hidden" name="form_settings_json" id="form_settings_json" value="{}">

          <p id="scholarship-editor-mode"><strong>Mode:</strong> Create new scholarship</p>

          <label>Title</label>
          <input name="title" id="scholarship_title_input" required>

          <label>Description</label>
          <textarea name="description" id="scholarship_description_input" rows="3"></textarea>

          <label>Status</label>
          <select name="status" id="scholarship_status_input">
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="closed">Closed</option>
          </select>

          <p style="margin-top:8px; color:#555;">When editing an existing scholarship, each save creates a new form version.</p>

          <h4>Form Settings</h4>
          <div class="grid">
            <label><input type="checkbox" id="setting_one_response_per_user"> One response per user</label>
            <label><input type="checkbox" id="setting_allow_edit_after_submit"> Allow edit after submit</label>
            <div>
              <label>Autosave interval (seconds)</label>
              <input id="setting_autosave_interval_seconds" type="number" min="5" max="300" value="30">
            </div>
            <div>
              <label>Submission start</label>
              <input id="setting_submission_start_at" type="datetime-local">
            </div>
            <div>
              <label>Submission end</label>
              <input id="setting_submission_end_at" type="datetime-local">
            </div>
          </div>

          <h4>Page Nodes</h4>
          <div id="fields-builder"></div>
          <button class="btn" type="button" id="add-field-btn">Add Node</button>
          <button class="btn" type="button" id="reset-scholarship-editor">Reset Editor</button>
          <button class="btn primary" type="submit" id="save-scholarship-btn">Save Scholarship</button>

          <h4 style="margin-top:14px;">Live Preview (Section Flow)</h4>
          <div id="scholarship-form-preview" class="card" style="background:#fff;"></div>
        </form>
      </div>

      <div class="card" style="margin-bottom: 14px;">
        <h3>Manage Scholarships</h3>
        <table class="table">
          <thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($scholarshipsForAdmin as $sc): ?>
            <tr>
              <td><?= (int)$sc['id'] ?></td>
              <td><?= h((string)$sc['title']) ?></td>
              <td><?= h((string)$sc['status']) ?></td>
              <td><?= h((string)$sc['created_at']) ?></td>
              <td>
                <button
                  class="btn load-scholarship-btn"
                  type="button"
                  data-id="<?= (int)$sc['id'] ?>"
                  data-title="<?= h((string)$sc['title']) ?>"
                  data-description="<?= h((string)($sc['description'] ?? '')) ?>"
                  data-status="<?= h((string)$sc['status']) ?>"
                  data-schema="<?= h((string)$sc['form_schema_json']) ?>"
                >
                  Edit / New Version
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin-bottom: 14px;">
        <h3>Form Versions History</h3>
        <?php if ($scholarshipVersions === []): ?>
          <p>No version records yet (or migration not applied).</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>Scholarship</th><th>Version</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($scholarshipVersions as $ver): ?>
              <tr>
                <td><?= h((string)$ver['scholarship_title']) ?></td>
                <td>v<?= (int)$ver['version_no'] ?></td>
                <td><?= h((string)$ver['status']) ?></td>
                <td><?= h((string)$ver['created_at']) ?></td>
                <td>
                  <?php if ((string)$ver['status'] !== 'published'): ?>
                    <form method="post" action="<?= h(app_route('publish_scholarship_version')) ?>">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="scholarship_id" value="<?= (int)$ver['scholarship_id'] ?>">
                      <input type="hidden" name="version_no" value="<?= (int)$ver['version_no'] ?>">
                      <button class="btn" type="submit">Publish This Version</button>
                    </form>
                  <?php else: ?>
                    <span class="badge">Live</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (in_array($user['role'], ['admin', 'it'], true)): ?>
      <div class="card" style="margin-bottom: 14px;">
        <h3>Blacklist Management</h3>
        <p>Admin and IT use global Registration ID (users.register_id) or email across all tenants. For non-registered persons, use email only. Use Preview Person before adding the blacklist row.</p>
        <form method="post" action="<?= h(app_route('blacklist_add')) ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <label>Registration ID (use for registered users, optional if email is provided)</label>
          <input type="number" name="register_id" min="1" placeholder="e.g. 125" value="<?= h((string)($blacklistForm['register_id'] ?? '')) ?>">
          <label>Email (required for non-registered persons)</label>
          <input type="email" name="email" placeholder="student@example.com" value="<?= h((string)($blacklistForm['email'] ?? '')) ?>">
          <label>Reason (optional)</label>
          <input name="reason" placeholder="Example: Fraud attempt / Duplicate identity" value="<?= h((string)($blacklistForm['reason'] ?? '')) ?>">
          <br>
          <button class="btn" type="submit" formaction="<?= h(app_route('blacklist_preview')) ?>">Preview Person</button>
          <button class="btn" type="submit">Add Blacklist Entry</button>
        </form>

        <?php if (is_array($blacklistPreview ?? null)): ?>
          <div class="card" style="margin-top: 12px;">
            <h4>Preview Result</h4>
            <?php if (($blacklistPreview['mode'] ?? '') === 'found' && is_array($blacklistPreview['user'] ?? null)): ?>
              <?php $previewUser = $blacklistPreview['user']; ?>
              <p>Matched registered user by <?= h((string)($blacklistPreview['matched_by'] ?? 'input')) ?>. Review this person before blacklisting.</p>
              <table class="table">
                <thead><tr><th>register_id</th><th>Name</th><th>Email</th><th>Role</th><th>Created</th><?php if ($usersBlacklistReady): ?><th>Flag</th><th>Action</th><?php endif; ?></tr></thead>
                <tbody>
                  <tr>
                    <td><?= (int)($previewUser['register_id'] ?? $previewUser['id'] ?? 0) ?></td>
                    <td><?= h((string)($previewUser['full_name'] ?? '')) ?></td>
                    <td><?= h((string)($previewUser['email'] ?? '')) ?></td>
                    <td><?= h((string)($previewUser['role'] ?? '')) ?></td>
                    <td><?= h((string)($previewUser['created_at'] ?? '')) ?></td>
                    <?php if ($usersBlacklistReady): ?>
                      <?php $previewFlag = (int)($previewUser['blacklist'] ?? 0); ?>
                      <td><?= $previewFlag === 1 ? 'blacklist (1)' : 'whitelist (0)' ?></td>
                      <td>
                        <form method="post" action="<?= h(app_route('user_blacklist_toggle')) ?>">
                          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                          <input type="hidden" name="user_id" value="<?= (int)($previewUser['id'] ?? 0) ?>">
                          <input type="hidden" name="blacklist" value="<?= $previewFlag === 1 ? 0 : 1 ?>">
                          <button class="btn" type="submit"><?= $previewFlag === 1 ? 'Move to Whitelist' : 'Move to Blacklist' ?></button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                </tbody>
              </table>
            <?php elseif (($blacklistPreview['mode'] ?? '') === 'not_found_id'): ?>
              <p>No registered user was found for Registration ID <?= h((string)($blacklistPreview['register_id'] ?? '')) ?>. If this person is not registered, blacklist by email only.</p>
            <?php elseif (($blacklistPreview['mode'] ?? '') === 'mismatch'): ?>
              <p>Registration ID and email point to different users. Fix input before blacklisting.</p>
            <?php elseif (($blacklistPreview['mode'] ?? '') === 'email_only'): ?>
              <p>No registered user matched this email. You can still blacklist this email for future registrations.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <h4 style="margin-top:14px;">Import Excel/CSV</h4>
        <p>Headers supported: register_id,email,reason (reason optional)</p>
        <form method="post" action="<?= h(app_route('blacklist_import')) ?>" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="file" name="blacklist_file" accept=".csv,.xlsx" required>
          <button class="btn" type="submit">Import Blacklist File</button>
        </form>
      </div>

      <div class="card" style="margin-bottom: 14px;">
        <h3>Admin Support Tools</h3>
        <form method="post" action="<?= h(app_route('admin_user_support')) ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <label>Target User Email</label>
          <input type="email" name="target_email" value="<?= h((string)($adminSupportTargetEmail ?? '')) ?>" required>
          <label>Action</label>
          <select name="support_action" required>
            <option value="verification_attempts">View Verification Attempts</option>
            <option value="login_lockout_status">View Login Lockout Status</option>
            <option value="resend_verification">Resend Verification</option>
            <option value="unlock_user">Unlock User Account</option>
            <option value="clear_login_lockout">Clear Login Lockout</option>
          </select>
          <br>
          <button class="btn" type="submit">Run Support Action</button>
        </form>

        <?php if (!empty($adminSupportRows)): ?>
          <h4 style="margin-top: 12px;">Recent Verification Attempts</h4>
          <table class="table">
            <thead><tr><th>ID</th><th>Channel</th><th>Created</th><th>Expires</th><th>Consumed</th></tr></thead>
            <tbody>
            <?php foreach ($adminSupportRows as $row): ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= h((string)$row['channel']) ?></td>
                <td><?= h((string)$row['created_at']) ?></td>
                <td><?= h((string)$row['expires_at']) ?></td>
                <td><?= h((string)($row['consumed_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <?php if (is_array($adminLoginAttemptSummary ?? null)): ?>
          <h4 style="margin-top: 12px;">Login Lockout Snapshot</h4>
          <p>
            Status:
            <strong><?= (($adminLoginAttemptSummary['enabled'] ?? false) && ($adminLoginAttemptSummary['is_locked'] ?? false)) ? 'LOCKED' : 'Not Locked' ?></strong>
            | Failed in window: <strong><?= (int)($adminLoginAttemptSummary['failed_count'] ?? 0) ?></strong>
            | Success in window: <strong><?= (int)($adminLoginAttemptSummary['success_count'] ?? 0) ?></strong>
            | Threshold: <strong><?= (int)($adminLoginAttemptSummary['threshold'] ?? 0) ?></strong>
            | Window: <strong><?= (int)($adminLoginAttemptSummary['window_seconds'] ?? 0) ?>s</strong>
          </p>
          <p>Last failed login: <strong><?= h((string)($adminLoginAttemptSummary['last_failed_at'] ?? 'n/a')) ?></strong></p>

          <?php if (!empty($adminLoginAttemptRows)): ?>
            <table class="table">
              <thead><tr><th>ID</th><th>IP</th><th>Result</th><th>Created</th></tr></thead>
              <tbody>
              <?php foreach ($adminLoginAttemptRows as $row): ?>
                <tr>
                  <td><?= (int)($row['id'] ?? 0) ?></td>
                  <td><?= h((string)($row['ip_address'] ?? '')) ?></td>
                  <td><?= ((int)($row['success'] ?? 0) === 1) ? 'success' : 'failed' ?></td>
                  <td><?= h((string)($row['created_at'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card" style="margin-bottom: 14px;">
        <h3>Outbound Push Logs (N8N)</h3>
        <p>Queue status and outbound delivery outcomes for this tenant.</p>

        <h4>Queue Jobs</h4>
        <?php if ($notificationJobs === []): ?>
          <p>No queue jobs yet.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>ID</th><th>Event</th><th>Status</th><th>Attempts</th><th>Last Error</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($notificationJobs as $job): ?>
              <tr>
                <td><?= (int)($job['id'] ?? 0) ?></td>
                <td><?= h((string)($job['event_name'] ?? '')) ?></td>
                <td><?= h((string)($job['status'] ?? '')) ?></td>
                <td><?= (int)($job['attempts'] ?? 0) ?>/<?= (int)($job['max_attempts'] ?? 0) ?></td>
                <td><?= h((string)($job['last_error'] ?? '')) ?></td>
                <td><?= h((string)($job['updated_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <h4 style="margin-top:12px;">Delivery Log</h4>
        <?php if ($notificationDeliveries === []): ?>
          <p>No outbound deliveries yet.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>ID</th><th>Event</th><th>Route</th><th>Status</th><th>Error</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($notificationDeliveries as $entry): ?>
              <tr>
                <td><?= (int)($entry['id'] ?? 0) ?></td>
                <td><?= h((string)($entry['event_name'] ?? '')) ?></td>
                <td><?= h((string)($entry['delivery_route'] ?? '')) ?></td>
                <td><?= h((string)($entry['status'] ?? '')) ?></td>
                <td><?= h((string)($entry['error_message'] ?? '')) ?></td>
                <td><?= h((string)($entry['received_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
      $stmt = $pdo->prepare('SELECT a.id, a.status, a.rejection_reason, a.created_at, u.id AS student_id, u.full_name AS student_name, s.title AS scholarship_title
                             FROM applications a
                             JOIN users u ON u.id = a.student_id
                             JOIN scholarships s ON s.id = a.scholarship_id
                             WHERE a.tenant_id = ?
                             ORDER BY a.id DESC LIMIT 20');
      $stmt->execute([$user['tenant_id']]);
      $applications = $stmt->fetchAll();

      $newUsersSql = $usersBlacklistReady
        ? 'SELECT id, register_id, full_name, email, role, blacklist, created_at FROM users WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY) ORDER BY id DESC LIMIT 30'
        : 'SELECT id, register_id, full_name, email, role, created_at FROM users WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY) ORDER BY id DESC LIMIT 30';
      $newUsersStmt = $pdo->prepare($newUsersSql);
      $newUsersStmt->execute([$user['tenant_id']]);
      $newUsers = $newUsersStmt->fetchAll();

      $oldUsersSql = $usersBlacklistReady
        ? 'SELECT id, register_id, full_name, email, role, blacklist, created_at FROM users WHERE tenant_id = ? AND created_at < (NOW() - INTERVAL 7 DAY) ORDER BY id DESC LIMIT 30'
        : 'SELECT id, register_id, full_name, email, role, created_at FROM users WHERE tenant_id = ? AND created_at < (NOW() - INTERVAL 7 DAY) ORDER BY id DESC LIMIT 30';
      $oldUsersStmt = $pdo->prepare($oldUsersSql);
      $oldUsersStmt->execute([$user['tenant_id']]);
      $oldUsers = $oldUsersStmt->fetchAll();

      $blacklistStmt = $pdo->query('SELECT register_id, email_original, reason, created_at FROM blacklist_entries ORDER BY id ASC LIMIT 100');
      $blacklistRows = $blacklistStmt->fetchAll();

      $notificationRows = [];
      if (in_array($user['role'], ['admin', 'it'], true) && function_exists('notification_inbox_ready') && notification_inbox_ready($pdo)) {
        $notificationStmt = $pdo->prepare(
          'SELECT id, event_name, notification_type, correlation_id, delivery_route, status, auth_valid, source_ip, received_at
           FROM notification_inbox
           WHERE tenant_id = ? OR tenant_id IS NULL
           ORDER BY id DESC
           LIMIT 40'
        );
        $notificationStmt->execute([$user['tenant_id']]);
        $notificationRows = $notificationStmt->fetchAll();
      }

      $adminSupportAuditRows = [];
      if (in_array($user['role'], ['admin', 'it'], true)) {
        $supportAuditStmt = $pdo->prepare(
          'SELECT id, event_name, actor_user_id, entity_id, details_json, created_at
           FROM audit_logs
           WHERE tenant_id = ? AND event_name LIKE "admin_support_%"
           ORDER BY id DESC
           LIMIT 60'
        );
        $supportAuditStmt->execute([(int)$user['tenant_id']]);
        $adminSupportAuditRows = $supportAuditStmt->fetchAll();
      }
    ?>
    <?php if (in_array($user['role'], ['admin', 'it'], true)): ?>
      <div class="grid" style="margin-bottom: 14px;">
        <div class="card">
          <h3>New Registrations (7 days)</h3>
          <table class="table">
            <thead><tr><th>register_id</th><th>Name</th><th>Email</th><th>Role</th><?php if ($usersBlacklistReady): ?><th>Flag</th><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($newUsers as $u): ?>
              <tr>
                <td><?= (int)($u['register_id'] ?? $u['id']) ?></td>
                <td><?= h($u['full_name']) ?></td>
                <td><?= h($u['email']) ?></td>
                <td><?= h($u['role']) ?></td>
                <?php if ($usersBlacklistReady): ?>
                  <?php $rowFlag = (int)($u['blacklist'] ?? 0); ?>
                  <td><?= $rowFlag === 1 ? 'blacklist (1)' : 'whitelist (0)' ?></td>
                  <td>
                    <form method="post" action="<?= h(app_route('user_blacklist_toggle')) ?>">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="blacklist" value="<?= $rowFlag === 1 ? 0 : 1 ?>">
                      <button class="btn" type="submit"><?= $rowFlag === 1 ? 'Whitelist' : 'Blacklist' ?></button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card">
          <h3>Old Registrations</h3>
          <table class="table">
            <thead><tr><th>register_id</th><th>Name</th><th>Email</th><th>Role</th><?php if ($usersBlacklistReady): ?><th>Flag</th><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($oldUsers as $u): ?>
              <tr>
                <td><?= (int)($u['register_id'] ?? $u['id']) ?></td>
                <td><?= h($u['full_name']) ?></td>
                <td><?= h($u['email']) ?></td>
                <td><?= h($u['role']) ?></td>
                <?php if ($usersBlacklistReady): ?>
                  <?php $rowFlag = (int)($u['blacklist'] ?? 0); ?>
                  <td><?= $rowFlag === 1 ? 'blacklist (1)' : 'whitelist (0)' ?></td>
                  <td>
                    <form method="post" action="<?= h(app_route('user_blacklist_toggle')) ?>">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="blacklist" value="<?= $rowFlag === 1 ? 0 : 1 ?>">
                      <button class="btn" type="submit"><?= $rowFlag === 1 ? 'Whitelist' : 'Blacklist' ?></button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if (in_array($user['role'], ['admin', 'it'], true)): ?>
      <div class="card" style="margin-bottom: 14px;">
        <h3>Admin Support Audit Events</h3>
        <p>Privileged support actions are logged for traceability.</p>
        <?php if ($adminSupportAuditRows === []): ?>
          <p>No admin support audit events yet.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>ID</th><th>Event</th><th>Actor</th><th>Target User ID</th><th>Details</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($adminSupportAuditRows as $row): ?>
              <tr>
                <td><?= (int)($row['id'] ?? 0) ?></td>
                <td><?= h((string)($row['event_name'] ?? '')) ?></td>
                <td><?= (int)($row['actor_user_id'] ?? 0) ?></td>
                <td><?= (int)($row['entity_id'] ?? 0) ?></td>
                <td><?= h((string)($row['details_json'] ?? '')) ?></td>
                <td><?= h((string)($row['created_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="card" style="margin-bottom:14px;">
        <h3>Blacklist Table</h3>
        <table class="table">
          <thead><tr><th>register_id</th><th>Email</th><th>Reason</th><th>Created</th></tr></thead>
          <tbody>
          <?php foreach ($blacklistRows as $b): ?>
            <tr>
              <td><?= h((string)($b['register_id'] ?? '')) ?></td>
              <td><?= h((string)($b['email_original'] ?? '')) ?></td>
              <td><?= h(blacklist_reason_text($b['reason'] ?? null)) ?></td>
              <td><?= h($b['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (in_array($user['role'], ['admin', 'it'], true)): ?>
      <div class="card" style="margin-bottom:14px;">
        <h3>Internal Notification Inbox</h3>
        <?php if ($notificationRows === []): ?>
          <p>No notification events captured yet.</p>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>ID</th><th>Event</th><th>Type</th><th>Correlation</th><th>Route</th><th>Auth</th><th>Status</th><th>IP</th><th>Received</th></tr></thead>
            <tbody>
            <?php foreach ($notificationRows as $nr): ?>
              <tr>
                <td><?= (int)$nr['id'] ?></td>
                <td><?= h((string)$nr['event_name']) ?></td>
                <td><?= h((string)($nr['notification_type'] ?? '')) ?></td>
                <td><?= h((string)($nr['correlation_id'] ?? '')) ?></td>
                <td><?= h((string)($nr['delivery_route'] ?? '')) ?></td>
                <td><?= (int)$nr['auth_valid'] === 1 ? 'valid' : 'invalid' ?></td>
                <td><?= h((string)$nr['status']) ?></td>
                <td><?= h((string)($nr['source_ip'] ?? '')) ?></td>
                <td><?= h((string)$nr['received_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <table class="table">
      <thead>
        <tr>
          <th>ID</th><th>Student</th><th>Scholarship</th><th>Status</th><th>Created</th>
          <?php if (can_view_profiles($user)): ?><th>Export</th><?php endif; ?>
          <?php if (in_array($user['role'], ['admin', 'manager'], true)): ?><th>Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($applications as $a): ?>
          <tr>
            <td><?= (int)$a['id'] ?></td>
            <td><?= h($a['student_name']) ?></td>
            <td><?= h($a['scholarship_title']) ?></td>
            <td><span class="badge"><?= h($a['status']) ?></span></td>
            <td><?= h($a['created_at']) ?></td>
            <?php if (can_view_profiles($user)): ?>
              <td><a class="btn" href="<?= h(app_route('profile_export') . '?user_id=' . (int)($a['student_id'] ?? 0)) ?>">CSV</a></td>
            <?php endif; ?>
            <?php if (in_array($user['role'], ['admin', 'manager'], true)): ?>
              <td>
                <form method="post" action="<?= h(app_route('decide')) ?>">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                  <select name="status">
                    <option value="approved">Approve</option>
                    <option value="rejected">Reject</option>
                  </select>
                  <input name="reason" placeholder="Reason (optional)">
                  <button class="btn" type="submit">Save</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>
