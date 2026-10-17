<?php
// Test 2.6.0: chuyển kho (mọi bản sao lưu của một kho sang kho mới, thư mục trống).
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');
$saved = ['settings' => WNBStorage::read('settings'), 'jobs' => WNBStorage::read('jobs'), 'backups' => WNBStorage::read('backups'),
    'destinations' => WNBStorage::read('destinations'), 'queue' => WNBStorage::read('queue')];

WNBRclone::saveRemote('wnb_small', 's3', ['provider' => 'Other', 'access_key_id' => 'a', 'secret_access_key' => 's', 'endpoint' => 'https://s3.example.vn']);
WNBRclone::saveRemote('wnb_big', 's3', ['provider' => 'Other', 'access_key_id' => 'b', 'secret_access_key' => 't', 'endpoint' => 's3.example.vn/']);
WNBRclone::saveRemote('wnb_other', 's3', ['provider' => 'Other', 'access_key_id' => 'c', 'secret_access_key' => 'u', 'endpoint' => 'https://s3.khac.vn']);
$D = [
    'small' => ['id' => 'dest_small', 'name' => 'S3 nhỏ', 'type' => 's3', 'remote' => 'wnb_small', 'subpath' => 'nho/control3', 'format' => 'archive'],
    'big' => ['id' => 'dest_big', 'name' => 'S3 lớn', 'type' => 's3', 'remote' => 'wnb_big', 'subpath' => 'lon/control3', 'format' => 'archive'],
    'inside' => ['id' => 'dest_inside', 'name' => 'Lồng', 'type' => 's3', 'remote' => 'wnb_big', 'subpath' => 'nho/control3/moi', 'format' => 'folder'],
    'other' => ['id' => 'dest_other', 'name' => 'Khác', 'type' => 's3', 'remote' => 'wnb_other', 'subpath' => 'nho/control3', 'format' => 'folder'],
    'rs' => ['id' => 'dest_rs', 'name' => 'Restic', 'type' => 's3', 'remote' => 'wnb_other', 'subpath' => 'rs', 'format' => 'restic'],
    'rs2' => ['id' => 'dest_rs2', 'name' => 'Restic 2', 'type' => 's3', 'remote' => 'wnb_big', 'subpath' => 'rs', 'format' => 'restic'],
    'disk' => ['id' => 'dest_disk', 'name' => 'Ổ phụ', 'type' => 'local', 'path' => '/backup2/webnowbackup', 'remote' => '', 'subpath' => ''],
    'disk2' => ['id' => 'dest_disk2', 'name' => 'Ổ con', 'type' => 'local', 'path' => '/backup2/webnowbackup/con', 'remote' => '', 'subpath' => ''],
];
WNBStorage::write('destinations', array_merge($saved['destinations'], array_values($D)));
$bk = fn($id, $u, $dest, $fmt, $extra = []) => array_merge(['id' => $id, 'username' => $u, 'owner_key' => 'demo:' . $u, 'type' => 'backup', 'destination_id' => $dest['id'],
    'destination_name' => $dest['name'], 'destination_type' => $dest['type'], 'format' => $fmt, 'job_id' => 'job_move', 'created_at' => '2026-10-01 02:00:00', 'warnings' => [],
    'domains' => [], 'databases' => [], 'emails' => [], 'crons' => [], 'size_mb' => 10, 'storage_path' => 'wnb_small:nho/control3/' . $u . '/' . $id], $extra);
$B = [
    $bk('bkp_shopgiay_1', 'shopgiay', $D['small'], 'archive', ['home_archive' => true]),
    $bk('bkp_nhakhoa_1', 'nhakhoa', $D['small'], 'folder'),
    $bk('bkp_shopgiay_r', 'shopgiay', $D['rs'], 'restic', ['restic' => ['home' => 'aaa', 'dbs' => []]]),
    $bk('bkp_shopgiay_l', 'shopgiay', $D['disk'], 'folder'),
];

