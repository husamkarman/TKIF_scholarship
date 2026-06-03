<?php

declare(strict_types=1);

$tenantId = (int)$user['tenant_id'];

$tenantUsersStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM users WHERE tenant_id = ?');
$tenantUsersStmt->execute([$tenantId]);
$tenantUsersTotal = (int)($tenantUsersStmt->fetch()['total'] ?? 0);

$linkedUsersStmt = $pdo->prepare(
  'SELECT COUNT(DISTINCT i.user_id) AS total
   FROM user_identities i
   INNER JOIN users u ON u.id = i.user_id
   WHERE u.tenant_id = ?'
);
$linkedUsersStmt->execute([$tenantId]);
$linkedUsersTotal = (int)($linkedUsersStmt->fetch()['total'] ?? 0);

$coverageStmt = $pdo->prepare(
  'SELECT i.provider, COUNT(*) AS identities, COUNT(DISTINCT i.user_id) AS users_linked
   FROM user_identities i
   INNER JOIN users u ON u.id = i.user_id
   WHERE u.tenant_id = ?
   GROUP BY i.provider
   ORDER BY i.provider ASC'
);
$coverageStmt->execute([$tenantId]);
$coverageRows = $coverageStmt->fetchAll();

$duplicateEmailStmt = $pdo->prepare(
  'SELECT LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS total,
          GROUP_CONCAT(CAST(id AS CHAR) ORDER BY id SEPARATOR ",") AS user_ids
   FROM users
   WHERE tenant_id = ?
   GROUP BY LOWER(TRIM(email))
   HAVING COUNT(*) > 1
   ORDER BY total DESC, normalized_email ASC
   LIMIT 100'
);
$duplicateEmailStmt->execute([$tenantId]);
$duplicateEmails = $duplicateEmailStmt->fetchAll();

$pendingLinksStmt = $pdo->prepare(
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
   ORDER BY u.id DESC
   LIMIT 100'
);
$pendingLinksStmt->execute([$tenantId]);
$pendingLinks = $pendingLinksStmt->fetchAll();
?>

<h2>Identity Diagnostics</h2>
<p>Tenant #<?= (int)$tenantId ?> identity-link health for Admin and IT.</p>
<p><a class="btn" href="/?page=identity_diagnostics_export">Export Backfill Candidates CSV</a></p>

<div class="grid" style="margin-bottom: 14px;">
  <div class="card">
    <h3>Coverage Summary</h3>
    <table class="table">
      <tbody>
        <tr><th>Total Users</th><td><?= (int)$tenantUsersTotal ?></td></tr>
        <tr><th>Users With Provider Identity</th><td><?= (int)$linkedUsersTotal ?></td></tr>
        <tr><th>Users Without Provider Identity</th><td><?= max(0, (int)$tenantUsersTotal - (int)$linkedUsersTotal) ?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3>By Provider</h3>
    <table class="table">
      <thead><tr><th>Provider</th><th>Identity Rows</th><th>Users Linked</th></tr></thead>
      <tbody>
        <?php if ($coverageRows === []): ?>
          <tr><td colspan="3">No provider links yet.</td></tr>
        <?php else: ?>
          <?php foreach ($coverageRows as $row): ?>
            <tr>
              <td><?= h((string)$row['provider']) ?></td>
              <td><?= (int)$row['identities'] ?></td>
              <td><?= (int)$row['users_linked'] ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-bottom:14px;">
  <h3>Duplicate Emails (Current Tenant)</h3>
  <table class="table">
    <thead><tr><th>Normalized Email</th><th>Count</th><th>User IDs</th></tr></thead>
    <tbody>
      <?php if ($duplicateEmails === []): ?>
        <tr><td colspan="3">No duplicate emails found.</td></tr>
      <?php else: ?>
        <?php foreach ($duplicateEmails as $row): ?>
          <tr>
            <td><?= h((string)$row['normalized_email']) ?></td>
            <td><?= (int)$row['total'] ?></td>
            <td><?= h((string)$row['user_ids']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Potential Link Backfill Candidates</h3>
  <p>Users with profile auth provider id set but no row in user_identities.</p>
  <table class="table">
    <thead><tr><th>User ID</th><th>Name</th><th>Email</th><th>Role</th><th>Tenant Code</th><th>Inferred Provider</th><th>auth_provider_id</th></tr></thead>
    <tbody>
      <?php if ($pendingLinks === []): ?>
        <tr><td colspan="7">No pending candidates.</td></tr>
      <?php else: ?>
        <?php foreach ($pendingLinks as $row): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><?= h((string)$row['full_name']) ?></td>
            <td><?= h((string)$row['email']) ?></td>
            <td><?= h((string)$row['role']) ?></td>
            <td><?= h((string)$row['tenant_code']) ?></td>
            <td><?= h((string)$row['inferred_provider']) ?></td>
            <td><?= h((string)$row['auth_provider_id']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
