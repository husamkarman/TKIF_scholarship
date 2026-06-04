<?php

declare(strict_types=1);

function phone_country_codes_ready(PDO $pdo): bool
{
  static $ready = null;
  if (is_bool($ready)) {
    return $ready;
  }

  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'phone_country_codes'");
    $ready = $stmt !== false && (bool)$stmt->fetchColumn();
  } catch (Throwable) {
    $ready = false;
  }

  return $ready;
}

function phone_country_code_rows(PDO $pdo, bool $activeOnly = true): array
{
  if (!phone_country_codes_ready($pdo)) {
    return [];
  }

  $sql = 'SELECT id, iso2, country_name, dial_code, min_length, max_length, regex_pattern, is_default, is_active, sort_order
          FROM phone_country_codes';
  if ($activeOnly) {
    $sql .= ' WHERE is_active = 1';
  }
  $sql .= ' ORDER BY is_default DESC, sort_order ASC, country_name ASC';

  $stmt = $pdo->query($sql);
  return $stmt ? $stmt->fetchAll() : [];
}

function phone_default_country_code(PDO $pdo, string $fallback = '+90'): string
{
  if (!phone_country_codes_ready($pdo)) {
    return $fallback;
  }

  $stmt = $pdo->query('SELECT dial_code FROM phone_country_codes WHERE is_active = 1 AND is_default = 1 ORDER BY id ASC LIMIT 1');
  $dial = $stmt ? trim((string)$stmt->fetchColumn()) : '';
  if ($dial !== '') {
    return $dial;
  }

  return $fallback;
}

function phone_find_country_by_dial(PDO $pdo, string $dialCode): ?array
{
  if (!phone_country_codes_ready($pdo)) {
    return null;
  }

  $cleanDial = trim($dialCode);
  if ($cleanDial === '') {
    return null;
  }

  $stmt = $pdo->prepare('SELECT id, iso2, country_name, dial_code, min_length, max_length, regex_pattern, is_default, is_active, sort_order
                         FROM phone_country_codes
                         WHERE dial_code = ? AND is_active = 1
                         LIMIT 1');
  $stmt->execute([$cleanDial]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function phone_normalize_local_number(string $number): string
{
  return preg_replace('/\D+/', '', trim($number)) ?? '';
}

function phone_validate_input(PDO $pdo, string $dialCode, string $number, bool $required = true): array
{
  $dial = trim($dialCode);
  $normalizedNumber = phone_normalize_local_number($number);

  if ($dial === '' && $normalizedNumber === '') {
    return $required
      ? ['ok' => false, 'error' => 'Phone country code and phone number are required.', 'country' => null, 'number' => '']
      : ['ok' => true, 'error' => '', 'country' => null, 'number' => ''];
  }

  if ($dial === '' || $normalizedNumber === '') {
    return ['ok' => false, 'error' => 'Phone country code and phone number must both be provided.', 'country' => null, 'number' => $normalizedNumber];
  }

  $country = phone_find_country_by_dial($pdo, $dial);
  if (!$country) {
    return ['ok' => false, 'error' => 'Selected phone country code is not allowed.', 'country' => null, 'number' => $normalizedNumber];
  }

  $minLen = max(4, (int)($country['min_length'] ?? 4));
  $maxLen = max($minLen, (int)($country['max_length'] ?? 15));

  $regex = trim((string)($country['regex_pattern'] ?? ''));
  if ($regex === '') {
    $regex = '/^[0-9]{' . $minLen . ',' . $maxLen . '}$/';
  }

  $patternOk = false;
  try {
    $patternOk = (bool)preg_match($regex, $normalizedNumber);
  } catch (Throwable) {
    $patternOk = false;
  }

  if (!$patternOk) {
    return [
      'ok' => false,
      'error' => 'Phone number format is invalid for selected country code.',
      'country' => $country,
      'number' => $normalizedNumber,
    ];
  }

  return [
    'ok' => true,
    'error' => '',
    'country' => $country,
    'number' => $normalizedNumber,
    'combined' => $dial . $normalizedNumber,
  ];
}
