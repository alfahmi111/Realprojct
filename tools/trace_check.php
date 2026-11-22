<?php
/**
 * Traceability check for Claude Code (run before every pull request): php tools/trace_check.php
 *
 * Fails (exit 1) if any requirement marked DONE in docs/TRACEABILITY.md has no tests, lists a missing
 * test file, or none of its test files mention the requirement ID; or if the IDs in TRACEABILITY.md
 * differ from the register in PROMPT.md. Prints progress per phase.
 */
$root = realpath($argv[1] ?? __DIR__ . '/..');
$rows = [];
foreach (file("$root/docs/TRACEABILITY.md", FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^\|\s*(REQ-\d{4})\s*\|/', $line)) {
        $c = array_map('trim', explode('|', trim($line, " |")));
        $rows[] = ['id' => $c[0], 'kind' => $c[2], 'phase' => $c[3], 'status' => $c[4], 'tests' => $c[6] ?? ''];
    }
}
preg_match_all('/^- (REQ-\d{4}) \[/m', file_get_contents("$root/PROMPT.md"), $m);
$errors = [];
if (array_column($rows, 'id') !== $m[1]) {
    $errors[] = 'TRACEABILITY.md IDs differ from the register in PROMPT.md — regenerate with tools/build_register.py';
}
foreach ($rows as $r) {
    if ($r['status'] !== 'DONE') continue;
    $tests = array_filter(array_map('trim', preg_split('/[,،]/u', $r['tests'])));
    if (!$tests) { $errors[] = "{$r['id']}: DONE without tests"; continue; }
    $found = false;
    foreach ($tests as $t) {
        $f = "$root/$t";
        if (!is_file($f)) { $errors[] = "{$r['id']}: test file not found: $t"; continue; }
        if (str_contains(file_get_contents($f), $r['id'])) $found = true;
    }
    if (!$found) $errors[] = "{$r['id']}: none of its test files mention {$r['id']}";
}
$count = [];
foreach ($rows as $r) if ($r['kind'] === 'BUILD') $count["{$r['phase']}  {$r['status']}"] = ($count["{$r['phase']}  {$r['status']}"] ?? 0) + 1;
ksort($count);
echo "BUILD requirements by phase/status:\n";
foreach ($count as $k => $n) echo "  $k  $n\n";
if ($errors) { echo "\nFAIL:\n  - " . implode("\n  - ", $errors) . "\n"; exit(1); }
echo "\nPASS: every DONE requirement is backed by a test that names it.\n";
