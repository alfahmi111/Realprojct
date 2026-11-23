<?php
/**
 * Builds public/fonts/saudi-riyal.ttf: a one-glyph TrueType font carrying the official Saudi riyal sign at its Unicode
 * code point U+20C1 (Unicode 17.0, adopted by the Saudi Central Bank on 2025-02-20). Most system and web fonts do not
 * have the glyph yet, so the platform ships it and loads it for that one character only (CSS unicode-range): amounts
 * stay plain text (copyable, read by screen readers) and the right symbol appears everywhere.
 *
 * Source drawing: resources/brand/saudi-riyal-symbol.svg (the official design as published, via Wikimedia Commons).
 * Run: php tools/build_riyal_font.php
 */

$root = dirname(__DIR__);
$svg = (string) file_get_contents("$root/resources/brand/saudi-riyal-symbol.svg");
preg_match('/viewBox="([\d.\s-]+)"/', $svg, $vb);
[, , $vbW, $vbH] = array_map('floatval', preg_split('/\s+/', trim($vb[1])));
preg_match_all('/<path[^>]*\sd="([^"]+)"/', $svg, $m);

// Glyph metrics: the sign sits on the baseline and is as tall as Arabic/Latin capitals (~0.70 em).
$UPM = 1000;
$height = 700;
$scale = $height / $vbH;
$side = 70;                                     // side bearing on each side
$tx = fn (float $x) => (int) round($x * $scale) + $side;
$ty = fn (float $y) => (int) round(($vbH - $y) * $scale);

/** Parse an SVG path (M m L l H h V v C c Z z) into contours of [x, y, onCurve] with cubics turned into quadratics. */
function contours(string $d, callable $tx, callable $ty): array
{
    preg_match_all('/([MmLlHhVvCcZz])|(-?\d*\.?\d+(?:e-?\d+)?)/', $d, $tok, PREG_SET_ORDER);
    $out = [];
    $cur = [];
    $x = $y = 0.0;
    $cmd = '';
    $nums = [];
    $flush = function () use (&$cmd, &$nums, &$x, &$y, &$cur, &$out, $tx, $ty) {
        $rel = ctype_lower($cmd);
        $C = strtoupper($cmd);
        $take = function (int $n) use (&$nums) { return array_splice($nums, 0, $n); };   // by reference: consumes the numbers
        while (true) {
            if ($C === 'M' && count($nums) >= 2) {
                [$a, $b] = $take(2);
                if ($cur) { $out[] = $cur; }
                $x = $rel ? $x + $a : $a; $y = $rel ? $y + $b : $b;
                $cur = [[$tx($x), $ty($y), true]];
                $C = 'L';                                  // implicit lineto after moveto
                continue;
            }
            if ($C === 'L' && count($nums) >= 2) {
                [$a, $b] = $take(2);
                $x = $rel ? $x + $a : $a; $y = $rel ? $y + $b : $b;
                $cur[] = [$tx($x), $ty($y), true];
                continue;
            }
            if ($C === 'H' && count($nums) >= 1) {
                [$a] = $take(1);
                $x = $rel ? $x + $a : $a;
                $cur[] = [$tx($x), $ty($y), true];
                continue;
            }
            if ($C === 'V' && count($nums) >= 1) {
                [$a] = $take(1);
                $y = $rel ? $y + $a : $a;
                $cur[] = [$tx($x), $ty($y), true];
                continue;
            }
            if ($C === 'C' && count($nums) >= 6) {
                $p = $take(6);
                $p0 = [$x, $y];
                $p1 = $rel ? [$x + $p[0], $y + $p[1]] : [$p[0], $p[1]];
                $p2 = $rel ? [$x + $p[2], $y + $p[3]] : [$p[2], $p[3]];
                $p3 = $rel ? [$x + $p[4], $y + $p[5]] : [$p[4], $p[5]];
                // Split the cubic into 4 parts; each part becomes one quadratic (control = (3(c1+c2) - (p0+p3)) / 4).
                $pt = function (float $t) use ($p0, $p1, $p2, $p3) {
                    $u = 1 - $t;
                    return [
                        $u * $u * $u * $p0[0] + 3 * $u * $u * $t * $p1[0] + 3 * $u * $t * $t * $p2[0] + $t * $t * $t * $p3[0],
                        $u * $u * $u * $p0[1] + 3 * $u * $u * $t * $p1[1] + 3 * $u * $t * $t * $p2[1] + $t * $t * $t * $p3[1],
                    ];
                };
                $d1 = function (float $t) use ($p0, $p1, $p2, $p3) {   // derivative
                    $u = 1 - $t;
                    return [
                        3 * $u * $u * ($p1[0] - $p0[0]) + 6 * $u * $t * ($p2[0] - $p1[0]) + 3 * $t * $t * ($p3[0] - $p2[0]),
                        3 * $u * $u * ($p1[1] - $p0[1]) + 6 * $u * $t * ($p2[1] - $p1[1]) + 3 * $t * $t * ($p3[1] - $p2[1]),
                    ];
                };
                $n = 4;
                for ($i = 0; $i < $n; $i++) {
                    $ta = $i / $n; $tb = ($i + 1) / $n; $h = $tb - $ta;
                    $a = $pt($ta); $b = $pt($tb); $da = $d1($ta); $db = $d1($tb);
                    $c1 = [$a[0] + $da[0] * $h / 3, $a[1] + $da[1] * $h / 3];
                    $c2 = [$b[0] - $db[0] * $h / 3, $b[1] - $db[1] * $h / 3];
                    $q = [(3 * ($c1[0] + $c2[0]) - ($a[0] + $b[0])) / 4, (3 * ($c1[1] + $c2[1]) - ($a[1] + $b[1])) / 4];
                    $cur[] = [$tx($q[0]), $ty($q[1]), false];
                    $cur[] = [$tx($b[0]), $ty($b[1]), true];
                }
                [$x, $y] = $p3;
                continue;
            }
            break;
        }
    };
    foreach ($tok as $t) {
        if (($t[1] ?? '') !== '') {
            $flush();
            $cmd = $t[1];
            if (strtoupper($cmd) === 'Z') {
                if ($cur) {
                    $last = end($cur);
                    if ($last[0] === $cur[0][0] && $last[1] === $cur[0][1] && $last[2]) { array_pop($cur); }
                    $out[] = $cur;
                    $cur = [];
                }
                $cmd = '';
            }
        } else {
            $nums[] = (float) $t[2];
        }
    }
    $flush();
    if ($cur) { $out[] = $cur; }
    // Drop zero-length steps (e.g. "h0") so no two consecutive points coincide.
    return array_map(function ($c) {
        $clean = [];
        foreach ($c as $p) {
            $prev = end($clean);
            if ($prev && $prev[0] === $p[0] && $prev[1] === $p[1]) { continue; }
            $clean[] = $p;
        }
        return $clean;
    }, $out);
}

