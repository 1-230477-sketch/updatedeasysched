<?php
declare(strict_types=1);

// Ensure clean output from the start
ob_start();

// Set up error handling before anything else
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    // Don't prevent normal execution
    return false;
});

set_exception_handler(function($e) {
    error_log('Uncaught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    ob_clean(); // Clear any buffered output
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error.'], JSON_UNESCAPED_SLASHES) ?: '{"ok":false,"error":"Server error"}';
    exit;
});

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] & (E_ERROR | E_PARSE | E_COMPILE_ERROR | E_COMPILE_WARNING))) {
        error_log('Fatal Error [' . $error['type'] . ']: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo '{"ok":false,"error":"Server error"}';
    }
});

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'security.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'captcha_challenge.php';

// New Sinai School and Colleges Sta. Rosa, Inc. schedules run Monday through Friday. Keeping the day domain
// explicit prevents the solver from publishing weekend classes accidentally.
const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];

if (!defined('EASYSCHED_LIBRARY_MODE')) {
    try {
        easysched_start_session();
        easysched_send_security_headers();
        $_SERVER['EASYSCHED_REQUEST_ID'] ??= bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        error_log('Session initialization error: ' . $e->getMessage());
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Session error'], JSON_UNESCAPED_SLASHES) ?: '{"ok":false,"error":"Session error"}';
        exit;
    }
}

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

function respond(array $payload, int $status = 200): never
{
    audit_successful_api_transaction($payload, $status);

    // Clean any buffered output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    
    // Encode to JSON
    $json = null;
    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e1) {
        error_log('JSON encode with flags failed: ' . $e1->getMessage());
        // Try again without throw flag
        $json = @json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    // Ensure we have valid output
    if (!is_string($json) || empty($json)) {
        $json = '{"ok":false,"error":"Encoding error"}';
    }
    
    // Output
    echo $json;
    exit(0);
}

function body(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_starts_with($contentType, 'multipart/form-data')) {
        $input = $_POST;
        if (isset($_FILES['csv_file']) && is_array($_FILES['csv_file'])) {
            $upload = $_FILES['csv_file'];
            if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new ApiError(422, 'The CSV upload failed. Choose the file again and retry.');
            }
            $path = (string) ($upload['tmp_name'] ?? '');
            $csv = $path !== '' ? file_get_contents($path) : false;
            if (!is_string($csv)) {
                throw new ApiError(422, 'The uploaded CSV file could not be read.');
            }
            $input['csv'] = $csv;
        }
        return $input;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new ApiError(400, 'Request body must be valid JSON.');
    }
    return $decoded;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function require_csrf(array $input): void
{
    $given = (string) ($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($given === '' || !hash_equals(csrf_token(), $given)) {
        throw new ApiError(419, 'Your session token is invalid or expired. Refresh and try again.');
    }
}

function current_user(PDO $pdo): ?array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return null;
    }
    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity'] ?? $now);
    if ($lastActivity > 0 && ($now - $lastActivity) > 1800) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    $_SESSION['last_activity'] = $now;
    $stmt = $pdo->prepare('SELECT id, username, display_name, email, role, instructor_id, section_id, active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['active']) {
        return null;
    }
    return $user;
}

function require_auth(PDO $pdo, array $roles = []): array
{
    $user = current_user($pdo);
    if (!$user) {
        throw new ApiError(401, 'Authentication is required.');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        throw new ApiError(403, 'You do not have permission to perform this action.');
    }
    return $user;
}

function audit_safe_details(array $details): array
{
    $blocked = ['password', 'password_hash', 'token', 'csrf', 'session', 'cookie', 'secret', 'api_key', 'code_hash'];
    $clean = [];
    foreach ($details as $key => $value) {
        $name = strtolower((string) $key);
        $isBlocked = false;
        foreach ($blocked as $needle) {
            if (str_contains($name, $needle)) {
                $isBlocked = true;
                break;
            }
        }
        if ($isBlocked) {
            continue;
        }
        if (is_array($value)) {
            $clean[(string) $key] = audit_safe_details($value);
        } elseif (is_scalar($value) || $value === null) {
            $clean[(string) $key] = is_string($value) ? substr($value, 0, 500) : $value;
        }
    }
    return $clean;
}

function audit(PDO $pdo, ?array $user, string $action, ?string $entity = null, ?int $entityId = null, array $details = []): void
{
    $severity = str_contains($action, 'FAILURE') || str_contains($action, 'DENIED') || str_contains($action, 'CONFLICT') ? 'WARNING' : 'INFO';
    if (str_contains($action, 'UNAUTHORIZED') || str_contains($action, 'SECURITY_ALERT')) {
        $severity = 'CRITICAL';
    }
    $outcome = str_contains($action, 'FAILURE') ? 'FAILURE' : (str_contains($action, 'DENIED') ? 'DENIED' : 'SUCCESS');
    $details = audit_safe_details($details);
    $requestPath = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500);
    $method = substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 16);
    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null;
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $correlation = (string) ($_SERVER['EASYSCHED_REQUEST_ID'] ?? '');
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details_json, severity, outcome, request_path, http_method, source_ip, user_agent, correlation_id, occurred_at_utc, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $stmt->execute([$user['id'] ?? null, $action, $entity, $entityId, encode_json($details), $severity, $outcome, $requestPath, $method, $ip, $agent, $correlation]);
    $GLOBALS['EASYSCHED_AUDIT_REQUEST_RECORDED'] = true;
}

function audit_successful_api_transaction(array $payload, int $status): void
{
    if ($status >= 400
        || ($payload['ok'] ?? false) !== true
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || !empty($GLOBALS['EASYSCHED_AUDIT_REQUEST_RECORDED'])
    ) {
        return;
    }

    $action = (string) ($GLOBALS['EASYSCHED_API_ACTION'] ?? '');
    if ($action === '' || in_array($action, ['check_room_availability', 'available_rooms'], true)) {
        return;
    }

    $pdo = $GLOBALS['EASYSCHED_API_PDO'] ?? null;
    if (!$pdo instanceof PDO) {
        error_log('Successful API transaction could not be audited: database context is unavailable.');
        return;
    }

    $input = $GLOBALS['EASYSCHED_API_INPUT'] ?? [];
    $entities = [
        'register' => 'pending_registration',
        'reset_password' => 'user',
        'change_password' => 'user',
        'save_student_profile' => 'student_profile',
        'confirm_email_change' => 'user',
        'generate' => 'schedule_run',
        'generate_selected' => 'schedule_run',
        'save_schedule' => 'schedule',
        'add_schedule' => 'schedule',
        'delete_schedule' => 'schedule',
        'save_master' => (string) (($input['entity'] ?? '') ?: 'master_data'),
        'review_registration' => 'pending_registration',
        'request_schedule' => 'schedule_request',
        'request_schedule_change' => 'schedule_request',
        'review_schedule_request' => 'schedule_request',
        'import_offerings' => 'course_offerings',
        'save_settings' => 'settings',
        'request_registration_otp' => 'authentication',
        'request_password_reset' => 'authentication',
        'request_email_change_otp' => 'authentication',
        'contact_message' => 'contact_message',
        'logout' => 'authentication'
    ];
    $data = $payload['data'] ?? [];
    $entityId = null;
    if (is_array($data)) {
        foreach (['id', 'registration_id', 'request_id', 'entry_id'] as $key) {
            if (isset($data[$key]) && filter_var($data[$key], FILTER_VALIDATE_INT) !== false && (int) $data[$key] > 0) {
                $entityId = (int) $data[$key];
                break;
            }
        }
    }
    $fieldKeys = array_map('strval', array_keys(is_array($input) ? $input : []));
    $fieldKeys = array_values(array_slice(array_filter(
        $fieldKeys,
        static fn (string $key): bool => preg_match('/password|otp|code|token|csrf|secret|cookie|session|api_key/i', $key) !== 1
    ), 0, 30));

    audit($pdo, current_user($pdo), strtoupper(substr($action, 0, 80)), $entities[$action] ?? 'application', $entityId, [
        'summary' => 'Successful application transaction.',
        'fields' => $fieldKeys
    ]);
}

function respond_csv(string $content, string $filename): never
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    echo $content;
    exit(0);
}

function audit_csv_cell(mixed $value): string
{
    $text = (string) ($value ?? '');
    if (preg_match('/^[=+\-@]/', $text)) $text = "'" . $text;
    return '"' . str_replace('"', '""', $text) . '"';
}

function audit_admin_user(PDO $pdo): array
{
    $user = current_user($pdo);
    if (!$user) {
        audit($pdo, null, 'UNAUTHORIZED_AUDIT_ACCESS', 'audit_logs', null, ['reason' => 'authentication_required']);
        throw new ApiError(401, 'Authentication is required.');
    }
    if ($user['role'] !== 'admin') {
        audit($pdo, $user, 'UNAUTHORIZED_AUDIT_ACCESS', 'audit_logs', null, ['reason' => 'admin_required']);
        throw new ApiError(403, 'Only administrators can access security logs.');
    }
    return $user;
}

function audit_query_filters(array $input): array
{
    $allowedSeverities = ['INFO', 'WARNING', 'CRITICAL'];
    $allowedOutcomes = ['SUCCESS', 'FAILURE', 'DENIED'];
    $allowedTargets = ['user', 'users', 'schedule', 'schedule_run', 'schedule_request', 'rooms', 'instructors', 'subjects', 'programs', 'sections', 'offerings', 'course_offerings', 'pending_registration', 'student_profile', 'contact_message', 'application', 'master_data', 'authentication', 'settings', 'audit_logs'];
    $severity = strtoupper(trim((string) ($input['severity'] ?? '')));
    $outcome = strtoupper(trim((string) ($input['outcome'] ?? '')));
    $target = trim((string) ($input['target_type'] ?? ''));
    if ($severity !== '' && !in_array($severity, $allowedSeverities, true)) throw new ApiError(422, 'Invalid severity filter.');
    if ($outcome !== '' && !in_array($outcome, $allowedOutcomes, true)) throw new ApiError(422, 'Invalid outcome filter.');
    if ($target !== '' && !in_array($target, $allowedTargets, true)) throw new ApiError(422, 'Invalid target filter.');
    $userId = trim((string) ($input['user_id'] ?? ''));
    if ($userId !== '' && (!ctype_digit($userId) || (int) $userId < 1)) throw new ApiError(422, 'Invalid user filter.');
    $from = trim((string) ($input['from'] ?? ''));
    $to = trim((string) ($input['to'] ?? ''));
    foreach ([$from, $to] as $date) {
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new ApiError(422, 'Dates must use YYYY-MM-DD format.');
    }
    $conditions = [];
    $params = [];
    if ($from !== '') { $conditions[] = "(al.occurred_at_utc AT TIME ZONE 'Asia/Manila') >= ?::date"; $params[] = $from; }
    if ($to !== '') { $conditions[] = "(al.occurred_at_utc AT TIME ZONE 'Asia/Manila') < (?::date + INTERVAL '1 day')"; $params[] = $to; }
    if ($severity !== '') { $conditions[] = 'al.severity = ?'; $params[] = $severity; }
    if ($outcome !== '') { $conditions[] = 'al.outcome = ?'; $params[] = $outcome; }
    if ($target !== '') { $conditions[] = 'al.entity_type = ?'; $params[] = $target; }
    if ($userId !== '') { $conditions[] = 'al.user_id = ?'; $params[] = (int) $userId; }
    $keyword = trim((string) ($input['q'] ?? ''));
    if ($keyword !== '') {
        if (strlen($keyword) > 100) throw new ApiError(422, 'Search text is too long.');
        $conditions[] = '(al.action ILIKE ? OR al.entity_type ILIKE ? OR al.details_json ILIKE ? OR u.username ILIKE ?)';
        $like = '%' . $keyword . '%';
        array_push($params, $like, $like, $like, $like);
    }
    return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
}

function audit_rows(PDO $pdo, array $input, bool $forExport = false): array
{
    [$where, $params] = audit_query_filters($input);
    $sortMap = ['time' => 'al.occurred_at_utc', 'action' => 'al.action', 'severity' => 'al.severity', 'outcome' => 'al.outcome'];
    $sort = $sortMap[(string) ($input['sort'] ?? 'time')] ?? $sortMap['time'];
    $direction = strtoupper((string) ($input['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $limit = $forExport ? 5000 : min(100, max(1, (int) ($input['limit'] ?? 25)));
    $page = $forExport ? 1 : min(10000, max(1, (int) ($input['page'] ?? 1)));
    $offset = ($page - 1) * $limit;
    $count = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id{$where}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $sql = "SELECT al.id, to_char(al.occurred_at_utc AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || ' PHT' AS occurred_at_utc, al.action, al.entity_type, al.entity_id, al.details_json, al.severity, al.outcome, al.request_path, al.http_method, host(al.source_ip) AS source_ip, al.user_agent, al.correlation_id, u.username, u.display_name, u.role FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id{$where} ORDER BY {$sort} {$direction}, al.id DESC LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $decoded = json_decode((string) $row['details_json'], true);
        $row['details'] = is_array($decoded) ? $decoded : [];
        unset($row['details_json']);
    }
    unset($row);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => max(1, (int) ceil($total / $limit))];
}

function audit_summary(PDO $pdo, array $input): array
{
    [$where, $params] = audit_query_filters($input);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE action = 'LOGIN_FAILURE') AS failed_logins, COUNT(*) FILTER (WHERE outcome = 'DENIED') AS denied, COUNT(*) FILTER (WHERE severity = 'WARNING') AS warnings, COUNT(*) FILTER (WHERE severity = 'CRITICAL') AS critical FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id{$where}");
    $stmt->execute($params);
    return $stmt->fetch() ?: ['total' => 0, 'failed_logins' => 0, 'denied' => 0, 'warnings' => 0, 'critical' => 0];
}

function login_throttle_key(string $username): string
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'local');
    return hash('sha256', $address . '|' . strtolower($username));
}

function login_ip_key(): string { return 'ip:' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')); }
function login_account_key(string $username): string { return 'account:' . hash('sha256', strtolower($username)); }

function login_attempts(PDO $pdo, string $key, int $now): int
{
    $stmt = $pdo->prepare('SELECT attempts, window_started_at FROM login_throttles WHERE throttle_key = ?');
    $stmt->execute([$key]); $row = $stmt->fetch();
    return !$row || $now - (int) $row['window_started_at'] > 900 ? 0 : (int) $row['attempts'];
}

function assert_login_allowed(PDO $pdo, string $key, int $now): void
{
    $stmt = $pdo->prepare('SELECT attempts, window_started_at, locked_until FROM login_throttles WHERE throttle_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    // Normalize locks created by older builds that used a 15-minute duration.
    $lockedUntil = min((int) $row['locked_until'], (int) $row['window_started_at'] + 60);
    if ($lockedUntil > $now) {
        throw new ApiError(429, 'Too many login attempts. Try again in about one minute.');
    }
    if ($lockedUntil !== (int) $row['locked_until'] || $now - (int) $row['window_started_at'] > 900) {
        $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = ?')->execute([$key]);
    }
}

function record_login_failure(PDO $pdo, string $key, int $now): void
{
    $stmt = $pdo->prepare('SELECT attempts, window_started_at FROM login_throttles WHERE throttle_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $attempts = 1;
    $windowStarted = $now;
    if ($row && $now - (int) $row['window_started_at'] <= 900) {
        $attempts = (int) $row['attempts'] + 1;
        $windowStarted = (int) $row['window_started_at'];
    }
    $lockedUntil = $attempts >= 8 ? $now + 60 : 0;
    $upsert = $pdo->prepare('INSERT INTO login_throttles (throttle_key, attempts, window_started_at, locked_until) VALUES (?, ?, ?, ?) ON CONFLICT(throttle_key) DO UPDATE SET attempts=excluded.attempts, window_started_at=excluded.window_started_at, locked_until=excluded.locked_until');
    $upsert->execute([$key, $attempts, $windowStarted, $lockedUntil]);
}

function login_captcha_required(PDO $pdo, string $ipKey, string $accountKey, int $now): bool
{
    return max(login_attempts($pdo, $ipKey, $now), login_attempts($pdo, $accountKey, $now)) >= 3;
}

function login_captcha_valid(array $input): bool
{
    return easysched_captcha_validate((string) ($input['captcha'] ?? ''));
}

function login_captcha_issue(): array
{
    return easysched_captcha_issue();
}

function login_failure(PDO $pdo, string $username, string $ipKey, string $accountKey, int $now, string $reason): never
{
    record_login_failure($pdo, $ipKey, $now); record_login_failure($pdo, $accountKey, $now);
    audit($pdo, null, 'LOGIN_FAILURE', 'authentication', null, ['username_hash' => hash('sha256', strtolower($username)), 'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')), 'reason' => $reason]);
    $accountAttempts = login_attempts($pdo, $accountKey, $now);
    if ($accountAttempts >= 3 && $username !== '') {
        $accountStmt = $pdo->prepare('SELECT email FROM users WHERE lower(username) = ? AND active = 1 LIMIT 1');
        $accountStmt->execute([strtolower($username)]);
        $email = (string) ($accountStmt->fetchColumn() ?: '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $usernameHash = hash('sha256', strtolower($username));
            $windowCutoff = (new DateTimeImmutable('@' . ($now - 900)))
                ->setTimezone(new DateTimeZone('Asia/Manila'))
                ->format('Y-m-d H:i:s');
            $sentStmt = $pdo->prepare("SELECT 1 FROM audit_logs WHERE action = 'LOGIN_SECURITY_ALERT_SENT' AND created_at >= ? AND details_json LIKE ? LIMIT 1");
            $sentStmt->execute([$windowCutoff, '%"username_hash":"' . $usernameHash . '"%']);
            if (!$sentStmt->fetchColumn()) {
                $retryCutoff = (new DateTimeImmutable('@' . ($now - 60)))
                    ->setTimezone(new DateTimeZone('Asia/Manila'))
                    ->format('Y-m-d H:i:s');
                $attemptStmt = $pdo->prepare("SELECT 1 FROM audit_logs WHERE action = 'LOGIN_SECURITY_ALERT_ATTEMPT' AND created_at >= ? AND details_json LIKE ? LIMIT 1");
                $attemptStmt->execute([$retryCutoff, '%"username_hash":"' . $usernameHash . '"%']);
                if (!$attemptStmt->fetchColumn()) {
                    audit($pdo, null, 'LOGIN_SECURITY_ALERT_ATTEMPT', 'authentication', null, [
                        'username_hash' => $usernameHash,
                        'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')),
                    ]);
                    $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EasySched security alert</title>
</head>
<body style="margin:0;padding:24px 12px;background-color:#f4f6f2;font-family:Arial,Helvetica,sans-serif;color:#182d28;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;border-collapse:separate;border-spacing:0;background-color:#ffffff;border:1px solid #d8e3db;border-radius:12px;overflow:hidden;">
                <tr><td style="padding:28px 32px;background-color:#d2e8dc;border-bottom:4px solid #4f8b67;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                        <tr>
                            <td width="76" valign="middle" style="padding-right:16px;">
                                <img src="https://easysched.tech/assets/school-logo.png" width="64" height="64" alt="New Sinai School and Colleges" style="display:block;width:64px;height:64px;border:0;">
                            </td>
                            <td valign="middle">
                                <div style="font-size:22px;line-height:1.3;font-weight:700;color:#183f34;">EasySched</div>
                                <div style="padding-top:5px;font-size:12px;line-height:1.5;letter-spacing:2px;text-transform:uppercase;color:#2d4f45;">Class scheduling made simple</div>
                            </td>
                        </tr>
                    </table>
                </td></tr>
                <tr><td style="padding:32px;">
                    <div style="margin:0 0 18px;padding:12px 16px;background-color:#fff5e8;border:1px solid #f1d6ac;border-radius:8px;color:#704b17;font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">Account security notice</div>
                    <h1 style="margin:0 0 16px;font-size:25px;line-height:1.3;color:#182d28;">Unsuccessful sign-in attempts</h1>
                    <p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#2d4f45;">We detected several unsuccessful attempts to sign in to your EasySched account.</p>
                    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#2d4f45;">If this wasn’t you, reset your password from the EasySched sign-in page using <strong>Forgot password</strong>. Choose a strong, unique password and never share it or your verification codes.</p>
                    <div style="padding:16px;background-color:#e5f1ea;border:1px solid #d2e8dc;border-radius:8px;">
                        <p style="margin:0;font-size:14px;line-height:1.6;color:#183f34;"><strong>Were you trying to sign in?</strong> You can ignore this message; no action is needed.</p>
                    </div>
                </td></tr>
                <tr><td style="padding:18px 32px;background-color:#f4f6f2;border-top:1px solid #e4ebe5;">
                    <p style="margin:0;font-size:12px;line-height:1.6;color:#5d736b;">This is an automated security message from EasySched. We will never ask you to send us your password or verification code by email.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;
                    try {
                        send_email_message($email, 'Security alert: unsuccessful EasySched sign-in attempts', $html);
                        audit($pdo, null, 'LOGIN_SECURITY_ALERT_SENT', 'authentication', null, [
                            'username_hash' => $usernameHash,
                            'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')),
                        ]);
                    } catch (Throwable $error) {
                        error_log('EasySched login security alert delivery failed for account ' . $usernameHash . ': ' . $error->getMessage());
                    }
                }
            }
        }
    }
    $attempts = max(login_attempts($pdo, $ipKey, $now), $accountAttempts);
    usleep(min(8, 1 << min(3, max(0, $attempts - 1))) * 100000);
    throw new ApiError(401, 'The username or password is incorrect.', $attempts >= 3 ? login_captcha_issue() : []);
}

function recent_login_security_alert(PDO $pdo, string $username): ?array
{
    $usernameHash = hash('sha256', strtolower($username));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_FAILURE' AND created_at >= ? AND details_json LIKE ?");
    $philippineCutoff = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('-24 hours')->format('Y-m-d H:i:s');
    $stmt->execute([$philippineCutoff, '%"username_hash":"' . $usernameHash . '"%']);
    $count = (int) $stmt->fetchColumn();
    return $count > 0 ? ['failed_attempts' => $count, 'window_hours' => 24] : null;
}

function send_email_message(string $to, string $subject, string $html, ?string $replyTo = null): void
{
    $apiKey = trim((string) (getenv('RESEND_API_KEY') ?: ''));
    $smtpUser = trim((string) (getenv('EASYSCHED_SMTP_USERNAME') ?: ''));
    $smtpPass = trim((string) (getenv('EASYSCHED_SMTP_PASSWORD') ?: ''));
    if ($apiKey === '' && $smtpUser !== '' && $smtpPass !== '') {
        $host = trim((string) (getenv('EASYSCHED_SMTP_HOST') ?: 'smtp.gmail.com'));
        $port = (int) (getenv('EASYSCHED_SMTP_PORT') ?: 587);
        $from = trim((string) (getenv('EASYSCHED_EMAIL_FROM') ?: $smtpUser));
        $transport = $port === 465 ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $error, 15);
        if (!$socket && $port === 587) {
            $port = 465; $socket = @stream_socket_client('ssl://' . $host . ':465', $errno, $error, 15);
        }
        if (!$socket) throw new ApiError(503, 'Gmail SMTP could not be reached.');
        stream_set_timeout($socket, 15);
        $smtpRead = static function () use ($socket): string { $reply = ''; while (($line = fgets($socket)) !== false) { $reply .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; } return $reply; };
        $smtpWrite = static function (string $command) use ($socket): void { fwrite($socket, $command . "\r\n"); };
        $smtpRead(); $smtpWrite('EHLO localhost'); $smtpRead();
        if ($port !== 465) { $smtpWrite('STARTTLS'); $smtpRead(); if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($socket); throw new ApiError(503, 'Gmail SMTP encryption could not be started.'); } }
        $smtpWrite('EHLO localhost'); $smtpRead(); $smtpWrite('AUTH LOGIN'); $smtpRead(); $smtpWrite(base64_encode($smtpUser)); $smtpRead(); $smtpWrite(base64_encode($smtpPass)); $auth = $smtpRead();
        if (strpos($auth, '235') === false) { fclose($socket); throw new ApiError(503, 'Gmail SMTP authentication failed. Check the app password.'); }
        $smtpWrite('MAIL FROM:<' . $smtpUser . '>'); $smtpRead(); $smtpWrite('RCPT TO:<' . $to . '>'); $smtpRead(); $smtpWrite('DATA'); $smtpRead();
        $safeSubject = str_replace(["\r", "\n"], '', $subject); $safeFrom = str_replace(["\r", "\n"], '', $from);
        $replyToHeader = $replyTo !== null ? "Reply-To: {$replyTo}\r\n" : '';
        $body = "From: {$safeFrom}\r\nTo: {$to}\r\n{$replyToHeader}Subject: {$safeSubject}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html;
        $smtpWrite(str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body)) . "\r\n."); $sent = $smtpRead(); $smtpWrite('QUIT'); fclose($socket);
        if (strpos($sent, '250') === false) throw new ApiError(503, 'Gmail could not accept the verification email.');
        return;
    }
    $from = trim((string) (getenv('EASYSCHED_EMAIL_FROM') ?: ''));
    if ($apiKey === '' || $from === '') throw new ApiError(503, 'Email delivery is not configured yet.');
    if (!function_exists('curl_init')) throw new ApiError(503, 'Email delivery is unavailable on this server.');
    $curl = curl_init('https://api.resend.com/emails');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            ...($replyTo !== null ? ['reply_to' => $replyTo] : []),
        ], JSON_THROW_ON_ERROR),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false || $status < 200 || $status >= 300) {
        $providerResponse = is_string($response) ? json_decode($response, true) : null;
        $providerName = is_array($providerResponse) ? (string) ($providerResponse['name'] ?? '') : '';
        $providerMessage = is_array($providerResponse) ? (string) ($providerResponse['message'] ?? '') : '';
        error_log('EasySched email error: HTTP ' . $status
            . ($providerName !== '' ? ' ' . substr($providerName, 0, 100) : '')
            . ($providerMessage !== '' ? ' ' . substr($providerMessage, 0, 300) : '')
            . ($error !== '' ? ' ' . substr($error, 0, 300) : ''));
        throw new ApiError(503, 'The verification email could not be sent. Please try again later.');
    }
}

