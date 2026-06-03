<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/lib/db.php';

$options = getopt('', ['apply', 'email::']);
$apply = array_key_exists('apply', $options);
$emailFilter = isset($options['email']) ? strtolower(trim((string)$options['email'])) : '';

try {
    $pdo = db($config);
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!identity_table_exists($pdo)) {
    fwrite(STDERR, "user_identities table not found. Run sql/migrations/20260603_add_user_identities.sql first.\n");
    exit(1);
}

if (!$apply) {
    echo "DRY RUN mode. No database changes will be applied. Use --apply to execute.\n";
}

$groups = find_duplicate_email_groups($pdo, $emailFilter);
if ($groups === []) {
    echo "No duplicate emails found.\n";
    exit(0);
}

$totalMergedUsers = 0;
$totalGroups = 0;

foreach ($groups as $group) {
    $normalizedEmail = (string)$group['normalized_email'];
    $users = find_users_by_normalized_email($pdo, $normalizedEmail);
    if (count($users) < 2) {
        continue;
    }

    $canonical = pick_canonical_user($users, $config);
    $duplicates = array_values(array_filter(
        $users,
        static fn(array $row): bool => (int)$row['id'] !== (int)$canonical['id']
    ));

    echo "\nEmail {$normalizedEmail}: keep user #{$canonical['id']} ({$canonical['tenant_code']}, {$canonical['role']}) and merge " . count($duplicates) . " duplicate(s).\n";

    if (!$apply) {
        foreach ($duplicates as $dup) {
            echo "  - would merge user #{$dup['id']} ({$dup['tenant_code']}, {$dup['role']})\n";
        }
        $totalGroups++;
        $totalMergedUsers += count($duplicates);
        continue;
    }

    $pdo->beginTransaction();
    try {
        foreach ($duplicates as $dup) {
            $duplicateId = (int)$dup['id'];
            $canonicalId = (int)$canonical['id'];

            link_legacy_identity_if_possible($pdo, $duplicateId, $canonicalId, $dup);
            merge_profile_row($pdo, $duplicateId, $canonicalId);
            reassign_fk($pdo, 'applications', 'student_id', $duplicateId, $canonicalId);
            reassign_fk($pdo, 'scholarships', 'created_by', $duplicateId, $canonicalId);
            reassign_fk($pdo, 'audit_logs', 'actor_user_id', $duplicateId, $canonicalId);
            reassign_fk($pdo, 'blacklist_entries', 'created_by', $duplicateId, $canonicalId);
            reassign_fk($pdo, 'otp_codes', 'user_id', $duplicateId, $canonicalId);
            reassign_fk($pdo, 'user_identities', 'user_id', $duplicateId, $canonicalId);

            $deleteProfile = $pdo->prepare('DELETE FROM user_profiles WHERE user_id = ?');
            $deleteProfile->execute([$duplicateId]);

            $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $deleteUser->execute([$duplicateId]);

            echo "  - merged user #{$duplicateId} into #{$canonicalId}\n";
        }

        $pdo->commit();
        $totalGroups++;
        $totalMergedUsers += count($duplicates);
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Failed merging {$normalizedEmail}: {$e->getMessage()}\n");
        exit(1);
    }
}

if (!$apply) {
    echo "\nSummary (dry run): groups={$totalGroups}, duplicate_users={$totalMergedUsers}.\n";
    echo "Next: php scripts/merge_duplicate_users.php --apply\n";
    exit(0);
}

echo "\nSummary: merged groups={$totalGroups}, merged users={$totalMergedUsers}.\n";
$remaining = find_duplicate_email_groups($pdo, $emailFilter);
echo 'Remaining duplicate-email groups: ' . count($remaining) . "\n";

