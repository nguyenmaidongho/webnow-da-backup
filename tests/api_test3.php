<?php
// Test bổ sung: chế độ Nén cho kho cloud (chỉ mục tar, duyệt, khôi phục) và giám sát nguy cơ mất dữ liệu.
// Chạy SAU api_test2.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');
$priv = function (string $name) {
    $m = new ReflectionMethod(WNBEngine::class, $name);
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    return $m;
};

// Dòng chỉ mục của tar -cvv --quoting-style=literal
$p = fn($l) => WNBRclone::parseIndexLine($l);
ok($p('-rw-r--r-- 1000/1000      1234 2024-05-01 12:00 ./domains/a.vn/public_html/index.php') === ['path' => 'domains/a.vn/public_html/index.php', 'type' => 'file', 'size' => 1234, 'mtime' => '2024-05-01 12:00:00'], 'index: file');
ok(($p('drwxr-x--- 1000/1000         0 2024-05-01 12:00 ./domains/')['path'] ?? '') === 'domains' && $p('drwxr-x--- 1000/1000 0 2024-05-01 12:00 ./domains/')['type'] === 'dir', 'index: thư mục');
$l = $p('lrwxrwxrwx 1000/1000         0 2024-05-01 12:00 ./public_html -> ./domains/a.vn/public_html');
ok($l && $l['path'] === 'public_html' && $l['type'] === 'link', 'index: symlink');
ok(($p('hrw-r--r-- 1000/1000 0 2024-05-01 12:00 ./b.txt link to ./a.txt')['path'] ?? '') === 'b.txt', 'index: hardlink');
ok(($p('-rw-r--r-- 0/0 5 2024-05-01 12:00:07 ./Tài liệu/hợp đồng 1.pdf')['path'] ?? '') === 'Tài liệu/hợp đồng 1.pdf', 'index: tên tiếng Việt có dấu cách');
ok(($p('crw-r--r-- 0/0 1,3 2024-05-01 12:00 ./dev/null')['size'] ?? -1) === 0, 'index: thiết bị');
ok($p('drwx------ 0/0 0 2024-05-01 12:00 ./') === null && $p('tar: ./x: file changed as we read it') === null, 'index: bỏ dòng gốc và dòng lạ');

// Kiểu lưu của kho
ok(WNBRclone::destFormat(['type' => 'local']) === 'folder', 'kho local = thư mục');
ok(WNBRclone::destFormat(['type' => 's3']) === 'archive', 'kho S3 mặc định = nén');
ok(WNBRclone::destFormat(['type' => 's3', 'format' => 'folder']) === 'folder', 'kho S3 chọn thư mục');
ok(WNBRclone::destFormat(['type' => 'webdav', 'format' => 'archive']) === 'folder', 'WebDAV không hỗ trợ nén');

$r = call('admin', 'admin', ['action' => 'dest_save', 'name' => 'R2 test', 'type' => 'r2', 'subpath' => 'bucket/srv1', 'format' => 'archive', 'p_access_key_id' => 'k', 'p_secret_access_key' => 's', 'p_endpoint' => 'https://x.r2.cloudflarestorage.com', 'csrf' => $ac]);
ok($r['ok'], 'lưu kho R2: ' . ($r['error'] ?? ''));
$r2 = null;
foreach (WNBStorage::read('destinations') as $d) {
    if ($d['name'] === 'R2 test') {
        $r2 = $d;
    }
}
ok($r2 && $r2['format'] === 'archive', 'kho R2 lưu kiểu nén');
$r = call('admin', 'admin', ['action' => 'dest_save', 'name' => 'Dav test', 'type' => 'webdav', 'subpath' => 'backup', 'format' => 'archive', 'p_url' => 'https://dav.example.com', 'csrf' => $ac]);
$dav = null;
foreach (WNBStorage::read('destinations') as $d) {
    if ($d['name'] === 'Dav test') {
        $dav = $d;
    }
}
ok($dav && $dav['format'] === 'folder', 'kho WebDAV bị ép kiểu thư mục');
$st = call('admin', 'admin', ['action' => 'state'])['data'];
$fmt = [];
foreach ($st['destinations'] as $d) {
    $fmt[$d['name']] = $d['format'] ?? null;
}
ok(($fmt['R2 test'] ?? '') === 'archive' && ($fmt['Dav test'] ?? '') === 'folder', 'state trả kiểu lưu của kho');

