<?php
// Test: nút "Kết nối" Google Drive / OneDrive (preview giả lập rclone authorize).
// Chạy SAU api_test.php trong cùng tiến trình (dùng lại ok(), call(), tok()).

$ac = tok('admin', 'admin');

// Đọc địa chỉ dán vào
$q = WNBOAuth::parseRedirect('  http://127.0.0.1:53682/?state=abc&code=4/0AXyz&scope=https://www.googleapis.com/auth/drive&x[]=1&Bad-Key=2 ');
ok($q['state'] === 'abc' && $q['params']['code'] === '4/0AXyz' && isset($q['params']['scope']), 'đọc code/state/scope từ URL');
ok(!isset($q['params']['x']) && !isset($q['params']['Bad-Key']), 'bỏ tham số lạ');
ok(WNBOAuth::parseRedirect('?code=c1&state=s1')['state'] === 's1', 'dán riêng phần query');
foreach (['', 'http://127.0.0.1:53682/', 'http://127.0.0.1:53682/?state=abc', 'xin chao'] as $bad) {
    $okp = true; try { WNBOAuth::parseRedirect($bad); } catch (Throwable $e) { $okp = false; }
    ok(!$okp, 'địa chỉ thiếu code bị từ chối: ' . $bad);
}
$msg = ''; try { WNBOAuth::parseRedirect('http://localhost:53682/?error=access_denied&state=abc'); } catch (Throwable $e) { $msg = $e->getMessage(); }
ok(strpos($msg, 'access_denied') !== false, 'từ chối cấp quyền báo rõ: ' . $msg);

// Token trong output của rclone authorize
$tok = '{"access_token":"ya29.a","token_type":"Bearer","refresh_token":"1//r","expiry":"2026-10-15T09:00:00Z"}';
$out = "2026/10/15 NOTICE: Got code\nPaste the following into your remote machine --->\n" . $tok . "\n<---End paste\n";
ok(json_decode(WNBOAuth::extractToken($out), true)['refresh_token'] === '1//r', 'đọc token JSON');
$b64 = rtrim(strtr(base64_encode(json_encode(['token' => $tok])), '+/', '-_'), '=');
ok(json_decode(WNBOAuth::extractToken("--->\n" . $b64 . "\n<---End paste"), true)['access_token'] === 'ya29.a', 'đọc token dạng base64');
ok(WNBOAuth::extractToken("--->\nkhong phai token\n<---End paste") === '' && WNBOAuth::extractToken('NOTICE: Waiting for code...') === '', 'chưa có token thì trả rỗng');

// Quyền
ok(!call('reseller_vn', 'reseller', ['action' => 'dest_oauth_start', 'kind' => 'drive', 'csrf' => tok('reseller_vn', 'reseller')])['ok'], 'reseller không kết nối được');
ok(!call('admin', 'admin', ['action' => 'dest_oauth_start', 'kind' => 'drive'])['ok'], 'thiếu csrf bị chặn');
ok(!call('admin', 'admin', ['action' => 'dest_oauth_start', 'kind' => 's3', 'csrf' => $ac])['ok'], 'loại kho không phải OAuth bị từ chối');

// Google Drive: bấm Kết nối -> dán địa chỉ -> lưu
$r = call('admin', 'admin', ['action' => 'dest_oauth_start', 'kind' => 'drive', 'csrf' => $ac]);
ok($r['ok'] && parse_url($r['data']['url'], PHP_URL_HOST) === 'accounts.google.com', 'mở trang đăng nhập Google: ' . ($r['error'] ?? ''));
parse_str((string)parse_url($r['data']['url'], PHP_URL_QUERY), $aq);
$state = $aq['state'];
$r = call('admin', 'admin', ['action' => 'dest_oauth_finish', 'kind' => 'drive', 'url' => 'http://127.0.0.1:53682/?state=khac&code=x', 'csrf' => $ac]);
ok(!$r['ok'] && strpos($r['error'], 'không thuộc') !== false, 'state khác bị từ chối');
$r = call('admin', 'admin', ['action' => 'dest_oauth_finish', 'kind' => 'onedrive', 'url' => 'http://127.0.0.1:53682/?state=' . $state . '&code=x', 'csrf' => $ac]);
ok(!$r['ok'], 'hoàn tất sai loại kho bị từ chối');
$r = call('admin', 'admin', ['action' => 'dest_oauth_finish', 'kind' => 'drive', 'url' => 'http://127.0.0.1:53682/?state=' . $state . '&code=4/abc&scope=drive', 'csrf' => $ac]);
ok($r['ok'] && strlen($r['data']['ticket']) === 24 && $r['data']['account'] === 'demo@gmail.com', 'nhận vé kết nối: ' . ($r['error'] ?? ''));
ok(!isset($r['data']['token']) && strpos(json_encode($r), 'access_token') === false, 'token không gửi về trình duyệt');
$ticket = $r['data']['ticket'];
$r = call('admin', 'admin', ['action' => 'dest_oauth_finish', 'kind' => 'drive', 'url' => 'http://127.0.0.1:53682/?state=' . $state . '&code=4/abc', 'csrf' => $ac]);
ok(!$r['ok'], 'không dùng lại được địa chỉ đã xác nhận');

