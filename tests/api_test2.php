<?php
// Test bổ sung: phạm vi lịch, loại trừ chung, báo cáo lượt chạy, retention tách riêng, Telegram, song ngữ.
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại $b, $c, ok(), call(), tok()).

$ac = tok('admin', 'admin');
$r = call('admin', 'admin', ['action' => 'job_preview', 'exclude_users' => json_encode(['nhakhoa']), 'csrf' => $ac]);
ok(!in_array('nhakhoa', $r['data']['users'], true) && count($r['data']['users']) === 3, 'job_preview: tất cả trừ loại trừ');
$r = call('admin', 'admin', ['action' => 'job_preview', 'csrf' => $ac]);
ok(count($r['data']['users']) === 4, 'job_preview: không loại trừ = tất cả');
// Loại trừ theo reseller: mất cả reseller lẫn khách của reseller
$pv = fn(array $p) => call('admin', 'admin', array_merge(['action' => 'job_preview', 'csrf' => $ac], $p))['data']['users'] ?? null;
ok($pv(['exclude_resellers' => json_encode(['reseller_vn'])]) === ['admin', 'nhakhoa'], 'loại trừ reseller_vn → bỏ reseller_vn + shopgiay');
ok($pv(['exclude_resellers' => json_encode(['admin'])]) === ['reseller_vn', 'shopgiay'], 'loại trừ admin → bỏ admin + khách do admin tạo, giữ reseller');
ok($pv(['exclude_resellers' => json_encode(['admin', 'reseller_vn'])]) === [], 'loại trừ mọi reseller → không còn tài khoản');
ok(count($pv(['exclude_resellers' => json_encode(['shopgiay', 'ghost', '../x'])])) === 4, 'user thường / không tồn tại không được coi là reseller');
ok($pv(['exclude_resellers' => json_encode(['reseller_vn']), 'exclude_users' => json_encode(['nhakhoa'])]) === ['admin'], 'kết hợp loại trừ reseller + tài khoản');
$r = call('admin', 'admin', ['action' => 'job_save', 'name' => 'Trừ reseller', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '04:00', 'exclude_resellers' => json_encode(['reseller_vn', 'shopgiay']), 'retention' => '2', 'csrf' => $ac]);
$rj = null;
foreach ($r['data']['state']['jobs'] ?? [] as $j) {
    if ($j['name'] === 'Trừ reseller') {
        $rj = $j;
    }
}
ok($rj && $rj['exclude_resellers'] === ['reseller_vn'] && $rj['exclude_users'] === [] && $rj['target_count'] === 2, 'lịch lưu exclude_resellers (chỉ reseller hợp lệ): ' . json_encode($rj));
// Khách tạo sau này của reseller bị loại trừ cũng không được sao lưu; khách mới của admin thì có
WNBDirectAdmin::$demoExtra = [
    'khachmoi' => ['username' => 'khachmoi', 'usertype' => 'user', 'creator' => 'reseller_vn', 'domain' => 'khachmoi.vn', 'domains' => ['khachmoi.vn'], 'package' => '', 'suspended' => false, 'quota_mb' => 0, 'usage_mb' => 1.0],
    'adminmoi' => ['username' => 'adminmoi', 'usertype' => 'user', 'creator' => 'admin', 'domain' => 'adminmoi.vn', 'domains' => ['adminmoi.vn'], 'package' => '', 'suspended' => false, 'quota_mb' => 0, 'usage_mb' => 1.0],
];
$tg = WNBEngine::jobTargets($rj);
sort($tg);
ok($tg === ['admin', 'adminmoi', 'nhakhoa'], 'khách mới của reseller bị loại trừ, khách mới khác vẫn sao lưu: ' . json_encode($tg));
WNBDirectAdmin::$demoExtra = [];
// Loại trừ reseller chung trong Cài đặt áp dụng mọi lịch
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'exclude_resellers' => json_encode(['admin', 'nhakhoa']), 'default_retention' => '2', 'max_snapshots_per_user' => '3', 'csrf' => $ac]);
ok(WNBStorage::settings()['exclude_resellers'] === ['admin'], 'cài đặt chỉ nhận reseller/admin hợp lệ');
ok(WNBEngine::jobTargets(['exclude_users' => []]) === ['reseller_vn', 'shopgiay'], 'loại trừ reseller chung áp dụng cho lịch');
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'default_retention' => '2', 'max_snapshots_per_user' => '3', 'csrf' => $ac]);
ok(WNBStorage::settings()['exclude_resellers'] === [] && count(WNBEngine::jobTargets(['exclude_users' => []])) === 4, 'bỏ loại trừ reseller chung');
call('admin', 'admin', ['action' => 'job_delete', 'id' => $rj['id'], 'csrf' => $ac]);

