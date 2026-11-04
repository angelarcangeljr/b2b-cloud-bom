<?php
declare(strict_types=1);

/* Settings come from .env (or real environment variables), else from config.php. */
function load_dotenv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (!preg_match('~^[A-Z_][A-Z0-9_]*$~', $key)) continue;
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
            $q = $val[0];
            $val = substr($val, 1, -1);
            if ($q === '"') $val = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $val);
        } else {
            $val = trim(preg_replace('~\s+#.*$~', '', $val));
        }
        if (getenv($key) === false) { putenv("$key=$val"); $_ENV[$key] = $val; }
    }
}
function env(string $key, $default = null)
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}
function env_bool(string $key, bool $default = false): bool
{
    $v = env($key);
    return $v === null ? $default : in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

load_dotenv(__DIR__ . '/../.env');
$cfgFile = __DIR__ . '/../config.php';
if (env('DB_NAME') !== null || env('DB_USER') !== null) {
    $CFG = [
        'db' => [
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int)env('DB_PORT', 3306),
            'name' => env('DB_NAME', 'solution_builder'),
            'user' => env('DB_USER', 'root'),
            'pass' => (string)env('DB_PASS', ''),
        ],
        'app_name' => env('APP_NAME', 'Solution Summary Builder'),
        'session_minutes' => (int)env('SESSION_MINUTES', 480),
        'max_template_mb' => (int)env('MAX_TEMPLATE_MB', 10),
        'debug' => env_bool('APP_DEBUG'),
    ];
} elseif (is_file($cfgFile)) {
    $CFG = require $cfgFile;
} else {
    http_response_code(500);
    exit('Setup needed: copy .env.example to .env (or config.sample.php to config.php) and enter your database details.');
}

ini_set('display_errors', !empty($CFG['debug']) ? '1' : '0');
error_reporting(E_ALL);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('ssb_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// Idle timeout
$idle = 60 * (int)($CFG['session_minutes'] ?? 480);
if (!empty($_SESSION['uid']) && isset($_SESSION['seen']) && time() - $_SESSION['seen'] > $idle) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['seen'] = time();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

function cfg(string $key, $default = null) { global $CFG; return $CFG[$key] ?? $default; }

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['port'] ?? 3306), $d['name']);
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_ok($t): bool { return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
function require_csrf(): void
{
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('This form has expired. Go back, reload the page and try again.');
    }
}

function current_user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, username, full_name, email, role, is_active FROM users WHERE id = ?');
            $st->execute([(int)$_SESSION['uid']]);
            $r = $st->fetch();
            if ($r && (int)$r['is_active'] === 1) $u = $r; else unset($_SESSION['uid']);
        }
    }
    return $u;
}
function is_admin(): bool { $u = current_user(); return $u !== null && $u['role'] === 'admin'; }

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING'])));
    return $u;
}
function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); exit('Only administrators can open this page.'); }
    return $u;
}

function redirect(string $to): void { header('Location: ' . $to); exit; }
function safe_next(?string $n): string
{
    $n = (string)$n;
    return preg_match('~^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.]*)?$~', $n) ? $n : 'index.php';
}

function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $kind]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function tables_ready(): bool
{
    try { db()->query('SELECT 1 FROM users LIMIT 1'); db()->query('SELECT 1 FROM projects LIMIT 1'); db()->query('SELECT 1 FROM bom_templates LIMIT 1'); return true; }
    catch (PDOException $e) { return false; }
}
function users_exist(): bool { return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }

/** A project the user may open: their own, or any project for an admin. */
function load_project(int $id, array $u): ?array
{
    $st = db()->prepare('SELECT p.*, u.full_name AS owner_name, u.username AS owner_username FROM projects p JOIN users u ON u.id = p.user_id WHERE p.id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) return null;
    if ((int)$p['user_id'] !== (int)$u['id'] && $u['role'] !== 'admin') return null;
    return $p;
}

/** The BOM template in effect for a user: their own, else the company default. */
function effective_template(int $uid, bool $withData = true): ?array
{
    $cols = 'id, scope, filename, size_bytes, sheets, uploaded_at' . ($withData ? ', data' : '');
    $st = db()->prepare("SELECT $cols FROM bom_templates WHERE (scope = 'user' AND user_id = ?) OR scope = 'company' ORDER BY scope = 'user' DESC, uploaded_at DESC LIMIT 1");
    $st->execute([$uid]);
    $t = $st->fetch();
    return $t ?: null;
}
function template_meta(?array $t): ?array
{
    if (!$t) return null;
    return ['name' => $t['filename'], 'size' => (int)$t['size_bytes'], 'sheets' => json_decode($t['sheets'], true) ?: [], 'date' => substr((string)$t['uploaded_at'], 0, 10), 'scope' => $t['scope']];
}

function money($v): string { return '$' . number_format((float)$v, 2); }
function display_name(array $u): string { return $u['full_name'] !== '' ? $u['full_name'] : $u['username']; }
function password_problem(string $p, string $confirm): ?string
{
    if (strlen($p) < 10) return 'Use at least 10 characters for the password.';
    if ($p !== $confirm) return 'The two passwords do not match.';
    return null;
}
