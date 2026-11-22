<?php
/**
 * Preflight environment check — spec section 11.7 (dev environment: spec 11.6).
 * Written in PHP so it runs on Windows (AMPPS) and on any other system without extra tools.
 *
 *   php scripts/preflight.php          full report; exit 0 = every MUST check passed
 *   php scripts/preflight.php --hook   same checks; always exit 0; short summary for the Claude Code SessionStart hook
 */

$ROOT = realpath(__DIR__ . '/..');
chdir($ROOT);
$HOOK = in_array('--hook', $argv ?? [], true);
$REPORT = 'docs/PREFLIGHT_REPORT.md';
$MARK = '.preflight/PASS';
@mkdir('docs', 0777, true);
@mkdir('.preflight', 0777, true);

$rows = [];
$mustFail = 0;
$warns = 0;
function add(string $status, string $cat, string $check, string $detail): void
{
    global $rows, $mustFail, $warns;
    $detail = trim(preg_replace('/\s+/', ' ', str_replace('|', '/', $detail)));
    $rows[] = "| $status | $cat | $check | $detail |";
    if ($status === 'FAIL') $mustFail++;
    if ($status === 'WARN') $warns++;
}
function run(string $cmd): ?string
{
    $out = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $out, $code);
    return $code === 0 ? trim(implode("\n", $out)) : null;
}
function ver(?string $s): ?string
{
    return ($s !== null && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $s, $m)) ? $m[1] : null;
}
function envFile(): ?array
{
    if (!is_file('.env')) return null;
    $env = [];
    foreach (file('.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
            $env[$m[1]] = trim($m[2], " \t\"'");
        }
    }
    return $env;
}

// ---------------------------------------------------------------- Tools
($v = ver(run('git --version'))) ? add('PASS', 'tools', 'git', $v) : add('FAIL', 'tools', 'git', 'not found — install Git for Windows (git-scm.com)');
$v = ver(run('composer --version --no-ansi'));
if ($v === null) add('FAIL', 'tools', 'composer', 'not found — install Composer 2 (getcomposer.org; on Windows use Composer-Setup.exe and point it to the AMPPS php.exe)');
elseif (version_compare($v, '2.0', '>=')) add('PASS', 'tools', 'composer', $v);
else add('FAIL', 'tools', 'composer', "found $v, need 2.x");
$v = ver(run('node --version'));
if ($v === null) add('FAIL', 'tools', 'node', 'not found — install Node.js 20 LTS or newer (nodejs.org)');
elseif (version_compare($v, '20.0', '>=')) add('PASS', 'tools', 'node', $v);
else add('FAIL', 'tools', 'node', "found $v, need 20+");
($v = ver(run('npm --version'))) ? add('PASS', 'tools', 'npm', $v) : add('FAIL', 'tools', 'npm', 'not found (comes with Node.js)');

// ---------------------------------------------------------------- PHP
$pv = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
if (version_compare($pv, '8.3', '>=') && version_compare($pv, '8.6', '<')) add('PASS', 'php', 'version', "$pv (accepted 8.3–8.5)");
else add('FAIL', 'php', 'version', "found $pv, need 8.3–8.5 — switch the PHP version in AMPPS");

$env = envFile();
$usesRedis = $env && (in_array('redis', [$env['QUEUE_CONNECTION'] ?? '', $env['CACHE_STORE'] ?? '', $env['SESSION_DRIVER'] ?? ''], true));
$EXT = ['pdo_pgsql', 'pgsql', 'mbstring', 'intl', 'bcmath', 'zip', 'xml', 'dom', 'curl', 'openssl', 'fileinfo', 'tokenizer', 'ctype'];
foreach ($EXT as $e) {
    extension_loaded($e) ? add('PASS', 'php-ext', $e, 'loaded')
        : add('FAIL', 'php-ext', $e, "missing — enable $e (AMPPS: PHP → Extensions, or uncomment extension=$e in php.ini)");
}
(extension_loaded('gd') || extension_loaded('imagick')) ? add('PASS', 'php-ext', 'gd/imagick', 'loaded')
    : add('FAIL', 'php-ext', 'gd/imagick', 'missing — enable gd');
