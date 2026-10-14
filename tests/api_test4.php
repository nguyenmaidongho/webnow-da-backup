<?php
// Test bổ sung: giữ bản theo ngày/tuần/tháng và kho incremental restic.
// Chạy SAU api_test3.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');

// ---------- Chọn bản giữ lại (giống restic forget --keep-*) ----------
$mk = function (int $days, int $hours = 0) {
    $out = [];
    $base = strtotime(date('Y-m-d 12:00:00'));
    for ($i = 0; $i < $days; $i++) {
        for ($h = 0; $h <= $hours; $h++) {
            $t = $base - $i * 86400 - $h * 3600;
            $out[] = ['id' => 'b' . $i . '_' . $h, 'created_at' => date('Y-m-d H:i:s', $t)];
        }
    }
    return $out;
};
$list = $mk(120);
$keep = WNBEngine::retentionKeep($list, ['last' => 0, 'daily' => 7, 'weekly' => 4, 'monthly' => 3]);
$ids = array_keys($keep);
ok(array_slice(array_column($list, 'id'), 0, 7) === array_values(array_filter($ids, fn($id) => in_array('daily', $keep[$id], true))), '7 bản ngày = 7 ngày gần nhất');
$weekly = array_filter($ids, fn($id) => in_array('weekly', $keep[$id], true));
$monthly = array_filter($ids, fn($id) => in_array('monthly', $keep[$id], true));
ok(count($weekly) === 4 && count($monthly) === 3, 'đủ 4 bản tuần, 3 bản tháng');
$weeks = array_map(fn($id) => date('o-W', strtotime($list[array_search($id, array_column($list, 'id'))]['created_at'])), $weekly);
$months = array_map(fn($id) => date('Y-m', strtotime($list[array_search($id, array_column($list, 'id'))]['created_at'])), $monthly);
ok(count(array_unique($weeks)) === 4 && count(array_unique($months)) === 3, 'mỗi tuần/tháng một bản');
ok(count($keep) <= 14 && count($keep) >= 9, 'tổng số bản giữ hợp lý: ' . count($keep));
$oldest = min(array_map(fn($id) => $list[array_search($id, array_column($list, 'id'))]['created_at'], $ids));
ok($oldest >= date('Y-m-01', strtotime('first day of -2 month')), 'không giữ bản cũ hơn 3 tháng: ' . $oldest);
// Nhiều bản mỗi ngày (lịch theo giờ): bản ngày = bản mới nhất của từng ngày
$hourly = $mk(5, 3);
$k2 = WNBEngine::retentionKeep($hourly, ['daily' => 3]);
ok(array_keys($k2) === ['b0_0', 'b1_0', 'b2_0'], 'lịch theo giờ: giữ bản cuối của mỗi ngày');
$k3 = WNBEngine::retentionKeep($hourly, ['last' => 5, 'daily' => 2]);
ok(count($k3) === 5 && in_array('last', $k3['b1_0'], true) && in_array('daily', $k3['b1_0'], true), 'kết hợp mới nhất + ngày');
ok(array_keys(WNBEngine::retentionKeep($hourly, [])) === ['b0_0'], 'không cấu hình gì vẫn giữ bản mới nhất');
ok(WNBEngine::jobPolicy(['retention' => 5]) === ['last' => 5, 'daily' => 0, 'weekly' => 0, 'monthly' => 0, 'hours' => 0], 'lịch cũ: retention = N bản mới nhất');
ok(WNBEngine::jobPolicy(['keep_last' => 0, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0])['last'] === 1, 'chính sách toàn 0 vẫn giữ 1 bản');

