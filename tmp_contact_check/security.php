<?php
declare(strict_types=1);

function easysched_load_local_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $candidatePaths = [
        __DIR__ . DIRECTORY_SEPARATOR . '.env',
        __DIR__ . DIRECTORY_SEPARATOR . '.env.local',
        __DIR__ . DIRECTORY_SEPARATOR . '.env.txt',
    ];

    foreach ($candidatePaths as $path) {
        if (!is_file($path)) {
            continue;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
                $value = trim($value, "\"'");
            }

            if ($key === '') {
                continue;
            }

            $runtimeValue = getenv($key);
            if ($runtimeValue === false || $runtimeValue === '') {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        break;
    }
}

function easysched_env_string(string $key): string
{
    easysched_load_local_env();

    foreach ([
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        getenv($key),
    ] as $candidate) {
        if (is_string($candidate)) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return '';
}

function easysched_contact_email(): string
{
    $email = easysched_env_string('EASYSCHED_EMAIL_FROM');
    if ($email === '') {
        $email = easysched_env_string('EASYSCHED_CONTACT_EMAIL');
    }
    if ($email === '') {
        $email = easysched_env_string('CONTACT_EMAIL');
    }
    if ($email === '') {
        $email = easysched_env_string('MAIL_FROM');
    }
    if ($email === '') {
        $email = easysched_env_string('EASYSCHED_SMTP_USERNAME');
    }
    if ($email === '') {
        $email = 'sinaicapstone@gmail.com';
    }

    return $email;
}

function easysched_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return false;
}

function easysched_start_session(): void
{
    $secure = easysched_is_https();
    $sessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0770, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('EASYSCHEDSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function easysched_send_security_headers(bool $noStore = true): void
{
    $secure = easysched_is_https();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    if ($noStore) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    $upgrade = $secure ? "; upgrade-insecure-requests" : '';
    header("Content-Security-Policy: default-src 'self'; connect-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'{$upgrade}");
}