$r = call('admin', 'admin', ['action' => 'dest_save', 'type' => 'drive', 'name' => 'Google Drive công ty', 'subpath' => 'WebNowBackup/control3', 'format' => 'archive', 'oauth_ticket' => $ticket, 'csrf' => $ac]);
ok($r['ok'], 'lưu kho Drive bằng vé: ' . ($r['error'] ?? ''));
$gd = null;
foreach ($r['data']['state']['destinations'] as $d) {
    if ($d['type'] === 'drive') {
        $gd = $d;
    }
}
ok($gd && !empty($gd['has_token']) && $gd['params']['token'] === '' && $gd['account'] === 'demo@gmail.com', 'kho Drive đã kết nối, giấu token, ghi tên tài khoản');
$remote = WNBRclone::readRemote($gd['remote']);
ok(($remote['type'] ?? '') === 'drive' && strpos($remote['token'] ?? '', '"refresh_token"') !== false, 'token ghi vào rclone.conf');
$r = call('admin', 'admin', ['action' => 'dest_save', 'id' => $gd['id'], 'name' => 'Google Drive công ty', 'subpath' => 'WebNowBackup/control3', 'format' => 'archive', 'oauth_ticket' => $ticket, 'csrf' => $ac]);
ok(!$r['ok'] && strpos($r['error'], 'hết hạn') !== false, 'vé chỉ dùng một lần');
$r = call('admin', 'admin', ['action' => 'dest_save', 'id' => $gd['id'], 'name' => 'Drive (đổi tên)', 'subpath' => 'WebNowBackup/control3', 'format' => 'archive', 'csrf' => $ac]);
ok($r['ok'] && WNBRclone::readRemote($gd['remote'])['token'] === $remote['token'], 'sửa kho không kết nối lại thì giữ token');

// OneDrive: drive_id/drive_type tự điền
$r = call('admin', 'admin', ['action' => 'dest_oauth_start', 'kind' => 'onedrive', 'csrf' => $ac]);
ok($r['ok'] && parse_url($r['data']['url'], PHP_URL_HOST) === 'login.microsoftonline.com', 'mở trang đăng nhập Microsoft');
parse_str((string)parse_url($r['data']['url'], PHP_URL_QUERY), $aq);
$r = call('admin', 'admin', ['action' => 'dest_oauth_finish', 'kind' => 'onedrive', 'url' => 'http://localhost:53682/?code=M.C1&state=' . $aq['state'], 'csrf' => $ac]);
ok($r['ok'], 'nhận vé OneDrive: ' . ($r['error'] ?? ''));
$r = call('admin', 'admin', ['action' => 'dest_save', 'type' => 'onedrive', 'name' => 'OneDrive', 'subpath' => 'Backup/control3', 'format' => 'restic', 'oauth_ticket' => $r['data']['ticket'], 'csrf' => $ac]);
ok($r['ok'], 'lưu kho OneDrive: ' . ($r['error'] ?? ''));
foreach ($r['data']['state']['destinations'] as $d) {
    if ($d['type'] === 'onedrive') {
        ok(strpos($d['params']['drive_id'], 'b!demo') === 0 && $d['params']['drive_type'] === 'personal', 'drive_id/drive_type tự điền');
    }
}

