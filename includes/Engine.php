<?php
/**
 * WebNowBackup - engine: hàng đợi, worker, sao lưu, khôi phục, đóng gói tải về, xóa, retention, lịch, thông báo.
 * Chạy với quyền root (helper/cron/worker) hoặc trong preview.
 *
 * Nguyên tắc an toàn:
 *  - Mọi lệnh đều kiểm tra exit code; tác vụ chỉ "hoàn tất" khi bước chính thành công, lỗi phụ ghi thành cảnh báo.
 *  - Không bao giờ ghi vào home của khách bằng root: dữ liệu được dựng ở vùng staging của root,
 *    sau đó rsync vào home BẰNG CHÍNH USER đó (symlink hay đường dẫn lừa không vượt quyền được).
 *  - Thông báo/cảnh báo lưu dạng khóa dịch (WNBLang::msg) để hiển thị theo ngôn ngữ người xem.
 */

final class WNBEngine
{
    /* ============================== Hàng đợi ============================== */

    public static function enqueue(array $p): array
    {
        $task = [
            'id' => WNB::newId('task'),
            'type' => $p['type'],
            'username' => $p['username'] ?? '',
            'initiated_by' => $p['initiated_by'] ?? 'system',
            'role' => $p['role'] ?? 'admin',
            'backup_id' => $p['backup_id'] ?? '',
            'destination_id' => $p['destination_id'] ?? '',
            'job_id' => $p['job_id'] ?? '',
            'job_run' => $p['job_run'] ?? '',
            'scope' => $p['scope'] ?? '',
            'items' => array_values($p['items'] ?? []),
            'note' => $p['note'] ?? '',
            'lang' => WNBLang::get(),
            'status' => 'pending',
            'progress' => 0,
            'message' => WNBLang::msg('Đang chờ'),
            'warnings' => 0,
            'created_at' => WNB::now(),
            'started_at' => null,
            'finished_at' => null,
            'pid' => 0,
        ];
        WNBStorage::update('queue', function ($q) use ($task) {
            array_unshift($q, $task);
            return array_slice($q, 0, 1000);
        });
        WNBStorage::log($task['id'], wnb_t('Tạo tác vụ {type} cho {user} (bởi {by}/{role}).', ['type' => $task['type'], 'user' => $task['username'], 'by' => $task['initiated_by'], 'role' => $task['role']]));
        return $task;
    }

    /** Khởi động worker nền (root) để xử lý hàng đợi. Preview: xử lý ngay trong request. */
    public static function kick(): void
    {
        if (WNB::isPreview()) {
            self::worker();
            return;
        }
        $cli = WNB_ROOT . '/daemon/webnowbackup-cli.php';
        $setsid = WNBShell::which('setsid');
        $pending = count(array_filter(WNBStorage::read('queue'), fn($t) => $t['status'] === 'pending'));
        $workers = min(max(1, $pending), max(1, min(8, (int)WNBStorage::settings()['max_concurrent_tasks'])));
        // Mỗi worker tự giữ một slot; worker dư sẽ thoát ngay nên gọi thừa không sao.
        for ($i = 0; $i < $workers; $i++) {
            @exec(sprintf('%s%s -n %s worker </dev/null >/dev/null 2>&1 &', $setsid !== '' ? escapeshellarg($setsid) . ' ' : '', escapeshellarg(WNB::phpBinary()), escapeshellarg($cli)));
        }
    }

    /** Một worker giữ một "slot"; số slot = số tác vụ chạy song song tối đa. */
    public static function worker(): void
    {
        $max = max(1, min(8, (int)WNBStorage::settings()['max_concurrent_tasks']));
        $slot = null;
        for ($i = 0; $i < $max && !$slot; $i++) {
            $slot = WNBStorage::lock('worker_' . $i, false);
        }
        if (!$slot) {
            return;
        }
        try {
            while ($task = self::claimNext()) {
                self::execute($task);
            }
        } finally {
            WNBStorage::unlock($slot);
        }
    }

    private static function claimNext(): ?array
    {
        $claimed = null;
        WNBStorage::update('queue', function ($q) use (&$claimed) {
            for ($i = count($q) - 1; $i >= 0; $i--) {
                if (($q[$i]['status'] ?? '') === 'pending') {
                    $q[$i]['status'] = 'running';
                    $q[$i]['started_at'] = WNB::now();
                    $q[$i]['pid'] = getmypid();
                    $q[$i]['message'] = WNBLang::msg('Đang chạy');
                    $claimed = $q[$i];
                    return $q;
                }
            }
            return null;
        });
        return $claimed;
    }

    /** Tác vụ "running" mà tiến trình đã chết (server reboot, bị kill) -> đánh dấu lỗi. */
    public static function recoverStale(): void
    {
        if (WNB::isPreview() || !is_dir('/proc')) {
            return;
        }
        $jobs = [];
        WNBStorage::update('queue', function ($q) use (&$jobs) {
            $changed = false;
            foreach ($q as &$t) {
                if (($t['status'] ?? '') === 'running' && (int)($t['pid'] ?? 0) > 0 && !is_dir('/proc/' . (int)$t['pid'])) {
                    $t['status'] = 'failed';
                    $t['message'] = WNBLang::msg('Tiến trình bị dừng đột ngột (máy chủ khởi động lại hoặc bị kill).');
                    $t['finished_at'] = WNB::now();
                    $changed = true;
                    if (!empty($t['job_id'])) {
                        $jobs[$t['job_id']] = true;
                    }
                }
            }
            return $changed ? $q : null;
        });
        foreach (array_keys($jobs) as $jobId) {
            self::maybeJobReport($jobId);
        }
    }

    public static function execute(array $task): void
    {
        $id = $task['id'];
        WNBLang::set($task['lang'] ?? (string)WNBStorage::settings()['language']);
        try {
            switch ($task['type']) {
                case 'backup':
                case 'snapshot':
                    $res = self::backup($task);
                    break;
                case 'restore':
                    $res = self::restore($task);
                    break;
                case 'download':
                    $res = self::download($task);
                    break;
                case 'delete':
                    $res = self::deleteBackupTask($task);
                    break;
                case 'maintain':
                    $res = self::maintainRepo($task);
                    break;
                case 'rescan':
                    $res = self::rescanRepo($task);
                    break;
                default:
                    throw new WNBError('Loại tác vụ không hợp lệ.');
            }
            $warn = $res['warnings'] ?? [];
            foreach ($warn as $w) {
                WNBStorage::log($id, wnb_t('CẢNH BÁO') . ': ' . WNBLang::render($w));
            }
            self::setTask($id, ['status' => 'completed', 'progress' => 100, 'message' => $res['message'], 'warnings' => count($warn), 'finished_at' => WNB::now()]);
            WNBStorage::log($id, wnb_t('HOÀN TẤT') . ': ' . WNBLang::render($res['message']));
        } catch (Throwable $e) {
            $msg = $e instanceof WNBError ? $e->msg() : WNBLang::msg('{detail}', ['detail' => $e->getMessage()]);
            WNBStorage::log($id, wnb_t('LỖI') . ': ' . $e->getMessage());
            self::setTask($id, ['status' => 'failed', 'progress' => 100, 'message' => $msg, 'finished_at' => WNB::now()]);
            // Tác vụ thuộc lịch được gom vào báo cáo của lượt chạy, không gửi lẻ từng cái.
            if (empty($task['job_id'])) {
                self::notifyFailure($task, $msg);
            }
        }
        if (!empty($task['job_id'])) {
            self::maybeJobReport($task['job_id']);
        }
    }

    private static function setTask(string $id, array $changes): void
    {
        WNBStorage::update('queue', function ($q) use ($id, $changes) {
            foreach ($q as &$t) {
                if (($t['id'] ?? '') === $id) {
                    $t = array_merge($t, $changes);
                    return $q;
                }
            }
            return null;
        });
    }

    private static function step(array $task, int $pct, string $key, array $vars = []): void
    {
        WNBStorage::log($task['id'], wnb_t($key, $vars));
        self::setTask($task['id'], ['progress' => $pct, 'message' => WNBLang::msg($key, $vars)]);
    }

    /* ============================== Tiện ích ============================== */