// ---------- Lưu lịch với chính sách mới ----------
$findJob = function ($r, $name) {
    foreach ($r['data']['state']['jobs'] ?? [] as $j) {
        if ($j['name'] === $name) {
            return $j;
        }
    }
    return null;
};
$r = call('admin', 'admin', ['action' => 'job_save', 'name' => 'GFS', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'csrf' => $ac]);
$j = $findJob($r, 'GFS');
ok($j && $j['policy'] === ['last' => 0, 'daily' => 7, 'weekly' => 4, 'monthly' => 3, 'hours' => 24] && !isset($j['retention']), 'lịch mới mặc định 7 ngày/4 tuần/3 tháng: ' . json_encode($j['policy'] ?? null));
$r = call('admin', 'admin', ['action' => 'job_save', 'id' => $j['id'], 'name' => 'GFS', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'keep_daily' => '14', 'keep_weekly' => '8', 'keep_monthly' => '12', 'keep_last' => '0', 'keep_hours' => '0', 'csrf' => $ac]);
$j = $findJob($r, 'GFS');
ok($j['policy'] === ['last' => 0, 'daily' => 14, 'weekly' => 8, 'monthly' => 12, 'hours' => 0], 'lưu chính sách tùy chỉnh');
$r = call('admin', 'admin', ['action' => 'job_save', 'id' => $j['id'], 'name' => 'GFS 2', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'csrf' => $ac]);
ok(($findJob($r, 'GFS 2')['policy']['daily'] ?? 0) === 14, 'sửa lịch không gửi chính sách thì giữ nguyên');
$r = call('admin', 'admin', ['action' => 'job_save', 'id' => $j['id'], 'name' => 'GFS', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'keep_daily' => '999', 'keep_weekly' => '-3', 'csrf' => $ac]);
$p = $findJob($r, 'GFS')['policy'];
ok($p['daily'] === 365 && $p['weekly'] === 0, 'giới hạn 0..365');
// Chạy 3 lần trong cùng ngày với chính sách chỉ "3 ngày": chỉ còn bản mới nhất của hôm nay
call('admin', 'admin', ['action' => 'job_save', 'id' => $j['id'], 'name' => 'GFS', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'keep_daily' => '3', 'keep_weekly' => '0', 'keep_monthly' => '0', 'keep_last' => '0', 'csrf' => $ac]);
$jj = null;
foreach (WNBStorage::read('jobs') as $x) {
    if ($x['id'] === $j['id']) {
        $jj = $x;
    }
}
$jj['exclude_users'] = ['admin', 'reseller_vn', 'nhakhoa'];
for ($i = 0; $i < 3; $i++) {
    WNBEngine::runJob($jj, 'test');
    WNBEngine::worker();
}
$mine = array_values(array_filter(WNBStorage::read('backups'), fn($b) => $b['job_id'] === $j['id']));
ok(count($mine) === 1, 'keep_hours = 0: cùng ngày chỉ giữ 1 bản "ngày": ' . count($mine));
$st = call('admin', 'admin', ['action' => 'state'])['data'];
$tagged = null;
foreach ($st['backups'] as $b) {
    if ($b['id'] === $mine[0]['id']) {
        $tagged = $b;
    }
}
ok($tagged && $tagged['keep'] === ['daily'] && !isset($tagged['restic']), 'state gắn nhãn giữ bản: ' . json_encode($tagged['keep'] ?? null));
call('admin', 'admin', ['action' => 'job_delete', 'id' => $j['id'], 'csrf' => $ac]);

