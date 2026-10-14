<?php
/**
 * WebNowBackup - đọc dữ liệu tài khoản DirectAdmin (chạy với quyền root).
 * Ở chế độ preview trả về tài khoản demo.
 */

final class WNBDirectAdmin
{
    public const DA_DIR = '/usr/local/directadmin';
    public const USERS_DIR = '/usr/local/directadmin/data/users';
    public const MYSQL_CONF = '/usr/local/directadmin/conf/mysql.conf';
    public const TASK_QUEUE = '/usr/local/directadmin/data/task.queue';

    private static array $cache = [];

    public static function isReal(): bool
    {
        return is_file(self::DA_DIR . '/directadmin') || is_dir(self::DA_DIR . '/data');
    }

    public static function validUsername(string $u): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_\-]{0,31}$/i', $u);
    }

    public static function validDomain(string $d): bool
    {
        return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z0-9\-]{2,63}$/i', $d);
    }

    public static function parseConf(string $path): array
    {
        $out = [];
        foreach ((is_readable($path) ? (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : []) as $line) {
            $pos = strpos($line, '=');
            if ($pos !== false && ($line[0] ?? '#') !== '#') {
                $out[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }
        return $out;
    }

    public static function exists(string $u): bool
    {
        if (!self::validUsername($u)) {
            return false;
        }
        if (WNB::isPreview()) {
            return isset(self::demoAccounts()[$u]);
        }
        return is_file(self::USERS_DIR . '/' . $u . '/user.conf');
    }

    /** @return string[] */
    public static function usernames(): array
    {
        if (WNB::isPreview()) {
            return array_keys(self::demoAccounts());
        }
        $names = [];
        foreach (@scandir(self::USERS_DIR) ?: [] as $u) {
            if ($u[0] !== '.' && self::validUsername($u) && is_file(self::USERS_DIR . '/' . $u . '/user.conf')) {
                $names[] = $u;
            }
        }
        sort($names);
        return $names;
    }

    public static function usertype(string $u): string
    {
        $acc = self::account($u);
        return $acc['usertype'] ?? '';
    }

    /** Thông tin cơ bản (nhẹ, dùng cho danh sách). $detailed = true để lấy DB/email/cron. */
    public static function account(string $u, bool $detailed = false): ?array
    {
        if (!self::exists($u)) {
            return null;
        }
        $key = $u . ($detailed ? ':d' : '');
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (WNB::isPreview()) {
            $acc = self::demoAccounts()[$u];
        } else {
            $conf = self::parseConf(self::USERS_DIR . '/' . $u . '/user.conf');
            $usage = self::parseConf(self::USERS_DIR . '/' . $u . '/user.usage');
            $usertype = $conf['usertype'] ?? 'user';
            $acc = [
                'username' => $u,
                'usertype' => in_array($usertype, ['admin', 'reseller', 'user'], true) ? $usertype : 'user',
                'creator' => $conf['creator'] ?? 'admin',
                'domain' => $conf['domain'] ?? '',
                'domains' => self::domains($u),
                'package' => $conf['package'] ?? '',
                'suspended' => ($conf['suspended'] ?? 'no') === 'yes',
                'quota_mb' => is_numeric($conf['quota'] ?? null) ? (float)$conf['quota'] : 0.0,
                'usage_mb' => round((float)($usage['quota'] ?? 0), 1),
            ];
            if ($detailed) {
                $acc['databases'] = self::databases($u);
                $acc['emails'] = self::emails($acc['domains']);
                $acc['crons'] = self::crons($u);
            }
        }
        if ($acc['domain'] === '' && !empty($acc['domains'])) {
            $acc['domain'] = $acc['domains'][0];
        }
        self::$cache[$key] = $acc;
        return $acc;
    }

    public static function accounts(): array
    {
        $out = [];
        foreach (self::usernames() as $u) {
            $acc = self::account($u);
            if ($acc) {
                $out[] = $acc;
            }
        }
        return $out;
    }

    /** User do reseller quản lý (kèm chính reseller). */
    public static function resellerUsernames(string $reseller): array
    {
        if (!self::exists($reseller)) {
            return [];
        }
        $names = [$reseller];
        if (WNB::isPreview()) {
            foreach (self::demoAccounts() as $acc) {
                if ($acc['creator'] === $reseller) {
                    $names[] = $acc['username'];
                }
            }
            return $names;
        }
        foreach (@file(self::USERS_DIR . '/' . $reseller . '/users.list', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $u) {
            $u = trim($u);
            if (self::exists($u)) {
                $names[] = $u;
            }
        }
        return array_values(array_unique($names));
    }

    /** Có phải reseller (hoặc admin, đại diện cho khách do Admin tạo) dùng được trong bộ lọc reseller không. */
    public static function isOwnerAccount(string $u): bool
    {
        return self::validUsername($u) && in_array(self::usertype($u), ['reseller', 'admin'], true);
    }

    /**
     * Tài khoản bị loại trừ khi loại trừ một reseller: chính reseller đó và khách do reseller tạo.
     * Với 'admin' (hay tài khoản admin khác): chính admin và khách do admin tạo, KHÔNG gồm các reseller
     * (mỗi reseller là một nhóm riêng).
     */
    public static function resellerGroup(string $reseller): array
    {
        if (!self::isOwnerAccount($reseller)) {
            return [];
        }
        return array_values(array_filter(self::resellerUsernames($reseller), fn($u) => $u === $reseller || self::usertype($u) === 'user'));
    }

    public static function domains(string $u): array
    {
        if (WNB::isPreview()) {
            return self::demoAccounts()[$u]['domains'] ?? [];
        }
        $list = @file(self::USERS_DIR . '/' . $u . '/domains.list', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_filter(array_map('trim', $list), [self::class, 'validDomain']));
    }

    /** UID, GID, home lấy từ /etc/passwd (home có thể nằm ở /home2...). */
    public static function passwd(string $u): ?array
    {
        if (!self::validUsername($u)) {
            return null;
        }
        if (WNB::isPreview()) {
            return ['uid' => 1000, 'gid' => 1000, 'home' => '/home/' . $u];
        }
        foreach (@file('/etc/passwd', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $p = explode(':', $line);
            if (count($p) >= 7 && $p[0] === $u) {
                return ['uid' => (int)$p[2], 'gid' => (int)$p[3], 'home' => rtrim($p[5], '/')];
            }
        }
        return null;
    }

    /**
     * Dấu định danh chủ sở hữu: UID + ngày tạo tài khoản. Tài khoản bị xóa rồi tạo lại cùng tên
     * sẽ có dấu khác, nên không nhìn thấy bản sao lưu của khách cũ.
     */
    public static function ownerKey(string $u): string
    {
        if (WNB::isPreview()) {
            return 'demo:' . $u;
        }
        $pw = self::passwd($u);
        $conf = self::parseConf(self::USERS_DIR . '/' . $u . '/user.conf');
        return ($pw['uid'] ?? 'x') . ':' . ($conf['date_created'] ?? '');
    }

    /** Tạo file cấu hình MySQL tạm (0600) để không lộ mật khẩu trên dòng lệnh. Caller tự xóa. */
    public static function mysqlDefaultsFile(): string
    {
        $c = self::parseConf(self::MYSQL_CONF);
        if (($c['passwd'] ?? '') === '') {
            throw new WNBError('Không đọc được thông tin MySQL trong {file}', ['file' => self::MYSQL_CONF]);
        }
        $ini = "[client]\nuser=" . ($c['user'] ?? 'da_admin') . "\npassword=\"" . addcslashes($c['passwd'], "\"\\") . "\"\n";
        if (!empty($c['socket'])) {
            $ini .= 'socket=' . $c['socket'] . "\n";
        } elseif (!empty($c['host'])) {
            $ini .= 'host=' . $c['host'] . "\n";
            if (!empty($c['port'])) {
                $ini .= 'port=' . (int)$c['port'] . "\n";
            }
        }
        $file = WNBStorage::path('tmp/my_' . bin2hex(random_bytes(6)) . '.cnf');
        $old = umask(0077);
        file_put_contents($file, $ini);
        umask($old);
        return $file;
    }

    public static function databases(string $u): array
    {
        if (WNB::isPreview()) {
            return self::demoAccounts()[$u]['databases'] ?? [];
        }
        $dbs = [];
        $mysql = WNBShell::which('mysql');
        if ($mysql !== '') {
            try {
                $cnf = self::mysqlDefaultsFile();
                $pattern = str_replace('_', '\\_', $u) . '\\_%';
                $code = WNBShell::run(sprintf('%s --defaults-extra-file=%s -N -B -e %s', escapeshellarg($mysql), escapeshellarg($cnf), escapeshellarg("SHOW DATABASES LIKE '" . $pattern . "'")), $out);
                @unlink($cnf);
                if ($code === 0) {
                    foreach ($out as $db) {
                        $db = trim($db);
                        if ($db !== '' && strpos($db, $u . '_') === 0) {
                            $dbs[] = $db;
                        }
                    }
                    return $dbs;
                }
            } catch (Throwable $e) {
                // rơi xuống quét thư mục dữ liệu MySQL
            }
        }
        foreach (['/var/lib/mysql', '/home/mysql'] as $dir) {
            foreach (@scandir($dir) ?: [] as $entry) {
                if (strpos($entry, $u . '_') === 0 && is_dir($dir . '/' . $entry)) {
                    $dbs[] = $entry;
                }
            }
        }
        return array_values(array_unique($dbs));
    }

    public static function emails(array $domains): array
    {
        $emails = [];
        foreach ($domains as $d) {
            foreach (@file('/etc/virtual/' . $d . '/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $box = explode(':', $line, 2)[0];
                if ($box !== '' && preg_match('/^[a-z0-9._+\-]+$/i', $box)) {
                    $emails[] = $box . '@' . $d;
                }
            }
        }
        return $emails;
    }

    public static function crons(string $u): array
    {
        $out = [];
        foreach (@file(self::USERS_DIR . '/' . $u . '/crontab.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\d+=(.+)$/', $line, $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    public static function zoneFile(string $domain): string
    {
        foreach (['/var/named', '/etc/bind', '/etc/namedb'] as $dir) {
            if (is_file($dir . '/' . $domain . '.db')) {
                return $dir . '/' . $domain . '.db';
            }
        }
        return is_dir('/var/named') ? '/var/named/' . $domain . '.db' : '/etc/bind/' . $domain . '.db';
    }

    public static function systemCrontab(string $u): string
    {
        foreach (['/var/spool/cron/' . $u, '/var/spool/cron/crontabs/' . $u] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return '';
    }

    public static function queueTask(string $line): void
    {
        @file_put_contents(self::TASK_QUEUE, $line . "\n", FILE_APPEND);
    }

    /** Chỉ dùng ở preview/test: tài khoản demo thêm vào (giả lập khách tạo sau). */
    public static array $demoExtra = [];

    public static function demoAccounts(): array
    {
        return self::$demoExtra + [
            'admin' => [
                'username' => 'admin', 'usertype' => 'admin', 'creator' => 'root',
                'domain' => 'server.webnow.vn', 'domains' => ['server.webnow.vn'],
                'package' => '', 'suspended' => false, 'quota_mb' => 0, 'usage_mb' => 420.5,
                'databases' => ['admin_panel', 'admin_whmcs'],
                'emails' => ['support@server.webnow.vn', 'billing@server.webnow.vn'],
                'crons' => ['*/5 * * * * php /home/admin/domains/server.webnow.vn/public_html/cron.php'],
            ],
            'reseller_vn' => [
                'username' => 'reseller_vn', 'usertype' => 'reseller', 'creator' => 'admin',
                'domain' => 'agencycloud.vn', 'domains' => ['agencycloud.vn'],
                'package' => 'Reseller_100G', 'suspended' => false, 'quota_mb' => 102400, 'usage_mb' => 1280.0,
                'databases' => ['reseller_vn_crm'],
                'emails' => ['hello@agencycloud.vn', 'sales@agencycloud.vn'],
                'crons' => [],
            ],
            'shopgiay' => [
                'username' => 'shopgiay', 'usertype' => 'user', 'creator' => 'reseller_vn',
                'domain' => 'shopgiaydep.vn', 'domains' => ['shopgiaydep.vn', 'khuyenmai.shopgiaydep.vn'],
                'package' => 'Business_20G', 'suspended' => false, 'quota_mb' => 20480, 'usage_mb' => 3450.8,
                'databases' => ['shopgiay_wp', 'shopgiay_erp'],
                'emails' => ['info@shopgiaydep.vn', 'cskh@shopgiaydep.vn', 'ketoan@shopgiaydep.vn'],
                'crons' => ['0 3 * * * php /home/shopgiay/domains/shopgiaydep.vn/public_html/wp-cron.php'],
            ],
            'nhakhoa' => [
                'username' => 'nhakhoa', 'usertype' => 'user', 'creator' => 'admin',
                'domain' => 'nhakhoasaigon.vn', 'domains' => ['nhakhoasaigon.vn'],
                'package' => 'Starter_5G', 'suspended' => false, 'quota_mb' => 5120, 'usage_mb' => 812.3,
                'databases' => ['nhakhoa_wp'],
                'emails' => ['lienhe@nhakhoasaigon.vn'],
                'crons' => [],
            ],
        ];
    }
}
