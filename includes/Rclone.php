<?php
/**
 * WebNowBackup - kho lưu trữ: ổ cứng local và Rclone (S3, R2, B2, SFTP, FTP, WebDAV, Google Drive, OneDrive).
 */

final class WNBRclone
{
    /** Loại kho -> [kiểu rclone, các trường được phép nhập]. */
    public const TYPES = [
        's3' => ['type' => 's3', 'fields' => ['provider', 'access_key_id', 'secret_access_key', 'region', 'endpoint']],
        'r2' => ['type' => 's3', 'fields' => ['access_key_id', 'secret_access_key', 'endpoint']],
        'b2' => ['type' => 'b2', 'fields' => ['account', 'key']],
        'sftp' => ['type' => 'sftp', 'fields' => ['host', 'user', 'port', 'pass']],
        'ftp' => ['type' => 'ftp', 'fields' => ['host', 'user', 'port', 'pass']],
        'webdav' => ['type' => 'webdav', 'fields' => ['url', 'vendor', 'user', 'pass']],
        'drive' => ['type' => 'drive', 'fields' => ['token']],
        'onedrive' => ['type' => 'onedrive', 'fields' => ['token', 'drive_id', 'drive_type']],
    ];

    /** Các trường bí mật: không bao giờ trả về giao diện. */
    public const SECRET_FIELDS = ['secret_access_key', 'key', 'pass', 'token'];

    /**
     * Loại kho tải lên dạng luồng (rclone rcat) mà không cần file tạm trên đĩa local,
     * nên dùng được chế độ "Nén". WebDAV/OneDrive có thể phải đệm cả file ra đĩa nên chỉ dùng chế độ thư mục.
     */
    public const ARCHIVE_TYPES = ['s3', 'r2', 'b2', 'sftp', 'ftp', 'drive'];

    public static function supportsArchive(string $type): bool
    {
        return in_array($type, self::ARCHIVE_TYPES, true);
    }

    /**
     * Cách lưu bản sao lưu trên kho: 'archive' = mỗi bản là vài file nén (nhanh, không cần chỗ trống local),
     * 'folder' = cây thư mục từng file (duyệt/khôi phục từng file trực tiếp nhưng tải lên chậm),
     * 'restic' = incremental + khử trùng bằng restic (chỉ tải phần thay đổi). Kho local luôn là 'folder'.
     */
    public static function destFormat(array $dest): string
    {
        if (($dest['type'] ?? 'local') === 'local') {
            return 'folder';
        }
        $f = (string)($dest['format'] ?? 'archive');
        if ($f === 'restic') {
            return 'restic';
        }
        if (!self::supportsArchive((string)$dest['type'])) {
            return 'folder';
        }
        return $f === 'folder' ? 'folder' : 'archive';
    }

    public static function binary(): string
    {
        return WNB::isPreview() ? '' : WNBShell::which('rclone');
    }

    public static function installed(): bool
    {
        return self::binary() !== '';
    }

    public static function version(): string
    {
        if (!self::installed()) {
            return '';
        }
        WNBShell::run(escapeshellarg(self::binary()) . ' version', $out);
        return preg_match('/rclone\s+(v[\d.]+)/', implode("\n", $out), $m) ? $m[1] : '';
    }

    public static function configPath(): string
    {
        $path = WNBStorage::path('rclone.conf');
        if (!is_file($path)) {
            $old = umask(0077);
            @file_put_contents($path, "# WebNowBackup rclone config\n");
            umask($old);
        }
        return $path;
    }

    /** Tiền tố lệnh rclone kèm config và giới hạn băng thông. */
    public static function cmd(string $args, bool $bwlimit = false): string
    {
        $extra = '';
        $bw = trim((string)(WNBStorage::settings()['bwlimit'] ?? ''));
        if ($bwlimit && $bw !== '' && $bw !== '0' && preg_match('/^[0-9.]+[KMGkmg]?$/', $bw)) {
            $extra = ' --bwlimit ' . escapeshellarg($bw);
        }
        return escapeshellarg(self::binary()) . ' --config ' . escapeshellarg(self::configPath()) . $extra . ' ' . $args;
    }

    /** Ghi/ghi đè một remote trong rclone.conf. Trường bí mật để trống = giữ giá trị cũ. */
    public static function saveRemote(string $name, string $kind, array $input): void
    {
        $params = self::buildParams($name, $kind, $input);
        $sections = self::parseConfig();
        $sections[$name] = ['type' => self::TYPES[$kind]['type']] + $params;
        self::writeConfig($sections);
    }

    /** Tham số remote sẽ ghi vào rclone.conf từ dữ liệu form (chưa ghi). */
    public static function buildParams(string $name, string $kind, array $input): array
    {
        if (!isset(self::TYPES[$kind]) || !preg_match('/^[a-z0-9_\-]{1,40}$/i', $name)) {
            throw new WNBError('Loại kho hoặc tên remote không hợp lệ.');
        }
        $existing = self::readRemote($name);
        $params = [];
        foreach (self::TYPES[$kind]['fields'] as $field) {
            $val = trim(str_replace(["\r", "\n"], '', (string)($input[$field] ?? '')));
            if ($val === '' && in_array($field, self::SECRET_FIELDS, true) && isset($existing[$field])) {
                $params[$field] = $existing[$field];
                continue;
            }
            if ($val === '') {
                continue;
            }
            if ($field === 'pass') {
                $val = self::obscure($val);
            }
            $params[$field] = $val;
        }
        if ($kind === 'r2') {
            $params['provider'] = 'Cloudflare';
            $params['acl'] = 'private';
        }
        if ($kind === 's3' && empty($params['provider'])) {
            $params['provider'] = 'Other';
        }
        return $params;
    }

