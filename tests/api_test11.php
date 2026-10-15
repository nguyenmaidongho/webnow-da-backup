<?php
// Test 2.5.0: bản thiếu phần, mẫu loại trừ **/, tự xóa bản mồ côi, quét lại kho Nén/Thư mục/local.
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');
$day = 86400;
$at = fn($ago) => date('Y-m-d H:i:s', time() - $ago);
$e = 'escapeshellarg';

// ---------- Bản thiếu phần ----------
ok(WNBEngine::missingParts(['missing' => []]) === [], 'bản mới đầy đủ');
ok(WNBEngine::missingParts(['missing' => ['databases' => ['u_a']]]) === ['databases' => ['u_a']], 'đọc trường missing');
$oldWarn = ['warnings' => [
    WNBLang::msg('Dump database {db} thất bại: {detail}', ['db' => 'u_a', 'detail' => 'x']),
    WNBLang::msg('Không thấy DNS zone của {domain}.', ['domain' => 'a.vn']),
    WNBLang::msg('Không tìm thấy thư mục home.'),
]];
ok(WNBEngine::missingParts($oldWarn) === ['databases' => ['u_a'], 'home' => true], 'bản cũ: suy ra phần thiếu từ cảnh báo đã lưu');
ok(WNBEngine::missingParts(['warnings' => [WNBLang::msg('Bỏ qua database: {detail}', ['detail' => 'x'])]]) === ['databases' => ['*']], 'bỏ qua cả phần database');
ok(!WNBEngine::isPartial(['warnings' => ['chuỗi cũ']]) && !WNBEngine::isPartial([]), 'cảnh báo dạng chuỗi / không cảnh báo: coi là đủ');

// Ngày có bản cuối thiếu database: giữ thêm bản đầy đủ của ngày đó.
$base = strtotime(date('Y-m-d 20:00:00'));
$L = [];
for ($i = 0; $i < 10; $i++) {
    $L[] = ['id' => "p{$i}_pm", 'created_at' => date('Y-m-d H:i:s', $base - $i * $day), 'missing' => ['databases' => ['u_a']]];
    $L[] = ['id' => "p{$i}_am", 'created_at' => date('Y-m-d H:i:s', $base - $i * $day - 12 * 3600)];
}
$k = WNBEngine::retentionKeep($L, ['daily' => 3]);
ok(array_keys($k) === ['p0_pm', 'p0_am', 'p1_pm', 'p1_am', 'p2_pm', 'p2_am'], 'bản thiếu phần không đẩy mất bản đầy đủ cùng ngày: ' . json_encode(array_keys($k)));

// Các bản gần đây đều thiếu phần: bản đầy đủ mới nhất vẫn được giữ.
$L2 = [];
for ($i = 0; $i < 10; $i++) {
    $L2[] = ['id' => "q$i", 'created_at' => date('Y-m-d H:i:s', $base - $i * $day), 'missing' => $i < 8 ? ['home' => true] : []];
}
$k2 = WNBEngine::retentionKeep($L2, ['daily' => 3]);
ok(array_keys($k2) === ['q0', 'q1', 'q2', 'q8'] && $k2['q8'] === ['complete'], 'luôn giữ bản đầy đủ mới nhất: ' . json_encode($k2));
$k3 = WNBEngine::retentionKeep(array_map(fn($b) => ['id' => $b['id'], 'created_at' => $b['created_at']], $L2), ['daily' => 3]);
ok(array_keys($k3) === ['q0', 'q1', 'q2'], 'không có bản thiếu phần: giữ như trước');

// ---------- Mẫu loại trừ ----------
$raw = "backups/*\n**/node_modules\n/.cache/*\n**/\n../etc\n**/wp-content/cache/*";
ok(WNBEngine::excludePatterns($raw) === [['backups/*', false], ['node_modules', true], ['.cache/*', false], ['wp-content/cache/*', true]], 'đọc mẫu loại trừ');
ok(WNBEngine::excludeArgs($raw) === ' --exclude=' . $e('/backups/*') . ' --exclude=' . $e('node_modules') . ' --exclude=' . $e('/.cache/*') . ' --exclude=' . $e('wp-content/cache/*'), 'rsync: **/ thành mẫu không neo');
ok(WNBEngine::tarExcludeArgs($raw) === ' --anchored --exclude=' . $e('./backups/*') . ' --exclude=' . $e('./.cache/*') . ' --no-anchored --exclude=' . $e('node_modules') . ' --exclude=' . $e('wp-content/cache/*'), 'tar: mẫu **/ đặt sau --no-anchored');
ok(WNBEngine::tarExcludeArgs('backups/*') === ' --anchored --exclude=' . $e('./backups/*'), 'tar: không có mẫu **/ thì như cũ');
ok(WNBEngine::resticExcludeArgs('/home/u/', "backups/*\n**/node_modules") === ' --exclude=' . $e('/home/u/backups/*') . ' --exclude=' . $e('/home/u/**/node_modules'), 'restic: **/ ở mọi cấp trong home');

