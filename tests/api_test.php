<?php
putenv('WNB_PREVIEW=1');
require 'C:/laragon/www/vaultbackup/includes/bootstrap.php';
$pass = 0; $fail = 0;
function ok($cond, $msg) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $msg\n"; } }
function call($u, $r, $p) { return WNBApi::handle($u, $r, $p); }
function tok($u, $r) { return WNBApi::csrfToken($u, $r); }

// danh tính
ok(!call('shopgiay', 'admin', ['action'=>'state'])['ok'], 'user không được dùng cấp admin');
ok(!call('shopgiay', 'reseller', ['action'=>'state'])['ok'], 'user không được dùng cấp reseller');
ok(call('admin', 'reseller', ['action'=>'state'])['ok'], 'admin dùng được cấp reseller');
ok(!call('ghost', 'user', ['action'=>'state'])['ok'], 'user không tồn tại bị chặn');

// csrf
$r = call('admin', 'admin', ['action'=>'backup_now', 'username'=>'shopgiay']);
ok(!$r['ok'] && strpos($r['error'], 'Phiên') !== false, 'thiếu csrf bị chặn');
$r = call('admin', 'admin', ['action'=>'backup_now', 'username'=>'shopgiay', 'csrf'=>tok('shopgiay','user')]);
ok(!$r['ok'], 'csrf của user khác bị chặn');

// admin sao lưu
$r = call('admin', 'admin', ['action'=>'backup_now', 'username'=>'shopgiay', 'destination_id'=>'dest_local', 'csrf'=>tok('admin','admin')]);
ok($r['ok'], 'admin backup_now: ' . ($r['error'] ?? ''));
$st = call('admin','admin',['action'=>'state'])['data'];
$b = null; foreach ($st['backups'] as $x) if ($x['username']==='shopgiay') { $b = $x; break; }
ok($b !== null, 'có bản sao lưu shopgiay');
ok(!isset($b['storage_path']), 'không lộ storage_path');
$t = $st['queue'][0];
ok($t['status']==='completed', 'task hoàn tất: ' . $t['status'] . ' ' . $t['message']);

// user chỉ thấy của mình
$us = call('shopgiay','user',['action'=>'state'])['data'];
ok(count($us['accounts'])===1 && $us['accounts'][0]['username']==='shopgiay', 'user chỉ thấy chính mình');
foreach ($us['backups'] as $x) ok($x['username']==='shopgiay', 'user chỉ thấy backup của mình');
ok($us['jobs']===[] && !isset($us['settings']['local_backup_root']), 'user không thấy jobs/settings');
ok(!isset($us['destinations'][0]['path']), 'user không thấy đường dẫn kho');

// user khác
$r = call('nhakhoa','user',['action'=>'browse','backup_id'=>$b['id'],'path'=>'']);
ok(!$r['ok'], 'user khác không browse được');
$r = call('nhakhoa','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'databases','items'=>['shopgiay_wp'],'csrf'=>tok('nhakhoa','user')]);
ok(!$r['ok'], 'user khác không restore được');
$r = call('nhakhoa','user',['action'=>'task_log','task_id'=>$t['id']]);
ok(!$r['ok'], 'user khác không đọc được log');

// duyệt file
$r = call('shopgiay','user',['action'=>'browse','backup_id'=>$b['id'],'path'=>'']);
ok($r['ok'] && count($r['data']['items'])>0, 'browse root: ' . ($r['error'] ?? ''));
$r = call('shopgiay','user',['action'=>'browse','backup_id'=>$b['id'],'path'=>'../../']);
ok(!$r['ok'], 'chặn path traversal');
$r = call('shopgiay','user',['action'=>'browse','backup_id'=>$b['id'],'path'=>'domains/shopgiaydep.vn/public_html']);
ok($r['ok'] && in_array('wp-config.php', array_column($r['data']['items'],'name'), true), 'browse sâu');

// kiểm tra đầu vào khi khôi phục
$c = tok('shopgiay','user');
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'full','csrf'=>$c]);
ok(!$r['ok'], 'full restore cần xác nhận');
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'full','confirm'=>'shopgiay','csrf'=>$c]);
ok($r['ok'], 'full restore với xác nhận: ' . ($r['error'] ?? ''));
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'databases','items'=>json_encode(['admin_panel']),'csrf'=>$c]);
ok(!$r['ok'], 'không restore DB không thuộc user');
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'files','items'=>json_encode(['../etc/passwd']),'csrf'=>$c]);
ok(!$r['ok'], 'chặn file ../');
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'dns','items'=>json_encode(['agencycloud.vn']),'csrf'=>$c]);
ok(!$r['ok'], 'không restore DNS domain của người khác');
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'emails','items'=>json_encode(['info@shopgiaydep.vn']),'csrf'=>$c]);
ok($r['ok'], 'restore email hợp lệ: ' . ($r['error'] ?? ''));
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'files','items'=>json_encode(['domains/shopgiaydep.vn/public_html/wp-config.php']),'csrf'=>$c]);
ok($r['ok'], 'restore file hợp lệ: ' . ($r['error'] ?? ''));
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'evil','csrf'=>$c]);
ok(!$r['ok'], 'scope lạ bị chặn');