    /** Trường xác định VỊ TRÍ kho (không gồm khóa bí mật, nên đổi khóa truy cập không bị coi là đổi kho). */
    public const LOCATION_FIELDS = [
        's3' => ['provider', 'region', 'endpoint'], 'r2' => ['endpoint'], 'b2' => ['account'],
        'sftp' => ['host', 'port', 'user'], 'ftp' => ['host', 'port', 'user'], 'webdav' => ['url', 'user'],
        'drive' => [], 'onedrive' => ['drive_id'],
    ];

    /**
     * Dấu vết vị trí của kho cloud: loại + bucket/thư mục + endpoint/host... Đổi dấu vết = trỏ sang kho khác,
     * các bản sao lưu đã ghi trong danh sách không còn nằm ở đó nữa.
     */
    public static function locationKey(string $type, string $subpath, array $params): string
    {
        $parts = [$type, trim($subpath, '/')];
        foreach (self::LOCATION_FIELDS[$type] ?? [] as $f) {
            $v = strtolower(rtrim(trim((string)($params[$f] ?? '')), '/'));
            $parts[] = $f === 'endpoint' ? preg_replace('#^https?://#', '', $v) : $v;
        }
        return sha1(implode("\n", $parts));
    }

    public static function deleteRemote(string $name): void
    {
        $sections = self::parseConfig();
        unset($sections[$name]);
        self::writeConfig($sections);
    }

    public static function readRemote(string $name): array
    {
        return self::parseConfig()[$name] ?? [];
    }