function issue_email_otp(PDO $pdo, string $purpose, string $identifier, string $email): array
{
    $now = time();
    $stmt = $pdo->prepare('SELECT created_at_epoch FROM email_otps WHERE purpose = ? AND identifier = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$purpose, $identifier]);
    $last = (int) ($stmt->fetchColumn() ?: 0);
    if ($last > 0 && $now - $last < 60) throw new ApiError(429, 'Please wait one minute before requesting another code.');
    $pdo->prepare('UPDATE email_otps SET consumed_at = ? WHERE purpose = ? AND identifier = ? AND consumed_at IS NULL')->execute([$now, $purpose, $identifier]);
    $code = (string) random_int(100000, 999999);
    $insert = $pdo->prepare('INSERT INTO email_otps (purpose, identifier, code_hash, expires_at, attempts, max_attempts, created_at_epoch) VALUES (?, ?, ?, ?, 0, 5, ?)');
    $insert->execute([$purpose, $identifier, password_hash($code, PASSWORD_DEFAULT), $now + 600, $now]);
    $label = $purpose === 'REGISTRATION' ? 'registration verification' : 'password reset';
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>EasySched verification code</title></head>
<body style="margin:0;padding:24px 12px;background-color:#f4f6f2;font-family:Arial,Helvetica,sans-serif;color:#182d28;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;border-collapse:separate;border-spacing:0;background-color:#ffffff;border:1px solid #d8e3db;border-radius:12px;overflow:hidden;">
                <tr><td style="padding:28px 32px;background-color:#d2e8dc;border-bottom:4px solid #4f8b67;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                        <tr>
                            <td width="76" valign="middle" style="padding-right:16px;">
                                <img src="https://easysched.tech/assets/school-logo.png" width="64" height="64" alt="New Sinai School and Colleges" style="display:block;width:64px;height:64px;border:0;">
                            </td>
                            <td valign="middle">
                                <div style="font-size:22px;line-height:1.3;font-weight:700;color:#183f34;">EasySched</div>
                                <div style="padding-top:5px;font-size:12px;line-height:1.5;letter-spacing:2px;text-transform:uppercase;color:#2d4f45;">Class scheduling made simple</div>
                            </td>
                        </tr>
                    </table>
                </td></tr>
                <tr><td style="padding:32px;">
                    <h1 style="margin:0 0 16px;font-size:25px;line-height:1.3;color:#182d28;">Your verification code</h1>
                    <p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#2d4f45;">Use this code to complete your EasySched {$safeLabel}.</p>
                    <div style="padding:20px 12px;background-color:#e5f1ea;border:1px solid #d2e8dc;border-radius:8px;text-align:center;">
                        <span style="font-size:32px;line-height:1.3;font-weight:700;letter-spacing:8px;color:#183f34;">{$safeCode}</span>
                    </div>
                    <p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#5d736b;">This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
                </td></tr>
                <tr><td style="padding:18px 32px;background-color:#f4f6f2;border-top:1px solid #e4ebe5;">
                    <p style="margin:0;font-size:12px;line-height:1.6;color:#5d736b;">This is an automated message from EasySched. If you did not request this code, you can ignore this email.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;
        send_email_message($email, 'EasySched verification code', $html);
    return ['message' => 'A six-digit verification code was sent to your email.'];
}

function consume_email_otp(PDO $pdo, string $purpose, string $identifier, string $code): void
{
    if (!preg_match('/^\d{6}$/', $code)) throw new ApiError(422, 'Enter the six-digit verification code.');
    $stmt = $pdo->prepare('SELECT id, code_hash, expires_at, attempts, max_attempts FROM email_otps WHERE purpose = ? AND identifier = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$purpose, $identifier]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['expires_at'] < time()) throw new ApiError(422, 'The verification code is invalid or expired. Request a new code.');
    if ((int) $row['attempts'] >= (int) $row['max_attempts']) throw new ApiError(429, 'Too many incorrect attempts. Request a new code.');
    if (!password_verify($code, (string) $row['code_hash'])) {
        $pdo->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $row['id']]);
        throw new ApiError(422, 'The verification code is invalid or expired.');
    }
    $pdo->prepare('UPDATE email_otps SET consumed_at = ? WHERE id = ?')->execute([time(), (int) $row['id']]);
}

function assert_email_available(PDO $pdo, string $email, int $excludeUserId = 0): void
{
    if ($email === '') return;
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE lower(email) = ? AND id <> ? UNION SELECT 1 FROM pending_registrations WHERE lower(email) = ? AND status = 'PENDING' LIMIT 1");
    $stmt->execute([$email, $excludeUserId, $email]);
    if ($stmt->fetchColumn()) throw new ApiError(409, 'That email address is already registered or awaiting review.');
}

function send_email_change_otp(string $email, string $code): void
{
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html lang="en"><meta charset="UTF-8"><body style="margin:0;padding:24px;background:#f4f6f2;font-family:Arial,Helvetica,sans-serif;color:#182d28"><main style="max-width:520px;margin:auto;padding:28px;background:#fff;border:1px solid #d8e3db;border-radius:12px"><h1 style="font-size:22px;color:#183f34">Confirm your EasySched email change</h1><p>Enter this verification code to change the email address on your account to <strong>' . $safeEmail . '</strong>:</p><p style="padding:18px;background:#e5f1ea;border-radius:8px;text-align:center;font-size:30px;font-weight:700;letter-spacing:8px;color:#183f34">' . $safeCode . '</p><p>This code expires in 10 minutes and can be used only once. Do not share it with anyone.</p><p style="color:#5d736b;font-size:12px">If you did not request this change, you can ignore this message.</p></main></body></html>';
    send_email_message($email, 'Confirm your EasySched email change', $html);
}

function request_email_change_otp(PDO $pdo, array $user, array $input): array
{
    $email = input_email($input, 'new_email');
    if ($email === '') throw new ApiError(422, 'Email address is required.');
    if (strtolower((string) ($user['email'] ?? '')) === $email) throw new ApiError(409, 'That is already your current email address.');
    assert_email_available($pdo, $email, (int) $user['id']);

    $now = time();
    $code = (string) random_int(100000, 999999);
    $pdo->beginTransaction();
    try {
        $account = $pdo->prepare('SELECT id FROM users WHERE id = ? AND active = 1 FOR UPDATE');
        $account->execute([(int) $user['id']]);
        if (!$account->fetchColumn()) throw new ApiError(401, 'Authentication is required.');
        $latest = $pdo->prepare('SELECT created_at_epoch FROM email_change_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $latest->execute([(int) $user['id']]);
        $lastRequested = (int) ($latest->fetchColumn() ?: 0);
        if ($lastRequested > 0 && $now - $lastRequested < 60) throw new ApiError(429, 'Please wait one minute before requesting another code.');

        $pdo->prepare('UPDATE email_change_otps SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL')->execute([$now, (int) $user['id']]);
        $insert = $pdo->prepare('INSERT INTO email_change_otps (user_id, target_email, code_hash, expires_at, attempts, max_attempts, created_at_epoch) VALUES (?, ?, ?, ?, 0, 5, ?)');
        $insert->execute([(int) $user['id'], $email, password_hash($code, PASSWORD_DEFAULT), $now + 600, $now]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    send_email_change_otp($email, $code);
    return ['message' => 'A six-digit verification code was sent to the new email address.'];
}

function confirm_email_change(PDO $pdo, array $user, array $input): array
{
    $code = input_string($input, 'otp', 6);
    if (!preg_match('/^\d{6}$/', $code)) throw new ApiError(422, 'Enter the six-digit verification code.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, target_email, code_hash, expires_at, attempts, max_attempts FROM email_change_otps WHERE user_id = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([(int) $user['id']]);
        $request = $stmt->fetch();
        if (!$request || (int) $request['expires_at'] < time()) throw new ApiError(422, 'The verification code is invalid or expired. Request a new code.');
        if ((int) $request['attempts'] >= (int) $request['max_attempts']) throw new ApiError(429, 'Too many incorrect attempts. Request a new code.');
        if (!password_verify($code, (string) $request['code_hash'])) {
            $pdo->prepare('UPDATE email_change_otps SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $request['id']]);
            $pdo->commit();
            throw new ApiError(422, 'The verification code is invalid or expired.');
        }

        $email = strtolower((string) $request['target_email']);
        assert_email_available($pdo, $email, (int) $user['id']);
        $update = $pdo->prepare('UPDATE users SET email = ? WHERE id = ? AND active = 1');
        $update->execute([$email, (int) $user['id']]);
        if ($update->rowCount() !== 1) throw new ApiError(404, 'Your account could not be updated.');
        $now = time();
        $pdo->prepare('UPDATE email_change_otps SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL')->execute([$now, (int) $user['id']]);
        audit($pdo, $user, 'CHANGE_EMAIL', 'user', (int) $user['id']);
        $pdo->commit();
        return ['message' => 'Your email address has been updated.'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function request_registration_otp(PDO $pdo, array $input): array
{
    $email = input_email($input);
        if ($email === '') throw new ApiError(422, 'Email address is required.');
    $stmt = $pdo->prepare('SELECT id FROM users WHERE lower(email) = ? UNION SELECT id FROM pending_registrations WHERE lower(email) = ? AND status = \'PENDING\' LIMIT 1');
    $stmt->execute([$email, $email]);
    if ($stmt->fetchColumn()) throw new ApiError(409, 'That email is already registered or awaiting review.');
    return issue_email_otp($pdo, 'REGISTRATION', $email, $email);
}

function request_password_reset_otp(PDO $pdo, array $input): array
{
    $account = strtolower(input_string($input, 'account', 180));
    $stmt = $pdo->prepare('SELECT email FROM users WHERE active = 1 AND (lower(username) = ? OR lower(email) = ?) LIMIT 1');
    $stmt->execute([$account, $account]);
    $email = strtolower((string) ($stmt->fetchColumn() ?: ''));
    if ($email !== '') {
        issue_email_otp($pdo, 'PASSWORD_RESET', $email, $email);
        $_SESSION['password_reset_identifier'] = $email;
    } else {
        unset($_SESSION['password_reset_identifier']);
    }
    return ['message' => 'If the account exists and has an email address, a verification code has been sent.'];
}

function reset_forgotten_password(PDO $pdo, array $input): array
{
    $identifier = strtolower((string) ($_SESSION['password_reset_identifier'] ?? ''));
    if ($identifier === '') throw new ApiError(422, 'Request a new password-reset code first.');
    $code = input_string($input, 'otp', 6);
    $password = (string) ($input['password'] ?? '');
    $confirm = (string) ($input['confirm_password'] ?? '');
    if ($password !== $confirm || !password_meets_requirements($password)) throw new ApiError(422, 'Use matching passwords with at least 10 characters, one uppercase letter, one number, and one special character.');
    consume_email_otp($pdo, 'PASSWORD_RESET', $identifier, $code);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE lower(email) = ? AND active = 1');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $identifier]);
    if ($stmt->rowCount() < 1) throw new ApiError(422, 'The reset request is invalid or expired.');
    unset($_SESSION['password_reset_identifier']);
    $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = ?')->execute([login_account_key($identifier)]);
    return ['message' => 'Password reset successfully. You can now sign in.'];
}

function password_meets_requirements(string $password): bool
{
    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    return $length >= 10
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/\d/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

function register_student(PDO $pdo, array $input): array
{
    $username = strtolower(input_string($input, 'username', 80));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $username)) throw new ApiError(422, 'Username may contain only letters, numbers, dots, hyphens, and underscores.');
    $displayName = input_string($input, 'display_name', 160);
    $email = input_email($input);
    if ($email === '') throw new ApiError(422, 'Email address is required.');
    $enrollmentRef = '';
    $programId = input_int($input, 'program_id', 1, 100000000);
    $yearLevel = input_int($input, 'year_level', 1, 4);
    $sectionId = input_int($input, 'section_id', 1, 100000000);
    $password = (string) ($input['password'] ?? '');
    $confirmPassword = (string) ($input['confirm_password'] ?? '');
    if ($password !== $confirmPassword || !password_meets_requirements($password)) throw new ApiError(422, 'Use matching passwords with at least 10 characters, one uppercase letter, one number, and one special character.');
    assert_reference($pdo, 'programs', $programId, 'Program', 'active = 1');
    if ($sectionId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM sections WHERE id = ? AND program_id = ? AND year_level = ? AND active = 1');
        $stmt->execute([$sectionId, $programId, $yearLevel]);
        if (!$stmt->fetchColumn()) throw new ApiError(422, 'The selected section does not match the program and year level.');
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? UNION SELECT id FROM pending_registrations WHERE username = ? AND status = \'PENDING\' LIMIT 1');
    $stmt->execute([$username, $username]);
    if ($stmt->fetchColumn()) throw new ApiError(409, 'That username is already registered or awaiting review.');
    consume_email_otp($pdo, 'REGISTRATION', $email, input_string($input, 'otp', 6));
    try {
        $stmt = $pdo->prepare('INSERT INTO pending_registrations (username, display_name, email, enrollment_ref, program_id, year_level, section_id, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, $displayName, $email, $enrollmentRef, $programId, $yearLevel, $sectionId, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        throw new ApiError(409, 'A registration with those details already exists.');
    }
    $id = db_insert_id($pdo, 'pending_registrations');
    audit($pdo, null, 'REGISTER_STUDENT', 'pending_registration', $id, ['username_hash' => hash('sha256', $username)]);
    return ['message' => 'Registration submitted. An administrator must approve your account before you can sign in.'];
}

function input_string(array $input, string $key, int $max = 255, bool $required = true): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($required && $value === '') {
        throw new ApiError(422, ucfirst($key) . ' is required.');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $max) {
        throw new ApiError(422, ucfirst($key) . ' is too long.');
    }
    return $value;
}

function save_student_profile(PDO $pdo, array $user, array $input): array
{
    $section = input_string($input, 'section', 20);
    if ($section === 'verification') {
        $verified = filter_var($input['verified'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verified === null) throw new ApiError(422, 'Verification choice is invalid.');
        if ($verified) {
            $stmt = $pdo->prepare('SELECT sex, birthdate, mobile_number, guardian_first_name, guardian_last_name, guardian_email, guardian_contact_number, guardian_relation, father_first_name, father_last_name, mother_first_name, mother_last_name, block_lot, barangay, city_municipality, province FROM student_profiles WHERE user_id = ?');
            $stmt->execute([(int) $user['id']]);
            $profile = $stmt->fetch();
            if (!$profile || in_array('', array_map('trim', array_values($profile)), true)) {
                throw new ApiError(422, 'Complete the required profile information before verifying it.');
            }
            $pdo->prepare('UPDATE student_profiles SET verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([(int) $user['id']]);
        } else {
            $pdo->prepare('UPDATE student_profiles SET verified_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([(int) $user['id']]);
        }
        audit($pdo, $user, 'VERIFY_STUDENT_PROFILE', 'student_profile', (int) $user['id']);
        return ['snapshot' => bootstrap($pdo, $user)];
    }

    $groups = [
        'basics' => ['sex' => [20, true], 'birthdate' => [10, true], 'mobile_number' => [24, true], 'guardian_first_name' => [80, true], 'guardian_middle_name' => [80, false], 'guardian_last_name' => [80, true], 'guardian_email' => [254, true], 'guardian_contact_number' => [24, true], 'guardian_relation' => [60, true]],
        'address' => ['block_lot' => [120, true], 'street_name' => [120, false], 'barangay' => [120, true], 'city_municipality' => [120, true], 'province' => [120, true], 'zip_code' => [12, false]],
        'parents' => ['father_last_name' => [80, true], 'father_first_name' => [80, true], 'father_middle_name' => [80, false], 'mother_last_name' => [80, true], 'mother_first_name' => [80, true], 'mother_middle_name' => [80, false]],
    ];
    if (!isset($groups[$section])) throw new ApiError(422, 'Profile section is invalid.');

    $values = [];
    foreach ($groups[$section] as $field => [$max, $required]) {
        $values[$field] = input_string($input, $field, $max, $required);
    }
    if ($section === 'basics') {
        if (!in_array($values['sex'], ['Male', 'Female', 'Other', 'Prefer not to say'], true)) throw new ApiError(422, 'Choose a valid sex option.');
        $birthdate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['birthdate']);
        if (!$birthdate || $birthdate->format('Y-m-d') !== $values['birthdate'] || $birthdate > new DateTimeImmutable('today')) throw new ApiError(422, 'Enter a valid birthdate that is not in the future.');
        if (!preg_match('/^\+?[0-9 ()-]{7,24}$/D', $values['mobile_number'])) throw new ApiError(422, 'Enter a valid mobile number.');
        if (!filter_var($values['guardian_email'], FILTER_VALIDATE_EMAIL)) throw new ApiError(422, 'Enter a valid guardian email address.');
        if (!preg_match('/^\+?[0-9 ()-]{7,24}$/D', $values['guardian_contact_number'])) throw new ApiError(422, 'Enter a valid guardian contact number.');
    }

    $columns = array_keys($values);
    $insertColumns = array_merge(['user_id'], $columns);
    $placeholders = array_fill(0, count($insertColumns), '?');
    $updates = array_map(static fn(string $column): string => $column . ' = excluded.' . $column, $columns);
    $updates[] = 'verified_at = NULL';
    $updates[] = 'updated_at = CURRENT_TIMESTAMP';
    $sql = 'INSERT INTO student_profiles (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ') ON CONFLICT(user_id) DO UPDATE SET ' . implode(', ', $updates);
    $pdo->prepare($sql)->execute(array_merge([(int) $user['id']], array_values($values)));
    audit($pdo, $user, 'UPDATE_STUDENT_PROFILE', 'student_profile', (int) $user['id'], ['section' => $section]);
    return ['snapshot' => bootstrap($pdo, $user)];
}

function input_int(array $input, string $key, int $min, int $max, bool $required = true): ?int
{
    if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
        if ($required) {
            throw new ApiError(422, ucfirst($key) . ' is required.');
        }
        return null;
    }
    $value = filter_var($input[$key], FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
        throw new ApiError(422, ucfirst($key) . ' is invalid.');
    }
    return (int) $value;
}

function input_code(array $input, string $key): string
{
    $value = strtoupper(input_string($input, $key, 40));
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $value)) {
        throw new ApiError(422, ucfirst($key) . ' may contain only letters, numbers, hyphens, and underscores.');
    }
    return $value;
}

function normalize_subject_code(array $input): string
{
    $value = strtoupper(input_string($input, 'code', 40));
    $value = preg_replace('/\s*:\s*/u', ' ', $value) ?? $value;
    $value = preg_replace('/(?<=[A-Z])(?=[0-9])/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    if (str_starts_with($value, 'PATFIT ')) {
        $value = 'PATHFIT ' . substr($value, 7);
    }
    if (!preg_match('/^[A-Z0-9]+(?:[ \/-][A-Z0-9]+)*$/D', $value)) {
        throw new ApiError(422, 'Course code may contain letters, numbers, spaces, slashes, and hyphens.');
    }
    return $value;
}

function normalize_subject_name(array $input): string
{
    $value = input_string($input, 'name', 180);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace_callback('/[\p{L}\p{M}]+(?:[\'’][\p{L}\p{M}]+)*/u', static function (array $match): string {
        $word = $match[0];
        $uppercase = function_exists('mb_strtoupper') ? mb_strtoupper($word, 'UTF-8') : strtoupper($word);
        $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
        if ($length <= 5 && $word === $uppercase) {
            return $word;
        }
        return function_exists('mb_convert_case')
            ? mb_convert_case($word, MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($word));
    }, $value) ?? $value;
    return trim($value);
}

function input_email(array $input, string $key = 'email'): string
{
    $value = input_string($input, $key, 180, false);
    if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        throw new ApiError(422, 'Email address is invalid.');
    }
    return strtolower($value);
}

function clean_string_list(mixed $value, int $maxItems = 30): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    $seen = [];
    foreach (array_slice($value, 0, $maxItems) as $item) {
        $text = trim((string) $item);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > 80) {
            continue;
        }
        $key = strtolower($text);
        if (!isset($seen[$key])) {
            $result[] = $text;
            $seen[$key] = true;
        }
    }
    return $result;
}

function assert_reference(PDO $pdo, string $table, int $id, string $label, string $extraWhere = ''): void
{
    $allowed = ['academic_terms', 'programs', 'instructors', 'rooms', 'subjects', 'sections', 'course_offerings', 'users', 'time_slots'];
    if (!in_array($table, $allowed, true)) {
        throw new LogicException('Unsupported reference table.');
    }
    $sql = "SELECT 1 FROM {$table} WHERE id = ?" . ($extraWhere !== '' ? ' AND ' . $extraWhere : '') . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        throw new ApiError(422, $label . ' is invalid or inactive.');
    }
}

function active_term_id(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT CAST(setting_value AS INTEGER) FROM system_settings WHERE setting_key = 'active_term_id'");
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    return (int) $pdo->query('SELECT id FROM academic_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
}

function decode_rows(array $rows, array $jsonFields = []): array
{
    foreach ($rows as &$row) {
        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = json_array($row[$field]);
            }
        }
    }
    return $rows;
}

function active_run(PDO $pdo, int $termId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM schedule_runs WHERE term_id = ? AND status = 'PUBLISHED' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$termId]);
    return $stmt->fetch() ?: null;
}

function schedule_run_fingerprint(PDO $pdo, int $runId): string
{
    $entries = $pdo->prepare('SELECT id, offering_id, meeting_no, room_id, day_of_week, slot_id, start_time_override, end_time_override, room_fit_override, status FROM schedule_entries WHERE run_id = ? ORDER BY id');
    $entries->execute([$runId]);
    $occupancy = $pdo->prepare('SELECT entry_id, resource_type, resource_id, day_of_week, slot_id FROM schedule_occupancy WHERE run_id = ? ORDER BY entry_id, resource_type, resource_id, day_of_week, slot_id');
    $occupancy->execute([$runId]);
    return hash('sha256', encode_json([$entries->fetchAll(), $occupancy->fetchAll()]));
}

function last_generation(PDO $pdo, int $termId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM schedule_runs WHERE term_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$termId]);
    return $stmt->fetch() ?: null;
}

