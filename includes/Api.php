<?php
/**
 * WebNowBackup - API phía root: xác thực danh tính, phân quyền, chống CSRF, gọi engine.
 * Danh tính ($user) đến từ UID thật của tiến trình gọi helper (hoặc preview), KHÔNG từ tham số request.
 */

final class WNBApi
{
    private const ROLES = ['admin', 'reseller', 'user'];
    private const READ_ACTIONS = ['state', 'browse', 'task_log'];

    /** Kiểm tra user có thật sự được dùng cấp $role không (dựa vào usertype trong DirectAdmin). */
    public static function verifyIdentity(string $user, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new WNBError('Cấp truy cập không hợp lệ.');
        }
        if (!WNBDirectAdmin::exists($user)) {
            throw new WNBError('Tài khoản {user} không phải tài khoản DirectAdmin.', ['user' => $user]);
        }
        $type = WNBDirectAdmin::usertype($user);
        if ($role === 'admin' && $type !== 'admin') {
            throw new WNBError('Chỉ Admin được dùng trang này.');
        }
        if ($role === 'reseller' && !in_array($type, ['reseller', 'admin'], true)) {
            throw new WNBError('Chỉ Reseller được dùng trang này.');
        }
    }

    public static function csrfToken(string $user, string $role): string
    {
        return hash_hmac('sha256', $user . '|' . $role, WNBStorage::secret());
    }

    /** @return array{ok:bool,data?:array,error?:string} */
    public static function handle(string $user, string $role, array $params): array
    {
        WNBLang::set((string)($params['lang'] ?? 'vi'));
        try {
            self::verifyIdentity($user, $role);
            $action = (string)($params['action'] ?? 'state');
            if (!in_array($action, self::READ_ACTIONS, true) && !hash_equals(self::csrfToken($user, $role), (string)($params['csrf'] ?? ''))) {
                throw new WNBError('Phiên làm việc đã hết hạn, hãy tải lại trang.');
            }
            $data = self::dispatch($user, $role, $action, $params);
            return ['ok' => true, 'data' => $data];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function dispatch(string $user, string $role, string $action, array $p): array
    {
        $perms = self::perms($user, $role);
        $str = fn(string $k, string $def = '') => trim((string)($p[$k] ?? $def));
        $list = fn(string $k) => self::listParam($p, $k);
        $done = function (string $key, array $vars = [], array $extra = []) use ($user, $role) {
            return array_merge(['message' => wnb_t($key, $vars), 'state' => self::state($user, $role)], $extra);
        };

        switch ($action) {
            case 'state':
                return self::state($user, $role);

            case 'browse':
                $b = self::backupFor($user, $role, $str('backup_id'));
                $dest = WNBEngine::findDestination($b['destination_id']) ?? ['type' => $b['destination_type']];
                return ['items' => WNBRclone::listHome($b, $dest, $str('path'))];

            case 'task_log':
                $task = null;
                foreach (WNBStorage::read('queue') as $t) {
                    if ($t['id'] === $str('task_id')) {
                        $task = $t;
                    }
                }
                if (!$task || !self::canAccess($user, $role, $task['username'])) {
                    throw new WNBError('Không tìm thấy tác vụ.');
                }
                return ['log' => WNBStorage::readLog($task['id'])];

            case 'backup_now':
                if (!$perms['can_backup']) {
                    throw new WNBError('Bạn không có quyền tạo bản sao lưu.');
                }
                $u = $str('username');
                self::assertAccess($user, $role, $u);
                $dest = WNBEngine::findDestination($str('destination_id', 'dest_local'));
                if (!$dest) {
                    throw new WNBError('Kho lưu trữ không tồn tại.');
                }
                if ($role === 'reseller') {
                    self::assertResellerQuota($user);
                }
                self::assertNoActiveTask($u);
                WNBEngine::enqueue(['type' => 'backup', 'username' => $u, 'initiated_by' => $user, 'role' => $role, 'destination_id' => $dest['id']]);
                WNBEngine::kick();
                return $done('Đã đưa {user} vào hàng đợi sao lưu.', ['user' => $u]);

            case 'snapshot':
                $u = $role === 'user' ? $user : $str('username');
                self::assertAccess($user, $role, $u);
                if (!$perms['can_snapshot']) {
                    throw new WNBError('Tính năng snapshot đang bị tắt cho tài khoản của bạn.');
                }
                if ($role === 'reseller') {
                    self::assertResellerQuota($user);
                }
                self::assertNoActiveTask($u);
                $settings = WNBStorage::settings();
                $dest = WNBEngine::findDestination((string)$settings['snapshot_destination']) ?? WNBEngine::findDestination('dest_local');
                WNBEngine::enqueue(['type' => 'snapshot', 'username' => $u, 'initiated_by' => $user, 'role' => $role, 'destination_id' => $dest['id'], 'note' => WNB::usub($str('note'), 120)]);
                WNBEngine::kick();
                return $done('Đang tạo snapshot, theo dõi tiến độ ở mục Tác vụ.');

            case 'restore':
                if (!$perms['can_restore']) {
                    throw new WNBError('Bạn không có quyền khôi phục.');
                }
                $b = self::backupFor($user, $role, $str('backup_id'));
                $scope = $str('scope');
                if (!in_array($scope, ['full', 'files', 'databases', 'emails', 'dns', 'cron'], true)) {
                    throw new WNBError('Phạm vi khôi phục không hợp lệ.');
                }
                if ($scope === 'full' && $str('confirm') !== $b['username']) {
                    throw new WNBError('Hãy nhập đúng tên tài khoản để xác nhận khôi phục toàn bộ.');
                }
                $acc = WNBDirectAdmin::account($b['username'], true);
                if (!$acc) {
                    throw new WNBError('Tài khoản {user} không còn tồn tại.', ['user' => $b['username']]);
                }
                $items = $list('items');
                WNBEngine::restoreSelection($b, $acc, $scope, $items);
                self::assertNoActiveTask($b['username']);
                WNBEngine::enqueue(['type' => 'restore', 'username' => $b['username'], 'initiated_by' => $user, 'role' => $role, 'backup_id' => $b['id'], 'destination_id' => $b['destination_id'], 'scope' => $scope, 'items' => $items]);
                WNBEngine::kick();
                return $done('Đã bắt đầu khôi phục {scope}.', ['scope' => WNBLang::msg(WNBEngine::scopeKey($scope))]);

            case 'download_create':
                if (!$perms['can_download']) {
                    throw new WNBError('Tính năng tải bản sao lưu đang bị tắt.');
                }
                $b = self::backupFor($user, $role, $str('backup_id'));
                WNBEngine::enqueue(['type' => 'download', 'username' => $b['username'], 'initiated_by' => $user, 'role' => $role, 'backup_id' => $b['id']]);
                WNBEngine::kick();
                return $done('Đang đóng gói, file sẽ xuất hiện ở mục Tải về.');

            case 'download_delete':
                $d = self::downloadFor($user, $role, $str('id'));
                WNBEngine::deleteDownload($d['id']);
                return $done('Đã xóa file tải về.');

            case 'backup_delete':
                $b = self::backupFor($user, $role, $str('backup_id'));
                if ($role === 'user' && $b['type'] !== 'snapshot') {
                    throw new WNBError('Bạn chỉ được xóa snapshot của chính mình.');
                }
                if ($role === 'reseller' && !$perms['can_backup']) {
                    throw new WNBError('Bạn không có quyền xóa bản sao lưu.');
                }
                if (WNBEngine::backupBusy($b['id'])) {
                    throw new WNBError('Bản sao lưu đang được khôi phục hoặc đóng gói, hãy đợi tác vụ hoàn tất.');
                }
                WNBEngine::enqueue(['type' => 'delete', 'username' => $b['username'], 'initiated_by' => $user, 'role' => $role, 'backup_id' => $b['id']]);
                WNBEngine::kick();
                return $done('Đang xóa bản sao lưu.');

            case 'dest_save':
                self::requireAdmin($role);
                [$key, $vars, $ok] = self::saveDestination($p);
                return $done($key, $vars, ['test_ok' => $ok]);

            case 'dest_test':
                self::requireAdmin($role);
                $dest = WNBEngine::findDestination($str('id'));
                if (!$dest) {
                    throw new WNBError('Kho lưu trữ không tồn tại.');
                }
                $res = WNBRclone::test($dest);
                self::updateDestination($dest['id'], ['free_gb' => $res['free_gb'], 'last_test' => ['ok' => $res['ok'], 'message' => $res['message'], 'at' => WNB::now()]]);
                return $done('{detail}', ['detail' => $res['message']], ['test_ok' => $res['ok']]);

            case 'dest_delete':
                self::requireAdmin($role);
                $id = $str('id');
                if ($id === 'dest_local') {
                    throw new WNBError('Không thể xóa kho mặc định.');
                }
                foreach (WNBStorage::read('jobs') as $j) {
                    if ($j['destination_id'] === $id) {
                        throw new WNBError('Lịch "{job}" đang dùng kho này.', ['job' => $j['name']]);
                    }
                }
                $count = count(array_filter(WNBStorage::read('backups'), fn($b) => $b['destination_id'] === $id));
                if ($count > 0) {
                    throw new WNBError('Còn {n} bản sao lưu trên kho này. Hãy xóa chúng trước.', ['n' => $count]);
                }
                $dest = WNBEngine::findDestination($id);
                if ($dest && $dest['type'] !== 'local') {
                    WNBRclone::deleteRemote($dest['remote']);
                }
                WNBStorage::update('destinations', fn($l) => array_values(array_filter($l, fn($d) => $d['id'] !== $id)));
                return $done('Đã xóa kho lưu trữ.');

            case 'job_save':
                self::requireAdmin($role);
                return $done('Đã lưu lịch "{job}".', ['job' => self::saveJob($p)]);

            case 'job_toggle':
                self::requireAdmin($role);
                $id = $str('id');
                $enabled = $str('enabled') === '1';
                self::findJob($id);
                WNBStorage::update('jobs', function ($l) use ($id, $enabled) {
                    foreach ($l as &$j) {
                        if ($j['id'] === $id) {
                            $j['enabled'] = $enabled;
                            if ($enabled) {
                                $j['enabled_at'] = WNB::now();
                                $j['last_run'] = null;
                            }
                        }
                    }
                    return $l;
                });
                return $done($enabled ? 'Đã bật lịch.' : 'Đã tắt lịch.');

            case 'job_run':
                self::requireAdmin($role);
                $r = WNBEngine::runJob(self::findJob($str('id')), $user);
                WNBEngine::kick();
                return $done($r['skipped'] ? 'Đã đưa {n} tài khoản vào hàng đợi, bỏ qua {skipped} tài khoản đang có tác vụ.' : 'Đã đưa {n} tài khoản vào hàng đợi.', ['n' => $r['queued'], 'skipped' => $r['skipped']]);

            case 'job_preview':
                // Số tài khoản lịch sẽ sao lưu, để hiển thị ngay trong form.
                self::requireAdmin($role);
                return ['users' => WNBEngine::jobTargets(self::jobFromParams($p, null))];

            case 'job_delete':
                self::requireAdmin($role);
                $id = $str('id');
                WNBStorage::update('jobs', fn($l) => array_values(array_filter($l, fn($j) => $j['id'] !== $id)));
                return $done('Đã xóa lịch.');

            case 'settings_save':
                self::requireAdmin($role);
                self::saveSettings($p);
                return $done('Đã lưu cài đặt.');

            case 'notify_test':
                self::requireAdmin($role);
                $s = WNBStorage::settings();
                if (trim((string)$s['notify_email']) === '' && ($s['telegram_token'] === '' || $s['telegram_chat_id'] === '')) {
                    throw new WNBError('Chưa cấu hình email hoặc Telegram.');
                }
                $res = WNBEngine::notify(wnb_t('[WebNowBackup] Tin nhắn thử'), wnb_t('[WebNowBackup] Tin nhắn thử') . "\n" . wnb_t('Thông báo từ WebNowBackup đã hoạt động.'));
                if (!empty($res['preview'])) {
                    return $done('Preview: không gửi thông báo thật.');
                }
                if (isset($res['telegram']) && $res['telegram'] !== true) {
                    throw new WNBError('Telegram báo lỗi: {detail}', ['detail' => $res['telegram']]);
                }
                return $done('Đã gửi tin nhắn thử.');

            case 'acl_save':
                $target = $role === 'admin' ? $str('target') : $user;
                if ($role === 'user') {
                    throw new WNBError('Không có quyền.');
                }
                if ($target !== 'admin' && WNBDirectAdmin::usertype($target) !== 'reseller') {
                    throw new WNBError('Reseller không hợp lệ.');
                }
                $fields = $role === 'admin'
                    ? ['allow_restore', 'allow_backup', 'allow_user_restore', 'allow_user_snapshots', 'allow_user_downloads']
                    : ['allow_user_restore', 'allow_user_snapshots', 'allow_user_downloads'];
                WNBStorage::update('acl', function ($acl) use ($target, $fields, $p, $role) {
                    $cur = array_merge(self::aclDefaults(), $acl[$target] ?? []);
                    foreach ($fields as $f) {
                        if (isset($p[$f])) {
                            $cur[$f] = (string)$p[$f] === '1';
                        }
                    }
                    if ($role === 'admin' && isset($p['quota_gb'])) {
                        $cur['quota_gb'] = max(0, (float)$p['quota_gb']);
                    }
                    $acl[$target] = $cur;
                    return $acl;
                });
                return $done('Đã lưu phân quyền.');
        }
        throw new WNBError('Thao tác không được hỗ trợ.');
    }

    private static function listParam(array $p, string $k): array
    {
        $v = $p[$k] ?? [];
        if (is_string($v)) {
            $v = $v === '' ? [] : (json_decode($v, true) ?? explode(',', $v));
        }
        return array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), (array)$v), 'strlen')));
    }

    /* ---------------------------- Trạng thái ---------------------------- */

    public static function state(string $user, string $role): array
    {
        $visibleUsers = self::visibleUsernames($user, $role);
        $visibleSet = array_flip($visibleUsers);
        $backups = [];
        $lastByUser = [];
        $countByUser = [];
        $totalMb = 0.0;
        foreach (WNBStorage::read('backups') as $b) {
            if (!isset($visibleSet[$b['username']]) || !self::ownedByCurrent($role, $b)) {
                continue;
            }
            unset($b['storage_path'], $b['owner_key']);
            $b['warnings'] = array_map([WNBLang::class, 'render'], (array)($b['warnings'] ?? []));
            $backups[] = $b;
            $totalMb += (float)$b['size_mb'];
            $countByUser[$b['username']] = ($countByUser[$b['username']] ?? 0) + 1;
            if (!isset($lastByUser[$b['username']])) {
                $lastByUser[$b['username']] = $b['created_at'];
            }
        }

        $accounts = [];
        foreach ($visibleUsers as $u) {
            $acc = WNBDirectAdmin::account($u);
            if ($acc) {
                $acc['last_backup_at'] = $lastByUser[$u] ?? null;
                $acc['backups_count'] = $countByUser[$u] ?? 0;
                $accounts[] = $acc;
            }
        }

        $queue = [];
        $running = 0;
        $failed24 = 0;
        $dayAgo = date('Y-m-d H:i:s', time() - 86400);
        foreach (WNBStorage::read('queue') as $t) {
            if (!isset($visibleSet[$t['username']])) {
                continue;
            }
            unset($t['pid'], $t['lang']);
            $t['message'] = WNBLang::render($t['message'] ?? '');
            $t += ['warnings' => 0, 'progress' => 0, 'job_id' => '', 'initiated_by' => '', 'created_at' => '', 'started_at' => null, 'finished_at' => null];
            if (in_array($t['status'], ['pending', 'running'], true)) {
                $running++;
            }
            if ($t['status'] === 'failed' && $t['created_at'] >= $dayAgo) {
                $failed24++;
            }
            if (count($queue) < 200) {
                $queue[] = $t;
            }
        }

        $downloads = [];
        foreach (WNBStorage::read('downloads') as $d) {
            if (isset($visibleSet[$d['username']]) && self::ownedByCurrent($role, $d)) {
                unset($d['path'], $d['owner_key']);
                $downloads[] = $d;
            }
        }

        $destinations = [];
        foreach (WNBStorage::read('destinations') as $d) {
            if ($role === 'admin') {
                $params = $d['type'] !== 'local' ? WNBRclone::readRemote($d['remote']) : [];
                foreach (WNBRclone::SECRET_FIELDS as $f) {
                    if (isset($params[$f])) {
                        $params[$f] = '';
                        $d['has_' . $f] = true;
                    }
                }
                unset($params['type']);
                $d['params'] = $params;
                if (isset($d['last_test']['message'])) {
                    $d['last_test']['message'] = WNBLang::render($d['last_test']['message']);
                }
                $destinations[] = $d;
            } else {
                $destinations[] = ['id' => $d['id'], 'name' => $d['name'], 'type' => $d['type']];
            }
        }

        $state = [
            'me' => ['user' => $user, 'role' => $role],
            'csrf' => self::csrfToken($user, $role),
            'version' => WNB_VERSION,
            'preview' => WNB::isPreview(),
            'now' => WNB::now(),
            'engine' => ['ready' => true, 'rclone' => WNBRclone::installed(), 'rclone_version' => WNBRclone::version()],
            'perms' => self::perms($user, $role),
            'accounts' => $accounts,
            'backups' => $backups,
            'queue' => $queue,
            'downloads' => $downloads,
            'destinations' => $destinations,
            'stats' => [
                'accounts' => count($accounts),
                'backups' => count($backups),
                'size_mb' => round($totalMb, 1),
                'running' => $running,
                'failed_24h' => $failed24,
            ],
        ];

        $settings = WNBStorage::settings();
        if ($role === 'admin') {
            // Bí mật Telegram chỉ được ghi đè, không bao giờ trả về giao diện.
            $state['settings'] = $settings;
            $state['settings']['telegram_configured'] = $settings['telegram_token'] !== '' && $settings['telegram_chat_id'] !== '';
            unset($state['settings']['telegram_token'], $state['settings']['telegram_chat_id']);
            $state['jobs'] = array_map(function ($j) {
                $j = array_merge(['exclude_users' => [], 'exclude_resellers' => [], 'skip_suspended' => false], $j);
                unset($j['target'], $j['users'], $j['resellers']);
                $next = WNBEngine::nextRun($j);
                $j['next_run'] = $next ? date('Y-m-d H:i:s', $next) : null;
                $j['target_count'] = count(WNBEngine::jobTargets($j));
                return $j;
            }, WNBStorage::read('jobs'));
            $acl = WNBStorage::read('acl');
            $resellers = ['admin'];
            foreach ($accounts as $a) {
                if ($a['usertype'] === 'reseller') {
                    $resellers[] = $a['username'];
                }
            }
            $state['acl'] = [];
            foreach ($resellers as $r) {
                $state['acl'][$r] = array_merge(self::aclDefaults(), $acl[$r] ?? []);
            }
        } else {
            $state['settings'] = ['max_snapshots_per_user' => $settings['max_snapshots_per_user'], 'download_ttl_hours' => $settings['download_ttl_hours']];
            $state['jobs'] = [];
            $state['acl'] = $role === 'reseller' ? [$user => self::aclFor($user)] : [];
        }
        return $state;
    }

    /* ---------------------------- Phân quyền ---------------------------- */

    public static function aclDefaults(): array
    {
        return [
            'allow_restore' => true,
            'allow_backup' => true,
            'allow_user_restore' => true,
            'allow_user_snapshots' => true,
            'allow_user_downloads' => true,
            'quota_gb' => 0,
        ];
    }

    private static function aclFor(string $owner): array
    {
        return array_merge(self::aclDefaults(), WNBStorage::read('acl')[$owner] ?? []);
    }

    public static function perms(string $user, string $role): array
    {
        if ($role === 'admin') {
            return ['can_backup' => true, 'can_restore' => true, 'can_snapshot' => true, 'can_download' => true, 'can_policy' => true];
        }
        if ($role === 'reseller') {
            $acl = self::aclFor($user);
            return ['can_backup' => $acl['allow_backup'], 'can_restore' => $acl['allow_restore'], 'can_snapshot' => $acl['allow_backup'], 'can_download' => true, 'can_policy' => true];
        }
        $acc = WNBDirectAdmin::account($user);
        $owner = $acc['creator'] ?? 'admin';
        $acl = self::aclFor(WNBDirectAdmin::usertype($owner) === 'reseller' ? $owner : 'admin');
        return ['can_backup' => false, 'can_restore' => $acl['allow_user_restore'], 'can_snapshot' => $acl['allow_user_snapshots'], 'can_download' => $acl['allow_user_downloads'], 'can_policy' => false];
    }

    private static function visibleUsernames(string $user, string $role): array
    {
        if ($role === 'admin') {
            return WNBDirectAdmin::usernames();
        }
        if ($role === 'reseller') {
            return WNBDirectAdmin::resellerUsernames($user);
        }
        return [$user];
    }

    private static function canAccess(string $user, string $role, string $target): bool
    {
        if ($target === '' || !WNBDirectAdmin::validUsername($target)) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        return in_array($target, self::visibleUsernames($user, $role), true);
    }

    private static function assertAccess(string $user, string $role, string $target): void
    {
        if (!self::canAccess($user, $role, $target) || !WNBDirectAdmin::exists($target)) {
            throw new WNBError('Không có quyền với tài khoản {user}.', ['user' => $target]);
        }
    }

    private static function backupFor(string $user, string $role, string $id): array
    {
        $b = WNBEngine::findBackup($id);
        if (!$b || !self::canAccess($user, $role, $b['username']) || !self::ownedByCurrent($role, $b)) {
            throw new WNBError('Không tìm thấy bản sao lưu.');
        }
        return $b;
    }

    /** Reseller/user chỉ thấy bản sao lưu của đúng chủ hiện tại (không thấy của khách cũ trùng tên). Admin thấy tất cả. */
    private static function ownedByCurrent(string $role, array $rec): bool
    {
        if ($role === 'admin' || empty($rec['owner_key'])) {
            return true;
        }
        static $keys = [];
        $u = $rec['username'];
        if (!isset($keys[$u])) {
            $keys[$u] = WNBDirectAdmin::ownerKey($u);
        }
        return hash_equals($keys[$u], (string)$rec['owner_key']);
    }

    private static function downloadFor(string $user, string $role, string $id): array
    {
        foreach (WNBStorage::read('downloads') as $d) {
            if ($d['id'] === $id && self::canAccess($user, $role, $d['username']) && self::ownedByCurrent($role, $d)) {
                return $d;
            }
        }
        throw new WNBError('File tải về không tồn tại hoặc đã hết hạn.');
    }

    /** Dùng cho lệnh stream file tải về. */
    public static function downloadFile(string $user, string $role, string $id): array
    {
        self::verifyIdentity($user, $role);
        if (!self::perms($user, $role)['can_download']) {
            throw new WNBError('Tính năng tải bản sao lưu đang bị tắt.');
        }
        $d = self::downloadFor($user, $role, $id);
        if (!is_file($d['path'])) {
            throw new WNBError('File đã bị xóa.');
        }
        return $d;
    }

    private static function requireAdmin(string $role): void
    {
        if ($role !== 'admin') {
            throw new WNBError('Chỉ Admin được thực hiện thao tác này.');
        }
    }

    private static function assertNoActiveTask(string $u): void
    {
        if (WNBEngine::accountBusy($u)) {
            throw new WNBError('Tài khoản {user} đang có tác vụ chạy, hãy đợi hoàn tất.', ['user' => $u]);
        }
    }

    private static function assertResellerQuota(string $reseller): void
    {
        $quota = (float)self::aclFor($reseller)['quota_gb'];
        if ($quota <= 0) {
            return;
        }
        $users = array_flip(WNBDirectAdmin::resellerUsernames($reseller));
        $used = 0.0;
        foreach (WNBStorage::read('backups') as $b) {
            if (isset($users[$b['username']])) {
                $used += (float)$b['size_mb'];
            }
        }
        if ($used / 1024 >= $quota) {
            throw new WNBError('Đã dùng hết hạn mức sao lưu {quota} GB.', ['quota' => $quota]);
        }
    }

    /* ---------------------------- Cấu hình ---------------------------- */

    /** @return array{0:string,1:array,2:bool} khóa thông báo, biến, kết quả kiểm tra */
    private static function saveDestination(array $p): array
    {
        $id = trim((string)($p['id'] ?? ''));
        $existing = $id !== '' ? WNBEngine::findDestination($id) : null;
        if ($id !== '' && !$existing) {
            throw new WNBError('Kho lưu trữ không tồn tại.');
        }
        $type = $existing['type'] ?? (string)($p['type'] ?? '');
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '' || WNB::ulen($name) > 60) {
            throw new WNBError('Tên kho phải từ 1 đến 60 ký tự.');
        }
        $id = $existing['id'] ?? 'dest_' . bin2hex(random_bytes(4));
        $rec = $existing ?? ['id' => $id, 'type' => $type, 'created_at' => WNB::now()];
        $rec['name'] = $name;

        if ($type === 'local') {
            $rec['path'] = self::validateStoragePath((string)($p['path'] ?? ''));
            $rec['remote'] = '';
            $rec['subpath'] = '';
        } else {
            if (!isset(WNBRclone::TYPES[$type])) {
                throw new WNBError('Loại kho không hợp lệ.');
            }
            $sub = trim(preg_replace('#/+#', '/', (string)($p['subpath'] ?? '')), '/');
            if ($sub === '' || strpos($sub, '..') !== false || !preg_match('#^[A-Za-z0-9._/\-]+$#', $sub)) {
                throw new WNBError('Bucket/thư mục đích không hợp lệ (vd my-bucket/server1).');
            }
            $rec['remote'] = $existing['remote'] ?? 'wnb_' . substr($id, 5);
            $rec['subpath'] = $sub;
            $input = [];
            foreach (WNBRclone::TYPES[$type]['fields'] as $f) {
                $input[$f] = (string)($p['p_' . $f] ?? '');
            }
            WNBRclone::saveRemote($rec['remote'], $type, $input);
        }

        $test = WNBRclone::test($rec);
        $rec['free_gb'] = $test['free_gb'];
        $rec['last_test'] = ['ok' => $test['ok'], 'message' => $test['message'], 'at' => WNB::now()];
        WNBStorage::update('destinations', function ($l) use ($rec) {
            foreach ($l as &$d) {
                if ($d['id'] === $rec['id']) {
                    $d = $rec;
                    return $l;
                }
            }
            $l[] = $rec;
            return $l;
        });
        return ['Đã lưu kho "{name}". {detail}', ['name' => $name, 'detail' => $test['message']], $test['ok']];
    }

    /**
     * Thư mục lưu bản sao lưu / staging (root ghi vào đây): đường dẫn tuyệt đối dạng chuẩn,
     * không nằm trong thư mục hệ thống hay home của khách, không đi qua symlink, mọi cấp đã tồn tại phải thuộc root.
     */
    public static function validateStoragePath(string $path): string
    {
        $path = trim($path);
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        if (!preg_match('#^(/[A-Za-z0-9_\-][A-Za-z0-9._\-]*)+$#', $path) || preg_match('#/\.\.?(/|$)#', $path)) {
            throw new WNBError('Đường dẫn phải là đường dẫn tuyệt đối hợp lệ, ví dụ /backup/webnowbackup.');
        }
        if (preg_match('#^/(usr|etc|var|root|tmp|bin|sbin|lib|lib64|proc|sys|dev|boot|run|opt)(/|$)#', $path)) {
            throw new WNBError('Không được lưu bản sao lưu trong thư mục hệ thống.');
        }
        if (preg_match('#^/home\d*(/([^/]+))?(/|$)#', $path, $hm) && (empty($hm[2]) || WNBDirectAdmin::exists($hm[2]) || WNBDirectAdmin::passwd($hm[2]))) {
            throw new WNBError('Không được lưu bản sao lưu trong thư mục home của khách (khách có thể đổi tên hoặc thay thế thư mục).');
        }
        if (!WNB::isPreview()) {
            $cur = '';
            foreach (explode('/', ltrim($path, '/')) as $seg) {
                $cur .= '/' . $seg;
                if (is_link($cur)) {
                    throw new WNBError('{path} là symlink, hãy dùng đường dẫn thật.', ['path' => $cur]);
                }
                if (!file_exists($cur)) {
                    break;
                }
                if (@fileowner($cur) !== 0) {
                    throw new WNBError('{path} không thuộc root.', ['path' => $cur]);
                }
            }
        }
        return $path;
    }

    private static function updateDestination(string $id, array $changes): void
    {
        WNBStorage::update('destinations', function ($l) use ($id, $changes) {
            foreach ($l as &$d) {
                if ($d['id'] === $id) {
                    $d = array_merge($d, $changes);
                }
            }
            return $l;
        });
    }

    private static function findJob(string $id): array
    {
        foreach (WNBStorage::read('jobs') as $j) {
            if ($j['id'] === $id) {
                return $j;
            }
        }
        throw new WNBError('Lịch không tồn tại.');
    }

    /** Dựng bản ghi lịch từ form (dùng chung cho lưu và xem trước số tài khoản). Lịch luôn theo quy tắc loại trừ. */
    private static function jobFromParams(array $p, ?array $existing): array
    {
        $job = array_merge($existing ?? ['id' => 'job_' . bin2hex(random_bytes(4)), 'created_at' => WNB::now(), 'last_run' => null], [
            'name' => trim((string)($p['name'] ?? '')),
            'destination_id' => (string)($p['destination_id'] ?? ''),
            'schedule_type' => (string)($p['schedule_type'] ?? 'daily'),
            'schedule_time' => (string)($p['schedule_time'] ?? '02:00'),
            'schedule_weekday' => max(0, min(6, (int)($p['schedule_weekday'] ?? 0))),
            'interval_hours' => max(1, min(24, (int)($p['interval_hours'] ?? 6))),
            'retention' => max(1, min(365, (int)($p['retention'] ?? 7))),
            'exclude_users' => array_values(array_filter(self::listParam($p, 'exclude_users'), [WNBDirectAdmin::class, 'validUsername'])),
            'exclude_resellers' => array_values(array_filter(self::listParam($p, 'exclude_resellers'), [WNBDirectAdmin::class, 'isOwnerAccount'])),
            'skip_suspended' => (string)($p['skip_suspended'] ?? '0') === '1',
            'enabled' => (string)($p['enabled'] ?? '1') === '1',
        ]);
        unset($job['target'], $job['users'], $job['resellers']); // bỏ chế độ "chọn tài khoản" của bản cũ
        return $job;
    }

    private static function saveJob(array $p): string
    {
        $id = trim((string)($p['id'] ?? ''));
        $existing = $id !== '' ? self::findJob($id) : null;
        $job = self::jobFromParams($p, $existing);
        if ($job['name'] === '' || WNB::ulen($job['name']) > 60) {
            throw new WNBError('Tên lịch phải từ 1 đến 60 ký tự.');
        }
        if (!WNBEngine::findDestination($job['destination_id'])) {
            throw new WNBError('Kho lưu trữ không tồn tại.');
        }
        if (!in_array($job['schedule_type'], ['hourly', 'daily', 'weekly'], true)) {
            throw new WNBError('Kiểu lịch không hợp lệ.');
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $job['schedule_time'])) {
            throw new WNBError('Giờ chạy phải có dạng HH:MM.');
        }
        if ($job['enabled'] && empty($existing['enabled'])) {
            $job['enabled_at'] = WNB::now();
            $job['last_run'] = null;
        }
        WNBStorage::update('jobs', function ($l) use ($job) {
            foreach ($l as &$j) {
                if ($j['id'] === $job['id']) {
                    $j = $job;
                    return $l;
                }
            }
            $l[] = $job;
            return $l;
        });
        return $job['name'];
    }

    private static function saveSettings(array $p): void
    {
        $root = self::validateStoragePath((string)($p['local_backup_root'] ?? ''));
        $bw = trim((string)($p['bwlimit'] ?? ''));
        if ($bw !== '' && !preg_match('/^[0-9.]+[KMGkmg]?$/', $bw)) {
            throw new WNBError('Giới hạn băng thông có dạng 10M, 500K hoặc để trống.');
        }
        $email = trim((string)($p['notify_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new WNBError('Email nhận cảnh báo không hợp lệ.');
        }
        $snapDest = (string)($p['snapshot_destination'] ?? 'dest_local');
        if (!WNBEngine::findDestination($snapDest)) {
            $snapDest = 'dest_local';
        }
        $report = (string)($p['notify_job_report'] ?? 'errors');
        if (!in_array($report, ['off', 'errors', 'always'], true)) {
            $report = 'errors';
        }

        // Telegram: chỉ ghi đè khi có nhập; không bao giờ đọc lại được. "Xóa" để gỡ cấu hình.
        $current = WNBStorage::settings();
        $token = $current['telegram_token'];
        $chat = $current['telegram_chat_id'];
        if ((string)($p['telegram_clear'] ?? '0') === '1') {
            $token = '';
            $chat = '';
        }
        $newToken = trim((string)($p['telegram_token'] ?? ''));
        $newChat = trim((string)($p['telegram_chat_id'] ?? ''));
        if ($newToken !== '') {
            if (!preg_match('/^\d{5,}:[A-Za-z0-9_\-]{30,}$/', $newToken)) {
                throw new WNBError('Bot Token Telegram không hợp lệ (dạng 123456:ABC...).');
            }
            $token = $newToken;
        }
        if ($newChat !== '') {
            if (!preg_match('/^(-?\d{3,20}|@[A-Za-z0-9_]{5,32})$/', $newChat)) {
                throw new WNBError('Chat ID Telegram không hợp lệ (vd -1001234567890 hoặc @tenkenh).');
            }
            $chat = $newChat;
        }

        $excludes = implode("\n", array_slice(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($p['excludes'] ?? ''))), fn($l) => $l !== '' && strpos($l, '..') === false), 0, 50));
        WNBStorage::update('settings', fn($s) => array_merge(WNBStorage::defaultSettings(), $s, [
            'local_backup_root' => $root,
            'max_concurrent_tasks' => max(1, min(8, (int)($p['max_concurrent_tasks'] ?? 2))),
            'bwlimit' => $bw,
            'nice_level' => max(0, min(19, (int)($p['nice_level'] ?? 19))),
            'default_retention' => max(1, min(365, (int)($p['default_retention'] ?? 7))),
            'max_snapshots_per_user' => max(1, min(30, (int)($p['max_snapshots_per_user'] ?? 3))),
            'snapshot_destination' => $snapDest,
            'download_ttl_hours' => max(1, min(168, (int)($p['download_ttl_hours'] ?? 24))),
            'min_free_gb' => max(0, min(10000, (float)($p['min_free_gb'] ?? 5))),
            'exclude_accounts' => array_values(array_filter(self::listParam($p, 'exclude_accounts'), [WNBDirectAdmin::class, 'validUsername'])),
            'exclude_resellers' => array_values(array_filter(self::listParam($p, 'exclude_resellers'), [WNBDirectAdmin::class, 'isOwnerAccount'])),
            'language' => ($p['language'] ?? 'vi') === 'en' ? 'en' : 'vi',
            'notify_email' => $email,
            'notify_on_failure' => (string)($p['notify_on_failure'] ?? '1') === '1',
            'notify_job_report' => $report,
            'telegram_token' => $token,
            'telegram_chat_id' => $chat,
            'excludes' => $excludes,
        ]));
        // Kho local mặc định theo thư mục lưu trữ.
        self::updateDestination('dest_local', ['path' => $root]);
    }
}
