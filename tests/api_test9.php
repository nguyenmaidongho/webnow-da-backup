<?php
// Test: thử lại tài khoản lỗi trong lượt chạy lịch (2.4.10) và tiến độ trong bước sao lưu home.
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');
$set = function (string $id, array $changes) {
    WNBStorage::update('queue', function ($q) use ($id, $changes) {
        foreach ($q as &$t) {
            if ($t['id'] === $id) {
                $t = array_merge($t, $changes);
            }
        }
        return $q;
    });
};
$jobRow = function (string $id) {
    foreach (WNBStorage::read('jobs') as $j) {
        if ($j['id'] === $id) {
            return $j;
        }
    }
    return null;
};
$newRun = function (string $run) use (&$job) {
    WNBStorage::update('jobs', function ($l) use ($job, $run) {
        foreach ($l as &$j) {
            if ($j['id'] === $job['id']) {
                $j['enabled'] = true;
                $j['last_run'] = $run;
                $j['reported_run'] = null;
            }
        }
        return $l;
    });
};
$runTasks = fn(string $run) => array_values(array_filter(WNBStorage::read('queue'), fn($t) => ($t['job_run'] ?? '') === $run));

$job = WNBStorage::read('jobs')[0];
ok((int)WNBStorage::settings()['job_retry_minutes'] === 30, 'mặc định thử lại sau 30 phút');

// Lượt 1: 1 thành công, 1 lỗi mạng (thử lại), 1 lỗi kho đã xóa (không thử lại)
$run = '2026-10-15 02:00:00';
$newRun($run);
$base = ['type' => 'backup', 'initiated_by' => 'cron', 'job_id' => $job['id'], 'job_run' => $run, 'destination_id' => 'dest_local'];
$ok1 = WNBEngine::enqueue($base + ['username' => 'shopgiay']);
$bad1 = WNBEngine::enqueue($base + ['username' => 'quancafe']);
$bad2 = WNBEngine::enqueue($base + ['username' => 'reseller_vn']);
$set($ok1['id'], ['status' => 'completed']);
$set($bad1['id'], ['status' => 'failed', 'message' => WNBLang::msg('Tải lên cloud thất bại: {detail}', ['detail' => 'timeout'])]);
$set($bad2['id'], ['status' => 'failed', 'message' => WNBLang::msg('Kho lưu trữ đã bị xóa.')]);
WNBEngine::maybeJobReport($job['id']);
$tasks = $runTasks($run);
$retry = array_values(array_filter($tasks, fn($t) => ($t['retry_of'] ?? '') !== ''));
ok(count($retry) === 1 && $retry[0]['retry_of'] === $bad1['id'] && $retry[0]['username'] === 'quancafe', 'chỉ thử lại lỗi tạm thời, không thử lại lỗi kho đã xóa');
ok($retry && $retry[0]['status'] === 'pending' && $retry[0]['run_after'] > WNB::now() && !WNBEngine::ready($retry[0]), 'tác vụ thử lại đợi 30 phút, worker chưa nhận');
ok(WNBEngine::findTask($bad1['id'])['retried_by'] === $retry[0]['id'], 'tác vụ lỗi gắn tác vụ thử lại');
ok(empty($jobRow($job['id'])['reported_run']), 'chưa gửi báo cáo khi còn tác vụ thử lại');
ok(strpos(WNBStorage::readLog($bad1['id']), 'Sẽ thử lại lúc') !== false, 'log ghi giờ thử lại');
$st = call('admin', 'admin', ['action' => 'state'])['data'];
$rq = null;
foreach ($st['queue'] as $q) {
    if ($q['id'] === $retry[0]['id']) {
        $rq = $q;
    }
}
ok($rq && $rq['run_after'] === $retry[0]['run_after'], 'state trả giờ thử lại cho giao diện');

// Worker bỏ qua tác vụ chưa tới giờ
WNBEngine::worker();
ok(WNBEngine::findTask($retry[0]['id'])['status'] === 'pending', 'worker không chạy tác vụ thử lại trước giờ');

// Thử lại thành công -> báo cáo: lần lỗi đầu không tính
$set($retry[0]['id'], ['status' => 'completed']);
WNBEngine::maybeJobReport($job['id']);
$r = $jobRow($job['id']);
ok($r['reported_run'] === $run, 'báo cáo sau khi thử lại xong');
ok($r['last_result']['total'] === 3 && $r['last_result']['done'] === 2 && $r['last_result']['failed'] === 1 && $r['last_result']['retried'] === 1, 'kết quả lượt chạy tính lần thử lại: ' . json_encode($r['last_result']));
$st = call('admin', 'admin', ['action' => 'state'])['data'];
$failedIds = array_column(array_filter($st['queue'], fn($q) => $q['status'] === 'failed'), 'id');
ok(in_array($bad1['id'], $failedIds, true), 'lần lỗi đầu vẫn hiện trong danh sách tác vụ');