    /** Chuẩn hóa đường dẫn tương đối trong home; null nếu có '..' hoặc ký tự lạ. */
    public static function cleanRelPath(string $p): ?string
    {
        $p = str_replace('\\', '/', $p);
        if (strpos($p, "\0") !== false) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    public static function findBackup(string $id): ?array
    {
        foreach (WNBStorage::read('backups') as $b) {
            if (($b['id'] ?? '') === $id) {
                return $b;
            }
        }
        return null;
    }

    /** Bản sao lưu đang được khôi phục/đóng gói/xóa thì không được xóa. */
    public static function backupBusy(string $id): bool
    {
        foreach (WNBStorage::read('queue') as $t) {
            if (($t['backup_id'] ?? '') === $id && in_array($t['status'], ['pending', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Kho đang có tác vụ chờ hoặc đang chạy (sao lưu, khôi phục, tải về, xóa, bảo trì...). */
    public static function destinationBusy(string $destId): bool
    {
        foreach (WNBStorage::read('queue') as $t) {
            if (($t['destination_id'] ?? '') === $destId && in_array($t['status'], ['pending', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Gỡ khỏi danh sách mọi bản sao lưu của một kho (không chạm vào dữ liệu trên kho). Trả về số bản đã gỡ. */
    public static function detachDestination(string $destId): int
    {
        $n = 0;
        WNBStorage::update('backups', function ($list) use ($destId, &$n) {
            $keep = array_values(array_filter($list, fn($b) => ($b['destination_id'] ?? '') !== $destId));
            $n = count($list) - count($keep);
            return $keep;
        });
        return $n;
    }

    /** Tài khoản đang có tác vụ sao lưu/khôi phục chờ hoặc đang chạy. */
    public static function accountBusy(string $u): bool
    {
        foreach (WNBStorage::read('queue') as $t) {
            if ($t['username'] === $u && in_array($t['type'], ['backup', 'snapshot', 'restore'], true) && in_array($t['status'], ['pending', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function findDestination(string $id): ?array
    {
        foreach (WNBStorage::read('destinations') as $d) {
            if (($d['id'] ?? '') === $id) {
                return $d;
            }
        }
        return null;
    }

    /** Vùng làm việc của root nằm trong thư mục lưu trữ (không làm đầy phân vùng hệ thống). */
    private static function workRoot(string $name, int $mode): string
    {
        $base = rtrim((string)WNBStorage::settings()['local_backup_root'], '/');
        self::mkdirSecure($base, 0711);
        self::mkdirSecure($base . '/' . $name, $mode);
        return $base . '/' . $name;
    }

    /** Vùng staging của root: chỉ root tạo/xóa, user chỉ đi xuyên qua (0711). */
    public static function stagingRoot(): string
    {
        return self::workRoot('.staging', 0711);
    }

    /** File .tar.gz chờ tải về: chỉ root đọc, phục vụ qua helper. */
    public static function downloadsRoot(): string
    {
        return WNB::isPreview() ? WNBStorage::path('downloads') : self::workRoot('.downloads', 0700);
    }

    private static function mkdirSecure(string $path, int $mode): void
    {
        if (is_link($path)) {
            throw new WNBError('Đường dẫn {path} là symlink, từ chối sử dụng.', ['path' => $path]);
        }
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new WNBError('Không tạo được thư mục {path}.', ['path' => $path]);
        }
        @chmod($path, $mode);
    }

    private static function rmrf(string $path): void
    {
        if ($path === '' || $path === '/' || (!file_exists($path) && !is_link($path))) {
            return;
        }
        if (!WNB::isPreview()) {
            WNBShell::run('rm -rf ' . escapeshellarg($path));
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $e) {
                if ($e !== '.' && $e !== '..') {
                    self::rmrf($path . '/' . $e);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    private static function duMb(string ...$paths): array
    {
        WNBShell::run('du -sk ' . implode(' ', array_map('escapeshellarg', $paths)), $out);
        $sizes = [];
        foreach ($out as $line) {
            if (preg_match('/^(\d+)\s/', $line, $m)) {
                $sizes[] = round($m[1] / 1024, 1);
            }
        }
        return $sizes;
    }

    private static function excludeArgs(): string
    {
        $args = '';
        foreach (preg_split('/\r?\n/', (string)WNBStorage::settings()['excludes']) as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '' && strpos($pattern, '..') === false) {
                $args .= ' --exclude=' . escapeshellarg('/' . ltrim($pattern, '/'));
            }
        }
        return $args;
    }

    /* ============================== Sao lưu ============================== */

    private static function backup(array $task): array
    {
        $u = $task['username'];
        $acc = WNBDirectAdmin::account($u, true);
        if (!$acc) {
            throw new WNBError('Tài khoản {user} không tồn tại.', ['user' => $u]);
        }
        $dest = self::findDestination($task['destination_id']);
        if (!$dest) {
            throw new WNBError('Kho lưu trữ đã bị xóa.');
        }
        $isSnap = $task['type'] === 'snapshot';
        $id = ($isSnap ? 'snap_' : 'bkp_') . $u . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2));
        $warnings = [];

        if (WNB::isPreview()) {
            return self::simulateBackup($task, $acc, $dest, $id);
        }

        $isLocal = $dest['type'] === 'local';
        if (!$isLocal && !WNBRclone::installed()) {
            throw new WNBError('Chưa cài rclone nên không sao lưu lên cloud được.');
        }
        if (!$isLocal && WNBRclone::destFormat($dest) === 'restic') {
            return self::backupRestic($task, $acc, $dest, $id, $isSnap);
        }
        // Chế độ "Nén": home và database được nén rồi đẩy thẳng lên cloud theo luồng,
        // local chỉ giữ vài file cấu hình nhỏ -> vẫn sao lưu được khi ổ đĩa máy chủ gần đầy.
        $archive = !$isLocal && WNBRclone::destFormat($dest) === 'archive';
        $root = $isLocal ? rtrim($dest['path'], '/') : self::stagingRoot();
        self::mkdirSecure($root, 0711);

        // Không sao lưu khi kho gần đầy: tránh làm đầy ổ đĩa của máy chủ.
        $minFree = $archive ? self::ARCHIVE_MIN_FREE_GB : (float)WNBStorage::settings()['min_free_gb'];
        $free = @disk_free_space($root);
        if ($minFree > 0 && $free !== false && $free / 1073741824 < $minFree) {
            throw new WNBError('Kho chỉ còn {free} GB trống (tối thiểu {min} GB). Hãy dọn bớt hoặc giảm số bản giữ lại.', ['free' => round($free / 1073741824, 2), 'min' => $minFree]);
        }
        // Hết inode thì vẫn báo "No space left on device" dù còn nhiều GB trống.
        $inodes = self::freeInodes($root);
        if ($inodes !== null && $inodes < ($archive ? self::ARCHIVE_MIN_FREE_INODES : self::MIN_FREE_INODES)) {
            throw new WNBError('Ổ đĩa chứa {path} gần hết inode (chỉ còn {n} inode trống) nên không tạo thêm file được. Hãy xóa bớt file nhỏ (bản sao lưu cũ, session, mail queue) hoặc chuyển thư mục lưu trữ sang ổ khác.', ['path' => $root, 'n' => $inodes]);
        }

        self::mkdirSecure($root . '/' . $u, 0700);
        $work = $root . '/' . $u . '/' . $id;
        foreach (array_merge(['', '/config', '/emails', '/dns', '/cron'], $archive ? [] : ['/databases', '/homedir']) as $sub) {
            self::mkdirSecure($work . $sub, 0700);
        }
        $prefix = WNBShell::lowPriorityPrefix();
        $remote = $isLocal ? '' : WNBRclone::join(WNBRclone::base($dest), $u, $id);
        $homeArchived = false;

        try {
            // 1-2. Cấu hình DirectAdmin, cron, DNS zone, cấu hình email
            self::step($task, 5, 'Sao lưu cấu hình DirectAdmin');
            self::collectMeta($u, $acc, $work, $warnings);

            // 3. Database
            $dumped = [];
            if (!empty($acc['databases'])) {
                self::step($task, 15, 'Dump {n} database', ['n' => count($acc['databases'])]);
                $mysqldump = WNBShell::which('mysqldump');
                try {
                    $cnf = WNBDirectAdmin::mysqlDefaultsFile();
                    foreach ($acc['databases'] as $db) {
                        $file = $work . '/databases/' . $db . '.sql.gz';
                        $dump = sprintf(
                            '%s%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers --events --max-allowed-packet=1G %s | %s',
                            $prefix,
                            escapeshellarg($mysqldump ?: 'mysqldump'),
                            escapeshellarg($cnf),
                            escapeshellarg($db),
                            $prefix . self::compressor()
                        );
                        // Bản nén: dump đi thẳng lên cloud, không ghi ra đĩa local.
                        $code = WNBShell::bash($dump . ' | ' . ($archive
                            ? WNBRclone::cmd('rcat ' . escapeshellarg(WNBRclone::join($remote, 'databases', $db . '.sql.gz')) . self::STREAM_FLAGS, true)
                            : 'cat > ' . escapeshellarg($file)), $out);
                        if ($code === 0) {
                            $dumped[] = $db;
                        } elseif (self::outOfSpace($out)) {
                            throw self::diskFullError($root);
                        } else {
                            @unlink($file);
                            if ($archive) {
                                WNBShell::run(WNBRclone::cmd('deletefile ' . escapeshellarg(WNBRclone::join($remote, 'databases', $db . '.sql.gz'))));
                            }
                            $warnings[] = WNBLang::msg('Dump database {db} thất bại: {detail}', ['db' => $db, 'detail' => WNBShell::tail($out, 2)]);
                        }
                    }
                } catch (WNBError $e) {
                    if ($e->key === self::DISK_FULL) {
                        throw $e;
                    }
                    $warnings[] = WNBLang::msg('Bỏ qua database: {detail}', ['detail' => $e->getMessage()]);
                } catch (Throwable $e) {
                    $warnings[] = WNBLang::msg('Bỏ qua database: {detail}', ['detail' => $e->getMessage()]);
                } finally {
                    if (isset($cnf)) {
                        @unlink($cnf);
                    }
                }
            }

            // 5. Home directory: kho local incremental bằng hardlink; kho cloud "Nén" thì nén thẳng lên cloud.
            self::step($task, 35, $archive ? 'Nén thư mục home lên {dest}' : 'Đồng bộ thư mục home', ['dest' => $dest['name']]);
            $pw = WNBDirectAdmin::passwd($u);
            $home = $pw['home'] ?? '';
            $prev = $isLocal ? self::latestLocalBackup($u, $dest['id']) : null;
            if ($home === '' || !is_dir($home)) {
                $warnings[] = WNBLang::msg('Không tìm thấy thư mục home.');
            } elseif ($archive) {
                self::streamHomeArchive($home, $remote, $work . '.index', $prefix, $warnings);
                $homeArchived = true;
            } else {
                // Không giữ chủ sở hữu (-o/-g): file sao lưu thuộc root nên KHÔNG bị tính vào quota của khách
                // khi /backup nằm cùng phân vùng có bật quota. Khi khôi phục, dữ liệu được chown lại cho user.
                $linkDest = $prev ? ' --link-dest=' . escapeshellarg($prev['storage_path'] . '/homedir') : '';
                $code = WNBShell::run(sprintf(
                    '%srsync -rlptH --delete%s%s %s %s',
                    $prefix,
                    $linkDest,
                    self::excludeArgs(),
                    escapeshellarg($home . '/'),
                    escapeshellarg($work . '/homedir/')
                ), $out);
                if (self::outOfSpace($out)) {
                    throw self::diskFullError($root);
                } elseif ($code === 23 || $code === 24) {
                    $warnings[] = WNBLang::msg('Một số file không đọc được hoặc thay đổi trong lúc sao lưu: {detail}', ['detail' => WNBShell::tail($out, 2)]);
                } elseif ($code !== 0) {
                    throw new WNBError('rsync thất bại (mã {code}): {detail}', ['code' => $code, 'detail' => WNBShell::tail($out, 3)]);
                }
            }

            // 6. Manifest + dung lượng thực tế (du 2 thư mục: phần dùng chung đếm 1 lần -> dòng 2 là dữ liệu mới)
            $fullMb = self::duMb($work)[0] ?? 0;
            if ($archive) {
                $fullMb = round($fullMb + self::remoteSizeMb($remote), 1);
            }
            $newMb = $prev ? (self::duMb($prev['storage_path'], $work)[1] ?? $fullMb) : $fullMb;
            $record = [
                'id' => $id,
                'username' => $u,
                'reseller' => $acc['creator'],
                'owner_key' => WNBDirectAdmin::ownerKey($u),
                'domain' => $acc['domain'],
                'domains' => $acc['domains'],
                'type' => $isSnap ? 'snapshot' : 'backup',
                'destination_id' => $dest['id'],
                'destination_name' => $dest['name'],
                'destination_type' => $dest['type'],
                'format' => $archive ? 'archive' : 'folder',
                'home_archive' => $homeArchived,
                'job_id' => $task['job_id'],
                'note' => $task['note'],
                'databases' => $dumped,
                'emails' => $acc['emails'],
                'crons' => $acc['crons'],
                'subdomains' => $acc['subdomains'],
                'pointers' => $acc['pointers'],
                'incremental' => (bool)$prev,
                'size_mb' => $fullMb,
                'new_mb' => $newMb,
                'warnings' => $warnings,
                'created_at' => WNB::now(),
                'storage_path' => $work,
            ];
            file_put_contents($work . '/manifest.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // 7. Đẩy lên cloud (chế độ Nén: chỉ còn vài file cấu hình nhỏ)
            if (!$isLocal) {
                self::step($task, 85, 'Tải lên {dest}', ['dest' => $dest['name']]);
                $code = WNBShell::run($prefix . WNBRclone::cmd('copy ' . escapeshellarg($work) . ' ' . escapeshellarg($remote) . ' --transfers 8 --checkers 16 --retries 3', true), $out);
                if ($code !== 0) {
                    throw new WNBError('Tải lên cloud thất bại: {detail}', ['detail' => WNBShell::tail($out, 3)]);
                }
                self::rmrf($work);
                $record['storage_path'] = $remote;
            }
        } catch (Throwable $e) {
            self::rmrf($work);
            @unlink($work . '.index');
            if ($remote !== '') {
                // Không để lại bản dở dang trên cloud.
                WNBShell::run(WNBRclone::cmd('purge ' . escapeshellarg($remote)));
            }
            throw $e;
        }

        WNBStorage::update('backups', function ($list) use ($record) {
            array_unshift($list, $record);
            return $list;
        });

        $removed = self::applyRetention($record);
        $vars = ['user' => $u, 'size' => $record['size_mb'], 'new' => $record['new_mb'], 'removed' => $removed];
        if ($isSnap) {
            $key = $removed ? 'Đã tạo snapshot {user} ({size} MB), xóa {removed} bản cũ' : 'Đã tạo snapshot {user} ({size} MB)';
        } elseif ($prev) {
            $key = $removed ? 'Đã sao lưu {user} ({size} MB, mới {new} MB), xóa {removed} bản cũ' : 'Đã sao lưu {user} ({size} MB, mới {new} MB)';
        } else {
            $key = $removed ? 'Đã sao lưu {user} ({size} MB), xóa {removed} bản cũ' : 'Đã sao lưu {user} ({size} MB)';
        }
        return ['message' => WNBLang::msg($key, $vars), 'warnings' => $warnings];
    }

    /** Thẻ restic của một bản sao lưu (để nhận lại bản khi quét kho). */
    private static function resticTags(string $u, string $id, bool $isSnap, string $jobId): string
    {
        $tags = ['wnb', 'u:' . $u, 'b:' . $id, 't:' . ($isSnap ? 'snapshot' : 'backup')];
        if ($jobId !== '') {
            $tags[] = 'j:' . $jobId;
        }
        return implode('', array_map(fn($t) => ' --tag ' . escapeshellarg($t), $tags));
    }

    /**
     * Sao lưu incremental bằng restic: chỉ phần dữ liệu mới được tải lên, trùng lặp giữa các lần và giữa các tài khoản
     * chỉ lưu một lần. Home + cấu hình là một snapshot (thư mục cấu hình có đường dẫn cố định để restic nhận ra bản trước),
     * mỗi database là một snapshot riêng (mysqldump chạy thẳng vào restic, không ghi ra đĩa).
     */
    private static function backupRestic(array $task, array $acc, array $dest, string $id, bool $isSnap): array
    {
        if (!WNBRestic::usable()) {
            throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
        }
        if (!WNBRestic::hasKey($dest['id'])) {
            throw new WNBError('Chưa có mật khẩu kho restic. Hãy mở kho trong mục Kho lưu trữ và lưu lại.');
        }
        $u = $acc['username'];
        $staging = self::stagingRoot();
        $free = @disk_free_space($staging);
        if ($free !== false && $free / 1073741824 < self::RESTIC_MIN_FREE_GB) {
            throw new WNBError('Kho chỉ còn {free} GB trống (tối thiểu {min} GB). Hãy dọn bớt hoặc giảm số bản giữ lại.', ['free' => round($free / 1073741824, 2), 'min' => self::RESTIC_MIN_FREE_GB]);
        }
        $inodes = self::freeInodes($staging);
        if ($inodes !== null && $inodes < self::ARCHIVE_MIN_FREE_INODES) {
            throw new WNBError('Ổ đĩa chứa {path} gần hết inode (chỉ còn {n} inode trống) nên không tạo thêm file được. Hãy xóa bớt file nhỏ (bản sao lưu cũ, session, mail queue) hoặc chuyển thư mục lưu trữ sang ổ khác.', ['path' => $staging, 'n' => $inodes]);
        }
        WNBRestic::ensureRepo($dest);

        self::mkdirSecure($staging . '/rmeta', 0700);
        $work = $staging . '/rmeta/' . $u;
        self::rmrf($work);
        foreach (['', '/config', '/emails', '/dns', '/cron'] as $sub) {
            self::mkdirSecure($work . $sub, 0700);
        }
        $warnings = [];
        $prefix = WNBShell::lowPriorityPrefix();
        $host = self::hostname();
        $tags = self::resticTags($u, $id, $isSnap, (string)$task['job_id']);
        $snaps = ['home' => '', 'dbs' => []];
        $bytes = 0;
        $added = 0;
        $dumped = [];
        $parent = null;
        try {
            self::step($task, 5, 'Sao lưu cấu hình DirectAdmin');
            self::collectMeta($u, $acc, $work, $warnings);

            if (!empty($acc['databases'])) {
                self::step($task, 15, 'Dump {n} database', ['n' => count($acc['databases'])]);
                $mysqldump = WNBShell::which('mysqldump') ?: 'mysqldump';
                try {
                    $cnf = WNBDirectAdmin::mysqlDefaultsFile();
                    foreach ($acc['databases'] as $db) {
                        $code = WNBShell::run($prefix . WNBRestic::cmd($dest, 'backup --json --retry-lock 30m --host ' . escapeshellarg($host) . $tags
                            . ' --tag ' . escapeshellarg('db:' . $db) . ' --stdin-from-command --stdin-filename ' . escapeshellarg($db . '.sql') . ' -- '
                            . escapeshellarg($mysqldump) . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --quick --routines --triggers --events --max-allowed-packet=1G ' . escapeshellarg($db), true), $out);
                        $r = WNBRestic::parseJson($out);
                        $snap = (string)($r['summary']['snapshot_id'] ?? '');
                        if ($code === 0 && $snap !== '') {
                            $snaps['dbs'][$db] = $snap;
                            $dumped[] = $db;
                            $bytes += (int)($r['summary']['total_bytes_processed'] ?? 0);
                            $added += (int)($r['summary']['data_added_packed'] ?? $r['summary']['data_added'] ?? 0);
                        } else {
                            if ($snap !== '') {
                                WNBRestic::forget($dest, [$snap]);
                            }
                            $warnings[] = WNBLang::msg('Dump database {db} thất bại: {detail}', ['db' => $db, 'detail' => self::resticDetail($r, $out)]);
                        }
                    }
                } catch (Throwable $e) {
                    $warnings[] = WNBLang::msg('Bỏ qua database: {detail}', ['detail' => $e->getMessage()]);
                } finally {
                    if (isset($cnf)) {
                        @unlink($cnf);
                    }
                }
            }

            $pw = WNBDirectAdmin::passwd($u);
            $home = $pw['home'] ?? '';
            if ($home === '' || !is_dir($home)) {
                $warnings[] = WNBLang::msg('Không tìm thấy thư mục home.');
                $home = '';
            }
            foreach (WNBStorage::read('backups') as $b) {
                if ($b['username'] === $u && $b['destination_id'] === $dest['id'] && ($b['format'] ?? '') === 'restic' && !empty($b['restic']['home']) && ($b['restic']['home_path'] ?? '') === $home) {
                    $parent = (string)$b['restic']['home'];
                    break;
                }
            }
            // Manifest đi kèm snapshot để "Quét lại kho" dựng lại được danh sách khi mất dữ liệu plugin.
            file_put_contents($work . '/manifest.json', json_encode([
                'id' => $id, 'username' => $u, 'reseller' => $acc['creator'], 'domain' => $acc['domain'], 'domains' => $acc['domains'],
                'type' => $isSnap ? 'snapshot' : 'backup', 'databases' => $dumped, 'emails' => $acc['emails'], 'crons' => $acc['crons'],
                'subdomains' => $acc['subdomains'], 'pointers' => $acc['pointers'],
                'job_id' => $task['job_id'], 'note' => $task['note'], 'created_at' => WNB::now(), 'host' => $host,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            self::step($task, 35, 'Sao lưu thư mục home lên {dest} (chỉ phần thay đổi)', ['dest' => $dest['name']]);
            $excludes = '';
            if ($home !== '') {
                foreach (preg_split('/
?
/', (string)WNBStorage::settings()['excludes']) as $pattern) {
                    $pattern = trim($pattern);
                    if ($pattern !== '' && strpos($pattern, '..') === false) {
                        $excludes .= ' --exclude=' . escapeshellarg($home . '/' . ltrim($pattern, '/'));
                    }
                }
            }
            foreach ($parent ? [$parent, null] : [null] as $try) {
                $out = [];
                $code = WNBShell::run($prefix . WNBRestic::cmd($dest, 'backup --json --retry-lock 30m --host ' . escapeshellarg($host) . $tags
                    . ' --tag part:home --tag ' . escapeshellarg('hp:' . $home) . ' --tag ' . escapeshellarg('mp:' . $work)
                    . ($try ? ' --parent ' . escapeshellarg($try) : '') . $excludes
                    . ($home !== '' ? ' ' . escapeshellarg($home) : '') . ' ' . escapeshellarg($work), true), $out);
                $r = WNBRestic::parseJson($out);
                $snap = (string)($r['summary']['snapshot_id'] ?? '');
                // Bản làm mốc không còn trong kho (vd kho vừa đổi vị trí): thử lại một lần không dùng mốc.
                if ($snap !== '' || !$try || strpos(implode("\n", $out), substr($try, 0, 8)) === false) {
                    break;
                }
            }
            $parent = $try;
            if ($snap === '' || ($code !== 0 && $code !== 3)) {
                if (self::outOfSpace($out)) {
                    throw self::diskFullError($staging);
                }
                throw new WNBError('restic sao lưu thất bại (mã {code}): {detail}', ['code' => $code, 'detail' => self::resticDetail($r, $out)]);
            }
            if ($code === 3) {
                $warnings[] = WNBLang::msg('Một số file không đọc được hoặc thay đổi trong lúc sao lưu: {detail}', ['detail' => self::resticDetail($r, $out)]);
            }
            $snaps['home'] = $snap;
            $bytes += (int)($r['summary']['total_bytes_processed'] ?? 0);
            $added += (int)($r['summary']['data_added_packed'] ?? $r['summary']['data_added'] ?? 0);
        } catch (Throwable $e) {
            try {
                WNBRestic::forget($dest, array_values(array_filter(array_merge([$snaps['home']], array_values($snaps['dbs'])))));
            } catch (Throwable $ignored) {
            }
            self::rmrf($work);
            throw $e;
        }
        self::rmrf($work);

        $record = [
            'id' => $id,
            'username' => $u,
            'reseller' => $acc['creator'],
            'owner_key' => WNBDirectAdmin::ownerKey($u),
            'domain' => $acc['domain'],
            'domains' => $acc['domains'],
            'type' => $isSnap ? 'snapshot' : 'backup',
            'destination_id' => $dest['id'],
            'destination_name' => $dest['name'],
            'destination_type' => $dest['type'],
            'format' => 'restic',
            'home_archive' => $home !== '',
            'job_id' => $task['job_id'],
            'note' => $task['note'],
            'databases' => $dumped,
            'emails' => $acc['emails'],
            'crons' => $acc['crons'],
            'subdomains' => $acc['subdomains'],
            'pointers' => $acc['pointers'],
            'incremental' => (bool)$parent,
            'size_mb' => round($bytes / 1048576, 1),
            'new_mb' => round($added / 1048576, 1),
            'warnings' => $warnings,
            'created_at' => WNB::now(),
            'storage_path' => WNBRestic::repo($dest),
            'restic' => ['home' => $snaps['home'], 'dbs' => $snaps['dbs'], 'home_path' => $home, 'meta_path' => $work, 'host' => $host],
        ];
        WNBStorage::update('backups', function ($list) use ($record) {
            array_unshift($list, $record);
            return $list;
        });
        $removed = self::applyRetention($record);
        $vars = ['user' => $u, 'size' => $record['size_mb'], 'new' => $record['new_mb'], 'removed' => $removed];
        if ($isSnap) {
            $key = $removed ? 'Đã tạo snapshot {user} ({size} MB), xóa {removed} bản cũ' : 'Đã tạo snapshot {user} ({size} MB)';
        } else {
            $key = $removed ? 'Đã sao lưu {user} ({size} MB, mới {new} MB), xóa {removed} bản cũ' : 'Đã sao lưu {user} ({size} MB, mới {new} MB)';
        }
        return ['message' => WNBLang::msg($key, $vars), 'warnings' => $warnings];
    }

    private static function resticDetail(array $parsed, array $out): string
    {
        $errors = array_values(array_filter($parsed['errors']));
        return $errors ? implode(' | ', array_slice($errors, -3)) : WNBShell::tail($out, 3);
    }

    /** Cấu hình DirectAdmin (user.conf, domains, crontab.conf...), crontab hệ thống, DNS zone, cấu hình email /etc/virtual. */
    private static function collectMeta(string $u, array $acc, string $work, array &$warnings): void
    {
        if (WNBShell::run(sprintf('cp -a %s %s', escapeshellarg(WNBDirectAdmin::USERS_DIR . '/' . $u . '/.'), escapeshellarg($work . '/config/')), $out) !== 0) {
            $warnings[] = WNBLang::msg('Không sao chép đủ cấu hình DirectAdmin: {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
        $sysCron = WNBDirectAdmin::systemCrontab($u);
        if ($sysCron !== '') {
            @copy($sysCron, $work . '/cron/system.cron');
        }
        foreach ($acc['domains'] as $d) {
            $zone = WNBDirectAdmin::zoneFile($d);
            if (is_file($zone)) {
                @copy($zone, $work . '/dns/' . $d . '.db');
            } else {
                $warnings[] = WNBLang::msg('Không thấy DNS zone của {domain}.', ['domain' => $d]);
            }
        }
        // Pointer kiểu alias có zone riêng; kiểu chuyển hướng thì không.
        foreach ($acc['pointers'] as $p) {
            $zone = WNBDirectAdmin::zoneFile($p['name']);
            if ($p['alias'] && is_file($zone)) {
                @copy($zone, $work . '/dns/' . $p['name'] . '.db');
            }
        }
        foreach ($acc['domains'] as $d) {
            if (is_dir('/etc/virtual/' . $d)) {
                self::mkdirSecure($work . '/emails/' . $d, 0700);
                if (WNBShell::run(sprintf('cp -a %s %s', escapeshellarg('/etc/virtual/' . $d . '/.'), escapeshellarg($work . '/emails/' . $d . '/')), $out) !== 0) {
                    $warnings[] = WNBLang::msg('Không sao chép đủ cấu hình email {domain}.', ['domain' => $d]);
                }
            }
        }
    }

    private const DISK_FULL = 'Ổ đĩa chứa {path} đã đầy (còn {free} GB và {inodes} inode trống). Hãy dọn bớt, giảm số bản giữ lại hoặc chuyển thư mục lưu trữ sang ổ lớn hơn trong Cài đặt.';
    /** Dưới mức này thì không bắt đầu sao lưu (mỗi tài khoản có thể cần hàng chục nghìn file). */
    private const MIN_FREE_INODES = 50000;

    /** Chế độ Nén chỉ ghi vài file cấu hình ra đĩa local nên chỉ cần rất ít chỗ trống. */
    private const ARCHIVE_MIN_FREE_GB = 0.2;
    private const ARCHIVE_MIN_FREE_INODES = 2000;
    /** restic cần thêm chỗ cho bộ đệm chỉ mục và file pack tạm. */
    private const RESTIC_MIN_FREE_GB = 0.5;
    /** Tải lên theo luồng: chunk 32M cho phép file tới ~320 GB trên S3 (10.000 phần), tốn ~128 MB RAM. */
    private const STREAM_FLAGS = ' --s3-chunk-size 32M --retries 1 --low-level-retries 10';

    /** pigz (đa luồng) nếu có, không thì gzip mức 4 (nhanh hơn nhiều so với mặc định, file lớn hơn chút ít). */
    private static function compressor(): string
    {
        $pigz = WNBShell::which('pigz');
        return $pigz !== '' ? escapeshellarg($pigz) . ' -6' : 'gzip -4';
    }

    /** Mẫu loại trừ trong Cài đặt, dạng của tar (tính từ "./" của home). */
    private static function tarExcludeArgs(): string
    {
        $args = ' --anchored';
        foreach (preg_split('/\r?\n/', (string)WNBStorage::settings()['excludes']) as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '' && strpos($pattern, '..') === false) {
                $args .= ' --exclude=' . escapeshellarg('./' . ltrim($pattern, '/'));
            }
        }
        return $args;
    }

    /**
     * Nén home thành homedir.tar.gz và đẩy thẳng lên cloud (tar | gzip | rclone rcat), không ghi bản nén ra đĩa.
     * Danh sách file (tar -vv) được lưu kèm thành homedir.index.gz để duyệt mà không phải tải bản nén.
     */
    private static function streamHomeArchive(string $home, string $remote, string $indexFile, string $prefix, array &$warnings): void
    {
        $tar = WNBShell::which('tar') ?: 'tar';
        WNBShell::bash(sprintf(
            '%s%s -C %s -cf - --numeric-owner -vv --index-file=%s --quoting-style=literal%s . | %s%s | %s; echo "WNB_PIPE ${PIPESTATUS[*]}"',
            $prefix,
            escapeshellarg($tar),
            escapeshellarg($home),
            escapeshellarg($indexFile),
            self::tarExcludeArgs(),
            $prefix,
            self::compressor(),
            WNBRclone::cmd('rcat ' . escapeshellarg(WNBRclone::join($remote, 'homedir.tar.gz')) . self::STREAM_FLAGS, true)
        ), $out);
        [$tarCode, $gzCode, $upCode] = self::pipeStatus($out, 3);
        if ($upCode !== 0) {
            throw new WNBError('Tải lên cloud thất bại: {detail}', ['detail' => WNBShell::tail($out, 3)]);
        }
        if ($gzCode !== 0 || $tarCode > 1) {
            throw new WNBError('Nén thư mục home thất bại (mã {code}): {detail}', ['code' => $tarCode ?: $gzCode, 'detail' => WNBShell::tail($out, 3)]);
        }
        if ($tarCode === 1) {
            $warnings[] = WNBLang::msg('Một số file không đọc được hoặc thay đổi trong lúc sao lưu: {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
        $code = WNBShell::bash(sprintf('gzip -c %s | %s', escapeshellarg($indexFile), WNBRclone::cmd('rcat ' . escapeshellarg(WNBRclone::join($remote, 'homedir.index.gz')) . self::STREAM_FLAGS)), $out);
        @unlink($indexFile);
        if ($code !== 0) {
            $warnings[] = WNBLang::msg('Không lưu được danh sách file của bản nén (vẫn khôi phục được, nhưng không duyệt từng file được): {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
    }

    /** Đọc dòng "WNB_PIPE a b c" (mã thoát từng lệnh của pipeline) và bỏ nó khỏi $out. */
    private static function pipeStatus(array &$out, int $n): array
    {
        $codes = array_fill(0, $n, 1);
        foreach ($out as $i => $line) {
            if (preg_match('/^WNB_PIPE ([\d ]+)$/', trim($line), $m)) {
                $codes = array_map('intval', explode(' ', trim($m[1])));
                unset($out[$i]);
            }
        }
        $out = array_values($out);
        return array_pad(array_slice($codes, 0, $n), $n, 1);
    }

    private static function remoteSizeMb(string $remote): float
    {
        if (WNBShell::run(WNBRclone::cmd('size ' . escapeshellarg($remote) . ' --json'), $out) !== 0) {
            return 0.0;
        }
        $raw = implode("\n", $out);
        $j = json_decode(substr($raw, (int)strpos($raw, '{')), true);
        return isset($j['bytes']) ? round($j['bytes'] / 1048576, 1) : 0.0;
    }

    /**
     * Đường dẫn local của danh sách file một bản nén (tải từ cloud lần đầu, lưu tạm 1 ngày trong data/cache).
     */
    public static function archiveIndex(array $b): string
    {
        $dir = WNBStorage::path('cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/idx_' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$b['id']) . '.txt';
        if (is_file($file)) {
            @touch($file);
            return $file;
        }
        if (WNB::isPreview()) {
            file_put_contents($file, self::previewIndex(rtrim((string)$b['storage_path'], '/') . '/homedir'));
            return $file;
        }
        if (!WNBRclone::installed()) {
            throw new WNBError('Chưa cài rclone nên không duyệt được bản sao lưu trên cloud.');
        }
        $tmp = $file . '.tmp';
        $code = WNBShell::bash(sprintf('%s | gzip -dc > %s', WNBRclone::cmd('cat ' . escapeshellarg(WNBRclone::join((string)$b['storage_path'], 'homedir.index.gz')) . ' --contimeout 15s --timeout 120s'), escapeshellarg($tmp)), $out);
        if ($code !== 0 || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new WNBError('Không đọc được danh sách file của bản sao lưu: {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
        return $file;
    }

    /** $rel (file hoặc thư mục) có trong bản nén không. */
    public static function archiveHas(array $b, string $rel): bool
    {
        $fp = @fopen(self::archiveIndex($b), 'rb');
        if (!$fp) {
            return false;
        }
        $prefix = $rel . '/';
        while (($line = fgets($fp)) !== false) {
            $e = WNBRclone::parseIndexLine(rtrim($line, "\r\n"));
            if ($e && ($e['path'] === $rel || strpos($e['path'], $prefix) === 0)) {
                fclose($fp);
                return true;
            }
        }
        fclose($fp);
        return false;
    }

    /** Preview: dựng danh sách file giống `tar -cvv` từ thư mục demo. */
    private static function previewIndex(string $dir): string
    {
        $out = "drwx------ 0/0 0 2024-05-01 12:00 ./\n";
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            $date = date('Y-m-d H:i', $f->getMTime());
            $out .= $f->isDir() ? "drwx------ 0/0 0 $date ./$rel/\n" : sprintf("-rw-r--r-- 0/0 %9d %s ./%s\n", $f->getSize(), $date, $rel);
        }
        return $out;
    }

    /** Số inode còn trống của phân vùng chứa $path (df -Pi); null nếu không xác định (vd btrfs báo 0 inode). */
    private static function freeInodes(string $path): ?int
    {
        if (WNBShell::run('df -Pi ' . escapeshellarg($path), $out) !== 0 || count($out) < 2) {
            return null;
        }
        $f = preg_split('/\s+/', trim((string)end($out)));
        if (count($f) < 4 || !preg_match('/^\d+$/', $f[1] . $f[3]) || (int)$f[1] === 0) {
            return null;
        }
        return (int)$f[3];
    }

    private static function outOfSpace(array $out): bool
    {
        return (bool)preg_grep('/No space left on device|Disk quota exceeded/i', $out);
    }

    private static function diskFullError(string $root): WNBError
    {
        $free = @disk_free_space($root);
        $inodes = self::freeInodes($root);
        return new WNBError(self::DISK_FULL, ['path' => $root, 'free' => $free === false ? '?' : round($free / 1073741824, 1), 'inodes' => $inodes === null ? '?' : $inodes]);
    }

    private static function latestLocalBackup(string $u, string $destId): ?array
    {
        foreach (WNBStorage::read('backups') as $b) {
            if ($b['username'] === $u && $b['destination_id'] === $destId && $b['destination_type'] === 'local' && is_dir($b['storage_path'] . '/homedir')) {
                return $b;
            }
        }
        return null;
    }

    /**
     * Mỗi nhóm giữ số bản riêng: snapshot (theo cài đặt), bản thủ công (theo cài đặt) và từng lịch (theo lịch đó).
     * Như vậy sao lưu tay không đẩy mất bản của lịch và ngược lại.
     */
    private static function applyRetention(array $rec): int
    {
        $policy = self::groupPolicy($rec, WNBStorage::read('jobs'));
        $matching = array_values(array_filter(WNBStorage::read('backups'), fn($b) => self::groupKey($b) === self::groupKey($rec) && !self::otherOwner($b, $rec)));
        usort($matching, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        $keep = self::retentionKeep($matching, $policy);
        $remove = array_values(array_filter($matching, fn($b) => !isset($keep[$b['id']]) && !self::backupBusy($b['id'])));
        foreach ($remove as $b) {
            self::removeStorage($b);
        }
        $ids = array_column($remove, 'id');
        if ($ids) {
            WNBStorage::update('backups', fn($list) => array_values(array_filter($list, fn($b) => !in_array($b['id'], $ids, true))));
        }
        return count($ids);
    }

    /**
     * Bản của tài khoản cũ cùng tên (đã bị xóa rồi tạo lại) không bị chính sách giữ bản của tài khoản mới dọn mất:
     * lịch sao lưu tài khoản mới (lúc còn trống) trước khi kịp khôi phục thì bản cũ vẫn còn nguyên.
     */
    private static function otherOwner(array $b, array $rec): bool
    {
        $real = fn($k) => $k !== '' && $k !== 'imported';
        $x = (string)($b['owner_key'] ?? '');
        $y = (string)($rec['owner_key'] ?? '');
        return $real($x) && $real($y) && $x !== $y;
    }

    /** Nhóm giữ bản: cùng tài khoản + kho + loại, và cùng lịch (snapshot gom chung). */
    private static function groupKey(array $b): string
    {
        $type = (string)($b['type'] ?? 'backup');
        return ($b['username'] ?? '') . '|' . ($b['destination_id'] ?? '') . '|' . $type . '|' . ($type === 'snapshot' ? '' : (string)($b['job_id'] ?? ''));
    }

    /** Mặc định: mọi bản của lịch trong 24 giờ gần nhất đều được giữ (chạy lại trong ngày không thay bản trước). */
    public const DEFAULT_KEEP_HOURS = 24;

    /**
     * Chính sách giữ bản của lịch: last (N bản mới nhất), daily/weekly/monthly (bản cuối của mỗi ngày/tuần/tháng),
     * hours (giữ MỌI bản trong N giờ gần nhất). Lịch cũ chỉ có 'retention' = giữ N bản mới nhất như trước.
     */
    public static function jobPolicy(array $job): array
    {
        $has = false;
        $p = [];
        foreach (['last', 'daily', 'weekly', 'monthly'] as $k) {
            $has = $has || array_key_exists('keep_' . $k, $job);
            $p[$k] = max(0, min(365, (int)($job['keep_' . $k] ?? 0)));
        }
        if (!$has) {
            $p = ['last' => max(1, min(365, (int)($job['retention'] ?? 7))), 'daily' => 0, 'weekly' => 0, 'monthly' => 0];
        }
        if (array_sum($p) === 0) {
            $p['last'] = 1;
        }
        // Lịch kiểu cũ (chỉ "N bản mới nhất") giữ nguyên cách cũ: không cộng thêm khoảng giờ.
        $p['hours'] = array_key_exists('keep_hours', $job) ? max(0, min(168, (int)$job['keep_hours'])) : ($has ? self::DEFAULT_KEEP_HOURS : 0);
        return $p;
    }

    private static function groupPolicy(array $b, array $jobs): array
    {
        $settings = WNBStorage::settings();
        if (($b['type'] ?? '') === 'snapshot') {
            return ['last' => max(1, (int)$settings['max_snapshots_per_user'])];
        }
        if (($b['job_id'] ?? '') !== '') {
            foreach ($jobs as $j) {
                if ($j['id'] === $b['job_id']) {
                    return self::jobPolicy($j);
                }
            }
        }
        return ['last' => max(1, (int)$settings['default_retention'])];
    }

    /**
     * Chọn bản giữ lại (giống restic forget --keep-* --keep-within): $list xếp mới -> cũ.
     * Trả về [id => ['recent'|'last'|'daily'|'weekly'|'monthly', ...]]. Bản mới nhất luôn được giữ.
     */
    public static function retentionKeep(array $list, array $policy, ?int $now = null): array
    {
        $keep = [];
        $hours = max(0, (int)($policy['hours'] ?? 0));
        if ($hours > 0) {
            $since = ($now ?? time()) - $hours * 3600;
            foreach ($list as $b) {
                if ((strtotime((string)$b['created_at']) ?: 0) >= $since) {
                    $keep[$b['id']][] = 'recent';
                }
            }
        }
        foreach (array_slice($list, 0, max(0, (int)($policy['last'] ?? 0))) as $b) {
            $keep[$b['id']][] = 'last';
        }
        foreach (['daily' => 'Y-m-d', 'weekly' => 'o-W', 'monthly' => 'Y-m'] as $kind => $fmt) {
            $limit = max(0, (int)($policy[$kind] ?? 0));
            $seen = [];
            foreach ($list as $b) {
                if (count($seen) >= $limit) {
                    break;
                }
                $period = date($fmt, strtotime((string)$b['created_at']) ?: 0);
                if (!isset($seen[$period])) {
                    $seen[$period] = true;
                    $keep[$b['id']][] = $kind;
                }
            }
        }
        if ($list && !isset($keep[$list[0]['id']])) {
            $keep[$list[0]['id']][] = 'last';
        }
        return $keep;
    }

    /** Lý do giữ của từng bản (để giao diện gắn nhãn Ngày/Tuần/Tháng). */
    public static function retentionTags(array $backups): array
    {
        $jobs = WNBStorage::read('jobs');
        $groups = [];
        foreach ($backups as $b) {
            $groups[self::groupKey($b)][] = $b;
        }
        $tags = [];
        foreach ($groups as $list) {
            usort($list, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
            $tags += self::retentionKeep($list, self::groupPolicy($list[0], $jobs));
        }
        return $tags;
    }

    /** Xóa dữ liệu của một bản sao lưu; chỉ xóa đúng thư mục <user>/<id>. */
    private static function removeStorage(array $b): void
    {
        $path = (string)($b['storage_path'] ?? '');
        if ($path === '') {
            return;
        }
        if (($b['destination_type'] ?? 'local') === 'local' || WNB::isPreview()) {
            if (basename($path) === $b['id'] && basename(dirname($path)) === $b['username'] && !is_link($path)) {
                self::rmrf($path);
            }
            return;
        }
        if (($b['format'] ?? '') === 'restic') {
            // Chỉ "quên" snapshot; dữ liệu không còn dùng được dọn ở lượt bảo trì kho (prune).
            $dest = self::findDestination((string)$b['destination_id']);
            if ($dest && WNBRestic::usable()) {
                WNBRestic::forget($dest, WNBRestic::snapshotIds($b));
            } elseif ($dest) {
                throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
            }
            return;
        }
        if (WNBRclone::installed() && substr($path, -strlen('/' . $b['username'] . '/' . $b['id'])) === '/' . $b['username'] . '/' . $b['id']) {
            if (WNBShell::run(WNBRclone::cmd('purge ' . escapeshellarg($path)), $out) !== 0) {
                throw new WNBError('Không xóa được bản sao lưu trên cloud: {detail}', ['detail' => WNBShell::tail($out, 2)]);
            }
        }
    }

    private static function simulateBackup(array $task, array $acc, array $dest, string $id): array
    {
        $u = $acc['username'];
        $work = WNBStorage::path('preview_store/' . $u . '/' . $id);
        foreach ($acc['domains'] as $d) {
            @mkdir($work . '/homedir/domains/' . $d . '/public_html/wp-content/uploads', 0700, true);
            @file_put_contents($work . '/homedir/domains/' . $d . '/public_html/index.php', "<?php // {$d}\n");
            @file_put_contents($work . '/homedir/domains/' . $d . '/public_html/wp-config.php', "<?php define('DB_NAME', '{$u}_wp');\n");
            @file_put_contents($work . '/homedir/domains/' . $d . '/public_html/.htaccess', "# BEGIN WordPress\n");
            @mkdir($work . '/homedir/imap/' . $d, 0700, true);
        }
        @mkdir($work . '/homedir/backups', 0700, true);
        @file_put_contents($work . '/homedir/.bashrc', "# .bashrc\n");
        $size = round(max(50, (float)$acc['usage_mb']) * (0.9 + mt_rand(0, 20) / 100), 1);
        $record = [
            'id' => $id, 'username' => $u, 'reseller' => $acc['creator'], 'owner_key' => WNBDirectAdmin::ownerKey($u), 'domain' => $acc['domain'], 'domains' => $acc['domains'],
            'type' => $task['type'] === 'snapshot' ? 'snapshot' : 'backup',
            'destination_id' => $dest['id'], 'destination_name' => $dest['name'], 'destination_type' => $dest['type'],
            'format' => WNBRclone::destFormat($dest), 'home_archive' => true,
            'job_id' => $task['job_id'], 'note' => $task['note'],
            'databases' => $acc['databases'], 'emails' => $acc['emails'], 'crons' => $acc['crons'],
            'subdomains' => $acc['subdomains'], 'pointers' => $acc['pointers'],
            'incremental' => false, 'size_mb' => $size, 'new_mb' => round($size * 0.08, 1),
            'warnings' => [], 'created_at' => WNB::now(), 'storage_path' => $work,
        ];
        WNBStorage::update('backups', function ($list) use ($record) {
            array_unshift($list, $record);
            return $list;
        });
        self::applyRetention($record);
        $key = $record['type'] === 'snapshot' ? 'Đã tạo snapshot {user} ({size} MB)' : 'Đã sao lưu {user} ({size} MB)';
        return ['message' => WNBLang::msg($key, ['user' => $u, 'size' => $size]), 'warnings' => []];
    }

    /* ============================== Khôi phục ============================== */

    private static function restore(array $task): array
    {
        $b = self::findBackup($task['backup_id']);
        if (!$b) {
            throw new WNBError('Bản sao lưu không còn tồn tại.');
        }
        $u = $b['username'];
        $acc = WNBDirectAdmin::account($u, true);
        if (!$acc) {
            throw new WNBError('Tài khoản {user} không còn tồn tại. Hãy tạo lại tài khoản trong DirectAdmin rồi khôi phục.', ['user' => $u]);
        }
        $scope = $task['scope'];
        $sel = self::restoreSelection($b, $acc, $scope, $task['items']);
        $warnings = [];
        // Tài khoản tạo lại sau khi bị xóa thường chưa có đủ tên miền: email/DNS của các tên miền đó bị bỏ qua.
        $missing = in_array($scope, ['full', 'emails', 'dns'], true) ? array_values(array_diff((array)$b['domains'], $acc['domains'])) : [];
        if ($missing) {
            $warnings[] = WNBLang::msg('Tài khoản chưa có tên miền {domains} nên email và DNS của chúng chưa được khôi phục. Thêm tên miền trong DirectAdmin rồi khôi phục lại phần Email và DNS.', ['domains' => implode(', ', $missing)]);
        }
        // Subdomain/pointer nằm trong cấu hình DirectAdmin, plugin không tạo lại: chỉ nhắc khi khôi phục toàn bộ.
        if ($scope === 'full') {
            $subs = array_values(array_diff((array)($b['subdomains'] ?? []), $acc['subdomains']));
            if ($subs) {
                $warnings[] = WNBLang::msg('Tài khoản chưa có subdomain {list}. File đã được khôi phục nhưng các subdomain này chỉ chạy lại sau khi tạo lại trong DirectAdmin.', ['list' => implode(', ', $subs)]);
            }
            $ptrs = array_values(array_diff(array_column((array)($b['pointers'] ?? []), 'name'), array_column($acc['pointers'], 'name')));
            if ($ptrs) {
                $warnings[] = WNBLang::msg('Tài khoản chưa có domain pointer {list}. Tạo lại pointer trong DirectAdmin để các tên miền này trỏ về website.', ['list' => implode(', ', $ptrs)]);
            }
        }
        $done = WNBLang::msg('Đã khôi phục {scope} cho {user}', ['scope' => WNBLang::msg(self::scopeKey($scope)), 'user' => $u]);

        if (WNB::isPreview()) {
            foreach ($sel as $k => $v) {
                if ($v) {
                    WNBStorage::log($task['id'], "Preview: {$k} = " . (is_array($v) ? implode(', ', $v) : 'yes'));
                }
            }
            return ['message' => $done, 'warnings' => $warnings];
        }

        if (($b['format'] ?? '') === 'restic') {
            $res = self::restoreRestic($task, $b, $sel, $done);
            $res['warnings'] = array_merge($warnings, $res['warnings']);
            return $res;
        }
        $stage = '';
        $src = $b['storage_path'];
        $archive = ($b['format'] ?? 'folder') === 'archive';
        try {
            if (($b['destination_type'] ?? 'local') !== 'local') {
                self::step($task, 10, 'Tải dữ liệu cần thiết từ cloud');
                $stage = self::stagingRoot() . '/restore_' . $task['id'];
                self::mkdirSecure($stage, 0700);
                self::fetchRemote($b['storage_path'], $stage, $sel, $archive);
                $src = $stage;
            } elseif (!is_dir($src)) {
                throw new WNBError('Không tìm thấy dữ liệu bản sao lưu trên đĩa.');
            }

            $homeItems = $sel['home'];
            foreach ($homeItems === [''] ? [] : $sel['emails'] as $email) {
                [$box, $domain] = explode('@', $email, 2);
                $rel = 'imap/' . $domain . '/' . $box;
                if ($archive ? self::archiveHas($b, $rel) : file_exists($src . '/homedir/' . $rel)) {
                    $homeItems[] = $rel;
                }
            }
            if ($homeItems) {
                self::step($task, 35, 'Khôi phục file vào thư mục home');
                if ($archive) {
                    self::restoreHomeArchive($u, $b, $homeItems, $warnings);
                } else {
                    self::restoreHome($u, $src . '/homedir', $homeItems, $task, $warnings);
                }
            }
            if ($sel['databases']) {
                self::step($task, 65, 'Import database');
                self::restoreDatabases($sel['databases'], $src . '/databases', $warnings);
            }
            if ($sel['emails']) {
                self::restoreEmailAccounts($sel['emails'], $src . '/emails', $warnings);
            }
            if ($sel['dns']) {
                self::step($task, 85, 'Khôi phục DNS');
                self::restoreDns($sel['dns'], $src . '/dns', $warnings);
            }
            if ($sel['cron']) {
                self::restoreCron($u, $src, $warnings);
            }
        } finally {
            if ($stage !== '') {
                self::rmrf($stage);
            }
        }
        return ['message' => $done, 'warnings' => $warnings];
    }

    /** Lọc lựa chọn khôi phục theo nội dung bản sao lưu và tài sản hiện tại của user. */
    public static function restoreSelection(array $b, array $acc, string $scope, array $items): array
    {
        $sel = ['home' => [], 'databases' => [], 'emails' => [], 'dns' => [], 'cron' => false];
        $full = $scope === 'full';
        $items = array_values(array_unique(array_map('strval', $items)));

        if ($full) {
            $sel['home'] = [''];
        } elseif ($scope === 'files') {
            foreach ($items as $p) {
                $p = self::cleanRelPath($p);
                if ($p !== null && $p !== '') {
                    $sel['home'][] = $p;
                }
            }
        }
        if ($full || $scope === 'databases') {
            $want = $full ? $b['databases'] : $items;
            $sel['databases'] = array_values(array_filter($want, fn($db) => in_array($db, $b['databases'], true) && strpos($db, $b['username'] . '_') === 0 && preg_match('/^[a-z0-9_]+$/i', $db)));
        }
        if ($full || $scope === 'emails') {
            $want = $full ? $b['emails'] : $items;
            $sel['emails'] = array_values(array_filter($want, function ($e) use ($b, $acc) {
                $parts = explode('@', $e, 2);
                return in_array($e, $b['emails'], true) && count($parts) === 2 && preg_match('/^[a-z0-9._+\-]+$/i', $parts[0]) && in_array($parts[1], $acc['domains'], true);
            }));
        }
        if ($full || $scope === 'dns') {
            $want = $full ? $b['domains'] : $items;
            $sel['dns'] = array_values(array_filter($want, fn($d) => in_array($d, $b['domains'], true) && in_array($d, $acc['domains'], true) && WNBDirectAdmin::validDomain($d)));
        }
        $sel['cron'] = $full || $scope === 'cron';

        if (!$sel['home'] && !$sel['databases'] && !$sel['emails'] && !$sel['dns'] && !$sel['cron']) {
            throw new WNBError('Không có mục hợp lệ nào để khôi phục.');
        }
        return $sel;
    }

    private static function fetchRemote(string $remote, string $stage, array $sel, bool $archive = false): void
    {
        // $optional: mục có thể không tồn tại (rclone không lưu thư mục rỗng) -> bỏ qua thay vì làm hỏng cả lượt khôi phục.
        $copy = function (string $rel, bool $optional = false) use ($remote, $stage) {
            $code = WNBShell::run(WNBRclone::cmd('copyto ' . escapeshellarg(WNBRclone::join($remote, $rel)) . ' ' . escapeshellarg($stage . '/' . $rel) . ' --retries 3', true), $out);
            if ($code !== 0 && !$optional) {
                throw new WNBError('Tải {item} từ cloud thất bại: {detail}', ['item' => $rel, 'detail' => WNBShell::tail($out, 2)]);
            }
        };
        if ($sel['home'] === ['']) {
            // Bản nén: phần home được giải nén thẳng từ cloud vào home ở bước sau, không tải về đĩa.
            $skip = $archive ? ' --exclude /homedir.tar.gz --exclude /homedir.index.gz' : '';
            $code = WNBShell::run(WNBRclone::cmd('copy ' . escapeshellarg($remote) . ' ' . escapeshellarg($stage) . $skip . ' --transfers 4 --retries 3', true), $out);
            if ($code !== 0) {
                throw new WNBError('Tải bản sao lưu từ cloud thất bại: {detail}', ['detail' => WNBShell::tail($out, 2)]);
            }
            return;
        }
        foreach ($archive ? [] : $sel['home'] as $p) {
            $copy('homedir/' . $p);
        }
        foreach ($sel['databases'] as $db) {
            $copy('databases/' . $db . '.sql.gz');
        }
        foreach ($sel['emails'] as $e) {
            [$box, $d] = explode('@', $e, 2);
            if (!$archive) {
                $copy('homedir/imap/' . $d . '/' . $box, true);
            }
            $copy('emails/' . $d, true);
        }
        foreach ($sel['dns'] as $d) {
            $copy('dns/' . $d . '.db');
        }
        if ($sel['cron']) {
            $copy('cron', true);
            $copy('config/crontab.conf', true);
        }
    }

    /**
     * Dựng các mục cần khôi phục trong staging (root), rồi rsync vào home BẰNG QUYỀN CỦA USER.
     * File mới phát sinh sau thời điểm sao lưu được giữ nguyên (không --delete).
     */
    private static function restoreHome(string $u, string $srcHome, array $items, array $task, array &$warnings): void
    {
        $pw = WNBDirectAdmin::passwd($u);
        if (!$pw || !is_dir($pw['home'])) {
            throw new WNBError('Không tìm thấy thư mục home của tài khoản.');
        }
        if (!is_dir($srcHome)) {
            throw new WNBError('Bản sao lưu không có dữ liệu home.');
        }
        // Staging đóng kín (0700) trong lúc root còn ghi; chỉ mở cho user sau khi đã chown xong.
        $stage = self::stagingRoot() . '/home_' . $task['id'];
        self::mkdirSecure($stage, 0700);
        $h = $stage . '/h';
        self::mkdirSecure($h, 0700);
        try {
            if ($items === ['']) {
                $need = (self::duMb($srcHome)[0] ?? 0) * 1048576;
                $free = @disk_free_space($stage);
                if ($free !== false && $need > $free * 0.95) {
                    throw new WNBError('Không đủ dung lượng trống để dựng dữ liệu khôi phục (cần khoảng {gb} GB).', ['gb' => round($need / 1073741824, 1)]);
                }
                $code = WNBShell::run(sprintf('rsync -aH --numeric-ids %s %s', escapeshellarg($srcHome . '/'), escapeshellarg($h . '/')), $out);
                if ($code !== 0) {
                    throw new WNBError('Dựng dữ liệu khôi phục thất bại: {detail}', ['detail' => WNBShell::tail($out, 2)]);
                }
            } else {
                foreach ($items as $rel) {
                    if (!self::insideWithoutLinks($srcHome, $rel)) {
                        $warnings[] = WNBLang::msg('Bỏ qua {item}: không có trong bản sao lưu.', ['item' => $rel]);
                        continue;
                    }
                    $code = WNBShell::run(sprintf('rsync -aH --numeric-ids --relative %s %s', escapeshellarg($srcHome . '/./' . $rel), escapeshellarg($h . '/')), $out);
                    if ($code !== 0) {
                        $warnings[] = WNBLang::msg('Không dựng được {item}: {detail}', ['item' => $rel, 'detail' => WNBShell::tail($out, 2)]);
                    }
                }
            }
            self::finishHomeRestore($u, $stage, $h, $warnings);
        } finally {
            self::rmrf($stage);
        }
    }

    /**
     * $h là bản dựng (thuộc root) của các mục cần khôi phục, nằm trong $stage. Chuyển quyền cho user rồi rsync vào home
     * BẰNG QUYỀN CỦA USER (không --delete: file mới sau thời điểm sao lưu được giữ nguyên).
     */
    private static function finishHomeRestore(string $u, string $stage, string $h, array &$warnings): void
    {
        $pw = WNBDirectAdmin::passwd($u);
        if (!$pw || !is_dir($pw['home'])) {
            throw new WNBError('Không tìm thấy thư mục home của tài khoản.');
        }
        // User phải đọc được toàn bộ dữ liệu dựng; nhóm (vd mail) được giữ nguyên.
        WNBShell::run(sprintf('chown -hR %s %s', escapeshellarg($u), escapeshellarg($h)));
        // Gốc bản dựng mang quyền hiện tại của home để rsync -p không đổi quyền thư mục home.
        @chmod($h, fileperms($pw['home']) & 07777);
        // User chỉ cần đi xuyên qua các thư mục cha (0711), không liệt kê được.
        for ($d = dirname($h); strlen($d) >= strlen($stage); $d = dirname($d)) {
            @chmod($d, 0711);
            if ($d === $stage) {
                break;
            }
        }
        $rsync = WNBShell::which('rsync') ?: 'rsync';
        $code = WNBShell::run(WNBShell::asUser($u, sprintf('%s -rlpt %s %s', escapeshellarg($rsync), escapeshellarg($h . '/'), escapeshellarg($pw['home'] . '/'))), $out);
        if ($code === 23 || $code === 24) {
            $warnings[] = WNBLang::msg('Một số file không ghi được (hết quota hoặc thiếu quyền): {detail}', ['detail' => WNBShell::tail($out, 2)]);
        } elseif ($code !== 0) {
            throw new WNBError('Ghi file vào home thất bại (mã {code}): {detail}', ['code' => $code, 'detail' => WNBShell::tail($out, 3)]);
        }
    }

    /**
     * Khôi phục từ kho restic: restore đúng các mục đã chọn (kèm thư mục cấu hình) vào staging của root,
     * home được rsync vào bằng quyền user; database được restic dump thẳng vào mysql.
     */
    private static function restoreRestic(array $task, array $b, array $sel, array $done): array
    {
        $dest = self::findDestination((string)$b['destination_id']);
        if (!$dest) {
            throw new WNBError('Kho lưu trữ đã bị xóa.');
        }
        if (!WNBRestic::usable()) {
            throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
        }
        $r = (array)$b['restic'];
        $u = $b['username'];
        $warnings = [];
        $homeItems = $sel['home'];
        foreach ($homeItems === [''] ? [] : $sel['emails'] as $email) {
            [$box, $domain] = explode('@', $email, 2);
            $homeItems[] = 'imap/' . $domain . '/' . $box;
        }
        $homePath = rtrim((string)$r['home_path'], '/');
        $includes = [(string)$r['meta_path']];
        if ($homeItems && $homePath !== '') {
            foreach ($homeItems === [''] ? [''] : $homeItems as $rel) {
                $includes[] = $rel === '' ? $homePath : $homePath . '/' . $rel;
            }
        }
        $stage = self::stagingRoot() . '/restore_' . $task['id'];
        self::mkdirSecure($stage, 0700);
        try {
            if ($homeItems === ['']) {
                $need = (float)$b['size_mb'] * 1048576;
                $free = @disk_free_space($stage);
                if ($free !== false && $need > $free * 0.95) {
                    throw new WNBError('Không đủ dung lượng trống để dựng dữ liệu khôi phục (cần khoảng {gb} GB).', ['gb' => round($need / 1073741824, 1)]);
                }
            }
            self::step($task, 10, 'Tải dữ liệu cần thiết từ cloud');
            $args = 'restore --retry-lock 30m ' . escapeshellarg((string)$r['home']) . ' --target ' . escapeshellarg($stage)
                . implode('', array_map(fn($p) => ' --include ' . escapeshellarg(WNBRestic::literalPattern($p)), $includes));
            if (WNBShell::run(WNBRestic::cmd($dest, $args, true), $out) !== 0) {
                throw new WNBError('Tải bản sao lưu từ cloud thất bại: {detail}', ['detail' => WNBShell::tail($out, 3)]);
            }
            $src = $stage . $r['meta_path'];
            if ($homeItems) {
                self::step($task, 35, 'Khôi phục file vào thư mục home');
                $h = $stage . $homePath;
                foreach ($homeItems === [''] ? [] : $sel['home'] as $rel) {
                    if (!file_exists($h . '/' . $rel) && !is_link($h . '/' . $rel)) {
                        $warnings[] = WNBLang::msg('Bỏ qua {item}: không có trong bản sao lưu.', ['item' => $rel]);
                    }
                }
                if ($homePath === '' || !is_dir($h)) {
                    if ($homeItems === ['']) {
                        throw new WNBError('Bản sao lưu không có dữ liệu home.');
                    }
                } else {
                    self::finishHomeRestore($u, $stage, $h, $warnings);
                }
            }
            if ($sel['databases']) {
                self::step($task, 65, 'Import database');
                self::restoreDatabases($sel['databases'], '', $warnings, function (string $db) use ($dest, $r) {
                    $snap = (string)($r['dbs'][$db] ?? '');
                    return $snap === '' ? null : WNBRestic::cmd($dest, 'dump --retry-lock 30m ' . escapeshellarg($snap) . ' ' . escapeshellarg('/' . $db . '.sql'), true);
                });
            }
            if ($sel['emails']) {
                self::restoreEmailAccounts($sel['emails'], $src . '/emails', $warnings);
            }
            if ($sel['dns']) {
                self::step($task, 85, 'Khôi phục DNS');
                self::restoreDns($sel['dns'], $src . '/dns', $warnings);
            }
            if ($sel['cron']) {
                self::restoreCron($u, $src, $warnings);
            }
        } finally {
            self::rmrf($stage);
        }
        return ['message' => $done, 'warnings' => $warnings];
    }

    /**
     * Khôi phục home từ bản nén: rclone cat | gzip -dc | tar -x chạy BẰNG QUYỀN CỦA USER ngay trong home,
     * không cần chỗ trống để dựng staging. File mới phát sinh sau thời điểm sao lưu được giữ nguyên.
     */
    private static function restoreHomeArchive(string $u, array $b, array $items, array &$warnings): void
    {
        $pw = WNBDirectAdmin::passwd($u);
        if (!$pw || !is_dir($pw['home'])) {
            throw new WNBError('Không tìm thấy thư mục home của tài khoản.');
        }
        if (array_key_exists('home_archive', $b) && !$b['home_archive']) {
            throw new WNBError('Bản sao lưu không có dữ liệu home.');
        }
        $members = [];
        if ($items !== ['']) {
            foreach ($items as $rel) {
                if (self::archiveHas($b, $rel)) {
                    $members[] = './' . $rel;
                } else {
                    $warnings[] = WNBLang::msg('Bỏ qua {item}: không có trong bản sao lưu.', ['item' => $rel]);
                }
            }
            if (!$members) {
                return;
            }
        }
        $tar = WNBShell::which('tar') ?: 'tar';
        $extract = sprintf(
            '%s -xf - -C %s -p --no-same-owner --no-overwrite-dir --no-wildcards --quoting-style=literal%s',
            escapeshellarg($tar),
            escapeshellarg($pw['home']),
            $members ? ' -- ' . implode(' ', array_map('escapeshellarg', $members)) : ''
        );
        WNBShell::bash(sprintf(
            '%s | gzip -dc | %s; echo "WNB_PIPE ${PIPESTATUS[*]}"',
            WNBRclone::cmd('cat ' . escapeshellarg(WNBRclone::join((string)$b['storage_path'], 'homedir.tar.gz')) . ' --retries 3', true),
            WNBShell::asUser($u, $extract)
        ), $out);
        [$dlCode, $gzCode, $tarCode] = self::pipeStatus($out, 3);
        if ($dlCode !== 0 || $gzCode !== 0) {
            throw new WNBError('Tải bản sao lưu từ cloud thất bại: {detail}', ['detail' => WNBShell::tail($out, 3)]);
        }
        if ($tarCode !== 0) {
            // Giải nén một phần (hết quota, thiếu quyền ở vài file): dữ liệu còn lại đã được ghi.
            $warnings[] = WNBLang::msg('Một số file không ghi được (hết quota hoặc thiếu quyền): {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
    }

    /** $rel tồn tại trong $base và không đi qua symlink nào. */
    private static function insideWithoutLinks(string $base, string $rel): bool
    {
        $path = $base;
        foreach (explode('/', $rel) as $seg) {
            $path .= '/' . $seg;
            if (!file_exists($path) && !is_link($path)) {
                return false;
            }
            if (is_link($path) && $path !== $base . '/' . $rel) {
                return false;
            }
        }
        return true;
    }

    /** $source (tùy chọn): fn(db) => lệnh in nội dung SQL ra stdout, null nếu bản sao lưu không có database đó. */
    private static function restoreDatabases(array $dbs, string $dir, array &$warnings, ?callable $source = null): void
    {
        $mysql = WNBShell::which('mysql') ?: 'mysql';
        $cnf = WNBDirectAdmin::mysqlDefaultsFile();
        try {
            foreach ($dbs as $db) {
                $file = $dir . '/' . $db . '.sql.gz';
                $from = $source ? $source($db) : (is_file($file) ? 'gzip -dc ' . escapeshellarg($file) : null);
                if ($from === null) {
                    $warnings[] = WNBLang::msg('Bản sao lưu không có dump của {db}.', ['db' => $db]);
                    continue;
                }
                WNBShell::run(sprintf('%s --defaults-extra-file=%s -e %s', escapeshellarg($mysql), escapeshellarg($cnf), escapeshellarg('CREATE DATABASE IF NOT EXISTS `' . $db . '`')));
                $code = WNBShell::bash(sprintf('%s | %s --defaults-extra-file=%s %s', $from, escapeshellarg($mysql), escapeshellarg($cnf), escapeshellarg($db)), $out);
                if ($code !== 0) {
                    $warnings[] = WNBLang::msg('Import {db} lỗi: {detail}', ['db' => $db, 'detail' => WNBShell::tail($out, 2)]);
                }
            }
        } finally {
            @unlink($cnf);
        }
    }

    /** Thêm lại dòng tài khoản email vào /etc/virtual/<domain>/passwd nếu hộp thư đã bị xóa. */
    private static function restoreEmailAccounts(array $emails, string $dir, array &$warnings): void
    {
        foreach ($emails as $e) {
            [$box, $d] = explode('@', $e, 2);
            $dst = '/etc/virtual/' . $d . '/passwd';
            if (!is_file($dst)) {
                $warnings[] = WNBLang::msg('Tên miền {domain} chưa có cấu hình email trên máy chủ.', ['domain' => $d]);
                continue;
            }
            $current = @file($dst, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($current as $line) {
                if (strpos($line, $box . ':') === 0) {
                    continue 2;
                }
            }
            foreach (@file($dir . '/' . $d . '/passwd', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (strpos($line, $box . ':') === 0) {
                    $raw = (string)file_get_contents($dst);
                    $lead = ($raw !== '' && substr($raw, -1) !== "\n") ? "\n" : '';
                    file_put_contents($dst, $lead . $line . "\n", FILE_APPEND);
                    continue 2;
                }
            }
            $warnings[] = WNBLang::msg('Không thấy tài khoản {email} trong cấu hình email của bản sao lưu.', ['email' => $e]);
        }
    }

    private static function restoreDns(array $domains, string $dir, array &$warnings): void
    {
        foreach ($domains as $d) {
            $src = $dir . '/' . $d . '.db';
            if (!is_file($src)) {
                $warnings[] = WNBLang::msg('Bản sao lưu không có DNS zone của {domain}.', ['domain' => $d]);
                continue;
            }
            $dst = WNBDirectAdmin::zoneFile($d);
            $zone = (string)file_get_contents($src);
            // Tăng serial để DNS phụ (slave) nhận bản mới.
            $liveSerial = is_file($dst) && preg_match('/SOA[^(]*\(\s*(\d+)/s', (string)file_get_contents($dst), $m) ? (int)$m[1] : 0;
            $zone = preg_replace_callback('/(SOA[^(]*\(\s*)(\d+)/s', fn($m) => $m[1] . (max((int)$m[2], $liveSerial, (int)(date('Ymd') . '00')) + 1), $zone, 1);
            if (@file_put_contents($dst, $zone) === false) {
                $warnings[] = WNBLang::msg('Không ghi được zone {domain}.', ['domain' => $d]);
            }
        }
        WNBDirectAdmin::queueTask('action=named&value=reload');
    }

    private static function restoreCron(string $u, string $src, array &$warnings): void
    {
        $daCron = $src . '/config/crontab.conf';
        if (is_file($daCron)) {
            $dst = WNBDirectAdmin::USERS_DIR . '/' . $u . '/crontab.conf';
            $existed = is_file($dst);
            @copy($daCron, $dst);
            if (!$existed) {
                WNBShell::run('chown diradmin:diradmin ' . escapeshellarg($dst));
                @chmod($dst, 0600);
            }
        }
        if (is_file($src . '/cron/system.cron')) {
            if (WNBShell::run(sprintf('crontab -u %s %s', escapeshellarg($u), escapeshellarg($src . '/cron/system.cron')), $out) !== 0) {
                $warnings[] = WNBLang::msg('Cài lại crontab thất bại: {detail}', ['detail' => WNBShell::tail($out, 2)]);
            }
        } elseif (!is_file($daCron)) {
            $warnings[] = WNBLang::msg('Bản sao lưu không có cron job.');
        }
    }

    public static function scopeKey(string $scope): string
    {
        $map = ['full' => 'toàn bộ tài khoản', 'files' => 'file', 'databases' => 'database', 'emails' => 'email', 'dns' => 'DNS', 'cron' => 'cron job'];
        return $map[$scope] ?? $scope;
    }

    /* ============================== Tải về & xóa ============================== */

    private static function download(array $task): array
    {
        $b = self::findBackup($task['backup_id']);
        if (!$b) {
            throw new WNBError('Bản sao lưu không còn tồn tại.');
        }
        $dlId = WNB::newId('dl');
        $file = self::downloadsRoot() . '/' . $dlId . '.tar.gz';
        $stage = '';
        try {
            if (WNB::isPreview()) {
                file_put_contents($file, gzencode(json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
            } else {
                $src = $b['storage_path'];
                if (($b['format'] ?? '') === 'restic') {
                    self::step($task, 15, 'Tải bản sao lưu từ cloud');
                    $stage = self::stagingRoot() . '/dl_' . $task['id'];
                    self::mkdirSecure($stage, 0700);
                    $src = self::resticExport($b, $stage);
                } elseif (($b['destination_type'] ?? 'local') !== 'local') {
                    self::step($task, 15, 'Tải bản sao lưu từ cloud');
                    $stage = self::stagingRoot() . '/dl_' . $task['id'];
                    self::mkdirSecure($stage, 0700);
                    $skip = ($b['format'] ?? 'folder') === 'archive' ? ' --exclude /homedir.index.gz' : '';
                    if (WNBShell::run(WNBRclone::cmd('copy ' . escapeshellarg($src) . ' ' . escapeshellarg($stage) . $skip . ' --transfers 4', true), $out) !== 0) {
                        throw new WNBError('Tải bản sao lưu từ cloud thất bại: {detail}', ['detail' => WNBShell::tail($out, 2)]);
                    }
                    $src = $stage;
                }
                self::step($task, 50, 'Nén thành file .tar.gz');
                $code = WNBShell::run(sprintf('%star -czf %s -C %s .', WNBShell::lowPriorityPrefix(), escapeshellarg($file), escapeshellarg($src)), $out);
                if ($code !== 0) {
                    @unlink($file);
                    throw new WNBError('Nén thất bại: {detail}', ['detail' => WNBShell::tail($out, 2)]);
                }
            }
        } finally {
            if ($stage !== '') {
                self::rmrf($stage);
            }
        }
        @chmod($file, 0600);
        $ttl = max(1, (int)WNBStorage::settings()['download_ttl_hours']);
        $record = [
            'id' => $dlId,
            'backup_id' => $b['id'],
            'username' => $b['username'],
            'owner_key' => $b['owner_key'] ?? '',
            'filename' => $b['id'] . '.tar.gz',
            'path' => $file,
            'size_mb' => round(((float)@filesize($file)) / 1048576, 1),
            'created_at' => WNB::now(),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl * 3600),
        ];
        WNBStorage::update('downloads', function ($l) use ($record) {
            array_unshift($l, $record);
            return $l;
        });
        return ['message' => WNBLang::msg('File tải về đã sẵn sàng ({size} MB), tự xóa sau {hours} giờ', ['size' => $record['size_mb'], 'hours' => $ttl]), 'warnings' => []];
    }

    /**
     * Dựng bản sao lưu restic thành cây thư mục giống bản local (homedir/, databases/*.sql.gz, config/, dns/...) để nén tải về.
     */
    private static function resticExport(array $b, string $stage): string
    {
        $dest = self::findDestination((string)$b['destination_id']);
        if (!$dest) {
            throw new WNBError('Kho lưu trữ đã bị xóa.');
        }
        if (!WNBRestic::usable()) {
            throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
        }
        $r = (array)$b['restic'];
        $raw = $stage . '/raw';
        $out = $stage . '/out';
        self::mkdirSecure($raw, 0700);
        self::mkdirSecure($out . '/databases', 0700);
        if (WNBShell::run(WNBRestic::cmd($dest, 'restore --retry-lock 30m ' . escapeshellarg((string)$r['home']) . ' --target ' . escapeshellarg($raw), true), $o) !== 0) {
            throw new WNBError('Tải bản sao lưu từ cloud thất bại: {detail}', ['detail' => WNBShell::tail($o, 3)]);
        }
        $home = $raw . rtrim((string)$r['home_path'], '/');
        if ((string)$r['home_path'] !== '' && is_dir($home)) {
            @rename($home, $out . '/homedir');
        }
        foreach (@scandir($raw . $r['meta_path']) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                @rename($raw . $r['meta_path'] . '/' . $e, $out . '/' . $e);
            }
        }
        foreach ((array)($r['dbs'] ?? []) as $db => $snap) {
            $code = WNBShell::bash(WNBRestic::cmd($dest, 'dump --retry-lock 30m ' . escapeshellarg((string)$snap) . ' ' . escapeshellarg('/' . $db . '.sql'), true) . ' | gzip -c > ' . escapeshellarg($out . '/databases/' . $db . '.sql.gz'), $o);
            if ($code !== 0) {
                throw new WNBError('Tải bản sao lưu từ cloud thất bại: {detail}', ['detail' => WNBShell::tail($o, 3)]);
            }
        }
        self::rmrf($raw);
        return $out;
    }

    private static function deleteBackupTask(array $task): array
    {
        $b = self::findBackup($task['backup_id']);
        if (!$b) {
            return ['message' => WNBLang::msg('Bản sao lưu đã được xóa trước đó'), 'warnings' => []];
        }
        self::removeStorage($b);
        WNBStorage::update('backups', fn($l) => array_values(array_filter($l, fn($x) => $x['id'] !== $b['id'])));
        return ['message' => WNBLang::msg('Đã xóa bản sao lưu {id}', ['id' => $b['id']]), 'warnings' => []];
    }

    public static function deleteDownload(string $id): void
    {
        WNBStorage::update('downloads', function ($l) use ($id) {
            return array_values(array_filter($l, function ($d) use ($id) {
                if ($d['id'] === $id) {
                    $path = (string)$d['path'];
                    // Chỉ xóa file nằm trong thư mục tải về của plugin.
                    if (preg_match('#/(\.downloads|downloads)/dl_[A-Za-z0-9_]+\.tar\.gz$#', $path) && !is_link($path)) {
                        @unlink($path);
                    }
                    return false;
                }
                return true;
            }));
        });
    }

    /* ============================== Kho restic: bảo trì, quét lại ============================== */

    private static function updateDestination(string $id, array $changes): void
    {
        WNBStorage::update('destinations', function ($l) use ($id, $changes) {
            foreach ($l as &$d) {
                if ($d['id'] === $id) {
                    $d = array_merge($d, $changes);
                    return $l;
                }
            }
            return null;
        });
    }

    /**
     * Mỗi ngày một lần với mỗi kho restic: dọn dữ liệu không còn snapshot nào dùng (prune), mỗi tuần kiểm tra
     * tính toàn vẹn (check). Không chen vào lúc kho đang có tác vụ sao lưu.
     */
    public static function scheduleMaintenance(): void
    {
        if (!WNBRestic::usable()) {
            return;
        }
        $queue = WNBStorage::read('queue');
        foreach (WNBStorage::read('destinations') as $d) {
            if (WNBRclone::destFormat($d) !== 'restic' || !WNBRestic::hasKey($d['id'])) {
                continue;
            }
            if (time() - (int)($d['restic_maint']['prune_ts'] ?? 0) < 86400) {
                continue;
            }
            foreach ($queue as $t) {
                if (in_array($t['status'], ['pending', 'running'], true) && ($t['destination_id'] ?? '') === $d['id']) {
                    continue 2;
                }
            }
            if (!array_filter(WNBStorage::read('backups'), fn($b) => $b['destination_id'] === $d['id'])) {
                continue;
            }
            self::enqueue(['type' => 'maintain', 'username' => '', 'initiated_by' => 'cron', 'destination_id' => $d['id']]);
        }
    }

    private static function maintainRepo(array $task): array
    {
        $dest = self::findDestination((string)$task['destination_id']);
        if (!$dest || WNBRclone::destFormat($dest) !== 'restic') {
            throw new WNBError('Kho lưu trữ đã bị xóa.');
        }
        if (!WNBRestic::usable()) {
            throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
        }
        $m = (array)($dest['restic_maint'] ?? []);
        $now = time();
        // Khóa bỏ lại của tiến trình đã chết (máy chủ khởi động lại giữa chừng) sẽ chặn prune.
        WNBShell::run(WNBRestic::cmd($dest, 'unlock'));
        self::step($task, 20, 'Dọn dữ liệu không còn dùng trong kho {dest}', ['dest' => $dest['name']]);
        $code = WNBShell::run(WNBShell::lowPriorityPrefix() . WNBRestic::cmd($dest, 'prune --max-unused 10% --retry-lock 30m', true), $out);
        $m['prune_ts'] = $now;
        $m['prune_ok'] = $code === 0;
        $m['prune_msg'] = $code === 0 ? '' : WNBShell::tail($out, 3);
        if ($code === 0 && $now - (int)($m['check_ts'] ?? 0) >= 7 * 86400) {
            self::step($task, 70, 'Kiểm tra tính toàn vẹn kho {dest}', ['dest' => $dest['name']]);
            $code = WNBShell::run(WNBShell::lowPriorityPrefix() . WNBRestic::cmd($dest, 'check --retry-lock 30m'), $out);
            $m['check_ts'] = $now;
            $m['check_ok'] = $code === 0;
            $m['check_msg'] = $code === 0 ? '' : WNBShell::tail($out, 3);
            $checked = true;
        }
        self::updateDestination($dest['id'], ['restic_maint' => $m]);
        if (!$m['prune_ok']) {
            throw new WNBError('Dọn kho restic {dest} thất bại: {detail}', ['dest' => $dest['name'], 'detail' => $m['prune_msg']]);
        }
        if (isset($checked) && !$m['check_ok']) {
            throw new WNBError('Kiểm tra kho restic {dest} phát hiện lỗi: {detail}', ['dest' => $dest['name'], 'detail' => $m['check_msg']]);
        }
        return ['message' => WNBLang::msg(isset($checked) ? 'Đã dọn và kiểm tra kho {dest}: không có lỗi' : 'Đã dọn kho {dest}', ['dest' => $dest['name']]), 'warnings' => []];
    }

    /** Nạp lại danh sách bản sao lưu từ kho restic (sau khi cài lại máy chủ hoặc mất dữ liệu plugin). */
    private static function rescanRepo(array $task): array
    {
        $dest = self::findDestination((string)$task['destination_id']);
        if (!$dest || WNBRclone::destFormat($dest) !== 'restic') {
            throw new WNBError('Kho lưu trữ đã bị xóa.');
        }
        if (WNB::isPreview()) {
            return ['message' => WNBLang::msg('Đã nạp {n} bản sao lưu từ kho {dest}.', ['n' => 0, 'dest' => $dest['name']]), 'warnings' => []];
        }
        if (!WNBRestic::usable()) {
            throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
        }
        self::step($task, 10, 'Đọc danh sách snapshot trong kho {dest}', ['dest' => $dest['name']]);
        $listedAt = WNB::now();
        $code = WNBShell::run(WNBRestic::cmd($dest, 'snapshots --json --no-lock --tag wnb'), $out);
        $raw = implode("
", $out);
        $start = strpos($raw, '[');
        $snaps = ($code === 0 && $start !== false) ? json_decode(substr($raw, $start), true) : null;
        if (!is_array($snaps)) {
            throw new WNBError('Không đọc được danh sách file trên cloud: {detail}', ['detail' => WNBShell::tail($out, 2)]);
        }
        $records = WNBRestic::recordsFromSnapshots($snaps, $dest, array_column(WNBStorage::read('backups'), 'id'));
        foreach ($records as $i => $rec) {
            self::step($task, 20 + (int)(70 * $i / max(1, count($records))), 'Đọc thông tin bản {id}', ['id' => $rec['id']]);
            $m = [];
            if (WNBShell::run(WNBRestic::cmd($dest, 'dump --no-lock ' . escapeshellarg($rec['restic']['home']) . ' ' . escapeshellarg($rec['restic']['meta_path'] . '/manifest.json')), $m) === 0) {
                $man = json_decode(implode("
", $m), true);
                if (is_array($man)) {
                    foreach (['reseller', 'domain', 'domains', 'emails', 'crons', 'subdomains', 'pointers', 'note'] as $k) {
                        if (isset($man[$k])) {
                            $records[$i][$k] = $man[$k];
                        }
                    }
                }
            }
        }
        $removed = self::dropMissingSnapshots($dest['id'], array_column($snaps, 'id'), $listedAt);
        if ($records) {
            WNBStorage::update('backups', function ($list) use ($records) {
                $list = array_merge($list, $records);
                usort($list, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
                return $list;
            });
        }
        if ($removed) {
            return ['message' => WNBLang::msg('Đã nạp {n} bản sao lưu từ kho {dest}, gỡ {removed} bản không còn trong kho.', ['n' => count($records), 'dest' => $dest['name'], 'removed' => $removed]), 'warnings' => []];
        }
        return ['message' => WNBLang::msg('Đã nạp {n} bản sao lưu từ kho {dest}.', ['n' => count($records), 'dest' => $dest['name']]), 'warnings' => []];
    }

    /**
     * Gỡ khỏi danh sách các bản restic của kho mà snapshot home không còn trong kho (bị xóa ngoài plugin, kho đổi vị trí...).
     * Chỉ xét bản tạo trước lúc đọc danh sách snapshot và không có tác vụ đang dùng. Trả về số bản đã gỡ.
     */
    public static function dropMissingSnapshots(string $destId, array $snapshotIds, string $before): int
    {
        $have = array_fill_keys(array_map('strval', $snapshotIds), true);
        $gone = [];
        foreach (WNBStorage::read('backups') as $b) {
            if (($b['destination_id'] ?? '') === $destId && ($b['format'] ?? '') === 'restic' && !empty($b['restic']['home'])
                && !isset($have[(string)$b['restic']['home']]) && strcmp((string)$b['created_at'], $before) < 0 && !self::backupBusy($b['id'])) {
                $gone[] = $b['id'];
            }
        }
        if ($gone) {
            WNBStorage::update('backups', fn($list) => array_values(array_filter($list, fn($b) => !in_array($b['id'], $gone, true))));
        }
        return count($gone);
    }

    /* ============================== Lịch tự động ============================== */

    /** Thời điểm chạy kế tiếp (timestamp) tính từ lần chạy cuối (hoặc lúc bật lịch). */
    public static function nextRun(array $job): ?int
    {
        if (empty($job['enabled'])) {
            return null;
        }
        $ref = strtotime((string)($job['last_run'] ?: ($job['enabled_at'] ?? $job['created_at'] ?? 'now'))) ?: time();
        [$hh, $mm] = array_map('intval', explode(':', preg_match('/^\d{1,2}:\d{2}$/', (string)$job['schedule_time']) ? $job['schedule_time'] : '02:00'));
        switch ($job['schedule_type']) {
            case 'hourly':
                return $job['last_run'] ? $ref + max(1, (int)$job['interval_hours']) * 3600 : (int)(ceil($ref / 3600) * 3600);
            case 'weekly':
                $t = mktime($hh, $mm, 0, (int)date('n', $ref), (int)date('j', $ref), (int)date('Y', $ref));
                $diff = ((int)$job['schedule_weekday'] - (int)date('w', $t) + 7) % 7;
                $t = strtotime("+{$diff} days", $t);
                return $t <= $ref ? strtotime('+7 days', $t) : $t;
            default:
                $t = mktime($hh, $mm, 0, (int)date('n', $ref), (int)date('j', $ref), (int)date('Y', $ref));
                return $t <= $ref ? strtotime('+1 day', $t) : $t;
        }
    }

    /**
     * Danh sách tài khoản của một lịch, tính lại mỗi lần chạy nên tài khoản tạo sau này cũng được tính:
     * - filter_mode 'exclude' (mặc định): MỌI tài khoản trừ exclude_users / exclude_resellers của lịch.
     * - filter_mode 'include': chỉ include_users và các reseller trong include_resellers (cùng khách của họ).
     * Chọn/loại một reseller = chính reseller đó cùng toàn bộ khách của họ, kể cả khách tạo sau này.
     * Danh sách "không bao giờ tự sao lưu" trong cài đặt luôn bị bỏ qua. Tùy chọn bỏ qua tài khoản tạm khóa.
     */
    public static function jobTargets(array $job): array
    {
        $settings = WNBStorage::settings();
        $never = array_merge((array)($settings['exclude_accounts'] ?? []), self::resellerMembers((array)($settings['exclude_resellers'] ?? [])));
        $all = WNBDirectAdmin::usernames();
        if (self::jobMode($job) === 'include') {
            $pick = array_merge((array)($job['include_users'] ?? []), self::resellerMembers((array)($job['include_resellers'] ?? [])));
            $list = array_intersect($all, $pick);
        } else {
            $list = array_diff($all, (array)($job['exclude_users'] ?? []), self::resellerMembers((array)($job['exclude_resellers'] ?? [])));
        }
        $list = array_values(array_diff($list, $never));
        if (!empty($job['skip_suspended'])) {
            $list = array_values(array_filter($list, fn($u) => !(WNBDirectAdmin::account($u)['suspended'] ?? false)));
        }
        return $list;
    }

    /** Chế độ chọn tài khoản của lịch: 'include' (chỉ những tài khoản được chọn) hoặc 'exclude' (tất cả trừ...). */
    public static function jobMode(array $job): string
    {
        return ($job['filter_mode'] ?? '') === 'include' ? 'include' : 'exclude';
    }

    /** Tài khoản thuộc các reseller trong danh sách (gồm chính reseller). */
    public static function resellerMembers(array $resellers): array
    {
        $out = [];
        foreach (array_unique($resellers) as $r) {
            $out = array_merge($out, WNBDirectAdmin::resellerGroup((string)$r));
        }
        return $out;
    }

    /** Đưa các tài khoản của lịch vào hàng đợi; bỏ qua tài khoản đang có tác vụ (lượt trước chưa xong). */
    public static function runJob(array $job, string $by, string $role = 'admin'): array
    {
        $queued = 0;
        $skipped = 0;
        $runAt = WNB::now();
        foreach (self::jobTargets($job) as $u) {
            if (self::accountBusy($u)) {
                $skipped++;
                continue;
            }
            self::enqueue([
                'type' => 'backup', 'username' => $u, 'initiated_by' => $by, 'role' => $role,
                'destination_id' => $job['destination_id'], 'job_id' => $job['id'], 'job_run' => $runAt, 'note' => $job['name'],
            ]);
            $queued++;
        }
        $jobId = $job['id'];
        WNBStorage::update('jobs', function ($list) use ($jobId, $queued, $skipped, $runAt) {
            foreach ($list as &$j) {
                if ($j['id'] === $jobId) {
                    $j['last_run'] = $runAt;
                    $j['last_result'] = ['total' => $queued, 'skipped' => $skipped, 'done' => 0, 'failed' => 0, 'warnings' => 0, 'finished_at' => null];
                }
            }
            return $list;
        });
        if ($queued === 0) {
            self::maybeJobReport($jobId);
        }
        return ['queued' => $queued, 'skipped' => $skipped];
    }

    public static function runDueJobs(): void
    {
        $lock = WNBStorage::lock('scheduler', false);
        if (!$lock) {
            return;
        }
        try {
            WNBLang::set((string)WNBStorage::settings()['language']);
            foreach (WNBStorage::read('jobs') as $job) {
                $next = self::nextRun($job);
                if ($next !== null && $next <= time()) {
                    self::runJob($job, 'cron');
                }
            }
        } finally {
            WNBStorage::unlock($lock);
        }
    }

    /** Khi mọi tác vụ của lượt chạy lịch đã xong: ghi kết quả và gửi một báo cáo duy nhất. */
    public static function maybeJobReport(string $jobId): void
    {
        $lock = WNBStorage::lock('report', true);
        try {
            $job = null;
            foreach (WNBStorage::read('jobs') as $j) {
                if ($j['id'] === $jobId) {
                    $job = $j;
                }
            }
            if (!$job || empty($job['last_run']) || ($job['reported_run'] ?? '') === $job['last_run']) {
                return;
            }
            $stats = ['total' => 0, 'done' => 0, 'failed' => 0, 'warnings' => 0, 'size_mb' => 0.0, 'failures' => []];
            foreach (WNBStorage::read('queue') as $t) {
                if (($t['job_id'] ?? '') !== $jobId || ($t['job_run'] ?? '') !== $job['last_run']) {
                    continue;
                }
                if (in_array($t['status'], ['pending', 'running'], true)) {
                    return; // lượt chạy chưa xong
                }
                $stats['total']++;
                if ($t['status'] === 'failed') {
                    $stats['failed']++;
                    $stats['failures'][] = $t['username'] . ': ' . WNBLang::render($t['message']);
                } else {
                    $stats['done']++;
                    $stats['warnings'] += (int)$t['warnings'];
                }
            }
            $skipped = (int)($job['last_result']['skipped'] ?? 0);
            WNBStorage::update('jobs', function ($l) use ($jobId, $job, $stats, $skipped) {
                foreach ($l as &$j) {
                    if ($j['id'] === $jobId) {
                        $j['reported_run'] = $job['last_run'];
                        $j['last_result'] = ['total' => $stats['total'], 'skipped' => $skipped, 'done' => $stats['done'], 'failed' => $stats['failed'], 'warnings' => $stats['warnings'], 'finished_at' => WNB::now()];
                    }
                }
                return $l;
            });

            $mode = (string)WNBStorage::settings()['notify_job_report'];
            if ($mode === 'off' || ($mode === 'errors' && $stats['failed'] === 0)) {
                return;
            }
            WNBLang::set((string)WNBStorage::settings()['language']);
            $title = wnb_t('Lịch "{job}": {done}/{total} thành công, {failed} lỗi', ['job' => $job['name'], 'done' => $stats['done'], 'total' => $stats['total'], 'failed' => $stats['failed']]);
            $body = '';
            if ($skipped) {
                $body .= wnb_t('Bỏ qua {n} tài khoản đang có tác vụ chạy.', ['n' => $skipped]) . "\n";
            }
            if ($stats['warnings']) {
                $body .= wnb_t('Cảnh báo: {n}', ['n' => $stats['warnings']]) . "\n";
            }
            foreach (array_slice($stats['failures'], 0, 20) as $f) {
                $body .= '• ' . $f . "\n";
            }
            self::notify($title, $body);
        } finally {
            WNBStorage::unlock($lock);
        }
    }

    /** Dọn file tải về hết hạn, staging bị bỏ dở, file tạm và log cũ. */
    public static function cleanup(): void
    {
        $now = time();
        foreach (WNBStorage::read('downloads') as $d) {
            if (strtotime((string)$d['expires_at']) < $now) {
                self::deleteDownload($d['id']);
            }
        }
        foreach (glob(WNBStorage::path('tmp/*')) ?: [] as $f) {
            if (filemtime($f) < $now - 3600) {
                @unlink($f);
            }
        }
        foreach (glob(WNBStorage::path('cache/idx_*')) ?: [] as $f) {
            if (filemtime($f) < $now - 86400) {
                @unlink($f);
            }
        }
        foreach (glob(WNBStorage::path('logs/*.log')) ?: [] as $f) {
            if (filemtime($f) < $now - 60 * 86400) {
                @unlink($f);
            }
        }
        $staging = rtrim((string)WNBStorage::settings()['local_backup_root'], '/') . '/.staging';
        $running = array_filter(WNBStorage::read('queue'), fn($t) => $t['status'] === 'running');
        if (!$running) {
            foreach (glob($staging . '/*') ?: [] as $dir) {
                if (filemtime($dir) < $now - 6 * 3600) {
                    self::rmrf($dir);
                }
            }
        }
    }

    /* ============================== Giám sát ============================== */

    /** Số giờ giữa hai lượt chạy của lịch. */
    private static function jobIntervalHours(array $job): int
    {
        if ($job['schedule_type'] === 'hourly') {
            return max(1, (int)$job['interval_hours']);
        }
        return $job['schedule_type'] === 'weekly' ? 168 : 24;
    }

    /**
     * Các nguy cơ mất dữ liệu đang có: [['code' => khóa ổn định để chống gửi trùng, 'msg' => WNBLang::msg]].
     * Dùng cho banner Tổng quan và cảnh báo định kỳ qua Telegram/email.
     */
    public static function healthIssues(): array
    {
        $issues = [];
        $settings = WNBStorage::settings();
        $jobs = array_values(array_filter(WNBStorage::read('jobs'), fn($j) => !empty($j['enabled'])));
        if (!$jobs) {
            return [['code' => 'no_job', 'msg' => WNBLang::msg('Chưa bật lịch sao lưu tự động nào: máy chủ đang KHÔNG được tự sao lưu.')]];
        }
        $now = time();

        // 1. Ổ đĩa: thiếu chỗ thì lượt sao lưu kế tiếp sẽ bị từ chối hoặc lỗi giữa chừng.
        $checks = [];
        foreach ($jobs as $j) {
            $dest = self::findDestination((string)$j['destination_id']);
            if (!$dest) {
                $issues[] = ['code' => 'dest_missing:' . $j['id'], 'msg' => WNBLang::msg('Lịch "{job}" dùng kho lưu trữ đã bị xóa.', ['job' => $j['name']])];
                continue;
            }
            if (WNBRclone::destFormat($dest) === 'restic') {
                if (!WNB::isPreview() && !WNBRestic::usable()) {
                    $issues[] = ['code' => 'restic_missing', 'msg' => WNBLang::msg('Lịch "{job}" dùng kho restic nhưng máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root.', ['job' => $j['name'], 'min' => WNBRestic::MIN_VERSION])];
                }
                $m = (array)($dest['restic_maint'] ?? []);
                if (isset($m['check_ok']) && !$m['check_ok']) {
                    $issues[] = ['code' => 'restic_check:' . $dest['id'], 'msg' => WNBLang::msg('Kiểm tra kho restic {dest} phát hiện lỗi: {detail}', ['dest' => $dest['name'], 'detail' => (string)($m['check_msg'] ?? '')])];
                }
                if (isset($m['prune_ok']) && !$m['prune_ok']) {
                    $issues[] = ['code' => 'restic_prune:' . $dest['id'], 'msg' => WNBLang::msg('Dọn kho restic {dest} thất bại: {detail}', ['dest' => $dest['name'], 'detail' => (string)($m['prune_msg'] ?? '')])];
                }
            }
            if ($dest['type'] === 'local') {
                $path = rtrim((string)$dest['path'], '/');
                $need = [(float)$settings['min_free_gb'], self::MIN_FREE_INODES];
            } elseif (WNBRclone::destFormat($dest) === 'restic') {
                $path = rtrim((string)$settings['local_backup_root'], '/');
                $need = [self::RESTIC_MIN_FREE_GB, self::ARCHIVE_MIN_FREE_INODES];
            } else {
                $path = rtrim((string)$settings['local_backup_root'], '/');
                $need = WNBRclone::destFormat($dest) === 'archive' ? [self::ARCHIVE_MIN_FREE_GB, self::ARCHIVE_MIN_FREE_INODES] : [(float)$settings['min_free_gb'], self::MIN_FREE_INODES];
            }
            $cur = $checks[$path] ?? [0.0, 0];
            $checks[$path] = [max($cur[0], $need[0]), max($cur[1], $need[1])];
        }
        foreach ($checks as $path => [$minGb, $minInodes]) {
            $probe = $path;
            while ($probe !== '' && $probe !== '/' && !is_dir($probe)) {
                $probe = dirname($probe);
            }
            $free = @disk_free_space($probe ?: '/');
            if ($free !== false && $minGb > 0 && $free / 1073741824 < $minGb) {
                $issues[] = ['code' => 'disk:' . $path, 'msg' => WNBLang::msg('Ổ đĩa chứa {path} chỉ còn {free} GB trống (cần tối thiểu {min} GB): lịch sao lưu sẽ không chạy được.', ['path' => $path, 'free' => round($free / 1073741824, 2), 'min' => $minGb])];
            }
            $inodes = WNB::isPreview() ? null : self::freeInodes($probe ?: '/');
            if ($inodes !== null && $inodes < $minInodes) {
                $issues[] = ['code' => 'inode:' . $path, 'msg' => WNBLang::msg('Ổ đĩa chứa {path} chỉ còn {n} inode trống (cần tối thiểu {min}): lịch sao lưu sẽ không chạy được.', ['path' => $path, 'n' => $inodes, 'min' => $minInodes])];
            }
        }

        // 2. Lịch không chạy đúng giờ (cron hỏng) hoặc lượt gần nhất có lỗi.
        foreach ($jobs as $j) {
            $next = self::nextRun($j);
            if ($next !== null && $next < $now - 7200) {
                $issues[] = ['code' => 'overdue:' . $j['id'], 'msg' => WNBLang::msg('Lịch "{job}" lẽ ra phải chạy lúc {time} nhưng chưa chạy. Kiểm tra cron /etc/cron.d/webnowbackup.', ['job' => $j['name'], 'time' => date('Y-m-d H:i', $next)])];
            }
            $r = $j['last_result'] ?? null;
            if ($r && !empty($r['finished_at']) && (int)$r['failed'] > 0) {
                $issues[] = ['code' => 'failed:' . $j['id'] . ':' . ($j['last_run'] ?? ''), 'msg' => WNBLang::msg('Lịch "{job}": lượt chạy gần nhất lỗi {failed}/{total} tài khoản.', ['job' => $j['name'], 'failed' => $r['failed'], 'total' => $r['total']])];
            }
        }

        // 3. Tài khoản quá hạn: không có bản sao lưu nào mới hơn (chu kỳ lịch + 12 giờ).
        $limit = [];
        foreach ($jobs as $j) {
            $h = self::jobIntervalHours($j) + 12;
            foreach (self::jobTargets($j) as $u) {
                $limit[$u] = min($limit[$u] ?? PHP_INT_MAX, $h);
            }
        }
        $last = [];
        foreach (WNBStorage::read('backups') as $b) {
            if (($b['type'] ?? '') === 'backup') {
                $t = strtotime((string)$b['created_at']) ?: 0;
                $last[$b['username']] = max($last[$b['username']] ?? 0, $t);
            }
        }
        $stale = [];
        foreach ($limit as $u => $h) {
            $since = $now - $h * 3600;
            $created = (int)(WNBDirectAdmin::account($u)['created_ts'] ?? 0);
            if (($last[$u] ?? 0) < $since && $created < $since) {
                $stale[] = $u;
            }
        }
        if ($stale) {
            sort($stale);
            $issues[] = ['code' => 'stale:' . implode(',', $stale), 'msg' => WNBLang::msg('{n} tài khoản quá hạn chưa có bản sao lưu mới: {list}', ['n' => count($stale), 'list' => implode(', ', array_slice($stale, 0, 15)) . (count($stale) > 15 ? ', …' : '')])];
        }
        return $issues;
    }

    /**
     * Chạy từ cron (tối đa 30 phút/lần): gửi cảnh báo khi xuất hiện nguy cơ mới, nhắc lại mỗi 24 giờ
     * khi vẫn chưa xử lý, và báo khi mọi thứ đã ổn trở lại.
     */
    public static function healthCheck(bool $force = false): void
    {
        $state = WNBStorage::read('health');
        $now = time();
        // Dựa cả vào mtime: khi ổ đầy không ghi được JSON thì touch vẫn chặn được gửi dồn dập mỗi phút.
        $file = WNBStorage::path('health.json');
        $last = max((int)($state['checked_at'] ?? 0), is_file($file) ? (int)filemtime($file) : 0);
        if (!$force && $now - $last < 1800) {
            return;
        }
        WNBLang::set((string)WNBStorage::settings()['language']);
        $issues = self::healthIssues();
        $sig = $issues ? md5(implode('|', array_column($issues, 'code'))) : '';
        $prev = (string)($state['signature'] ?? '');
        $state['checked_at'] = $now;
        $send = '';
        if ($sig !== '' && ($sig !== $prev || $now - (int)($state['sent_at'] ?? 0) >= 86400)) {
            $send = 'alert';
        } elseif ($sig === '' && $prev !== '') {
            $send = 'ok';
        }
        if ($send !== '' && !empty(WNBStorage::settings()['notify_health'])) {
            if ($send === 'alert') {
                $title = wnb_t('Cảnh báo sao lưu: {n} vấn đề', ['n' => count($issues)]);
                $body = '';
                foreach ($issues as $i) {
                    $body .= '• ' . WNBLang::render($i['msg']) . "\n";
                }
            } else {
                $title = wnb_t('Sao lưu đã hoạt động bình thường trở lại');
                $body = '';
            }
            self::notify($title, $body);
            $state['sent_at'] = $now;
        }
        $state['signature'] = $sig;
        if (!WNBStorage::write('health', $state)) {
            @touch($file);
        }
    }

    /* ============================== Thông báo ============================== */

    public static function hostname(): string
    {
        return trim((string)@file_get_contents('/etc/hostname')) ?: (gethostname() ?: 'server');
    }

    /** Tên máy chủ trong thông báo: tên đặt trong Cài đặt (kèm hostname), mặc định là hostname. */
    public static function serverLabel(): string
    {
        $name = trim((string)(WNBStorage::settings()['server_name'] ?? ''));
        $host = self::hostname();
        return $name === '' || strcasecmp($name, $host) === 0 ? $host : $name . ' (' . $host . ')';
    }

    /**
     * Dựng thông báo cho từng kênh; mọi tin đều mang tên máy chủ để phân biệt khi nhiều máy chủ gửi về một nơi.
     * @return array{subject: string, email: string, telegram: string} telegram dạng HTML (parse_mode=HTML)
     */
    public static function message(string $title, string $details): array
    {
        $label = self::serverLabel();
        $details = rtrim($details);
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return [
            'subject' => '[WebNowBackup · ' . $label . '] ' . $title,
            'email' => $title . "\n" . wnb_t('Máy chủ: {host}', ['host' => $label]) . "\n" . ($details !== '' ? "\n" . $details . "\n" : ''),
            'telegram' => '🖥 <b>' . $h($label) . '</b>' . "\n" . '<b>' . $h($title) . '</b>' . ($details !== '' ? "\n" . $h(mb_strimwidth_safe($details, 3500)) : ''),
        ];
    }

    /** Lỗi của tác vụ lẻ (không thuộc lịch). */
    private static function notifyFailure(array $task, array $msg): void
    {
        if (empty(WNBStorage::settings()['notify_on_failure'])) {
            return;
        }
        WNBLang::set((string)WNBStorage::settings()['language']);
        $title = wnb_t('Lỗi {type} {user}', ['type' => $task['type'], 'user' => $task['username']]);
        $body = wnb_t('Tác vụ: {id}', ['id' => $task['id']]) . "\n" . wnb_t('Lỗi: {error}', ['error' => WNBLang::render($msg)]) . "\n";
        self::notify($title, $body);
    }

    /** Gửi qua mọi kênh đã cấu hình. $details: các dòng chi tiết dưới tiêu đề. Trả về kết quả từng kênh. */
    public static function notify(string $title, string $details): array
    {
        $res = [];
        if (WNB::isPreview()) {
            return ['preview' => true];
        }
        $s = WNBStorage::settings();
        $msg = self::message($title, $details);
        $to = trim((string)$s['notify_email']);
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $res['email'] = @mail($to, '=?UTF-8?B?' . base64_encode($msg['subject']) . '?=', $msg['email'], "Content-Type: text/plain; charset=UTF-8\r\n");
        }
        if ($s['telegram_token'] !== '' && $s['telegram_chat_id'] !== '') {
            $res['telegram'] = self::telegram((string)$s['telegram_token'], (string)$s['telegram_chat_id'], $msg['telegram'], true);
        }
        return $res;
    }

    /**
     * Gửi Telegram bằng curl; URL chứa token được đưa qua stdin (-K -) để không lộ trên danh sách tiến trình.
     * @return true|string true hoặc nội dung lỗi
     */
    public static function telegram(string $token, string $chatId, string $text, bool $html = false)
    {
        $curl = WNBShell::which('curl');
        if ($curl === '') {
            return 'curl not found';
        }
        $q = fn(string $v) => '"' . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', '\\n'], $v) . '"';
        $cfg = 'url = ' . $q('https://api.telegram.org/bot' . $token . '/sendMessage') . "\n"
            . 'data-urlencode = ' . $q('chat_id=' . $chatId) . "\n"
            . 'data-urlencode = ' . $q('text=' . ($html ? $text : mb_strimwidth_safe($text, 3800))) . "\n"
            . ($html ? 'data = "parse_mode=HTML"' . "\n" : '')
            . 'data = "disable_web_page_preview=true"' . "\n"
            . "max-time = 20\nsilent\nshow-error\n";
        $code = WNBShell::runWithInput(escapeshellarg($curl) . ' -K -', $cfg, $out);
        $raw = implode("\n", $out);
        if ($code === 0 && strpos($raw, '"ok":true') !== false) {
            return true;
        }
        $j = json_decode($raw, true);
        return is_array($j) && isset($j['description']) ? (string)$j['description'] : ($raw !== '' ? substr($raw, 0, 200) : 'curl exit ' . $code);
    }
}

/** Cắt chuỗi UTF-8 theo số ký tự mà không cần mbstring. */
function mb_strimwidth_safe(string $s, int $max): string
{
    return WNB::ulen($s) > $max ? WNB::usub($s, $max - 1) . '…' : $s;
}