// ---------- Bản mồ côi ----------
$saved = ['settings' => WNBStorage::read('settings'), 'jobs' => WNBStorage::read('jobs'), 'backups' => WNBStorage::read('backups')];
$setOrphan = function (int $days, int $since = 0) {
    WNBStorage::update('settings', fn($s) => array_merge($s, ['orphan_days' => $days, 'orphan_since' => $since, 'exclude_accounts' => [], 'exclude_resellers' => []]));
};
WNBStorage::write('jobs', [
    ['id' => 'job_on', 'name' => 'On', 'enabled' => true, 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'last_run' => null, 'filter_mode' => 'include', 'include_users' => ['shopgiay', 'nhakhoa'], 'include_resellers' => []],
    ['id' => 'job_off', 'name' => 'Off', 'enabled' => false, 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'last_run' => null, 'filter_mode' => 'exclude', 'exclude_users' => [], 'exclude_resellers' => []],
    ['id' => 'job_other', 'name' => 'Other', 'enabled' => true, 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'last_run' => null, 'filter_mode' => 'include', 'include_users' => ['shopgiay'], 'include_resellers' => []],
]);
$bk = fn($id, $u, $job, $ago, $extra = []) => array_merge(['id' => $id, 'username' => $u, 'owner_key' => 'demo:' . $u, 'type' => 'backup', 'destination_id' => 'dest_local',
    'destination_name' => 'Local', 'destination_type' => 'local', 'format' => 'folder', 'job_id' => $job, 'created_at' => $at($ago), 'warnings' => [], 'domains' => [], 'databases' => [],
    'emails' => [], 'crons' => [], 'size_mb' => 1, 'storage_path' => WNBStorage::path('preview_store/' . $u . '/' . $id)], $extra);
$B = [
    $bk('bkp_ghostacc_1', 'ghostacc', 'job_on', 40 * $day),
    $bk('bkp_ghostacc_2', 'ghostacc', '', 35 * $day),
    $bk('bkp_ghosttwo_1', 'ghosttwo', '', 5 * $day),
    $bk('bkp_shopgiay_on', 'shopgiay', 'job_on', 1 * $day),
    $bk('bkp_shopgiay_gone1', 'shopgiay', 'job_deleted', 50 * $day),
    $bk('bkp_shopgiay_gone2', 'shopgiay', 'job_deleted', 45 * $day, ['missing' => ['home' => true]]),
    $bk('bkp_shopgiay_off', 'shopgiay', 'job_off', 90 * $day),
    $bk('bkp_shopgiay_out', 'shopgiay', 'job_other', 1 * $day),
    $bk('bkp_nhakhoa_gone1', 'nhakhoa', 'job_other', 60 * $day),
    $bk('bkp_nhakhoa_gone2', 'nhakhoa', 'job_other', 50 * $day, ['missing' => ['databases' => ['x']]]),
    $bk('bkp_nhakhoa_man', 'nhakhoa', '', 70 * $day),
    $bk('bkp_shopgiay_prev', 'shopgiay', '', 40 * $day, ['owner_key' => 'old:9']),
    $bk('bkp_ghostthree_imp', 'ghostthree', 'job_x', 100 * $day, ['owner_key' => 'imported', 'imported_at' => $at(2 * $day)]),
];
$setOrphan(0);
ok(WNBEngine::orphanPlan($B) === [], 'mặc định không tự xóa bản mồ côi');
$setOrphan(30);
$plan = WNBEngine::orphanPlan($B);
$due = array_keys(array_filter($plan, fn($o) => $o['at'] <= time()));
sort($due);
ok($due === ['bkp_ghostacc_1', 'bkp_ghostacc_2', 'bkp_nhakhoa_gone2', 'bkp_shopgiay_gone1', 'bkp_shopgiay_gone2', 'bkp_shopgiay_prev'], 'bản mồ côi đến hạn: ' . json_encode($due));
ok(($plan['bkp_ghostacc_1']['why'] ?? '') === 'deleted' && ($plan['bkp_shopgiay_prev']['why'] ?? '') === 'previous' && ($plan['bkp_shopgiay_gone1']['why'] ?? '') === 'job', 'lý do tự xóa');
ok(isset($plan['bkp_ghosttwo_1']) && abs($plan['bkp_ghosttwo_1']['at'] - (time() + 25 * $day)) < 5, 'tài khoản mới xóa: hết hạn sau 30 ngày kể từ bản cuối');
ok(isset($plan['bkp_ghostthree_imp']) && $plan['bkp_ghostthree_imp']['at'] > time() + 27 * $day, 'bản nạp lại tính từ lúc nạp, không từ ngày sao lưu');
ok(!isset($plan['bkp_shopgiay_on']) && !isset($plan['bkp_shopgiay_out']) && !isset($plan['bkp_shopgiay_off']) && !isset($plan['bkp_nhakhoa_man']),
    'bản của lịch còn sao lưu tài khoản, lịch đang tắt, bản thủ công: không tự xóa');