function identity_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_identities'");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function find_duplicate_email_groups(PDO $pdo, string $emailFilter): array
{
    $sql = 'SELECT LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS total
            FROM users
            GROUP BY LOWER(TRIM(email))
            HAVING COUNT(*) > 1';
    $params = [];

    if ($emailFilter !== '') {
        $sql = 'SELECT normalized_email, total FROM (
                  SELECT LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS total
                  FROM users
                  GROUP BY LOWER(TRIM(email))
                  HAVING COUNT(*) > 1
                ) d
                WHERE normalized_email = ?';
        $params[] = $emailFilter;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function find_users_by_normalized_email(PDO $pdo, string $normalizedEmail): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.tenant_id, u.full_name, u.email, u.role, u.is_active, u.created_at, t.code AS tenant_code
         FROM users u
         INNER JOIN tenants t ON t.id = u.tenant_id
         WHERE LOWER(TRIM(u.email)) = ?
         ORDER BY u.id ASC'
    );
    $stmt->execute([$normalizedEmail]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pick_canonical_user(array $users, array $config): array
{
    $msTenant = trim((string)($config['microsoft']['default_tenant_code'] ?? ''));
    $googleTenant = trim((string)($config['google']['default_tenant_code'] ?? ''));
    $providerTenants = array_filter([$msTenant, $googleTenant]);

    usort($users, static function (array $a, array $b) use ($providerTenants): int {
        $aProviderTenant = in_array((string)$a['tenant_code'], $providerTenants, true) ? 1 : 0;
        $bProviderTenant = in_array((string)$b['tenant_code'], $providerTenants, true) ? 1 : 0;
        if ($aProviderTenant !== $bProviderTenant) {
            return $aProviderTenant <=> $bProviderTenant;
        }

        $aActive = (int)$a['is_active'];
        $bActive = (int)$b['is_active'];
        if ($aActive !== $bActive) {
            return $bActive <=> $aActive;
        }

        return ((int)$a['id']) <=> ((int)$b['id']);
    });

    return $users[0];
}

function reassign_fk(PDO $pdo, string $table, string $column, int $fromUserId, int $toUserId): void
{
    $stmt = $pdo->prepare("UPDATE {$table} SET {$column} = ? WHERE {$column} = ?");
    $stmt->execute([$toUserId, $fromUserId]);
}

function merge_profile_row(PDO $pdo, int $duplicateId, int $canonicalId): void
{
    $profileColumns = [
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'nationality', 'phone_country_code',
        'phone_number', 'whatsapp_number', 'secondary_email', 'address_country', 'address_city',
        'address_zip_code', 'address_text', 'auth_provider_id',
    ];

    $load = $pdo->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
    $load->execute([$canonicalId]);
    $canonical = $load->fetch(PDO::FETCH_ASSOC) ?: [];

    $load->execute([$duplicateId]);
    $duplicate = $load->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($duplicate === []) {
        return;
    }

    if ($canonical === []) {
        $insert = $pdo->prepare(
            'INSERT INTO user_profiles (
               user_id, first_name, middle_name, last_name, date_of_birth, nationality,
               phone_country_code, phone_number, whatsapp_number, secondary_email,
               address_country, address_city, address_zip_code, address_text, auth_provider_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $canonicalId,
            $duplicate['first_name'] ?? null,
            $duplicate['middle_name'] ?? null,
            $duplicate['last_name'] ?? null,
            $duplicate['date_of_birth'] ?? null,
            $duplicate['nationality'] ?? null,
            $duplicate['phone_country_code'] ?? null,
            $duplicate['phone_number'] ?? null,
            $duplicate['whatsapp_number'] ?? null,
            $duplicate['secondary_email'] ?? null,
            $duplicate['address_country'] ?? null,
            $duplicate['address_city'] ?? null,
            $duplicate['address_zip_code'] ?? null,
            $duplicate['address_text'] ?? null,
            $duplicate['auth_provider_id'] ?? null,
        ]);
        return;
    }

    $updates = [];
    $params = [];
    foreach ($profileColumns as $column) {
        $canonicalVal = normalize_nullable((string)($canonical[$column] ?? ''));
        $duplicateVal = normalize_nullable((string)($duplicate[$column] ?? ''));
        if ($canonicalVal === null && $duplicateVal !== null) {
            $updates[] = $column . ' = ?';
            $params[] = $duplicateVal;
        }
    }

    if ($updates !== []) {
        $params[] = $canonicalId;
        $stmt = $pdo->prepare('UPDATE user_profiles SET ' . implode(', ', $updates) . ' WHERE user_id = ?');
        $stmt->execute($params);
    }
}

function normalize_nullable(string $value): ?string
{
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

function link_legacy_identity_if_possible(PDO $pdo, int $duplicateId, int $canonicalId, array $duplicateUser): void
{
    $loadProfile = $pdo->prepare('SELECT auth_provider_id FROM user_profiles WHERE user_id = ? LIMIT 1');
    $loadProfile->execute([$duplicateId]);
    $profile = $loadProfile->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$profile) {
        return;
    }

    $providerUserId = trim((string)($profile['auth_provider_id'] ?? ''));
    if ($providerUserId === '') {
        return;
    }

    $provider = '';
    $tenantCode = (string)($duplicateUser['tenant_code'] ?? '');
    if (str_starts_with($tenantCode, 'TKIFGO')) {
        $provider = 'google';
    } elseif (str_starts_with($tenantCode, 'TKIFMS')) {
        $provider = 'microsoft';
    }

    if ($provider === '') {
        return;
    }

    $existing = $pdo->prepare('SELECT user_id FROM user_identities WHERE provider = ? AND provider_user_id = ? LIMIT 1');
    $existing->execute([$provider, $providerUserId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row && (int)$row['user_id'] !== $canonicalId) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO user_identities (user_id, provider, provider_user_id, provider_email)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           provider_user_id = VALUES(provider_user_id),
           provider_email = VALUES(provider_email),
           updated_at = CURRENT_TIMESTAMP'
    );
    $insert->execute([$canonicalId, $provider, $providerUserId, strtolower(trim((string)$duplicateUser['email']))]);
}
