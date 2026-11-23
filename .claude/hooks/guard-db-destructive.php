<?php
/**
 * PreToolUse guard — PROJECT_LOCK د-1.
 * Blocks (exit 2) any command that wipes or rebuilds a database. `migrate:fresh` once erased the owner's account
 * on the development database `property_platform`. Tests never need these commands: they rebuild the separate
 * `testing` schema themselves (RefreshDatabase). Only `php artisan migrate` (additive) is allowed.
 */

const DESTRUCTIVE = '/\b(migrate:(fresh|refresh|reset|rollback)|db:wipe|schema:drop)\b|\bdrop\s+(database|schema|table|owned)\b|\btruncate\s+(table\s+)?\w|\bdropdb\b/i';

$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$tool = (string) ($input['tool_name'] ?? '');
if ($tool !== 'Bash' && $tool !== 'PowerShell') {
    exit(0);
}
$cmd = (string) ($input['tool_input']['command'] ?? '');
// A literal mention inside a commit message or a search pattern is not an execution.
$probe = preg_replace('/(git\s+commit\b.*|grep\b.*|rg\b.*|Select-String\b.*)$/is', '', $cmd);
if (preg_match(DESTRUCTIVE, (string) $probe, $m)) {
    fwrite(STDERR, "BLOCKED (PROJECT_LOCK د-1): «{$m[0]}» يمسح قاعدة بيانات أو يعيد بناءها.\n");
    fwrite(STDERR, "قاعدة التطوير property_platform لا تُمسح أبداً — استعمل `php artisan migrate` فقط. الاختبارات تعيد بناء مخطط testing بنفسها.\n");
    fwrite(STDERR, "إن كان المالك قد طلب ذلك صراحةً فليشغّل الأمر بنفسه.\n");
    exit(2);
}
exit(0);
