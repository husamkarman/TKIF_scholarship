<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $vars = null;

    $runtime = getenv($key);
    if ($runtime !== false && $runtime !== '') {
        return $runtime;
    }

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

function resolve_oauth_redirect_uri(string $providerPrefix, string $appEnv): string
{
    $legacy = trim((string)env($providerPrefix . '_REDIRECT_URI', ''));
    $local = trim((string)env($providerPrefix . '_REDIRECT_URI_LOCAL', ''));
    $prod = trim((string)env($providerPrefix . '_REDIRECT_URI_PROD', ''));

    if ($appEnv === 'production') {
        if ($prod !== '') {
            return $prod;
        }
        if ($legacy !== '') {
            return $legacy;
        }
        return $local;
    }

    if ($local !== '') {
        return $local;
    }
    if ($legacy !== '') {
        return $legacy;
    }
    return $prod;
}

$appEnv = (string)env('APP_ENV', 'local');

return [
    'app_name' => env('APP_NAME', 'TKIF Scholarship MVP'),
    'app_env' => $appEnv,
    'session_name' => env('SESSION_NAME', 'tkif_session'),
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'tkif_scholarship'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],
    'notifications' => [
        'internal_endpoint' => env('INTERNAL_NOTIFICATION_ENDPOINT', 'http://localhost:8080/?page=notification_inbox_receive'),
        'internal_secret' => env('INTERNAL_NOTIFICATION_SECRET', ''),
        'hmac_tolerance_seconds' => (int)env('INTERNAL_NOTIFICATION_HMAC_TOLERANCE_SECONDS', '300'),
        'worker_token' => env('INTERNAL_NOTIFICATION_WORKER_TOKEN', ''),
        'outbound_enabled' => env('OUTBOUND_PUSH_ENABLED', 'false') === 'true',
        'outbound_endpoint' => env('OUTBOUND_PUSH_ENDPOINT', ''),
        'outbound_secret' => env('OUTBOUND_PUSH_SECRET', ''),
        'outbound_timeout_seconds' => (int)env('OUTBOUND_PUSH_TIMEOUT_SECONDS', '15'),
        'outbound_route' => env('OUTBOUND_PUSH_ROUTE', 'n8n_global'),
    ],
    'registration' => [
        'enabled' => env('REGISTRATION_ENABLED', 'true') === 'true',
        'default_role' => env('REGISTRATION_DEFAULT_ROLE', 'student'),
        'default_tenant_code' => env('REGISTRATION_DEFAULT_TENANT_CODE', ''),
    ],
    'email_verification' => [
        'enabled' => env('EMAIL_VERIFICATION_ENABLED', 'true') === 'true',
        'method' => env('EMAIL_VERIFICATION_METHOD', 'code'),
        'code_length' => (int)env('EMAIL_VERIFICATION_CODE_LENGTH', '6'),
        'ttl_minutes' => (int)env('EMAIL_VERIFICATION_TTL_MINUTES', '3'),
        'resend_cooldown_seconds' => (int)env('EMAIL_VERIFICATION_RESEND_COOLDOWN_SECONDS', '30'),
        'require_for_login' => env('EMAIL_VERIFICATION_REQUIRE_FOR_LOGIN', 'true') === 'true',
    ],
    'security' => [
        'rate_limits' => [
            'login' => [
                'max' => (int)env('RATE_LIMIT_LOGIN_MAX', '10'),
                'window_seconds' => (int)env('RATE_LIMIT_LOGIN_WINDOW_SECONDS', '300'),
            ],
            'register' => [
                'max' => (int)env('RATE_LIMIT_REGISTER_MAX', '5'),
                'window_seconds' => (int)env('RATE_LIMIT_REGISTER_WINDOW_SECONDS', '900'),
            ],
            'verify_code' => [
                'max' => (int)env('RATE_LIMIT_VERIFY_CODE_MAX', '10'),
                'window_seconds' => (int)env('RATE_LIMIT_VERIFY_CODE_WINDOW_SECONDS', '300'),
            ],
            'verify_resend' => [
                'max' => (int)env('RATE_LIMIT_VERIFY_RESEND_MAX', '5'),
                'window_seconds' => (int)env('RATE_LIMIT_VERIFY_RESEND_WINDOW_SECONDS', '600'),
            ],
            'otp_request' => [
                'max' => (int)env('RATE_LIMIT_OTP_REQUEST_MAX', '5'),
                'window_seconds' => (int)env('RATE_LIMIT_OTP_REQUEST_WINDOW_SECONDS', '900'),
            ],
            'otp_verify' => [
                'max' => (int)env('RATE_LIMIT_OTP_VERIFY_MAX', '10'),
                'window_seconds' => (int)env('RATE_LIMIT_OTP_VERIFY_WINDOW_SECONDS', '300'),
            ],
            'reset_request' => [
                'max' => (int)env('RATE_LIMIT_RESET_REQUEST_MAX', '5'),
                'window_seconds' => (int)env('RATE_LIMIT_RESET_REQUEST_WINDOW_SECONDS', '900'),
            ],
            'reset_submit' => [
                'max' => (int)env('RATE_LIMIT_RESET_SUBMIT_MAX', '10'),
                'window_seconds' => (int)env('RATE_LIMIT_RESET_SUBMIT_WINDOW_SECONDS', '900'),
            ],
        ],
        'login_lockout' => [
            'enabled' => env('LOGIN_LOCKOUT_ENABLED', 'true') === 'true',
            'failure_threshold' => (int)env('LOGIN_LOCKOUT_FAILURE_THRESHOLD', '8'),
            'window_seconds' => (int)env('LOGIN_LOCKOUT_WINDOW_SECONDS', '900'),
        ],
        'login_attempts' => [
            'ip_hash_pepper' => env('LOGIN_ATTEMPTS_IP_HASH_PEPPER', ''),
        ],
        'retention' => [
            'enabled' => env('SECURITY_RETENTION_ENABLED', 'true') === 'true',
            'login_attempts_days' => (int)env('SECURITY_RETENTION_LOGIN_ATTEMPTS_DAYS', '30'),
            'password_resets_days' => (int)env('SECURITY_RETENTION_PASSWORD_RESETS_DAYS', '30'),
            'worker_token' => env('SECURITY_RETENTION_WORKER_TOKEN', ''),
        ],
    ],
    'captcha' => [
        'enabled' => env('CAPTCHA_ENABLED', 'false') === 'true',
        'provider' => env('CAPTCHA_PROVIDER', 'turnstile'),
        'site_key' => env('CAPTCHA_SITE_KEY', ''),
        'secret_key' => env('CAPTCHA_SECRET_KEY', ''),
        'verify_url' => env('CAPTCHA_VERIFY_URL', ''),
    ],
    'password_reset' => [
        'enabled' => env('PASSWORD_RESET_ENABLED', 'true') === 'true',
        'ttl_minutes' => (int)env('PASSWORD_RESET_TTL_MINUTES', '30'),
    ],
    'smtp' => [
        'enabled' => env('SMTP_ENABLED', 'false') === 'true',
        'host' => env('SMTP_HOST', ''),
        'port' => env('SMTP_PORT', ''),
        'encryption' => env('SMTP_ENCRYPTION', 'ssl'),
        'auth' => env('SMTP_AUTH', 'true') === 'true',
        'username' => env('SMTP_USERNAME', ''),
        'password' => env('SMTP_PASSWORD', ''),
        'from_email' => env('SMTP_FROM_EMAIL', ''),
        'from_name' => env('SMTP_FROM_NAME', env('APP_NAME', 'TKIF Scholarship MVP')),
        'timeout' => env('SMTP_TIMEOUT', '10'),
    ],
    'otp' => [
        'enabled' => env('OTP_ENABLED', 'true') === 'true',
        'length' => (int)env('OTP_LENGTH', '6'),
        'ttl_minutes' => (int)env('OTP_TTL_MINUTES', '10'),
    ],
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID', ''),
        'tenant_id' => env('MICROSOFT_TENANT_ID', ''),
        'redirect_uri' => resolve_oauth_redirect_uri('MICROSOFT', $appEnv),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET', ''),
        'auto_provision' => env('MICROSOFT_AUTO_PROVISION', 'true') === 'true',
        'allowed_domain' => env('MICROSOFT_ALLOWED_DOMAIN', ''),
        'default_role' => env('MICROSOFT_DEFAULT_ROLE', 'student'),
        'default_tenant_code' => env('MICROSOFT_DEFAULT_TENANT_CODE', 'TKIF001'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => resolve_oauth_redirect_uri('GOOGLE', $appEnv),
        'auto_provision' => env('GOOGLE_AUTO_PROVISION', 'true') === 'true',
        'allowed_domain' => env('GOOGLE_ALLOWED_DOMAIN', ''),
        'default_role' => env('GOOGLE_DEFAULT_ROLE', 'student'),
        'default_tenant_code' => env('GOOGLE_DEFAULT_TENANT_CODE', 'TKIF001'),
    ],
];