function validate_published_schedule(PDO $pdo, int $termId): array
{
    $run = active_run($pdo, $termId);
    if (!$run) {
        return ['valid' => false, 'checks' => [], 'issues' => ['No published schedule exists for the active term.']];
    }

    $requirementsStmt = $pdo->prepare("SELECT co.id, co.required_meetings, s.code AS subject_code, sec.code AS section_code
                                       FROM course_offerings co
                                       JOIN subjects s ON s.id = co.subject_id
                                       JOIN sections sec ON sec.id = co.section_id
                                       WHERE co.term_id = ? AND co.status = 'ACTIVE'");
    $requirementsStmt->execute([$termId]);
    $requirements = $requirementsStmt->fetchAll();
    $expected = array_sum(array_map(static fn(array $row): int => (int) $row['required_meetings'], $requirements));
    $entryStmt = $pdo->prepare("SELECT se.id, se.offering_id, se.meeting_no, se.room_id, se.day_of_week, se.slot_id,
                                      se.start_time_override, se.end_time_override,
                                      se.room_fit_override,
                                      co.section_id, co.instructor_id, co.enrollment,
                                      s.code AS subject_code, s.duration_slots, s.room_type, s.required_features_json,
                                      sec.code AS section_code, i.max_hours_day,
                                      r.capacity, r.room_type AS assigned_room_type, r.features_json
                               FROM schedule_entries se
                               JOIN course_offerings co ON co.id = se.offering_id
                               JOIN subjects s ON s.id = co.subject_id
                               JOIN sections sec ON sec.id = co.section_id
                               JOIN instructors i ON i.id = co.instructor_id
                               JOIN rooms r ON r.id = se.room_id
                               WHERE se.run_id = ? AND se.status = 'PUBLISHED'");
    $entryStmt->execute([(int) $run['id']]);
    $entries = $entryStmt->fetchAll();
    $scheduledByOffering = [];
    foreach ($entries as $entry) {
        $offeringId = (int) $entry['offering_id'];
        $scheduledByOffering[$offeringId] = ($scheduledByOffering[$offeringId] ?? 0) + 1;
    }
    $missingRequirements = array_values(array_filter($requirements, static fn(array $row): bool =>
        ($scheduledByOffering[(int) $row['id']] ?? 0) < (int) $row['required_meetings']
    ));
    $context = load_scheduler_context($pdo);
    $checks = [
        'required_meetings_complete' => $missingRequirements === [],
        'valid_time_slots' => true,
        'valid_room_assignments' => true,
        'room_capacity' => true,
        'room_type_and_features' => true,
        'room_conflicts' => true,
        'instructor_conflicts' => true,
        'section_conflicts' => true,
        'declared_availability' => true,
        'instructor_daily_hours' => true,
        'duplicate_assignments' => true,
    ];
    $issues = [];
    if (!$checks['required_meetings_complete']) {
        foreach ($missingRequirements as $requirement) {
            $scheduled = $scheduledByOffering[(int) $requirement['id']] ?? 0;
            $issues[] = sprintf(
                '%s for %s has %d of %d required meetings scheduled.',
                $requirement['subject_code'],
                $requirement['section_code'],
                $scheduled,
                (int) $requirement['required_meetings']
            );
        }
    }
    $occupied = [];
    $dailyHours = [];
    $assignmentKeys = [];
    $occupancyStmt = $pdo->prepare('SELECT entry_id, slot_id FROM schedule_occupancy WHERE run_id = ? ORDER BY entry_id, slot_id');
    $occupancyStmt->execute([(int) $run['id']]);
    $occupiedSlotsByEntry = [];
    foreach ($occupancyStmt->fetchAll() as $occupiedRow) {
        $occupiedSlotsByEntry[(int) $occupiedRow['entry_id']][] = (int) $occupiedRow['slot_id'];
    }

    foreach ($entries as $entry) {
        $assignmentKey = (int) $entry['offering_id'] . ':' . (int) $entry['meeting_no'];
        if (isset($assignmentKeys[$assignmentKey])) {
            $checks['duplicate_assignments'] = false;
            $issues[] = 'A course offering meeting is assigned more than once.';
        }
        $assignmentKeys[$assignmentKey] = true;

        if (!isset(DAY_NAMES[(int) $entry['day_of_week']])) {
            $checks['valid_time_slots'] = false;
            $issues[] = $entry['subject_code'] . ' has an invalid school day.';
            continue;
        }
        $slotIndex = null;
        foreach ($context['slots'] as $index => $slot) {
            if ((int) $slot['id'] === (int) $entry['slot_id']) { $slotIndex = $index; break; }
        }
        $window = $slotIndex === null ? null : slot_window($context['slots'], $slotIndex, max(1, (int) $entry['duration_slots']));
        if ($window === null) {
            $checks['valid_time_slots'] = false;
            $issues[] = $entry['subject_code'] . ' does not fit within the configured time slots.';
            continue;
        }

        if (!(int) $entry['room_fit_override'] && (int) $entry['capacity'] < (int) $entry['enrollment']) {
            $checks['room_capacity'] = false;
            $issues[] = sprintf('%s %s exceeds its room capacity.', $entry['subject_code'], $entry['section_code']);
        }
        $features = array_map('strtolower', array_map('strval', json_array($entry['features_json'])));
        $required = json_array($entry['required_features_json']);
        if (!(int) $entry['room_fit_override'] && ($entry['assigned_room_type'] !== $entry['room_type'] || array_filter($required, static fn($feature): bool => !in_array(strtolower((string) $feature), $features, true)))) {
            $checks['room_type_and_features'] = false;
            $checks['valid_room_assignments'] = false;
            $issues[] = sprintf('%s %s is assigned to an incompatible room.', $entry['subject_code'], $entry['section_code']);
        }

        $occupiedSlotIds = $occupiedSlotsByEntry[(int) $entry['id']] ?? [];
        if ($occupiedSlotIds !== []) {
            $occupiedSet = array_flip($occupiedSlotIds);
            $window = array_values(array_filter($context['slots'], static fn(array $slot): bool => isset($occupiedSet[(int) $slot['id']])));
        }
        $entryHours = 0.0;
        foreach ($window as $slot) {
            $slotId = (int) $slot['id'];
            $day = (int) $entry['day_of_week'];
            $resourceKeys = [
                'room_conflicts' => occupancy_key('ROOM', (int) $entry['room_id'], $day, $slotId),
                'instructor_conflicts' => occupancy_key('INSTRUCTOR', (int) $entry['instructor_id'], $day, $slotId),
                'section_conflicts' => occupancy_key('SECTION', (int) $entry['section_id'], $day, $slotId),
            ];
            foreach ($resourceKeys as $check => $key) {
                if (isset($occupied[$key])) {
                    $checks[$check] = false;
                    $issues[] = ucfirst(str_replace('_', ' ', $check)) . ' detected.';
                }
                $occupied[$key] = true;
            }
            if (isset($context['blocked_room'][(int) $entry['room_id'] . ':' . $day . ':' . $slotId]) || isset($context['blocked_instructor'][(int) $entry['instructor_id'] . ':' . $day . ':' . $slotId]) || isset($context['blocked_section'][(int) $entry['section_id'] . ':' . $day . ':' . $slotId])) {
                $checks['declared_availability'] = false;
                $issues[] = sprintf('%s %s uses a blocked availability period.', $entry['subject_code'], $entry['section_code']);
            }
            $entryHours += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600;
        }
        if ($window !== [] && (!empty($entry['start_time_override']) || !empty($entry['end_time_override']))) {
            $entryStartTime = (string) ($entry['start_time_override'] ?: $window[0]['start_time']);
            $entryEndTime = (string) ($entry['end_time_override'] ?: $window[count($window) - 1]['end_time']);
            $entryHours = max(0, (strtotime($entryEndTime) - strtotime($entryStartTime)) / 3600);
        }
        $hoursKey = (int) $entry['instructor_id'] . ':' . (int) $entry['day_of_week'];
        $dailyHours[$hoursKey] = ($dailyHours[$hoursKey] ?? 0) + $entryHours;
        if ($dailyHours[$hoursKey] > (int) $entry['max_hours_day']) {
            $checks['instructor_daily_hours'] = false;
            $issues[] = 'An instructor exceeds the configured daily teaching-hour limit.';
        }
    }

    return ['valid' => !in_array(false, $checks, true), 'checks' => $checks, 'issues' => array_values(array_unique($issues)), 'expected_tasks' => $expected, 'published_tasks' => count($entries)];
}

function scoped_schedule(PDO $pdo, array $user, int $termId, ?int $runId = null): array
{
    if ($runId === null) {
        $where = ['co.term_id = ?', "sr.status = 'PUBLISHED'", "se.status = 'PUBLISHED'"];
        $params = [$termId];
    } else {
        $where = ['sr.id = ?', "sr.status IN ('PUBLISHED', 'ARCHIVED')", "se.status = 'PUBLISHED'"];
        $params = [$runId];
    }
    if ($user['role'] === 'instructor') {
        $where[] = 'co.instructor_id = ?';
        $params[] = (int) $user['instructor_id'];
    } elseif ($user['role'] === 'student') {
        $where[] = 'co.section_id = ?';
        $params[] = (int) $user['section_id'];
    }
    $sql = 'SELECT se.id, se.run_id, se.offering_id, se.meeting_no, se.day_of_week, se.slot_id,
                   ts.code AS slot_code, ts.label AS slot_label, COALESCE(se.start_time_override, ts.start_time) AS start_time,
                   COALESCE(se.end_time_override, (SELECT end_time FROM time_slots ts_end WHERE ts_end.slot_order = ts.slot_order + s.duration_slots - 1), ts.end_time) AS end_time,
                   r.id AS room_id, r.code AS room_code, r.name AS room_name, r.capacity AS room_capacity, r.room_type,
                   s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.duration_slots,
                   sec.id AS section_id, sec.code AS section_code, sec.student_count,
                   p.code AS program_code, p.name AS program_name,
                   i.id AS instructor_id, i.employee_no, i.name AS instructor_name
            FROM schedule_entries se
            JOIN schedule_runs sr ON sr.id = se.run_id
            JOIN course_offerings co ON co.id = se.offering_id
            JOIN time_slots ts ON ts.id = se.slot_id
            JOIN rooms r ON r.id = se.room_id
            JOIN subjects s ON s.id = co.subject_id
            JOIN sections sec ON sec.id = co.section_id
            JOIN programs p ON p.id = sec.program_id
            JOIN instructors i ON i.id = co.instructor_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY se.day_of_week, ts.slot_order, sec.code, s.code';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['day_name'] = DAY_NAMES[(int) $row['day_of_week']] ?? 'Unknown';
        $row['time_label'] = date('g:i A', strtotime((string) $row['start_time'])) . ' - ' . date('g:i A', strtotime((string) $row['end_time']));
    }
    return $rows;
}

function sample_schedule_for_term(PDO $pdo, int $termId): array
{
    $stmt = $pdo->prepare('SELECT schedules_json FROM schedule_history_samples WHERE term_id = ?');
    $stmt->execute([$termId]);
    $stored = $stmt->fetchColumn();
    if ($stored === false) return [];
    $samples = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($samples)) {
        throw new RuntimeException('Stored sample schedule history is invalid.');
    }

    $slotRows = $pdo->query('SELECT id, code, label, start_time, end_time, slot_order FROM time_slots ORDER BY slot_order')->fetchAll();
    $slotsByOrder = [];
    foreach ($slotRows as $slot) {
        $slotsByOrder[(int) $slot['slot_order']] = $slot;
    }

    $rows = [];
    foreach ($samples as $index => $sample) {
        $slot = $slotsByOrder[(int) ($sample['slot_order'] ?? 0)] ?? null;
        if ($slot === null) {
            throw new RuntimeException('A sample schedule references a missing timetable slot.');
        }
        $start = (string) $slot['start_time'];
        $end = (string) $slot['end_time'];
        $day = (int) $sample['day_of_week'];
        $rows[] = [
            'id' => 'sample-' . $termId . '-' . ($index + 1),
            'run_id' => null,
            'offering_id' => 0,
            'meeting_no' => 1,
            'day_of_week' => $day,
            'slot_id' => (int) $slot['id'],
            'slot_code' => $slot['code'],
            'slot_label' => $slot['label'],
            'start_time' => $start,
            'end_time' => $end,
            'time_label' => date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end)),
            'room_id' => 0,
            'room_code' => $sample['room_code'],
            'room_name' => $sample['room_code'],
            'room_capacity' => 30,
            'room_type' => $sample['room_type'],
            'subject_id' => 0,
            'subject_code' => $sample['subject_code'],
            'subject_name' => $sample['subject_name'],
            'duration_slots' => 1,
            'section_id' => 0,
            'section_code' => $sample['section_code'],
            'student_count' => 30,
            'program_code' => 'SAMPLE',
            'program_name' => 'Sample program',
            'instructor_id' => 0,
            'employee_no' => '',
            'instructor_name' => $sample['instructor_name'],
            'day_name' => DAY_NAMES[$day] ?? 'Unknown',
        ];
    }
    return $rows;
}

function bootstrap(PDO $pdo, array $user): array
{
    $termId = active_term_id($pdo);
    $terms = $pdo->query('SELECT id, academic_year, semester, is_active FROM academic_terms ORDER BY academic_year DESC, id DESC')->fetchAll();
    $programs = $pdo->query('SELECT id, code, name, active FROM programs WHERE active = 1 ORDER BY code')->fetchAll();
    $instructors = $pdo->query('SELECT id, employee_no, name, email, max_hours_day, active FROM instructors WHERE active = 1 ORDER BY id')->fetchAll();
    $rooms = decode_rows($pdo->query('SELECT id, code, name, capacity, room_type, features_json, active FROM rooms WHERE active = 1 ORDER BY code')->fetchAll(), ['features_json']);
    foreach ($rooms as &$room) {
        $room['features'] = $room['features_json'];
        unset($room['features_json']);
    }
    $subjects = decode_rows($pdo->query('SELECT id, code, name, units, hours_per_week, duration_slots, room_type, required_features_json, active FROM subjects WHERE active = 1 ORDER BY code')->fetchAll(), ['required_features_json']);
    foreach ($subjects as &$subject) {
        $subject['required_features'] = $subject['required_features_json'];
        unset($subject['required_features_json']);
    }
    $stmt = $pdo->prepare('SELECT sec.id, sec.program_id, sec.code, sec.year_level, sec.student_count, sec.term_id, p.code AS program_code, p.name AS program_name FROM sections sec JOIN programs p ON p.id = sec.program_id WHERE sec.active = 1 AND sec.term_id = ? ORDER BY sec.code');
    $stmt->execute([$termId]);
    $sections = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT co.id, co.term_id, co.subject_id, co.section_id, co.instructor_id, co.enrollment, co.required_meetings, co.status, s.code AS subject_code, s.name AS subject_name, s.room_type, s.required_features_json, sec.code AS section_code, i.name AS instructor_name FROM course_offerings co JOIN subjects s ON s.id = co.subject_id JOIN sections sec ON sec.id = co.section_id JOIN instructors i ON i.id = co.instructor_id WHERE co.term_id = ? AND co.status = \'ACTIVE\' ORDER BY sec.code, s.code');
    $stmt->execute([$termId]);
    $offerings = $stmt->fetchAll();
    foreach ($offerings as &$offering) {
        $offering['required_features'] = json_array($offering['required_features_json']);
        unset($offering['required_features_json']);
    }
    unset($offering);
    $slots = $pdo->query('SELECT id, code, label, start_time, end_time, slot_order FROM time_slots ORDER BY slot_order')->fetchAll();
    $run = active_run($pdo, $termId);
    $schedules = scoped_schedule($pdo, $user, $termId);
    $settings = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $studentProfile = null;
    $profileStmt = $pdo->prepare('SELECT sex, birthdate, mobile_number, father_first_name, father_middle_name, father_last_name, mother_first_name, mother_middle_name, mother_last_name, guardian_first_name, guardian_middle_name, guardian_last_name, guardian_email, guardian_contact_number, guardian_relation, block_lot, street_name, barangay, city_municipality, province, zip_code, verified_at FROM student_profiles WHERE user_id = ?');
    $profileStmt->execute([(int) $user['id']]);
    $studentProfile = $profileStmt->fetch() ?: null;
    $users = [];
    $pendingRegistrations = [];
    if ($user['role'] === 'admin') {
        $users = $pdo->query("SELECT u.id, u.username, u.display_name, u.email, u.role, u.instructor_id, u.section_id, u.active, i.name AS instructor_name, sec.code AS section_code FROM users u LEFT JOIN instructors i ON i.id = u.instructor_id LEFT JOIN sections sec ON sec.id = u.section_id WHERE u.active = 1 ORDER BY u.role, u.username")->fetchAll();
        $pendingRegistrations = $pdo->query("SELECT pr.id, pr.username, pr.display_name, pr.email, pr.enrollment_ref, pr.program_id, pr.year_level, pr.section_id, pr.status, pr.review_note, pr.created_at, p.code AS program_code, sec.code AS section_code FROM pending_registrations pr JOIN programs p ON p.id = pr.program_id LEFT JOIN sections sec ON sec.id = pr.section_id WHERE pr.status = 'PENDING' ORDER BY pr.created_at ASC, pr.id ASC")->fetchAll();
    }
    $scheduleRequests = [];
    if (in_array($user['role'], ['admin', 'instructor'], true)) {
        $requestSql = "SELECT sr.*, s.code AS subject_code, CASE WHEN sr.request_type='OTHER' THEN 'Others' ELSE s.name END AS subject_name, sec.code AS section_code, i.name AS instructor_name, r.code AS room_code, ts.label AS slot_label, ts.start_time AS request_start_time, COALESCE(sr.requested_end_time, ts.end_time) AS requested_end_time, CASE WHEN target.id IS NULL THEN NULL ELSE target.day_of_week END AS previous_day_of_week, COALESCE(target.start_time_override, targetSlot.start_time) AS previous_start_time, COALESCE(target.end_time_override, (SELECT previousEnd.end_time FROM time_slots previousEnd WHERE previousEnd.slot_order = targetSlot.slot_order + s.duration_slots - 1), targetSlot.end_time) AS previous_end_time, targetRoom.code AS previous_room_code FROM schedule_requests sr LEFT JOIN course_offerings co ON co.id=sr.offering_id LEFT JOIN subjects s ON s.id=co.subject_id LEFT JOIN sections sec ON sec.id=co.section_id JOIN instructors i ON i.id=sr.instructor_id JOIN rooms r ON r.id=sr.room_id JOIN time_slots ts ON ts.id=sr.slot_id LEFT JOIN schedule_entries target ON target.id=sr.target_entry_id LEFT JOIN rooms targetRoom ON targetRoom.id=target.room_id LEFT JOIN time_slots targetSlot ON targetSlot.id=target.slot_id WHERE 1=1";
        $requestParams = [];
        if ($user['role'] === 'instructor') { $requestSql .= ' AND sr.instructor_id = ?'; $requestParams[] = (int) $user['instructor_id']; }
        if ($user['role'] === 'admin') $requestSql .= " AND sr.status='PENDING'";
        $requestSql .= ' ORDER BY sr.created_at ASC';
        $requestStmt = $pdo->prepare($requestSql); $requestStmt->execute($requestParams); $scheduleRequests = $requestStmt->fetchAll();
    }
    if (in_array($user['role'], ['instructor', 'student'], true)) {
        if ($user['role'] === 'student') {
            $validSectionId = $pdo->prepare("SELECT sec.id FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = ? AND co.status = 'ACTIVE' WHERE sec.id = ? AND sec.active = 1 GROUP BY sec.id LIMIT 1");
            $validSectionId->execute([$termId, (int) ($user['section_id'] ?? 0)]);
            if ($validSectionId->fetchColumn() === false) {
                $fallbackSectionId = $pdo->prepare("SELECT sec.id FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = ? AND co.status = 'ACTIVE' WHERE sec.active = 1 GROUP BY sec.id ORDER BY sec.id LIMIT 1");
                $fallbackSectionId->execute([$termId]);
                $fallbackValue = $fallbackSectionId->fetchColumn();
                if ($fallbackValue !== false) {
                    $pdo->prepare("UPDATE users SET section_id = ? WHERE id = ? AND role = 'student'")->execute([(int) $fallbackValue, (int) $user['id']]);
                    $user['section_id'] = (int) $fallbackValue;
                }
            }
        }

        $offerings = array_values(array_filter($offerings, static function (array $offering) use ($user): bool {
            return $user['role'] === 'instructor'
                ? (int) $offering['instructor_id'] === (int) $user['instructor_id']
                : (int) $offering['section_id'] === (int) ($user['section_id'] ?? 0);
        }));
        $allowedInstructorIds = array_map('intval', array_column($offerings, 'instructor_id'));
        $allowedSectionIds = array_map('intval', array_column($offerings, 'section_id'));
        $allowedSubjectIds = array_map('intval', array_column($offerings, 'subject_id'));
        $allowedRoomIds = $user['role'] === 'instructor'
            ? array_map('intval', array_column($rooms, 'id'))
            : array_map('intval', array_column($schedules, 'room_id'));
        $instructors = array_values(array_filter($instructors, static fn(array $row): bool => in_array((int) $row['id'], $allowedInstructorIds, true)));
        $sections = array_values(array_filter($sections, static fn(array $row): bool => in_array((int) $row['id'], $allowedSectionIds, true)));
        $subjects = array_values(array_filter($subjects, static fn(array $row): bool => in_array((int) $row['id'], $allowedSubjectIds, true)));
        $rooms = array_values(array_filter($rooms, static fn(array $row): bool => in_array((int) $row['id'], $allowedRoomIds, true)));
        $allowedProgramIds = array_map('intval', array_column($sections, 'program_id'));
        $programs = array_values(array_filter($programs, static fn(array $row): bool => in_array((int) $row['id'], $allowedProgramIds, true)));
        $settings = array_intersect_key($settings, array_flip(['institution_name', 'system_name']));
    }
    return [
        'user' => $user,
        'student_profile' => $studentProfile,
        'csrf' => csrf_token(),
        'settings' => $settings,
        'active_term_id' => $termId,
        'terms' => $terms,
        'programs' => $programs,
        'instructors' => $instructors,
        'rooms' => $rooms,
        'subjects' => $subjects,
        'sections' => $sections,
        'offerings' => $offerings,
        'users' => $users,
        'pending_registrations' => $pendingRegistrations,
        'schedule_requests' => $scheduleRequests,
        'time_slots' => $slots,
        'days' => DAY_NAMES,
        'active_run' => $run ? ['id' => (int) $run['id'], 'status' => $run['status'], 'created_at' => $run['created_at'], 'assigned_tasks' => (int) $run['assigned_tasks'], 'total_tasks' => (int) $run['total_tasks'], 'diagnostics' => json_decode($run['diagnostics_json'], true) ?: []] : null,
        'last_generation' => ($lastGeneration = last_generation($pdo, $termId)) ? ['id' => (int) $lastGeneration['id'], 'status' => $lastGeneration['status'], 'created_at' => $lastGeneration['created_at'], 'assigned_tasks' => (int) $lastGeneration['assigned_tasks'], 'total_tasks' => (int) $lastGeneration['total_tasks'], 'diagnostics' => json_decode($lastGeneration['diagnostics_json'], true) ?: []] : null,
        'validation' => validate_published_schedule($pdo, $termId),
        'schedules' => $schedules,
        'database_driver' => (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    ];
}

