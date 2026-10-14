<?php
/**
 * WebNowBackup - bootstrap, môi trường chạy và tiện ích shell.
 *
 * Mô hình quyền:
 *  - Script giao diện (admin/reseller/user) được DirectAdmin chạy bằng chính UNIX user đang đăng nhập,
 *    nên KHÔNG đọc/ghi trực tiếp dữ liệu. Mọi yêu cầu đi qua bin/wnb_exec (setuid root),
 *    helper xác định danh tính bằng UID thật của tiến trình gọi (không giả mạo được).
 *  - Phía root (helper, cron, worker) giữ toàn bộ dữ liệu trong data/ với quyền 0700.
 *  - Chế độ preview (không phải máy DirectAdmin) chạy mọi thứ trong cùng tiến trình với dữ liệu demo.
 */

define('WNB_ID', 'webnowbackup');
define('WNB_NAME', 'WebNowBackup');
define('WNB_VERSION', '2.4.1');
define('WNB_ROOT', dirname(__DIR__));
define('WNB_PLUGIN_DIR', '/usr/local/directadmin/plugins/' . WNB_ID);

require_once __DIR__ . '/Lang.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/DirectAdmin.php';
require_once __DIR__ . '/Rclone.php';
require_once __DIR__ . '/Engine.php';
require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Client.php';

final class WNB
{
    /** php -n không đọc php.ini nên không có date.timezone: lấy múi giờ của hệ thống để lịch chạy đúng giờ địa phương. */
    public static function initTimezone(): void
    {
        $tz = '';
        $link = @readlink('/etc/localtime');
        if (is_string($link) && ($pos = strpos($link, 'zoneinfo/')) !== false) {
            $tz = substr($link, $pos + 9);
        } elseif (is_readable('/etc/timezone')) {
            $tz = trim((string)file_get_contents('/etc/timezone'));
        }
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        } elseif (!ini_get('date.timezone')) {
            date_default_timezone_set('Asia/Ho_Chi_Minh');
        }
    }

    /**
     * Preview chỉ bật khi chạy bằng php -S (router.php) hoặc WNB_PREVIEW=1.
     * Trên máy DirectAdmin thật script chạy bằng SAPI "cli" nên không bao giờ rơi vào dữ liệu demo.
     */
    public static function isPreview(): bool
    {
        return PHP_SAPI === 'cli-server' || getenv('WNB_PREVIEW') === '1';
    }

    /** UID hiệu lực của tiến trình (không phụ thuộc extension posix vì php chạy với -n). */
    public static function euid(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        $status = @file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $m)) {
            return (int)$m[1];
        }
        return -1;
    }

    public static function isRoot(): bool
    {
        return self::euid() === 0;
    }

    public static function helperPath(): string
    {
        return WNB_ROOT . '/bin/wnb_exec';
    }

    /** Helper đã được cài bằng root: thuộc root và có bit setuid. */
    public static function helperReady(): bool
    {
        $path = self::helperPath();
        if (!is_file($path) || !is_executable($path)) {
            return false;
        }
        $stat = @stat($path);
        return is_array($stat) && $stat['uid'] === 0 && ($stat['mode'] & 04000) === 04000;
    }

    public static function installCommand(): string
    {
        return 'sh ' . WNB_PLUGIN_DIR . '/scripts/install.sh';
    }

    public static function phpBinary(): string
    {
        foreach (['/usr/local/bin/php', '/usr/bin/php'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        return PHP_BINARY ?: 'php';
    }

    /**
     * Gộp tham số request từ môi trường CGI của DirectAdmin (QUERY_STRING, POST) và superglobals (preview).
     */
    public static function requestParams(): array
    {
        $params = [];
        $qs = getenv('QUERY_STRING');
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $fromEnv);
            $params = array_merge($params, $fromEnv);
        }
        if (!empty($_GET)) {
            $params = array_merge($params, $_GET);
        }
        $post = getenv('POST');
        if (is_string($post) && $post !== '') {
            parse_str($post, $fromPost);
            $params = array_merge($params, $fromPost);
        }
        if (!empty($_POST)) {
            $params = array_merge($params, $_POST);
        }
        return $params;
    }

    /** Độ dài/cắt chuỗi UTF-8 không cần mbstring (php -n có thể không nạp mbstring). */
    public static function ulen(string $s): int
    {
        return (int)preg_match_all('/./us', $s);
    }

    public static function usub(string $s, int $n): string
    {
        return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : '';
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function newId(string $prefix): string
    {
        return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    }
}

/**
 * Chạy lệnh shell có kiểm tra mã thoát. Mọi lệnh của engine đi qua đây để không còn lỗi bị nuốt im lặng.
 */
final class WNBShell
{
    /** Chạy lệnh, gom stdout+stderr vào $output, trả về exit code. */
    public static function run(string $cmd, &$output = null): int
    {
        $lines = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $lines, $code);
        $output = $lines;
        return (int)$code;
    }

    /** Chạy script với bash -o pipefail để lỗi ở giữa pipeline (vd mysqldump | gzip) không bị che. */
    public static function bash(string $script, &$output = null): int
    {
        $bash = self::which('bash') ?: '/bin/bash';
        return self::run(escapeshellarg($bash) . ' -o pipefail -c ' . escapeshellarg($script), $output);
    }

    /** Chạy lệnh và đưa dữ liệu vào stdin (dùng cho bí mật, tránh lộ trên danh sách tiến trình). */
    public static function runWithInput(string $cmd, string $input, &$output = null): int
    {
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $output = ['Không chạy được lệnh'];
            return 127;
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $output = array_values(array_filter(explode("\n", (string)$stdout . "\n" . (string)$stderr), 'strlen'));
        return (int)$code;
    }

    public static function which(string $bin): string
    {
        foreach (['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'] as $dir) {
            if (is_executable($dir . '/' . $bin)) {
                return $dir . '/' . $bin;
            }
        }
        return '';
    }

    /** Tiền tố nice/ionice để tiến trình backup không làm nghẽn web của khách. */
    public static function lowPriorityPrefix(): string
    {
        $s = WNBStorage::settings();
        $prefix = '';
        if (self::which('ionice') !== '') {
            $prefix .= 'ionice -c 2 -n 7 ';
        }
        if (self::which('nice') !== '') {
            $prefix .= 'nice -n ' . max(0, min(19, (int)($s['nice_level'] ?? 19))) . ' ';
        }
        return $prefix;
    }

    /** Lệnh chạy dưới quyền một user (hạ quyền khi ghi vào home của khách). */
    public static function asUser(string $username, string $cmd): string
    {
        $runuser = self::which('runuser');
        if ($runuser !== '') {
            return escapeshellarg($runuser) . ' -u ' . escapeshellarg($username) . ' -- /bin/sh -c ' . escapeshellarg($cmd);
        }
        return 'su -s /bin/sh ' . escapeshellarg($username) . ' -c ' . escapeshellarg($cmd);
    }

    public static function tail(array $lines, int $n = 4): string
    {
        $lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
        return implode(' | ', array_slice($lines, -$n));
    }
}

WNB::initTimezone();
