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
