<?php
/**
 * PostToolUse check — PROJECT_LOCK د-2.
 * After any edit or shell command: every PHP file changed in the last 3 minutes must pass `php -l`, and no tracked
 * file may have become empty. A shell `preg_replace` once returned null and emptied three files silently.
 * On a problem it returns {"decision":"block","reason":…} so Claude must fix it before going on.
 */

$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$root = rtrim(str_replace('\\', '/', (string) (getenv('CLAUDE_PROJECT_DIR') ?: ($input['cwd'] ?? getcwd()))), '/');
if (!is_dir("$root/.git")) {
    exit(0);
}

$git = fn (string $args): array => array_values(array_filter(array_map('trim', explode("\n", (string) shell_exec('git -C ' . escapeshellarg($root) . " $args 2>NUL")))));
$changed = array_unique(array_merge($git('diff --name-only HEAD'), $git('ls-files --others --exclude-standard')));

$problems = [];
$recent = time() - 180;
foreach ($changed as $rel) {
    $path = "$root/$rel";
    if (!is_file($path) || str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'node_modules/')) {
        continue;
    }
    if (filesize($path) === 0 && !in_array($rel, $git('ls-files --others --exclude-standard'), true)) {
        $problems[] = "الملف صار فارغاً: $rel (استعده بـ git checkout -- $rel إن لم يكن مقصوداً)";
        continue;
    }
    if (str_ends_with($rel, '.php') && filemtime($path) >= $recent) {
        $out = (string) shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        if (!str_contains($out, 'No syntax errors')) {
            $problems[] = "خطأ صياغة في $rel: " . trim(preg_replace('/\s+/', ' ', $out));
        }
    }
}

if ($problems) {
    echo json_encode(['decision' => 'block', 'reason' => "فحص ما بعد التعديل (PROJECT_LOCK د-2):\n- " . implode("\n- ", $problems) . "\nأصلح هذا قبل أي خطوة أخرى."], JSON_UNESCAPED_UNICODE);
}
exit(0);
