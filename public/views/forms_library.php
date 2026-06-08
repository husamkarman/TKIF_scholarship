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

<?php if ($usingUnified && count($forms) > 1): ?>
  <div class="card" style="margin-bottom: 14px;">
    <h3>Bulk Theme Apply</h3>
    <p class="muted">Copy one form theme to multiple target forms in a single action.</p>
    <form method="post" action="<?= h(app_route('forms_library_apply_theme')) ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;" onsubmit="return confirm('Apply selected source theme to all chosen target forms?');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="return_q" value="<?= h($formsSearch) ?>">
      <input type="hidden" name="return_status" value="<?= h($formsStatus) ?>">
      <div style="min-width:260px;">
        <label>Source Form</label>
        <select name="source_form_id" required>
          <option value="">Choose source form...</option>
          <?php foreach ($forms as $source): ?>
            <?php $sourceId = (int)($source['id'] ?? 0); ?>
            <?php if ($sourceId <= 0) { continue; } ?>
            <option value="<?= $sourceId ?>">#<?= $sourceId ?> - <?= h((string)($source['title'] ?? 'Untitled')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="min-width:320px; flex:1 1 320px;">
        <label>Target Forms</label>
        <select name="target_form_ids[]" multiple size="6" required>
          <?php foreach ($forms as $target): ?>
            <?php $targetId = (int)($target['id'] ?? 0); ?>
            <?php if ($targetId <= 0) { continue; } ?>
            <option value="<?= $targetId ?>">#<?= $targetId ?> - <?= h((string)($target['title'] ?? 'Untitled')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button class="btn" type="submit">Apply Theme to Selected</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h3>All Forms</h3>
  <?php if (!$usingUnified): ?>
    <p class="muted">Unified forms tables are not ready yet. Showing legacy scholarship-backed forms.</p>
  <?php endif; ?>

  <?php if ($forms === []): ?>
    <p>No forms found for current filter.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>ID</th><th>Title</th><th>Theme</th><th>Status</th><th>Published Ver</th><th>Responses</th><th>Last Response</th><th>Updated</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($forms as $form): ?>
        <?php
          $formId = (int)($form['id'] ?? 0);
          $formTitle = (string)($form['title'] ?? 'Untitled Form');
          $formStatus = (string)($form['status'] ?? 'draft');
          $latestPublishedVersionNo = (int)($form['latest_published_version_no'] ?? 0);
          $responseCount = (int)($form['response_count'] ?? 0);
          $lastResponseAt = (string)($form['last_response_at'] ?? '');
          $formUpdated = (string)($form['updated_at'] ?? $form['created_at'] ?? '');
          $settings = json_decode((string)($form['settings_json'] ?? '{}'), true);
          $builderType = strtolower(trim((string)($settings['builder_type'] ?? 'scholarship')));
          if (!in_array($builderType, ['scholarship', 'survey', 'quiz'], true)) {
            $builderType = 'scholarship';
          }
          $theme = normalize_form_theme(json_decode((string)($form['theme_json'] ?? '{}'), true));
          $themeSwatchStyle = '--theme-primary:' . (string)$theme['primary_color']
            . ';--theme-accent:' . (string)$theme['accent_color']
            . ';--theme-background:' . (string)$theme['background_color']
            . ';--theme-surface:' . (string)$theme['surface_color']
            . ';--theme-text:' . (string)$theme['text_color']
            . ';--theme-font:' . (string)$theme['font_family'] . ';';
          $archiveTarget = $formStatus === 'archived' ? 'draft' : 'archived';
          $archiveLabel = $formStatus === 'archived' ? 'Unarchive' : 'Archive';
        ?>
        <tr>
          <td>#<?= $formId ?></td>
          <td><?= h($formTitle) ?></td>
          <td>
            <div class="form-theme-swatch" style="<?= h($themeSwatchStyle) ?>" title="<?= h((string)$theme['font_family']) ?>">
              <span class="swatch-chip swatch-bg"></span>
              <span class="swatch-chip swatch-surface"></span>
              <span class="swatch-chip swatch-primary"></span>
              <span class="swatch-chip swatch-accent"></span>
              <span class="swatch-font"><?= h((string)$theme['font_family']) ?></span>
            </div>
          </td>
          <td><?= h($formStatus) ?></td>
          <td><?= $latestPublishedVersionNo > 0 ? ('v' . (string)$latestPublishedVersionNo) : '-' ?></td>
          <td><?= $responseCount ?></td>
          <td><?= h($lastResponseAt !== '' ? $lastResponseAt : '-') ?></td>
          <td><?= h($formUpdated) ?></td>
          <td style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn" href="<?= h(app_route('form_builder') . '&form_id=' . $formId . '&builder_type=' . rawurlencode($builderType)) ?>">Open Builder</a>
            <a class="btn" href="<?= h(app_route('form_responses_export') . '&format=csv&form_id=' . $formId) ?>">CSV</a>
            <a class="btn" href="<?= h(app_route('form_responses_export') . '&format=xls&form_id=' . $formId) ?>">Excel</a>
            <form method="post" action="<?= h(app_route('forms_library_duplicate')) ?>" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="form_id" value="<?= $formId ?>">
              <input type="hidden" name="return_q" value="<?= h($formsSearch) ?>">
              <input type="hidden" name="return_status" value="<?= h($formsStatus) ?>">
              <button class="btn" type="submit">Duplicate</button>
            </form>
            <form method="post" action="<?= h(app_route('forms_library_archive_toggle')) ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to <?= h(strtolower($archiveLabel)) ?> this form?');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="form_id" value="<?= $formId ?>">
              <input type="hidden" name="target_status" value="<?= h($archiveTarget) ?>">
              <input type="hidden" name="return_q" value="<?= h($formsSearch) ?>">
              <input type="hidden" name="return_status" value="<?= h($formsStatus) ?>">
              <button class="btn" type="submit"><?= h($archiveLabel) ?></button>
            </form>
            <?php if ($usingUnified): ?>
              <form method="post" action="<?= h(app_route('forms_library_apply_theme')) ?>" style="display:inline-flex; gap:6px; align-items:center;" onsubmit="return confirm('Apply this theme to selected target form?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="source_form_id" value="<?= $formId ?>">
                <input type="hidden" name="return_q" value="<?= h($formsSearch) ?>">
                <input type="hidden" name="return_status" value="<?= h($formsStatus) ?>">
                <select name="target_form_id" required style="min-width: 160px;">
                  <option value="">Apply theme to...</option>
                  <?php foreach ($forms as $targetForm): ?>
                    <?php $targetId = (int)($targetForm['id'] ?? 0); ?>
                    <?php if ($targetId <= 0 || $targetId === $formId) { continue; } ?>
                    <option value="<?= $targetId ?>">#<?= $targetId ?> - <?= h((string)($targetForm['title'] ?? 'Untitled')) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Apply Theme</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