$all = [];
foreach ($m[1] as $d) {
    foreach (contours($d, $tx, $ty) as $c) {
        if (count($c) >= 3) { $all[] = $c; }
    }
}

// ---- glyph 1 (the sign) ----
$xs = $ys = [];
foreach ($all as $c) { foreach ($c as $p) { $xs[] = $p[0]; $ys[] = $p[1]; } }
[$xMin, $xMax, $yMin, $yMax] = [min($xs), max($xs), min($ys), max($ys)];
$advance = $xMax + $side;
$g = pack('n', count($all)) . pack('n4', $xMin & 0xFFFF, $yMin & 0xFFFF, $xMax & 0xFFFF, $yMax & 0xFFFF);
$end = -1;
foreach ($all as $c) { $end += count($c); $g .= pack('n', $end); }
$g .= pack('n', 0);                                         // no instructions
$flags = $xd = $yd = '';
$px = $py = 0;
foreach ($all as $c) {
    foreach ($c as [$x, $y, $on]) {
        $flags .= chr($on ? 1 : 0);                          // int16 deltas for x and y
        $xd .= pack('n', ($x - $px) & 0xFFFF);
        $yd .= pack('n', ($y - $py) & 0xFFFF);
        [$px, $py] = [$x, $y];
    }
}
$g .= $flags . $xd . $yd;
if (strlen($g) % 4) { $g .= str_repeat("\0", 4 - strlen($g) % 4); }
$points = array_sum(array_map('count', $all));

$s16 = fn (int $v) => pack('n', $v & 0xFFFF);
$tables = [];
$tables['glyf'] = $g;                                       // glyph 0 (.notdef) is empty
$tables['loca'] = pack('N3', 0, 0, strlen($g));
$tables['head'] = pack('N', 0x00010000) . pack('N', 0x00010000) . pack('N', 0) . pack('N', 0x5F0F3CF5)
    . pack('n', 0x000B) . pack('n', $UPM) . str_repeat("\0", 16)
    . $s16($xMin) . $s16($yMin) . $s16($xMax) . $s16($yMax)
    . pack('n', 0) . pack('n', 8) . $s16(2) . $s16(1) . $s16(0);