// ---------- Điều kiện chuyển ----------
$why = fn($from, $to, $list) => ($e = WNBEngine::moveProblem($from, $to, $list)) ? $e->key : '';
ok($why($D['small'], $D['big'], $B) === '', 'S3 nhỏ sang S3 lớn (Nén sang Nén) được');
ok($why($D['small'], $D['small'], $B) === 'Hãy chọn kho đích khác kho đang chuyển.', 'cùng một kho');
ok($why($D['big'], $D['other'], $B) === 'Kho "{name}" không có bản sao lưu nào để chuyển.', 'kho trống không có gì để chuyển');
ok($why($D['small'], $D['rs'], $B) === 'Kho đích "{name}" đã có bản sao lưu. Hãy chuyển sang một kho mới thêm, thư mục trống.', 'kho đích phải chưa có bản nào');
ok($why($D['small'], $D['rs2'], $B) === 'Kho đích kiểu Incremental (restic) không nhận được bản kiểu Nén/Thư mục. Hãy chọn kho đích kiểu Nén hoặc Thư mục.', 'Nén không sang restic');
ok($why($D['rs'], $D['big'], $B) === 'Bản sao lưu của kho "{name}" là kiểu Incremental (restic) nên kho đích cũng phải là kho cloud kiểu Incremental (restic).', 'restic chỉ sang restic');
ok($why($D['rs'], $D['rs2'], $B) === '', 'restic sang restic được');
ok($why($D['small'], $D['disk2'], $B) === 'Bản kiểu Nén chỉ chuyển được sang kho cloud.', 'bản Nén không về ổ cứng');
ok($why($D['small'], $D['disk2'], array_slice($B, 1)) === '', 'bản Thư mục về ổ cứng được');
ok($why($D['disk'], $D['big'], $B) === '', 'ổ cứng lên cloud được');
ok($why($D['disk'], $D['disk2'], $B) === 'Hai kho trỏ vào cùng một chỗ hoặc thư mục lồng nhau. Hãy chọn kho đích ở thư mục khác.', 'ổ cứng lồng nhau');
ok($why($D['small'], $D['inside'], $B) === 'Hai kho trỏ vào cùng một chỗ hoặc thư mục lồng nhau. Hãy chọn kho đích ở thư mục khác.', 'cùng endpoint (khác khóa), thư mục lồng nhau');
ok($why($D['small'], $D['other'], $B) === '', 'cùng thư mục nhưng khác endpoint là kho khác');
$mixed = array_merge($B, [$bk('bkp_mix', 'shopgiay', $D['small'], 'restic')]);
ok($why($D['small'], $D['big'], $mixed) === 'Kho "{name}" có cả bản Incremental (restic) lẫn bản Nén/Thư mục nên chưa chuyển được.', 'kho lẫn hai kiểu');

// ---------- Bản ghi sau khi chuyển ----------
$m = WNBEngine::movedRecord($B[0], $D['big']);
ok($m['destination_id'] === 'dest_big' && $m['destination_name'] === 'S3 lớn' && $m['storage_path'] === 'wnb_big:lon/control3/shopgiay/bkp_shopgiay_1'
    && $m['owner_key'] === 'demo:shopgiay' && $m['job_id'] === 'job_move' && $m['format'] === 'archive' && $m['home_archive'] === true && empty($m['imported']),
    'đường dẫn theo kho mới, giữ chủ sở hữu/lịch/kiểu: ' . $m['storage_path']);
ok(WNBEngine::movedRecord($B[1], $D['disk'])['storage_path'] === '/backup2/webnowbackup/nhakhoa/bkp_nhakhoa_1', 'sang ổ cứng');
ok(WNBEngine::movedRecord($B[2], $D['rs2'])['storage_path'] === 'rclone:wnb_big:rs/restic', 'restic: đường dẫn kho restic mới');
ok(WNBEngine::movedRecord($B[1], $D['small'])['storage_path'] === $B[1]['storage_path'], 'bản ghi khớp vị trí ở kho cũ');