// xóa
$r = call('shopgiay','user',['action'=>'backup_delete','backup_id'=>$b['id'],'csrf'=>$c]);
ok(!$r['ok'], 'user không xóa được backup thường');
$r = call('shopgiay','user',['action'=>'snapshot','note'=>'Trước khi update <script>alert(1)</script>','csrf'=>$c]);
ok($r['ok'], 'user tạo snapshot: ' . ($r['error'] ?? ''));
$us = call('shopgiay','user',['action'=>'state'])['data'];
$snap = null; foreach ($us['backups'] as $x) if ($x['type']==='snapshot') { $snap = $x; break; }
ok($snap !== null, 'có snapshot');
$r = call('shopgiay','user',['action'=>'backup_delete','backup_id'=>$snap['id'],'csrf'=>$c]);
ok($r['ok'], 'user xóa snapshot của mình');

// hành động admin
foreach (['dest_save','job_save','settings_save','job_run','dest_delete','job_toggle'] as $a) {
    $r = call('shopgiay','user',['action'=>$a,'csrf'=>$c]);
    ok(!$r['ok'], 'user không gọi được ' . $a);
}
$r = call('shopgiay','user',['action'=>'backup_now','username'=>'shopgiay','csrf'=>$c]);
ok(!$r['ok'], 'user không backup_now');
$r = call('shopgiay','user',['action'=>'snapshot','username'=>'nhakhoa','csrf'=>$c]);
ok($r['ok'] && $r['data']['state']['queue'][0]['username']==='shopgiay', 'snapshot của user luôn cho chính mình');

// reseller
$rc = tok('reseller_vn','reseller');
$rs = call('reseller_vn','reseller',['action'=>'state'])['data'];
$names = array_column($rs['accounts'],'username'); sort($names);
ok($names === ['reseller_vn','shopgiay'], 'reseller thấy đúng user của mình: ' . implode(',', $names));
$r = call('reseller_vn','reseller',['action'=>'backup_now','username'=>'nhakhoa','csrf'=>$rc]);
ok(!$r['ok'], 'reseller không backup user ngoài quyền');
$r = call('reseller_vn','reseller',['action'=>'acl_save','target'=>'admin','allow_restore'=>'1','allow_user_restore'=>'0','csrf'=>$rc]);
ok($r['ok'], 'reseller lưu policy');
$acl = WNBStorage::read('acl');
ok(!isset($acl['admin']) && isset($acl['reseller_vn']) && $acl['reseller_vn']['allow_user_restore']===false, 'reseller chỉ sửa được ACL của mình');
ok($acl['reseller_vn']['allow_restore']===true, 'reseller không tự cấp quyền allow_restore');
$r = call('shopgiay','user',['action'=>'restore','backup_id'=>$b['id'],'scope'=>'cron','csrf'=>$c]);
ok(!$r['ok'], 'user bị khóa restore khi reseller tắt');
call('reseller_vn','reseller',['action'=>'acl_save','allow_user_restore'=>'1','allow_user_snapshots'=>'1','allow_user_downloads'=>'1','csrf'=>$rc]);
call('admin','admin',['action'=>'acl_save','target'=>'reseller_vn','allow_backup'=>'0','allow_restore'=>'1','quota_gb'=>'0','csrf'=>tok('admin','admin')]);
$r = call('reseller_vn','reseller',['action'=>'backup_now','username'=>'shopgiay','csrf'=>$rc]);
ok(!$r['ok'], 'reseller bị tắt backup');
call('admin','admin',['action'=>'acl_save','target'=>'reseller_vn','allow_backup'=>'1','allow_restore'=>'1','quota_gb'=>'0.001','csrf'=>tok('admin','admin')]);
$r = call('reseller_vn','reseller',['action'=>'backup_now','username'=>'shopgiay','csrf'=>$rc]);
ok(!$r['ok'] && strpos($r['error'],'hạn mức')!==false, 'quota reseller: ' . ($r['error'] ?? 'ok'));
call('admin','admin',['action'=>'acl_save','target'=>'reseller_vn','allow_backup'=>'1','allow_restore'=>'1','quota_gb'=>'0','csrf'=>tok('admin','admin')]);
$r = call('admin','admin',['action'=>'acl_save','target'=>'shopgiay','allow_backup'=>'1','csrf'=>tok('admin','admin')]);
ok(!$r['ok'], 'không lưu ACL cho user thường');

