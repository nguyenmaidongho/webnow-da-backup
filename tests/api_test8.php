<?php
// Test: dừng tác vụ đang chờ / đang chạy, dừng lượt chạy của lịch, dừng tất cả.
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).
// Preview xử lý hàng đợi ngay trong request nên tác vụ được đưa thẳng vào hàng đợi (không kick).

$ac = tok('admin', 'admin');
$uc = tok('shopgiay', 'user');
$status = fn($id) => WNBEngine::findTask($id)['status'] ?? '';
$setRunning = function (string $id) {
    WNBStorage::update('queue', function ($q) use ($id) {
        foreach ($q as &$t) {
            if ($t['id'] === $id) {
                $t['status'] = 'running';
                $t['started_at'] = WNB::now();
            }
        }
        return $q;
    });
};

// Tác vụ đang chờ: hủy ngay
$t1 = WNBEngine::enqueue(['type' => 'snapshot', 'username' => 'shopgiay', 'initiated_by' => 'shopgiay', 'role' => 'user', 'destination_id' => 'dest_local']);
$t2 = WNBEngine::enqueue(['type' => 'backup', 'username' => 'shopgiay', 'initiated_by' => 'admin', 'role' => 'admin', 'destination_id' => 'dest_local']);
ok(!call('shopgiay', 'user', ['action' => 'task_cancel', 'task_id' => $t1['id']])['ok'], 'thiếu csrf bị chặn');
$r = call('shopgiay', 'user', ['action' => 'task_cancel', 'task_id' => $t2['id'], 'csrf' => $uc]);
ok(!$r['ok'] && $status($t2['id']) === 'pending', 'user không dừng được tác vụ admin tạo');
ok(!call('shopgiay', 'user', ['action' => 'task_cancel', 'all' => '1', 'csrf' => $uc])['ok'], 'user không dùng được "dừng tất cả"');
ok(!call('reseller_vn', 'reseller', ['action' => 'task_cancel', 'task_id' => $t1['id'], 'csrf' => tok('reseller_vn', 'reseller')])['ok'], 'reseller khác không dừng được tác vụ của user');
$r = call('shopgiay', 'user', ['action' => 'task_cancel', 'task_id' => $t1['id'], 'csrf' => $uc]);
ok($r['ok'] && $status($t1['id']) === 'cancelled', 'user dừng được tác vụ chính mình tạo: ' . ($r['error'] ?? ''));
$tk = null;
foreach ($r['data']['state']['queue'] as $q) {
    if ($q['id'] === $t1['id']) {
        $tk = $q;
    }
}
ok($tk && $tk['cancel_by'] === 'shopgiay' && strpos($tk['message'], 'shopgiay') !== false && !isset($tk['pid']), 'state trả về người dừng, ẩn pid');
ok(!call('shopgiay', 'user', ['action' => 'task_cancel', 'task_id' => $t1['id'], 'csrf' => $uc])['ok'], 'tác vụ đã dừng không dừng lại lần nữa');

// Tác vụ đang chạy: đặt cờ, worker dừng ở điểm kiểm tra kế tiếp, không ra bản sao lưu
$before = count(WNBStorage::read('backups'));
$r = call('admin', 'admin', ['action' => 'task_cancel', 'task_id' => $t2['id'], 'csrf' => $ac]);
ok($r['ok'] && $status($t2['id']) === 'cancelled', 'admin dừng được tác vụ đang chờ của bất kỳ ai');
$t3 = WNBEngine::enqueue(['type' => 'backup', 'username' => 'shopgiay', 'initiated_by' => 'admin', 'role' => 'admin', 'destination_id' => 'dest_local']);
$setRunning($t3['id']);
$r = call('admin', 'admin', ['action' => 'task_cancel', 'task_id' => $t3['id'], 'csrf' => $ac]);
ok($r['ok'] && $status($t3['id']) === 'running' && is_file(WNBEngine::cancelFlag($t3['id'])), 'tác vụ đang chạy: đặt cờ dừng');
ok(!call('admin', 'admin', ['action' => 'task_cancel', 'task_id' => $t3['id'], 'csrf' => $ac])['ok'], 'đang dừng thì không bấm dừng lần hai');
WNBEngine::execute(WNBEngine::findTask($t3['id']));
$t3 = WNBEngine::findTask($t3['id']);
ok($t3['status'] === 'cancelled' && count(WNBStorage::read('backups')) === $before, 'worker dừng tác vụ, không tạo bản sao lưu');
ok(!is_file(WNBEngine::cancelFlag($t3['id'])) && WNBShell::$guard === null, 'dọn cờ dừng và điểm kiểm tra');
ok(strpos(WNBStorage::readLog($t3['id']), 'ĐÃ DỪNG') !== false, 'log ghi đã dừng');

