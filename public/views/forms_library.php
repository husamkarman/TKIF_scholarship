<?php
$forms = is_array($formsLibraryRows ?? null) ? $formsLibraryRows : [];
$formsSearch = (string)($formsLibrarySearch ?? '');
$formsStatus = (string)($formsLibraryStatus ?? 'all');
$usingUnified = (bool)($formsLibraryUsingUnified ?? false);
?>

<h2>Forms Library</h2>
<p>Manage all created forms, open any form in the builder, and export responses per form.</p>

<div class="card" style="margin-bottom: 14px;">
  <form method="get" action="<?= h(app_route('forms_library')) ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
    <input type="hidden" name="page" value="forms_library">
    <div>
      <label>Search</label>
      <input type="text" name="q" value="<?= h($formsSearch) ?>" placeholder="Title or description">
    </div>
    <div>
      <label>Status</label>
      <select name="status">
        <option value="all" <?= $formsStatus === 'all' ? 'selected' : '' ?>>all</option>
        <option value="draft" <?= $formsStatus === 'draft' ? 'selected' : '' ?>>draft</option>
        <option value="published" <?= $formsStatus === 'published' ? 'selected' : '' ?>>published</option>
        <option value="closed" <?= $formsStatus === 'closed' ? 'selected' : '' ?>>closed</option>
        <option value="archived" <?= $formsStatus === 'archived' ? 'selected' : '' ?>>archived</option>
      </select>
    </div>
    <div>
      <button class="btn" type="submit">Apply</button>
      <a class="btn" href="<?= h(app_route('forms_library')) ?>">Reset</a>
      <a class="btn primary" href="<?= h(app_route('form_builder')) ?>">Create New Form</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>All Forms</h3>
  <?php if (!$usingUnified): ?>
    <p class="muted">Unified forms tables are not ready yet. Showing legacy scholarship-backed forms.</p>
  <?php endif; ?>

  <?php if ($forms === []): ?>
    <p>No forms found for current filter.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($forms as $form): ?>
        <?php
          $formId = (int)($form['id'] ?? 0);
          $formTitle = (string)($form['title'] ?? 'Untitled Form');
          $formStatus = (string)($form['status'] ?? 'draft');
          $formUpdated = (string)($form['updated_at'] ?? $form['created_at'] ?? '');
          $settings = json_decode((string)($form['settings_json'] ?? '{}'), true);
          $builderType = strtolower(trim((string)($settings['builder_type'] ?? 'scholarship')));
          if (!in_array($builderType, ['scholarship', 'survey', 'quiz'], true)) {
            $builderType = 'scholarship';
          }
        ?>
        <tr>
          <td>#<?= $formId ?></td>
          <td><?= h($formTitle) ?></td>
          <td><?= h($formStatus) ?></td>
          <td><?= h($formUpdated) ?></td>
          <td style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn" href="<?= h(app_route('form_builder') . '&form_id=' . $formId . '&builder_type=' . rawurlencode($builderType)) ?>">Open Builder</a>
            <a class="btn" href="<?= h(app_route('form_responses_export') . '&format=csv&form_id=' . $formId) ?>">CSV</a>
            <a class="btn" href="<?= h(app_route('form_responses_export') . '&format=xls&form_id=' . $formId) ?>">Excel</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
