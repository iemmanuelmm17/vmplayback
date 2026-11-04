<?php
declare(strict_types=1);

// Keep configuration and private audio outside the web document root.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        if (getenv(trim($name)) === false) putenv(trim($name) . '=' . trim(trim($value), "\"'"));
    }
}
function env(string $key, string $default = ''): string { return getenv($key) !== false ? (string)getenv($key) : $default; }
function config(): array {
    static $config;
    if ($config !== null) return $config;
    $config = [
        'base_url' => rtrim(env('APP_URL'), '/'),
        'api_url' => rtrim(env('NS_API_URL', 'https://nms1.nyc.birns.net/ns-api/v2'), '/'),
        'token' => env('NS_API_TOKEN'), 'key' => env('APP_KEY'),
        'browser_key' => env('BROWSER_ACCESS_KEY'), 'phone_key' => env('WEBRESPONDER_KEY'),
        'mailboxes' => array_filter(array_map('trim', explode(',', env('ALLOWED_MAILBOXES')))),
        'media_hosts' => array_filter(array_map('trim', explode(',', env('MEDIA_ALLOWED_HOSTS', 'nms1.nyc.birns.net')))),
        'storage' => env('STORAGE_PATH', dirname(__DIR__) . '/var'),
        'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
        'phone_codec' => env('PHONE_AUDIO_CODEC', 'pcm_s16le'),
        'trash_confirmed' => env('NS_DELETE_MOVES_TO_TRASH', 'false') === 'true',
    ];
    return $config;
}
function requireConfig(): void {
    $c = config();
    foreach (['base_url', 'token', 'key', 'browser_key', 'phone_key', 'mailboxes'] as $key) {
        if (empty($c[$key])) throw new RuntimeException('Server configuration is incomplete. See README.');
    }
    if (strlen($c['key']) < 32 || strlen($c['browser_key']) < 24 || strlen($c['phone_key']) < 24) {
        throw new RuntimeException('Configure strong application and access keys.');
    }
    foreach (['base_url', 'api_url'] as $key) {
        if (parse_url($c[$key], PHP_URL_SCHEME) !== 'https' && env('APP_ENV') !== 'development') {
            throw new RuntimeException('HTTPS is required.');
        }
    }
    foreach (['audio', 'calls'] as $dir) {
        $path = $c['storage'] . '/' . $dir;
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Private storage is not writable.');
    }
}
function input(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (!is_scalar($v)) throw new InvalidArgumentException('Invalid request parameter.');
    return (string)$v;
}
function mailbox(string $domain, string $user): array {
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/', $domain) || !preg_match('/\A[0-9]{1,12}\z/', $user)) {
        throw new InvalidArgumentException('Enter a valid domain and numeric extension.');
    }
    if (!in_array($user . '@' . $domain, config()['mailboxes'], true)) throw new InvalidArgumentException('This mailbox is not allowed.');
    return ['domain' => $domain, 'user' => $user];
}
function folder(string $folder): string {
    if (!in_array($folder, ['new', 'save', 'trash'], true)) throw new InvalidArgumentException('Invalid folder.');
    return $folder;
}
function filename(string $file): string {
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,249}\.wav\z/i', $file) || str_contains($file, '..')) throw new InvalidArgumentException('Invalid voicemail filename.');
    return $file;
}
function speed(float $value): float { return max(0.5, min(2.0, round($value * 4) / 4)); }
function escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_XML1, 'UTF-8'); }
function url(string $path, array $query = []): string { return config()['base_url'] . '/' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''); }
function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode($data, JSON_THROW_ON_ERROR); exit;
}
function securityHeaders(): void {
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; media-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'");
}
function browserSession(): void {
    session_name('vmplayback');
    session_start(['cookie_httponly' => true, 'cookie_secure' => parse_url(config()['base_url'], PHP_URL_SCHEME) === 'https', 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function browserAuthorized(): bool { return isset($_SESSION['auth_until']) && $_SESSION['auth_until'] > time(); }
function csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? input('csrf'))) throw new InvalidArgumentException('Invalid form token. Reload the page.');
}
require_once __DIR__ . '/NetSapiens.php';
require_once __DIR__ . '/Audio.php';
require_once __DIR__ . '/Phone.php';