ok(!isset($plan['bkp_nhakhoa_gone1']), 'tài khoản không còn bản đầy đủ nào mới hơn: giữ bản đầy đủ mới nhất của lịch cũ');
$setOrphan(30, time());
ok(!array_filter(WNBEngine::orphanPlan($B), fn($o) => $o['at'] <= time() + 3600), 'vừa bật: đợi 1 ngày rồi mới xóa');
$p2 = WNBEngine::orphanPlan(array_map(fn($b) => array_merge($b, ['username' => $b['username'] === 'ghostacc' ? 'ghostacc' : $b['username']]), $B));
ok(count($p2) === count(WNBEngine::orphanPlan($B)), 'kết quả ổn định');

// Dọn thật (preview): tác vụ hệ thống xóa đúng các bản đến hạn và thư mục của chúng.
$setOrphan(30);
foreach ($B as $b) {
    @mkdir($b['storage_path'] . '/homedir', 0700, true);
}
WNBStorage::write('backups', $B);
WNBStorage::write('orphans', []);
WNBEngine::scheduleOrphanCleanup();
$cq = array_values(array_filter(WNBStorage::read('queue'), fn($t) => $t['type'] === 'cleanup'));
ok(count($cq) === 1 && $cq[0]['username'] === '' && $cq[0]['status'] === 'pending', 'cron xếp một tác vụ dọn bản mồ côi');
WNBEngine::scheduleOrphanCleanup();
ok(count(array_filter(WNBStorage::read('queue'), fn($t) => $t['type'] === 'cleanup')) === 1, 'không xếp trùng trong vòng 1 giờ');
WNBEngine::worker();
$left = array_column(WNBStorage::read('backups'), 'id');
sort($left);
ok($left === ['bkp_ghostthree_imp', 'bkp_ghosttwo_1', 'bkp_nhakhoa_gone1', 'bkp_nhakhoa_man', 'bkp_shopgiay_off', 'bkp_shopgiay_on', 'bkp_shopgiay_out'], 'còn lại đúng các bản chưa đến hạn: ' . json_encode($left));
ok(!is_dir(WNBStorage::path('preview_store/ghostacc/bkp_ghostacc_1')) && is_dir(WNBStorage::path('preview_store/nhakhoa/bkp_nhakhoa_gone1')), 'xóa cả dữ liệu trên kho');
$ct = WNBEngine::findTask($cq[0]['id']);
ok(($ct['status'] ?? '') === 'completed' && WNBLang::render($ct['message']) === 'Đã xóa 6 bản sao lưu mồ côi.', 'tác vụ dọn hoàn tất: ' . WNBLang::render($ct['message'] ?? ''));

// Giao diện: nhãn "Tự xóa", tài khoản đã xóa, phần thiếu.
WNBStorage::update('backups', fn($l) => array_merge($l, [$bk('bkp_shopgiay_part', 'shopgiay', '', 3 * $day, ['warnings' => [WNBLang::msg('Không tìm thấy thư mục home.')]])]));
$st = call('admin', 'admin', ['action' => 'state'])['data'];
$by = array_column($st['backups'], null, 'id');
ok(($by['bkp_ghosttwo_1']['expires_why'] ?? '') === 'deleted' && !empty($by['bkp_ghosttwo_1']['expires_at']) && !isset($by['bkp_shopgiay_on']['expires_at']), 'state: bản sắp tự xóa có mốc và lý do');
ok(json_encode($by['bkp_shopgiay_part']['missing']) === '{"home":true}' && json_encode($by['bkp_shopgiay_on']['missing']) === '{}', 'state: phần thiếu (cả bản cũ chỉ có cảnh báo)');
$gone = array_column($st['deleted_accounts'], null, 'username');
ok(!empty($gone['ghosttwo']['expires_at']) && !empty($gone['ghostthree']['expires_at']), 'tài khoản đã xóa có ngày tự xóa');
$us = call('shopgiay', 'user', ['action' => 'state'])['data'];
ok(!array_filter($us['backups'], fn($b) => isset($b['expires_at'])), 'khách không thấy lịch tự xóa');

