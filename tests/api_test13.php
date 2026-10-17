<?php
// Test 2.6.1: dung lượng thật của kho (đo bằng du / rclone size), không cộng size_mb từng bản.
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');
$saved = ['backups' => WNBStorage::read('backups'), 'destinations' => WNBStorage::read('destinations'), 'queue' => WNBStorage::read('queue')];

$D = [
    ['id' => 'dest_u1', 'name' => 'Đo 1', 'type' => 's3', 'remote' => 'wnb_u1', 'subpath' => 'u1', 'format' => 'restic'],
    ['id' => 'dest_u2', 'name' => 'Đo 2', 'type' => 's3', 'remote' => 'wnb_u2', 'subpath' => 'u2', 'format' => 'restic'],
];
$bk = fn($id, $u, $dest, $at, $size, $new, $inc = true) => ['id' => $id, 'username' => $u, 'owner_key' => 'demo:' . $u, 'type' => 'backup', 'destination_id' => $dest,
    'destination_name' => $dest, 'destination_type' => 's3', 'format' => 'restic', 'job_id' => '', 'created_at' => $at, 'warnings' => [], 'domains' => [], 'databases' => [],
    'emails' => [], 'crons' => [], 'size_mb' => $size, 'new_mb' => $new, 'incremental' => $inc, 'restic' => ['home' => 'x', 'dbs' => []]];
// 3 bản Incremental của một tài khoản 1000 MB: cộng size_mb ra 3000 MB, dữ liệu thật ~1000 + 5 + 5.
$B = [
    $bk('bkp_u_3', 'shopgiay', 'dest_u1', '2026-10-03 02:00:00', 1000, 5),
    $bk('bkp_u_2', 'shopgiay', 'dest_u1', '2026-10-02 02:00:00', 1000, 5),
    $bk('bkp_u_1', 'shopgiay', 'dest_u1', '2026-10-01 02:00:00', 1000, 1000, false),
];
WNBStorage::write('destinations', $D);
WNBStorage::write('backups', $B);
WNBStorage::write('queue', []);

// ---------- Kho nào cần đo ----------
ok(array_column(WNBEngine::usageDue(), 'id') === ['dest_u1', 'dest_u2'], 'kho chưa đo thì cần đo');
WNBStorage::write('queue', [['id' => 't_busy', 'type' => 'backup', 'username' => 'shopgiay', 'status' => 'running', 'destination_id' => 'dest_u1', 'created_at' => WNB::now()]]);
ok(array_column(WNBEngine::usageDue(), 'id') === ['dest_u2'], 'kho đang có tác vụ thì chưa đo');
WNBStorage::write('queue', []);

$st = call('admin', 'admin', ['action' => 'state']);
ok($st['data']['stats']['used_bytes'] === null && $st['data']['stats']['used_unmeasured'] === 1, 'chưa đo: không có số, 1 kho có bản chưa đo');

// ---------- Worker đo khi rảnh ----------
ok(WNBEngine::measureNextUsage() && WNBEngine::measureNextUsage() && !WNBEngine::measureNextUsage(), 'mỗi lần đo một kho, hết thì dừng');
$u = WNBEngine::findDestination('dest_u1')['usage'];
ok($u['bytes'] === (int)round(1010 * 1048576) && $u['error'] === '' && $u['stale'] === false && $u['ts'] > 0, 'dung lượng thật = bản đầy đủ + phần mới: ' . json_encode($u));
ok(WNBEngine::findDestination('dest_u2')['usage']['bytes'] === 0, 'kho trống = 0');
ok(WNBEngine::usageDue() === [], 'vừa đo xong thì không đo lại');

$st = call('admin', 'admin', ['action' => 'state'])['data'];
ok($st['stats']['used_bytes'] === (int)round(1010 * 1048576) && $st['stats']['used_unmeasured'] === 0, 'Tổng quan dùng dung lượng thật, không cộng 3000 MB: ' . $st['stats']['used_bytes']);
ok($st['stats']['size_mb'] == 3000, 'size_mb (tổng từng bản) vẫn giữ cho reseller/user');
ok(isset($st['destinations'][0]['usage']['bytes']), 'state trả usage của kho cho admin');
$rs = call('admin', 'reseller', ['action' => 'state'])['data'];
ok($rs['stats']['used_bytes'] === null && !isset($rs['destinations'][0]['usage']), 'reseller không thấy dung lượng kho');

// ---------- Tác vụ làm đổi dữ liệu thì đo lại ----------
WNBEngine::markUsageStale(['dest_u1']);
ok(array_column(WNBEngine::usageDue(), 'id') === ['dest_u1'], 'đánh dấu cũ thì cần đo lại');
WNBEngine::measureNextUsage();
WNBStorage::update('destinations', function ($l) {
    $l[0]['usage']['ts'] = time() - 86400;
    return $l;
});
ok(array_column(WNBEngine::usageDue(), 'id') === ['dest_u1'], 'đo đã quá 1 ngày thì đo lại');
WNBEngine::measureNextUsage();

// Xóa một bản: kho của bản (lấy trước khi bản ghi mất) được đo lại.
ok(call('admin', 'admin', ['action' => 'backup_delete', 'backup_id' => 'bkp_u_3', 'csrf' => $ac])['ok'], 'xóa bản');
$u = WNBEngine::findDestination('dest_u1')['usage'];
ok(!WNBEngine::findBackup('bkp_u_3') && $u['stale'] === false && $u['bytes'] === (int)round(1005 * 1048576), 'sau khi xóa, worker đo lại kho: ' . json_encode($u));

// Có tác vụ đổi dữ liệu trong lúc đang đo: giữ cờ để đo lại.
WNBStorage::update('destinations', function ($l) {
    $l[0]['usage']['stale_ts'] = time() + 5;
    $l[0]['usage']['stale'] = true;
    return $l;
});
WNBEngine::measureNextUsage();
ok(WNBEngine::findDestination('dest_u1')['usage']['stale'] === true, 'đổi dữ liệu trong lúc đo thì vẫn cần đo lại');

// Kiểm tra kho thành công: đo lại ngay (preview: worker chạy luôn).
WNBStorage::update('destinations', function ($l) {
    $l[1]['usage'] = ['bytes' => 123, 'ts' => time(), 'at' => WNB::now(), 'error' => '', 'stale' => false];
    return $l;
});
ok(call('admin', 'admin', ['action' => 'dest_test', 'id' => 'dest_u2', 'csrf' => $ac])['ok'] && WNBEngine::findDestination('dest_u2')['usage']['bytes'] === 0, 'Kiểm tra kho đo lại dung lượng');

foreach ($saved as $k => $v) {
    WNBStorage::write($k, $v);
}
