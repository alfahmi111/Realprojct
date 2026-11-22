<?php
/**
 * PreToolUse guard — spec 11.7.
 * Blocks (exit 2) application-code writes and build/bootstrap commands until scripts/preflight.php
 * has passed within the last 24 hours. Setup files stay writable so the environment can be fixed.
 */

const ALLOWED = ['docs/', 'scripts/', 'tools/', '.claude/', '.preflight/', '.env', '.env.example', 'CLAUDE.md', 'PROMPT.md', 'README.md', '.gitignore'];
const MAX_AGE_SECONDS = 86400; // 24 hours
const BUILD_CMD = '/(composer\s+(create-project|require|install|update))|(php\s+artisan)|(npm\s+(install|ci|run))|(npx\s)|(laravel\s+new)/i';

$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$root = getenv('CLAUDE_PROJECT_DIR') ?: ($input['cwd'] ?? getcwd());
$norm = fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
$root = $norm((string) $root);

function freshPass(string $root): bool
{
    $m = $root . '/.preflight/PASS';
    return is_file($m) && (time() - filemtime($m)) < MAX_AGE_SECONDS;
}
function block(string $why): void
{
    fwrite(STDERR, "BLOCKED by preflight gate (spec 11.7): $why\n");
    fwrite(STDERR, "Run: php scripts/preflight.php — fix every FAIL in docs/PREFLIGHT_REPORT.md (ask the owner for installs on the machine), then retry.\n");
    exit(2);
}

$tool = (string) ($input['tool_name'] ?? '');
$ti = $input['tool_input'] ?? [];

if ($tool === 'Bash' || $tool === 'PowerShell') {
    if (preg_match(BUILD_CMD, (string) ($ti['command'] ?? '')) && !freshPass($root)) {
        block('build/bootstrap command not allowed before a passing preflight.');
    }
    exit(0);
}

$fp = (string) ($ti['file_path'] ?? $ti['notebook_path'] ?? '');
if ($fp === '') exit(0);
$fp = $norm($fp);
$rel = (stripos($fp, $root . '/') === 0) ? substr($fp, strlen($root) + 1) : $fp;
foreach (ALLOWED as $a) {
    if (str_ends_with($a, '/') ? stripos($rel, $a) === 0 : strcasecmp($rel, $a) === 0) exit(0);
}
if (!freshPass($root)) block("writing $rel is not allowed before a passing preflight.");
exit(0);
