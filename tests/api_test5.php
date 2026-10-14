<?php
// Test: lịch chế độ "chỉ tài khoản được chọn" (filter_mode = include).
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).
// Tài khoản demo: admin, reseller_vn (reseller), shopgiay (khách của reseller_vn), nhakhoa (khách của admin).

$ac = tok('admin', 'admin');
$pv = fn(array $p) => call('admin', 'admin', array_merge(['action' => 'job_preview', 'filter_mode' => 'include', 'csrf' => $ac], $p))['data']['users'] ?? null;
ok($pv(['include_users' => json_encode(['nhakhoa'])]) === ['nhakhoa'], 'include: chỉ tài khoản được chọn');
ok($pv(['include_resellers' => json_encode(['reseller_vn'])]) === ['reseller_vn', 'shopgiay'], 'include reseller → reseller + khách');
ok($pv(['include_resellers' => json_encode(['reseller_vn']), 'include_users' => json_encode(['nhakhoa', 'ghost'])]) === ['reseller_vn', 'shopgiay', 'nhakhoa'], 'include reseller + tài khoản, bỏ tài khoản không tồn tại');
ok($pv(['include_resellers' => json_encode(['shopgiay'])]) === [], 'user thường không được coi là reseller khi include');
ok($pv(['include_users' => json_encode(['nhakhoa']), 'exclude_users' => json_encode(['nhakhoa'])]) === ['nhakhoa'], 'include bỏ qua danh sách loại trừ');
ok(count($pv(['filter_mode' => 'bogus', 'include_users' => json_encode(['nhakhoa'])])) === 4, 'filter_mode lạ = exclude (tất cả)');

// Lưu lịch include rỗng bị từ chối
$r = call('admin', 'admin', ['action' => 'job_save', 'name' => 'Rỗng', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'filter_mode' => 'include', 'csrf' => $ac]);
ok(!$r['ok'] && strpos($r['error'], 'ít nhất') !== false, 'include rỗng bị từ chối: ' . ($r['error'] ?? ''));

// Lưu lịch include: chỉ giữ danh sách của chế độ đang dùng
$r = call('admin', 'admin', ['action' => 'job_save', 'name' => 'Chỉ chọn', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'filter_mode' => 'include', 'include_resellers' => json_encode(['reseller_vn']), 'include_users' => json_encode(['nhakhoa', 'nhakhoa']), 'exclude_users' => json_encode(['admin']), 'enabled' => '1', 'csrf' => $ac]);
ok($r['ok'], 'lưu lịch include: ' . ($r['error'] ?? ''));
$ij = null;
foreach ($r['data']['state']['jobs'] ?? [] as $j) {
    if ($j['name'] === 'Chỉ chọn') {
        $ij = $j;
    }
}
ok($ij && $ij['filter_mode'] === 'include' && $ij['include_resellers'] === ['reseller_vn'] && $ij['include_users'] === ['nhakhoa'] && $ij['exclude_users'] === [] && $ij['target_count'] === 3, 'lịch include lưu đúng: ' . json_encode($ij));

// Khách tạo sau của reseller được chọn cũng được sao lưu; tài khoản mới khác thì không
WNBDirectAdmin::$demoExtra = [
    'khachmoi' => ['username' => 'khachmoi', 'usertype' => 'user', 'creator' => 'reseller_vn', 'domain' => 'khachmoi.vn', 'domains' => ['khachmoi.vn'], 'package' => '', 'suspended' => false, 'quota_mb' => 0, 'usage_mb' => 1.0],
    'adminmoi' => ['username' => 'adminmoi', 'usertype' => 'user', 'creator' => 'admin', 'domain' => 'adminmoi.vn', 'domains' => ['adminmoi.vn'], 'package' => '', 'suspended' => false, 'quota_mb' => 0, 'usage_mb' => 1.0],
];
$tg = WNBEngine::jobTargets($ij);
sort($tg);
ok($tg === ['khachmoi', 'nhakhoa', 'reseller_vn', 'shopgiay'], 'include: khách mới của reseller được chọn có, tài khoản mới khác không: ' . json_encode($tg));
WNBDirectAdmin::$demoExtra = [];

// "Không bao giờ tự sao lưu" vẫn thắng chế độ include
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'exclude_accounts' => json_encode(['shopgiay']), 'default_retention' => '2', 'max_snapshots_per_user' => '3', 'csrf' => $ac]);
ok(WNBEngine::jobTargets($ij) === ['reseller_vn', 'nhakhoa'], 'include vẫn bỏ qua danh sách không bao giờ sao lưu');
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'default_retention' => '2', 'max_snapshots_per_user' => '3', 'csrf' => $ac]);