// tải về
$r = call('shopgiay','user',['action'=>'download_create','backup_id'=>$b['id'],'csrf'=>$c]);
ok($r['ok'] && count($r['data']['state']['downloads'])>=1, 'tạo file tải về');
$dl = $r['data']['state']['downloads'][0];
ok(!isset($dl['path']), 'không lộ path download');
try { WNBApi::downloadFile('nhakhoa','user',$dl['id']); ok(false,'user khác tải được file'); } catch (Throwable $e) { ok(true,''); }
$d = WNBApi::downloadFile('shopgiay','user',$dl['id']); ok(is_file($d['path']), 'chủ sở hữu tải được');

// lịch & kho (admin)
$ac = tok('admin','admin');
$r = call('admin','admin',['action'=>'dest_save','name'=>'Ổ phụ','type'=>'local','path'=>'/home/shopgiay/bk','csrf'=>$ac]);
ok(!$r['ok'], 'chặn kho trong home của khách');
$r = call('admin','admin',['action'=>'dest_save','name'=>'Ổ phụ','type'=>'local','path'=>'/etc/x','csrf'=>$ac]);
ok(!$r['ok'], 'chặn kho trong /etc');
$r = call('admin','admin',['action'=>'dest_save','name'=>'Ổ phụ','type'=>'local','path'=>'/home','csrf'=>$ac]);
ok(!$r['ok'], 'chặn kho /home');
$r = call('admin','admin',['action'=>'job_save','name'=>'Đêm','destination_id'=>'dest_local','schedule_type'=>'weekly','schedule_time'=>'25:00','csrf'=>$ac]);
ok(!$r['ok'], 'chặn giờ sai');
$r = call('admin','admin',['action'=>'job_save','name'=>'Hằng tuần','destination_id'=>'dest_local','schedule_type'=>'weekly','schedule_time'=>'03:30','schedule_weekday'=>'0','retention'=>'4','exclude_users'=>json_encode(['nhakhoa']),'enabled'=>'1','csrf'=>$ac]);
ok($r['ok'], 'lưu lịch tuần');
$job = end($r['data']['state']['jobs']);
ok($job['next_run'] !== null && date('w', strtotime($job['next_run']))==='0' && substr($job['next_run'],11,5)==='03:30', 'next_run tuần đúng: '.$job['next_run']);
$r = call('admin','admin',['action'=>'job_run','id'=>$job['id'],'csrf'=>$ac]);
ok($r['ok'] && strpos($r['data']['message'],'3 tài khoản')!==false, 'job_run trừ user loại trừ: ' . ($r['data']['message'] ?? $r['error']));
$r = call('admin','admin',['action'=>'dest_delete','id'=>'dest_local','csrf'=>$ac]);
ok(!$r['ok'], 'không xóa kho mặc định');
$r = call('admin','admin',['action'=>'settings_save','local_backup_root'=>'/backup/webnowbackup','max_concurrent_tasks'=>'3','nice_level'=>'19','default_retention'=>'2','bwlimit'=>'abc','csrf'=>$ac]);
ok(!$r['ok'], 'chặn bwlimit sai');

// retention: giữ 2 bản thủ công
call('admin','admin',['action'=>'settings_save','local_backup_root'=>'/backup/webnowbackup','max_concurrent_tasks'=>'2','nice_level'=>'19','default_retention'=>'2','max_snapshots_per_user'=>'3','download_ttl_hours'=>'24','csrf'=>$ac]);
for ($i=0;$i<3;$i++) { call('admin','admin',['action'=>'backup_now','username'=>'nhakhoa','destination_id'=>'dest_local','csrf'=>$ac]); }
$n = 0; foreach (WNBStorage::read('backups') as $x) if ($x['username']==='nhakhoa' && $x['type']==='backup' && $x['job_id']==='') $n++;
ok($n === 2, "retention giữ 2 bản: $n");

