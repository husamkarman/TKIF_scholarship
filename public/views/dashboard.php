<h2><?= h(ucfirst($user['role'])) ?> Dashboard</h2>
<p>Welcome, <?= h($user['name']) ?> (Tenant #<?= (int)$user['tenant_id'] ?>)</p>

<?php if ($pdo): ?>
  <?php if ($user['role'] === 'student'): ?>
    <?php
      $stmt = $pdo->prepare('SELECT id, title, description, form_schema_json FROM scholarships WHERE tenant_id = ? AND status = "published" ORDER BY id DESC');
      $stmt->execute([$user['tenant_id']]);
      $scholarships = $stmt->fetchAll();
    ?>
    <div class="grid">
      <?php foreach ($scholarships as $s): ?>
        <div class="card">
          <h3><?= h($s['title']) ?></h3>
          <p><?= h((string)$s['description']) ?></p>
          <form method="post" action="/?page=apply" class="scholarship-apply-form" data-scholarship-id="<?= (int)$s['id'] ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="scholarship_id" value="<?= (int)$s['id'] ?>">
            <?php
              $schema = normalize_form_schema(json_decode((string)$s['form_schema_json'], true));
              foreach ($schema as $field) {
                  render_dynamic_field($field);
              }
            ?>
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
    <?php if (in_array($user['role'], ['admin', 'it'], true)): ?>
      <div class="card" style="margin-bottom: 14px;">
        <h3>Create Scholarship</h3>
        <form method="post" action="/?page=create_scholarship" id="create-scholarship-form">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="scholarship_id" id="scholarship_id" value="0">
          <input type="hidden" name="form_schema_json" id="form_schema_json" value="[]">

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

          <h4>Form Fields</h4>
          <div id="fields-builder"></div>
          <button class="btn" type="button" id="add-field-btn">Add Field</button>
          <button class="btn" type="button" id="reset-scholarship-editor">Reset Editor</button>
          <button class="btn primary" type="submit" id="save-scholarship-btn">Save Scholarship</button>
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
                    <form method="post" action="/?page=publish_scholarship_version">
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
        <p>Unique matching keys: register_id and normalized email.</p>
        <form method="post" action="/?page=blacklist_add">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <label>register_id (optional if email is provided)</label>
          <input type="number" name="register_id" min="1" placeholder="e.g. 125">
          <label>Email (optional if register_id is provided)</label>
          <input type="email" name="email" placeholder="student@example.com">
          <label>Reason (optional)</label>
          <input name="reason" placeholder="Example: Fraud attempt / Duplicate identity">
          <br>
          <button class="btn" type="submit">Add Blacklist Entry</button>
        </form>

        <h4 style="margin-top:14px;">Import Excel/CSV</h4>
        <p>Headers supported: register_id,email,reason (reason optional)</p>
        <form method="post" action="/?page=blacklist_import" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="file" name="blacklist_file" accept=".csv,.xlsx" required>
          <button class="btn" type="submit">Import Blacklist File</button>
        </form>
      </div>
    <?php endif; ?>
    <?php
      $stmt = $pdo->prepare('SELECT a.id, a.status, a.rejection_reason, a.created_at, u.full_name AS student_name, s.title AS scholarship_title
                             FROM applications a
                             JOIN users u ON u.id = a.student_id
                             JOIN scholarships s ON s.id = a.scholarship_id
                             WHERE a.tenant_id = ?
                             ORDER BY a.id DESC LIMIT 20');
      $stmt->execute([$user['tenant_id']]);
      $applications = $stmt->fetchAll();

      $newUsersStmt = $pdo->prepare('SELECT id, full_name, email, role, created_at FROM users WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY) ORDER BY id DESC LIMIT 30');
      $newUsersStmt->execute([$user['tenant_id']]);
      $newUsers = $newUsersStmt->fetchAll();

      $oldUsersStmt = $pdo->prepare('SELECT id, full_name, email, role, created_at FROM users WHERE tenant_id = ? AND created_at < (NOW() - INTERVAL 7 DAY) ORDER BY id DESC LIMIT 30');
      $oldUsersStmt->execute([$user['tenant_id']]);
      $oldUsers = $oldUsersStmt->fetchAll();

      $blacklistStmt = $pdo->prepare('SELECT register_id, email_original, reason, created_at FROM blacklist_entries WHERE tenant_id = ? ORDER BY id DESC LIMIT 50');
      $blacklistStmt->execute([$user['tenant_id']]);
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
    ?>
    <div class="grid" style="margin-bottom: 14px;">
      <div class="card">
        <h3>New Registrations (7 days)</h3>
        <table class="table">
          <thead><tr><th>register_id</th><th>Name</th><th>Email</th><th>Role</th></tr></thead>
          <tbody>
          <?php foreach ($newUsers as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= h($u['full_name']) ?></td>
              <td><?= h($u['email']) ?></td>
              <td><?= h($u['role']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card">
        <h3>Old Registrations</h3>
        <table class="table">
          <thead><tr><th>register_id</th><th>Name</th><th>Email</th><th>Role</th></tr></thead>
          <tbody>
          <?php foreach ($oldUsers as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= h($u['full_name']) ?></td>
              <td><?= h($u['email']) ?></td>
              <td><?= h($u['role']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
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
            <?php if (in_array($user['role'], ['admin', 'manager'], true)): ?>
              <td>
                <form method="post" action="/?page=decide">
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
