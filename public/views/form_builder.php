<?php
$builderType = strtolower(trim((string)($formBuilderEntityType ?? 'scholarship')));
if (!in_array($builderType, ['scholarship', 'survey', 'quiz'], true)) {
  $builderType = 'scholarship';
}
$templates = form_builder_templates_for_builder_type($builderType);
$catalog = form_builder_field_catalog();
$defaultTemplateKey = (string)array_key_first($templates);
if ($defaultTemplateKey === '') {
  $defaultTemplateKey = 'basic_application';
}
$templateMeta = is_array($formBuilderTemplateMeta ?? null) ? $formBuilderTemplateMeta : form_builder_starter_template($defaultTemplateKey);
$selectedTemplate = (string)($formBuilderSelectedTemplate ?? $defaultTemplateKey);
if (!isset($templates[$selectedTemplate])) {
  $selectedTemplate = $defaultTemplateKey;
  $templateMeta = form_builder_starter_template($selectedTemplate);
}
$draftSchema = (string)($formBuilderDraftSchema ?? form_builder_starter_template_json($selectedTemplate));
$scholarships = is_array($formBuilderScholarships ?? null) ? $formBuilderScholarships : [];
$selectedScholarshipId = (int)($formBuilderScholarshipId ?? 0);
$scholarshipTitle = (string)($formBuilderScholarshipTitle ?? '');
$scholarshipDescription = (string)($formBuilderScholarshipDescription ?? '');
$scholarshipStatus = (string)($formBuilderScholarshipStatus ?? 'draft');

$builderTypeLabels = [
  'scholarship' => 'Scholarship',
  'survey' => 'Survey',
  'quiz' => 'Quiz',
];
$builderTitleLabel = $builderTypeLabels[$builderType] ?? 'Form';
$nodeTypes = ['welcome', 'agreement', 'section', 'form', 'thank_you'];
$loadTemplateHeading = 'Load Template';
$loadTemplateButtonLabel = 'Load Template';
?>

<h2>Form Builder Workspace (Step 12)</h2>
<p>Build <?= h(strtolower($builderTitleLabel)) ?> forms in a dedicated page outside the dashboard.</p>

