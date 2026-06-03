<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/lib/db.php';
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
  require dirname(__DIR__) . '/vendor/autoload.php';
}
require dirname(__DIR__) . '/app/lib/notify.php';

use Shuchkin\SimpleXLSX;

session_name($config['session_name']);
session_start();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

    $stmt = $pdo->prepare('SELECT id, tenant_id, full_name, email, role, password_hash FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        header('Location: /?page=dashboard');
        exit;
    }

    $error = 'Invalid credentials';
}

if ($page === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    require_csrf();
    $user = require_login();
    if ($user['role'] !== 'student') {
        http_response_code(403);
        exit('Forbidden');
    }

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
      $reason = 'Blacklisted: ' . (string)$blacklist['reason'];
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
  } elseif ($reason === '') {
    $error = 'Blacklist reason is required for compliance and audit traceability.';
  } else {
    $insert = $pdo->prepare('INSERT INTO blacklist_entries (tenant_id, register_id, email_original, email_normalized, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([
      $user['tenant_id'],
      $registerId > 0 ? $registerId : null,
      $email !== '' ? $email : null,
      $emailNorm,
      $reason,
      $user['id'],
    ]);

    $matchedUserIds = collect_blacklisted_user_ids($pdo, (int)$user['tenant_id'], $registerId > 0 ? $registerId : null, $emailNorm);
    $rejectedCount = reject_inflight_applications($pdo, (int)$user['tenant_id'], $matchedUserIds, (int)$user['id'], 'Blacklisted: ' . $reason);
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

      if ($idxReason === false || ($idxRegister === false && $idxEmail === false)) {
        $error = 'Missing required headers. Use register_id,email,reason';
      } else {
        $inserted = 0;
        $rejectedTotal = 0;
        $insert = $pdo->prepare('INSERT INTO blacklist_entries (tenant_id, register_id, email_original, email_normalized, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i < count($rows); $i++) {
          $row = $rows[$i];
          $registerId = $idxRegister !== false ? (int)trim((string)($row[$idxRegister] ?? '0')) : 0;
          $email = $idxEmail !== false ? trim((string)($row[$idxEmail] ?? '')) : '';
          $emailNorm = $email !== '' ? normalize_email($email) : null;
          $reason = trim((string)($row[$idxReason] ?? ''));

          if ($reason === '' || ($registerId <= 0 && $emailNorm === null)) {
            continue;
          }

          $insert->execute([
            $user['tenant_id'],
            $registerId > 0 ? $registerId : null,
            $email !== '' ? $email : null,
            $emailNorm,
            $reason,
            $user['id'],
          ]);
          $inserted++;

          $matchedUserIds = collect_blacklisted_user_ids($pdo, (int)$user['tenant_id'], $registerId > 0 ? $registerId : null, $emailNorm);
          $rejectedTotal += reject_inflight_applications($pdo, (int)$user['tenant_id'], $matchedUserIds, (int)$user['id'], 'Blacklisted: ' . $reason);
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

    <?php elseif ($page === 'dashboard' && $user): ?>
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
                <form method="post" action="/?page=apply">
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
          <?php if ($user['role'] === 'admin'): ?>
            <div class="card" style="margin-bottom: 14px;">
              <h3>Create Scholarship</h3>
              <form method="post" action="/?page=create_scholarship" id="create-scholarship-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="form_schema_json" id="form_schema_json" value="[]">

                <label>Title</label>
                <input name="title" required>

                <label>Description</label>
                <textarea name="description" rows="3"></textarea>

                <label>Status</label>
                <select name="status">
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                  <option value="closed">Closed</option>
                </select>

                <h4>Form Fields</h4>
                <div id="fields-builder"></div>
                <button class="btn" type="button" id="add-field-btn">Add Field</button>
                <button class="btn primary" type="submit">Create Scholarship</button>
              </form>
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
                <label>Reason (mandatory)</label>
                <input name="reason" required placeholder="Example: Fraud attempt / Duplicate identity">
                <br>
                <button class="btn" type="submit">Add Blacklist Entry</button>
              </form>

              <h4 style="margin-top:14px;">Import Excel/CSV</h4>
              <p>Headers required: register_id,email,reason</p>
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
                  <td><?= h($b['reason']) ?></td>
                  <td><?= h($b['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

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