// ---------- Hàng đợi: tác vụ của hai kho đợi chuyển kho ----------
$T = fn($id, $type, $status, $extra = []) => array_merge(['id' => $id, 'type' => $type, 'status' => $status, 'username' => '', 'destination_id' => '', 'backup_id' => ''], $extra);
WNBStorage::write('backups', $B);
// Hàng đợi: mới nhất ở đầu.
$q = [
    $T('t_new_restore', 'restore', 'pending', ['backup_id' => 'bkp_nhakhoa_1', 'destination_id' => 'dest_small']),
    $T('t_new_dl', 'download', 'pending', ['backup_id' => 'bkp_shopgiay_1']),
    $T('t_new_target', 'backup', 'pending', ['destination_id' => 'dest_big']),
    $T('t_new_other', 'backup', 'pending', ['destination_id' => 'dest_local']),
    $T('t_move', 'move', 'pending', ['destination_id' => 'dest_small', 'target_id' => 'dest_big']),
    $T('t_old', 'backup', 'pending', ['destination_id' => 'dest_small']),
];
ok(array_keys(WNBEngine::moveBlocked($q)) === ['t_new_target', 't_new_dl', 't_new_restore'], 'tác vụ mới hơn chạm vào hai kho đợi chuyển kho: ' . json_encode(array_keys(WNBEngine::moveBlocked($q))));
$q[5]['status'] = 'running';
ok(isset(WNBEngine::moveBlocked($q)['t_move']), 'kho còn tác vụ đang chạy: chuyển kho đợi');
$q[5]['status'] = 'completed';
$q[4]['status'] = 'running';
ok(array_keys(WNBEngine::moveBlocked($q)) === ['t_new_target', 't_new_dl', 't_new_restore'], 'đang chuyển: tác vụ của hai kho đợi, kho khác chạy bình thường');
ok(WNBEngine::moveBlocked([$T('a', 'backup', 'pending', ['destination_id' => 'dest_small'])]) === [], 'không có chuyển kho: không chặn gì');
WNBStorage::write('queue', $q);
ok(WNBEngine::destinationBusy('dest_big') && WNBEngine::movingDestinations() === ['dest_small' => true, 'dest_big' => true], 'kho đích cũng tính là bận');
ok(!call('admin', 'admin', ['action' => 'dest_move', 'id' => 'dest_small', 'target_id' => 'dest_other', 'csrf' => $ac])['ok'], 'kho đang được chuyển: không chuyển thêm');
ok(!call('admin', 'admin', ['action' => 'dest_delete', 'id' => 'dest_big', 'csrf' => $ac])['ok'], 'kho đích đang chuyển: không xóa được');
WNBStorage::write('queue', $saved['queue']);

// Dữ liệu đã chép xong, đang chuyển bản ghi: không dừng được.
WNBStorage::update('queue', function ($q) use ($T) { array_unshift($q, $T('t_nc', 'move', 'running', ['destination_id' => 'dest_small', 'target_id' => 'dest_big', 'no_cancel' => true, 'pid' => 0])); return $q; });
$err = '';
try { WNBEngine::cancelTask('t_nc', 'admin'); } catch (WNBError $e) { $err = $e->key; }
ok($err === 'Dữ liệu đã chép xong, đang chuyển bản ghi sang kho mới nên không dừng được nữa. Hãy đợi nó xong.', 'bước chuyển bản ghi không dừng được');
ok(WNBEngine::cancelAll('admin') === 0, 'dừng tất cả bỏ qua bước chuyển bản ghi');
WNBStorage::write('queue', $saved['queue']);

// ---------- Chuyển kho qua API (preview: không chép dữ liệu thật) ----------
WNBStorage::write('jobs', [['id' => 'job_move', 'name' => 'Move', 'enabled' => false, 'destination_id' => 'dest_small', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'last_run' => null, 'filter_mode' => 'exclude', 'exclude_users' => [], 'exclude_resellers' => []],
    ['id' => 'job_keep', 'name' => 'Keep', 'enabled' => false, 'destination_id' => 'dest_local', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'last_run' => null, 'filter_mode' => 'exclude', 'exclude_users' => [], 'exclude_resellers' => []]]);