// ---------- restic: hàm thuần ----------
ok(WNBRclone::destFormat(['type' => 'webdav', 'format' => 'restic']) === 'restic' && WNBRclone::destFormat(['type' => 'local', 'format' => 'restic']) === 'folder', 'restic dùng được với mọi kho cloud, không với local');
$pj = WNBRestic::parseJson([
    '{"message_type":"status","percent_done":0.5}',
    '{"message_type":"error","error":{"message":"open /home/u/x: permission denied"},"during":"archival","item":"/home/u/x"}',
    'Fatal: something',
    '{"message_type":"summary","files_new":3,"data_added":2048,"data_added_packed":1024,"total_bytes_processed":10485760,"snapshot_id":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"}',
]);
ok(($pj['summary']['snapshot_id'] ?? '') !== '' && $pj['summary']['data_added_packed'] === 1024 && count($pj['errors']) === 2 && strpos($pj['errors'][0], 'permission denied (/home/u/x)') !== false, 'đọc JSON restic backup');
ok(WNBRestic::time('2024-05-01T12:00:07.123456789+00:00') === date('Y-m-d H:i:s', strtotime('2024-05-01T12:00:07+00:00')), 'đọc thời gian nano giây');
ok(WNBRestic::literalPattern('/home/u/a*b?[1].txt') === '/home/u/a\\*b\\?\\[1\\].txt', 'thoát ký tự đại diện cho --include');
$items = WNBRestic::nodesToItems([
    '{"time":"2024-05-01T02:00:00Z","paths":["/home/u"],"struct_type":"snapshot"}',
    '{"name":"public_html","type":"dir","path":"/home/u/domains/a.vn/public_html","mtime":"2024-05-01T01:00:00.5Z","struct_type":"node"}',
    '{"name":"index.php","type":"file","path":"/home/u/domains/a.vn/public_html/index.php","size":321,"mtime":"2024-05-01T01:00:00Z","struct_type":"node"}',
    '{"name":"link","type":"symlink","path":"/home/u/domains/a.vn/public_html/link","struct_type":"node"}',
    '{"name":"deep.txt","type":"file","path":"/home/u/domains/a.vn/public_html/wp/deep.txt","size":5,"struct_type":"node"}',
], '/home/u/domains/a.vn/public_html', 'domains/a.vn/public_html');
ok(array_column($items, 'name') === ['index.php', 'link'] && $items[0]['size'] === 321 && $items[0]['path'] === 'domains/a.vn/public_html/index.php' && $items[1]['type'] === 'link', 'restic ls: chỉ lấy con trực tiếp: ' . json_encode($items));
$threw = false;
try {
    WNBRestic::nodesToItems(['{"time":"x","struct_type":"snapshot"}'], '/home/u/nope', 'nope');
} catch (WNBError $e) {
    $threw = true;
}
ok($threw, 'restic ls: thư mục không có -> lỗi');
$sid = fn($c) => str_repeat($c, 64);
ok(WNBRestic::snapshotIds(['restic' => ['home' => $sid('a'), 'dbs' => ['x_wp' => $sid('b'), 'bad' => '../x']]]) === [$sid('a'), $sid('b')], 'lấy id snapshot hợp lệ');
$dest = ['id' => 'dest_t', 'name' => 'R', 'type' => 's3', 'remote' => 'wnb_t', 'subpath' => 'bk/srv'];
$snaps = [
    ['id' => $sid('a'), 'time' => '2024-05-01T02:00:00+07:00', 'hostname' => 'old', 'paths' => ['/home/shopgiay', '/backup/.staging/rmeta/shopgiay'], 'tags' => ['wnb', 'u:shopgiay', 'b:bkp_shopgiay_20240501_020000_ab12', 't:backup', 'j:job_x', 'part:home', 'hp:/home/shopgiay', 'mp:/backup/.staging/rmeta/shopgiay']],
    ['id' => $sid('b'), 'time' => '2024-05-01T01:59:00+07:00', 'tags' => ['wnb', 'u:shopgiay', 'b:bkp_shopgiay_20240501_020000_ab12', 't:backup', 'db:shopgiay_wp']],
    ['id' => $sid('c'), 'time' => '2024-05-02T02:00:00+07:00', 'tags' => ['wnb', 'u:shopgiay', 'b:bkp_known', 'part:home']],
    ['id' => $sid('d'), 'time' => '2024-05-02T02:00:00+07:00', 'tags' => ['u:x', 'b:bkp_other_1', 'part:home']],
    ['id' => $sid('e'), 'time' => '2024-05-02T02:00:00+07:00', 'tags' => ['wnb', 'u:../evil', 'b:bkp_evil_1', 'part:home']],
    ['id' => $sid('f'), 'time' => '2024-05-02T02:00:00+07:00', 'tags' => ['wnb', 'u:nhakhoa', 'b:bkp_nhakhoa_1', 'db:nhakhoa_wp']],
];
$recs = WNBRestic::recordsFromSnapshots($snaps, $dest, ['bkp_known']);
ok(count($recs) === 1 && $recs[0]['id'] === 'bkp_shopgiay_20240501_020000_ab12' && $recs[0]['restic']['dbs'] === ['shopgiay_wp' => $sid('b')] && $recs[0]['restic']['home_path'] === '/home/shopgiay' && $recs[0]['job_id'] === 'job_x', 'quét kho dựng lại bản sao lưu: ' . json_encode(array_column($recs, 'id')));
ok($recs[0]['owner_key'] === 'imported' && $recs[0]['databases'] === ['shopgiay_wp'] && $recs[0]['format'] === 'restic', 'bản nạp lại chỉ admin thấy');
WNBStorage::update('settings', fn($s) => array_merge($s, ['bwlimit' => '2M']));
$cmd = WNBRestic::cmd($dest, 'snapshots', true);
ok(strpos($cmd, 'RESTIC_REPOSITORY=' . escapeshellarg('rclone:wnb_t:bk/srv/restic')) !== false && strpos($cmd, 'RESTIC_PASSWORD_FILE=') !== false && strpos($cmd, 'RCLONE_CONFIG=') !== false && strpos($cmd, '--limit-upload 2048') !== false && substr($cmd, -10) === ' snapshots', 'lệnh restic kèm kho, mật khẩu qua file, giới hạn băng thông');
ok(strpos(WNBRestic::cmd($dest, 'snapshots'), '--limit-upload') === false, 'không giới hạn băng thông khi không cần');
WNBStorage::update('settings', fn($s) => array_merge($s, ['bwlimit' => '']));