// Sao lưu lên kho nén (preview) rồi duyệt bằng chỉ mục
$r = call('admin', 'admin', ['action' => 'backup_now', 'username' => 'shopgiay', 'destination_id' => $r2['id'], 'csrf' => $ac]);
ok($r['ok'], 'backup lên R2: ' . ($r['error'] ?? ''));
$ab = null;
foreach (WNBStorage::read('backups') as $x) {
    if ($x['destination_id'] === $r2['id']) {
        $ab = $x;
        break;
    }
}
ok($ab && $ab['format'] === 'archive', 'bản sao lưu ghi kiểu nén');
$c = tok('shopgiay', 'user');
$r = call('shopgiay', 'user', ['action' => 'browse', 'backup_id' => $ab['id'], 'path' => '']);
$names = array_column($r['data']['items'] ?? [], 'name');
ok($r['ok'] && in_array('domains', $names, true) && in_array('.bashrc', $names, true), 'duyệt bản nén: ' . json_encode($names));
$r = call('shopgiay', 'user', ['action' => 'browse', 'backup_id' => $ab['id'], 'path' => 'domains/shopgiaydep.vn/public_html']);
$items = [];
foreach ($r['data']['items'] ?? [] as $it) {
    $items[$it['name']] = $it;
}
ok(isset($items['index.php']) && $items['index.php']['type'] === 'file' && $items['index.php']['size'] > 0 && isset($items['wp-content']) && $items['wp-content']['type'] === 'dir', 'duyệt thư mục con của bản nén');
ok(!call('shopgiay', 'user', ['action' => 'browse', 'backup_id' => $ab['id'], 'path' => '../etc'])['ok'], 'bản nén: chặn ../');
ok(!call('shopgiay', 'user', ['action' => 'browse', 'backup_id' => $ab['id'], 'path' => 'khong-co'])['ok'], 'bản nén: thư mục không tồn tại');
ok(WNBEngine::archiveHas($ab, 'domains/shopgiaydep.vn') && WNBEngine::archiveHas($ab, '.bashrc') && !WNBEngine::archiveHas($ab, 'domains/shopgiay'), 'archiveHas khớp đúng đường dẫn');
$r = call('shopgiay', 'user', ['action' => 'restore', 'backup_id' => $ab['id'], 'scope' => 'files', 'items' => json_encode(['domains/shopgiaydep.vn/public_html/index.php']), 'csrf' => $c]);
ok($r['ok'], 'khôi phục file từ bản nén: ' . ($r['error'] ?? ''));