// State: scheduled = tài khoản thuộc ít nhất một lịch đang bật (tắt mọi lịch khác để kiểm tra)
$jobsBak = WNBStorage::read('jobs');
WNBStorage::update('jobs', function ($l) use ($ij) {
    foreach ($l as &$j) {
        $j['enabled'] = $j['id'] === $ij['id'];
    }
    return $l;
});
$sc = call('admin', 'admin', ['action' => 'state'])['data']['scheduled'];
sort($sc);
ok($sc === ['nhakhoa', 'reseller_vn', 'shopgiay'], 'state.scheduled theo lịch đang bật: ' . json_encode($sc));
ok(!isset(call('shopgiay', 'user', ['action' => 'state'])['data']['scheduled']), 'user không thấy scheduled');
WNBStorage::write('jobs', $jobsBak);

// Sửa lịch sang exclude: danh sách include bị xóa
$r = call('admin', 'admin', ['action' => 'job_save', 'id' => $ij['id'], 'name' => 'Chỉ chọn', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'filter_mode' => 'exclude', 'exclude_users' => json_encode(['admin']), 'include_users' => json_encode(['nhakhoa']), 'csrf' => $ac]);
$ej = null;
foreach ($r['data']['state']['jobs'] ?? [] as $j) {
    if ($j['id'] === $ij['id']) {
        $ej = $j;
    }
}
ok($ej && $ej['filter_mode'] === 'exclude' && $ej['include_users'] === [] && $ej['include_resellers'] === [] && $ej['exclude_users'] === ['admin'] && $ej['target_count'] === 3, 'đổi sang exclude: ' . json_encode($ej));
call('admin', 'admin', ['action' => 'job_delete', 'id' => $ij['id'], 'csrf' => $ac]);

// Giữ mọi bản trong N giờ gần nhất: chạy lịch 2 lần trong ngày không thay bản trước
$now = strtotime('2026-10-14 12:00:00');
$day = [
    ['id' => 'b3', 'created_at' => '2026-10-14 11:30:00'],
    ['id' => 'b2', 'created_at' => '2026-10-14 02:30:00'],
    ['id' => 'b1', 'created_at' => '2026-10-14 02:00:00'],
    ['id' => 'b0', 'created_at' => '2026-10-13 02:00:00'],
    ['id' => 'a9', 'created_at' => '2026-10-12 23:00:00'],
    ['id' => 'a8', 'created_at' => '2026-10-12 02:00:00'],
];
$k = WNBEngine::retentionKeep($day, ['daily' => 7, 'hours' => 24], $now);
ok(isset($k['b3'], $k['b2'], $k['b1'], $k['b0'], $k['a9']) && !isset($k['a8']), 'giữ mọi bản 24 giờ + bản cuối mỗi ngày: ' . json_encode(array_keys($k)));
ok($k['b1'] === ['recent'] && in_array('daily', $k['b3'], true), 'bản chỉ giữ vì còn mới có nhãn recent');
$k = WNBEngine::retentionKeep($day, ['daily' => 7, 'hours' => 0], $now);
ok(!isset($k['b2']) && !isset($k['b1']) && isset($k['b3']), 'hours = 0: như trước, chỉ bản cuối mỗi ngày');
ok(WNBEngine::jobPolicy(['keep_daily' => 7])['hours'] === 24, 'lịch cũ không có keep_hours: mặc định 24 giờ');
ok(WNBEngine::jobPolicy(['keep_daily' => 7, 'keep_hours' => 999])['hours'] === 168, 'keep_hours tối đa 168');
$r = call('admin', 'admin', ['action' => 'job_save', 'name' => 'Giờ', 'destination_id' => 'dest_local', 'schedule_type' => 'hourly', 'interval_hours' => '6', 'schedule_time' => '00:00', 'keep_hours' => '48', 'keep_daily' => '7', 'keep_weekly' => '0', 'keep_monthly' => '0', 'keep_last' => '0', 'csrf' => $ac]);
$hj = null;
foreach ($r['data']['state']['jobs'] ?? [] as $j) {
    if ($j['name'] === 'Giờ') {
        $hj = $j;
    }
}
ok($hj && $hj['keep_hours'] === 48 && $hj['policy']['hours'] === 48, 'lưu keep_hours: ' . json_encode($hj['policy'] ?? null));
$r = call('admin', 'admin', ['action' => 'job_save', 'id' => $hj['id'], 'name' => 'Giờ', 'destination_id' => 'dest_local', 'schedule_type' => 'hourly', 'interval_hours' => '6', 'schedule_time' => '00:00', 'keep_hours' => '0', 'keep_daily' => '0', 'keep_weekly' => '0', 'keep_monthly' => '0', 'keep_last' => '0', 'csrf' => $ac]);
foreach ($r['data']['state']['jobs'] ?? [] as $j) {
    if ($j['id'] === $hj['id']) {
        $hj = $j;
    }
}
ok($hj['policy']['last'] === 1 && $hj['policy']['hours'] === 0, 'để trống hết vẫn giữ ít nhất 1 bản');
call('admin', 'admin', ['action' => 'job_delete', 'id' => $hj['id'], 'csrf' => $ac]);