// Điểm kiểm tra chỉ ném một lần để lệnh dọn dẹp vẫn chạy
$flag = WNBEngine::cancelFlag('task_thu');
touch($flag);
WNBShell::$guard = function () use ($flag) {
    if (is_file($flag)) {
        WNBShell::$guard = null;
        throw new WNBCancelled();
    }
};
$thrown = false;
try { WNBShell::checkpoint(); } catch (WNBCancelled $e) { $thrown = true; }
$again = true;
try { WNBShell::checkpoint(); } catch (WNBCancelled $e) { $again = false; }
ok($thrown && $again && $e instanceof WNBError, 'điểm kiểm tra ném WNBCancelled đúng một lần');
@unlink($flag);

// Tác vụ xóa đang chạy không dừng giữa chừng
$t4 = WNBEngine::enqueue(['type' => 'delete', 'username' => 'shopgiay', 'initiated_by' => 'admin', 'role' => 'admin', 'backup_id' => 'bkp_x']);
$setRunning($t4['id']);
$r = call('admin', 'admin', ['action' => 'task_cancel', 'task_id' => $t4['id'], 'csrf' => $ac]);
ok(!$r['ok'] && strpos($r['error'], 'xóa') !== false && $status($t4['id']) === 'running', 'không dừng tác vụ xóa đang chạy');
WNBStorage::update('queue', fn($q) => array_values(array_filter($q, fn($t) => $t['id'] !== $t4['id'])));

// Dừng lượt chạy của một lịch: báo cáo lượt chạy đếm số tác vụ đã dừng
$job = WNBStorage::read('jobs')[0];
WNBStorage::update('jobs', function ($l) use ($job) {
    foreach ($l as &$j) {
        if ($j['id'] === $job['id']) {
            $j['last_run'] = '2026-10-15 01:00:00';
            $j['reported_run'] = null;
        }
    }
    return $l;
});
$j1 = WNBEngine::enqueue(['type' => 'backup', 'username' => 'shopgiay', 'initiated_by' => 'cron', 'job_id' => $job['id'], 'job_run' => '2026-10-15 01:00:00', 'destination_id' => 'dest_local']);
$j2 = WNBEngine::enqueue(['type' => 'backup', 'username' => 'shopgiay', 'initiated_by' => 'cron', 'job_id' => $job['id'], 'job_run' => '2026-10-15 01:00:00', 'destination_id' => 'dest_local']);
$other = WNBEngine::enqueue(['type' => 'snapshot', 'username' => 'shopgiay', 'initiated_by' => 'shopgiay', 'destination_id' => 'dest_local']);
ok(!call('reseller_vn', 'reseller', ['action' => 'task_cancel', 'job_id' => $job['id'], 'csrf' => tok('reseller_vn', 'reseller')])['ok'], 'reseller không dừng được lịch');
ok(!call('admin', 'admin', ['action' => 'task_cancel', 'job_id' => 'job_khong_co', 'csrf' => $ac])['ok'], 'lịch không tồn tại bị từ chối');
$r = call('admin', 'admin', ['action' => 'task_cancel', 'job_id' => $job['id'], 'csrf' => $ac]);
ok($r['ok'] && $status($j1['id']) === 'cancelled' && $status($j2['id']) === 'cancelled' && $status($other['id']) === 'pending', 'dừng lượt chạy chỉ đụng tác vụ của lịch: ' . ($r['error'] ?? ''));
$jr = null;
foreach (WNBStorage::read('jobs') as $j) {
    if ($j['id'] === $job['id']) {
        $jr = $j;
    }
}
ok($jr['reported_run'] === '2026-10-15 01:00:00' && $jr['last_result']['cancelled'] === 2 && $jr['last_result']['failed'] === 0, 'báo cáo lượt chạy đếm 2 tác vụ đã dừng');

