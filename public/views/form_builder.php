<?php
$templates = form_builder_starter_templates();
$catalog = form_builder_field_catalog();
$templateMeta = is_array($formBuilderTemplateMeta ?? null) ? $formBuilderTemplateMeta : form_builder_starter_template('basic_application');
$selectedTemplate = (string)($formBuilderSelectedTemplate ?? 'basic_application');
$draftSchema = (string)($formBuilderDraftSchema ?? form_builder_starter_template_json('basic_application'));
?>

<h2>Form Builder Workspace (Step 12)</h2>
<p>Purpose: start the dedicated form-builder module for admin-managed scholarship forms.</p>

<div class="grid">
  <div class="card">
    <h3>Starter Template</h3>
    <form method="post" action="<?= h(app_route('form_builder')) ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Template</label>
      <select name="template">
        <?php foreach ($templates as $key => $meta): ?>
          <option value="<?= h((string)$key) ?>" <?= $selectedTemplate === (string)$key ? 'selected' : '' ?>>
            <?= h((string)$meta['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <br>
      <button class="btn" type="submit">Load Template</button>
    </form>

    <p style="margin-top:10px;"><strong>Description:</strong> <?= h((string)($templateMeta['description'] ?? '')) ?></p>
  </div>

  <div class="card">
    <h3>Field Catalog</h3>
    <table class="table">
      <thead><tr><th>Type</th><th>Use</th></tr></thead>
      <tbody>
      <?php foreach ($catalog as $type => $description): ?>
        <tr>
          <td><?= h((string)$type) ?></td>
          <td><?= h((string)$description) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top: 14px;">
  <h3>Schema Draft (JSON)</h3>
  <p>This is the initial schema draft for Step 12. Next sub-step is persistence and visual drag/drop editing.</p>
  <textarea rows="18" readonly><?= h($draftSchema) ?></textarea>
</div>
