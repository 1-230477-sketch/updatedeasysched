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
    $email = easysched_env_string('EASYSCHED_CONTACT_EMAIL');
    if ($email === '') {
        $email = easysched_env_string('CONTACT_EMAIL');
    }
    if ($email === '') {
        $email = easysched_env_string('MAIL_FROM');
    }
    if ($email === '') {
        $email = easysched_env_string('EASYSCHED_EMAIL_FROM');
    }
    if ($email === '') {
        $email = easysched_env_string('EASYSCHED_SMTP_USERNAME');
    }
    if ($email === '') {
        $email = 'easyscheduler1@gmail.com';
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
    if (filter_var(easysched_env_string('EASYSCHED_TRUST_PROXY'), FILTER_VALIDATE_BOOLEAN)) {
        $forwardedProtocols = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return strtolower(trim($forwardedProtocols[0] ?? '')) === 'https';
    }
    return false;
}

function easysched_public_https_url(): string
{
    $publicUrl = trim(easysched_env_string('EASYSCHED_PUBLIC_URL'));
    if ($publicUrl === '') {
        return '';
    }
    $parts = parse_url($publicUrl);
    if (
        !is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        || (isset($parts['port']) && (int) $parts['port'] !== 443)
    ) {
        throw new RuntimeException('EASYSCHED_PUBLIC_URL must be an HTTPS origin without a path or nonstandard port.');
    }
    $host = (string) $parts['host'];
    if (!preg_match('/\A(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[A-Fa-f0-9:.]+\])\z/', $host)) {
        throw new RuntimeException('EASYSCHED_PUBLIC_URL contains an invalid host.');
    }
    return 'https://' . $host;
}

function easysched_enforce_https(): void
{
    if (!filter_var(easysched_env_string('EASYSCHED_FORCE_HTTPS'), FILTER_VALIDATE_BOOLEAN) || easysched_is_https()) {
        return;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $publicUrl = easysched_public_https_url();
    if (
        ($publicUrl === '' && !preg_match('/\A(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[A-Fa-f0-9:.]+\])(?::[0-9]{1,5})?\z/', $host))
        || !str_starts_with($requestUri, '/')
        || str_starts_with($requestUri, '//')
        || preg_match('/[\r\n]/', $requestUri)
    ) {
        throw new RuntimeException('Cannot safely redirect this request to HTTPS.');
    }

    header('Location: ' . ($publicUrl !== '' ? $publicUrl : 'https://' . $host) . $requestUri, true, 308);
    exit;
}

function easysched_start_session(): void
{
    easysched_enforce_https();
    $secure = easysched_is_https();
    $configuredSessionPath = easysched_env_string('EASYSCHED_SESSION_PATH');
    $sessionPath = $configuredSessionPath !== ''
        ? $configuredSessionPath
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'easysched-sessions-' . substr(hash('sha256', __DIR__), 0, 12);
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0770, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Could not create the private session directory.');
    }
    if (!is_writable($sessionPath)) {
        throw new RuntimeException('The private session directory is not writable.');
    }
    session_save_path($sessionPath);
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
