<?php
// Test: bản sao lưu của tài khoản đã bị xóa khỏi DirectAdmin (chỉ admin thấy), tạo lại tài khoản rồi khôi phục.
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');
// Tài khoản demo đổi giữa chừng: xóa cache thông tin tài khoản như một tiến trình mới.
$resetDa = function () {
    $p = new ReflectionProperty('WNBDirectAdmin', 'cache');
    if (PHP_VERSION_ID < 80100) {
        $p->setAccessible(true);
    }
    $p->setValue(null, []);
};
$cafe = ['username' => 'quancafe', 'usertype' => 'user', 'creator' => 'reseller_vn', 'domain' => 'quancafe.vn', 'domains' => ['quancafe.vn', 'cafe-sach.vn'], 'package' => '', 'suspended' => false, 'quota_mb' => 0, 'usage_mb' => 80.0,
    'databases' => ['quancafe_wp'], 'emails' => ['info@quancafe.vn', 'ban@cafe-sach.vn'], 'crons' => []];
WNBDirectAdmin::$demoExtra = ['quancafe' => $cafe];
$r = call('admin', 'admin', ['action' => 'backup_now', 'username' => 'quancafe', 'destination_id' => 'dest_local', 'csrf' => $ac]);
ok($r['ok'], 'sao lưu tài khoản sắp xóa: ' . ($r['error'] ?? ''));
WNBEngine::worker();
$old = null;
foreach (WNBStorage::read('backups') as $b) {
    if ($b['username'] === 'quancafe') {
        $old = $b;
    }
}
ok($old !== null, 'có bản sao lưu của quancafe');

// Xóa tài khoản khỏi DirectAdmin
WNBDirectAdmin::$demoExtra = [];
$resetDa();
$st = call('admin', 'admin', ['action' => 'state'])['data'];
$gone = array_values(array_filter($st['backups'], fn($b) => $b['username'] === 'quancafe'));
ok(count($gone) === 1 && !empty($gone[0]['account_deleted']), 'admin vẫn thấy bản của tài khoản đã xóa, có cờ account_deleted');
ok(!isset($gone[0]['storage_path']) && !isset($gone[0]['owner_key']), 'không lộ đường dẫn/owner_key');
$da = $st['deleted_accounts'] ?? [];
ok(count($da) === 1 && $da[0]['username'] === 'quancafe' && $da[0]['backups_count'] === 1 && $da[0]['last_backup_id'] === $old['id']
    && $da[0]['domains'] === ['quancafe.vn', 'cafe-sach.vn'] && $da[0]['databases'] === ['quancafe_wp'] && $da[0]['reseller'] === 'reseller_vn', 'state.deleted_accounts đúng: ' . json_encode($da));
ok(!in_array('quancafe', array_column($st['accounts'], 'username'), true), 'tài khoản đã xóa không nằm trong danh sách tài khoản');
foreach ([['reseller_vn', 'reseller'], ['nhakhoa', 'user']] as [$u, $role]) {
    $s2 = call($u, $role, ['action' => 'state'])['data'];
    ok(!isset($s2['deleted_accounts']) && !in_array('quancafe', array_column($s2['backups'], 'username'), true), $role . ' không thấy bản của tài khoản đã xóa');
}
$r = call('reseller_vn', 'reseller', ['action' => 'download_create', 'backup_id' => $old['id'], 'csrf' => tok('reseller_vn', 'reseller')]);
ok(!$r['ok'], 'reseller không tải được bản của tài khoản đã xóa');

// Admin: khôi phục bị chặn với hướng dẫn, tải về vẫn được
$r = call('admin', 'admin', ['action' => 'restore', 'backup_id' => $old['id'], 'scope' => 'full', 'confirm' => 'quancafe', 'csrf' => $ac]);
ok(!$r['ok'] && strpos($r['error'], 'không còn tồn tại') !== false, 'khôi phục tài khoản đã xóa bị chặn: ' . ($r['error'] ?? ''));
$r = call('admin', 'admin', ['action' => 'download_create', 'backup_id' => $old['id'], 'csrf' => $ac]);
ok($r['ok'], 'admin tải về được bản của tài khoản đã xóa: ' . ($r['error'] ?? ''));
WNBEngine::worker();
$st = call('admin', 'admin', ['action' => 'state'])['data'];
ok(in_array('quancafe', array_column($st['downloads'], 'username'), true), 'file tải về của tài khoản đã xóa hiện cho admin');
$tasks = array_values(array_filter($st['queue'], fn($t) => $t['username'] === 'quancafe'));
ok(count($tasks) >= 2, 'tác vụ của tài khoản đã xóa vẫn hiện cho admin');