// Thư mục đích: khoảng trắng / ký tự ẩn / dấu / ở hai đầu (hay dính khi copy/paste) được bỏ
foreach (['control3.webnow.vn/webnowbackup', '/control3.webnow.vn/webnowbackup/', " control3.webnow.vn/webnowbackup \t", "\u{FEFF}control3.webnow.vn//webnowbackup\u{200B}"] as $s) {
    $r = call('admin', 'admin', ['action' => 'dest_save', 'type' => 'drive', 'name' => 'GD thử', 'subpath' => $s, 'format' => 'archive', 'p_token' => '{"access_token":"x"}', 'csrf' => $ac]);
    $saved = $r['ok'] ? array_values(array_filter($r['data']['state']['destinations'], fn($d) => $d['name'] === 'GD thử'))[0] : null;
    ok($saved && $saved['subpath'] === 'control3.webnow.vn/webnowbackup', 'thư mục đích chuẩn hóa: ' . json_encode($s) . ' ' . ($r['error'] ?? ''));
    if ($saved) {
        call('admin', 'admin', ['action' => 'dest_delete', 'id' => $saved['id'], 'csrf' => $ac]);
    }
}
$r = call('admin', 'admin', ['action' => 'dest_save', 'type' => 'drive', 'name' => 'GD thử', 'subpath' => 'Sao lưu/control3', 'format' => 'archive', 'p_token' => '{"access_token":"x"}', 'csrf' => $ac]);
ok(!$r['ok'] && strpos($r['error'], '"Sao lưu/control3"') !== false, 'báo rõ thư mục có dấu/khoảng trắng: ' . ($r['error'] ?? ''));
// Để trống: kho kiểu thư mục dùng WebNowBackup/<tên đầy đủ của máy chủ>, kho bucket thì bắt nhập
$def = WNBRclone::defaultSubpath();
ok(preg_match('#^WebNowBackup/[a-z0-9][a-z0-9.\-]*$#', $def) === 1, 'thư mục mặc định hợp lệ: ' . $def);
foreach (['drive', 'onedrive', 'sftp'] as $ty) {
    $r = call('admin', 'admin', ['action' => 'dest_save', 'type' => $ty, 'name' => 'Mặc định ' . $ty, 'subpath' => ' / ', 'format' => 'folder', 'p_token' => '{"access_token":"x"}', 'p_host' => 'sftp.example.com', 'p_user' => 'u', 'csrf' => $ac]);
    $saved = $r['ok'] ? array_values(array_filter($r['data']['state']['destinations'], fn($d) => $d['name'] === 'Mặc định ' . $ty))[0] : null;
    ok($saved && $saved['subpath'] === $def, $ty . ' để trống dùng thư mục mặc định: ' . ($r['error'] ?? ''));
    if ($saved) {
        call('admin', 'admin', ['action' => 'dest_delete', 'id' => $saved['id'], 'csrf' => $ac]);
    }
}
ok(call('admin', 'admin', ['action' => 'state'])['data']['default_subpath'] === $def, 'giao diện nhận thư mục mặc định');
$r = call('admin', 'admin', ['action' => 'dest_save', 'type' => 's3', 'name' => 'S3 trống', 'subpath' => '', 'format' => 'archive', 'csrf' => $ac]);
ok(!$r['ok'] && strpos($r['error'], 'my-bucket/server1') !== false, 'kho bucket để trống thì bắt nhập');

// Bấm Kết nối lại thì vé cũ chưa dùng bị hủy
$r1 = call('admin', 'admin', ['action' => 'dest_oauth_start', 'kind' => 'drive', 'csrf' => $ac]);
parse_str((string)parse_url($r1['data']['url'], PHP_URL_QUERY), $aq);
$t1 = call('admin', 'admin', ['action' => 'dest_oauth_finish', 'kind' => 'drive', 'url' => '?state=' . $aq['state'] . '&code=z', 'csrf' => $ac])['data']['ticket'];
call('admin', 'admin', ['action' => 'dest_oauth_start', 'kind' => 'drive', 'csrf' => $ac]);
$okc = true; try { WNBOAuth::consume($t1, 'drive'); } catch (Throwable $e) { $okc = false; }
ok(!$okc, 'bấm Kết nối lại hủy vé cũ');
WNBOAuth::stop(true);
