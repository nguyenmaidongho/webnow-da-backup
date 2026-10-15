#!/usr/local/bin/php -n
<?php
/**
 * WebNowBackup - CLI phía root.
 *   api        (qua bin/wnb_exec) nhận {role, params} từ stdin, danh tính lấy từ WNB_CALLER_USER do helper đặt
 *   download   (qua bin/wnb_exec) stream file tải về: dòng đầu là JSON meta, sau đó là nội dung file
 *   worker     xử lý hàng đợi
 *   cron       chạy mỗi phút từ /etc/cron.d/webnowbackup: lịch tự động, dọn dẹp, khởi động worker
 *   run-task   chạy một tác vụ cụ thể (gỡ lỗi)
 *   status     in trạng thái nhanh
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$cmd = $argv[1] ?? '';
if (!WNB::isPreview() && !WNB::isRoot()) {
    fwrite(STDERR, "WebNowBackup CLI cần quyền root.\n");
    exit(1);
}
umask(0077);

function wnb_read_request(): array
{
    $raw = stream_get_contents(STDIN, 1048576);
    $req = json_decode((string)$raw, true);
    return is_array($req) ? $req : [];
}

switch ($cmd) {
    case 'api':
        $req = wnb_read_request();
        $user = (string)getenv('WNB_CALLER_USER');
        $res = WNBApi::handle($user, (string)($req['role'] ?? ''), is_array($req['params'] ?? null) ? $req['params'] : []);
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    case 'download':
        $req = wnb_read_request();
        try {
            $d = WNBApi::downloadFile((string)getenv('WNB_CALLER_USER'), (string)($req['role'] ?? ''), (string)($req['id'] ?? ''));
            echo json_encode(['ok' => true, 'filename' => $d['filename'], 'size' => filesize($d['path'])]) . "\n";
            $fp = fopen($d['path'], 'rb');
            while ($fp && !feof($fp)) {
                echo fread($fp, 1048576);
            }
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
        }
        break;

    case 'worker':
        WNBEngine::worker();
        break;

    case 'cron':
        $lock = WNBStorage::lock('cron', false);
        if (!$lock) {
            break;
        }
        WNBEngine::recoverStale();
        WNBEngine::runDueJobs();
        WNBEngine::cleanup();
        try {
            WNBEngine::scheduleMaintenance();
            WNBEngine::healthCheck();
        } catch (Throwable $e) {
            // Giám sát không được làm hỏng lượt cron.
        }
        WNBStorage::unlock($lock);
        foreach (WNBStorage::read('queue') as $t) {
            if (WNBEngine::ready($t)) {
                WNBEngine::kick();
                break;
            }
        }
        break;

    case 'run-task':
        foreach (WNBStorage::read('queue') as $t) {
            if ($t['id'] === ($argv[2] ?? '')) {
                WNBEngine::execute($t);
                exit(0);
            }
        }
        fwrite(STDERR, "Không tìm thấy tác vụ.\n");
        exit(1);

    case 'status':
        $q = WNBStorage::read('queue');
        $count = fn($s) => count(array_filter($q, fn($t) => $t['status'] === $s));
        printf("WebNowBackup %s\nBản sao lưu: %d\nĐang chờ: %d | Đang chạy: %d | Lỗi: %d\nRclone: %s\nHelper: %s\n",
            WNB_VERSION, count(WNBStorage::read('backups')), $count('pending'), $count('running'), $count('failed'),
            WNBRclone::version() ?: 'chưa cài', WNB::helperReady() ? 'sẵn sàng' : 'chưa cài');
        break;

    default:
        echo "Dùng: webnowbackup-cli.php {cron|worker|run-task <id>|status}\n";
        exit(1);
}