// Dù gửi kiểu cũ "chỉ tài khoản đã chọn", lịch vẫn theo quy tắc loại trừ
$r = call('admin', 'admin', ['action' => 'job_save', 'name' => 'Chọn tay', 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '01:00', 'target' => 'users', 'users' => json_encode(['shopgiay']), 'exclude_users' => json_encode(['reseller_vn', 'admin']), 'retention' => '2', 'csrf' => $ac]);
ok($r['ok'], 'job lưu được: ' . ($r['error'] ?? ''));
$job = null;
foreach ($r['data']['state']['jobs'] ?? [] as $j) {
    if ($j['name'] === 'Chọn tay') {
        $job = $j;
    }
}
ok($job && !isset($job['target']) && $job['target_count'] === 2, 'lịch = tất cả trừ 2 loại trừ: ' . json_encode($job['target_count'] ?? null));
// Tài khoản mới tạo sau vẫn được sao lưu (lịch cũ có target=users cũng bị bỏ qua phần chọn)
ok(WNBEngine::jobTargets(['target' => 'users', 'users' => ['shopgiay'], 'exclude_users' => []]) === WNBDirectAdmin::usernames(), 'lịch kiểu cũ vẫn sao lưu mọi tài khoản');

// Loại trừ chung
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'exclude_accounts' => json_encode(['nhakhoa']), 'default_retention' => '2', 'max_snapshots_per_user' => '3', 'csrf' => $ac]);
ok(WNBEngine::jobTargets($job) === ['shopgiay'], 'loại trừ chung áp dụng cho lịch');
$r = call('admin', 'admin', ['action' => 'job_run', 'id' => $job['id'], 'csrf' => $ac]);
ok($r['ok'] && strpos($r['data']['message'], '1 tài khoản') !== false, 'job_run 1 tài khoản: ' . ($r['data']['message'] ?? $r['error']));
$jj = null;
foreach (WNBStorage::read('jobs') as $j) {
    if ($j['id'] === $job['id']) {
        $jj = $j;
    }
}
ok(($jj['reported_run'] ?? '') === $jj['last_run'] && $jj['last_result']['done'] === 1 && $jj['last_result']['finished_at'], 'báo cáo lượt chạy được ghi: ' . json_encode($jj['last_result'] ?? null));

// Retention tách riêng: bản thủ công không bị lịch xóa
$countWhere = fn($f) => count(array_filter(WNBStorage::read('backups'), $f));
$manualBefore = $countWhere(fn($x) => $x['username'] === 'shopgiay' && $x['type'] === 'backup' && $x['job_id'] === '');
for ($i = 0; $i < 3; $i++) {
    WNBEngine::runJob($jj, 'test');
    WNBEngine::worker();
}
$jobCopies = $countWhere(fn($x) => $x['username'] === 'shopgiay' && $x['job_id'] === $job['id']);
$manualAfter = $countWhere(fn($x) => $x['username'] === 'shopgiay' && $x['type'] === 'backup' && $x['job_id'] === '');
ok($jobCopies === 2, "lịch giữ đúng 2 bản: $jobCopies");
ok($manualAfter === $manualBefore, "bản thủ công không bị lịch xóa: $manualBefore -> $manualAfter");

