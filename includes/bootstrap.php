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
define('WNB_VERSION', '2.6.1');
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
    /** Engine đặt khi đang chạy một tác vụ: ném WNBCancelled nếu tác vụ được yêu cầu dừng. */
    public static $guard = null;

    /** Điểm dừng: gọi trước và sau mỗi lệnh để tác vụ bị dừng không chạy tiếp lệnh kế. */
    public static function checkpoint(): void
    {
        if (self::$guard !== null) {
            (self::$guard)();
        }
    }

    /** Chạy lệnh, gom stdout+stderr vào $output, trả về exit code. */
    public static function run(string $cmd, &$output = null): int
    {
        self::checkpoint();
        if (self::$abort !== null && DIRECTORY_SEPARATOR === '/') {
            // Đang chạy tác vụ trên Linux: chờ theo vòng lặp để tự dừng được lệnh khi bị yêu cầu dừng.
            $code = self::watch($cmd, $output, null, 5);
        } else {
            $lines = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $lines, $code);
            $output = $lines;
        }
        self::checkpoint();
        return (int)$code;
    }

    /** Chạy script với bash -o pipefail để lỗi ở giữa pipeline (vd mysqldump | gzip) không bị che. */
    public static function bash(string $script, &$output = null): int
    {
        $bash = self::which('bash') ?: '/bin/bash';
        return self::run(escapeshellarg($bash) . ' -o pipefail -c ' . escapeshellarg($script), $output);
    }

    /**
     * Như run() nhưng cứ $every giây gọi $tick(file output) trong lúc lệnh còn chạy, để cập nhật tiến độ.
     * Output ghi ra file tạm (lệnh dài như rsync/tar/restic có thể in rất nhiều dòng tiến độ).
     */
    public static function runWatch(string $cmd, &$output, callable $tick, int $every = 5): int
    {
        self::checkpoint();
        $code = self::watch($cmd, $output, $tick, $every);
        self::checkpoint();
        return $code;
    }

    /**
     * Chạy lệnh bằng proc_open và chờ theo vòng lặp (thay cho exec chặn cứng) để trong lúc chờ:
     *  - gọi $tick cập nhật tiến độ;
     *  - nếu tác vụ bị yêu cầu dừng ($abort trả true): TERM cả cây tiến trình con, sau 5 giây KILL.
     * Nhờ vậy worker tự dừng được lệnh của mình, không phụ thuộc lần kill từ API.
     */
    private static function watch(string $cmd, &$output, ?callable $tick, int $every): int
    {
        $log = WNBStorage::path('tmp/run_' . bin2hex(random_bytes(6)) . '.out');
        // Bọc { } để output của mọi vế (a; b) đều vào file, như exec($cmd . ' 2>&1') trước đây với vế cuối.
        $full = DIRECTORY_SEPARATOR === '/' ? '{ ' . $cmd . "
} > " . escapeshellarg($log) . ' 2>&1' : $cmd . ' > ' . escapeshellarg($log) . ' 2>&1';
        $proc = @proc_open($full, [0 => ['pipe', 'r']], $pipes);
        if (!is_resource($proc)) {
            $output = ['Không chạy được lệnh'];
            return 127;
        }
        fclose($pipes[0]);
        $code = -1;
        $last = microtime(true);
        $sleep = 10000;
        $abortAt = null;
        $victims = [];
        $lastKill = 0.0;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $code = (int)$st['exitcode'];
                break;
            }
            usleep($sleep);
            $sleep = min(250000, $sleep * 2); // lệnh ngắn trả về gần như ngay, lệnh dài thì đỡ tốn CPU
            $now = microtime(true);
            if ($tick !== null && $now - $last >= $every) {
                $last = $now;
                try {
                    $tick($log);
                } catch (Throwable $e) {
                    // tiến độ chỉ để hiển thị, không được làm hỏng lệnh
                }
            }
            if (self::$abort !== null && $now - $lastKill >= 1 && (self::$abort)()) {
                $abortAt = $abortAt ?? $now;
                $lastKill = $now;
                // Gom dần: tiến trình con có thể đổi nhóm (rclone của restic) hoặc mồ côi khi cha chết trước.
                $procs = self::procTable();
                foreach (array_merge([(int)$st['pid']], self::descendants((int)$st['pid'], $procs)) as $p) {
                    if (isset($procs[$p]) && !isset($victims[$p])) {
                        $victims[$p] = $procs[$p]['start'];
                    }
                }
                self::signal($victims, $now - $abortAt >= 5 ? 'KILL' : 'TERM', $procs);
            }
        }
        $closed = proc_close($proc);
        if ($code === -1) {
            $code = $closed;
        }
        $output = array_values(array_filter(array_map('rtrim', preg_split('/[\r\n]+/', (string)@file_get_contents($log))), 'strlen'));
        @unlink($log);
        return (int)$code;
    }

    /** Engine đặt khi đang chạy một tác vụ: trả true nếu tác vụ được yêu cầu dừng (kiểm tra trong lúc chờ lệnh). */
    public static $abort = null;

    /**
     * Bảng tiến trình từ /proc: pid => trạng thái, ppid, nhóm, thời điểm khởi động (để không kill nhầm PID bị tái sử dụng).
     * @return array<int, array{state:string, ppid:int, pgrp:int, start:string}>
     */
    public static function procTable(): array
    {
        $out = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
            $s = @file_get_contents($f);
            $end = $s === false ? false : strrpos($s, ')');
            if ($end === false) {
                continue;
            }
            // Sau "(tên lệnh) ": state ppid pgrp session tty tpgid flags minflt cminflt majflt cmajflt utime stime cutime cstime priority nice threads itreal starttime
            $parts = explode(' ', substr($s, $end + 2));
            $out[(int)substr($f, 6)] = ['state' => $parts[0], 'ppid' => (int)($parts[1] ?? 0), 'pgrp' => (int)($parts[2] ?? 0), 'start' => (string)($parts[19] ?? '')];
        }
        return $out;
    }

    /** Mọi tiến trình con cháu của $pid. */
    public static function descendants(int $pid, array $procs): array
    {
        $found = [];
        $todo = [$pid];
        while ($todo) {
            $parent = array_pop($todo);
            foreach ($procs as $p => $info) {
                if ($info['ppid'] === $parent && $p !== $pid && !isset($found[$p])) {
                    $found[$p] = true;
                    $todo[] = $p;
                }
            }
        }
        return array_keys($found);
    }

    /** Gửi tín hiệu cho các tiến trình còn sống ĐÚNG là tiến trình đã ghi nhận (cùng thời điểm khởi động). */
    public static function signal(array $victims, string $sig, ?array $procs = null): int
    {
        $procs = $procs ?? self::procTable();
        $alive = [];
        foreach ($victims as $p => $start) {
            if (isset($procs[$p]) && $procs[$p]['start'] === $start && $procs[$p]['state'] !== 'Z') {
                $alive[] = (int)$p;
            }
        }
        if ($alive) {
            $list = implode(' ', $alive);
            // Lệnh bị dừng tạm (SIGSTOP) chỉ nhận TERM sau khi được CONT.
            @exec('kill -' . $sig . ' ' . $list . ' 2>/dev/null; kill -CONT ' . $list . ' 2>/dev/null');
        }
        return count($alive);
    }

    /** bash() có theo dõi tiến độ. */
    public static function bashWatch(string $script, &$output, callable $tick, int $every = 5): int
    {
        $bash = self::which('bash') ?: '/bin/bash';
        return self::runWatch(escapeshellarg($bash) . ' -o pipefail -c ' . escapeshellarg($script), $output, $tick, $every);
    }

    /** Đọc tối đa $bytes cuối của file (đọc tiến độ mới nhất mà không nạp cả file). */
    public static function tailFile(string $file, int $bytes = 8192): string
    {
        $size = @filesize($file);
        if (!$size) {
            return '';
        }
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return '';
        }
        fseek($fp, max(0, $size - $bytes));
        $data = (string)stream_get_contents($fp);
        fclose($fp);
        return $data;
    }

    /** Chạy lệnh và đưa dữ liệu vào stdin (dùng cho bí mật, tránh lộ trên danh sách tiến trình). */
    public static function runWithInput(string $cmd, string $input, &$output = null): int
    {
        self::checkpoint();
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
        self::checkpoint();
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
