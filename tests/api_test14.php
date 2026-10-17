<?php
// Test 2.6.2: tải về các mục đã chọn (file, database, email, DNS) thay vì cả bản sao lưu.
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');
$saved = ['backups' => WNBStorage::read('backups'), 'queue' => WNBStorage::read('queue'), 'downloads' => WNBStorage::read('downloads')];

$B = ['id' => 'bkp_dl_1', 'username' => 'shopgiay', 'owner_key' => 'demo:shopgiay', 'type' => 'backup', 'destination_id' => 'dest_local', 'destination_name' => 'Local',
    'destination_type' => 'local', 'format' => 'folder', 'job_id' => '', 'created_at' => '2026-10-17 02:00:00', 'warnings' => [],
    'domains' => ['shopgiay.vn'], 'databases' => ['shopgiay_wp', 'shopgiay_shop'], 'emails' => ['info@shopgiay.vn', 'sale@shopgiay.vn'], 'crons' => [],
    'size_mb' => 161, 'storage_path' => '/backup/webnowbackup/shopgiay/bkp_dl_1'];
WNBStorage::write('backups', [$B]);
WNBStorage::write('queue', []);
WNBStorage::write('downloads', []);

// ---------- Lọc mục ----------
$sel = WNBEngine::downloadSelection($B, 'files', ['domains/shopgiay.vn/public_html/index.php', '../etc/passwd', '', 'domains/shopgiay.vn/public_html/index.php']);
ok($sel['home'] === ['domains/shopgiay.vn/public_html/index.php'], 'file: bỏ đường dẫn lạ, trùng: ' . json_encode($sel['home']));
$sel = WNBEngine::downloadSelection($B, 'databases', ['shopgiay_wp', 'khac_db', 'shopgiay_wp; rm']);
ok($sel['databases'] === ['shopgiay_wp'], 'database: chỉ db có trong bản sao lưu');
$sel = WNBEngine::downloadSelection($B, 'emails', ['sale@shopgiay.vn', 'x@khac.vn']);
ok($sel['emails'] === ['sale@shopgiay.vn'], 'email: chỉ hộp thư có trong bản sao lưu');
$sel = WNBEngine::downloadSelection($B, 'dns', ['shopgiay.vn']);
ok($sel['dns'] === ['shopgiay.vn'] && !$sel['home'] && !$sel['databases'], 'DNS: chỉ zone đã chọn');
foreach ([['databases', ['khac_db']], ['files', ['..']], ['cron', []], ['full', []]] as [$sc, $it]) {
    $err = '';
    try { WNBEngine::downloadSelection($B, $sc, $it); } catch (WNBError $e) { $err = $e->getMessage(); }
    ok($err !== '', 'từ chối ' . $sc . ' ' . json_encode($it));
}

// ---------- API ----------
$r = call('admin', 'admin', ['action' => 'download_create', 'backup_id' => 'bkp_dl_1', 'scope' => 'databases', 'items' => ['khac_db'], 'csrf' => $ac]);
ok(!$r['ok'] && !WNBStorage::read('queue'), 'mục không hợp lệ: báo lỗi, không tạo tác vụ');

$r = call('admin', 'admin', ['action' => 'download_create', 'backup_id' => 'bkp_dl_1', 'scope' => 'files', 'items' => ['domains/shopgiay.vn/public_html', 'backups/x.tar.gz'], 'csrf' => $ac]);
ok($r['ok'], 'tải file đã chọn');
$t = WNBStorage::read('queue')[0];
ok($t['type'] === 'download' && $t['scope'] === 'files' && $t['items'] === ['domains/shopgiay.vn/public_html', 'backups/x.tar.gz'] && $t['status'] === 'completed', 'tác vụ mang phạm vi + mục: ' . json_encode([$t['scope'], $t['status'], $t['message']]));
$dl = $r['data']['state']['downloads'][0];
ok($dl['filename'] === 'bkp_dl_1-files.tar.gz' && $dl['scope'] === 'files' && $dl['items'] === 2 && !isset($dl['path']), 'file tải về ghi phạm vi: ' . json_encode($dl));
$raw = json_decode(gzdecode((string)file_get_contents(WNBApi::downloadFile('admin', 'admin', $dl['id'])['path'])), true);
ok(($raw['selection']['home'] ?? null) === ['domains/shopgiay.vn/public_html', 'backups/x.tar.gz'], 'preview: gói đúng các mục đã chọn');

WNBStorage::write('queue', []);
$r = call('admin', 'admin', ['action' => 'download_create', 'backup_id' => 'bkp_dl_1', 'csrf' => $ac]);
$t = WNBStorage::read('queue')[0];
ok($r['ok'] && $t['scope'] === '' && $t['items'] === [], 'không có phạm vi: tải toàn bộ như cũ');
$dl = $r['data']['state']['downloads'][0];
ok($dl['filename'] === 'bkp_dl_1.tar.gz' && $dl['scope'] === 'full', 'file tải toàn bộ giữ tên cũ');
WNBStorage::write('queue', []);
$r = call('admin', 'admin', ['action' => 'download_create', 'backup_id' => 'bkp_dl_1', 'scope' => 'full', 'items' => ['x'], 'csrf' => $ac]);
ok($r['ok'] && WNBStorage::read('queue')[0]['scope'] === '', 'scope=full cũng là tải toàn bộ');

// User tắt quyền tải thì không tải từng phần được.
WNBStorage::write('queue', []);
$r = call('shopgiay', 'user', ['action' => 'download_create', 'backup_id' => 'bkp_dl_1', 'scope' => 'emails', 'items' => ['info@shopgiay.vn'], 'csrf' => tok('shopgiay', 'user')]);
ok($r['ok'] === (bool)WNBApi::perms('shopgiay', 'user')['can_download'], 'user tải từng phần theo quyền tải về');

foreach ($saved as $k => $v) {
    WNBStorage::write($k, $v);
}