// Tạo lại cùng tên (chủ mới khác owner_key), chỉ có tên miền chính
WNBStorage::update('backups', function ($l) {
    foreach ($l as &$b) {
        if ($b['username'] === 'quancafe') {
            $b['owner_key'] = 'demo:quancafe-cu';
        }
    }
    return $l;
});
WNBDirectAdmin::$demoExtra = ['quancafe' => array_merge($cafe, ['domains' => ['quancafe.vn'], 'emails' => ['info@quancafe.vn']])];
$resetDa();
$st = call('admin', 'admin', ['action' => 'state'])['data'];
$b0 = array_values(array_filter($st['backups'], fn($b) => $b['id'] === $old['id']))[0] ?? [];
ok(empty($b0['account_deleted']) && !empty($b0['previous_owner']) && ($st['deleted_accounts'] ?? null) === [], 'tạo lại: bản cũ gắn previous_owner, hết khỏi danh sách đã xóa');
ok(!in_array($old['id'], array_column(call('reseller_vn', 'reseller', ['action' => 'state'])['data']['backups'], 'id'), true), 'reseller vẫn không thấy bản của tài khoản cũ');

// Lịch sao lưu tài khoản mới không làm retention dọn bản của tài khoản cũ
$jr = call('admin', 'admin', ['action' => 'job_save', 'name' => 'Giữ 1', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'filter_mode' => 'include', 'include_users' => json_encode(['quancafe']),
    'keep_last' => '1', 'keep_daily' => '0', 'keep_weekly' => '0', 'keep_monthly' => '0', 'keep_hours' => '0', 'csrf' => $ac]);
ok($jr['ok'], 'lưu lịch giữ 1 bản: ' . ($jr['error'] ?? ''));
$jq = null;
foreach ($jr['data']['state']['jobs'] ?? [] as $j) {
    if ($j['name'] === 'Giữ 1') {
        $jq = $j;
    }
}
WNBStorage::update('backups', function ($l) use ($jq) {
    foreach ($l as &$b) {
        if ($b['username'] === 'quancafe') {
            $b['job_id'] = $jq['id'];
        }
    }
    return $l;
});
for ($i = 0; $i < 2; $i++) {
    WNBEngine::runJob($jq, 'test');
    WNBEngine::worker();
}
$mine = array_values(array_filter(WNBStorage::read('backups'), fn($b) => $b['username'] === 'quancafe'));
$ids = array_column($mine, 'id');
ok(in_array($old['id'], $ids, true) && count($mine) === 2, 'retention giữ bản của tài khoản cũ, chỉ dọn bản của tài khoản mới: ' . json_encode($ids));

// Khôi phục toàn bộ sau khi tạo lại: chạy được, cảnh báo tên miền còn thiếu
$r = call('admin', 'admin', ['action' => 'restore', 'backup_id' => $old['id'], 'scope' => 'full', 'confirm' => 'quancafe', 'csrf' => $ac]);
ok($r['ok'], 'khôi phục toàn bộ sau khi tạo lại: ' . ($r['error'] ?? ''));
WNBEngine::worker();
$rt = null;
foreach (WNBStorage::read('queue') as $t) {
    if ($t['type'] === 'restore' && $t['username'] === 'quancafe') {
        $rt = $t;
        break;
    }
}
ok($rt && $rt['status'] === 'completed' && $rt['warnings'] === 1, 'khôi phục hoàn tất với 1 cảnh báo tên miền thiếu: ' . json_encode($rt));
$log = WNBStorage::readLog((string)($rt["id"] ?? ""));
ok(strpos($log, 'cafe-sach.vn') !== false && strpos($log, 'emails = info@quancafe.vn') !== false, 'log ghi tên miền thiếu, chỉ khôi phục email của tên miền có: ' . $log);

// Dọn
call('admin', 'admin', ['action' => 'job_delete', 'id' => $jq['id'], 'csrf' => $ac]);
foreach ($ids as $id) {
    call('admin', 'admin', ['action' => 'backup_delete', 'backup_id' => $id, 'csrf' => $ac]);
}
WNBEngine::worker();
WNBDirectAdmin::$demoExtra = [];
$resetDa();
ok(!in_array('quancafe', array_column(WNBStorage::read('backups'), 'username'), true), 'admin xóa được bản của tài khoản');