<div class="grid">
  <div class="card">
    <h3>Builder Type</h3>
    <form method="post" action="<?= h(app_route('form_builder') . '&builder_type=' . h($builderType)) ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="switch_builder_type">
      <label>Mode</label>
      <select name="builder_type">
        <option value="scholarship" <?= $builderType === 'scholarship' ? 'selected' : '' ?>>Scholarship</option>
        <option value="survey" <?= $builderType === 'survey' ? 'selected' : '' ?>>Survey</option>
        <option value="quiz" <?= $builderType === 'quiz' ? 'selected' : '' ?>>Quiz</option>
      </select>
      <br>
      <button class="btn" type="submit">Switch Builder</button>
    </form>
  </div>

  <div class="card">
    <h3><?= h($loadTemplateHeading) ?></h3>
    <form method="post" action="<?= h(app_route('form_builder') . '&builder_type=' . h($builderType)) ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="load_template">
      <label>Template</label>
      <select name="template">
        <?php foreach ($templates as $key => $meta): ?>
          <option value="<?= h((string)$key) ?>" <?= $selectedTemplate === (string)$key ? 'selected' : '' ?>>
            <?= h((string)$meta['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <br>
      <button class="btn" type="submit"><?= h($loadTemplateButtonLabel) ?></button>
    </form>

    <p style="margin-top:10px;"><strong>Description:</strong> <?= h((string)($templateMeta['description'] ?? '')) ?></p>
  </div>

  <div class="card">
    <h3>Builder Actions</h3>
    <p>Preview the current schema, then save or publish it from the same editor.</p>
    <p style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:0;">
      <button class="btn" type="button" id="fb_preview">Preview</button>
      <button class="btn" type="button" id="fb_sync_visual_from_json_inline">Load JSON to Visual</button>
      <button class="btn" type="submit" form="form-builder-save-form" id="fb_save_draft_top">Save Draft</button>
      <button class="btn primary" type="submit" form="form-builder-save-form" id="fb_publish_top">Publish</button>
    </p>
    <?php if ($selectedScholarshipId > 0): ?>
      <p style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; margin-bottom:0;">
        <a class="btn" href="<?= h(app_route('form_responses_export') . '&format=csv&form_id=' . (int)$selectedScholarshipId) ?>">Export Responses CSV</a>
        <a class="btn" href="<?= h(app_route('form_responses_export') . '&format=xls&form_id=' . (int)$selectedScholarshipId) ?>">Export Responses Excel</a>
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Load Existing Form</h3>
    <form method="post" action="<?= h(app_route('form_builder') . '&builder_type=' . h($builderType)) ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="load_form">
      <label>Form</label>
      <select name="load_form_id">
        <option value="0">Create new form</option>
        <?php foreach ($scholarships as $sch): ?>
          <?php $sid = (int)($sch['id'] ?? 0); ?>
          <option value="<?= $sid ?>" <?= $selectedScholarshipId === $sid ? 'selected' : '' ?>>
            #<?= $sid ?> - <?= h((string)($sch['title'] ?? 'Untitled')) ?> (<?= h((string)($sch['status'] ?? 'draft')) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <br>
      <button class="btn" type="submit">Load Form</button>
    </form>
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
  <h3>Form Editor</h3>
  <form method="post" action="<?= h(app_route('form_builder_save')) ?>" id="form-builder-save-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="builder_type" value="<?= h($builderType) ?>">
    <input type="hidden" name="form_id" id="fb_form_id" value="<?= (int)$selectedScholarshipId ?>">
    <input type="hidden" name="scholarship_id" id="fb_scholarship_id" value="<?= (int)$selectedScholarshipId ?>">
    <input type="hidden" name="selected_template" value="<?= h($selectedTemplate) ?>">
    <input type="hidden" name="save_action" id="fb_save_action" value="save_draft">

    <label><?= h($builderTitleLabel) ?> Title</label>
    <input name="title" id="fb_title" value="<?= h($scholarshipTitle) ?>" required>

    <label>Description</label>
    <textarea name="description" id="fb_description" rows="3"><?= h($scholarshipDescription) ?></textarea>

    <p style="margin-top:8px; color:#555;"><strong>Note:</strong> Form Builder now saves unified forms. Builder mode works as an authoring preset.</p>

    <label>Status</label>
    <select name="status" id="fb_status" disabled>
      <option value="draft" <?= $scholarshipStatus === 'draft' ? 'selected' : '' ?>>draft</option>
      <option value="published" <?= $scholarshipStatus === 'published' ? 'selected' : '' ?>>published</option>
      <option value="closed" <?= $scholarshipStatus === 'closed' ? 'selected' : '' ?>>closed</option>
      <option value="archived" <?= $scholarshipStatus === 'archived' ? 'selected' : '' ?>>archived</option>
    </select>
    <p style="color:#555; margin-top:6px;">Status is controlled by action: Save Draft or Publish.</p>

    <div class="grid" style="align-items:start; margin-top: 12px;">
      <div class="card" style="margin:0;">
        <h4>Visual Field Builder</h4>
        <div id="fb_fields_container"></div>
        <button class="btn" type="button" id="fb_add_field">Add Field</button>

        <h4 style="margin-top: 12px;">Schema JSON (editable)</h4>
        <textarea name="form_schema_json" id="fb_schema_json" rows="18"><?= h($draftSchema) ?></textarea>

        <div style="margin-top: 10px; display:flex; gap:8px; flex-wrap:wrap;">
          <button class="btn" type="button" id="fb_sync_visual_from_json">Reload Visual from JSON</button>
          <button class="btn" type="button" id="fb_sync_json_from_visual">Sync JSON from Visual</button>
          <button class="btn" type="submit" id="fb_save_draft">Save Draft</button>
          <button class="btn primary" type="submit" id="fb_publish">Publish</button>
        </div>
      </div>

      <div class="card" style="margin:0;">
        <h4>Preview</h4>
        <div id="fb_preview_panel"><p class="muted">Preview will appear here from the current schema.</p></div>
      </div>
    </div>
  </form>
</div>

<script>
(function () {
  const builderType = <?= json_encode($builderType, JSON_UNESCAPED_UNICODE) ?>;
  const fieldsContainer = document.getElementById('fb_fields_container');
  const schemaTextarea = document.getElementById('fb_schema_json');
  const addBtn = document.getElementById('fb_add_field');
  const saveForm = document.getElementById('form-builder-save-form');
  const saveActionInput = document.getElementById('fb_save_action');
  const syncVisualBtn = document.getElementById('fb_sync_visual_from_json');
  const syncJsonBtn = document.getElementById('fb_sync_json_from_visual');
  const saveDraftBtn = document.getElementById('fb_save_draft');
  const publishBtn = document.getElementById('fb_publish');
  const previewBtn = document.getElementById('fb_preview');
  const previewPanel = document.getElementById('fb_preview_panel');
  const syncVisualInlineBtn = document.getElementById('fb_sync_visual_from_json_inline');
  const topSaveDraftBtn = document.getElementById('fb_save_draft_top');
  const topPublishBtn = document.getElementById('fb_publish_top');

  if (!fieldsContainer || !schemaTextarea || !addBtn || !saveForm) {
    return;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function parseOptions(value) {
    return String(value || '')
      .split(',')
      .map(function (part) { return part.trim(); })
      .filter(function (part) { return part !== ''; });
  }

  function createFieldRow(field) {
    const row = document.createElement('div');
    row.className = 'field-row';
    row.innerHTML =
      '<input class="fb-name" placeholder="field_name" value="' + escapeHtml(field.name || '') + '">' +
      '<input class="fb-label" placeholder="Label" value="' + escapeHtml(field.label || '') + '">' +
      '<select class="fb-type">' +
        '<option value="text">text</option>' +
        '<option value="textarea">textarea</option>' +
        '<option value="number">number</option>' +
        '<option value="email">email</option>' +
        '<option value="date">date</option>' +
        '<option value="phone">phone</option>' +
        '<option value="linear_scale">linear scale</option>' +
        '<option value="select">select</option>' +
        '<option value="radio">radio</option>' +
        '<option value="checkbox">checkbox</option>' +
        '<option value="welcome">welcome</option>' +
        '<option value="agreement">agreement</option>' +
        '<option value="section">section</option>' +
        '<option value="form">form</option>' +
        '<option value="thank_you">thank_you</option>' +
      '</select>' +
      '<select class="fb-text-rule">' +
        '<option value="none">No letter restriction</option>' +
        '<option value="arabic_only">Arabic only</option>' +
        '<option value="english_only">English only</option>' +
        '<option value="turkish_latin_only">Turkish Latin only</option>' +
        '<option value="english_or_arabic">English or Arabic</option>' +
      '</select>' +
      '<select class="fb-agreement-mode">' +
        '<option value="text">Agreement text</option>' +
        '<option value="pdf">Agreement PDF</option>' +
      '</select>' +
      '<input class="fb-help-text" placeholder="Help text / HTML" value="' + escapeHtml(field.help_text || '') + '">' +
      '<input class="fb-image-path" placeholder="Image path (optional)" value="' + escapeHtml(field.image_path || '') + '">' +
      '<input class="fb-agreement-pdf" placeholder="Agreement PDF path" value="' + escapeHtml(field.agreement_pdf_path || '') + '">' +
      '<input class="fb-options" placeholder="Options (comma separated)" value="' + escapeHtml((field.options || []).join(', ')) + '">' +
      '<label><input type="checkbox" class="fb-required"> Required</label>' +
      '<button class="btn fb-remove" type="button">Remove</button>';

    row.querySelector('.fb-type').value = field.type || 'text';
    row.querySelector('.fb-required').checked = !!field.required;
    row.querySelector('.fb-text-rule').value = field.text_rule || 'none';
    row.querySelector('.fb-agreement-mode').value = field.agreement_mode || (field.agreement_pdf_path ? 'pdf' : 'text');

    function toggleOptions() {
      const type = row.querySelector('.fb-type').value;
      const isNodeType = ['welcome', 'agreement', 'section', 'form', 'thank_you'].includes(type);
      row.querySelector('.fb-options').style.display = ['select', 'radio', 'checkbox', 'linear_scale'].includes(type) ? '' : 'none';
      row.querySelector('.fb-text-rule').style.display = ['text', 'textarea'].includes(type) ? '' : 'none';
      row.querySelector('.fb-help-text').style.display = isNodeType ? '' : 'none';
      row.querySelector('.fb-image-path').style.display = ['welcome', 'section', 'form', 'thank_you'].includes(type) ? '' : 'none';
      row.querySelector('.fb-agreement-mode').style.display = type === 'agreement' ? '' : 'none';
      row.querySelector('.fb-agreement-pdf').style.display = type === 'agreement' ? '' : 'none';
      if (!['text', 'textarea'].includes(type)) {
        row.querySelector('.fb-text-rule').value = 'none';
      }
      if (!isNodeType) {
        row.querySelector('.fb-help-text').value = '';
      }
      if (!['welcome', 'section', 'form', 'thank_you'].includes(type)) {
        row.querySelector('.fb-image-path').value = '';
      }
      if (type !== 'agreement') {
        row.querySelector('.fb-agreement-mode').value = 'text';
        row.querySelector('.fb-agreement-pdf').value = '';
      }
    }

    row.querySelector('.fb-type').addEventListener('change', function () {
      toggleOptions();
      syncJsonFromVisual();
    });
    row.querySelector('.fb-remove').addEventListener('click', function () {
      row.remove();
      syncJsonFromVisual();
    });
    row.querySelectorAll('input').forEach(function (input) {
      input.addEventListener('keyup', syncJsonFromVisual);
      input.addEventListener('change', syncJsonFromVisual);
    });
    row.querySelectorAll('select').forEach(function (select) {
      select.addEventListener('change', syncJsonFromVisual);
    });

    toggleOptions();
    return row;
  }

  function normalizeSchema(raw) {
    if (!Array.isArray(raw)) {
      return [];
    }

    const allowedTypes = ['text', 'textarea', 'number', 'email', 'date', 'phone', 'linear_scale', 'select', 'radio', 'checkbox', 'welcome', 'agreement', 'section', 'form', 'thank_you'];
    const out = [];

    raw.forEach(function (field, index) {
      if (!field || typeof field !== 'object') {
        return;
      }
      let name = String(field.name || '').trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
      if (!name) {
        name = 'field_' + (index + 1);
      }
      const type = allowedTypes.includes(String(field.type || 'text')) ? String(field.type || 'text') : 'text';
      const label = String(field.label || '').trim() || name;
      const normalized = {
        name: name,
        label: label,
        type: type,
        required: !!field.required,
      };
      if (['welcome', 'agreement', 'section', 'form', 'thank_you'].includes(type)) {
        const helpText = String(field.help_text || '').trim();
        if (helpText !== '') {
          normalized.help_text = helpText;
        }
        const imagePath = String(field.image_path || '').trim();
        if (imagePath !== '' && ['welcome', 'section', 'form', 'thank_you'].includes(type)) {
          normalized.image_path = imagePath;
        }
      }
      if (['text', 'textarea'].includes(type)) {
        const allowedRules = ['none', 'arabic_only', 'english_only', 'turkish_latin_only', 'english_or_arabic', 'latin_arabic'];
        let textRule = String(field.text_rule || '').trim();
        if (textRule === 'latin_arabic') {
          textRule = 'english_or_arabic';
        }
        if (allowedRules.includes(textRule)) {
          normalized.text_rule = textRule;
        }
      }
      if (type === 'phone') {
        normalized.default_country_code = '+90';
        normalized.allow_country_change = true;
        normalized.validation_mode = 'country_strict';
      }
      if (type === 'agreement') {
        const agreementMode = String(field.agreement_mode || (field.agreement_pdf_path ? 'pdf' : 'text')).trim();
        normalized.agreement_mode = agreementMode === 'pdf' ? 'pdf' : 'text';
        const agreementPdfPath = String(field.agreement_pdf_path || '').trim();
        if (agreementPdfPath !== '') {
          normalized.agreement_pdf_path = agreementPdfPath;
        }
      }
      if (['select', 'radio', 'checkbox', 'linear_scale'].includes(type)) {
        const options = Array.isArray(field.options)
          ? field.options.map(function (opt) { return String(opt || '').trim(); }).filter(Boolean)
          : [];
        if (options.length) {
          normalized.options = options;
        }
      }
      out.push(normalized);
    });

    return out;
  }

  function getVisualSchema() {
    const rows = fieldsContainer.querySelectorAll('.field-row');
    const schema = [];
    rows.forEach(function (row, index) {
      const type = row.querySelector('.fb-type').value;
      const field = {
        name: row.querySelector('.fb-name').value || ('field_' + (index + 1)),
        label: row.querySelector('.fb-label').value || row.querySelector('.fb-name').value || ('Field ' + (index + 1)),
        type: type,
        required: row.querySelector('.fb-required').checked,
      };
      const textRule = row.querySelector('.fb-text-rule').value;
      if (['text', 'textarea'].includes(type) && textRule) {
        field.text_rule = textRule;
      }
      const helpText = row.querySelector('.fb-help-text').value.trim();
      if (helpText && ['welcome', 'agreement', 'section', 'form', 'thank_you'].includes(type)) {
        field.help_text = helpText;
      }
      const imagePath = row.querySelector('.fb-image-path').value.trim();
      if (imagePath && ['welcome', 'section', 'form', 'thank_you'].includes(type)) {
        field.image_path = imagePath;
      }
      if (type === 'phone') {
        field.default_country_code = '+90';
        field.allow_country_change = true;
        field.validation_mode = 'country_strict';
      }
      if (type === 'agreement') {
        field.agreement_mode = row.querySelector('.fb-agreement-mode').value === 'pdf' ? 'pdf' : 'text';
        const agreementPdfPath = row.querySelector('.fb-agreement-pdf').value.trim();
        if (agreementPdfPath) {
          field.agreement_pdf_path = agreementPdfPath;
        }
      }
      if (['select', 'radio', 'checkbox', 'linear_scale'].includes(type)) {
        field.options = parseOptions(row.querySelector('.fb-options').value);
      }
      schema.push(field);
    });
    return normalizeSchema(schema);
  }

  function syncJsonFromVisual() {
    const schema = getVisualSchema();
    schemaTextarea.value = JSON.stringify(schema, null, 2);
  }

  function loadVisualFromSchema(schema) {
    fieldsContainer.innerHTML = '';
    const normalized = normalizeSchema(schema);
    if (!normalized.length) {
      fieldsContainer.appendChild(createFieldRow({ name: 'welcome_intro', label: 'Welcome', type: 'welcome', help_text: '<p>Welcome to the form.</p>' }));
      fieldsContainer.appendChild(createFieldRow({ name: 'form_main', label: 'Form', type: 'form', help_text: '<p>Please answer the following questions.</p>' }));
      fieldsContainer.appendChild(createFieldRow({ name: 'full_name', label: 'Full Name', type: 'text', required: true }));
      fieldsContainer.appendChild(createFieldRow({ name: 'agreement_terms', label: 'Agreement', type: 'agreement', required: true, help_text: '<p>I agree to the terms.</p>' }));
      fieldsContainer.appendChild(createFieldRow({ name: 'thank_you_note', label: 'Thank You', type: 'thank_you', help_text: '<p>Thank you for submitting the form.</p>' }));
      syncJsonFromVisual();
      renderPreview(getVisualSchema());
      return;
    }
    normalized.forEach(function (field) {
      fieldsContainer.appendChild(createFieldRow(field));
    });
    schemaTextarea.value = JSON.stringify(normalized, null, 2);
    renderPreview(getVisualSchema());
  }

  addBtn.addEventListener('click', function () {
    fieldsContainer.appendChild(createFieldRow({ type: 'text', required: false }));
    syncJsonFromVisual();
  });

  syncVisualBtn.addEventListener('click', function () {
    try {
      const parsed = JSON.parse(schemaTextarea.value || '[]');
      loadVisualFromSchema(parsed);
    } catch (error) {
      window.alert('Invalid JSON. Fix schema JSON before reloading visual editor.');
    }
  });

  syncJsonBtn.addEventListener('click', syncJsonFromVisual);
  if (syncVisualInlineBtn) {
    syncVisualInlineBtn.addEventListener('click', function () {
      try {
        const parsed = JSON.parse(schemaTextarea.value || '[]');
        loadVisualFromSchema(parsed);
      } catch (error) {
        window.alert('Invalid JSON. Fix schema JSON before reloading visual editor.');
      }
    });
  }

  function renderPreview(schema) {
    if (!previewPanel) {
      return;
    }

    if (!Array.isArray(schema) || schema.length === 0) {
      previewPanel.innerHTML = '<p class="muted">Add blocks and fields to see the preview.</p>';
      return;
    }

    const html = [];
    html.push('<div class="card" style="margin-bottom:10px;"><strong>Preview Mode</strong><br><span class="muted">' + escapeHtml(builderType) + '</span></div>');
    schema.forEach(function (field) {
      const type = String(field.type || 'text');
      const label = String(field.label || 'Untitled');
      const help = String(field.help_text || '');
      html.push('<div class="card" style="margin-bottom:8px;">');
      html.push('<div style="display:flex; justify-content:space-between; gap:8px; align-items:center;"><strong>' + escapeHtml(label) + '</strong><span class="badge">' + escapeHtml(type) + '</span></div>');
      if (help) {
        html.push('<div style="margin-top:6px;">' + help + '</div>');
      }
      if (field.image_path) {
        html.push('<p class="muted">Image: ' + escapeHtml(field.image_path) + '</p>');
      }
      if (type === 'agreement') {
        html.push('<label style="display:block; margin-top:8px;"><input type="checkbox" disabled> I agree</label>');
        if (field.agreement_mode === 'pdf' && field.agreement_pdf_path) {
          html.push('<p class="muted">Agreement PDF: ' + escapeHtml(field.agreement_pdf_path) + '</p>');
        }
      }
      if (['text', 'textarea', 'number', 'email', 'date', 'phone', 'select', 'radio', 'checkbox'].includes(type)) {
        html.push('<p class="muted">Interactive field preview</p>');
      }
      html.push('</div>');
    });
    previewPanel.innerHTML = html.join('');
  }

  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      syncJsonFromVisual();
      renderPreview(getVisualSchema());
      if (previewPanel) {
        previewPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  }

  saveDraftBtn.addEventListener('click', function () {
    saveActionInput.value = 'save_draft';
  });
  publishBtn.addEventListener('click', function () {
    saveActionInput.value = 'publish';
  });
  if (topSaveDraftBtn) {
    topSaveDraftBtn.addEventListener('click', function () {
      saveActionInput.value = 'save_draft';
    });
  }
  if (topPublishBtn) {
    topPublishBtn.addEventListener('click', function () {
      saveActionInput.value = 'publish';
    });
  }

  saveForm.addEventListener('submit', function () {
    syncJsonFromVisual();
    renderPreview(getVisualSchema());
  });

  try {
    const parsed = JSON.parse(schemaTextarea.value || '[]');
    loadVisualFromSchema(parsed);
  } catch (error) {
    loadVisualFromSchema([]);
  }
  renderPreview(getVisualSchema());
})();
</script>