// Lịch không đưa lại tài khoản đang có tác vụ
WNBStorage::update('queue', function ($q) {
    array_unshift($q, ['id' => 'task_busy2', 'type' => 'backup', 'username' => 'shopgiay', 'status' => 'running', 'created_at' => WNB::now(), 'message' => 'x', 'warnings' => 0]);
    return $q;
});
$res = WNBEngine::runJob($jj, 'test');
ok($res['queued'] === 0 && $res['skipped'] === 1, 'bỏ qua tài khoản đang bận: ' . json_encode($res));
WNBStorage::update('queue', fn($q) => array_values(array_filter($q, fn($x) => $x['id'] !== 'task_busy2')));

// Telegram: chỉ ghi đè, không đọc lại
$r = call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'telegram_token' => 'bad', 'csrf' => $ac]);
ok(!$r['ok'], 'token sai bị chặn');
$r = call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'telegram_token' => '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1', 'telegram_chat_id' => '-1001234567890', 'csrf' => $ac]);
ok($r['ok'], 'lưu telegram: ' . ($r['error'] ?? ''));
$set = $r['data']['state']['settings'];
ok(!isset($set['telegram_token']) && !isset($set['telegram_chat_id']) && $set['telegram_configured'] === true, 'không trả token/chat id về giao diện');
$raw = json_encode($r);
ok(strpos($raw, 'AAHdqTcv') === false && strpos($raw, '1001234567890') === false, 'token không lọt ra JSON');
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'csrf' => $ac]);
ok(WNBStorage::settings()['telegram_token'] !== '', 'lưu lại form trống không xóa token');
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'telegram_clear' => '1', 'csrf' => $ac]);
ok(WNBStorage::settings()['telegram_token'] === '' && WNBStorage::settings()['telegram_chat_id'] === '', 'xóa cấu hình telegram');
$us = call('shopgiay', 'user', ['action' => 'state'])['data'];
ok(!isset($us['settings']['telegram_configured']) && !isset($us['settings']['notify_email']), 'user không thấy cấu hình thông báo');
ok(telegramEscapeOk(), 'telegram config escape');

// Song ngữ
$r = call('admin', 'admin', ['action' => 'backup_now', 'username' => 'ghost', 'csrf' => $ac, 'lang' => 'en']);
ok($r['error'] === 'You have no access to account ghost.', 'lỗi tiếng Anh: ' . $r['error']);
$st = call('admin', 'admin', ['action' => 'state', 'lang' => 'en'])['data'];
$doneTask = null;
foreach ($st['queue'] as $tq) {
    if ($tq['status'] === 'completed' && $tq['type'] === 'backup') {
        $doneTask = $tq;
        break;
    }
}
ok($doneTask && strpos($doneTask['message'], 'Backed up') === 0, 'thông báo tác vụ dịch theo người xem: ' . ($doneTask['message'] ?? ''));
$st = call('admin', 'admin', ['action' => 'state', 'lang' => 'vi'])['data'];
$doneTask = null;
foreach ($st['queue'] as $tq) {
    if ($tq['status'] === 'completed' && $tq['type'] === 'backup') {
        $doneTask = $tq;
        break;
    }
}
ok($doneTask && strpos($doneTask['message'], 'Đã sao lưu') === 0, 'cùng tác vụ hiển thị tiếng Việt');
$r = call('shopgiay', 'user', ['action' => 'restore', 'backup_id' => $b['id'], 'scope' => 'dns', 'items' => json_encode(['shopgiaydep.vn']), 'csrf' => $c, 'lang' => 'en']);
ok($r['ok'] && $r['data']['message'] === 'Started restoring DNS.', 'thông báo lồng biến dịch đúng: ' . ($r['data']['message'] ?? $r['error']));
WNBLang::set('vi');

/** Nội dung có dấu ngoặc kép / xuống dòng không phá cấu hình curl. */
function telegramEscapeOk(): bool
{
    $q = fn(string $v) => '"' . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', '\\n'], $v) . '"';
    return $q("a\"b\nc\\d") === '"a\\"b\\nc\\\\d"';
}
