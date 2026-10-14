<?php
// Tạo dữ liệu demo cho preview: vài bản sao lưu trải đều theo ngày, một tác vụ lỗi, một lịch đang bật.
putenv('WNB_PREVIEW=1');
require 'C:/laragon/www/vaultbackup/includes/bootstrap.php';
$ac = WNBApi::csrfToken('admin', 'admin');
WNBApi::handle('admin', 'admin', ['action' => 'job_toggle', 'id' => 'job_daily', 'enabled' => '1', 'csrf' => $ac]);
foreach (['admin', 'reseller_vn', 'shopgiay', 'shopgiay', 'shopgiay', 'reseller_vn'] as $u) {
    WNBApi::handle('admin', 'admin', ['action' => 'backup_now', 'username' => $u, 'destination_id' => 'dest_local', 'csrf' => $ac]);
}
WNBApi::handle('shopgiay', 'user', ['action' => 'snapshot', 'note' => 'Trước khi cập nhật WooCommerce 9.3', 'csrf' => WNBApi::csrfToken('shopgiay', 'user')]);

// Dàn thời gian cho giống thực tế
$offsets = [3600 * 2, 3600 * 5, 86400 + 3600, 86400 * 2 + 1800, 86400 * 3 + 600, 86400 * 4, 86400 * 5];
$i = 0;
WNBStorage::update('backups', function ($l) use (&$i, $offsets) {
    foreach ($l as &$b) {
        $b['created_at'] = date('Y-m-d H:i:s', time() - $offsets[$i++ % count($offsets)]);
        if ($b['type'] === 'backup') {
            $b['job_id'] = $i % 2 ? 'job_daily' : '';
            $b['incremental'] = $i > 2;
        }
    }
    usort($l, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $l;
});
WNBStorage::update('queue', function ($q) {
    $j = 0;
    foreach ($q as &$t) {
        $t['created_at'] = date('Y-m-d H:i:s', time() - 600 * (++$j));
        $t['started_at'] = $t['created_at'];
        $t['finished_at'] = date('Y-m-d H:i:s', strtotime($t['created_at']) + 40 + $j * 7);
    }
    $q[2]['status'] = 'failed';
    $q[2]['message'] = WNBLang::msg('Dump database {db} thất bại: {detail}', ['db' => 'shopgiay_erp', 'detail' => 'mysqldump: Got error: 1045 Access denied']);
    array_unshift($q, array_merge($q[0], ['id' => 'task_demo_running', 'type' => 'backup', 'username' => 'nhakhoa', 'status' => 'running', 'progress' => 35, 'message' => WNBLang::msg('Đồng bộ thư mục home'), 'created_at' => date('Y-m-d H:i:s', time() - 40), 'finished_at' => null, 'warnings' => 0]));
    return $q;
});
WNBStorage::update('destinations', function ($l) {
    $l[0]['free_gb'] = 412.6;
    $l[0]['last_test'] = ['ok' => true, 'message' => WNBLang::msg('Ghi được vào {path}, còn trống {free} GB.', ['path' => '/backup/webnowbackup', 'free' => 412.6]), 'at' => date('Y-m-d H:i:s', time() - 7200)];
    $l[] = ['id' => 'dest_r2demo', 'name' => 'Cloudflare R2', 'type' => 'r2', 'path' => '', 'remote' => 'wnb_r2demo', 'subpath' => 'webnow-backup/control3', 'free_gb' => null,
        'last_test' => ['ok' => true, 'message' => WNBLang::msg('Kết nối thành công tới {target}.', ['target' => 'wnb_r2demo:webnow-backup/control3']), 'at' => date('Y-m-d H:i:s', time() - 86400)], 'created_at' => date('Y-m-d H:i:s')];
    return $l;
});
echo "seeded\n";