    private static function parseConfig(): array
    {
        $sections = [];
        $current = null;
        foreach (@file(self::configPath(), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $m)) {
                $current = $m[1];
                $sections[$current] = [];
            } elseif ($current !== null && ($pos = strpos($line, '=')) !== false) {
                $sections[$current][trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }
        return $sections;
    }

    private static function writeConfig(array $sections): void
    {
        $out = "# WebNowBackup rclone config\n";
        foreach ($sections as $name => $kv) {
            $out .= "\n[" . $name . "]\n";
            foreach ($kv as $k => $v) {
                $out .= $k . ' = ' . $v . "\n";
            }
        }
        $old = umask(0077);
        file_put_contents(self::configPath(), $out);
        umask($old);
    }

    private static function obscure(string $plain): string
    {
        if (!self::installed()) {
            return $plain;
        }
        $code = WNBShell::runWithInput(escapeshellarg(self::binary()) . ' obscure -', $plain, $out);
        if ($code !== 0 || empty($out[0])) {
            throw new WNBError('rclone obscure thất bại: {detail}', ['detail' => WNBShell::tail($out)]);
        }
        return trim($out[0]);
    }

    /** remote:subpath của một kho cloud. */
    public static function base(array $dest): string
    {
        $sub = trim((string)($dest['subpath'] ?? ''), '/');
        return $dest['remote'] . ':' . $sub;
    }

    public static function join(string $base, string ...$parts): string
    {
        $path = $base;
        foreach ($parts as $p) {
            $p = trim($p, '/');
            if ($p !== '') {
                $path .= (substr($path, -1) === ':' ? '' : '/') . $p;
            }
        }
        return $path;
    }

    /** Kiểm tra kho. Trả về ok, message, free_gb (null nếu không xác định được). */
    public static function test(array $dest): array
    {
        if (($dest['type'] ?? 'local') === 'local') {
            $path = (string)($dest['path'] ?? '');
            if ($path === '' || $path[0] !== '/') {
                return ['ok' => false, 'message' => WNBLang::msg('Đường dẫn phải là đường dẫn tuyệt đối.'), 'free_gb' => null];
            }
            if (!is_dir($path)) {
                @mkdir($path, 0711, true);
            }
            if (!is_dir($path) || !is_writable($path)) {
                return ['ok' => false, 'message' => WNBLang::msg('Không ghi được vào {path}.', ['path' => $path]), 'free_gb' => null];
            }
            $free = @disk_free_space($path);
            $freeGb = $free === false ? null : round($free / 1073741824, 1);
            return ['ok' => true, 'message' => $freeGb !== null ? WNBLang::msg('Ghi được vào {path}, còn trống {free} GB.', ['path' => $path, 'free' => $freeGb]) : WNBLang::msg('Ghi được vào {path}.', ['path' => $path]), 'free_gb' => $freeGb];
        }

        if (WNB::isPreview()) {
            return ['ok' => true, 'message' => WNBLang::msg('Preview: không kết nối cloud thật.'), 'free_gb' => null];
        }
        if (!self::installed()) {
            return ['ok' => false, 'message' => WNBLang::msg('Chưa cài rclone trên máy chủ.'), 'free_gb' => null];
        }
        $target = self::base($dest);
        $code = WNBShell::run(self::cmd('mkdir ' . escapeshellarg($target) . ' --contimeout 15s --timeout 30s'), $out);
        if ($code === 0) {
            $code = WNBShell::run(self::cmd('lsf ' . escapeshellarg($target) . ' --max-depth 1 --contimeout 15s --timeout 30s'), $out);
        }
        if ($code !== 0) {
            return ['ok' => false, 'message' => WNBLang::msg('Kết nối thất bại: {detail}', ['detail' => WNBShell::tail($out, 3)]), 'free_gb' => null];
        }
        $freeGb = null;
        if (WNBShell::run(self::cmd('about ' . escapeshellarg($dest['remote'] . ':') . ' --json --contimeout 15s --timeout 30s'), $about) === 0) {
            $j = json_decode(implode("\n", $about), true);
            if (isset($j['free'])) {
                $freeGb = round($j['free'] / 1073741824, 1);
            }
        }
        return ['ok' => true, 'message' => WNBLang::msg('Kết nối thành công tới {target}.', ['target' => $target]), 'free_gb' => $freeGb];
    }

    /**
     * Liệt kê một thư mục trong homedir của bản sao lưu.
     * Bản local: chặn mọi đường dẫn đi qua symlink để không lộ file ngoài bản sao lưu.
     */
    public static function listHome(array $backup, array $dest, string $rel): array
    {
        $rel = WNBEngine::cleanRelPath($rel);
        if ($rel === null) {
            throw new WNBError('Đường dẫn không hợp lệ.');
        }

        $format = (string)($backup['format'] ?? 'folder');
        if ($format === 'archive' || ($format === 'restic' && WNB::isPreview())) {
            return self::listArchive($backup, $rel);
        }
        if ($format === 'restic') {
            if (empty($dest['id'])) {
                throw new WNBError('Kho lưu trữ đã bị xóa.');
            }
            if (!WNBRestic::usable()) {
                throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
            }
            return self::sortItems(WNBRestic::listDir($dest, $backup, $rel));
        }

        if (($dest['type'] ?? 'local') === 'local') {
            $base = rtrim((string)$backup['storage_path'], '/') . '/homedir';
            $dir = $rel === '' ? $base : $base . '/' . $rel;
            $realBase = realpath($base);
            $realDir = realpath($dir);
            if ($realBase === false || $realDir === false || ($realDir !== $realBase && strpos($realDir, $realBase . DIRECTORY_SEPARATOR) !== 0) || is_link($dir) || !is_dir($dir)) {
                throw new WNBError('Thư mục không tồn tại trong bản sao lưu.');
            }
            $items = [];
            foreach (@scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $st = @lstat($dir . '/' . $name);
                if (!$st) {
                    continue;
                }
                $type = ($st['mode'] & 0170000) === 0040000 ? 'dir' : ((($st['mode'] & 0170000) === 0120000) ? 'link' : 'file');
                $items[] = [
                    'name' => $name,
                    'path' => ($rel === '' ? '' : $rel . '/') . $name,
                    'type' => $type,
                    'size' => $type === 'file' ? (int)$st['size'] : 0,
                    'mtime' => date('Y-m-d H:i:s', (int)$st['mtime']),
                ];
            }
            return self::sortItems($items);
        }

        if (!self::installed()) {
            throw new WNBError('Chưa cài rclone nên không duyệt được bản sao lưu trên cloud.');
        }
        $target = self::join((string)$backup['storage_path'], 'homedir', $rel);
        $code = WNBShell::run(self::cmd('lsjson ' . escapeshellarg($target) . ' --max-depth 1 --contimeout 15s --timeout 60s'), $out);
        $raw = implode("\n", $out);
        $start = strpos($raw, '[');
        $rows = ($code === 0 && $start !== false) ? json_decode(substr($raw, $start), true) : null;
        if (!is_array($rows)) {
            throw new WNBError('Không đọc được danh sách file trên cloud: {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
        $items = [];
        foreach ($rows as $r) {
            if (($r['Name'] ?? '') === '') {
                continue;
            }
            $items[] = [
                'name' => $r['Name'],
                'path' => ($rel === '' ? '' : $rel . '/') . $r['Name'],
                'type' => !empty($r['IsDir']) ? 'dir' : 'file',
                'size' => (int)($r['Size'] ?? 0),
                'mtime' => isset($r['ModTime']) ? date('Y-m-d H:i:s', strtotime($r['ModTime'])) : '',
            ];
        }
        return self::sortItems($items);
    }

    /** Liệt kê một thư mục của bản nén dựa vào file chỉ mục (không phải tải bản nén). */
    private static function listArchive(array $backup, string $rel): array
    {
        $fp = @fopen(WNBEngine::archiveIndex($backup), 'rb');
        if (!$fp) {
            throw new WNBError('Không đọc được danh sách file của bản sao lưu.');
        }
        $prefix = $rel === '' ? '' : $rel . '/';
        $found = $rel === '';
        $items = [];
        while (($line = fgets($fp)) !== false) {
            $e = self::parseIndexLine(rtrim($line, "\r\n"));
            if (!$e) {
                continue;
            }
            if ($e['path'] === $rel) {
                $found = true;
                continue;
            }
            if ($prefix !== '' && strpos($e['path'], $prefix) !== 0) {
                continue;
            }
            $found = true;
            $rest = substr($e['path'], strlen($prefix));
            $slash = strpos($rest, '/');
            if ($slash === false) {
                $items[$rest] = ['name' => $rest, 'path' => $e['path'], 'type' => $e['type'], 'size' => $e['size'], 'mtime' => $e['mtime']];
            } elseif (!isset($items[substr($rest, 0, $slash)])) {
                // Thư mục cha không có dòng riêng trong chỉ mục (hiếm): vẫn hiển thị.
                $name = substr($rest, 0, $slash);
                $items[$name] = ['name' => $name, 'path' => $prefix . $name, 'type' => 'dir', 'size' => 0, 'mtime' => ''];
            }
        }
        fclose($fp);
        if (!$found) {
            throw new WNBError('Thư mục không tồn tại trong bản sao lưu.');
        }
        return self::sortItems(array_values($items));
    }

    /**
     * Một dòng của `tar -cvv --quoting-style=literal`, vd:
     *   -rw-r--r-- 1000/1000      1234 2024-05-01 12:00 ./domains/a.vn/public_html/index.php
     *   lrwxrwxrwx 1000/1000         0 2024-05-01 12:00 ./public_html -> ./domains/a.vn/public_html
     * Trả về path (không có "./" và "/" cuối), type (dir|file|link), size, mtime; null nếu không phải dòng mục.
     */
    public static function parseIndexLine(string $line): ?array
    {
        if (!preg_match('#^([-dlhcbpsDCM])\S*\s+\S+\s+(\d+|\d+,\s*\d+)\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?)\s(.+)$#', $line, $m)) {
            return null;
        }
        $name = $m[4];
        if ($m[1] === 'l' && ($p = strpos($name, ' -> ')) !== false) {
            $name = substr($name, 0, $p);
        } elseif ($m[1] === 'h' && ($p = strpos($name, ' link to ')) !== false) {
            $name = substr($name, 0, $p);
        }
        if (strpos($name, './') === 0) {
            $name = substr($name, 2);
        }
        $name = rtrim($name, '/');
        if ($name === '' || $name === '.') {
            return null;
        }
        $type = $m[1] === 'd' ? 'dir' : ($m[1] === 'l' ? 'link' : 'file');
        return ['path' => $name, 'type' => $type, 'size' => $type === 'file' && strpos($m[2], ',') === false ? (int)$m[2] : 0, 'mtime' => strlen($m[3]) === 16 ? $m[3] . ':00' : $m[3]];
    }

    private static function sortItems(array $items): array
    {
        usort($items, function ($a, $b) {
            if (($a['type'] === 'dir') !== ($b['type'] === 'dir')) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $items;
    }
}

/**
 * Kho incremental bằng restic, đi qua rclone (restic -r rclone:<remote>:...) nên dùng được mọi loại kho cloud đã cấu hình.
 * Một kho restic cho mỗi "kho lưu trữ": dữ liệu trùng giữa các tài khoản và giữa các lần sao lưu chỉ lưu một lần.
 */
final class WNBRestic
{
    /** Cần --stdin-from-command (0.17) để dump database thẳng vào restic mà vẫn bắt được lỗi của mysqldump. */
    public const MIN_VERSION = '0.17.0';

    public static function binary(): string
    {
        return WNB::isPreview() ? '' : WNBShell::which('restic');
    }

    public static function version(): string
    {
        static $v = null;
        if ($v === null) {
            $v = '';
            if (self::binary() !== '' && WNBShell::run(escapeshellarg(self::binary()) . ' version', $out) === 0 && preg_match('/restic\s+([\d.]+)/', implode("\n", $out), $m)) {
                $v = $m[1];
            }
        }
        return $v;
    }

    public static function usable(): bool
    {
        return self::version() !== '' && version_compare(self::version(), self::MIN_VERSION, '>=');
    }

    public static function keyFile(string $destId): string
    {
        return WNBStorage::path('restic_' . preg_replace('/[^A-Za-z0-9_]/', '', $destId) . '.key');
    }

    public static function hasKey(string $destId): bool
    {
        return self::password($destId) !== '';
    }

    public static function password(string $destId): string
    {
        $f = self::keyFile($destId);
        return is_file($f) ? trim((string)@file_get_contents($f)) : '';
    }

    public static function setPassword(string $destId, string $password): void
    {
        $old = umask(0077);
        $ok = @file_put_contents(self::keyFile($destId), $password . "\n") !== false;
        umask($old);
        if (!$ok) {
            throw new WNBError('Không ghi được mật khẩu kho restic.');
        }
        @chmod(self::keyFile($destId), 0600);
    }

    public static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function repo(array $dest): string
    {
        return 'rclone:' . WNBRclone::join(WNBRclone::base($dest), 'restic');
    }

    /** Bộ đệm chỉ mục của restic: nằm trong thư mục lưu trữ, không làm đầy /root. */
    public static function cacheDir(): string
    {
        $dir = rtrim((string)WNBStorage::settings()['local_backup_root'], '/') . '/.restic-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /** Lệnh restic kèm kho, mật khẩu (qua file), cấu hình rclone, cache, thư mục tạm và giới hạn băng thông. */
    public static function cmd(array $dest, string $args, bool $bwlimit = false): string
    {
        $env = 'env RESTIC_REPOSITORY=' . escapeshellarg(self::repo($dest))
            . ' RESTIC_PASSWORD_FILE=' . escapeshellarg(self::keyFile((string)$dest['id']))
            . ' RCLONE_CONFIG=' . escapeshellarg(WNBRclone::configPath())
            . ' RESTIC_CACHE_DIR=' . escapeshellarg(self::cacheDir())
            . ' TMPDIR=' . escapeshellarg(WNBEngine::stagingRoot());
        $limit = '';
        $bw = trim((string)(WNBStorage::settings()['bwlimit'] ?? ''));
        if ($bwlimit && preg_match('/^([0-9.]+)([KMGkmg]?)$/', $bw, $m) && (float)$m[1] > 0) {
            $kib = (float)$m[1] * ['' => 1, 'k' => 1, 'm' => 1024, 'g' => 1048576][strtolower($m[2])];
            $limit = ' --limit-upload ' . max(1, (int)$kib);
        }
        return $env . ' ' . escapeshellarg(self::binary()) . ' -o ' . escapeshellarg('rclone.program=' . WNBRclone::binary()) . $limit . ' ' . $args;
    }

    /**
     * Mở kho, tạo mới nếu chưa có. Trả về 'exists' | 'created'. Sai mật khẩu với kho đã có thì báo lỗi rõ ràng.
     */
    public static function ensureRepo(array $dest): string
    {
        static $ok = [];
        if (isset($ok[$dest['id']])) {
            return 'exists';
        }
        $code = WNBShell::run(self::cmd($dest, 'cat config --no-lock'), $out);
        $txt = implode("\n", $out);
        if ($code === 0) {
            $ok[$dest['id']] = true;
            return 'exists';
        }
        if ($code === 12 || stripos($txt, 'wrong password') !== false) {
            throw new WNBError('Mật khẩu kho restic không đúng với kho đã có trên cloud.');
        }
        if ($code === 10 || preg_match('/does not exist|Is there a repository|unable to open config file|config: not found/i', $txt)) {
            if (WNBShell::run(self::cmd($dest, 'init --repository-version 2'), $out) !== 0) {
                throw new WNBError('Không tạo được kho restic: {detail}', ['detail' => WNBShell::tail($out, 3)]);
            }
            $ok[$dest['id']] = true;
            return 'created';
        }
        throw new WNBError('Không kết nối được kho restic: {detail}', ['detail' => WNBShell::tail($out, 3)]);
    }

    /** Đọc output --json của restic backup: summary (có snapshot_id) và các lỗi. */
    public static function parseJson(array $out): array
    {
        $res = ['summary' => null, 'errors' => []];
        foreach ($out as $line) {
            $line = trim($line);
            $j = ($line !== '' && $line[0] === '{') ? json_decode($line, true) : null;
            if (!is_array($j)) {
                if ($line !== '') {
                    $res['errors'][] = $line;
                }
                continue;
            }
            $type = $j['message_type'] ?? '';
            if ($type === 'summary') {
                $res['summary'] = $j;
            } elseif ($type === 'error' || $type === 'exit_error') {
                $res['errors'][] = (string)($j['error']['message'] ?? $j['message'] ?? '') . (isset($j['item']) ? ' (' . $j['item'] . ')' : '');
            }
        }
        return $res;
    }

    /** Chuẩn hóa thời điểm RFC3339 của restic (có phần lẻ nano giây) thành Y-m-d H:i:s giờ máy chủ. */
    public static function time(string $t): string
    {
        $ts = strtotime((string)preg_replace('/\.\d+/', '', $t));
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }

    /** Thoát ký tự đại diện để --include khớp đúng tên file (restic dùng cú pháp filepath.Match). */
    public static function literalPattern(string $path): string
    {
        return (string)preg_replace('/([*?\[\]\\\\])/', '\\\\$1', $path);
    }

    /** Liệt kê một thư mục trong home của bản sao lưu (restic ls). */
    public static function listDir(array $dest, array $b, string $rel): array
    {
        $base = rtrim((string)$b['restic']['home_path'], '/');
        $dir = $rel === '' ? $base : $base . '/' . $rel;
        $code = WNBShell::run(self::cmd($dest, 'ls --json --no-lock ' . escapeshellarg((string)$b['restic']['home']) . ' ' . escapeshellarg($dir)), $out);
        if ($code !== 0) {
            throw new WNBError('Không đọc được danh sách file trên cloud: {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
        return self::nodesToItems($out, $dir, $rel);
    }

    /** Lọc các node con trực tiếp của $dir trong output `restic ls --json`. */
    public static function nodesToItems(array $out, string $dir, string $rel): array
    {
        $items = [];
        $found = false;
        foreach ($out as $line) {
            $j = json_decode(trim($line), true);
            if (!is_array($j) || !isset($j['path'], $j['type']) || ($j['struct_type'] ?? $j['message_type'] ?? 'node') !== 'node') {
                continue;
            }
            if ($j['path'] === $dir) {
                $found = true;
                continue;
            }
            if (dirname($j['path']) !== $dir) {
                continue;
            }
            $found = true;
            $type = $j['type'] === 'dir' ? 'dir' : ($j['type'] === 'symlink' ? 'link' : 'file');
            $items[] = [
                'name' => (string)($j['name'] ?? basename($j['path'])),
                'path' => ($rel === '' ? '' : $rel . '/') . ($j['name'] ?? basename($j['path'])),
                'type' => $type,
                'size' => $type === 'file' ? (int)($j['size'] ?? 0) : 0,
                'mtime' => self::time((string)($j['mtime'] ?? '')),
            ];
        }
        if (!$found) {
            throw new WNBError('Thư mục không tồn tại trong bản sao lưu.');
        }
        return $items;
    }

    /** Các snapshot của một bản sao lưu (home + từng database). */
    public static function snapshotIds(array $b): array
    {
        $r = (array)($b['restic'] ?? []);
        return array_values(array_filter(array_merge([(string)($r['home'] ?? '')], array_map('strval', array_values((array)($r['dbs'] ?? [])))), fn($id) => (bool)preg_match('/^[0-9a-f]{8,64}$/', $id)));
    }

    public static function forget(array $dest, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $code = WNBShell::run(self::cmd($dest, 'forget --retry-lock 10m ' . implode(' ', array_map('escapeshellarg', $ids))), $out);
        if ($code !== 0 && !preg_match('/no matching ID found|not found/i', implode("\n", $out))) {
            throw new WNBError('Không xóa được bản sao lưu trên cloud: {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
    }

    /**
     * Dựng lại bản ghi catalog từ snapshot trong kho (khi mất dữ liệu plugin, cài lại máy chủ).
     * Mỗi bản sao lưu gồm snapshot home (tag part:home) và các snapshot database (tag db:<tên>), chung tag b:<id>.
     */
    public static function recordsFromSnapshots(array $snaps, array $dest, array $knownIds): array
    {
        $known = array_flip($knownIds);
        $groups = [];
        foreach ($snaps as $s) {
            $t = [];
            foreach ((array)($s['tags'] ?? []) as $tag) {
                if (preg_match('/^([a-z]+):(.*)$/', (string)$tag, $m)) {
                    $t[$m[1]][] = $m[2];
                } else {
                    $t[(string)$tag][] = '';
                }
            }
            $bid = $t['b'][0] ?? '';
            if (!isset($t['wnb']) || !preg_match('/^(bkp|snap)_[A-Za-z0-9_\-]+$/', $bid) || isset($known[$bid])) {
                continue;
            }
            $g = $groups[$bid] ?? ['u' => $t['u'][0] ?? '', 't' => $t['t'][0] ?? 'backup', 'j' => $t['j'][0] ?? '', 'dbs' => [], 'home' => null];
            $id = (string)($s['id'] ?? '');
            if (isset($t['db'])) {
                $g['dbs'][$t['db'][0]] = $id;
            } elseif (in_array('home', $t['part'] ?? [], true)) {
                $g['home'] = ['id' => $id, 'hp' => $t['hp'][0] ?? '', 'mp' => $t['mp'][0] ?? '', 'time' => self::time((string)($s['time'] ?? '')), 'host' => (string)($s['hostname'] ?? '')];
            }
            $groups[$bid] = $g;
        }
        $records = [];
        foreach ($groups as $bid => $g) {
            if (!$g['home'] || !WNBDirectAdmin::validUsername($g['u'])) {
                continue;
            }
            $records[] = [
                'id' => $bid,
                'username' => $g['u'],
                'reseller' => '',
                // Tài khoản cùng tên hiện tại có thể là khách khác: chỉ admin thấy bản nạp lại.
                'owner_key' => 'imported',
                'domain' => '',
                'domains' => [],
                'type' => $g['t'] === 'snapshot' ? 'snapshot' : 'backup',
                'destination_id' => $dest['id'],
                'destination_name' => $dest['name'],
                'destination_type' => $dest['type'],
                'format' => 'restic',
                'home_archive' => true,
                'job_id' => $g['j'],
                'note' => '',
                'databases' => array_keys($g['dbs']),
                'emails' => [],
                'crons' => [],
                'incremental' => true,
                'size_mb' => 0,
                'new_mb' => 0,
                'warnings' => [],
                'imported' => true,
                'created_at' => $g['home']['time'],
                'storage_path' => self::repo($dest),
                'restic' => ['home' => $g['home']['id'], 'dbs' => $g['dbs'], 'home_path' => $g['home']['hp'], 'meta_path' => $g['home']['mp'], 'host' => $g['home']['host']],
            ];
        }
        return $records;
    }
}

/**
 * Kết nối Google Drive / OneDrive bằng nút bấm, không phải gõ lệnh.
 * Chạy `rclone authorize` trên máy chủ (dùng app OAuth có sẵn của rclone, không cần đăng ký gì thêm). Trình duyệt của
 * admin mở trang đăng nhập; sau khi cho phép, nhà cung cấp chuyển về http://127.0.0.1:53682/?code=... và trang đó báo
 * "không truy cập được" vì cổng này nằm trên máy chủ. Admin dán lại địa chỉ đó, máy chủ tự gọi vào rclone đang chờ,
 * rclone đổi code lấy token. Token giữ ở máy chủ (vé dùng một lần cho dest_save), không bao giờ gửi về trình duyệt.
 */
final class WNBOAuth
{
    public const KINDS = ['drive', 'onedrive'];
    private const PORT = 53682;
    private const TIMEOUT = 900;
    private const TICKET_TTL = 3600;
    /** Trang đăng nhập hợp lệ mà rclone chuyển tới. */
    private const AUTH_HOSTS = ['accounts.google.com', 'login.microsoftonline.com', 'login.live.com'];

    private static function stateFile(): string
    {
        return WNBStorage::path('oauth.json');
    }

    private static function logFile(): string
    {
        return WNBStorage::path('oauth.log');
    }

    private static function load(): array
    {
        $f = self::stateFile();
        $s = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($s) ? $s : [];
    }

    private static function save(array $s): void
    {
        $old = umask(0077);
        @file_put_contents(self::stateFile(), json_encode($s, JSON_UNESCAPED_SLASHES));
        umask($old);
    }

    /** Bắt đầu: chạy rclone authorize nền, trả về địa chỉ trang đăng nhập của Google/Microsoft. */
    public static function start(string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new WNBError('Loại kho không hợp lệ.');
        }
        self::stop(true);
        if (WNB::isPreview()) {
            $state = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            self::save(['kind' => $kind, 'state' => $state, 'pid' => 0, 'started' => time()]);
            $url = $kind === 'drive'
                ? 'https://accounts.google.com/o/oauth2/auth?client_id=demo&redirect_uri=http%3A%2F%2F127.0.0.1%3A53682%2F&state=' . $state
                : 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=demo&redirect_uri=http%3A%2F%2Flocalhost%3A53682%2F&state=' . $state;
            return ['url' => $url];
        }
        if (!WNBRclone::installed()) {
            throw new WNBError('Chưa cài rclone nên không sao lưu lên cloud được.');
        }
        $curl = WNBShell::which('curl');
        if ($curl === '') {
            throw new WNBError('Máy chủ thiếu curl nên không kết nối được.');
        }
        self::freePort();
        $log = self::logFile();
        $setsid = WNBShell::which('setsid');
        $timeout = WNBShell::which('timeout');
        $cmd = ($setsid !== '' ? escapeshellarg($setsid) . ' ' : '') . ($timeout !== '' ? escapeshellarg($timeout) . ' ' . self::TIMEOUT . ' ' : '')
            . WNBRclone::cmd('authorize ' . escapeshellarg($kind) . ' --auth-no-open-browser');
        $old = umask(0077);
        WNBShell::bash($cmd . ' </dev/null >' . escapeshellarg($log) . ' 2>&1 & echo $!', $out);
        umask($old);
        $pid = (int)trim((string)($out[0] ?? ''));
        self::save(['kind' => $kind, 'pid' => $pid, 'started' => time()]);

        // rclone in ra http://127.0.0.1:53682/auth?state=... rồi chờ.
        $local = '';
        $state = '';
        for ($i = 0; $i < 40 && $local === ''; $i++) {
            usleep(250000);
            $txt = (string)@file_get_contents($log);
            if (preg_match('#http://(?:127\.0\.0\.1|localhost):' . self::PORT . '/auth\?state=([A-Za-z0-9_\-]+)#', $txt, $m)) {
                $local = $m[0];
                $state = $m[1];
            } elseif (stripos($txt, 'address already in use') !== false) {
                self::stop(true);
                throw new WNBError('Cổng {port} trên máy chủ đang bận, có thể do một lệnh rclone authorize khác đang chạy. Hãy dừng lệnh đó (Ctrl+C) rồi bấm Kết nối lại.', ['port' => self::PORT]);
            } elseif (!self::alive($pid)) {
                self::stop(true);
                throw new WNBError('rclone authorize dừng bất thường: {detail}', ['detail' => WNBShell::tail(explode("\n", $txt), 2)]);
            }
        }
        if ($local === '') {
            self::stop(true);
            throw new WNBError('rclone authorize không phản hồi, hãy thử lại.');
        }
        // Trang /auth của rclone chuyển hướng tới trang đăng nhập thật: lấy địa chỉ đó cho trình duyệt.
        WNBShell::run(escapeshellarg($curl) . " -s -o /dev/null --max-time 10 -w '%{redirect_url}' " . escapeshellarg($local), $out);
        $url = trim(implode('', $out));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array((string)parse_url($url, PHP_URL_HOST), self::AUTH_HOSTS, true)) {
            self::stop(true);
            throw new WNBError('Không lấy được trang đăng nhập từ rclone, hãy thử lại.');
        }
        self::save(['kind' => $kind, 'pid' => $pid, 'state' => $state, 'started' => time()]);
        return ['url' => $url];
    }

    /**
     * Hoàn tất: nhận địa chỉ trang "không truy cập được" admin dán vào, chuyển code cho rclone đang chờ, nhận token.
     * @return array{ticket:string,account:string}
     */
    public static function finish(string $kind, string $pasted): array
    {
        $s = self::load();
        if (($s['kind'] ?? '') !== $kind || empty($s['state']) || time() - (int)($s['started'] ?? 0) > self::TIMEOUT) {
            throw new WNBError('Phiên kết nối đã hết hạn, hãy bấm Kết nối lại.');
        }
        $q = self::parseRedirect($pasted);
        if (!hash_equals((string)$s['state'], $q['state'])) {
            throw new WNBError('Địa chỉ này không thuộc lần kết nối hiện tại. Hãy bấm Kết nối lại rồi dán địa chỉ mới nhất.');
        }
        if (WNB::isPreview()) {
            $token = (string)json_encode(['access_token' => 'demo-' . bin2hex(random_bytes(6)), 'token_type' => 'Bearer', 'refresh_token' => 'demo-refresh', 'expiry' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
            $extra = $kind === 'onedrive' ? ['drive_id' => 'b!demo' . bin2hex(random_bytes(4)), 'drive_type' => 'personal'] : [];
            $account = $kind === 'drive' ? 'demo@gmail.com' : 'Demo OneDrive';
        } else {
            if (!self::alive((int)($s['pid'] ?? 0))) {
                throw new WNBError('Phiên kết nối đã hết hạn, hãy bấm Kết nối lại.');
            }
            WNBShell::run(escapeshellarg(WNBShell::which('curl')) . ' -s -o /dev/null --max-time 30 ' . escapeshellarg('http://127.0.0.1:' . self::PORT . '/?' . http_build_query($q['params'])), $out);
            $token = '';
            for ($i = 0; $i < 60 && $token === ''; $i++) {
                $token = self::extractToken((string)@file_get_contents(self::logFile()));
                if ($token === '') {
                    if (!self::alive((int)$s['pid'])) {
                        break;
                    }
                    usleep(500000);
                }
            }
            if ($token === '') {
                $detail = WNBShell::tail(explode("\n", (string)@file_get_contents(self::logFile())), 2);
                self::stop(true);
                throw new WNBError('Không nhận được token từ rclone: {detail}', ['detail' => $detail]);
            }
            [$account, $extra] = self::accountInfo($kind, (string)(json_decode($token, true)['access_token'] ?? ''));
        }
        self::stop(true);
        $ticket = bin2hex(random_bytes(12));
        self::save(['ticket' => $ticket, 'kind' => $kind, 'token' => $token, 'extra' => $extra, 'account' => $account, 'expires' => time() + self::TICKET_TTL]);
        return ['ticket' => $ticket, 'account' => $account];
    }

    /** Lấy token + tham số kèm theo của vé (dùng một lần). */
    public static function consume(string $ticket, string $kind): array
    {
        $s = self::load();
        if ($ticket === '' || empty($s['ticket']) || !hash_equals((string)$s['ticket'], $ticket) || ($s['kind'] ?? '') !== $kind || time() > (int)($s['expires'] ?? 0)) {
            throw new WNBError('Phiên kết nối đã hết hạn, hãy bấm Kết nối lại.');
        }
        @unlink(self::stateFile());
        return ['token' => (string)$s['token'], 'account' => (string)($s['account'] ?? '')] + (array)($s['extra'] ?? []);
    }

    /**
     * Đọc địa chỉ admin dán vào (cả URL hoặc chỉ phần code=...&state=...). Chỉ giữ tham số có tên đơn giản để gửi lại cho rclone.
     * @return array{state:string,params:array}
     */
    public static function parseRedirect(string $pasted): array
    {
        $pasted = trim($pasted);
        $query = (string)(parse_url($pasted, PHP_URL_QUERY) ?? '');
        if ($query === '' && strpos($pasted, '=') !== false && strpos($pasted, '://') === false) {
            $query = ltrim($pasted, '?');
        }
        parse_str($query, $all);
        $params = [];
        foreach ($all as $k => $v) {
            if (is_string($v) && preg_match('/^[a-z_]{1,40}$/', (string)$k) && strlen($v) <= 4096) {
                $params[$k] = $v;
            }
        }
        if (isset($params['error'])) {
            throw new WNBError('Chưa được cấp quyền: {detail}', ['detail' => $params['error_description'] ?? $params['error']]);
        }
        if (($params['code'] ?? '') === '' || ($params['state'] ?? '') === '') {
            throw new WNBError('Địa chỉ dán vào không có mã xác nhận. Hãy copy toàn bộ thanh địa chỉ của trang hiện ra sau khi bấm Cho phép (bắt đầu bằng http://127.0.0.1:53682/ hoặc http://localhost:53682/).');
        }
        return ['state' => $params['state'], 'params' => $params];
    }

    /** Token JSON trong output của rclone authorize (giữa "--->" và "<---"; có bản in dạng base64 của {"token":"..."}). */
    public static function extractToken(string $txt): string
    {
        if (!preg_match('/--->\s*(.+?)\s*<---/s', $txt, $m)) {
            return '';
        }
        $raw = trim($m[1]);
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            $dec = base64_decode(strtr($raw, '-_', '+/'), true);
            $j = $dec !== false ? json_decode($dec, true) : null;
            if (is_array($j) && isset($j['token']) && is_string($j['token'])) {
                $j = json_decode($j['token'], true);
            }
        }
        if (!is_array($j) || empty($j['access_token'])) {
            return '';
        }
        return (string)json_encode($j, JSON_UNESCAPED_SLASHES);
    }

    /** Tên tài khoản để hiển thị; OneDrive cần thêm drive_id/drive_type cho rclone. */
    private static function accountInfo(string $kind, string $access): array
    {
        $url = $kind === 'drive' ? 'https://www.googleapis.com/drive/v3/about?fields=user' : 'https://graph.microsoft.com/v1.0/me/drive';
        // Token đi qua stdin (-K -) để không lộ trên danh sách tiến trình.
        $cfg = 'url = "' . $url . '"' . "\n" . 'header = "Authorization: Bearer ' . str_replace(['"', '\\', "\n", "\r"], '', $access) . '"' . "\nmax-time = 20\nsilent\n";
        WNBShell::runWithInput(escapeshellarg(WNBShell::which('curl')) . ' -K -', $cfg, $out);
        $j = json_decode(implode("\n", $out), true);
        if ($kind === 'drive') {
            return [(string)($j['user']['emailAddress'] ?? ''), []];
        }
        if (empty($j['id'])) {
            throw new WNBError('Đã đăng nhập nhưng không đọc được OneDrive của tài khoản này: {detail}', ['detail' => (string)($j['error']['message'] ?? substr(implode(' ', $out), 0, 160))]);
        }
        $name = (string)($j['owner']['user']['email'] ?? $j['owner']['user']['displayName'] ?? '');
        return [$name, ['drive_id' => (string)$j['id'], 'drive_type' => (string)($j['driveType'] ?? 'personal')]];
    }

    /**
     * Dừng mọi `rclone authorize` còn sót (lần bấm trước, hoặc admin chạy tay trong terminal rồi bỏ đó) và đợi cổng trống.
     * Cổng vẫn bị chương trình khác giữ thì báo lỗi.
     */
    private static function freePort(): void
    {
        $pids = self::strayPids();
        if ($pids) {
            // Lệnh bị Ctrl+Z (Stopped) chỉ nhận TERM sau khi được CONT.
            WNBShell::run('kill -TERM ' . implode(' ', $pids) . '; kill -CONT ' . implode(' ', $pids));
        }
        for ($i = 0; $i < 20 && (self::strayPids() || self::portListening()); $i++) {
            usleep(250000);
            if ($i === 11 && ($left = self::strayPids())) {
                WNBShell::run('kill -9 ' . implode(' ', $left));
            }
        }
        if (self::portListening()) {
            throw new WNBError('Cổng {port} trên máy chủ đang bị một chương trình khác dùng nên không kết nối được. Kiểm tra bằng lệnh: ss -ltnp | grep {port}', ['port' => self::PORT]);
        }
    }

    /** @return int[] tiến trình rclone authorize (kể cả tiến trình timeout bọc ngoài) */
    private static function strayPids(): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
            $args = explode("\0", trim((string)@file_get_contents($f), "\0"));
            $pid = (int)basename(dirname($f));
            if ($pid === getmypid() || !in_array('authorize', $args, true)) {
                continue;
            }
            foreach ($args as $a) {
                if (basename($a) === 'rclone') {
                    $pids[] = $pid;
                    break;
                }
            }
        }
        return $pids;
    }

    private static function portListening(): bool
    {
        $hex = ':' . strtoupper(dechex(self::PORT));
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
            foreach (@file($f) ?: [] as $line) {
                $c = preg_split('/\s+/', trim($line));
                if (isset($c[3]) && $c[3] === '0A' && substr($c[1], -strlen($hex)) === $hex) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function alive(int $pid): bool
    {
        return $pid > 0 && strpos((string)@file_get_contents('/proc/' . $pid . '/cmdline'), 'authorize') !== false;
    }

    /** Dừng rclone authorize đang chờ (nếu có) và xóa log. $all = xóa luôn vé chưa dùng. */
    public static function stop(bool $all = false): void
    {
        $s = self::load();
        $pid = (int)($s['pid'] ?? 0);
        if (!WNB::isPreview() && self::alive($pid)) {
            // timeout chuyển tín hiệu cho rclone con.
            WNBShell::run('kill ' . $pid);
        }
        @unlink(self::logFile());
        if ($all || empty($s['ticket'])) {
            @unlink(self::stateFile());
        }
    }
}