// Đổi kho S3 sang vị trí khác: gỡ danh sách bản của vị trí cũ; chỉ đổi khóa thì giữ
$s3 = fn(array $x) => call('admin', 'admin', array_merge(['action' => 'dest_save', 'name' => 'S3 đổi', 'type' => 's3', 'subpath' => 'bk-old/srv', 'format' => 'restic', 'p_provider' => 'Other', 'p_region' => '', 'p_access_key_id' => 'AK1', 'p_secret_access_key' => 'SK1', 'p_endpoint' => 'https://s3.old.vn', 'csrf' => $ac], $x));
$r = $s3([]);
ok($r['ok'], 'lưu kho S3 restic: ' . ($r['error'] ?? ''));
$sd = null;
foreach (WNBStorage::read('destinations') as $d) {
    if ($d['name'] === 'S3 đổi') {
        $sd = $d;
    }
}
ok($sd && strlen($sd['location'] ?? '') === 40, 'kho cloud lưu dấu vết vị trí');
$fake = function ($id, $snap, $at = '2026-10-14 22:15:00') use ($sd) {
    return ['id' => $id, 'username' => 'shopgiay', 'reseller' => 'reseller_vn', 'owner_key' => 'reseller_vn', 'type' => 'backup', 'job_id' => '', 'destination_id' => $sd['id'], 'format' => 'restic', 'created_at' => $at, 'size_mb' => 1, 'restic' => ['home' => $snap, 'dbs' => [], 'home_path' => '/home/shopgiay', 'meta_path' => '/x', 'host' => 'h']];
};
WNBStorage::update('backups', fn($l) => array_merge([$fake('bkp_old1', str_repeat('a', 64)), $fake('bkp_old2', str_repeat('b', 64))], $l));
$countDest = fn() => count(array_filter(WNBStorage::read('backups'), fn($b) => $b['destination_id'] === $sd['id']));
$r = $s3(['id' => $sd['id'], 'p_access_key_id' => 'AK2', 'p_secret_access_key' => 'SK2', 'p_endpoint' => 'S3.OLD.VN/']);
ok($r['ok'] && $countDest() === 2, 'chỉ đổi khóa (endpoint viết khác kiểu) không gỡ bản: ' . ($r['error'] ?? $countDest()));
WNBStorage::update('queue', fn($l) => array_merge([['id' => 'task_busy', 'type' => 'backup', 'username' => 'shopgiay', 'status' => 'pending', 'destination_id' => $sd['id'], 'created_at' => WNB::now()]], $l));
$r = $s3(['id' => $sd['id'], 'p_endpoint' => 'https://s3.new.vn']);
ok(!$r['ok'] && $countDest() === 2, 'đang có tác vụ thì không cho đổi vị trí');
ok((WNBRclone::readRemote($sd['remote'])['endpoint'] ?? '') === 'S3.OLD.VN/', 'bị từ chối thì không ghi đè cấu hình rclone');
WNBStorage::update('queue', fn($l) => array_values(array_filter($l, fn($t) => $t['id'] !== 'task_busy')));
$r = $s3(['id' => $sd['id'], 'p_endpoint' => 'https://s3.new.vn']);
ok($r['ok'] && $countDest() === 0 && strpos($r['data']['message'] ?? '', 'gỡ 2 bản') !== false, 'đổi endpoint: gỡ 2 bản của vị trí cũ: ' . ($r['data']['message'] ?? $r['error'] ?? ''));
WNBStorage::update('backups', fn($l) => array_merge([$fake('bkp_x', str_repeat('c', 64))], $l));
$r = $s3(['id' => $sd['id'], 'p_endpoint' => 'https://s3.new.vn', 'subpath' => 'bk-new/srv']);
ok($r['ok'] && $countDest() === 0, 'đổi bucket cũng là đổi vị trí');

// Quét lại kho: gỡ bản mà snapshot không còn trong kho (chỉ bản cũ hơn lúc đọc danh sách)
WNBStorage::update('backups', fn($l) => array_merge([$fake('bkp_keep', str_repeat('d', 64)), $fake('bkp_gone', str_repeat('e', 64)), $fake('bkp_late', str_repeat('f', 64), '2026-10-15 09:00:00')], $l));
$n = WNBEngine::dropMissingSnapshots($sd['id'], [str_repeat('d', 64)], '2026-10-15 08:00:00');
$ids = array_column(array_filter(WNBStorage::read('backups'), fn($b) => $b['destination_id'] === $sd['id']), 'id');
sort($ids);
ok($n === 1 && $ids === ['bkp_keep', 'bkp_late'], 'quét lại kho gỡ bản không còn trong kho: ' . json_encode($ids));
WNBStorage::update('backups', fn($l) => array_values(array_filter($l, fn($b) => $b['destination_id'] !== $sd['id'])));
call('admin', 'admin', ['action' => 'dest_delete', 'id' => $sd['id'], 'csrf' => $ac]);
