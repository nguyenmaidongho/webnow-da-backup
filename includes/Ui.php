<?php
/**
 * WebNowBackup - dựng trang giao diện (chạy phía web bằng user đang đăng nhập).
 * CSS/JS được nhúng trực tiếp để không phụ thuộc cách DirectAdmin phục vụ file tĩnh.
 */

require_once __DIR__ . '/bootstrap.php';

final class WNBUi
{
    private const BASE = [
        'admin' => '/CMD_PLUGINS_ADMIN/' . WNB_ID,
        'reseller' => '/CMD_PLUGINS_RESELLER/' . WNB_ID,
        'user' => '/CMD_PLUGINS/' . WNB_ID,
    ];

    public static function render(string $role): void
    {
        $params = WNB::requestParams();

        // Đường dự phòng: gọi API qua trang thường (kết quả nằm giữa 2 marker trong HTML của skin).
        if (isset($params['wnb_api'])) {
            unset($params['wnb_api']);
            echo '<!--WNB_JSON-->' . json_encode(WNBClient::call($role, $params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '<!--/WNB_JSON-->';
            return;
        }

        $res = WNBClient::call($role, ['action' => 'state']);
        if (WNB::isPreview()) {
            $page = '/?role=' . $role;
            $api = '/api.raw?role=' . $role;
        } else {
            $page = self::BASE[$role] . '/index.html';
            $api = self::BASE[$role] . '/api.raw';
        }
        $boot = [
            'role' => $role,
            'page' => $page,
            'api' => $api,
            'state' => $res['ok'] ? $res['data'] : null,
            'error' => $res['ok'] ? null : ['error' => $res['error'], 'code' => $res['code'] ?? '', 'install' => $res['install'] ?? ''],
        ];
        $json = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo '<div id="wnb" class="wnb" data-theme="light">';
        echo self::sprite();
        echo '<div data-app><noscript>WebNowBackup cần bật JavaScript.</noscript></div><div data-modals></div><div data-toasts class="toasts" aria-live="polite"></div></div>';
        echo '<style>' . file_get_contents(WNB_ROOT . '/assets/app.css') . '</style>';
        echo '<script>window.WNB_BOOT=' . $json . ';</script>';
        echo '<script>' . file_get_contents(WNB_ROOT . '/assets/lang-en.js') . '</script>';
        echo '<script>' . file_get_contents(WNB_ROOT . '/assets/app.js') . '</script>';
    }

    private static function sprite(): string
    {
        $icons = [
            'logo' => '<path d="M12 2.8 4.5 5.6v5.6c0 4.6 3.1 8.6 7.5 9.9 4.4-1.3 7.5-5.3 7.5-9.9V5.6z"/><path d="M12 8v6.5M9 11.5l3 3 3-3"/>',
            'home' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
            'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.9.7 3.1 2.4 3.5 5.2"/>',
            'archive' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'server' => '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
            'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
            'sliders' => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
            'refresh' => '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4"/>',
            'play' => '<path d="M7 5v14l11-7z"/>',
            'stop' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
            'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
            'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
            'restore' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
            'file' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/>',
            'folder' => '<path d="M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/>',
            'database' => '<ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v13c0 1.7 3.6 3 8 3s8-1.3 8-3v-13M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="m3.5 6 8.5 7 8.5-7"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="1.5"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'camera' => '<path d="M4 8a1 1 0 0 1 1-1h3l1.5-2h5L16 7h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/><circle cx="12" cy="13" r="3.5"/>',
            'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
            'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'alert' => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
            'chevron-left' => '<path d="m15 6-6 6 6 6"/>',
            'shield' => '<path d="M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
            'cloud' => '<path d="M7 18a4.5 4.5 0 0 1-.6-9A6 6 0 0 1 18 9.5a4.2 4.2 0 0 1-.5 8.5z"/>',
            'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
            'list' => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
            'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
            'move' => '<rect x="3" y="5" width="7" height="14" rx="1.5"/><path d="M13 12h8M18 9l3 3-3 3"/>',
            'copy' =>'<rect x="8" y="8" width="12" height="12" rx="1.5"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        ];
        $out = '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>';
        foreach ($icons as $name => $body) {
            $out .= '<symbol id="wnb-i-' . $name . '" viewBox="0 0 24 24">' . $body . '</symbol>';
        }
        return $out . '</defs></svg>';
    }
}
