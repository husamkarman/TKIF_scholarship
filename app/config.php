<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $vars = null;

    if ($vars === null) {
        $vars = [];
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $vars[trim($k)] = trim($v);
                }
            }
        }
    }

    return $vars[$key] ?? getenv($key) ?: $default;
}

return [
    'app_name' => env('APP_NAME', 'TKIF Scholarship MVP'),
    'app_env' => env('APP_ENV', 'local'),
    'session_name' => env('SESSION_NAME', 'tkif_session'),
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'tkif_scholarship'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],
    'n8n' => [
        'base_url' => env('N8N_BASE_URL', 'http://localhost:5678'),
        'api_key' => env('N8N_API_KEY', ''),
        'submit_hook' => env('N8N_WEBHOOK_SUBMIT', '/webhook/scholarship-submit'),
    ],
    'smtp' => [
        'enabled' => env('SMTP_ENABLED', 'false') === 'true',
        'host' => env('SMTP_HOST', ''),
        'port' => env('SMTP_PORT', ''),
        'encryption' => env('SMTP_ENCRYPTION', 'ssl'),
        'username' => env('SMTP_USERNAME', ''),
        'password' => env('SMTP_PASSWORD', ''),
        'from_email' => env('SMTP_FROM_EMAIL', ''),
        'from_name' => env('SMTP_FROM_NAME', env('APP_NAME', 'TKIF Scholarship MVP')),
        'timeout' => env('SMTP_TIMEOUT', '10'),
    ],
];
