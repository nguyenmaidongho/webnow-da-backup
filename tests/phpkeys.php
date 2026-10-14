<?php
$en = require 'C:/laragon/www/vaultbackup/includes/lang/en.php';
$keys = [];
foreach (glob('C:/laragon/www/vaultbackup/includes/*.php') as $f) {
    if (basename($f) === 'Client.php' || basename($f) === 'Ui.php') continue; // phía web, không dịch server-side
    foreach (token_get_all(file_get_contents($f)) as $t) {
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && $t[1][0] === "'") {
            $v = str_replace("\'", "'", substr($t[1], 1, -1));
            if (preg_match('/[\x{00C0}-\x{1EF9}]/u', $v)) $keys[$v] = true;
        }
    }
}
$skip = ['Ổ cứng máy chủ', 'Sao lưu hằng ngày', 'Không chạy được lệnh'];
$missing = 0;
foreach (array_keys($keys) as $k) {
    if (in_array($k, $skip, true)) continue;
    if (!isset($en[$k])) { echo "MISSING: $k\n"; $missing++; continue; }
    preg_match_all('/\{\w+\}/', $k, $a); preg_match_all('/\{\w+\}/', $en[$k], $b);
    sort($a[0]); sort($b[0]);
    if ($a[0] !== $b[0]) echo "VARS MISMATCH: $k\n";
}
echo "keys " . count($keys) . " missing $missing\n";