if ($usesRedis) {
    extension_loaded('redis') ? add('PASS', 'php-ext', 'redis', 'loaded (Redis is used in .env)')
        : add('FAIL', 'php-ext', 'redis', '.env uses Redis but the redis extension is missing');
} else {
    add('PASS', 'php-ext', 'redis', 'not required in development (.env does not use Redis; production adds it — spec 11.6)');
}

// Hijri (Umm al-Qura) via ICU — 2026-11-20 must fall in year 1448 AH
if (class_exists('IntlDateFormatter')) {
    $f = new IntlDateFormatter('en_US@calendar=islamic-umalqura', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, 'y');
    $hy = $f->format(new DateTime('2026-11-20 12:00:00', new DateTimeZone('UTC')));
    $hy === '1448' ? add('PASS', 'hijri', 'umm-al-qura', '2026-11-20 → year 1448 AH')
        : add('FAIL', 'hijri', 'umm-al-qura', "ICU calendar check failed (got: $hy) — need intl with ICU islamic-umalqura");
} else {
    add('FAIL', 'hijri', 'umm-al-qura', 'intl extension missing');
}
date_default_timezone_get() === 'UTC' ? add('PASS', 'config', 'php-timezone', 'UTC')
    : add('WARN', 'config', 'php-timezone', date_default_timezone_get() . ' — the application forces UTC (spec 4.7)');
strtoupper((string) ini_get('default_charset')) === 'UTF-8' ? add('PASS', 'config', 'charset', 'UTF-8')
    : add('FAIL', 'config', 'charset', 'default_charset is ' . ini_get('default_charset') . ' — set default_charset = "UTF-8" in php.ini');

// ---------------------------------------------------------------- .env
$NEED = ['APP_TIMEZONE', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'QUEUE_CONNECTION', 'CACHE_STORE', 'FILESYSTEM_DISK'];
if ($env === null) {
    add('FAIL', 'config', '.env', 'missing — copy .env.example to .env and fill the values');
} else {
    $missing = array_values(array_filter($NEED, fn($k) => ($env[$k] ?? '') === ''));
    $missing ? add('FAIL', 'config', '.env', 'missing values: ' . implode(' ', $missing)) : add('PASS', 'config', '.env', 'required keys present');
    ($env['DB_CONNECTION'] ?? '') === 'pgsql' ? add('PASS', 'config', 'DB_CONNECTION', 'pgsql')
        : add('FAIL', 'config', 'DB_CONNECTION', 'must be pgsql (found: ' . ($env['DB_CONNECTION'] ?? '') . ')');
    ($env['APP_TIMEZONE'] ?? '') === 'UTC' ? add('PASS', 'config', 'APP_TIMEZONE', 'UTC')
        : add('FAIL', 'config', 'APP_TIMEZONE', 'must be UTC (spec 4.7) (found: ' . ($env['APP_TIMEZONE'] ?? '') . ')');
}

