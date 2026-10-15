<?php
/**
 * WebNowBackup - API phía root: xác thực danh tính, phân quyền, chống CSRF, gọi engine.
 * Danh tính ($user) đến từ UID thật của tiến trình gọi helper (hoặc preview), KHÔNG từ tham số request.
 */

final class WNBApi
{
    private const ROLES = ['admin', 'reseller', 'user'];
    private const READ_ACTIONS = ['state', 'browse', 'task_log'];
    /** Mặc định của lịch mới: 7 ngày gần nhất, 4 tuần, 3 tháng. */
    public const DEFAULT_KEEP = ['keep_last' => 0, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 3];

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

            case 'task_cancel':
                // Admin dừng được mọi tác vụ, cả lượt chạy của lịch; reseller/user chỉ dừng tác vụ chính họ tạo
                // (không bỏ qua được bản sao lưu tự động admin đã đặt lịch).
                if ($str('job_id') !== '' || $str('all') === '1') {
                    self::requireAdmin($role);
                    if ($str('job_id') !== '') {
                        self::findJob($str('job_id'));
                    }
                    $n = WNBEngine::cancelAll($user, $str('job_id'));
                    return $done($n ? 'Đã dừng {n} tác vụ.' : 'Không có tác vụ nào đang chạy.', ['n' => $n]);
                }
                $task = WNBEngine::findTask($str('task_id'));
                if (!$task || ($role !== 'admin' && (!self::canAccess($user, $role, $task['username']) || ($task['initiated_by'] ?? '') !== $user))) {
                    throw new WNBError('Không tìm thấy tác vụ.');
                }
                $r = WNBEngine::cancelTask($task['id'], $user);
                if ($r === '') {
                    throw new WNBError('Tác vụ đã kết thúc hoặc đang được dừng.');
                }
                return $done($r === 'cancelled' ? 'Đã dừng tác vụ.' : 'Đang dừng tác vụ, bản dở dang sẽ được dọn.');

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
                [$key, $vars, $ok, $newKey] = self::saveDestination($p);
                return $done($key, $vars, ['test_ok' => $ok, 'restic_new_key' => $newKey]);

            case 'dest_oauth_start':
                self::requireAdmin($role);
                return WNBOAuth::start($str('kind'));

            case 'dest_oauth_finish':
                self::requireAdmin($role);
                return WNBOAuth::finish($str('kind'), $str('url'));

            case 'dest_restic_key':
                // Admin xem mật khẩu kho restic để cất giữ ngoài máy chủ (mất mật khẩu = mất toàn bộ bản sao lưu trong kho).
                self::requireAdmin($role);
                $dest = WNBEngine::findDestination($str('id'));
                if (!$dest || WNBRclone::destFormat($dest) !== 'restic' || !WNBRestic::hasKey($dest['id'])) {
                    throw new WNBError('Kho lưu trữ không tồn tại.');
                }
                return ['password' => WNBRestic::password($dest['id']), 'repository' => WNBRestic::repo($dest)];