WNBStorage::update('settings', fn($s) => array_merge($s, ['snapshot_destination' => 'dest_small']));
ok(!call('reseller_vn', 'reseller', ['action' => 'dest_move', 'id' => 'dest_small', 'target_id' => 'dest_big', 'csrf' => tok('reseller_vn', 'reseller')])['ok'], 'reseller không chuyển kho được');
ok(!call('admin', 'admin', ['action' => 'dest_move', 'id' => 'dest_small', 'target_id' => 'dest_rs', 'csrf' => $ac])['ok'], 'API từ chối kho đích đã có bản');
$r = call('admin', 'admin', ['action' => 'dest_move', 'id' => 'dest_small', 'target_id' => 'dest_big', 'jobs' => '1', 'delete_source' => '1', 'csrf' => $ac]);
ok($r['ok'], 'chuyển kho: ' . ($r['error'] ?? ''));
$task = null;
foreach (WNBStorage::read('queue') as $t) {
    if ($t['type'] === 'move') {
        $task = $t;
        break;
    }
}
ok($task && $task['status'] === 'completed' && $task['target_id'] === 'dest_big' && $task['options'] === ['jobs' => true, 'delete' => true], 'tác vụ chuyển kho hoàn tất: ' . json_encode($task['message'] ?? null));
$after = array_column(WNBStorage::read('backups'), null, 'id');
ok($after['bkp_shopgiay_1']['destination_id'] === 'dest_big' && $after['bkp_nhakhoa_1']['destination_id'] === 'dest_big' && $after['bkp_shopgiay_1']['owner_key'] === 'demo:shopgiay'
    && $after['bkp_shopgiay_r']['destination_id'] === 'dest_rs' && $after['bkp_shopgiay_l']['destination_id'] === 'dest_disk', 'chỉ bản của kho cũ được chuyển, giữ chủ sở hữu');
$jobs = array_column(WNBStorage::read('jobs'), 'destination_id', 'id');
ok($jobs === ['job_move' => 'dest_big', 'job_keep' => 'dest_local'] && WNBStorage::settings()['snapshot_destination'] === 'dest_big', 'lịch và kho snapshot chuyển sang kho mới');
$st = call('shopgiay', 'user', ['action' => 'state'])['data'] ?? [];
ok(in_array('bkp_shopgiay_1', array_column($st['backups'] ?? [], 'id'), true), 'khách vẫn thấy bản của mình sau khi chuyển');
ok(!call('admin', 'admin', ['action' => 'dest_move', 'id' => 'dest_small', 'target_id' => 'dest_other', 'csrf' => $ac])['ok'], 'kho cũ đã trống');

// Không chọn chuyển lịch: lịch giữ nguyên.
WNBStorage::write('backups', $B);
WNBStorage::write('jobs', [['id' => 'job_move', 'name' => 'Move', 'enabled' => false, 'destination_id' => 'dest_small', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'last_run' => null, 'filter_mode' => 'exclude', 'exclude_users' => [], 'exclude_resellers' => []]]);
ok(call('admin', 'admin', ['action' => 'dest_move', 'id' => 'dest_small', 'target_id' => 'dest_other', 'jobs' => '0', 'csrf' => $ac])['ok']
    && WNBStorage::read('jobs')[0]['destination_id'] === 'dest_small' && WNBEngine::findBackup('bkp_nhakhoa_1')['destination_id'] === 'dest_other', 'không chuyển lịch khi bỏ chọn');

// Chuyển lịch: tác vụ sao lưu đang chờ của kho cũ cũng đổi sang kho mới.
WNBStorage::write('queue', [$T('t_wait', 'backup', 'pending', ['destination_id' => 'dest_x']), $T('t_wait_restore', 'restore', 'pending', ['destination_id' => 'dest_x'])]);
WNBEngine::retargetDestination('dest_x', 'dest_y');
ok(array_column(WNBStorage::read('queue'), 'destination_id') === ['dest_y', 'dest_x'], 'sao lưu đang chờ đổi kho, khôi phục giữ nguyên');

foreach ($saved as $k => $v) {
    WNBStorage::write($k, $v);
}
