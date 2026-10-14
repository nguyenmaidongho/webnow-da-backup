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
     * 'folder' = cây thư mục từng file (duyệt/khôi phục từng file trực tiếp nhưng tải lên chậm). Kho local luôn là 'folder'.
     */
    public static function destFormat(array $dest): string
    {
        if (($dest['type'] ?? 'local') === 'local' || !self::supportsArchive((string)$dest['type'])) {
            return 'folder';
        }
        return ($dest['format'] ?? 'archive') === 'folder' ? 'folder' : 'archive';
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

        $sections = self::parseConfig();
        $sections[$name] = ['type' => self::TYPES[$kind]['type']] + $params;
        self::writeConfig($sections);
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

        if (($backup['format'] ?? 'folder') === 'archive') {
            return self::listArchive($backup, $rel);
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