$tables['hhea'] = pack('N', 0x00010000) . $s16(800) . $s16(-200) . $s16(0) . pack('n', $advance)
    . $s16(0) . $s16($side) . $s16($xMax) . $s16(1) . $s16(0) . $s16(0) . str_repeat("\0", 8) . $s16(0) . pack('n', 2);
$tables['hmtx'] = pack('n', 500) . $s16(0) . pack('n', $advance) . $s16($xMin);
$tables['maxp'] = pack('N', 0x00010000) . pack('n', 2) . pack('n', $points) . pack('n', count($all)) . pack('n', 0) . pack('n', 0)
    . pack('n', 2) . str_repeat("\0", 18);
$cmap4 = pack('n7', 4, 32, 0, 4, 4, 1, 0)
    . pack('n2', 0x20C1, 0xFFFF) . pack('n', 0) . pack('n2', 0x20C1, 0xFFFF)
    . $s16(1 - 0x20C1) . $s16(1) . pack('n2', 0, 0);
$tables['cmap'] = pack('n2', 0, 1) . pack('n2N', 3, 1, 12) . $cmap4;
$tables['post'] = pack('N', 0x00030000) . pack('N', 0) . $s16(-100) . $s16(50) . pack('N', 0) . pack('N4', 0, 0, 0, 0);
$tables['OS/2'] = pack('n', 4) . $s16(intdiv($advance, 1)) . pack('n', 400) . pack('n', 5) . pack('n', 0)
    . $s16(650) . $s16(600) . $s16(0) . $s16(75) . $s16(650) . $s16(600) . $s16(0) . $s16(350) . $s16(50) . $s16(259)
    . $s16(0) . str_repeat("\0", 10) . pack('N4', 0, 0, 0, 0) . 'WTHQ' . pack('n', 0x0040)
    . pack('n', 0x20C1) . pack('n', 0x20C1) . $s16(800) . $s16(-200) . $s16(0) . pack('n', 800) . pack('n', 200)
    . pack('N2', 1, 0) . $s16(500) . $s16(700) . pack('n', 0) . pack('n', 0x20C1) . pack('n', 0);
$names = [0 => 'Official design: Saudi Central Bank (2025). Packaged for the Withaq platform.', 1 => 'Withaq Riyal', 2 => 'Regular', 3 => 'Withaq Riyal Regular 1.0', 4 => 'Withaq Riyal', 5 => 'Version 1.0', 6 => 'WithaqRiyal-Regular'];
$recs = $str = '';
foreach ($names as $id => $v) {
    $u = mb_convert_encoding($v, 'UTF-16BE', 'UTF-8');
    $recs .= pack('n6', 3, 1, 0x0409, $id, strlen($u), strlen($str));
    $str .= $u;
}
$tables['name'] = pack('n3', 0, count($names), 6 + 12 * count($names)) . $recs . $str;

// ---- assemble ----
ksort($tables, SORT_STRING);
$checksum = function (string $data): int {
    $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
    $sum = 0;
    foreach (unpack('N*', $data) as $w) { $sum = ($sum + $w) & 0xFFFFFFFF; }
    return $sum;
};
$n = count($tables);
$es = (int) floor(log($n, 2));
$sr = (2 ** $es) * 16;
$font = pack('N', 0x00010000) . pack('n4', $n, $sr, $es, $n * 16 - $sr);
$offset = 12 + 16 * $n;
$dir = $body = '';
foreach ($tables as $tag => $data) {
    $dir .= str_pad($tag, 4) . pack('N3', $checksum($data), $offset, strlen($data));
    $padded = $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
    $body .= $padded;
    $offset += strlen($padded);
}
$font .= $dir . $body;
$adj = (0xB1B0AFBA - $checksum($font)) & 0xFFFFFFFF;
$headPos = strpos($dir, 'head');
$headOffset = unpack('N', substr($dir, $headPos + 8, 4))[1];
$font = substr_replace($font, pack('N', $adj), $headOffset + 8, 4);

file_put_contents("$root/public/fonts/saudi-riyal.ttf", $font);
printf("public/fonts/saudi-riyal.ttf:%d bytes, %d contours, %d points, advance %d, bbox %d,%d..%d,%d\n", strlen($font), count($all), $points, $advance, $xMin, $yMin, $xMax, $yMax);