// ---------- restic: kho, mật khẩu, sao lưu, duyệt, quét ----------
$r = call('admin', 'admin', ['action' => 'dest_save', 'name' => 'Restic R2', 'type' => 'r2', 'subpath' => 'bucket/srv2', 'format' => 'restic', 'p_access_key_id' => 'k', 'p_secret_access_key' => 's', 'p_endpoint' => 'https://x.r2.cloudflarestorage.com', 'csrf' => $ac]);
ok($r['ok'] && $r['data']['restic_new_key'] === true, 'tạo kho restic, tự sinh mật khẩu: ' . ($r['error'] ?? ''));
$rd = null;
foreach ($r['data']['state']['destinations'] as $d) {
    if ($d['name'] === 'Restic R2') {
        $rd = $d;
    }
}
ok($rd && $rd['format'] === 'restic' && $rd['restic_key'] === true && !isset($rd['password']), 'state: kho restic có mật khẩu, không lộ mật khẩu');
$raw = json_encode($r);
$pw1 = WNBRestic::password($rd['id']);
ok(strlen($pw1) >= 30 && strpos($raw, $pw1) === false, 'mật khẩu mạnh, không có trong state');
$k = call('admin', 'admin', ['action' => 'dest_restic_key', 'id' => $rd['id'], 'csrf' => $ac]);
ok($k['ok'] && $k['data']['password'] === $pw1 && strpos($k['data']['repository'], 'rclone:') === 0, 'admin xem được mật khẩu kho');
ok(!call('admin', 'admin', ['action' => 'dest_restic_key', 'id' => $rd['id']])['ok'], 'xem mật khẩu cần CSRF');
ok(!call('reseller_vn', 'reseller', ['action' => 'dest_restic_key', 'id' => $rd['id'], 'csrf' => tok('reseller_vn', 'reseller')])['ok'], 'reseller không xem được mật khẩu kho');
$r = call('admin', 'admin', ['action' => 'dest_save', 'id' => $rd['id'], 'name' => 'Restic R2', 'type' => 'r2', 'subpath' => 'bucket/srv2', 'format' => 'restic', 'csrf' => $ac]);
ok($r['ok'] && $r['data']['restic_new_key'] === false && WNBRestic::password($rd['id']) === $pw1, 'lưu lại không đổi mật khẩu');
$r = call('admin', 'admin', ['action' => 'dest_save', 'id' => $rd['id'], 'name' => 'Restic R2', 'type' => 'r2', 'subpath' => 'bucket/srv2', 'format' => 'restic', 'restic_password' => 'short', 'csrf' => $ac]);
ok(!$r['ok'] && WNBRestic::password($rd['id']) === $pw1, 'mật khẩu quá ngắn bị từ chối');
$r = call('admin', 'admin', ['action' => 'dest_save', 'id' => $rd['id'], 'name' => 'Restic R2', 'type' => 'r2', 'subpath' => 'bucket/srv2', 'format' => 'restic', 'restic_password' => 'mat-khau-cu-cua-kho', 'csrf' => $ac]);
ok($r['ok'] && WNBRestic::password($rd['id']) === 'mat-khau-cu-cua-kho', 'nhập mật khẩu để kết nối lại kho cũ');