// Cài đặt: bật hoặc rút ngắn thì đợi thêm 1 ngày; giữ nguyên/kéo dài thì không.
$setOrphan(0);
$save = function (int $days) use ($ac) {
    $s = call('admin', 'admin', ['action' => 'state'])['data']['settings'];
    return call('admin', 'admin', array_merge($s, ['action' => 'settings_save', 'orphan_days' => $days, 'csrf' => $ac]));
};
$r = $save(30);
$s1 = WNBStorage::settings();
ok($r['ok'] && $s1['orphan_days'] === 30 && abs($s1['orphan_since'] - time()) < 5, 'bật tự xóa: ghi mốc bắt đầu: ' . ($r['error'] ?? ''));
WNBStorage::update('settings', fn($s) => array_merge($s, ['orphan_since' => 123]));
$save(60);
ok(WNBStorage::settings()['orphan_since'] === 123, 'kéo dài thời hạn: không đổi mốc');
$save(10);
ok(abs(WNBStorage::settings()['orphan_since'] - time()) < 5, 'rút ngắn thời hạn: đợi thêm 1 ngày');
$save(-5);
ok(WNBStorage::settings()['orphan_days'] === 0, 'số ngày âm thành 0');

WNBStorage::write('settings', $saved['settings']);
WNBStorage::write('jobs', $saved['jobs']);
WNBStorage::write('backups', $saved['backups']);

// ---------- Quét lại kho Nén / Thư mục / local ----------
$dest = ['id' => 'd_s3', 'name' => 'S3', 'type' => 's3', 'remote' => 'wnb_x', 'subpath' => 'bk/WebNowBackup/h', 'format' => 'archive'];
$oldMan = ['id' => 'bkp_shopgiay_20240101_020000_ab12', 'username' => 'shopgiay', 'created_at' => '2024-01-01 02:00:00', 'domain' => 'shopgiay.vn', 'domains' => ['shopgiay.vn'],
    'databases' => ['shopgiay_shop'], 'storage_path' => '/backup/webnowbackup/.staging/shopgiay/bkp_shopgiay_20240101_020000_ab12', 'size_mb' => 12.5, 'incremental' => false,
    'warnings' => [WNBLang::msg('Dump database {db} thất bại: {detail}', ['db' => 'shopgiay_wp', 'detail' => 'x'])], 'job_id' => 'job_daily', 'type' => 'backup'];
$newMan = ['id' => 'bkp_shopgiay_20261001_020000_cd34', 'username' => 'shopgiay', 'created_at' => '2026-10-01 02:00:00', 'format' => 'archive', 'home_archive' => true,
    'missing' => ['databases' => ['shopgiay_wp']], 'job_id' => 'job_x', 'type' => 'backup', 'emails' => ['a@shopgiay.vn'], 'pointers' => [['name' => 'p.vn', 'target' => 'shopgiay.vn', 'alias' => true]]];
