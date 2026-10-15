<?php
/**
 * WebNowBackup - lưu trữ JSON có khóa (flock). Chỉ phía root (hoặc preview) được dùng lớp này.
 * Thư mục data/ có quyền 0700, file 0600: user thường không đọc/ghi được.
 */

final class WNBStorage
{
    private static string $dir = '';

    public static function dir(): string
    {
        if (self::$dir === '') {
            $dir = WNB_ROOT . '/data';
            $old = umask(0077);
            foreach (['', '/logs', '/locks', '/downloads', '/tmp'] as $sub) {
                if (!is_dir($dir . $sub)) {
                    @mkdir($dir . $sub, 0700, true);
                }
            }
            umask($old);
            self::$dir = $dir;
            self::ensureDefaults();
        }
        return self::$dir;
    }

    public static function path(string $rel): string
    {
        return self::dir() . '/' . ltrim($rel, '/');
    }

    public static function read(string $collection): array
    {
        $file = self::dir() . '/' . $collection . '.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    public static function write(string $collection, array $data): bool
    {
        $file = self::dir() . '/' . $collection . '.json';
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $json) !== false;
        umask($old);
        if (!$ok) {
            return false;
        }
        @chmod($tmp, 0600);
        return @rename($tmp, $file);
    }

    /** Đọc - sửa - ghi trong khóa độc quyền. */
    public static function update(string $collection, callable $mutator)
    {
        $lock = self::lock($collection);
        try {
            $current = self::read($collection);
            $updated = $mutator($current);
            if (is_array($updated)) {
                self::write($collection, $updated);
                return $updated;
            }
            return $current;
        } finally {
            self::unlock($lock);
        }
    }

    /** @return resource|null */
    public static function lock(string $name, bool $wait = true)
    {
        $fp = @fopen(self::dir() . '/locks/' . preg_replace('/[^a-z0-9_\-]/i', '', $name) . '.lock', 'c');
        if (!$fp) {
            return null;
        }
        if (!@flock($fp, $wait ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
            fclose($fp);
            return null;
        }
        return $fp;
    }

    public static function unlock($fp): void
    {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    public static function log(string $taskId, string $message): void
    {
        $file = self::dir() . '/logs/' . preg_replace('/[^a-z0-9_\-]/i', '', $taskId) . '.log';
        $old = umask(0077);
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
        umask($old);
    }

    public static function readLog(string $taskId, int $tail = 400): string
    {
        $file = self::dir() . '/logs/' . preg_replace('/[^a-z0-9_\-]/i', '', $taskId) . '.log';
        if (!is_file($file)) {
            return '';
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        return implode("\n", array_slice($lines, -$tail));
    }

    public static function settings(): array
    {
        return array_merge(self::defaultSettings(), self::read('settings'));
    }

    public static function defaultSettings(): array
    {
        return [
            'local_backup_root' => '/backup/webnowbackup',
            'max_concurrent_tasks' => 2,
            'job_retry_minutes' => 30,
            'bwlimit' => '',
            'nice_level' => 19,
            'default_retention' => 7,
            'max_snapshots_per_user' => 3,
            'snapshot_destination' => 'dest_local',
            'download_ttl_hours' => 24,
            'min_free_gb' => 5,
            'exclude_accounts' => [],
            'exclude_resellers' => [],
            'language' => 'vi',
            'notify_email' => '',
            'server_name' => '',
            'notify_on_failure' => true,
            'notify_job_report' => 'errors',
            'notify_health' => true,
            'telegram_token' => '',
            'telegram_chat_id' => '',
            'excludes' => "backups/*\n.cache/*\ntmp/*",
        ];
    }

    /** Khóa bí mật dùng ký token chống CSRF. */
    public static function secret(): string
    {
        $file = self::dir() . '/secret.key';
        $key = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        if (strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            $old = umask(0077);
            @file_put_contents($file, $key);
            umask($old);
        }
        return $key;
    }

    private static function ensureDefaults(): void
    {
        if (!is_file(self::$dir . '/settings.json')) {
            self::write('settings', self::defaultSettings());
        }
        if (!is_file(self::$dir . '/destinations.json')) {
            self::write('destinations', [[
                'id' => 'dest_local',
                'name' => 'Ổ cứng máy chủ',
                'type' => 'local',
                'path' => self::defaultSettings()['local_backup_root'],
                'remote' => '',
                'subpath' => '',
                'created_at' => date('Y-m-d H:i:s'),
            ]]);
        }
        if (!is_file(self::$dir . '/jobs.json')) {
            // Lịch mặc định để TẮT: không tự sao lưu toàn server khi admin chưa xác nhận dung lượng đĩa.
            self::write('jobs', [[
                'id' => 'job_daily',
                'name' => 'Sao lưu hằng ngày',
                'destination_id' => 'dest_local',
                'schedule_type' => 'daily',
                'schedule_time' => '02:00',
                'schedule_weekday' => 0,
                'interval_hours' => 6,
                'keep_last' => 0,
                'keep_daily' => 7,
                'keep_weekly' => 4,
                'keep_monthly' => 3,
                'filter_mode' => 'exclude',
                'exclude_users' => [],
                'exclude_resellers' => [],
                'include_users' => [],
                'include_resellers' => [],
                'skip_suspended' => false,
                'enabled' => false,
                'last_run' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]]);
        }
        foreach (['backups', 'queue', 'acl', 'downloads'] as $c) {
            if (!is_file(self::$dir . '/' . $c . '.json')) {
                self::write($c, []);
            }
        }
    }
}