// tính lịch
$nr = WNBEngine::nextRun(['enabled'=>true,'schedule_type'=>'daily','schedule_time'=>'02:00','last_run'=>'2026-10-14 02:01:00','created_at'=>'2026-10-01 00:00:00']);
ok(date('Y-m-d H:i', $nr) === '2026-10-15 02:00', 'daily next: ' . date('Y-m-d H:i',$nr));
$nr = WNBEngine::nextRun(['enabled'=>true,'schedule_type'=>'daily','schedule_time'=>'23:00','last_run'=>null,'created_at'=>'2026-10-14 10:00:00']);
ok(date('Y-m-d H:i', $nr) === '2026-10-14 23:00', 'daily lần đầu cùng ngày');
$nr = WNBEngine::nextRun(['enabled'=>true,'schedule_type'=>'hourly','interval_hours'=>6,'schedule_time'=>'02:00','last_run'=>'2026-10-14 02:00:00']);
ok(date('Y-m-d H:i', $nr) === '2026-10-14 08:00', 'hourly next');
ok(WNBEngine::nextRun(['enabled'=>false,'schedule_type'=>'daily','schedule_time'=>'02:00','last_run'=>null]) === null, 'lịch tắt không có next');
ok(WNBEngine::cleanRelPath('a/../b') === null && WNBEngine::cleanRelPath('/a//b/./c') === 'a/b/c', 'cleanRelPath');


// chủ sở hữu cũ trùng tên
WNBStorage::update('backups', function ($l) { foreach ($l as &$x) { if ($x['username']==='shopgiay') { $x['owner_key']='old:1'; break; } } return $l; });
$us = call('shopgiay','user',['action'=>'state'])['data'];
$oldId = null; foreach (WNBStorage::read('backups') as $x) if (($x['owner_key'] ?? '')==='old:1') $oldId=$x['id'];
ok(!in_array($oldId, array_column($us['backups'],'id'), true), 'user mới không thấy backup của chủ cũ');
ok(!call('shopgiay','user',['action'=>'browse','backup_id'=>$oldId,'path'=>''])['ok'], 'user mới không browse backup chủ cũ');
ok(call('admin','admin',['action'=>'browse','backup_id'=>$oldId,'path'=>''])['ok'], 'admin vẫn thấy backup chủ cũ');
ok(!isset($us['backups'][0]['owner_key']), 'không lộ owner_key');
// backup đang bận không xóa được
$any = WNBStorage::read('backups')[0];
WNBStorage::update('queue', function ($q) use ($any) { array_unshift($q, ['id'=>'task_busy','type'=>'restore','username'=>$any['username'],'backup_id'=>$any['id'],'status'=>'running','created_at'=>WNB::now()]); return $q; });
$r = call('admin','admin',['action'=>'backup_delete','backup_id'=>$any['id'],'csrf'=>tok('admin','admin')]);
ok(!$r['ok'], 'không xóa backup đang khôi phục');
// đường dẫn
foreach (['/home/./shopgiay','//home/shopgiay','/home2','/backup/../etc','/opt/x','relative/path'] as $bad) {
    $ok = true; try { WNBApi::validateStoragePath($bad); } catch (Throwable $e) { $ok = false; }
    ok(!$ok, 'chặn đường dẫn ' . $bad);
}
ok(WNBApi::validateStoragePath('/backup/webnowbackup/') === '/backup/webnowbackup', 'đường dẫn hợp lệ');
$r = call('admin','admin',['action'=>'settings_save','local_backup_root'=>'/home/shopgiay/x','csrf'=>tok('admin','admin')]);
ok(!$r['ok'], 'settings chặn home khách');

require __DIR__ . "/api_test2.php";
require __DIR__ . "/api_test3.php";
require __DIR__ . "/api_test4.php";
require __DIR__ . "/api_test5.php";
require __DIR__ . "/api_test6.php";
require __DIR__ . "/api_test7.php";
require __DIR__ . "/api_test8.php";
require __DIR__ . "/api_test9.php";
require __DIR__ . "/api_test10.php";
require __DIR__ . "/api_test11.php";
require __DIR__ . "/api_test12.php";
echo "PASS: $pass  FAIL: $fail\n";
