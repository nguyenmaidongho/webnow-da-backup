<?php
// Test: database theo lô trong restic (2.4.12).
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok()).

$GB = 1073741824;
$MB = 1048576;

// Không đọc được dung lượng / chỗ trống: dump thẳng từng database như trước.
[$b, $s] = WNBEngine::planDbBatches(['u_a', 'u_b'], null, 100 * $GB);
ok($b === [] && $s === ['u_a', 'u_b'], 'không biết dung lượng thì không gom lô');
[$b, $s] = WNBEngine::planDbBatches(['u_a'], [], false);
ok($b === [] && $s === ['u_a'], 'không biết chỗ trống thì không gom lô');

// 1200 database nhỏ: lô 200 cái.
$dbs = [];
$sizes = [];
for ($i = 0; $i < 1200; $i++) {
    $dbs[] = 'web1_db' . $i;
    $sizes['web1_db' . $i] = 2 * $MB;
}
unset($sizes['web1_db5']); // chưa có bảng nào
[$b, $s] = WNBEngine::planDbBatches($dbs, $sizes, 100 * $GB);
ok(count($b) === 6 && count($b[0]) === 200 && $s === [] && array_merge(...$b) === $dbs, '1200 database nhỏ chia 6 lô: ' . count($b));

// Database lớn và tên lạ dump thẳng; lô không vượt 2 GB.
$sizes = ['u_big' => 5 * $GB, 'u_a' => 900 * $MB, 'u_b' => 900 * $MB, 'u_c' => 900 * $MB, 'u_x.y' => 1];
[$b, $s] = WNBEngine::planDbBatches(array_keys($sizes), $sizes, 100 * $GB);
ok($b === [['u_a', 'u_b'], ['u_c']] && $s === ['u_big', 'u_x.y'], 'lô tối đa 2 GB, database lớn/tên lạ dump thẳng: ' . json_encode([$b, $s]));

// Ít chỗ trống: lô nhỏ lại theo chỗ trống, hết chỗ thì dump thẳng hết.
[$b, $s] = WNBEngine::planDbBatches(['u_a', 'u_b'], ['u_a' => 350 * $MB, 'u_b' => 350 * $MB], 2 * $GB);
ok($b === [['u_a'], ['u_b']] && $s === [], 'còn 2 GB trống: mỗi lô ~680 MB: ' . json_encode($b));
[$b, $s] = WNBEngine::planDbBatches(['u_a'], ['u_a' => 1], 0.8 * $GB);
ok($b === [] && $s === ['u_a'], 'gần hết chỗ: không ghi dump ra đĩa');

// Đường dẫn file dump trong snapshot.
$r = ['dbs' => ['u_a' => 'aa', 'u_b' => 'bb'], 'db_files' => ['u_a' => '/backup/.staging/rdb/u/u_a.sql', 'u_c' => '/x/../etc/u_c.sql']];
ok(WNBRestic::dbFile($r, 'u_a') === '/backup/.staging/rdb/u/u_a.sql' && WNBRestic::dbFile($r, 'u_b') === '/u_b.sql' && WNBRestic::dbFile($r, 'u_c') === '/u_c.sql', 'đường dẫn dump theo lô / dump thẳng / không hợp lệ');

$sid = fn($c) => str_repeat($c, 64);
ok(WNBRestic::snapshotIds(['restic' => ['home' => $sid('a'), 'dbs' => ['u_a' => $sid('b'), 'u_b' => $sid('b'), 'u_c' => $sid('c')]]]) === [$sid('a'), $sid('b'), $sid('c')], 'snapshot lô chỉ xóa một lần');

// Quét lại kho: snapshot lô dựng lại đủ database và đường dẫn.
$dest = ['id' => 'd1', 'name' => 'S3', 'type' => 's3', 'remote' => 'wnb_t', 'subpath' => 'x'];
$snaps = [
    ['id' => $sid('a'), 'time' => '2026-10-01T02:00:00+07:00', 'hostname' => 'h', 'tags' => ['wnb', 'u:web1', 'b:bkp_web1_1', 't:backup', 'part:home', 'hp:/home/web1', 'mp:/backup/.staging/rmeta/web1']],
    ['id' => $sid('b'), 'time' => '2026-10-01T01:00:00+07:00', 'tags' => ['wnb', 'u:web1', 'b:bkp_web1_1', 't:backup', 'part:db', 'dp:/backup/.staging/rdb/web1', 'db:web1_a', 'db:web1_b']],
    ['id' => $sid('c'), 'time' => '2026-10-01T01:30:00+07:00', 'tags' => ['wnb', 'u:web1', 'b:bkp_web1_1', 't:backup', 'db:web1_big']],
];
$recs = WNBRestic::recordsFromSnapshots($snaps, $dest, []);
$rr = $recs[0]['restic'] ?? [];
ok(count($recs) === 1 && $rr['dbs'] === ['web1_a' => $sid('b'), 'web1_b' => $sid('b'), 'web1_big' => $sid('c')]
    && WNBRestic::dbFile($rr, 'web1_b') === '/backup/.staging/rdb/web1/web1_b.sql' && WNBRestic::dbFile($rr, 'web1_big') === '/web1_big.sql'
    && $recs[0]['databases'] === ['web1_a', 'web1_b', 'web1_big'], 'quét kho dựng lại database theo lô: ' . json_encode($rr));