$mf = [
    'shopgiay/bkp_shopgiay_20240101_020000_ab12/manifest.json' => $oldMan,
    'shopgiay/bkp_shopgiay_20261001_020000_cd34/manifest.json' => $newMan,
    'shopgiay/snap_shopgiay_x/manifest.json' => ['id' => 'snap_other', 'username' => 'shopgiay', 'created_at' => '2026-10-01 02:00:00'],
    'Bad.User/bkp_x/manifest.json' => ['id' => 'bkp_x', 'username' => 'Bad.User', 'created_at' => '2026-10-01 02:00:00'],
    'nhakhoa/bkp_nhakhoa_1/manifest.json' => null,
    'nhakhoa/bkp_known/manifest.json' => ['id' => 'bkp_known', 'username' => 'nhakhoa', 'created_at' => '2026-10-01 02:00:00'],
    'nhakhoa/bkp_nhakhoa_2/manifest.json' => ['id' => 'bkp_nhakhoa_2', 'username' => 'nhakhoa', 'created_at' => 'hôm qua'],
    'nhakhoa/bkp_nhakhoa_3/x/manifest.json' => ['id' => 'bkp_nhakhoa_3', 'username' => 'nhakhoa', 'created_at' => '2026-10-01 02:00:00'],
];
$recs = array_column(WNBEngine::recordsFromManifests($mf, $dest, 'wnb_x:bk/WebNowBackup/h', ['bkp_known']), null, 'id');
ok(array_keys($recs) === ['bkp_shopgiay_20240101_020000_ab12', 'bkp_shopgiay_20261001_020000_cd34'], 'chỉ nạp manifest hợp lệ, chưa có trong danh sách: ' . json_encode(array_keys($recs)));
$r0 = $recs['bkp_shopgiay_20240101_020000_ab12'];
$r1 = $recs['bkp_shopgiay_20261001_020000_cd34'];
ok($r0['format'] === 'folder' && $r0['storage_path'] === 'wnb_x:bk/WebNowBackup/h/shopgiay/bkp_shopgiay_20240101_020000_ab12' && $r0['destination_id'] === 'd_s3'
    && $r0['owner_key'] === 'imported' && $r0['imported'] === true && $r0['size_mb'] === 12.5 && $r0['databases'] === ['shopgiay_shop'] && $r0['job_id'] === 'job_daily',
    'manifest cũ (không có format) = Thư mục, đường dẫn theo kho hiện tại');
ok(WNBEngine::missingParts($r0) === ['databases' => ['shopgiay_wp']], 'manifest cũ: phần thiếu suy ra từ cảnh báo');
ok($r1['format'] === 'archive' && $r1['home_archive'] === true && $r1['missing'] === ['databases' => ['shopgiay_wp']] && $r1['emails'] === ['a@shopgiay.vn'] && $r1['pointers'][0]['name'] === 'p.vn',
    'manifest kiểu Nén');
$lrec = WNBEngine::recordsFromManifests(['shopgiay/bkp_shopgiay_20261001_020000_cd34/manifest.json' => $newMan], ['id' => 'dest_local', 'name' => 'Local', 'type' => 'local', 'path' => '/backup/webnowbackup'], '/backup/webnowbackup', []);
ok($lrec[0]['format'] === 'folder' && $lrec[0]['storage_path'] === '/backup/webnowbackup/shopgiay/bkp_shopgiay_20261001_020000_cd34' && $lrec[0]['destination_type'] === 'local', 'kho ổ cứng máy chủ');

// Gỡ bản không còn trong kho: chỉ bản Nén/Thư mục của đúng kho, tạo trước lúc đọc.
WNBStorage::write('backups', [
    $bk('bkp_a', 'shopgiay', '', $day, ['destination_id' => 'd_s3', 'format' => 'archive']),
    $bk('bkp_b', 'shopgiay', '', $day, ['destination_id' => 'd_s3', 'format' => 'folder']),
    $bk('bkp_c', 'shopgiay', '', $day, ['destination_id' => 'd_s3', 'format' => 'restic']),
    $bk('bkp_d', 'shopgiay', '', $day, ['destination_id' => 'd_other', 'format' => 'folder']),
    $bk('bkp_e', 'shopgiay', '', -3600, ['destination_id' => 'd_s3', 'format' => 'folder']),
]);
ok(WNBEngine::dropMissingFiles('d_s3', ['bkp_a'], WNB::now()) === 1 && array_column(WNBStorage::read('backups'), 'id') === ['bkp_a', 'bkp_c', 'bkp_d', 'bkp_e'], 'gỡ bản không còn trong kho');
WNBStorage::write('backups', $saved['backups']);

// Quét lại kho local qua API (preview) và kho đang bận.
$r = call('admin', 'admin', ['action' => 'dest_rescan', 'id' => 'dest_local', 'csrf' => $ac]);
ok($r['ok'], 'quét lại kho ổ cứng máy chủ');
WNBStorage::update('queue', function ($q) { array_unshift($q, ['id' => 'task_busy_dest', 'type' => 'backup', 'username' => 'shopgiay', 'destination_id' => 'dest_local', 'status' => 'pending', 'created_at' => WNB::now()]); return $q; });
ok(!call('admin', 'admin', ['action' => 'dest_rescan', 'id' => 'dest_local', 'csrf' => $ac])['ok'], 'kho đang có tác vụ: chưa quét');
WNBStorage::update('queue', fn($q) => array_values(array_filter($q, fn($t) => $t['id'] !== 'task_busy_dest')));
ok(!call('reseller_vn', 'reseller', ['action' => 'dest_rescan', 'id' => 'dest_local', 'csrf' => tok('reseller_vn', 'reseller')])['ok'], 'reseller không quét kho được');