// Lượt 2: thử lại vẫn lỗi -> không thử lần thứ hai
$run = '2026-10-15 03:00:00';
$newRun($run);
$base['job_run'] = $run;
$b = WNBEngine::enqueue($base + ['username' => 'quancafe']);
$set($b['id'], ['status' => 'failed', 'message' => WNBLang::msg('rsync thất bại (mã {code}): {detail}', ['code' => 12, 'detail' => 'x'])]);
WNBEngine::maybeJobReport($job['id']);
$retry = array_values(array_filter($runTasks($run), fn($t) => ($t['retry_of'] ?? '') !== ''));
ok(count($retry) === 1, 'xếp thử lại lần 1');
$set($retry[0]['id'], ['status' => 'failed', 'message' => WNBLang::msg('rsync thất bại (mã {code}): {detail}', ['code' => 12, 'detail' => 'x'])]);
WNBEngine::maybeJobReport($job['id']);
$r = $jobRow($job['id']);
ok(count($runTasks($run)) === 2 && $r['reported_run'] === $run && $r['last_result']['failed'] === 1 && $r['last_result']['total'] === 1, 'chỉ thử lại một lần rồi báo lỗi');

// Tắt thử lại
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'job_retry_minutes' => '0', 'csrf' => $ac]);
ok((int)WNBStorage::settings()['job_retry_minutes'] === 0, 'lưu cài đặt 0 = tắt thử lại');
$run = '2026-10-15 04:00:00';
$newRun($run);
$base['job_run'] = $run;
$b = WNBEngine::enqueue($base + ['username' => 'quancafe']);
$set($b['id'], ['status' => 'failed', 'message' => WNBLang::msg('rsync thất bại (mã {code}): {detail}', ['code' => 12, 'detail' => 'x'])]);
WNBEngine::maybeJobReport($job['id']);
ok(count($runTasks($run)) === 1 && $jobRow($job['id'])['reported_run'] === $run, 'tắt thử lại thì báo cáo ngay');
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'job_retry_minutes' => '9999', 'csrf' => $ac]);
ok((int)WNBStorage::settings()['job_retry_minutes'] === 720, 'giới hạn tối đa 720 phút');
call('admin', 'admin', ['action' => 'settings_save', 'local_backup_root' => '/backup/webnowbackup', 'job_retry_minutes' => '30', 'csrf' => $ac]);

// Tiến độ
ok(WNBEngine::humanSize(5 * 1048576) === '5 MB' && WNBEngine::humanSize(1.5 * 1073741824) === '1.5 GB', 'định dạng dung lượng');
$t = WNBEngine::enqueue(['type' => 'backup', 'username' => 'shopgiay', 'initiated_by' => 'admin', 'destination_id' => 'dest_local']);
$hp = new ReflectionMethod('WNBEngine', 'homeProgress');
if (PHP_VERSION_ID < 80100) {
    $hp->setAccessible(true);
}
$hp->invoke(null, WNBEngine::findTask($t['id']), 35, 80, 'Đồng bộ thư mục home: {done} / ~{total}', [], 512 * 1048576, 1024 * 1048576);
$x = WNBEngine::findTask($t['id']);
ok($x['progress'] === 57 && WNBLang::render($x['message']) === 'Đồng bộ thư mục home: 512 MB / ~1 GB', 'tiến độ giữa chừng: ' . $x['progress'] . ' ' . WNBLang::render($x['message']));
$hp->invoke(null, $x, 35, 80, 'Đồng bộ thư mục home: {done} / ~{total}', [], 3 * 1073741824, 1073741824);
$x = WNBEngine::findTask($t['id']);
ok($x['progress'] === 79 && WNBLang::render($x['message']) === 'Đồng bộ thư mục home: 3 GB / ~3 GB', 'vượt ước tính thì dừng ở 99% của bước');
$set($t['id'], ['status' => 'cancelled']);

// runWatch: gọi tick trong lúc lệnh chạy, trả output và mã thoát
$script = WNBStorage::path('tmp/watch_test.php');
file_put_contents($script, '<?php echo "dong 1\n"; fflush(STDOUT); usleep(2500000); echo "dong 2\r  50%\n"; exit(3);');
$ticks = [];
// cmd.exe của PHP 7.4 trên Windows hỏng khi lệnh bắt đầu bằng dấu nháy; đường dẫn ở đây không có khoảng trắng.
$q = DIRECTORY_SEPARATOR === '\\' ? fn($a) => $a : 'escapeshellarg';
$code = WNBShell::runWatch($q(PHP_BINARY) . ' -n ' . $q($script), $out, function ($log) use (&$ticks) {
    $ticks[] = WNBShell::tailFile($log);
}, 1);
@unlink($script);
ok($code === 3 && $out === ['dong 1', 'dong 2', '  50%'], 'runWatch trả mã thoát và output: ' . $code . ' ' . json_encode($out));
ok(count($ticks) >= 1 && strpos($ticks[0], 'dong 1') !== false, 'tick đọc được output trong lúc lệnh chạy (' . count($ticks) . ' lần)');
ok(!glob(WNBStorage::path('tmp/run_*')), 'dọn file output tạm');