function load_offerings(PDO $pdo, int $termId): array
{
    $stmt = $pdo->prepare('SELECT co.id, co.term_id, co.subject_id, co.section_id, co.instructor_id, co.enrollment, co.required_meetings, sec.program_id,
                                  s.code AS subject_code, s.name AS subject_name, s.hours_per_week, s.duration_slots, s.room_type, s.required_features_json,
                                  sec.code AS section_code, sec.student_count,
                                  i.name AS instructor_name, i.max_hours_day
                           FROM course_offerings co
                           JOIN subjects s ON s.id = co.subject_id
                           JOIN sections sec ON sec.id = co.section_id
                           JOIN instructors i ON i.id = co.instructor_id
                           WHERE co.term_id = ? AND co.status = \'ACTIVE\'
                           ORDER BY co.id');
    $stmt->execute([$termId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['required_features'] = json_array($row['required_features_json']);
        unset($row['required_features_json']);
        $row['duration_slots'] = max(1, (int) $row['duration_slots']);
        $row['required_meetings'] = max(1, (int) $row['required_meetings']);
    }
    return $rows;
}

function load_scheduler_context(PDO $pdo): array
{
    $slots = $pdo->query('SELECT id, slot_order, start_time, end_time FROM time_slots WHERE slot_order <> 6 ORDER BY slot_order')->fetchAll();
    $rooms = decode_rows($pdo->query('SELECT id, capacity, room_type, features_json FROM rooms WHERE active = 1')->fetchAll(), ['features_json']);
    foreach ($rooms as &$room) {
        $room['features'] = array_map('strtolower', array_map('strval', $room['features_json']));
        unset($room['features_json']);
    }
    $blockedInstructor = [];
    foreach ($pdo->query('SELECT instructor_id, day_of_week, slot_id FROM instructor_availability WHERE available = 0')->fetchAll() as $row) {
        $blockedInstructor[(int) $row['instructor_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = true;
    }
    $blockedRoom = [];
    foreach ($pdo->query('SELECT room_id, day_of_week, slot_id FROM room_availability WHERE available = 0')->fetchAll() as $row) {
        $blockedRoom[(int) $row['room_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = true;
    }
    $blockedSection = [];
    foreach ($pdo->query('SELECT section_id, day_of_week, slot_id FROM section_availability WHERE available = 0')->fetchAll() as $row) {
        $blockedSection[(int) $row['section_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = true;
    }
    $preferences = [];
    foreach ($pdo->query('SELECT instructor_id, day_of_week, slot_id, preference FROM instructor_time_preferences')->fetchAll() as $row) {
        $preferences[(int) $row['instructor_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = (int) $row['preference'];
    }
    return ['slots' => $slots, 'rooms' => $rooms, 'blocked_instructor' => $blockedInstructor, 'blocked_room' => $blockedRoom, 'blocked_section' => $blockedSection, 'preferences' => $preferences];
}

function slot_window(array $slots, int $startIndex, int $duration): ?array
{
    $window = array_slice($slots, $startIndex, $duration);
    if (count($window) !== $duration) {
        return null;
    }
    for ($i = 1; $i < count($window); $i++) {
        if ((int) $window[$i]['slot_order'] !== (int) $window[$i - 1]['slot_order'] + 1) {
            return null;
        }
    }
    return $window;
}

function occupancy_key(string $type, int $resourceId, int $day, int $slotId): string
{
    return $type . ':' . $resourceId . ':' . $day . ':' . $slotId;
}

function candidate_for(array $task, array $room, int $day, array $window, array $context, array $occupied, array $dailyHours, bool $requestProposal = false, ?float $actualHours = null, bool $allowRoomFitOverride = false): ?array
{
    if (!$requestProposal && !$allowRoomFitOverride && (int) $room['capacity'] < (int) $task['enrollment']) {
        return null;
    }
    if (!$requestProposal && !$allowRoomFitOverride && (string) $room['room_type'] !== (string) $task['room_type']) {
        return null;
    }
    if (!$requestProposal && !$allowRoomFitOverride) {
        foreach ($task['required_features'] as $feature) {
            if (!in_array(strtolower((string) $feature), $room['features'], true)) {
                return null;
            }
        }
    }
    $roomHours = 0;
    foreach ($window as $slot) {
        $slotId = (int) $slot['id'];
        $keyRoom = occupancy_key('ROOM', (int) $room['id'], $day, $slotId);
        $keyInstructor = occupancy_key('INSTRUCTOR', (int) $task['instructor_id'], $day, $slotId);
        $keySection = occupancy_key('SECTION', (int) $task['section_id'], $day, $slotId);
        if (isset($occupied[$keyRoom]) || isset($occupied[$keyInstructor]) || isset($occupied[$keySection])) {
            return null;
        }
        if (!$requestProposal && (isset($context['blocked_room'][(int) $room['id'] . ':' . $day . ':' . $slotId])
            || isset($context['blocked_instructor'][(int) $task['instructor_id'] . ':' . $day . ':' . $slotId])
            || isset($context['blocked_section'][(int) $task['section_id'] . ':' . $day . ':' . $slotId]))) {
            return null;
        }
        $roomHours += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600;
    }
    $currentHours = (float) ($dailyHours[(int) $task['instructor_id'] . ':' . $day] ?? 0);
    $scheduledHours = $actualHours ?? $roomHours;
    if (!$requestProposal && $currentHours + $scheduledHours > (int) $task['max_hours_day']) {
        return null;
    }
    $preferencePenalty = 0.0;
    foreach ($window as $slot) {
        $preference = (int) ($context['preferences'][(int) $task['instructor_id'] . ':' . $day . ':' . (int) $slot['id']] ?? 0);
        $preferencePenalty += $preference < 0 ? abs($preference) * 1.5 : -$preference * 0.75;
    }
    $roomWaste = max(0, (int) $room['capacity'] - (int) $task['enrollment']);
    return ['room_id' => (int) $room['id'], 'day' => $day, 'slot_id' => (int) $window[0]['id'], 'slot_ids' => array_map(static fn(array $slot): int => (int) $slot['id'], $window), 'hours' => $scheduledHours, 'cost' => $roomWaste * 0.01 + $preferencePenalty];
}

function build_tasks(array $offerings): array
{
    $tasks = [];
    foreach ($offerings as $offering) {
        for ($meeting = 1; $meeting <= (int) $offering['required_meetings']; $meeting++) {
            $tasks[] = ['task_id' => (int) $offering['id'] . ':' . $meeting, 'offering_id' => (int) $offering['id'], 'meeting_no' => $meeting, 'subject_name' => $offering['subject_name'], 'section_id' => (int) $offering['section_id'], 'section_code' => $offering['section_code'], 'instructor_id' => (int) $offering['instructor_id'], 'instructor_name' => $offering['instructor_name'], 'max_hours_day' => (int) $offering['max_hours_day'], 'enrollment' => (int) $offering['enrollment'], 'duration_slots' => (int) $offering['duration_slots'], 'room_type' => $offering['room_type'], 'required_features' => $offering['required_features']];
        }
    }
    return $tasks;
}

function scheduler_preflight(array $offerings, array $context): array
{
    $issues = [];
    $warnings = [];
    if ($context['slots'] === []) {
        $issues[] = 'No time slots are configured.';
    }
    if ($context['rooms'] === []) {
        $issues[] = 'No active rooms are configured.';
    }

    foreach ($offerings as $offering) {
        $eligibleRooms = array_filter($context['rooms'], static function (array $room) use ($offering): bool {
            if ((int) $room['capacity'] < (int) $offering['enrollment'] || (string) $room['room_type'] !== (string) $offering['room_type']) {
                return false;
            }
            foreach ($offering['required_features'] as $feature) {
                if (!in_array(strtolower((string) $feature), $room['features'], true)) {
                    return false;
                }
            }
            return true;
        });
        if ($eligibleRooms === []) {
            $issues[] = sprintf('%s for %s needs a %s room for %d students with %s, but no eligible room exists.', $offering['subject_code'], $offering['section_code'], strtolower((string) $offering['room_type']), (int) $offering['enrollment'], $offering['required_features'] === [] ? 'no special features' : implode(', ', $offering['required_features']));
        }
        if ((int) $offering['duration_slots'] > count($context['slots'])) {
            $issues[] = sprintf('%s for %s needs %d consecutive slots, but only %d are configured.', $offering['subject_code'], $offering['section_code'], (int) $offering['duration_slots'], count($context['slots']));
        }

        $firstWindow = slot_window($context['slots'], 0, max(1, (int) $offering['duration_slots']));
        $hoursPerMeeting = 0.0;
        foreach ($firstWindow ?: [] as $slot) {
            $hoursPerMeeting += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600;
        }
        $plannedHours = $hoursPerMeeting * (int) $offering['required_meetings'];
        if ($hoursPerMeeting > 0 && abs($plannedHours - (float) $offering['hours_per_week']) > 0.01) {
            $warnings[] = sprintf('%s for %s is configured for %.1f scheduled hours but the subject requires %d hours per week.', $offering['subject_code'], $offering['section_code'], $plannedHours, (int) $offering['hours_per_week']);
        }
    }
    return ['issues' => array_values(array_unique($issues)), 'warnings' => array_values(array_unique($warnings))];
}

function explain_unscheduled_tasks(array $tasks, array $context): array
{
    $reasons = [];
    foreach ($tasks as $task) {
        $hasRoom = false;
        $hasTime = false;
        foreach ($context['rooms'] as $room) {
            if ((int) $room['capacity'] < (int) $task['enrollment']) {
                continue;
            }
            $hasRoom = true;
            foreach (DAY_NAMES as $day => $_dayName) {
                foreach ($context['slots'] as $slotIndex => $_slot) {
                    $window = slot_window($context['slots'], $slotIndex, (int) $task['duration_slots']);
                    if ($window !== null && candidate_for($task, $room, (int) $day, $window, $context, [], []) !== null) {
                        $hasTime = true;
                        break 3;
                    }
                }
            }
        }
        if (!$hasRoom) {
            $reason = sprintf('%s (%s) has no room matching its capacity and type.', $task['subject_name'], $task['section_code']);
        } elseif (!$hasTime) {
            $reason = sprintf('%s (%s) has no individually valid time because of duration, availability, or daily-hour limits.', $task['subject_name'], $task['section_code']);
        } else {
            $reason = sprintf('%s (%s) has valid individual choices, but the combined room, instructor, and section constraints are over-constrained.', $task['subject_name'], $task['section_code']);
        }
        $reasons[] = $reason;
    }
    return array_values(array_unique($reasons));
}

function section_schedule_gap_slots(array $sectionAssignments, array $slotPositions): int
{
    $gaps = 0;
    foreach ($sectionAssignments as $dayAssignments) {
        $intervals = [];
        foreach ($dayAssignments as $assignment) {
            $positions = array_map(static fn(int $slotId): int => $slotPositions[$slotId] ?? 0, $assignment['slot_ids']);
            $intervals[] = [min($positions), max($positions)];
        }
        usort($intervals, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $lastEnd = null;
        foreach ($intervals as [$start, $end]) {
            if ($lastEnd !== null && $start > $lastEnd + 1) {
                $gaps += $start - $lastEnd - 1;
            }
            $lastEnd = max($lastEnd ?? $end, $end);
        }
    }
    return $gaps;
}

function section_schedule_compactness_cost(array $sectionAssignments, array $slotPositions): int
{
    $daysUsed = 0;
    $dailyOverload = 0.0;
    $dailyStartDelay = 0;
    foreach ($sectionAssignments as $dayAssignments) {
        if ($dayAssignments === []) {
            continue;
        }
        $daysUsed++;
        $dailyHours = array_sum(array_map(static fn(array $assignment): float => (float) $assignment['hours'], $dayAssignments));
        $dailyOverload += max(0.0, $dailyHours - 4.0);
        $starts = [];
        foreach ($dayAssignments as $assignment) {
            foreach ($assignment['slot_ids'] as $slotId) {
                $starts[] = $slotPositions[(int) $slotId] ?? 1;
            }
        }
        $dailyStartDelay += max(0, min($starts) - 1);
    }
    return (int) round(section_schedule_gap_slots($sectionAssignments, $slotPositions) * 100000 + $dailyOverload * 10000 + $dailyStartDelay * 1000 + $daysUsed * 100);
}

function compact_schedule_assignments(array $assigned, array $candidateOptions, array $slotPositions): array
{
    $maxPasses = min(8, count($assigned));
    for ($pass = 0; $pass < $maxPasses; $pass++) {
        $changed = false;
        foreach (array_keys($assigned) as $taskId) {
            $placement = $assigned[$taskId];
            $task = $placement['task'];
            $sectionAssignments = [];
            foreach ($assigned as $otherTaskId => $otherPlacement) {
                if ($otherTaskId === $taskId || (int) $otherPlacement['task']['section_id'] !== (int) $task['section_id']) {
                    continue;
                }
                $sectionAssignments[(int) $otherPlacement['candidate']['day']][] = $otherPlacement['candidate'];
            }
            $withCurrent = $sectionAssignments;
            $withCurrent[(int) $placement['candidate']['day']][] = $placement['candidate'];
            $bestCost = section_schedule_compactness_cost($withCurrent, $slotPositions);
            $bestCandidate = $placement['candidate'];

            foreach ($candidateOptions[$taskId] as $candidate) {
                if ((int) $candidate['room_id'] === (int) $placement['candidate']['room_id']
                    && (int) $candidate['day'] === (int) $placement['candidate']['day']
                    && (int) $candidate['slot_id'] === (int) $placement['candidate']['slot_id']) {
                    continue;
                }

                $proposal = $sectionAssignments;
                $proposal[(int) $candidate['day']][] = $candidate;
                $proposalCost = section_schedule_compactness_cost($proposal, $slotPositions);
                if ($proposalCost >= $bestCost) {
                    continue;
                }

                $available = true;
                $instructorDayHours = 0.0;
                foreach ($assigned as $otherTaskId => $otherPlacement) {
                    if ($otherTaskId === $taskId) {
                        continue;
                    }
                    $otherTask = $otherPlacement['task'];
                    $otherCandidate = $otherPlacement['candidate'];
                    if ((int) $otherTask['instructor_id'] === (int) $task['instructor_id']
                        && (int) $otherCandidate['day'] === (int) $candidate['day']) {
                        $instructorDayHours += (float) $otherCandidate['hours'];
                    }
                    if ((int) $otherCandidate['day'] !== (int) $candidate['day']) {
                        continue;
                    }
                    $sharesResource = (int) $otherTask['section_id'] === (int) $task['section_id']
                        || (int) $otherTask['instructor_id'] === (int) $task['instructor_id']
                        || (int) $otherCandidate['room_id'] === (int) $candidate['room_id'];
                    if ($sharesResource && array_intersect($otherCandidate['slot_ids'], $candidate['slot_ids']) !== []) {
                        $available = false;
                        break;
                    }
                }
                if (!$available || $instructorDayHours + (float) $candidate['hours'] > (int) $task['max_hours_day']) {
                    continue;
                }

                $bestCost = $proposalCost;
                $bestCandidate = $candidate;
            }

            if ($bestCandidate !== $placement['candidate']) {
                $assigned[$taskId]['candidate'] = $bestCandidate;
                $changed = true;
            }
        }
        if (!$changed) {
            break;
        }
    }
    return $assigned;
}

function solve_schedule(array $tasks, array $context, int $nodeLimit = 100000, array $fixedAssignments = []): array
{
    $occupied = [];
    $dailyHours = [];
    $assigned = [];
    $nodes = 0;
    $failureCounts = [];
    $deadline = microtime(true) + 20.0;
    $slotLoads = [];
    $candidateOptions = [];
    $slotPositions = [];
    foreach ($context['slots'] as $slot) {
        $slotPositions[(int) $slot['id']] = (int) $slot['slot_order'];
    }
    foreach ($fixedAssignments as $assignment) {
        $task = $assignment['task'];
        $candidate = $assignment['candidate'];
        foreach ($candidate['slot_ids'] as $slotId) {
            $occupied[occupancy_key('ROOM', (int) $candidate['room_id'], (int) $candidate['day'], (int) $slotId)] = true;
            $occupied[occupancy_key('INSTRUCTOR', (int) $task['instructor_id'], (int) $candidate['day'], (int) $slotId)] = true;
            $occupied[occupancy_key('SECTION', (int) $task['section_id'], (int) $candidate['day'], (int) $slotId)] = true;
            $slotLoads[(int) $candidate['day'] . ':' . (int) $slotId] = ($slotLoads[(int) $candidate['day'] . ':' . (int) $slotId] ?? 0) + 1;
        }
        $hoursKey = (int) $task['instructor_id'] . ':' . (int) $candidate['day'];
        $dailyHours[$hoursKey] = ($dailyHours[$hoursKey] ?? 0) + (float) $candidate['hours'];
        $occupied['__section_assignments'][(int) $task['section_id']][(int) $candidate['day']][] = $candidate;
    }
    foreach ($tasks as $task) {
        $options = [];
        foreach ($context['rooms'] as $room) {
            foreach (DAY_NAMES as $day => $dayName) {
                foreach ($context['slots'] as $slotIndex => $_slot) {
                    $window = slot_window($context['slots'], $slotIndex, (int) $task['duration_slots']);
                    if ($window === null) continue;
                    $candidate = candidate_for($task, $room, (int) $day, $window, $context, [], []);
                    if ($candidate !== null) {
                        $candidate['day_name'] = $dayName;
                        $options[] = $candidate;
                    }
                }
            }
        }
        $candidateOptions[$task['task_id']] = $options;
    }

    $search = function (array $remaining) use (&$search, &$occupied, &$dailyHours, &$assigned, &$nodes, &$failureCounts, &$slotLoads, $candidateOptions, $slotPositions, $nodeLimit, $deadline): bool {
        if ($remaining === []) {
            return true;
        }
        if (++$nodes > $nodeLimit || microtime(true) >= $deadline) {
            $failureCounts['search_limit'] = ($failureCounts['search_limit'] ?? 0) + 1;
            return false;
        }

        $bestIndex = -1;
        $bestCandidates = null;
        foreach ($remaining as $index => $task) {
            if (microtime(true) >= $deadline) {
                $failureCounts['search_limit'] = ($failureCounts['search_limit'] ?? 0) + 1;
                return false;
            }
            $candidates = [];
            foreach ($candidateOptions[$task['task_id']] as $baseCandidate) {
                $available = true;
                foreach ($baseCandidate['slot_ids'] as $slotId) {
                    if (isset($occupied[occupancy_key('ROOM', $baseCandidate['room_id'], $baseCandidate['day'], $slotId)])
                        || isset($occupied[occupancy_key('INSTRUCTOR', $task['instructor_id'], $baseCandidate['day'], $slotId)])
                        || isset($occupied[occupancy_key('SECTION', $task['section_id'], $baseCandidate['day'], $slotId)])) {
                        $available = false;
                        break;
                    }
                }
                $hoursKey = $task['instructor_id'] . ':' . $baseCandidate['day'];
                if ($available && (float) ($dailyHours[$hoursKey] ?? 0) + $baseCandidate['hours'] > (int) $task['max_hours_day']) {
                    $available = false;
                }
                if ($available) {
                    $candidate = $baseCandidate;
                    $sectionAssignments = $occupied['__section_assignments'][$task['section_id']] ?? [];
                    $withCandidate = $sectionAssignments;
                    $withCandidate[$candidate['day']][] = $candidate;
                    $candidate['cost'] += section_schedule_compactness_cost($withCandidate, $slotPositions)
                        - section_schedule_compactness_cost($sectionAssignments, $slotPositions);
                    $slotLoad = 0;
                    foreach ($candidate['slot_ids'] as $slotId) {
                        $slotLoad += (int) ($slotLoads[$candidate['day'] . ':' . $slotId] ?? 0);
                    }
                    $candidate['cost'] += $slotLoad * 10;
                    foreach ($assigned as $existing) {
                        if ((int) $existing['task']['offering_id'] === (int) $task['offering_id'] && (int) $existing['candidate']['day'] === (int) $candidate['day']) {
                            $candidate['cost'] += 4.0;
                        }
                    }
                    $candidates[] = $candidate;
                }
            }
            if ($candidates === []) {
                $failureCounts['no_candidate:' . $task['subject_name'] . ' (' . $task['section_code'] . ')'] = 1;
                return false;
            }
            usort($candidates, static fn(array $left, array $right): int => $left['cost'] <=> $right['cost']);
            if ($bestCandidates === null || count($candidates) < count($bestCandidates)) {
                $bestIndex = $index;
                $bestCandidates = $candidates;
                if (count($bestCandidates) === 1) {
                    break;
                }
            }
        }

        $task = $remaining[$bestIndex];
        $nextRemaining = $remaining;
        array_splice($nextRemaining, $bestIndex, 1);
        foreach ($bestCandidates as $candidate) {
            foreach ($candidate['slot_ids'] as $slotId) {
                $occupied[occupancy_key('ROOM', $candidate['room_id'], $candidate['day'], $slotId)] = true;
                $occupied[occupancy_key('INSTRUCTOR', $task['instructor_id'], $candidate['day'], $slotId)] = true;
                $occupied[occupancy_key('SECTION', $task['section_id'], $candidate['day'], $slotId)] = true;
                $slotLoads[$candidate['day'] . ':' . $slotId] = ($slotLoads[$candidate['day'] . ':' . $slotId] ?? 0) + 1;
            }
            $hoursKey = $task['instructor_id'] . ':' . $candidate['day'];
            $dailyHours[$hoursKey] = ($dailyHours[$hoursKey] ?? 0) + $candidate['hours'];
            $occupied['__section_assignments'][(int) $task['section_id']][(int) $candidate['day']][] = $candidate;
            $assigned[$task['task_id']] = ['task' => $task, 'candidate' => $candidate];
            if ($search($nextRemaining)) {
                return true;
            }
            unset($assigned[$task['task_id']]);
            array_pop($occupied['__section_assignments'][(int) $task['section_id']][(int) $candidate['day']]);
            if ($occupied['__section_assignments'][(int) $task['section_id']][(int) $candidate['day']] === []) {
                unset($occupied['__section_assignments'][(int) $task['section_id']][(int) $candidate['day']]);
            }
            if ($occupied['__section_assignments'][(int) $task['section_id']] === []) {
                unset($occupied['__section_assignments'][(int) $task['section_id']]);
            }
            $dailyHours[$hoursKey] -= $candidate['hours'];
            if ($dailyHours[$hoursKey] <= 0) {
                unset($dailyHours[$hoursKey]);
            }
            foreach ($candidate['slot_ids'] as $slotId) {
                unset($occupied[occupancy_key('ROOM', $candidate['room_id'], $candidate['day'], $slotId)]);
                unset($occupied[occupancy_key('INSTRUCTOR', $task['instructor_id'], $candidate['day'], $slotId)]);
                unset($occupied[occupancy_key('SECTION', $task['section_id'], $candidate['day'], $slotId)]);
                $slotLoads[$candidate['day'] . ':' . $slotId]--;
                if ($slotLoads[$candidate['day'] . ':' . $slotId] <= 0) {
                    unset($slotLoads[$candidate['day'] . ':' . $slotId]);
                }
            }
        }
        return false;
    };

    $success = $search($tasks);
    if ($success && $fixedAssignments === []) {
        $assigned = compact_schedule_assignments($assigned, $candidateOptions, $slotPositions);
    }
    return ['success' => $success, 'assignments' => array_values($assigned), 'nodes' => $nodes, 'failure_counts' => $failureCounts];
}

function generation_filter_ids(array $input, string $key): array
{
    $values = $input[$key] ?? [];
    if (!is_array($values) || count($values) > 5000) {
        throw new ApiError(422, 'The manual generation filters are invalid.');
    }
    $ids = [];
    foreach ($values as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000000]]);
        if ($id === false) {
            throw new ApiError(422, 'The manual generation filters contain an invalid selection.');
        }
        $ids[(int) $id] = true;
    }
    return array_keys($ids);
}

function generate_selected_schedule(PDO $pdo, array $user, int $termId, array $input): array
{
    $filters = [
        'program_id' => generation_filter_ids($input, 'program_ids'),
        'section_id' => generation_filter_ids($input, 'section_ids'),
        'subject_id' => generation_filter_ids($input, 'subject_ids'),
        'instructor_id' => generation_filter_ids($input, 'instructor_ids'),
    ];
    if (array_sum(array_map('count', $filters)) === 0) {
        throw new ApiError(422, 'Choose at least one program, section, subject, or instructor to generate.');
    }

    $offerings = load_offerings($pdo, $termId);
    $selectedOfferings = array_values(array_filter($offerings, static function (array $offering) use ($filters): bool {
        foreach ($filters as $field => $ids) {
            if ($ids !== [] && !in_array((int) $offering[$field], $ids, true)) {
                return false;
            }
        }
        return true;
    }));
    if ($selectedOfferings === []) {
        throw new ApiError(422, 'No active offerings match all of the selected filters.');
    }

    $selectedIds = array_fill_keys(array_map(static fn(array $offering): int => (int) $offering['id'], $selectedOfferings), true);
    $selectedTasks = build_tasks($selectedOfferings);
    $allTasks = build_tasks($offerings);
    $context = load_scheduler_context($pdo);
    $preflight = scheduler_preflight($selectedOfferings, $context);
    if ($preflight['issues'] !== []) {
        $diagnostics = ['mode' => 'manual', 'preflight_issues' => $preflight['issues'], 'warnings' => $preflight['warnings'], 'total_tasks' => count($selectedTasks), 'assigned_tasks' => 0, 'failures' => ['preflight' => count($preflight['issues'])], 'explanations' => $preflight['issues']];
        record_failed_generation($pdo, $user, $termId, $diagnostics);
        throw new ApiError(422, $preflight['issues'][0], $diagnostics);
    }

    $activeRun = active_run($pdo, $termId);
    $activeRunFingerprint = $activeRun ? schedule_run_fingerprint($pdo, (int) $activeRun['id']) : null;
    $retainedEntries = [];
    $fixedAssignments = [];
    $retainedEntryCount = 0;
    if ($activeRun) {
        $entryStmt = $pdo->prepare('SELECT se.*, co.subject_id, co.section_id, co.instructor_id, co.enrollment, co.required_meetings,
                                           s.code AS subject_code, s.name AS subject_name, s.room_type, s.required_features_json, s.duration_slots,
                                           sec.code AS section_code, i.name AS instructor_name, i.max_hours_day
                                    FROM schedule_entries se
                                    JOIN course_offerings co ON co.id = se.offering_id
                                    JOIN subjects s ON s.id = co.subject_id
                                    JOIN sections sec ON sec.id = co.section_id
                                    JOIN instructors i ON i.id = co.instructor_id
                                    WHERE se.run_id = ? AND se.offering_id NOT IN (' . implode(',', array_fill(0, count($selectedIds), '?')) . ')
                                    ORDER BY se.id');
        $entryStmt->execute(array_merge([(int) $activeRun['id']], array_keys($selectedIds)));
        $retainedEntries = $entryStmt->fetchAll();
        $occupancyStmt = $pdo->prepare('SELECT entry_id, resource_type, resource_id, day_of_week, slot_id FROM schedule_occupancy WHERE run_id = ? ORDER BY entry_id, slot_id');
        $occupancyStmt->execute([(int) $activeRun['id']]);
        $entryOccupancy = [];
        foreach ($occupancyStmt->fetchAll() as $row) {
            $entryOccupancy[(int) $row['entry_id']][] = $row;
        }
        $slotById = [];
        foreach ($context['slots'] as $slot) {
            $slotById[(int) $slot['id']] = $slot;
        }
        foreach ($retainedEntries as $entry) {
            if ($entry['status'] !== 'PUBLISHED') {
                continue;
            }
            $entryId = (int) $entry['id'];
            $roomSlots = [];
            foreach ($entryOccupancy[$entryId] ?? [] as $occupied) {
                if ($occupied['resource_type'] === 'ROOM') {
                    $roomSlots[] = (int) $occupied['slot_id'];
                }
            }
            if ($roomSlots === [] && isset($slotById[(int) $entry['slot_id']])) {
                $startIndex = array_search((int) $entry['slot_id'], array_map(static fn(array $slot): int => (int) $slot['id'], $context['slots']), true);
                $window = $startIndex === false ? null : slot_window($context['slots'], $startIndex, max(1, (int) $entry['duration_slots']));
                $roomSlots = $window === null ? [(int) $entry['slot_id']] : array_map(static fn(array $slot): int => (int) $slot['id'], $window);
            }
            $hours = 0.0;
            foreach (array_unique($roomSlots) as $slotId) {
                if (isset($slotById[$slotId])) {
                    $hours += (strtotime($slotById[$slotId]['end_time']) - strtotime($slotById[$slotId]['start_time'])) / 3600;
                }
            }
            if ($entry['start_time_override'] && $entry['end_time_override']) {
                $hours = max(0.0, (strtotime($entry['end_time_override']) - strtotime($entry['start_time_override'])) / 3600);
            }
            $task = [
                'task_id' => $entry['offering_id'] . ':' . $entry['meeting_no'],
                'offering_id' => (int) $entry['offering_id'],
                'meeting_no' => (int) $entry['meeting_no'],
                'subject_name' => $entry['subject_name'],
                'section_id' => (int) $entry['section_id'],
                'section_code' => $entry['section_code'],
                'instructor_id' => (int) $entry['instructor_id'],
                'instructor_name' => $entry['instructor_name'],
                'max_hours_day' => (int) $entry['max_hours_day'],
                'enrollment' => (int) $entry['enrollment'],
                'duration_slots' => max(1, (int) $entry['duration_slots']),
                'room_type' => $entry['room_type'],
                'required_features' => json_array($entry['required_features_json']),
            ];
            $candidate = ['room_id' => (int) $entry['room_id'], 'day' => (int) $entry['day_of_week'], 'slot_id' => (int) $entry['slot_id'], 'slot_ids' => array_values(array_unique($roomSlots)), 'hours' => $hours, 'cost' => 0.0];
            $fixedAssignments[] = ['task' => $task, 'candidate' => $candidate];
            $retainedEntryCount++;
        }
    }

    $nodeLimit = (int) ($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'generation_node_limit'")->fetchColumn() ?: 100000);
    $solution = solve_schedule($selectedTasks, $context, max(1000, min($nodeLimit, 1000000)), $fixedAssignments);
    $diagnostics = [
        'mode' => 'manual',
        'filters' => $filters,
        'selected_offerings' => count($selectedOfferings),
        'preserved_entries' => $retainedEntryCount,
        'hard_constraints' => ['room_capacity', 'room_type_and_features', 'room_overlap', 'instructor_overlap', 'section_overlap', 'instructor_daily_hours', 'instructor_room_section_availability', 'required_meetings'],
        'warnings' => $preflight['warnings'],
        'total_tasks' => count($allTasks),
        'assigned_tasks' => $retainedEntryCount + count($solution['assignments']),
        'generated_tasks' => count($selectedTasks),
        'search_nodes' => $solution['nodes'],
        'failures' => $solution['failure_counts'],
    ];
    if (!$solution['success']) {
        $diagnostics['explanations'] = explain_unscheduled_tasks($selectedTasks, $context);
        record_failed_generation($pdo, $user, $termId, $diagnostics);
        throw new ApiError(422, 'The selected offerings could not be scheduled without conflicts. The previous published schedule was preserved.', $diagnostics);
    }

    $pdo->beginTransaction();
    try {
        $termLock = $pdo->prepare('SELECT id FROM academic_terms WHERE id = ? FOR UPDATE');
        $termLock->execute([$termId]);
        if (!$termLock->fetchColumn()) {
            throw new RuntimeException('The academic term no longer exists.');
        }
        if ($activeRun) {
            $pdo->exec('LOCK TABLE schedule_entries IN SHARE MODE');
            $pdo->exec('LOCK TABLE schedule_occupancy IN SHARE MODE');
        }
        $currentRun = active_run($pdo, $termId);
        if ((int) ($currentRun['id'] ?? 0) !== (int) ($activeRun['id'] ?? 0)) {
            throw new RuntimeException('The published schedule changed during generation.');
        }
        if ($activeRun && schedule_run_fingerprint($pdo, (int) $activeRun['id']) !== $activeRunFingerprint) {
            throw new RuntimeException('The published schedule entries changed during generation.');
        }
        if ($activeRun) {
            $archive = $pdo->prepare("UPDATE schedule_runs SET status = 'ARCHIVED' WHERE id = ? AND status = 'PUBLISHED'");
            $archive->execute([(int) $activeRun['id']]);
            if ($archive->rowCount() !== 1) {
                throw new RuntimeException('The published schedule could not be archived.');
            }
        }
        $runStmt = $pdo->prepare("INSERT INTO schedule_runs (term_id, created_by, status, total_tasks, assigned_tasks, diagnostics_json) VALUES (?, ?, 'RUNNING', ?, ?, ?)");
        $runStmt->execute([$termId, (int) $user['id'], count($allTasks), $diagnostics['assigned_tasks'], encode_json($diagnostics)]);
        $runId = db_insert_id($pdo, 'schedule_runs');
        $entryInsert = $pdo->prepare('INSERT INTO schedule_entries (run_id, offering_id, meeting_no, room_id, day_of_week, slot_id, start_time_override, end_time_override, room_fit_override, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $occupancyInsert = $pdo->prepare('INSERT INTO schedule_occupancy (run_id, entry_id, resource_type, resource_id, day_of_week, slot_id) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($retainedEntries as $entry) {
            $entryInsert->execute([$runId, (int) $entry['offering_id'], (int) $entry['meeting_no'], (int) $entry['room_id'], (int) $entry['day_of_week'], (int) $entry['slot_id'], $entry['start_time_override'], $entry['end_time_override'], (int) $entry['room_fit_override'], $entry['status']]);
            $newEntryId = db_insert_id($pdo, 'schedule_entries');
            foreach ($entryOccupancy[(int) $entry['id']] ?? [] as $occupied) {
                $occupancyInsert->execute([$runId, $newEntryId, $occupied['resource_type'], (int) $occupied['resource_id'], (int) $occupied['day_of_week'], (int) $occupied['slot_id']]);
            }
        }
        foreach ($solution['assignments'] as $assignment) {
            $task = $assignment['task'];
            $candidate = $assignment['candidate'];
            $entryInsert->execute([$runId, $task['offering_id'], $task['meeting_no'], $candidate['room_id'], $candidate['day'], $candidate['slot_id'], null, null, 0, 'PUBLISHED']);
            $newEntryId = db_insert_id($pdo, 'schedule_entries');
            foreach ($candidate['slot_ids'] as $slotId) {
                foreach ([['ROOM', $candidate['room_id']], ['INSTRUCTOR', $task['instructor_id']], ['SECTION', $task['section_id']]] as [$resourceType, $resourceId]) {
                    $occupancyInsert->execute([$runId, $newEntryId, $resourceType, $resourceId, $candidate['day'], $slotId]);
                }
            }
        }
        $publish = $pdo->prepare("UPDATE schedule_runs SET status = 'PUBLISHED' WHERE id = ?");
        $publish->execute([$runId]);
        audit($pdo, $user, 'GENERATE_SELECTED_SCHEDULE', 'schedule_run', $runId, $diagnostics);
        $pdo->commit();
        return ['run_id' => $runId, 'diagnostics' => $diagnostics];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new ApiError(409, 'The selected schedule could not be committed because the data changed during generation. Try again.');
    }
}

function generate_schedule(PDO $pdo, array $user, int $termId): array
{
    $offerings = load_offerings($pdo, $termId);
    if ($offerings === []) {
        $diagnostics = ['total_tasks' => 0, 'assigned_tasks' => 0, 'failures' => ['no_offerings' => 1], 'explanations' => ['No active course offerings exist for the selected term.']];
        record_failed_generation($pdo, $user, $termId, $diagnostics);
        throw new ApiError(422, 'No active course offerings exist for the selected term.', $diagnostics);
    }
    $context = load_scheduler_context($pdo);
    $preflight = scheduler_preflight($offerings, $context);
    if ($preflight['issues'] !== []) {
        $diagnostics = ['preflight_issues' => $preflight['issues'], 'warnings' => $preflight['warnings'], 'total_tasks' => count(build_tasks($offerings)), 'assigned_tasks' => 0, 'failures' => ['preflight' => count($preflight['issues'])], 'explanations' => $preflight['issues']];
        record_failed_generation($pdo, $user, $termId, $diagnostics);
        throw new ApiError(422, $preflight['issues'][0], $diagnostics);
    }
    $nodeLimit = (int) ($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'generation_node_limit'")->fetchColumn() ?: 100000);
    $tasks = build_tasks($offerings);
    $solution = solve_schedule($tasks, $context, max(1000, min($nodeLimit, 1000000)));
    $diagnostics = ['hard_constraints' => ['room_capacity', 'room_type_and_features', 'room_overlap', 'instructor_overlap', 'section_overlap', 'instructor_daily_hours', 'instructor_room_section_availability', 'required_meetings'], 'warnings' => $preflight['warnings'], 'total_tasks' => count($tasks), 'assigned_tasks' => count($solution['assignments']), 'search_nodes' => $solution['nodes'], 'failures' => $solution['failure_counts']];
    if (!$solution['success']) {
        $diagnostics['explanations'] = explain_unscheduled_tasks($tasks, $context);
        record_failed_generation($pdo, $user, $termId, $diagnostics);
        throw new ApiError(422, 'A complete conflict-free schedule could not be generated. The previous published schedule was preserved.', $diagnostics);
    }

    $pdo->beginTransaction();
    try {
        $termLock = $pdo->prepare('SELECT id FROM academic_terms WHERE id = ? FOR UPDATE');
        $termLock->execute([$termId]);
        if (!$termLock->fetchColumn()) {
            throw new RuntimeException('The academic term no longer exists.');
        }
        $archive = $pdo->prepare("UPDATE schedule_runs SET status = 'ARCHIVED' WHERE term_id = ? AND status = 'PUBLISHED'");
        $archive->execute([$termId]);
        $run = $pdo->prepare("INSERT INTO schedule_runs (term_id, created_by, status, total_tasks, assigned_tasks, diagnostics_json) VALUES (?, ?, 'RUNNING', ?, ?, ?)");
        $run->execute([$termId, (int) $user['id'], count($tasks), count($solution['assignments']), encode_json($diagnostics)]);
        $runId = db_insert_id($pdo, 'schedule_runs');
        $entry = $pdo->prepare('INSERT INTO schedule_entries (run_id, offering_id, meeting_no, room_id, day_of_week, slot_id) VALUES (?, ?, ?, ?, ?, ?)');
        $occupancy = $pdo->prepare('INSERT INTO schedule_occupancy (run_id, entry_id, resource_type, resource_id, day_of_week, slot_id) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($solution['assignments'] as $assignment) {
            $task = $assignment['task'];
            $candidate = $assignment['candidate'];
            $entry->execute([$runId, $task['offering_id'], $task['meeting_no'], $candidate['room_id'], $candidate['day'], $candidate['slot_id']]);
            $entryId = db_insert_id($pdo, 'schedule_entries');
            foreach ($candidate['slot_ids'] as $slotId) {
                $occupancy->execute([$runId, $entryId, 'ROOM', $candidate['room_id'], $candidate['day'], $slotId]);
                $occupancy->execute([$runId, $entryId, 'INSTRUCTOR', $task['instructor_id'], $candidate['day'], $slotId]);
                $occupancy->execute([$runId, $entryId, 'SECTION', $task['section_id'], $candidate['day'], $slotId]);
            }
        }
        $publish = $pdo->prepare("UPDATE schedule_runs SET status = 'PUBLISHED' WHERE id = ?");
        $publish->execute([$runId]);
        audit($pdo, $user, 'GENERATE_SCHEDULE', 'schedule_run', $runId, $diagnostics);
        $pdo->commit();
        return ['run_id' => $runId, 'diagnostics' => $diagnostics];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new ApiError(409, 'The generated schedule could not be committed because the data changed during generation. Try again.');
    }
}

function record_failed_generation(PDO $pdo, array $user, int $termId, array $diagnostics): void
{
    $stmt = $pdo->prepare("INSERT INTO schedule_runs (term_id, created_by, status, total_tasks, assigned_tasks, diagnostics_json) VALUES (?, ?, 'FAILED', ?, ?, ?)");
    $stmt->execute([$termId, (int) $user['id'], (int) ($diagnostics['total_tasks'] ?? 0), (int) ($diagnostics['assigned_tasks'] ?? 0), encode_json($diagnostics)]);
}

function active_entry_context(PDO $pdo, int $termId): array
{
    $run = active_run($pdo, $termId);
    if (!$run) {
        return ['run' => null, 'entries' => []];
    }
    $stmt = $pdo->prepare('SELECT se.*, co.subject_id, co.section_id, co.instructor_id, co.enrollment, s.room_type, s.required_features_json, s.duration_slots, i.max_hours_day FROM schedule_entries se JOIN schedule_runs sr ON sr.id = se.run_id AND sr.status = \'PUBLISHED\' JOIN course_offerings co ON co.id = se.offering_id JOIN subjects s ON s.id = co.subject_id JOIN instructors i ON i.id = co.instructor_id WHERE se.run_id = ? AND se.status = \'PUBLISHED\'');
    $stmt->execute([(int) $run['id']]);
    $entries = $stmt->fetchAll();
    $occupancyStmt = $pdo->prepare("SELECT entry_id, slot_id FROM schedule_occupancy WHERE run_id = ? AND resource_type = 'ROOM'");
    $occupancyStmt->execute([(int) $run['id']]);
    $occupiedSlots = [];
    foreach ($occupancyStmt->fetchAll() as $row) {
        $occupiedSlots[(int) $row['entry_id']][] = (int) $row['slot_id'];
    }
    foreach ($entries as &$entry) {
        $entry['occupied_slot_ids'] = $occupiedSlots[(int) $entry['id']] ?? [];
    }
    unset($entry);
    return ['run' => $run, 'entries' => $entries];
}

function validate_manual_candidate(PDO $pdo, int $termId, int $offeringId, int $roomId, int $day, int $slotId, ?int $ignoreEntryId = null, bool $requestProposal = false, ?array $requestedTime = null, bool $allowRequirementOverrides = false): array
{
    $ignoreEntryId = $ignoreEntryId !== null && (int) $ignoreEntryId > 0 ? (int) $ignoreEntryId : null;
    if (!isset(DAY_NAMES[$day])) {
        throw new ApiError(422, 'Schedules may only be placed Monday through Friday.');
    }
    $stmt = $pdo->prepare('SELECT co.*, s.room_type, s.required_features_json, s.duration_slots, s.name AS subject_name, sec.code AS section_code, sec.student_count, i.name AS instructor_name, i.max_hours_day FROM course_offerings co JOIN subjects s ON s.id = co.subject_id JOIN sections sec ON sec.id = co.section_id JOIN instructors i ON i.id = co.instructor_id WHERE co.id = ? AND co.term_id = ? AND co.status = \'ACTIVE\'');
    $stmt->execute([$offeringId, $termId]);
    $offering = $stmt->fetch();
    if (!$offering) {
        throw new ApiError(404, 'Course offering not found.');
    }
    $context = load_scheduler_context($pdo);
    $room = null;
    foreach ($context['rooms'] as $candidateRoom) {
        if ((int) $candidateRoom['id'] === $roomId) {
            $room = $candidateRoom;
            break;
        }
    }
    if (!$room) {
        throw new ApiError(422, 'Room is not available.');
    }
    if (!$requestProposal && !$allowRequirementOverrides && (int) $room['capacity'] < (int) $offering['enrollment']) {
        throw new ApiError(409, 'The selected room does not have enough seats for this class.');
    }
    if (!$requestProposal && !$allowRequirementOverrides && (string) $room['room_type'] !== (string) $offering['room_type']) {
        throw new ApiError(409, sprintf('The selected room is a %s room, but this class requires a %s room.', strtolower((string) $room['room_type']), strtolower((string) $offering['room_type'])));
    }
    if (!$requestProposal && !$allowRequirementOverrides) {
        $roomFeatures = array_map('strtolower', array_map('strval', $room['features'] ?? []));
        $missingFeatures = array_values(array_filter(json_array($offering['required_features_json']), static fn($feature): bool => !in_array(strtolower((string) $feature), $roomFeatures, true)));
        if ($missingFeatures !== []) {
            throw new ApiError(409, 'The selected room is missing required features: ' . implode(', ', $missingFeatures) . '.');
        }
    }
    $slotIndex = null;
    $window = null;
    $actualHours = null;
    if ($requestedTime !== null) {
        [$requestedStart, $requestedEnd] = $requestedTime;
        $window = array_values(array_filter($context['slots'], static fn(array $slot): bool =>
            substr((string) $slot['start_time'], 0, 5) < $requestedEnd
            && substr((string) $slot['end_time'], 0, 5) > $requestedStart
        ));
        if ($window === []) {
            throw new ApiError(422, 'The requested time does not overlap a configured timetable slot.');
        }
        if (substr((string) $window[0]['start_time'], 0, 5) > $requestedStart
            || substr((string) $window[count($window) - 1]['end_time'], 0, 5) < $requestedEnd) {
            throw new ApiError(422, 'The requested time must fit fully within configured timetable slots.');
        }
        for ($index = 1; $index < count($window); $index++) {
            if (substr((string) $window[$index - 1]['end_time'], 0, 5) !== substr((string) $window[$index]['start_time'], 0, 5)) {
                throw new ApiError(422, 'The requested time cannot span a gap in the timetable.');
            }
        }
        if (array_filter($window, static fn(array $slot): bool => (int) $slot['slot_order'] === 6)) {
            throw new ApiError(422, 'Classes cannot be scheduled during the 12:00 PM - 1:00 PM lunch break.');
        }
        $actualHours = (strtotime($requestedEnd) - strtotime($requestedStart)) / 3600;
        if (!$requestProposal && !$allowRequirementOverrides && abs($actualHours - (int) $offering['duration_slots']) > 0.001) {
            throw new ApiError(422, sprintf('This subject requires a %d-hour meeting.', (int) $offering['duration_slots']));
        }
        $slotId = (int) $window[0]['id'];
    } else {
        foreach ($context['slots'] as $index => $slot) {
            if ((int) $slot['id'] === $slotId) {
                $slotIndex = $index;
                break;
            }
        }
        if ($slotIndex === null) {
            throw new ApiError(422, 'Time slot is invalid.');
        }
        $window = slot_window($context['slots'], $slotIndex, max(1, (int) $offering['duration_slots']));
        if ($window === null) {
            throw new ApiError(422, 'The subject duration does not fit within the selected day.');
        }
    }
    $active = active_entry_context($pdo, $termId);
    $occupied = [];
    $dailyHours = [];
    foreach ($active['entries'] as $entry) {
        if ($ignoreEntryId !== null && (int) $entry['id'] === $ignoreEntryId) {
            continue;
        }
        $entryWindow = [];
        if ($entry['occupied_slot_ids'] !== []) {
            foreach ($entry['occupied_slot_ids'] as $occupiedSlotId) {
                foreach ($context['slots'] as $slot) {
                    if ((int) $slot['id'] === $occupiedSlotId) {
                        $entryWindow[] = $slot;
                        break;
                    }
                }
            }
        } else {
            $entrySlotIndex = null;
            foreach ($context['slots'] as $index => $slot) {
                if ((int) $slot['id'] === (int) $entry['slot_id']) {
                    $entrySlotIndex = $index;
                    break;
                }
            }
            if ($entrySlotIndex !== null) {
                $entryWindow = slot_window($context['slots'], $entrySlotIndex, max(1, (int) $entry['duration_slots'])) ?: [];
            }
        }
        $entryHours = 0.0;
        foreach ($entryWindow as $entrySlot) {
            $slotValue = (int) $entrySlot['id'];
            $occupied[occupancy_key('ROOM', (int) $entry['room_id'], (int) $entry['day_of_week'], $slotValue)] = true;
            $occupied[occupancy_key('INSTRUCTOR', (int) $entry['instructor_id'], (int) $entry['day_of_week'], $slotValue)] = true;
            $occupied[occupancy_key('SECTION', (int) $entry['section_id'], (int) $entry['day_of_week'], $slotValue)] = true;
            $entryHours += (strtotime($entrySlot['end_time']) - strtotime($entrySlot['start_time'])) / 3600;
        }
        if ($entryWindow !== [] && isset(DAY_NAMES[(int) $entry['day_of_week']])) {
            $entryStart = (string) ($entry['start_time_override'] ?: $entryWindow[0]['start_time']);
            $entryEnd = (string) ($entry['end_time_override'] ?: $entryWindow[count($entryWindow) - 1]['end_time']);
            $entryHours = max(0, (strtotime($entryEnd) - strtotime($entryStart)) / 3600);
            $hoursKey = (int) $entry['instructor_id'] . ':' . (int) $entry['day_of_week'];
            $dailyHours[$hoursKey] = ($dailyHours[$hoursKey] ?? 0) + $entryHours;
        }
    }
    $task = ['enrollment' => (int) $offering['enrollment'], 'room_type' => $offering['room_type'], 'required_features' => json_array($offering['required_features_json']), 'instructor_id' => (int) $offering['instructor_id'], 'section_id' => (int) $offering['section_id'], 'max_hours_day' => (int) $offering['max_hours_day']];
    $candidate = candidate_for($task, $room, $day, $window, $context, $occupied, $dailyHours, $requestProposal, $actualHours, $allowRequirementOverrides);
    if ($candidate === null) {
        if ($requestProposal) {
            throw new ApiError(409, 'The selected time conflicts with an existing room, instructor, or section schedule.');
        }
        throw new ApiError(409, 'The selected time conflicts with a schedule, availability rule, or instructor daily-hour limit.');
    }
    return ['offering' => $offering, 'candidate' => $candidate, 'run' => $active['run']];
}

function add_manual_schedule_entry(PDO $pdo, array $user, array $input): array
{
    $termId = active_term_id($pdo);
    $offeringId = input_int($input, 'offering_id', 1, 100000000);
    $roomId = input_int($input, 'room_id', 1, 100000000);
    $day = input_int($input, 'day_of_week', 1, 5);
    [$startTime, $endTime] = parse_manual_schedule_times($input);

    $pdo->beginTransaction();
    try {
        $runStmt = $pdo->prepare("SELECT id, total_tasks, diagnostics_json FROM schedule_runs WHERE term_id = ? AND status = 'PUBLISHED' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $runStmt->execute([$termId]);
        $run = $runStmt->fetch();
        if (!$run) throw new ApiError(409, 'Generate a schedule before adding a class.');

        $meetingStmt = $pdo->prepare('SELECT COALESCE(MAX(meeting_no), 0) + 1 FROM schedule_entries WHERE run_id = ? AND offering_id = ?');
        $meetingStmt->execute([(int) $run['id'], $offeringId]);
        $meetingNo = (int) $meetingStmt->fetchColumn();
        $validated = validate_manual_candidate($pdo, $termId, $offeringId, $roomId, $day, 0, null, false, [$startTime, $endTime], true);
        $candidate = $validated['candidate'];
        $slotId = (int) $candidate['slot_id'];

        $insert = $pdo->prepare("INSERT INTO schedule_entries (run_id, offering_id, meeting_no, room_id, day_of_week, slot_id, start_time_override, end_time_override, room_fit_override, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'PUBLISHED')");
        $insert->execute([(int) $run['id'], $offeringId, $meetingNo, $roomId, $day, $slotId, $startTime, $endTime]);
        $entryId = db_insert_id($pdo, 'schedule_entries');
        $occupancy = $pdo->prepare('INSERT INTO schedule_occupancy (run_id, entry_id, resource_type, resource_id, day_of_week, slot_id) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($candidate['slot_ids'] as $occupiedSlot) {
            foreach ([
                ['ROOM', $roomId],
                ['INSTRUCTOR', (int) $validated['offering']['instructor_id']],
                ['SECTION', (int) $validated['offering']['section_id']],
            ] as [$resourceType, $resourceId]) {
                $occupancy->execute([(int) $run['id'], $entryId, $resourceType, $resourceId, $day, (int) $occupiedSlot]);
            }
        }

        $assignedStmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_entries WHERE run_id = ? AND status = 'PUBLISHED'");
        $assignedStmt->execute([(int) $run['id']]);
        $assigned = (int) $assignedStmt->fetchColumn();
        $diagnostics = json_decode((string) $run['diagnostics_json'], true);
        $diagnostics = is_array($diagnostics) ? $diagnostics : [];
        $diagnostics['assigned_tasks'] = $assigned;
        $totalTasks = schedule_run_task_total($pdo, (int) $run['id'], $termId);
        $diagnostics['total_tasks'] = $totalTasks;
        $updateRun = $pdo->prepare('UPDATE schedule_runs SET total_tasks = ?, assigned_tasks = ?, diagnostics_json = ? WHERE id = ?');
        $updateRun->execute([$totalTasks, $assigned, encode_json($diagnostics), (int) $run['id']]);

        audit($pdo, $user, 'ADD_SCHEDULE_ENTRY', 'schedule_entry', $entryId, [
            'offering_id' => $offeringId,
            'room_id' => $roomId,
            'day' => $day,
            'slot_id' => $slotId,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
        $pdo->commit();
        return ['id' => $entryId];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof ApiError) throw $error;
        error_log('EasySched manual schedule add error: ' . $error->getMessage());
        throw new ApiError(409, 'The class could not be added. The schedule may have changed; refresh and try again.');
    }
}

function parse_manual_schedule_times(array $input): array
{
    $start = str_replace(':', '', input_string($input, 'start_time', 5));
    $startPeriod = strtoupper(input_string($input, 'start_period', 2));
    $end = str_replace(':', '', input_string($input, 'end_time', 5));
    $endPeriod = strtoupper(input_string($input, 'end_period', 2));
    if (!preg_match('/^(0[1-9]|1[0-2])[0-5][0-9]$/', $start) || !in_array($startPeriod, ['AM', 'PM'], true)
        || !preg_match('/^(0[1-9]|1[0-2])[0-5][0-9]$/', $end) || !in_array($endPeriod, ['AM', 'PM'], true)) {
        throw new ApiError(422, 'Use valid 12-hour times in HH:MM format with AM or PM.');
    }
    $toMinutes = static function (string $value, string $period): int {
        $hour = (int) substr($value, 0, 2);
        $minute = (int) substr($value, 2, 2);
        if ($period === 'AM' && $hour === 12) $hour = 0;
        if ($period === 'PM' && $hour !== 12) $hour += 12;
        return $hour * 60 + $minute;
    };
    $startMinutes = $toMinutes($start, $startPeriod);
    $endMinutes = $toMinutes($end, $endPeriod);
    if ($endMinutes <= $startMinutes) {
        throw new ApiError(422, 'End time must be later than start time.');
    }
    return [
        sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60),
        sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60),
    ];
}

function schedule_run_task_total(PDO $pdo, int $runId, int $termId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(co.required_meetings, COALESCE(entry_counts.scheduled_count, 0))), 0)
                           FROM course_offerings co
                           LEFT JOIN (
                               SELECT offering_id, COUNT(*) AS scheduled_count
                               FROM schedule_entries
                               WHERE run_id = ? AND status = 'PUBLISHED'
                               GROUP BY offering_id
                           ) entry_counts ON entry_counts.offering_id = co.id
                           WHERE co.term_id = ? AND co.status = 'ACTIVE'");
    $stmt->execute([$runId, $termId]);
    return (int) $stmt->fetchColumn();
}

function parse_room_request_times(array $input): array
{
    $start = str_replace(':', '', input_string($input, 'start_time', 5));
    $startPeriod = strtoupper(input_string($input, 'start_period', 2));
    $end = str_replace(':', '', input_string($input, 'end_time', 5));
    $endPeriod = strtoupper(input_string($input, 'end_period', 2));
    if (!preg_match('/^(0[1-9]|1[0-2])[0-5][0-9]$/', $start) || !in_array($startPeriod, ['AM', 'PM'], true)
        || !preg_match('/^(0[1-9]|1[0-2])[0-5][0-9]$/', $end) || !in_array($endPeriod, ['AM', 'PM'], true)) {
        throw new ApiError(422, 'Use valid 12-hour times in HH:MM format with AM or PM.');
    }
    $toMinutes = static function (string $value, string $period): int {
        $hour = (int) substr($value, 0, 2);
        $minute = (int) substr($value, 2, 2);
        if ($period === 'AM' && $hour === 12) $hour = 0;
        if ($period === 'PM' && $hour !== 12) $hour += 12;
        return $hour * 60 + $minute;
    };
    $startMinutes = $toMinutes($start, $startPeriod);
    $endMinutes = $toMinutes($end, $endPeriod);
    if ($endMinutes <= $startMinutes) {
        throw new ApiError(422, 'End time must be later than start time.');
    }

    $date = input_string($input, 'request_date', 10, false);
    if ($date === '' && preg_match('/^\[REQUEST_DATE:(\d{4}-\d{2}-\d{2})\]/', (string) ($input['note'] ?? ''), $match)) {
        $date = $match[1];
    }
    $timezone = new DateTimeZone('Asia/Manila');
    $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$dateValue || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $dateValue->format('Y-m-d') !== $date) {
        throw new ApiError(422, 'Choose a valid exact date for the room request.');
    }
    $today = new DateTimeImmutable('today', $timezone);
    $tomorrow = $today->modify('+1 day');
    if ($dateValue < $tomorrow || $dateValue->format('Y') !== $today->format('Y')) {
        throw new ApiError(422, 'Choose a date at least one day ahead within the current year.');
    }
    $day = (int) $dateValue->format('N');
    if ($day < 1 || $day > 5 || input_int($input, 'day_of_week', 1, 5) !== $day) {
        throw new ApiError(422, 'The selected day does not match the exact request date.');
    }

    return [
        'date' => $date,
        'day' => $day,
        'start' => sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60),
        'end' => sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60),
    ];
}

function room_request_conflicts(PDO $pdo, int $roomId, int $instructorId, int $sectionId, int $offeringId, int $termId, int $day, string $date, string $start, string $end, ?int $excludeRequestId = null): array
{
    $conflicts = [];
    $schedule = $pdo->prepare("SELECT co.id AS offering_id, se.room_id, co.instructor_id, co.section_id, s.name AS subject_name, COALESCE(se.start_time_override, ts.start_time) AS start_time, COALESCE(se.end_time_override, (SELECT ts_end.end_time FROM time_slots ts_end WHERE ts_end.slot_order = ts.slot_order + s.duration_slots - 1), ts.end_time) AS end_time FROM schedule_entries se JOIN schedule_runs sr ON sr.id = se.run_id AND sr.status = 'PUBLISHED' JOIN course_offerings co ON co.id = se.offering_id JOIN subjects s ON s.id = co.subject_id JOIN time_slots ts ON ts.id = se.slot_id WHERE (se.room_id = ? OR co.instructor_id = ? OR co.section_id = ?) AND co.id <> ? AND se.day_of_week = ? AND se.status = 'PUBLISHED' AND co.term_id = ?");
    $schedule->execute([$roomId, $instructorId, $sectionId, $offeringId, $day, $termId]);
    foreach ($schedule->fetchAll() as $booking) {
        if ($booking['start_time'] < $end && $booking['end_time'] > $start) {
            $resource = (int) $booking['room_id'] === $roomId ? 'Room' : ((int) $booking['instructor_id'] === $instructorId ? 'Instructor' : 'Class section');
            $key = $resource . '|' . $booking['start_time'] . '|' . $booking['end_time'];
            $conflicts[$key] = ['start_time' => $booking['start_time'], 'end_time' => $booking['end_time'], 'resource' => $resource, 'description' => $resource . ' conflict: ' . $booking['subject_name']];
        }
    }

    $requests = $pdo->prepare("SELECT sr.id, sr.room_id, sr.instructor_id, co.section_id, sr.status, COALESCE(s.name, 'Other room request') AS subject_name, ts.start_time, COALESCE(sr.requested_end_time, ts.end_time) AS end_time FROM schedule_requests sr LEFT JOIN course_offerings co ON co.id = sr.offering_id LEFT JOIN subjects s ON s.id = co.subject_id JOIN time_slots ts ON ts.id = sr.slot_id WHERE sr.request_type IN ('ROOM', 'OTHER') AND (sr.room_id = ? OR sr.instructor_id = ? OR co.section_id = ?) AND sr.term_id = ? AND sr.day_of_week = ? AND sr.status IN ('PENDING', 'APPROVED') AND (sr.request_date = ? OR (sr.request_date IS NULL AND sr.note LIKE ?)) AND (CAST(? AS INTEGER) IS NULL OR sr.id <> ?)");
    $requests->execute([$roomId, $instructorId, $sectionId, $termId, $day, $date, '[REQUEST_DATE:' . $date . ']%', $excludeRequestId, $excludeRequestId]);
    foreach ($requests->fetchAll() as $request) {
        if ($request['start_time'] < $end && $request['end_time'] > $start) {
            $resource = (int) $request['room_id'] === $roomId ? 'Room' : ((int) $request['instructor_id'] === $instructorId ? 'Instructor' : 'Class section');
            $key = $resource . '|' . $request['start_time'] . '|' . $request['end_time'];
            $conflicts[$key] ??= ['start_time' => $request['start_time'], 'end_time' => $request['end_time'], 'resource' => $resource, 'description' => ucfirst(strtolower($request['status'])) . ' ' . strtolower($resource) . ' request: ' . $request['subject_name']];
        }
    }

    $conflicts = array_values($conflicts);
    usort($conflicts, static fn(array $left, array $right): int => strcmp($left['start_time'], $right['start_time']));
    return $conflicts;
}

function check_room_availability(PDO $pdo, array $user, array $input): array
{
    $requestType = strtoupper((string) ($input['request_type'] ?? 'ROOM'));
    if (!in_array($requestType, ['ROOM', 'OTHER'], true)) {
        throw new ApiError(422, 'Invalid room request type.');
    }
    $isOther = $requestType === 'OTHER';
    $purpose = trim((string) ($input['purpose'] ?? ''));
    if ($isOther && ($purpose === '' || strlen($purpose) > 300)) {
        throw new ApiError(422, 'Enter a purpose of no more than 300 characters for this room request.');
    }
    $offeringId = $isOther ? null : input_int($input, 'offering_id', 1, 100000000);
    $roomId = input_int($input, 'room_id', 1, 100000000);
    $slotId = input_int($input, 'slot_id', 1, 100000000);
    $times = parse_room_request_times($input);
    $termId = active_term_id($pdo);

    if (empty($user['instructor_id'])) {
        throw new ApiError(403, 'Your account is not linked to a faculty record.');
    }
    $sectionId = 0;
    if (!$isOther) {
        $offering = $pdo->prepare("SELECT id, instructor_id, section_id FROM course_offerings WHERE id = ? AND term_id = ? AND instructor_id = ? AND status = 'ACTIVE'");
        $offering->execute([$offeringId, $termId, (int) $user['instructor_id']]);
        $offeringRecord = $offering->fetch();
        if (!$offeringRecord) {
            throw new ApiError(404, 'Class assignment not found.');
        }
        $sectionId = (int) $offeringRecord['section_id'];
    }
    $room = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND active = 1');
    $room->execute([$roomId]);
    if (!$room->fetchColumn()) {
        throw new ApiError(404, 'Room not found.');
    }

    $slot = $pdo->prepare('SELECT start_time FROM time_slots WHERE id = ?');
    $slot->execute([$slotId]);
    if ($slot->fetchColumn() !== $times['start']) {
        throw new ApiError(422, 'The selected start time must match a configured timetable slot.');
    }
    $slots = $pdo->query('SELECT start_time, end_time FROM time_slots ORDER BY slot_order')->fetchAll();
    $window = array_values(array_filter($slots, static fn(array $timeSlot): bool => $timeSlot['start_time'] < $times['end'] && $timeSlot['end_time'] > $times['start']));
    if ($window === [] || $window[0]['start_time'] !== $times['start'] || $window[count($window) - 1]['end_time'] !== $times['end']) {
        throw new ApiError(422, 'The requested time must fit fully within configured timetable slots.');
    }
    for ($index = 1; $index < count($window); $index++) {
        if ($window[$index - 1]['end_time'] !== $window[$index]['start_time']) {
            throw new ApiError(422, 'The requested time cannot span a gap in the timetable.');
        }
    }

    $conflicts = room_request_conflicts($pdo, $roomId, (int) $user['instructor_id'], $sectionId, $offeringId ?? 0, $termId, $times['day'], $times['date'], $times['start'], $times['end']);
    $run = active_run($pdo, $termId);
    $ignoreEntryId = null;
    if ($run !== null) {
        $entry = $pdo->prepare("SELECT id FROM schedule_entries WHERE run_id = ? AND offering_id = ? AND status = 'PUBLISHED' ORDER BY id DESC LIMIT 1");
        $entry->execute([(int) $run['id'], $offeringId]);
        $entryId = $entry->fetchColumn();
        if ($entryId !== false) {
            $ignoreEntryId = (int) $entryId;
        }
    }
    if (!$isOther) {
        try {
            validate_manual_candidate($pdo, $termId, $offeringId, $roomId, $times['day'], $slotId, $ignoreEntryId, true, [$times['start'], $times['end']]);
        } catch (ApiError $error) {
            if ($error->status !== 409) {
                throw $error;
            }
            if ($conflicts === []) {
                $conflicts[] = ['start_time' => $times['start'], 'end_time' => $times['end'], 'resource' => 'Instructor or class section', 'description' => $error->getMessage()];
            }
        }
    }
    return ['available' => $conflicts === [], 'conflicts' => $conflicts, 'request_start_time' => $times['start'], 'requested_end_time' => $times['end']];
}

function validate_room_request_submission(PDO $pdo, array $input, array $user): void
{
    $availability = check_room_availability($pdo, $user, $input);
    if (!$availability['available']) {
        throw new ApiError(409, 'The room, instructor, or class section is occupied for part of this time.', ['conflicts' => $availability['conflicts']]);
    }
}

function available_rooms_for_request(PDO $pdo, array $user, array $input): array
{
    $rooms = $pdo->query('SELECT id, code, name, room_type FROM rooms WHERE active = 1 ORDER BY code')->fetchAll();
    $available = [];
    $blockers = [];
    foreach ($rooms as $room) {
        $roomInput = $input;
        $roomInput['room_id'] = (int) $room['id'];
        $result = check_room_availability($pdo, $user, $roomInput);
        $roomBlocked = false;
        foreach ($result['conflicts'] as $conflict) {
            if ($conflict['resource'] === 'Room') {
                $roomBlocked = true;
                continue;
            }
            $key = implode('|', [$conflict['resource'], $conflict['start_time'], $conflict['end_time'], $conflict['description']]);
            $blockers[$key] = $conflict;
        }
        if (!$roomBlocked) {
            $available[] = $room;
        }
    }
    return ['rooms' => $available, 'request_blockers' => array_values($blockers)];
}

function create_room_request(PDO $pdo, array $user, array $input): array
{
    $requestType = strtoupper((string) ($input['request_type'] ?? 'ROOM'));
    if (!in_array($requestType, ['ROOM', 'OTHER'], true)) {
        throw new ApiError(422, 'Invalid room request type.');
    }
    $isOther = $requestType === 'OTHER';
    $offeringId = $isOther ? null : input_int($input, 'offering_id', 1, 100000000);
    $note = trim((string) ($isOther ? ($input['purpose'] ?? '') : ($input['note'] ?? '')));
    if ($isOther && ($note === '' || strlen($note) > 300)) {
        throw new ApiError(422, 'Enter a purpose of no more than 300 characters for this room request.');
    }
    $roomId = input_int($input, 'room_id', 1, 100000000);
    $slotId = input_int($input, 'slot_id', 1, 100000000);
    if (empty($user['instructor_id'])) {
        throw new ApiError(403, 'Your account is not linked to a faculty record.');
    }

    $times = parse_room_request_times($input);
    $pdo->beginTransaction();
    try {
        $input['slot_id'] = $slotId;
        validate_room_request_submission($pdo, $input, $user);

        $termId = active_term_id($pdo);
        $insert = $pdo->prepare('INSERT INTO schedule_requests(term_id, offering_id, instructor_id, room_id, request_type, request_date, day_of_week, slot_id, requested_end_time, note) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$termId, $offeringId, (int) $user['instructor_id'], $roomId, $requestType, $times['date'], $times['day'], $slotId, $times['end'], $note]);
        $requestId = db_insert_id($pdo, 'schedule_requests');
        audit($pdo, $user, 'REQUEST_SCHEDULE', 'schedule_request', $requestId);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $rollbackError) {}
        throw $error;
    }

    return ['snapshot' => bootstrap($pdo, $user)];
}

function create_schedule_change_request(PDO $pdo, array $user, array $input): array
{
    if (empty($user['instructor_id'])) {
        throw new ApiError(403, 'Your account is not linked to a faculty record.');
    }

    $entryId = input_int($input, 'entry_id', 1, 100000000);
    $roomId = input_int($input, 'room_id', 1, 100000000);
    $day = input_int($input, 'day_of_week', 1, 5);
    [$startTime, $endTime] = parse_manual_schedule_times($input);
    $pdo->beginTransaction();
    try {
        $termId = active_term_id($pdo);
        $run = active_run($pdo, $termId);
        if (!$run) {
            throw new ApiError(409, 'There is no published schedule to change.');
        }

        $entryStmt = $pdo->prepare("SELECT se.id, se.offering_id, COALESCE(se.start_time_override, ts.start_time) AS current_start_time, COALESCE(se.end_time_override, (SELECT ts_end.end_time FROM time_slots ts_end WHERE ts_end.slot_order = ts.slot_order + s.duration_slots - 1), ts.end_time) AS current_end_time FROM schedule_entries se JOIN course_offerings co ON co.id = se.offering_id JOIN subjects s ON s.id = co.subject_id JOIN time_slots ts ON ts.id = se.slot_id WHERE se.id = ? AND se.run_id = ? AND se.status = 'PUBLISHED' AND co.term_id = ? AND co.instructor_id = ? FOR UPDATE OF se");
        $entryStmt->execute([$entryId, (int) $run['id'], $termId, (int) $user['instructor_id']]);
        $entry = $entryStmt->fetch();
        if (!$entry) {
            throw new ApiError(404, 'Scheduled class not found in your published timetable.');
        }
        $currentDuration = strtotime((string) $entry['current_end_time']) - strtotime((string) $entry['current_start_time']);
        $requestedDuration = strtotime($endTime) - strtotime($startTime);
        if ($requestedDuration !== $currentDuration) {
            throw new ApiError(422, 'Keep the class duration unchanged when requesting a schedule change.');
        }

        $startSlotStmt = $pdo->prepare('SELECT id FROM time_slots WHERE start_time = ? LIMIT 1');
        $startSlotStmt->execute([$startTime]);
        $startSlotId = $startSlotStmt->fetchColumn();
        if ($startSlotId === false) {
            throw new ApiError(422, 'The proposed start time must match a configured timetable hour.');
        }

        $pendingStmt = $pdo->prepare("SELECT 1 FROM schedule_requests WHERE target_entry_id = ? AND request_type = 'CHANGE' AND status = 'PENDING' LIMIT 1");
        $pendingStmt->execute([$entryId]);
        if ($pendingStmt->fetchColumn()) {
            throw new ApiError(409, 'A change request for this class is already awaiting review.');
        }

        $roomStmt = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND active = 1');
        $roomStmt->execute([$roomId]);
        if (!$roomStmt->fetchColumn()) {
            throw new ApiError(404, 'Room not found.');
        }

        $validated = validate_manual_candidate(
            $pdo,
            $termId,
            (int) $entry['offering_id'],
            $roomId,
            $day,
            (int) $startSlotId,
            $entryId,
            true,
            [$startTime, $endTime]
        );
        $slotId = (int) $validated['candidate']['slot_id'];
        $note = trim(input_string($input, 'note', 300, false));
        $insert = $pdo->prepare("INSERT INTO schedule_requests (term_id, offering_id, instructor_id, room_id, request_type, target_entry_id, day_of_week, slot_id, requested_end_time, note) VALUES (?, ?, ?, ?, 'CHANGE', ?, ?, ?, ?, ?)");
        $insert->execute([$termId, (int) $entry['offering_id'], (int) $user['instructor_id'], $roomId, $entryId, $day, $slotId, $endTime, $note]);
        $requestId = db_insert_id($pdo, 'schedule_requests');
        audit($pdo, $user, 'REQUEST_SCHEDULE_CHANGE', 'schedule_request', $requestId, ['entry_id' => $entryId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return ['snapshot' => bootstrap($pdo, $user)];
}

function prepare_schedule_request_approval(PDO $pdo, array $input): void
{
    $requestId = input_int($input, 'request_id', 1, 100000000);
    $request = $pdo->prepare("SELECT * FROM schedule_requests WHERE id=? AND status='PENDING'");
    $request->execute([$requestId]);
    $row = $request->fetch();
    if (!$row) {
        return;
    }
    $existing = $pdo->prepare("SELECT se.id FROM schedule_entries se JOIN schedule_runs sr ON sr.id=se.run_id AND sr.status='PUBLISHED' WHERE se.offering_id=? AND se.status='PUBLISHED' ORDER BY se.id DESC LIMIT 1");
    $existing->execute([(int) $row['offering_id']]);
    $entryId = $existing->fetchColumn();
    if ($entryId === false) {
        return;
    }
    validate_manual_candidate($pdo, (int) $row['term_id'], (int) $row['offering_id'], (int) $row['room_id'], (int) $row['day_of_week'], (int) $row['slot_id'], (int) $entryId);
    $pdo->prepare('DELETE FROM schedule_occupancy WHERE entry_id=?')->execute([(int) $entryId]);
    $pdo->prepare('DELETE FROM schedule_entries WHERE id=?')->execute([(int) $entryId]);
}

function review_schedule_request(PDO $pdo, array $user, array $input): array
{
    $id = input_int($input, 'request_id', 1, 100000000);
    $decision = (string) ($input['decision'] ?? '');
    if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
        throw new ApiError(422, 'Invalid decision.');
    }
    $query = $pdo->prepare("SELECT * FROM schedule_requests WHERE id=? AND status='PENDING'");
    $query->execute([$id]);
    $request = $query->fetch();
    if (!$request) {
        throw new ApiError(404, 'Request not found.');
    }
    if ($decision === 'REJECT') {
        $pdo->prepare("UPDATE schedule_requests SET status='REJECTED', reviewed_by=?, reviewed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int) $user['id'], $id]);
        return ['snapshot' => bootstrap($pdo, $user)];
    }

    $pdo->beginTransaction();
    try {
        $query->execute([$id]);
        $request = $query->fetch();
        if (!$request) {
            throw new ApiError(409, 'This request has already been reviewed.');
        }
        if (($request['request_type'] ?? 'ROOM') === 'OTHER') {
            $slotStmt = $pdo->prepare('SELECT start_time, end_time FROM time_slots WHERE id = ?');
            $slotStmt->execute([(int) $request['slot_id']]);
            $requestSlot = $slotStmt->fetch();
            if (!$requestSlot) {
                throw new ApiError(422, 'The requested start time is no longer available.');
            }
            $requestDate = (string) ($request['request_date'] ?? '');
            if ($requestDate === '' || trim((string) $request['note']) === '') {
                throw new ApiError(422, 'The request date and purpose are required.');
            }
            $roomStmt = $pdo->prepare('SELECT 1 FROM rooms WHERE id=? AND active=1');
            $roomStmt->execute([(int) $request['room_id']]);
            if (!$roomStmt->fetchColumn()) {
                throw new ApiError(409, 'The requested room is no longer active.');
            }
            $requestedEnd = (string) ($request['requested_end_time'] ?: $requestSlot['end_time']);
            $conflicts = room_request_conflicts($pdo, (int) $request['room_id'], (int) $request['instructor_id'], 0, 0, (int) $request['term_id'], (int) $request['day_of_week'], $requestDate, (string) $requestSlot['start_time'], $requestedEnd, $id);
            if ($conflicts !== []) {
                throw new ApiError(409, 'The room or instructor is occupied for part of this time.', ['conflicts' => $conflicts]);
            }
            $pdo->prepare("UPDATE schedule_requests SET status='APPROVED', reviewed_by=?, reviewed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int) $user['id'], $id]);
            audit($pdo, $user, 'APPROVE_OTHER_ROOM_REQUEST', 'schedule_request', $id, ['purpose' => (string) $request['note']]);
            $pdo->exec('COMMIT');
            return ['snapshot' => bootstrap($pdo, $user)];
        }
        $run = active_run($pdo, (int) $request['term_id']);
        if (!$run) {
            throw new ApiError(409, 'Generate a schedule before approving requests.');
        }
        if (($request['request_type'] ?? 'ROOM') === 'CHANGE') {
            $existing = $pdo->prepare("SELECT se.id FROM schedule_entries se WHERE se.id=? AND se.run_id=? AND se.offering_id=? AND se.status='PUBLISHED' LIMIT 1");
            $existing->execute([(int) ($request['target_entry_id'] ?? 0), (int) $run['id'], (int) $request['offering_id']]);
        } else {
            $existing = $pdo->prepare("SELECT se.id FROM schedule_entries se WHERE se.run_id=? AND se.offering_id=? AND se.status='PUBLISHED' ORDER BY id LIMIT 1");
            $existing->execute([(int) $run['id'], (int) $request['offering_id']]);
        }
        $entryId = $existing->fetchColumn();
        if (($request['request_type'] ?? 'ROOM') === 'CHANGE' && $entryId === false) {
            throw new ApiError(409, 'The scheduled class changed before this request could be approved. Refresh the timetable and submit a new request.');
        }
        $slotStmt = $pdo->prepare('SELECT start_time, end_time FROM time_slots WHERE id = ?');
        $slotStmt->execute([(int) $request['slot_id']]);
        $requestSlot = $slotStmt->fetch();
        if (!$requestSlot) {
            throw new ApiError(422, 'The requested start time is no longer available.');
        }
        $requestedEnd = (string) ($request['requested_end_time'] ?: $requestSlot['end_time']);
        $requestedTime = [(string) $requestSlot['start_time'], $requestedEnd];
        $requestDate = (string) ($request['request_date'] ?? '');
        if ($requestDate === '' && preg_match('/^\[REQUEST_DATE:(\d{4}-\d{2}-\d{2})\]/', (string) $request['note'], $dateMatch)) {
            $requestDate = $dateMatch[1];
        }
        if ($requestDate !== '') {
            $offering = $pdo->prepare('SELECT section_id FROM course_offerings WHERE id = ?');
            $offering->execute([(int) $request['offering_id']]);
            $sectionId = (int) $offering->fetchColumn();
            $conflicts = room_request_conflicts($pdo, (int) $request['room_id'], (int) $request['instructor_id'], $sectionId, (int) $request['offering_id'], (int) $request['term_id'], (int) $request['day_of_week'], $requestDate, $requestedTime[0], $requestedTime[1], $id);
            if ($conflicts !== []) {
                throw new ApiError(409, 'The room, instructor, or class section is occupied for part of this time.', ['conflicts' => $conflicts]);
            }
        }
        $validated = validate_manual_candidate($pdo, (int) $request['term_id'], (int) $request['offering_id'], (int) $request['room_id'], (int) $request['day_of_week'], (int) $request['slot_id'], $entryId !== false ? (int) $entryId : null, true, $requestedTime);
        if ($entryId !== false) {
            $entryId = (int) $entryId;
            $pdo->prepare('DELETE FROM schedule_occupancy WHERE entry_id=?')->execute([$entryId]);
            $pdo->prepare('UPDATE schedule_entries SET room_id=?, day_of_week=?, slot_id=?, start_time_override=?, end_time_override=? WHERE id=?')->execute([(int) $request['room_id'], (int) $request['day_of_week'], (int) $request['slot_id'], $requestedTime[0], $requestedEnd, $entryId]);
        } else {
            $pdo->prepare("INSERT INTO schedule_entries(run_id,offering_id,meeting_no,room_id,day_of_week,slot_id,end_time_override,status) VALUES(?,?,?,?,?,?,?, 'PUBLISHED')")->execute([(int) $run['id'], (int) $request['offering_id'], 1, (int) $request['room_id'], (int) $request['day_of_week'], (int) $request['slot_id'], $requestedEnd]);
            $entryId = db_insert_id($pdo, 'schedule_entries');
        }
        $occupancy = $pdo->prepare('INSERT INTO schedule_occupancy(run_id,entry_id,resource_type,resource_id,day_of_week,slot_id) VALUES(?,?,?,?,?,?)');
        foreach ($validated['candidate']['slot_ids'] as $slot) {
            foreach ([['ROOM', (int) $request['room_id']], ['INSTRUCTOR', (int) $request['instructor_id']], ['SECTION', (int) $validated['offering']['section_id']] ] as $resource) {
                $occupancy->execute([(int) $run['id'], $entryId, $resource[0], $resource[1], (int) $request['day_of_week'], $slot]);
            }
        }
        $pdo->prepare("UPDATE schedule_requests SET status='APPROVED', reviewed_by=?, reviewed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int) $user['id'], $id]);
        audit($pdo, $user, ($request['request_type'] ?? 'ROOM') === 'CHANGE' ? 'APPROVE_SCHEDULE_CHANGE' : 'APPROVE_SCHEDULE_REQUEST', 'schedule_request', $id, ['entry_id' => $entryId]);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $rollbackError) {}
        throw $error;
    }
    return ['snapshot' => bootstrap($pdo, $user)];
}

function save_master(PDO $pdo, array $user, array $input): array
{
    $entity = (string) ($input['entity'] ?? '');
    $data = is_array($input['data'] ?? null) ? $input['data'] : [];
    $recordId = array_key_exists('id', $input)
        ? input_int($input, 'id', 1, 100000000, false)
        : input_int($data, 'id', 1, 100000000, false);
    $isUpdate = $recordId !== null;
    $originalEmail = null;
    $delete = !empty($input['delete']);
    $table = ['rooms' => 'rooms', 'instructors' => 'instructors', 'subjects' => 'subjects', 'programs' => 'programs', 'sections' => 'sections', 'offerings' => 'course_offerings', 'users' => 'users'][$entity] ?? null;
    if ($table === null) {
        throw new ApiError(422, 'Unsupported master-data type.');
    }
    if ($delete) {
        if ($recordId === null) {
            throw new ApiError(422, 'A record id is required.');
        }
        if ($entity === 'subjects') {
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $pdo->prepare("UPDATE course_offerings SET status = 'INACTIVE' WHERE subject_id = ? AND status = 'ACTIVE'")->execute([$recordId]);
                $stmt = $pdo->prepare('UPDATE subjects SET active = 0 WHERE id = ? AND active = 1');
                $stmt->execute([$recordId]);
                if ($stmt->rowCount() !== 1) {
                    throw new ApiError(404, 'Subject not found or already inactive.');
                }
                audit($pdo, $user, 'DEACTIVATE_MASTER_DATA', $entity, $recordId);
                if ($ownsTransaction) {
                    $pdo->commit();
                }
            } catch (Throwable $error) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            return ['deactivated' => $recordId];
        }
        if ($entity === 'users') {
            if ($user['role'] !== 'admin') throw new ApiError(403, 'Only administrators can manage user accounts.');
            if ($recordId === (int) $user['id']) throw new ApiError(409, 'You cannot deactivate your own account.');
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            $target = $pdo->prepare('SELECT role FROM users WHERE id = ? AND active = 1'); $target->execute([$recordId]);
            if ($target->fetchColumn() === 'admin' && $adminCount <= 1) throw new ApiError(409, 'At least one active administrator is required.');
        }
        if ($entity === 'rooms') {
            $pending = $pdo->prepare("SELECT COUNT(*) FROM schedule_requests WHERE room_id=? AND status='PENDING'");
            $pending->execute([$recordId]);
            if ((int) $pending->fetchColumn() > 0) throw new ApiError(409, 'This room has a pending request and cannot be deactivated yet.');
        }
        $sql = $entity === 'offerings' ? "UPDATE course_offerings SET status='INACTIVE' WHERE id=? AND status='ACTIVE'" : "UPDATE {$table} SET active=0 WHERE id=? AND active=1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$recordId]);
        if ($stmt->rowCount() !== 1) throw new ApiError(404, 'Record not found or already inactive.');
        audit($pdo, $user, 'DEACTIVATE_MASTER_DATA', $entity, $recordId);
        return ['deactivated' => $recordId];
    }

    if ($entity === 'rooms') {
        $code = input_code($data, 'code'); $name = input_string($data, 'name', 120); $capacity = input_int($data, 'capacity', 1, 5000); $type = strtoupper(input_string($data, 'room_type', 20));
        if (!in_array($type, ['LECTURE', 'LAB', 'SPECIAL'], true)) throw new ApiError(422, 'Room type is invalid.');
        $features = clean_string_list($data['features'] ?? []);
        if ($recordId) { $stmt = $pdo->prepare('UPDATE rooms SET code=?, name=?, capacity=?, room_type=?, features_json=? WHERE id=?'); $stmt->execute([$code, $name, $capacity, $type, encode_json($features), $recordId]); }
        else { $stmt = $pdo->prepare('INSERT INTO rooms (code,name,capacity,room_type,features_json) VALUES (?,?,?,?,?)'); $stmt->execute([$code,$name,$capacity,$type,encode_json($features)]); $recordId = db_insert_id($pdo, 'rooms'); }
    } elseif ($entity === 'instructors') {
        $employeeNo = input_code($data, 'employee_no'); $name = input_string($data, 'name', 120); $email = input_email($data); $max = input_int($data, 'max_hours_day', 1, 16);
        if ($recordId) { $stmt = $pdo->prepare('UPDATE instructors SET employee_no=?, name=?, email=?, max_hours_day=? WHERE id=?'); $stmt->execute([$employeeNo,$name,$email,$max,$recordId]); }
        else { $stmt = $pdo->prepare('INSERT INTO instructors (employee_no,name,email,max_hours_day) VALUES (?,?,?,?)'); $stmt->execute([$employeeNo,$name,$email,$max]); $recordId=db_insert_id($pdo, 'instructors'); }
    } elseif ($entity === 'programs') {
        $code = input_code($data, 'code'); $name = input_string($data, 'name', 160);
        if ($recordId) { $stmt=$pdo->prepare('UPDATE programs SET code=?, name=? WHERE id=?'); $stmt->execute([$code,$name,$recordId]); }
        else { $stmt=$pdo->prepare('INSERT INTO programs (code,name) VALUES (?,?)'); $stmt->execute([$code,$name]); $recordId=db_insert_id($pdo, 'programs'); }
    } elseif ($entity === 'subjects') {
        $code = normalize_subject_code($data);
        $name = normalize_subject_name($data);
        $roomType = strtoupper(input_string($data, 'room_type', 20));
        if (!in_array($roomType, ['LECTURE', 'LAB', 'SPECIAL'], true)) {
            throw new ApiError(422, 'Subject room type is invalid.');
        }
        $normalizedName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $normalizedName = preg_replace('/[\s\p{P}]+/u', '', $normalizedName) ?? $normalizedName;
        $excludeRecord = $recordId === null ? '' : ' AND id <> ?';
        $duplicate = $pdo->prepare(
            "SELECT id FROM subjects
             WHERE active = 1
               AND LOWER(REGEXP_REPLACE(BTRIM(name), '[[:space:][:punct:]]+', '', 'g')) = ?{$excludeRecord}
             LIMIT 1"
        );
        $duplicate->execute($recordId === null ? [$normalizedName] : [$normalizedName, $recordId]);
        if ($duplicate->fetchColumn() !== false) {
            throw new ApiError(409, 'A subject with this name already exists.');
        }
        if ($recordId) {
            $stmt = $pdo->prepare('UPDATE subjects SET code = ?, name = ?, room_type = ? WHERE id = ? AND active = 1');
            $stmt->execute([$code, $name, $roomType, $recordId]);
            if ($stmt->rowCount() !== 1) {
                throw new ApiError(404, 'Subject not found or inactive.');
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO subjects (code, name, units, hours_per_week, duration_slots, room_type, required_features_json) VALUES (?, ?, 3, 2, 2, ?, '[]')");
            $stmt->execute([$code, $name, $roomType]);
            $recordId = db_insert_id($pdo, 'subjects');
        }
    } elseif ($entity === 'sections') {
        $programId=input_int($data,'program_id',1,100000000); $termId=input_int($data,'term_id',1,100000000); $code=input_code($data,'code'); $year=input_int($data,'year_level',1,4); $count=input_int($data,'student_count',1,5000);
        assert_reference($pdo, 'programs', $programId, 'Program', 'active = 1');
        assert_reference($pdo, 'academic_terms', $termId, 'Academic term');
        if ($recordId) { $stmt=$pdo->prepare('UPDATE sections SET program_id=?,term_id=?,code=?,year_level=?,student_count=? WHERE id=?'); $stmt->execute([$programId,$termId,$code,$year,$count,$recordId]); }
        else { $stmt=$pdo->prepare('INSERT INTO sections (program_id,term_id,code,year_level,student_count) VALUES (?,?,?,?,?)'); $stmt->execute([$programId,$termId,$code,$year,$count]); $recordId=db_insert_id($pdo, 'sections'); }
    } elseif ($entity === 'offerings') {
        $termId=input_int($data,'term_id',1,100000000); $subjectId=input_int($data,'subject_id',1,100000000); $sectionId=input_int($data,'section_id',1,100000000); $instructorId=input_int($data,'instructor_id',1,100000000); $enrollment=input_int($data,'enrollment',1,5000); $meetings=input_int($data,'required_meetings',1,20);
        assert_reference($pdo, 'academic_terms', $termId, 'Academic term');
        assert_reference($pdo, 'subjects', $subjectId, 'Subject', 'active = 1');
        assert_reference($pdo, 'instructors', $instructorId, 'Instructor', 'active = 1');
        $sectionStmt = $pdo->prepare('SELECT student_count FROM sections WHERE id = ? AND term_id = ? AND active = 1');
        $sectionStmt->execute([$sectionId, $termId]);
        $sectionCount = $sectionStmt->fetchColumn();
        if ($sectionCount === false) throw new ApiError(422, 'Section must be active and belong to the selected academic term.');
        if ($enrollment > (int) $sectionCount) throw new ApiError(422, 'Enrollment cannot exceed the section student count.');
        if ($recordId) { $stmt=$pdo->prepare('UPDATE course_offerings SET term_id=?,subject_id=?,section_id=?,instructor_id=?,enrollment=?,required_meetings=? WHERE id=?'); $stmt->execute([$termId,$subjectId,$sectionId,$instructorId,$enrollment,$meetings,$recordId]); }
        else { $stmt=$pdo->prepare('INSERT INTO course_offerings (term_id,subject_id,section_id,instructor_id,enrollment,required_meetings) VALUES (?,?,?,?,?,?)'); $stmt->execute([$termId,$subjectId,$sectionId,$instructorId,$enrollment,$meetings]); $recordId=db_insert_id($pdo, 'course_offerings'); }
    } else {
        if ($user['role'] !== 'admin') throw new ApiError(403, 'Only administrators can manage user accounts.');
        if ($recordId !== null) {
            $existingUser = $pdo->prepare('SELECT email FROM users WHERE id = ? AND active = 1');
            $existingUser->execute([$recordId]);
            $originalEmail = $existingUser->fetchColumn();
            if ($originalEmail === false) throw new ApiError(404, 'User account not found.');
        }
        $username = strtolower(input_string($data, 'username', 80));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $username)) throw new ApiError(422, 'Username may contain lowercase letters, numbers, dots, hyphens, and underscores.');
        $displayName = input_string($data, 'display_name', 120);
        $email = input_email($data);
        if ($recordId === null || strtolower((string) $originalEmail) !== $email) {
            assert_email_available($pdo, $email, $recordId ?? 0);
        }
        $role = strtolower(input_string($data, 'role', 20));
        if (!in_array($role, ['admin', 'scheduler', 'instructor', 'student'], true)) throw new ApiError(422, 'User role is invalid.');
        $instructorId = input_int($data, 'instructor_id', 1, 100000000, false);
        $sectionId = input_int($data, 'section_id', 1, 100000000, false);
        if ($role === 'instructor') {
            if ($instructorId === null) throw new ApiError(422, 'An instructor account must be linked to a faculty record.');
            assert_reference($pdo, 'instructors', $instructorId, 'Instructor', 'active = 1');
            $sectionId = null;
        } elseif ($role === 'student') {
            if ($sectionId === null) throw new ApiError(422, 'A student account must be linked to a section.');
            assert_reference($pdo, 'sections', $sectionId, 'Section', 'active = 1');
            $instructorId = null;
        } else {
            $instructorId = null;
            $sectionId = null;
        }
        $password = (string) ($data['password'] ?? '');
        if (!$recordId && $password === '') throw new ApiError(422, 'A temporary password is required for a new account.');
        if ($password !== '' && !password_meets_requirements($password)) throw new ApiError(422, 'Temporary passwords need at least 10 characters, one uppercase letter, one number, and one special character.');
        if ($recordId) {
            if ($recordId === (int) $user['id'] && $role !== 'admin') throw new ApiError(409, 'Use another administrator account before changing your own administrator role.');
            $sql = 'UPDATE users SET username=?,display_name=?,email=?,role=?,instructor_id=?,section_id=?' . ($password !== '' ? ',password_hash=?' : '') . ' WHERE id=? AND active=1';
            $params = [$username,$displayName,$email,$role,$instructorId,$sectionId];
            if ($password !== '') $params[] = password_hash($password, PASSWORD_DEFAULT);
            $params[] = $recordId;
            $stmt=$pdo->prepare($sql); $stmt->execute($params);
        } else {
            $stmt=$pdo->prepare('INSERT INTO users (username,password_hash,display_name,email,role,instructor_id,section_id) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT),$displayName,$email,$role,$instructorId,$sectionId]);
            $recordId=db_insert_id($pdo, 'users');
        }
    }
    audit($pdo, $user, $isUpdate ? 'UPDATE_MASTER_DATA' : 'CREATE_MASTER_DATA', $entity, $recordId);
    if ($entity === 'users' && $isUpdate) {
        $previousEmail = strtolower((string) ($originalEmail ?? ''));
        if ($previousEmail !== $email) audit($pdo, $user, 'ADMIN_CHANGE_USER_EMAIL', 'user', $recordId, ['email_changed' => true]);
    }
    return ['id' => $recordId];
}

function review_registration(PDO $pdo, array $user, array $input): array
{
    $registrationId = input_int($input, 'registration_id', 1, 100000000);
    $decision = strtoupper(input_string($input, 'decision', 10));
    if (!in_array($decision, ['APPROVE', 'REJECT'], true)) throw new ApiError(422, 'Registration decision is invalid.');
    $stmt = $pdo->prepare('SELECT * FROM pending_registrations WHERE id = ? AND status = \'PENDING\'');
    $stmt->execute([$registrationId]);
    $registration = $stmt->fetch();
    if (!$registration) throw new ApiError(404, 'Pending registration not found.');
    $note = input_string($input, 'review_note', 300, false);
    $pdo->beginTransaction();
    try {
        if ($decision === 'APPROVE') {
            $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?'); $exists->execute([$registration['username']]);
            if ($exists->fetchColumn()) throw new ApiError(409, 'That username already belongs to an active account.');
            $insert = $pdo->prepare('INSERT INTO users (username, password_hash, display_name, email, role, section_id) VALUES (?, ?, ?, ?, \'student\', ?)');
            $insert->execute([$registration['username'], $registration['password_hash'], $registration['display_name'], $registration['email'], $registration['section_id']]);
        }
        $update = $pdo->prepare('UPDATE pending_registrations SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = \'PENDING\'');
        $reviewedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $update->execute([$decision === 'APPROVE' ? 'APPROVED' : 'REJECTED', $note, (int) $user['id'], $reviewedAt, $registrationId]);
        audit($pdo, $user, $decision === 'APPROVE' ? 'APPROVE_STUDENT_REGISTRATION' : 'REJECT_STUDENT_REGISTRATION', 'pending_registration', $registrationId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof ApiError) throw $error;
        throw new ApiError(409, 'The registration could not be reviewed.');
    }
    return ['message' => $decision === 'APPROVE' ? 'Student registration approved.' : 'Student registration rejected.'];
}

function export_csv(PDO $pdo, array $user, int $termId, ?int $runId = null): never
{
    $rows = scoped_schedule($pdo, $user, $termId, $runId);
    audit($pdo, $user, 'EXPORT_SCHEDULE', 'schedule', null, ['term_id' => $termId, 'run_id' => $runId, 'rows' => count($rows)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="EasySched-Schedule-' . ($runId === null ? '' : 'History-') . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Subject Code', 'Subject', 'Section', 'Program', 'Instructor', 'Room', 'Day', 'Time', 'Enrollment']);
    foreach ($rows as $row) {
        $values = [$row['subject_code'], $row['subject_name'], $row['section_code'], $row['program_code'], $row['instructor_name'], $row['room_code'], $row['day_name'], $row['time_label'], $row['student_count']];
        foreach ($values as &$value) {
            $value = (string) $value;
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                $value = "'" . $value;
            }
        }
        fputcsv($out, $values);
    }
    fclose($out);
    exit;
}

function export_offerings_csv(PDO $pdo, array $user, int $termId): never
{
    $stmt = $pdo->prepare(
        "SELECT t.academic_year, t.semester, sub.code AS subject_code, sub.name AS subject_name,
                sec.code AS section_code, i.employee_no AS instructor_employee_no, i.name AS instructor_name,
                co.enrollment, co.required_meetings
         FROM course_offerings co
         JOIN academic_terms t ON t.id = co.term_id
         JOIN subjects sub ON sub.id = co.subject_id
         JOIN sections sec ON sec.id = co.section_id
         JOIN instructors i ON i.id = co.instructor_id
         WHERE co.term_id = ? AND co.status = 'ACTIVE'
         ORDER BY sec.code, sub.code, sub.name"
    );
    $stmt->execute([$termId]);
    audit($pdo, $user, 'EXPORT_CLASS_ASSIGNMENTS', 'course_offerings', null, ['term_id' => $termId]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="EasySched-Class-Assignments-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not open the CSV export stream.');
    }
    fputcsv($out, ['academic_year', 'semester', 'subject_code', 'subject_name', 'section_code', 'instructor_employee_no', 'instructor_name', 'enrollment', 'required_meetings']);
    while ($row = $stmt->fetch()) {
        $values = array_values($row);
        foreach ($values as &$value) {
            $value = (string) $value;
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($out, $values);
    }
    fclose($out);
    exit;
}

function import_offerings_csv(PDO $pdo, array $user, array $input): array
{
    $csv = $input['csv'] ?? null;
    if (!is_string($csv) || trim($csv) === '') {
        throw new ApiError(422, 'Choose a non-empty CSV file.');
    }
    if (strlen($csv) > 2000000) {
        throw new ApiError(413, 'The CSV file must be 2 MB or smaller.');
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Could not open the CSV import stream.');
    }
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream, null, ',', '"', '\\');
    if (!is_array($headers)) {
        fclose($stream);
        throw new ApiError(422, 'The CSV file must start with a header row.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? (string) $headers[0];
    $headers = array_map(static fn($header): string => strtolower(trim((string) $header)), $headers);
    $requiredHeaders = [
        'academic_year',
        'semester',
        'subject_code',
        'subject_name',
        'section_code',
        'instructor_employee_no',
        'enrollment',
        'required_meetings',
    ];
    if (count($headers) !== count(array_unique($headers)) || array_diff($requiredHeaders, $headers)) {
        fclose($stream);
        throw new ApiError(422, 'CSV headers must include: ' . implode(', ', $requiredHeaders) . '.');
    }

    $headerPositions = array_flip($headers);
    $rows = [];
    $rowErrors = [];
    $lineNumber = 1;
    while (($values = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
        $lineNumber++;
        if (count($values) === 1 && trim((string) ($values[0] ?? '')) === '') {
            continue;
        }
        if (count($values) !== count($headers)) {
            $rowErrors[] = ['row' => $lineNumber, 'error' => 'The number of columns does not match the header.'];
            continue;
        }
        $record = [];
        foreach ($requiredHeaders as $header) {
            $value = trim((string) $values[$headerPositions[$header]]);
            $record[$header] = preg_replace("/^'(?=[=+\\-@])/", '', $value) ?? $value;
        }
        $record['instructor_name'] = isset($headerPositions['instructor_name'])
            ? trim((string) $values[$headerPositions['instructor_name']])
            : '';
        $record['row'] = $lineNumber;
        $rows[] = $record;
        if (count($rows) > 2000) {
            fclose($stream);
            throw new ApiError(413, 'The CSV file cannot contain more than 2,000 class assignments.');
        }
    }
    fclose($stream);
    if (!$rows && !$rowErrors) {
        throw new ApiError(422, 'The CSV file has no class assignment rows.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    $savepoint = 'offering_import';
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    } else {
        $pdo->exec('SAVEPOINT ' . $savepoint);
    }

    try {
        $resolved = [];
        $seen = [];
        $termLookup = $pdo->prepare('SELECT id FROM academic_terms WHERE academic_year = ? AND semester = ?');
        $subjectLookup = $pdo->prepare('SELECT id FROM subjects WHERE active = 1 AND code = ? AND LOWER(BTRIM(name)) = LOWER(?)');
        $sectionLookup = $pdo->prepare('SELECT id, student_count FROM sections WHERE active = 1 AND term_id = ? AND code = ?');
        $instructorLookup = $pdo->prepare('SELECT id FROM instructors WHERE active = 1 AND employee_no = ?');
        $instructorNameLookup = $pdo->prepare('SELECT id FROM instructors WHERE active = 1 AND LOWER(BTRIM(name)) = LOWER(?)');

        foreach ($rows as $record) {
            $line = (int) $record['row'];
            $errorStart = count($rowErrors);
            if (!preg_match('/^20\d{2}-20\d{2}$/', $record['academic_year'])) {
                $rowErrors[] = ['row' => $line, 'error' => 'Academic year must use YYYY-YYYY format.'];
            }
            if (!in_array($record['semester'], ['First Semester', 'Second Semester', 'Summer'], true)) {
                $rowErrors[] = ['row' => $line, 'error' => 'Semester must be First Semester, Second Semester, or Summer.'];
            }
            foreach (['subject_code', 'subject_name', 'section_code', 'instructor_employee_no'] as $field) {
                if ($record[$field] === '') {
                    $rowErrors[] = ['row' => $line, 'error' => str_replace('_', ' ', ucfirst($field)) . ' is required.'];
                }
            }
            if (!preg_match('/^[1-9]\d*$/', $record['enrollment']) || (int) $record['enrollment'] > 5000) {
                $rowErrors[] = ['row' => $line, 'error' => 'Enrollment must be a whole number from 1 to 5,000.'];
            }
            if (!preg_match('/^[1-9]\d*$/', $record['required_meetings']) || (int) $record['required_meetings'] > 20) {
                $rowErrors[] = ['row' => $line, 'error' => 'Required meetings must be a whole number from 1 to 20.'];
            }
            if (count($rowErrors) > $errorStart) {
                continue;
            }

            $termLookup->execute([$record['academic_year'], $record['semester']]);
            $termId = $termLookup->fetchColumn();
            if ($termId === false) {
                $rowErrors[] = ['row' => $line, 'error' => 'Academic term was not found in this database.'];
                continue;
            }
            $subjectLookup->execute([$record['subject_code'], $record['subject_name']]);
            $subjectId = $subjectLookup->fetchColumn();
            if ($subjectId === false) {
                $rowErrors[] = ['row' => $line, 'error' => 'Active subject was not found for this course code and subject name.'];
                continue;
            }
            $sectionLookup->execute([(int) $termId, $record['section_code']]);
            $section = $sectionLookup->fetch();
            if (!$section) {
                $rowErrors[] = ['row' => $line, 'error' => 'Active section was not found for this academic term.'];
                continue;
            }
            if ((int) $record['enrollment'] > (int) $section['student_count']) {
                $rowErrors[] = ['row' => $line, 'error' => 'Enrollment cannot exceed the section student count.'];
                continue;
            }
            $instructorLookup->execute([$record['instructor_employee_no']]);
            $instructorId = $instructorLookup->fetchColumn();
            if ($instructorId === false) {
                if ($record['instructor_name'] === '') {
                    $rowErrors[] = ['row' => $line, 'error' => 'Active instructor was not found for this employee number. Export a fresh CSV to include instructor names, or add the matching faculty record to the destination.'];
                    continue;
                }
                $instructorNameLookup->execute([$record['instructor_name']]);
                $instructorMatches = $instructorNameLookup->fetchAll(PDO::FETCH_COLUMN);
                if (count($instructorMatches) !== 1) {
                    $rowErrors[] = [
                        'row' => $line,
                        'error' => count($instructorMatches) > 1
                            ? 'Instructor name matches multiple active faculty records; match the employee number in the destination.'
                            : 'Active instructor was not found by employee number or exact name.',
                    ];
                    continue;
                }
                $instructorId = $instructorMatches[0];
            }

            $uniqueKey = $termId . ':' . $subjectId . ':' . $section['id'];
            if (isset($seen[$uniqueKey])) {
                $rowErrors[] = ['row' => $line, 'error' => 'This term, subject, and section combination appears more than once in the CSV.'];
                continue;
            }
            $seen[$uniqueKey] = true;
            $resolved[] = [
                'row' => $line,
                'term_id' => (int) $termId,
                'subject_id' => (int) $subjectId,
                'section_id' => (int) $section['id'],
                'instructor_id' => (int) $instructorId,
                'enrollment' => (int) $record['enrollment'],
                'required_meetings' => (int) $record['required_meetings'],
            ];
        }

        if ($rowErrors) {
            throw new ApiError(422, 'No assignments were imported. Fix the CSV errors and try again.', ['rows' => array_slice($rowErrors, 0, 50)]);
        }

        $existingLookup = $pdo->prepare('SELECT id FROM course_offerings WHERE term_id = ? AND subject_id = ? AND section_id = ?');
        $insert = $pdo->prepare("INSERT INTO course_offerings (term_id, subject_id, section_id, instructor_id, enrollment, required_meetings, status) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE') ON CONFLICT (term_id, subject_id, section_id) DO NOTHING");
        $created = 0;
        $skipped = 0;
        foreach ($resolved as $record) {
            $key = [$record['term_id'], $record['subject_id'], $record['section_id']];
            $existingLookup->execute($key);
            $existingId = $existingLookup->fetchColumn();
            if ($existingId !== false) {
                $skipped++;
                continue;
            }
            $insert->execute([...$key, $record['instructor_id'], $record['enrollment'], $record['required_meetings']]);
            if ($insert->rowCount() === 1) {
                $created++;
            } else {
                $skipped++;
            }
        }
        audit($pdo, $user, 'IMPORT_CLASS_ASSIGNMENTS', 'course_offerings', null, ['created' => $created, 'skipped_existing' => $skipped]);
        $snapshot = bootstrap($pdo, $user);
        if ($ownsTransaction) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        return ['created' => $created, 'skipped' => $skipped, 'snapshot' => $snapshot];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        } elseif ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $error;
    }
}

function handle(PDO $pdo): never
{
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'bootstrap');
    $input = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? body() : [];
    $GLOBALS['EASYSCHED_AUDIT_REQUEST_RECORDED'] = false;
    $GLOBALS['EASYSCHED_API_PDO'] = $pdo;
    $GLOBALS['EASYSCHED_API_ACTION'] = $action;
    $GLOBALS['EASYSCHED_API_INPUT'] = $input;

    if ($action === 'login') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new ApiError(405, 'POST is required.');
        $now = time();
        $username = strtolower(trim((string) ($input['username'] ?? ''))); $password = (string) ($input['password'] ?? '');
        $ipKey = login_ip_key(); $accountKey = login_account_key($username);
        assert_login_allowed($pdo, $ipKey, $now); assert_login_allowed($pdo, $accountKey, $now);
        if (login_captcha_required($pdo, $ipKey, $accountKey, $now) && !login_captcha_valid($input)) {
            throw new ApiError(422, 'The security answer is incorrect or expired. Try the new image.', login_captcha_issue());
        }
        if ($username === '' || strlen($username) > 80 || $password === '' || (function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) > 200) login_failure($pdo, $username, $ipKey, $accountKey, $now, 'invalid_input');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1'); 
        $stmt->execute([$username]); 
        $record = $stmt->fetch();
        if (!$record || !password_verify($password, $record['password_hash'])) login_failure($pdo, $username, $ipKey, $accountKey, $now, 'invalid_credentials');
        $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key IN (?, ?)')->execute([$ipKey, $accountKey]);
        easysched_captcha_clear();
        session_regenerate_id(true); 
        $_SESSION['user_id'] = (int)$record['id']; 
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        
        // Get current user and prepare response data
        $user = current_user($pdo);
        if (!$user) throw new ApiError(500, 'Failed to retrieve user session');
        
        // Log login and get additional data
        audit($pdo, $user, 'LOGIN');
        
        // Prepare the snapshot
        try {
            $snapshot = bootstrap($pdo, $user);
        } catch (Throwable $e) {
            error_log('Bootstrap error during login: ' . $e->getMessage());
            throw new ApiError(500, 'Failed to load user data.');
        }
        
        // Get security alert if applicable
        $snapshot['security_alert'] = recent_login_security_alert($pdo, (string) $user['username']);
        
        // Send response
        respond(['ok' => true, 'data' => $snapshot]);

    }
    if ($action === 'register') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new ApiError(405, 'POST is required.');
        respond(['ok' => true, 'data' => register_student($pdo, $input)]);
    }
    if ($action === 'request_registration_otp') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError(405, 'POST is required.');
        respond(['ok' => true, 'data' => request_registration_otp($pdo, $input)]);
    }
    if ($action === 'request_password_reset') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError(405, 'POST is required.');
        respond(['ok' => true, 'data' => request_password_reset_otp($pdo, $input)]);
    }
    if ($action === 'reset_password') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError(405, 'POST is required.');
        respond(['ok' => true, 'data' => reset_forgotten_password($pdo, $input)]);
    }
    if ($action === 'registration_options') {
        $programs = $pdo->query('SELECT id, code, name FROM programs WHERE active = 1 ORDER BY code')->fetchAll();
        $sections = $pdo->query('SELECT id, program_id, code, year_level FROM sections WHERE active = 1 ORDER BY code')->fetchAll();
        respond(['ok' => true, 'data' => ['programs' => $programs, 'sections' => $sections]]);
    }
    if ($action === 'health') {
        $pdo->query('SELECT 1')->fetchColumn();
        respond(['ok' => true, 'data' => ['service' => 'EasySched API', 'database' => 'connected']]);
    }
    if ($action === 'logout') {
        $user=require_auth($pdo); require_csrf($input); audit($pdo,$user,'LOGOUT'); $_SESSION=[]; if (ini_get('session.use_cookies')) { $params=session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']); } session_destroy(); respond(['ok'=>true]);
    }

    if ($action === 'contact_message') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new ApiError(405, 'POST is required.');
        }
        require_csrf($input);

        if (trim((string) ($input['website'] ?? '')) !== '') {
            respond(['ok' => true, 'data' => ['message' => 'Your message has been sent.']]);
        }

        $lastSentAt = (int) ($_SESSION['contact_message_sent_at'] ?? 0);
        if ($lastSentAt > 0 && time() - $lastSentAt < 60) {
            throw new ApiError(429, 'Please wait a minute before sending another message.');
        }

        $firstName = input_string($input, 'first_name', 80);
        $lastName = input_string($input, 'last_name', 80);
        $email = input_string($input, 'email', 254);
        $message = input_string($input, 'message', 3000);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiError(422, 'Enter a valid email address.');
        }

        $recipient = easysched_env_string('EASYSCHED_CONTACT_EMAIL');
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiError(503, 'The contact form recipient is not configured.');
        }

        $safeName = htmlspecialchars($firstName . ' ' . $lastName, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $html = '<p><strong>From:</strong> ' . $safeName . '</p>'
            . '<p><strong>Customer email:</strong> ' . $safeEmail . '</p>'
            . '<p><strong>Message:</strong><br>' . $safeMessage . '</p>';

        send_email_message($recipient, 'New EasySched contact message', $html, $email);
        $_SESSION['contact_message_sent_at'] = time();
        respond(['ok' => true, 'data' => ['message' => 'Your message has been sent.']]);
    }

    if (in_array($action, ['audit_summary', 'audit_logs', 'audit_detail', 'audit_export'], true)) {
        $admin = audit_admin_user($pdo);
        $auditInput = $_GET;
        if ($action === 'audit_detail') {
            $auditInput = ['id' => $_GET['id'] ?? 0];
        }
        if ($action === 'audit_summary') {
            respond(['ok' => true, 'data' => audit_summary($pdo, $auditInput)]);
        }
        if ($action === 'audit_detail') {
            $id = (int) ($auditInput['id'] ?? 0);
            if ($id < 1) throw new ApiError(422, 'A valid log id is required.');
            $stmt = $pdo->prepare("SELECT al.id, to_char(al.occurred_at_utc AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || ' PHT' AS occurred_at_utc, al.action, al.entity_type, al.entity_id, al.details_json, al.severity, al.outcome, al.request_path, al.http_method, host(al.source_ip) AS source_ip, al.user_agent, al.correlation_id, u.username, u.display_name, u.role FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE al.id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new ApiError(404, 'Audit event not found.');
            $row['details'] = json_decode((string) $row['details_json'], true) ?: [];
            unset($row['details_json']);
            respond(['ok' => true, 'data' => $row]);
        }
        if ($action === 'audit_export') {
            $result = audit_rows($pdo, $auditInput, true);
            audit($pdo, $admin, 'EXPORT_AUDIT_LOGS', 'audit_logs', null, ['rows' => count($result['rows'])]);
            $lines = [implode(',', array_map('audit_csv_cell', ['Timestamp PHT', 'Actor', 'Username', 'Role', 'Action', 'Target', 'Severity', 'Outcome', 'Source IP', 'Request path', 'Method', 'Correlation ID', 'Summary']))];
            foreach ($result['rows'] as $row) {
                $summary = (string) (($row['details']['summary'] ?? '') ?: $row['action']);
                $lines[] = implode(',', array_map('audit_csv_cell', [$row['occurred_at_utc'], $row['display_name'] ?: $row['username'] ?: 'System', $row['username'] ?: '', $row['role'] ?: '', $row['action'], ($row['entity_type'] ?: '') . ($row['entity_id'] ? ':' . $row['entity_id'] : ''), $row['severity'], $row['outcome'], $row['source_ip'], $row['request_path'], $row['http_method'], $row['correlation_id'], $summary]));
            }
            respond_csv(implode("\r\n", $lines) . "\r\n", 'easysched-audit-logs.csv');
        }
        respond(['ok' => true, 'data' => audit_rows($pdo, $auditInput)]);
    }

    $user = require_auth($pdo);
    if (in_array($action, ['generate','generate_selected','save_schedule','add_schedule','delete_schedule','save_master','import_offerings','change_password','save_settings','save_student_profile','review_registration','request_schedule','request_schedule_change','review_schedule_request','check_room_availability','available_rooms','request_email_change_otp','confirm_email_change'], true)) require_csrf($input);
    if ($action === 'import_offerings') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new ApiError(405, 'POST is required.');
        $admin = require_auth($pdo, ['admin']);
        respond(['ok' => true, 'data' => import_offerings_csv($pdo, $admin, $input)]);
    }
    if ($action === 'request_email_change_otp') {
        respond(['ok' => true, 'data' => request_email_change_otp($pdo, $user, $input)]);
    }
    if ($action === 'confirm_email_change') {
        $result = confirm_email_change($pdo, $user, $input);
        $updatedUser = current_user($pdo);
        if (!$updatedUser) throw new ApiError(401, 'Authentication is required.');
        respond(['ok' => true, 'data' => array_merge($result, ['snapshot' => bootstrap($pdo, $updatedUser)])]);
    }
    if ($action === 'save_student_profile') {
        respond(['ok' => true, 'data' => save_student_profile($pdo, $user, $input)]);
    }
    if ($action === 'available_rooms') {
        require_auth($pdo, ['instructor']);
        respond(['ok' => true, 'data' => available_rooms_for_request($pdo, $user, $input)]);
    }
    if ($action === 'check_room_availability') {
        require_auth($pdo, ['instructor']);
        respond(['ok' => true, 'data' => check_room_availability($pdo, $user, $input)]);
    }
    if ($action === 'request_schedule') {
        require_auth($pdo, ['instructor']);
        respond(['ok' => true, 'data' => create_room_request($pdo, $user, $input)]);
    }
    if ($action === 'request_schedule_change') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new ApiError(405, 'POST is required.');
        }
        require_auth($pdo, ['instructor']);
        respond(['ok' => true, 'data' => create_schedule_change_request($pdo, $user, $input)]);
    }
    if ($action === 'review_schedule_request') respond(['ok' => true, 'data' => review_schedule_request($pdo, $user, $input)]);
    if ($action === 'bootstrap') respond(['ok'=>true,'data'=>bootstrap($pdo,$user)]);
    if ($action === 'schedule_term') {
        $termId = input_int($_GET, 'term_id', 1, 100000000);
        $termStmt = $pdo->prepare('SELECT id FROM academic_terms WHERE id = ? LIMIT 1');
        $termStmt->execute([$termId]);
        if (!$termStmt->fetchColumn()) throw new ApiError(404, 'Academic term not found.');
        $run = active_run($pdo, $termId);
        $schedules = scoped_schedule($pdo, $user, $termId);
        $hasSavedHistoryStmt = $pdo->prepare("SELECT 1 FROM schedule_runs WHERE term_id = ? AND status IN ('PUBLISHED', 'ARCHIVED') LIMIT 1");
        $hasSavedHistoryStmt->execute([$termId]);
        $hasSampleStmt = $pdo->prepare('SELECT 1 FROM schedule_history_samples WHERE term_id = ?');
        $hasSampleStmt->execute([$termId]);
        $isSample = $run === null && !$hasSavedHistoryStmt->fetchColumn() && (bool) $hasSampleStmt->fetchColumn();
        if ($isSample) {
            $schedules = sample_schedule_for_term($pdo, $termId);
        }
        respond(['ok' => true, 'data' => [
            'term_id' => $termId,
            'schedules' => $schedules,
            'has_published_run' => $run !== null,
            'validation' => $run !== null ? validate_published_schedule($pdo, $termId) : null,
            'is_sample' => $isSample
        ]]);
    }
    if ($action === 'schedule_history') {
        $where = ["sr.status IN ('PUBLISHED', 'ARCHIVED')", "se.status = 'PUBLISHED'"];
        $params = [];
        if ($user['role'] === 'instructor') {
            $where[] = 'co.instructor_id = ?';
            $params[] = (int) $user['instructor_id'];
        } elseif ($user['role'] === 'student') {
            $where[] = 'co.section_id = ?';
            $params[] = (int) $user['section_id'];
        }
        $stmt = $pdo->prepare(
            'SELECT sr.id AS run_id, sr.term_id, t.academic_year, t.semester, sr.status,
                    sr.created_at, COUNT(se.id) AS class_count
             FROM schedule_runs sr
             JOIN academic_terms t ON t.id = sr.term_id
             JOIN schedule_entries se ON se.run_id = sr.id
             JOIN course_offerings co ON co.id = se.offering_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY sr.id, sr.term_id, t.academic_year, t.semester
             ORDER BY sr.created_at DESC, sr.id DESC'
        );
        $stmt->execute($params);
        $history = $stmt->fetchAll();
        $sampleTerms = $pdo->query(
            "SELECT s.term_id, t.academic_year, t.semester, s.created_at, s.schedules_json
             FROM schedule_history_samples s
             JOIN academic_terms t ON t.id = s.term_id
             WHERE NOT EXISTS (
                SELECT 1 FROM schedule_runs sr
                WHERE sr.term_id = s.term_id AND sr.status IN ('PUBLISHED', 'ARCHIVED')
             )
             ORDER BY t.academic_year DESC, t.semester DESC"
        )->fetchAll();
        foreach ($sampleTerms as $sampleTerm) {
            $sampleRows = json_decode((string) $sampleTerm['schedules_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($sampleRows)) {
                throw new RuntimeException('Stored sample schedule history is invalid.');
            }
            $history[] = [
                'run_id' => 'sample-' . (int) $sampleTerm['term_id'],
                'term_id' => (int) $sampleTerm['term_id'],
                'academic_year' => $sampleTerm['academic_year'],
                'semester' => $sampleTerm['semester'],
                'status' => 'SAMPLE',
                'created_at' => $sampleTerm['created_at'],
                'class_count' => count($sampleRows),
                'is_sample' => true
            ];
        }
        respond(['ok' => true, 'data' => $history]);
    }
    if ($action === 'schedule_run') {
        $runId = input_int($_GET, 'run_id', 1, 100000000);
        $runStmt = $pdo->prepare("SELECT id, term_id, status, created_at FROM schedule_runs WHERE id = ? AND status IN ('PUBLISHED', 'ARCHIVED') LIMIT 1");
        $runStmt->execute([$runId]);
        $run = $runStmt->fetch();
        if (!$run) throw new ApiError(404, 'Saved schedule not found.');
        $termId = (int) $run['term_id'];
        $scheduleRows = scoped_schedule($pdo, $user, $termId, (int) $run['id']);
        if ($scheduleRows === []) throw new ApiError(404, 'Saved schedule not found.');
        respond(['ok' => true, 'data' => [
            'run_id' => (int) $run['id'],
            'term_id' => $termId,
            'status' => $run['status'],
            'created_at' => $run['created_at'],
            'schedules' => $scheduleRows,
            'has_published_run' => $run['status'] === 'PUBLISHED',
            'validation' => $run['status'] === 'PUBLISHED' ? validate_published_schedule($pdo, $termId) : null
        ]]);
    }
    if ($action === 'export') {
        $runId = input_int($_GET, 'run_id', 1, 100000000, false);
        if ($runId !== null) {
            $runStmt = $pdo->prepare("SELECT id, term_id FROM schedule_runs WHERE id = ? AND status IN ('PUBLISHED', 'ARCHIVED') LIMIT 1");
            $runStmt->execute([$runId]);
            $run = $runStmt->fetch();
            if (!$run) throw new ApiError(404, 'Saved schedule not found.');
            if (scoped_schedule($pdo, $user, (int) $run['term_id'], $runId) === []) {
                throw new ApiError(404, 'Saved schedule not found.');
            }
            export_csv($pdo, $user, (int) $run['term_id'], $runId);
        }
        $termId = input_int($_GET, 'term_id', 1, 100000000, false) ?? active_term_id($pdo);
        $termStmt = $pdo->prepare('SELECT id FROM academic_terms WHERE id = ? LIMIT 1');
        $termStmt->execute([$termId]);
        if (!$termStmt->fetchColumn()) throw new ApiError(404, 'Academic term not found.');
        export_csv($pdo, $user, $termId);
    }
    if ($action === 'export_offerings') { $admin = require_auth($pdo, ['admin']); export_offerings_csv($pdo, $admin, active_term_id($pdo)); }
    if ($action === 'generate') { require_auth($pdo,['admin','scheduler']); $termId=input_int($input,'term_id',1,100000000,false)??active_term_id($pdo); if ($termId !== active_term_id($pdo)) throw new ApiError(422, 'Only the active academic term can be generated.'); $result=generate_schedule($pdo,$user,$termId); respond(['ok'=>true,'data'=>array_merge($result,['snapshot'=>bootstrap($pdo,$user)])]); }
    if ($action === 'generate_selected') { require_auth($pdo,['admin','scheduler']); $termId=input_int($input,'term_id',1,100000000,false)??active_term_id($pdo); if ($termId !== active_term_id($pdo)) throw new ApiError(422, 'Only the active academic term can be generated.'); $result=generate_selected_schedule($pdo,$user,$termId,$input); respond(['ok'=>true,'data'=>array_merge($result,['snapshot'=>bootstrap($pdo,$user)])]); }
    if ($action === 'save_master') { require_auth($pdo,['admin','scheduler']); $result=save_master($pdo,$user,$input); respond(['ok'=>true,'data'=>array_merge($result,['snapshot'=>bootstrap($pdo,$user)])]); }
    if ($action === 'review_registration') { require_auth($pdo, ['admin']); $result = review_registration($pdo, $user, $input); respond(['ok' => true, 'data' => array_merge($result, ['snapshot' => bootstrap($pdo, $user)])]); }
    if ($action === 'request_schedule') { require_auth($pdo, ['instructor']); $offeringId=input_int($input,'offering_id',1,100000000); $roomId=input_int($input,'room_id',1,100000000); $day=input_int($input,'day_of_week',1,5); $start=str_replace(':','',input_string($input,'start_time',5)); $startPeriod=strtoupper(input_string($input,'start_period',2)); $end=str_replace(':','',input_string($input,'end_time',5)); $endPeriod=strtoupper(input_string($input,'end_period',2)); $note=trim((string)($input['note']??'')); if (!preg_match('/^(0[1-9]|1[0-2])[0-5][0-9]$/',$start) || !in_array($startPeriod,['AM','PM'],true) || !preg_match('/^(0[1-9]|1[0-2])[0-5][0-9]$/',$end) || !in_array($endPeriod,['AM','PM'],true)) throw new ApiError(422,'Use valid 12-hour times in HH:MM format with AM or PM.'); $toMinutes=static function(string $value,string $period): int { $hour=(int)substr($value,0,2); $minute=(int)substr($value,2,2); if ($period==='AM' && $hour===12) $hour=0; if ($period==='PM' && $hour!==12) $hour+=12; return $hour*60+$minute; }; $startMinutes=$toMinutes($start,$startPeriod); $endMinutes=$toMinutes($end,$endPeriod); if ($endMinutes <= $startMinutes) throw new ApiError(422,'End time must be later than start time.'); $start24=sprintf('%02d:%02d', intdiv($startMinutes,60), $startMinutes%60); $termId=active_term_id($pdo); if (!$user['instructor_id']) throw new ApiError(403, 'Your account is not linked to a faculty record.'); $q=$pdo->prepare('SELECT id FROM course_offerings WHERE id=? AND term_id=? AND instructor_id=? AND status=\'ACTIVE\''); $q->execute([$offeringId,$termId,(int)$user['instructor_id']]); if(!$q->fetchColumn()) throw new ApiError(404,'Class assignment not found.'); $q=$pdo->prepare('SELECT id FROM rooms WHERE id=? AND active=1'); $q->execute([$roomId]); if(!$q->fetchColumn()) throw new ApiError(404,'Room not found.'); $slotId = input_int($input, 'slot_id', 1, 100000000, false); if ($slotId === null) { $q=$pdo->prepare('SELECT id FROM time_slots WHERE start_time=? LIMIT 1'); $q->execute([$start24]); $slotId=$q->fetchColumn(); } if (!$slotId) throw new ApiError(422,'Start time must match a configured timetable hour.'); $pdo->prepare('INSERT INTO schedule_requests(term_id,offering_id,instructor_id,room_id,day_of_week,slot_id,note) VALUES(?,?,?,?,?,?,?)')->execute([$termId,$offeringId,(int)$user['instructor_id'],$roomId,$day,(int)$slotId,$note]); audit($pdo,$user,'REQUEST_SCHEDULE','schedule_request',(int)$pdo->lastInsertId()); respond(['ok'=>true,'data'=>['snapshot'=>bootstrap($pdo,$user)]]); }
    if ($action === 'review_schedule_request') { require_auth($pdo,['admin']); $id=input_int($input,'request_id',1,100000000); $decision=(string)($input['decision']??''); if(!in_array($decision,['APPROVE','REJECT'],true)) throw new ApiError(422,'Invalid decision.'); $q=$pdo->prepare('SELECT * FROM schedule_requests WHERE id=? AND status=\'PENDING\''); $q->execute([$id]); $req=$q->fetch(); if(!$req) throw new ApiError(404,'Request not found.'); if($decision==='APPROVE'){ $run=active_run($pdo,(int)$req['term_id']); if(!$run) throw new ApiError(409,'Generate a schedule before approving requests.'); $existingEntryIdStmt=$pdo->prepare('SELECT se.id FROM schedule_entries se JOIN schedule_runs sr ON sr.id = se.run_id WHERE se.offering_id = ? AND sr.status = \'PUBLISHED\' AND se.status = \'PUBLISHED\' ORDER BY se.id DESC LIMIT 1'); $existingEntryIdStmt->execute([(int)$req['offering_id']]); $existingEntryId = $existingEntryIdStmt->fetchColumn(); $validated=validate_manual_candidate($pdo,(int)$req['term_id'],(int)$req['offering_id'],(int)$req['room_id'],(int)$req['day_of_week'],(int)$req['slot_id'], $existingEntryId !== false ? (int) $existingEntryId : null); $pdo->beginTransaction(); try{$pdo->prepare("INSERT INTO schedule_entries(run_id,offering_id,meeting_no,room_id,day_of_week,slot_id,status) VALUES(?,?,?,?,?,?,\'PUBLISHED\')")->execute([(int)$run['id'],(int)$req['offering_id'],1,(int)$req['room_id'],(int)$req['day_of_week'],(int)$req['slot_id']]); $entryId=(int)$pdo->lastInsertId(); $ins=$pdo->prepare('INSERT INTO schedule_occupancy(run_id,entry_id,resource_type,resource_id,day_of_week,slot_id) VALUES(?,?,?,?,?,?)'); foreach($validated['candidate']['slot_ids'] as $slot){ foreach ([['ROOM',(int)$req['room_id']],['INSTRUCTOR',(int)$req['instructor_id']],['SECTION',(int)$validated['offering']['section_id']] ] as $res) $ins->execute([(int)$run['id'],$entryId,$res[0],$res[1],(int)$req['day_of_week'],$slot]); } $pdo->prepare("UPDATE schedule_requests SET status='APPROVED',reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$user['id'],$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}else $pdo->prepare("UPDATE schedule_requests SET status='REJECTED',reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$user['id'],$id]); respond(['ok'=>true,'data'=>['snapshot'=>bootstrap($pdo, $user)]]); }
    if ($action === 'save_schedule') {
        require_auth($pdo,['admin','scheduler']); $entryId=input_int($input,'entry_id',1,100000000); $roomId=input_int($input,'room_id',1,100000000); $day=input_int($input,'day_of_week',1,7); [$startTime, $endTime] = parse_manual_schedule_times($input); $termId=active_term_id($pdo); if (!isset(DAY_NAMES[$day])) throw new ApiError(422, 'Schedules may only be placed Monday through Friday.');
        $stmt=$pdo->prepare("SELECT se.*, co.term_id FROM schedule_entries se JOIN schedule_runs sr ON sr.id=se.run_id AND sr.status='PUBLISHED' JOIN course_offerings co ON co.id=se.offering_id WHERE se.id=? AND se.status='PUBLISHED'"); $stmt->execute([$entryId]); $entry=$stmt->fetch(); if(!$entry || (int)$entry['term_id']!==$termId) throw new ApiError(404,'Schedule entry not found.');
        $validated=validate_manual_candidate($pdo,$termId,(int)$entry['offering_id'],$roomId,$day,0,$entryId,false,[$startTime,$endTime],true); $slotId=(int)$validated['candidate']['slot_id']; $pdo->beginTransaction(); try { $pdo->prepare('DELETE FROM schedule_occupancy WHERE entry_id=?')->execute([$entryId]); $pdo->prepare('UPDATE schedule_entries SET room_id=?,day_of_week=?,slot_id=?,start_time_override=?,end_time_override=?,room_fit_override=0 WHERE id=?')->execute([$roomId,$day,$slotId,$startTime,$endTime,$entryId]); $occupancy=$pdo->prepare('INSERT INTO schedule_occupancy (run_id,entry_id,resource_type,resource_id,day_of_week,slot_id) VALUES (?,?,?,?,?,?)'); foreach($validated['candidate']['slot_ids'] as $occupiedSlot){$occupancy->execute([(int)$entry['run_id'],$entryId,'ROOM',$roomId,$day,$occupiedSlot]);$occupancy->execute([(int)$entry['run_id'],$entryId,'INSTRUCTOR',(int)$validated['offering']['instructor_id'],$day,$occupiedSlot]);$occupancy->execute([(int)$entry['run_id'],$entryId,'SECTION',(int)$validated['offering']['section_id'],$day,$occupiedSlot]);} audit($pdo,$user,'UPDATE_SCHEDULE','schedule_entry',$entryId,['room_id'=>$roomId,'day'=>$day,'slot'=>$slotId]);$pdo->commit(); } catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack(); if($error instanceof ApiError)throw $error; throw new ApiError(409,'The schedule entry could not be saved.');} respond(['ok'=>true,'data'=>['snapshot'=>bootstrap($pdo, $user)]]);
    }
    if ($action === 'add_schedule') {
        require_auth($pdo, ['admin', 'scheduler']);
        add_manual_schedule_entry($pdo, $user, $input);
        respond(['ok' => true, 'data' => ['snapshot' => bootstrap($pdo, $user)]]);
    }
    if ($action === 'delete_schedule') {
        require_auth($pdo, ['admin', 'scheduler']);
        $entryId = input_int($input, 'entry_id', 1, 100000000);
        $termId = active_term_id($pdo);
        $stmt = $pdo->prepare("SELECT se.id, se.run_id FROM schedule_entries se JOIN schedule_runs sr ON sr.id = se.run_id AND sr.status = 'PUBLISHED' JOIN course_offerings co ON co.id = se.offering_id WHERE se.id = ? AND se.status = 'PUBLISHED' AND co.term_id = ?");
        $stmt->execute([$entryId, $termId]);
        $entry = $stmt->fetch();
        if (!$entry) throw new ApiError(404, 'Schedule entry not found.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE schedule_entries SET status = 'CANCELLED' WHERE id = ?")->execute([$entryId]);
            $pdo->prepare('DELETE FROM schedule_occupancy WHERE entry_id = ?')->execute([$entryId]);
            $assignedStmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_entries WHERE run_id = ? AND status = 'PUBLISHED'");
            $assignedStmt->execute([(int) $entry['run_id']]);
            $assigned = (int) $assignedStmt->fetchColumn();
            $totalTasks = schedule_run_task_total($pdo, (int) $entry['run_id'], $termId);
            $runStmt = $pdo->prepare('SELECT diagnostics_json FROM schedule_runs WHERE id = ?');
            $runStmt->execute([(int) $entry['run_id']]);
            $diagnostics = json_decode((string) $runStmt->fetchColumn(), true);
            $diagnostics = is_array($diagnostics) ? $diagnostics : [];
            $diagnostics['assigned_tasks'] = $assigned;
            $diagnostics['total_tasks'] = $totalTasks;
            $pdo->prepare('UPDATE schedule_runs SET total_tasks = ?, assigned_tasks = ?, diagnostics_json = ? WHERE id = ?')
                ->execute([$totalTasks, $assigned, encode_json($diagnostics), (int) $entry['run_id']]);
            audit($pdo, $user, 'CANCEL_SCHEDULE', 'schedule_entry', $entryId);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof ApiError) throw $error;
            error_log('EasySched schedule cancellation error: ' . $error->getMessage());
            throw new ApiError(409, 'The class could not be cancelled. Refresh and try again.');
        }
        respond(['ok' => true, 'data' => ['snapshot' => bootstrap($pdo, $user)]]);
    }
    if ($action === 'change_password') { $current=(string)($input['current_password']??'');$next=(string)($input['new_password']??'');$confirm=(string)($input['confirm_password']??'');if($current===''||$next!==$confirm||!password_meets_requirements($next))throw new ApiError(422,'Use a matching password with at least 10 characters, one uppercase letter, one number, and one special character.');$stmt=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([(int)$user['id']]);if(!password_verify($current,(string)$stmt->fetchColumn()))throw new ApiError(401,'The current password is incorrect.');$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($next,PASSWORD_DEFAULT),(int)$user['id']]);audit($pdo,$user,'CHANGE_PASSWORD','user',(int)$user['id']);respond(['ok'=>true,'data'=>['message'=>'Password changed successfully.']]); }
    if ($action === 'save_settings') { require_auth($pdo,['admin']);$year=input_string($input,'academic_year',9);$semester=input_string($input,'semester',30);if(!preg_match('/^20\d{2}-20\d{2}$/',$year)||!in_array($semester,['First Semester','Second Semester','Summer'],true))throw new ApiError(422,'Academic year or semester is invalid.');$stmt=$pdo->prepare('SELECT id FROM academic_terms WHERE academic_year=? AND semester=?');$stmt->execute([$year,$semester]);$termId=(int)($stmt->fetchColumn()?:0);if(!$termId){$pdo->prepare('INSERT INTO academic_terms (academic_year,semester,is_active) VALUES (?,?,1)')->execute([$year,$semester]);$termId=db_insert_id($pdo, 'academic_terms');}$pdo->prepare("UPDATE academic_terms SET is_active=0")->execute();$pdo->prepare('UPDATE academic_terms SET is_active=1 WHERE id=?')->execute([$termId]);$pdo->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES('active_term_id',?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value")->execute([(string)$termId]);audit($pdo,$user,'UPDATE_SETTINGS');respond(['ok'=>true,'data'=>['snapshot'=>bootstrap($pdo,$user)]]); }
    throw new ApiError(404, 'Unknown action.');
}

if (!defined('EASYSCHED_LIBRARY_MODE')) {
    try {
        ob_clean(); // Clear any output from includes
        $requestedAction = (string) ($_GET['action'] ?? $_POST['action'] ?? 'bootstrap');
        if ($requestedAction === 'bootstrap' && empty($_SESSION['user_id'])) {
            throw new ApiError(401, 'Authentication is required.');
        }
        handle(db());
    } catch (ApiError $error) {
        ob_clean();
        respond(['ok' => false, 'error' => $error->getMessage(), 'details' => $error->details], $error->status);
    } catch (PDOException $error) {
        // Do not expose SQL details. Convert integrity failures into an actionable
        // validation response while keeping the server log useful for diagnosis.
        error_log('EasySched database error: ' . $error->getMessage());
        $status = str_starts_with((string) $error->getCode(), '23') ? 409 : 500;
        $message = $status === 409 ? 'The change conflicts with an existing record or referenced data.' : 'The server could not complete that request.';
        ob_clean();
        respond(['ok' => false, 'error' => $message, 'details' => []], $status);
    } catch (Throwable $error) {
        error_log('EasySched API error: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
        ob_clean();
        respond(['ok' => false, 'error' => 'The server could not complete that request.'], 500);
    }
}