// ---------------------------------------------------------------- Services
if ($env !== null && extension_loaded('pdo_pgsql')) {
    try {
        $pdo = new PDO("pgsql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD'] ?? '', [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $num = (int) $pdo->query('SHOW server_version_num')->fetchColumn();
        $tz = (string) $pdo->query('SHOW timezone')->fetchColumn();
        $num >= 160000 ? add('PASS', 'service', 'postgresql', "server_version_num=$num") : add('FAIL', 'service', 'postgresql', "version $num < 16");
        $tz === 'UTC' ? add('PASS', 'service', 'postgresql-timezone', 'UTC')
            : add('WARN', 'service', 'postgresql-timezone', "$tz — run: ALTER DATABASE {$env['DB_DATABASE']} SET timezone TO 'UTC';");
    } catch (Throwable $e) {
        add('FAIL', 'service', 'postgresql', 'cannot connect: ' . substr($e->getMessage(), 0, 160) . ' — is PostgreSQL installed and running, and do the database and user in .env exist?');
    }
} elseif ($env !== null) {
    add('FAIL', 'service', 'postgresql', 'cannot test: pdo_pgsql extension missing');
}
if ($usesRedis) {
    try {
        $r = new Redis();
        $r->connect($env['REDIS_HOST'] ?? '127.0.0.1', (int) ($env['REDIS_PORT'] ?? 6379), 3);
        $rv = $r->info('server')['redis_version'] ?? '0';
        version_compare($rv, '7.0', '>=') ? add('PASS', 'service', 'redis', $rv) : add('FAIL', 'service', 'redis', "version $rv < 7");
    } catch (Throwable $e) {
        add('FAIL', 'service', 'redis', 'cannot connect: ' . $e->getMessage());
    }
}
function http_code(string $url): int
{
    if (!function_exists('curl_init')) return 0;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
    curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $code;
}
if ($env !== null && ($env['FILESYSTEM_DISK'] ?? '') === 's3') {
    $ep = $env['AWS_ENDPOINT'] ?? '';
    $ep !== '' && http_code($ep) > 0 ? add('PASS', 'service', 'object-storage', "$ep reachable")
        : add('FAIL', 'service', 'object-storage', "AWS_ENDPOINT unreachable or empty ($ep)");
}

// ---------------------------------------------------------------- Network
foreach (['https://repo.packagist.org/packages.json', 'https://registry.npmjs.org/'] as $u) {
    $c = http_code($u);
    $c === 200 ? add('PASS', 'network', $u, 'HTTP 200') : add('FAIL', 'network', $u, "unreachable (HTTP $c)");
}

// ---------------------------------------------------------------- Disk
$free = @disk_free_space($ROOT);
$free !== false && $free >= 5 * 1024 ** 3 ? add('PASS', 'disk', 'free', round($free / 1024 ** 3) . ' GB')
    : add('WARN', 'disk', 'free', ($free === false ? '?' : round($free / 1024 ** 3)) . ' GB (< 5 GB)');

// ---------------------------------------------------------------- Repository
run('git rev-parse --is-inside-work-tree') === 'true' ? add('PASS', 'repo', 'git', 'inside a git work tree')
    : add('FAIL', 'repo', 'git', 'not a git repository — run: git init');
foreach (['docs/SPEC.md', 'docs/TRACEABILITY.md', 'PROMPT.md', 'CLAUDE.md'] as $f) {
    is_file($f) ? add('PASS', 'repo', $f, 'present') : add('FAIL', 'repo', $f, 'missing');
}

// ---------------------------------------------------------------- Report
$now = gmdate('Y-m-d\TH:i:s\Z');
$table = "| Status | Category | Check | Detail |\n|---|---|---|---|\n" . implode("\n", $rows) . "\n";
file_put_contents($REPORT, "# Preflight report\n\n- Run at: $now (UTC)\n- MUST failures: $mustFail · Warnings: $warns\n\n" . $table);
if ($mustFail === 0) {
    file_put_contents($MARK, $now . "\n");
    $status = 'PASS';
} else {
    @unlink($MARK);
    $status = 'FAIL';
}

if ($HOOK) {
    echo "PREFLIGHT: $status (MUST failures: $mustFail, warnings: $warns). Full report: $REPORT\n";
    if ($status === 'FAIL') {
        echo "Application code writes and build commands are BLOCKED until preflight passes (spec 11.7). Failing checks:\n";
        foreach ($rows as $r) if (str_starts_with($r, '| FAIL')) echo $r, "\n";
        echo "Fix what is inside the project; ask the owner for installs on the machine with exact steps; then rerun: php scripts/preflight.php\n";
    }
    exit(0);
}
echo $table, "\nPREFLIGHT: $status — report written to $REPORT\n";
exit($status === 'PASS' ? 0 : 1);