            case 'dest_rescan':
                self::requireAdmin($role);
                $dest = WNBEngine::findDestination($str('id'));
                if (!$dest || WNBRclone::destFormat($dest) !== 'restic') {
                    throw new WNBError('Kho lưu trữ không tồn tại.');
                }
                WNBEngine::enqueue(['type' => 'rescan', 'username' => '', 'initiated_by' => $user, 'role' => $role, 'destination_id' => $dest['id']]);
                WNBEngine::kick();
                return $done('Đang quét kho, danh sách bản sao lưu sẽ cập nhật khi xong.');

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
                $res = WNBEngine::notify(wnb_t('Tin nhắn thử'), wnb_t('Thông báo từ WebNowBackup đã hoạt động.'));
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
        $allBackups = WNBStorage::read('backups');
        $keepTags = WNBEngine::retentionTags($allBackups);
        $deleted = []; // admin: tài khoản đã bị xóa khỏi DirectAdmin nhưng vẫn còn bản sao lưu
        foreach ($allBackups as $b) {
            $gone = $role === 'admin' && !isset($visibleSet[$b['username']]) && WNBDirectAdmin::validUsername((string)$b['username']);
            if (!$gone && (!isset($visibleSet[$b['username']]) || !self::ownedByCurrent($role, $b))) {
                continue;
            }
            if ($gone) {
                $b['account_deleted'] = true;
                $d = &$deleted[$b['username']];
                $d = $d ?? ['username' => $b['username'], 'reseller' => '', 'domain' => '', 'domains' => [], 'subdomains' => [], 'pointers' => [], 'databases' => [], 'backups_count' => 0, 'size_mb' => 0.0, 'last_backup_at' => '', 'last_backup_id' => ''];
                $d['backups_count']++;
                $d['size_mb'] = round($d['size_mb'] + (float)$b['size_mb'], 1);
                if ((string)$b['created_at'] > $d['last_backup_at']) {
                    $d['last_backup_at'] = (string)$b['created_at'];
                    $d['last_backup_id'] = $b['id'];
                    $d['reseller'] = (string)($b['reseller'] ?? '');
                    $d['domain'] = (string)($b['domain'] ?? '');
                    $d['domains'] = array_values((array)($b['domains'] ?? []));
                    $d['databases'] = array_values((array)($b['databases'] ?? []));
                    $d['subdomains'] = array_values((array)($b['subdomains'] ?? []));
                    $d['pointers'] = array_values((array)($b['pointers'] ?? []));
                }
                unset($d);
            } elseif ($role === 'admin' && self::previousOwner($b)) {
                $b['previous_owner'] = true;
            }
            $b['keep'] = $keepTags[$b['id']] ?? [];
            unset($b['storage_path'], $b['owner_key'], $b['restic']);
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
            if ($role !== 'admin' && !isset($visibleSet[$t['username']])) {
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
            if ($role === 'admin' || (isset($visibleSet[$d['username']]) && self::ownedByCurrent($role, $d))) {
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
                $d['format'] = WNBRclone::destFormat($d);
                $d['restic_key'] = $d['format'] === 'restic' && WNBRestic::hasKey($d['id']);
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
            'engine' => ['ready' => true, 'rclone' => WNBRclone::installed(), 'rclone_version' => WNBRclone::version(), 'restic' => WNB::isPreview() || WNBRestic::usable(), 'restic_version' => WNBRestic::version()],
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
            $state['server_host'] = WNBEngine::hostname();
            $state['default_subpath'] = WNBRclone::defaultSubpath();
            unset($state['settings']['telegram_token'], $state['settings']['telegram_chat_id']);
            $state['health'] = array_map(fn($i) => WNBLang::render($i['msg']), WNBEngine::healthIssues());
            $deleted = array_values($deleted);
            usort($deleted, fn($a, $b) => strcmp($b['last_backup_at'], $a['last_backup_at']));
            $state['deleted_accounts'] = $deleted;
            $scheduled = []; // tài khoản được ít nhất một lịch đang bật sao lưu
            $state['jobs'] = array_map(function ($j) use (&$scheduled) {
                $j = array_merge(['filter_mode' => 'exclude', 'exclude_users' => [], 'exclude_resellers' => [], 'include_users' => [], 'include_resellers' => [], 'skip_suspended' => false], $j);
                unset($j['target'], $j['users'], $j['resellers']);
                $next = WNBEngine::nextRun($j);
                $j['next_run'] = $next ? date('Y-m-d H:i:s', $next) : null;
                $targets = WNBEngine::jobTargets($j);
                $j['target_count'] = count($targets);
                if ($j['enabled']) {
                    $scheduled += array_fill_keys($targets, true);
                }
                $j['policy'] = WNBEngine::jobPolicy($j);
                return $j;
            }, WNBStorage::read('jobs'));
            $state['scheduled'] = array_keys($scheduled);
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

    /** Bản sao lưu của tài khoản cũ cùng tên (tài khoản đã bị xóa rồi tạo lại). Bản nạp lại từ kho (imported) không xác định được chủ. */
    private static function previousOwner(array $rec): bool
    {
        static $keys = [];
        $key = (string)($rec['owner_key'] ?? '');
        if ($key === '' || $key === 'imported') {
            return false;
        }
        $u = $rec['username'];
        $keys[$u] = $keys[$u] ?? WNBDirectAdmin::ownerKey($u);
        return !hash_equals($keys[$u], $key);
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
            // Bỏ khoảng trắng, ký tự ẩn (hay dính khi copy/paste) và dấu / ở hai đầu.
            $sub = (string)preg_replace('#^[\s/\x{200B}-\x{200D}\x{FEFF}]+|[\s/\x{200B}-\x{200D}\x{FEFF}]+$#u', '', preg_replace('#/+#', '/', (string)($p['subpath'] ?? '')));
            // Kho kiểu thư mục (không phải bucket) để trống: WebNowBackup/<tên đầy đủ của máy chủ>, rclone tự tạo khi kiểm tra.
            if ($sub === '' && !in_array($type, WNBRclone::BUCKET_TYPES, true)) {
                $sub = WNBRclone::defaultSubpath();
            }
            if ($sub === '') {
                throw new WNBError('Hãy nhập thư mục đích (vd {example}).', ['example' => 'my-bucket/server1']);
            }
            if (strpos($sub, '..') !== false || !preg_match('#^[A-Za-z0-9._/\-]+$#', $sub)) {
                throw new WNBError('Thư mục đích "{path}" không hợp lệ: chỉ dùng chữ không dấu, số, dấu chấm, gạch ngang, gạch dưới và dấu / (không có khoảng trắng).', ['path' => $sub]);
            }
            $rec['remote'] = $existing['remote'] ?? 'wnb_' . substr($id, 5);
            $rec['subpath'] = $sub;
            $fmt = (string)($p['format'] ?? 'archive');
            $rec['format'] = $fmt === 'restic' ? 'restic' : (WNBRclone::supportsArchive($type) && $fmt !== 'folder' ? 'archive' : 'folder');
            $input = [];
            foreach (WNBRclone::TYPES[$type]['fields'] as $f) {
                $input[$f] = (string)($p['p_' . $f] ?? '');
            }
            // Vừa bấm "Kết nối": token (và drive_id của OneDrive) nằm ở máy chủ, trình duyệt chỉ giữ vé.
            $ticket = trim((string)($p['oauth_ticket'] ?? ''));
            if ($ticket !== '' && in_array($type, WNBOAuth::KINDS, true)) {
                $auth = WNBOAuth::consume($ticket, $type);
                $rec['account'] = $auth['account'];
                unset($auth['account']);
                $input = array_merge($input, $auth);
            }
            $rec['location'] = WNBRclone::locationKey($type, $sub, WNBRclone::buildParams($rec['remote'], $type, $input));
            if ($existing) {
                $old = $existing['location'] ?? WNBRclone::locationKey($type, (string)($existing['subpath'] ?? ''), WNBRclone::readRemote($rec['remote']));
                $moved = $old !== $rec['location'];
                if ($moved && WNBEngine::destinationBusy($id)) {
                    throw new WNBError('Kho đang có tác vụ chờ hoặc đang chạy. Đợi xong rồi hãy đổi sang vị trí khác.');
                }
            }
            WNBRclone::saveRemote($rec['remote'], $type, $input);
        }

        $test = WNBRclone::test($rec);
        $rec['free_gb'] = $test['free_gb'];
        $rec['last_test'] = ['ok' => $test['ok'], 'message' => $test['message'], 'at' => WNB::now()];
        $newKey = false;
        if (($rec['format'] ?? '') === 'restic') {
            $newKey = self::setupRestic($rec, trim((string)($p['restic_password'] ?? '')), $test['ok']);
        }
        if (!empty($moved)) {
            unset($rec['restic_maint']); // trạng thái bảo trì là của kho cũ
        }
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
        if (!empty($moved)) {
            // Bản sao lưu cũ vẫn nằm nguyên ở vị trí cũ, chỉ gỡ khỏi danh sách vì kho này không còn trỏ tới đó.
            $removed = WNBEngine::detachDestination($rec['id']);
            if (($rec['format'] ?? '') === 'restic' && $test['ok']) {
                // Kho restic ở vị trí mới có thể đã có bản sao lưu (vd kết nối lại kho cũ): nạp vào danh sách.
                WNBEngine::enqueue(['type' => 'rescan', 'username' => '', 'initiated_by' => 'admin', 'role' => 'admin', 'destination_id' => $rec['id']]);
                WNBEngine::kick();
            }
            return ['Đã lưu kho "{name}" ở vị trí mới, gỡ {n} bản sao lưu của vị trí cũ khỏi danh sách (dữ liệu ở vị trí cũ không bị xóa). {detail}', ['name' => $name, 'n' => $removed, 'detail' => $test['message']], $test['ok'], $newKey];
        }
        return ['Đã lưu kho "{name}". {detail}', ['name' => $name, 'detail' => $test['message']], $test['ok'], $newKey];
    }

    /**
     * Mật khẩu + kho restic. Mật khẩu nhập tay dùng để kết nối lại kho đã có (vd cài lại máy chủ);
     * để trống thì giữ mật khẩu cũ hoặc tự tạo mật khẩu mạnh. Trả về true nếu vừa tạo mật khẩu mới (giao diện nhắc cất giữ).
     */
    private static function setupRestic(array $rec, string $password, bool $reachable): bool
    {
        if ($password !== '' && strlen($password) < 8) {
            throw new WNBError('Mật khẩu kho restic phải có ít nhất 8 ký tự.');
        }
        if (!WNB::isPreview() && !WNBRestic::usable()) {
            throw new WNBError('Máy chủ chưa cài restic {min} trở lên. Chạy lại scripts/install.sh bằng root để cài.', ['min' => WNBRestic::MIN_VERSION]);
        }
        $old = WNBRestic::password($rec['id']);
        $generated = false;
        if ($password !== '') {
            WNBRestic::setPassword($rec['id'], $password);
        } elseif ($old === '') {
            WNBRestic::setPassword($rec['id'], WNBRestic::generatePassword());
            $generated = true;
        }
        if (!WNB::isPreview() && $reachable) {
            try {
                WNBRestic::ensureRepo($rec);
            } catch (Throwable $e) {
                // Sai mật khẩu với kho đã có: trả lại mật khẩu cũ, không ghi đè.
                if ($old !== '') {
                    WNBRestic::setPassword($rec['id'], $old);
                } else {
                    @unlink(WNBRestic::keyFile($rec['id']));
                }
                throw $e;
            }
        }
        return $generated;
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

    /** Dựng bản ghi lịch từ form (dùng chung cho lưu và xem trước số tài khoản). filter_mode: exclude (tất cả trừ...) hoặc include (chỉ những tài khoản/reseller được chọn). */
    private static function jobFromParams(array $p, ?array $existing): array
    {
        $job = array_merge($existing ?? ['id' => 'job_' . bin2hex(random_bytes(4)), 'created_at' => WNB::now(), 'last_run' => null], [
            'name' => trim((string)($p['name'] ?? '')),
            'destination_id' => (string)($p['destination_id'] ?? ''),
            'schedule_type' => (string)($p['schedule_type'] ?? 'daily'),
            'schedule_time' => (string)($p['schedule_time'] ?? '02:00'),
            'schedule_weekday' => max(0, min(6, (int)($p['schedule_weekday'] ?? 0))),
            'interval_hours' => max(1, min(24, (int)($p['interval_hours'] ?? 6))),
            'filter_mode' => (string)($p['filter_mode'] ?? '') === 'include' ? 'include' : 'exclude',
            'skip_suspended' => (string)($p['skip_suspended'] ?? '0') === '1',
            'enabled' => (string)($p['enabled'] ?? '1') === '1',
        ]);
        unset($job['target'], $job['users'], $job['resellers']); // bỏ trường của bản cũ
        // Chỉ lưu danh sách của chế độ đang dùng; danh sách của chế độ kia để trống.
        $on = $job['filter_mode'];
        foreach (['include', 'exclude'] as $mode) {
            $job[$mode . '_users'] = $mode === $on ? array_values(array_unique(array_filter(self::listParam($p, $mode . '_users'), [WNBDirectAdmin::class, 'validUsername']))) : [];
            $job[$mode . '_resellers'] = $mode === $on ? array_values(array_unique(array_filter(self::listParam($p, $mode . '_resellers'), [WNBDirectAdmin::class, 'isOwnerAccount']))) : [];
        }

        // Giữ bản theo ngày/tuần/tháng. Gửi kiểu cũ 'retention' = giữ N bản mới nhất.
        $keys = ['keep_last', 'keep_daily', 'keep_weekly', 'keep_monthly'];
        if (array_intersect($keys, array_keys($p))) {
            foreach ($keys as $k) {
                $job[$k] = max(0, min(365, (int)($p[$k] ?? 0)));
            }
        } elseif (isset($p['retention'])) {
            $job = array_merge($job, ['keep_last' => max(1, min(365, (int)$p['retention'])), 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0, 'keep_hours' => 0]);
        } elseif (!$existing) {
            $job = array_merge($job, self::DEFAULT_KEEP);
        }
        if (isset($p['keep_hours'])) {
            $job['keep_hours'] = max(0, min(168, (int)$p['keep_hours']));
        }
        $policy = WNBEngine::jobPolicy($job);
        foreach ($policy as $k => $v) {
            $job['keep_' . $k] = $v;
        }
        unset($job['retention']);
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
        if ($job['filter_mode'] === 'include' && !$job['include_users'] && !$job['include_resellers']) {
            throw new WNBError('Chọn ít nhất một tài khoản hoặc reseller để sao lưu.');
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

        // Tên máy chủ trong thông báo: một dòng, tối đa 60 ký tự.
        $serverName = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($p['server_name'] ?? '')));
        $serverName = WNB::ulen($serverName) > 60 ? WNB::usub($serverName, 60) : $serverName;

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
            'server_name' => $serverName,
            'notify_on_failure' => (string)($p['notify_on_failure'] ?? '1') === '1',
            'notify_job_report' => $report,
            'notify_health' => (string)($p['notify_health'] ?? '1') === '1',
            'telegram_token' => $token,
            'telegram_chat_id' => $chat,
            'excludes' => $excludes,
        ]));
        // Kho local mặc định theo thư mục lưu trữ.
        self::updateDestination('dest_local', ['path' => $root]);
    }
}
