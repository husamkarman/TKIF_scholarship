<?php
$edit = is_array($phoneCodeEdit ?? null) ? $phoneCodeEdit : [
  'id' => 0,
  'iso2' => '',
  'country_name' => '',
  'dial_code' => '',
  'min_length' => 6,
  'max_length' => 12,
  'regex_pattern' => '',
  'is_default' => 0,
  'is_active' => 1,
  'sort_order' => 100,
];
?>

<h2>Phone Country Code Management</h2>
<p>IT can manage country codes, strict validation rules, default code, and active status.</p>

<div class="card" style="margin-bottom: 14px;">
  <h3><?= (int)$edit['id'] > 0 ? 'Edit Phone Code' : 'Add Phone Code' ?></h3>
  <form method="post" action="<?= h(app_route('phone_codes_save')) ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="phone_code_id" value="<?= (int)$edit['id'] ?>">

    <div class="grid">
      <div>
        <label>ISO2</label>
        <input name="iso2" maxlength="2" value="<?= h((string)$edit['iso2']) ?>" required>
      </div>
      <div>
        <label>Country Name</label>
        <input name="country_name" value="<?= h((string)$edit['country_name']) ?>" required>
      </div>
      <div>
        <label>Dial Code</label>
        <input name="dial_code" placeholder="+90" value="<?= h((string)$edit['dial_code']) ?>" required>
      </div>
      <div>
        <label>Min Length</label>
        <input name="min_length" type="number" min="4" max="15" value="<?= (int)$edit['min_length'] ?>" required>
      </div>
      <div>
        <label>Max Length</label>
        <input name="max_length" type="number" min="4" max="15" value="<?= (int)$edit['max_length'] ?>" required>
      </div>
      <div>
        <label>Sort Order</label>
        <input name="sort_order" type="number" min="0" value="<?= (int)$edit['sort_order'] ?>">
      </div>
    </div>

    <label>Regex Pattern (strict national number check)</label>
    <input name="regex_pattern" value="<?= h((string)$edit['regex_pattern']) ?>" placeholder="/^[0-9]{10}$/">

    <label><input type="checkbox" name="is_default" <?= ((int)$edit['is_default'] === 1) ? 'checked' : '' ?>> Set as default country code</label>
    <label><input type="checkbox" name="is_active" <?= ((int)$edit['is_active'] === 1) ? 'checked' : '' ?>> Active</label>

    <br>
    <button class="btn primary" type="submit">Save Phone Code</button>
    <a class="btn" href="<?= h(app_route('phone_codes')) ?>">Reset</a>
  </form>
</div>

<div class="card">
  <h3>Available Phone Codes</h3>
  <table class="table">
    <thead><tr><th>ID</th><th>ISO2</th><th>Country</th><th>Dial</th><th>Length</th><th>Default</th><th>Active</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($phoneCodeRows as $row): ?>
      <tr>
        <td><?= (int)$row['id'] ?></td>
        <td><?= h((string)$row['iso2']) ?></td>
        <td><?= h((string)$row['country_name']) ?></td>
        <td><?= h((string)$row['dial_code']) ?></td>
        <td><?= (int)$row['min_length'] ?>-<?= (int)$row['max_length'] ?></td>
        <td><?= ((int)$row['is_default'] === 1) ? 'Yes' : 'No' ?></td>
        <td><?= ((int)$row['is_active'] === 1) ? 'Yes' : 'No' ?></td>
        <td><a class="btn" href="<?= h(app_route('phone_codes')) ?>&edit_phone_code_id=<?= (int)$row['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