$r = call('admin', 'admin', ['action' => 'backup_now', 'username' => 'shopgiay', 'destination_id' => $rd['id'], 'csrf' => $ac]);
ok($r['ok'], 'sao lưu lên kho restic: ' . ($r['error'] ?? ''));
$rb = null;
foreach (WNBStorage::read('backups') as $x) {
    if ($x['destination_id'] === $rd['id']) {
        $rb = $x;
        break;
    }
}
ok($rb && $rb['format'] === 'restic', 'bản sao lưu kiểu restic');
$r = call('shopgiay', 'user', ['action' => 'browse', 'backup_id' => $rb['id'], 'path' => 'domains/shopgiaydep.vn/public_html']);
ok($r['ok'] && in_array('index.php', array_column($r['data']['items'], 'name'), true), 'duyệt bản restic');
$r = call('admin', 'admin', ['action' => 'download_create', 'backup_id' => $rb['id'], 'csrf' => $ac]);
$r = call('admin', 'admin', ['action' => 'backup_delete', 'backup_id' => $rb['id'], 'csrf' => $ac]);
ok($r['ok'] && !WNBEngine::findBackup($rb['id']), 'xóa bản restic: ' . ($r['error'] ?? ''));

$r = call('admin', 'admin', ['action' => 'dest_rescan', 'id' => $rd['id'], 'csrf' => $ac]);
ok($r['ok'], 'quét lại kho: ' . ($r['error'] ?? ''));
$q = array_values(array_filter($r['data']['state']['queue'], fn($t) => $t['type'] === 'rescan'));
ok($q && $q[0]['username'] === '' && $q[0]['status'] === 'completed', 'admin thấy tác vụ quét kho');
$rq = call('reseller_vn', 'reseller', ['action' => 'state'])['data']['queue'];
ok(!array_filter($rq, fn($t) => $t['type'] === 'rescan'), 'reseller không thấy tác vụ hệ thống');
ok(!call('admin', 'admin', ['action' => 'dest_rescan', 'id' => 'dest_local', 'csrf' => $ac])['ok'], 'không quét kho không phải restic');

// Cảnh báo khi kiểm tra kho lỗi
$jobsBak = WNBStorage::read('jobs');
WNBStorage::write('jobs', [['id' => 'job_r', 'name' => 'R', 'destination_id' => $rd['id'], 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'schedule_weekday' => 0, 'interval_hours' => 6, 'keep_daily' => 7, 'exclude_users' => [], 'skip_suspended' => false, 'enabled' => true, 'last_run' => WNB::now(), 'created_at' => WNB::now()]]);
WNBStorage::update('destinations', function ($l) use ($rd) {
    foreach ($l as &$d) {
        if ($d['id'] === $rd['id']) {
            $d['restic_maint'] = ['prune_ts' => time(), 'prune_ok' => true, 'check_ts' => time(), 'check_ok' => false, 'check_msg' => 'pack abc is damaged'];
        }
    }
    return $l;
});
ok(in_array('restic_check:' . $rd['id'], array_column(WNBEngine::healthIssues(), 'code'), true), 'cảnh báo khi kho restic hỏng');
WNBStorage::write('jobs', $jobsBak);