// Dừng tất cả
$r = call('admin', 'admin', ['action' => 'task_cancel', 'all' => '1', 'csrf' => $ac]);
ok($r['ok'] && $status($other['id']) === 'cancelled', 'admin dừng tất cả');
$r = call('admin', 'admin', ['action' => 'task_cancel', 'all' => '1', 'csrf' => $ac]);
ok($r['ok'] && strpos($r['data']['message'], 'Không có') !== false, 'không còn gì để dừng');
ok($r['data']['state']['stats']['running'] === 0, 'tác vụ đã dừng không tính là đang chạy');

// 2.4.11: worker tự canh cờ dừng trong lúc chờ lệnh, cron dừng hẳn worker kẹt
$procs = [
    100 => ['state' => 'S', 'ppid' => 1, 'pgrp' => 100, 'start' => '1'],   // worker
    101 => ['state' => 'S', 'ppid' => 100, 'pgrp' => 100, 'start' => '2'], // sh -c
    102 => ['state' => 'S', 'ppid' => 101, 'pgrp' => 100, 'start' => '3'], // restic
    103 => ['state' => 'S', 'ppid' => 102, 'pgrp' => 103, 'start' => '4'], // rclone (nhóm riêng)
    200 => ['state' => 'S', 'ppid' => 1, 'pgrp' => 200, 'start' => '5'],   // tiến trình khác
];
$d = WNBShell::descendants(100, $procs);
sort($d);
ok($d === [101, 102, 103], 'tìm cả cây con, kể cả tiến trình đổi nhóm: ' . json_encode($d));
ok(WNBShell::descendants(200, $procs) === [], 'không đụng tiến trình ngoài cây');
ok(WNBShell::signal([102 => '3'], 'TERM', [102 => ['state' => 'S', 'ppid' => 1, 'pgrp' => 1, 'start' => '9']]) === 0, 'PID bị tái sử dụng (khác thời điểm khởi động) thì không kill');
ok(WNBShell::signal([102 => '3'], 'TERM', [102 => ['state' => 'Z', 'ppid' => 1, 'pgrp' => 1, 'start' => '3']]) === 0, 'bỏ qua tiến trình zombie');
ok(WNBShell::$abort === null && WNBShell::$guard === null, 'hết tác vụ thì gỡ điểm kiểm tra dừng');
ok(WNBEngine::STOP_TIMEOUT === 180, 'chốt chặn dừng hẳn worker sau 3 phút');
$t5 = WNBEngine::enqueue(['type' => 'backup', 'username' => 'shopgiay', 'initiated_by' => 'admin', 'role' => 'admin', 'destination_id' => 'dest_local']);
$setRunning($t5['id']);
WNBEngine::cancelTask($t5['id'], 'admin');
ok(!empty(WNBEngine::findTask($t5['id'])['cancel_at']), 'ghi thời điểm yêu cầu dừng');
WNBEngine::execute(WNBEngine::findTask($t5['id']));
ok($status($t5['id']) === 'cancelled', 'tác vụ dừng xong');
$code = WNBShell::run('echo xin chao', $o);
ok($code === 0 && trim($o[0] ?? '') === 'xin chao', 'run() ngoài tác vụ vẫn chạy như cũ');
