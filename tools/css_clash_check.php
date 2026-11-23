<?php
/**
 * Lesson d-11: a .wq-* class defined at the start of a rule in more than one public/brand stylesheet means one file
 * silently restyles the other's pages (wq-timeline, wq-fall, wq-stack all happened). Rules scoped under another class
 * (".wq-page .wq-note") are not counted. Exit 1 when a clash is found.
 *   php tools/css_clash_check.php
 */
$files = glob(__DIR__ . '/../public/brand/*.css');
$owners = [];
foreach ($files as $f) {
    $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents($f));
    // selectors = text before each "{" (skipping @media lines)
    preg_match_all('~([^{}]+)\{~', $css, $m);
    foreach ($m[1] as $sel) {
        if (str_starts_with(trim($sel), '@')) {
            continue;
        }
        foreach (preg_split('~,(?![^()]*\))~', $sel) as $one) {   // top-level commas only, not those inside :is(...)
            if (preg_match('~^\s*\.(wq-[a-z0-9-]+)~', $one, $c)) {
                $owners[$c[1]][basename($f)] = true;
            }
        }
    }
}
$clashes = array_filter($owners, fn ($fs) => count($fs) > 1);
foreach ($clashes as $class => $fs) {
    echo "CLASH .$class in " . implode(', ', array_keys($fs)) . PHP_EOL;
}
echo $clashes ? 'FAIL: ' . count($clashes) . " class(es) defined in more than one stylesheet\n" : "PASS: no .wq-* class is defined in two stylesheets\n";
exit($clashes ? 1 : 0);