// Lệnh tar
WNBStorage::update('settings', fn($s) => array_merge($s, ['excludes' => "backups/*
tmp/*"]));
$ex = $priv('tarExcludeArgs')->invoke(null);
ok($ex === ' --anchored --exclude=' . escapeshellarg('./backups/*') . ' --exclude=' . escapeshellarg('./tmp/*'), 'mẫu loại trừ dạng tar: ' . $ex);
$out = ['tar: ./a: file changed as we read it', 'WNB_PIPE 1 0 0'];
$args = [&$out, 3];
ok($priv('pipeStatus')->invokeArgs(null, $args) === [1, 0, 0] && $out === ['tar: ./a: file changed as we read it'], 'đọc mã thoát pipeline');
$out = [];
$args = [&$out, 3];
ok($priv('pipeStatus')->invokeArgs(null, $args) === [1, 1, 1], 'không có mã thoát = coi như lỗi');

// Giám sát
$jobsBak = WNBStorage::read('jobs');
$backupsBak = WNBStorage::read('backups');
$settingsBak = WNBStorage::read('settings');
$codes = fn() => array_column(WNBEngine::healthIssues(), 'code');

WNBStorage::write('jobs', []);
ok($codes() === ['no_job'], 'không có lịch bật = cảnh báo');
$ago = fn(int $h) => date('Y-m-d H:i:s', time() - $h * 3600);
WNBStorage::write('jobs', [['id' => 'job_h', 'name' => 'Hằng ngày', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => date('H:i', time() - 3600), 'schedule_weekday' => 0, 'interval_hours' => 6, 'retention' => 3, 'exclude_users' => [], 'skip_suspended' => false, 'enabled' => true, 'last_run' => $ago(1), 'created_at' => $ago(100)]]);
WNBStorage::write('backups', [
    ['id' => 'b1', 'username' => 'shopgiay', 'type' => 'backup', 'created_at' => $ago(2), 'size_mb' => 1],
    ['id' => 'b2', 'username' => 'nhakhoa', 'type' => 'backup', 'created_at' => $ago(72), 'size_mb' => 1],
    ['id' => 'b3', 'username' => 'admin', 'type' => 'snapshot', 'created_at' => $ago(1), 'size_mb' => 1],
]);
ok($codes() === ['stale:admin,nhakhoa,reseller_vn'], 'tài khoản quá hạn (snapshot không tính): ' . json_encode($codes()));
$run72 = $ago(72); // tính một lần: gọi hai lần có thể lệch một giây
WNBStorage::update('jobs', function ($l) use ($ago, $run72) {
    $l[0]['last_run'] = $run72;
    $l[0]['last_result'] = ['total' => 4, 'done' => 3, 'failed' => 1, 'warnings' => 0, 'skipped' => 0, 'finished_at' => $ago(71)];
    return $l;
});
$cs = $codes();
ok(in_array('overdue:job_h', $cs, true) && in_array('failed:job_h:' . $run72, $cs, true), 'lịch quá giờ chưa chạy + lượt lỗi: ' . json_encode($cs));
WNBStorage::update('settings', fn($s) => array_merge($s, ['min_free_gb' => 10000]));
ok((bool)preg_grep('/^disk:/', $codes()), 'ổ đĩa thiếu chỗ');
$st = call('admin', 'admin', ['action' => 'state'])['data'];
ok(count($st['health']) === count($codes()) && is_string($st['health'][0]), 'state trả danh sách nguy cơ cho admin');
ok(!isset(call('shopgiay', 'user', ['action' => 'state'])['data']['health']), 'user không thấy cảnh báo hệ thống');

// Chống gửi trùng: cùng vấn đề chỉ nhắc lại sau 24 giờ; hết vấn đề thì báo đã ổn.
WNBStorage::write('health', []);
WNBEngine::healthCheck(true);
$h1 = WNBStorage::read('health');
ok($h1['signature'] !== '' && !empty($h1['sent_at']), 'gửi cảnh báo lần đầu');
WNBStorage::write('health', array_merge($h1, ['sent_at' => time() - 3600]));
WNBEngine::healthCheck(true);
ok(WNBStorage::read('health')['sent_at'] === time() - 3600 || WNBStorage::read('health')['sent_at'] <= time() - 3599, 'không gửi lại trong 24 giờ');
WNBStorage::write('health', array_merge(WNBStorage::read('health'), ['sent_at' => time() - 90000]));
WNBEngine::healthCheck(true);
ok(WNBStorage::read('health')['sent_at'] >= time() - 5, 'nhắc lại sau 24 giờ');
WNBStorage::write('health', array_merge(WNBStorage::read('health'), ['checked_at' => time()]));
WNBEngine::healthCheck();
ok(WNBStorage::read('health')['checked_at'] >= time() - 5, 'cron chỉ kiểm tra 30 phút/lần');
WNBStorage::write('settings', $settingsBak);
WNBStorage::write('jobs', [['id' => 'job_ok', 'name' => 'Ổn', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => date('H:i', time() - 3600), 'schedule_weekday' => 0, 'interval_hours' => 6, 'retention' => 3, 'exclude_users' => [], 'skip_suspended' => false, 'enabled' => true, 'last_run' => $ago(1), 'created_at' => $ago(100)]]);
WNBStorage::write('backups', array_map(fn($u) => ['id' => 'ok_' . $u, 'username' => $u, 'type' => 'backup', 'created_at' => $ago(1), 'size_mb' => 1], WNBDirectAdmin::usernames()));
ok($codes() === [], 'mọi thứ ổn = không có cảnh báo: ' . json_encode($codes()));
WNBStorage::write('health', array_merge(WNBStorage::read('health'), ['sent_at' => 0]));
WNBEngine::healthCheck(true);
$h3 = WNBStorage::read('health');
ok($h3['signature'] === '' && $h3['sent_at'] >= time() - 5, 'báo đã ổn trở lại');

WNBStorage::write('jobs', $jobsBak);
WNBStorage::write('backups', $backupsBak);
WNBStorage::write('settings', $settingsBak);
