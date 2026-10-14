<?php
/**
 * Preview WebNowBackup ngoài DirectAdmin (dữ liệu demo, không đụng máy chủ thật).
 *   php -S 127.0.0.1:8088 router.php
 *   http://127.0.0.1:8088/?role=admin | reseller | user   (thêm &theme=dark để xem giao diện tối)
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$role = $_GET['role'] ?? 'admin';
if (!in_array($role, ['admin', 'reseller', 'user'], true)) {
    $role = 'admin';
}

if ($uri === '/favicon.ico') {
    http_response_code(404);
    return true;
}
if (preg_match('#^/images/([a-z_]+\.svg)$#', $uri, $m) && is_file(__DIR__ . '/images/' . $m[1])) {
    header('Content-Type: image/svg+xml');
    readfile(__DIR__ . '/images/' . $m[1]);
    return true;
}

require_once __DIR__ . '/includes/Ui.php';

if (strpos($uri, 'api.raw') !== false) {
    WNBClient::serveRaw($role);
    return true;
}

// Mô phỏng cách Evolution nhúng plugin: iframe chứa trang trống không có CSS.
if (isset($_GET['bare'])) {
    $mode = $_GET['bare'];
    if ($mode === 'flex') {
        echo '<div style="display:flex;flex-direction:column;align-items:center"><div class="plugin-output">';
    } elseif ($mode === 'inline') {
        echo '<div style="text-align:center"><div style="display:inline-block;text-align:left">';
    } else {
        echo '<div class="container" style="width:940px;margin:0 auto"><div>';
    }
    WNBUi::render($role);
    echo '</div></div>';
    return true;
}

$dark = ($_GET['theme'] ?? '') === 'dark';
$iframe = isset($_GET['iframe']);
$roles = ['admin' => 'Admin', 'reseller' => 'Reseller', 'user' => 'User'];
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WebNowBackup Preview</title>
<style>
  body { margin: 0; font-family: "Open Sans", "Segoe UI", Roboto, Arial, sans-serif; background: <?= $dark ? '#151719' : '#eef1f4' ?>; color: <?= $dark ? '#e6e9ec' : '#1e2730' ?>; }
  .da-top { background: #0f2a40; color: #fff; padding: 10px 20px; display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; font-size: 14px; }
  .da-top a { color: #cfe3f5; text-decoration: none; padding: 4px 10px; border-radius: 4px; }
  .da-top a.on { background: #1d6fb5; color: #fff; }
  .da-page { max-width: 1440px; margin: 0 auto; padding: 20px 16px; }
</style>
</head>
<body>
<div class="da-top"><b>DirectAdmin (preview)</b>
<?php foreach ($roles as $r => $label): ?>
  <a class="<?= $r === $role ? 'on' : '' ?>" href="/?role=<?= $r ?><?= $dark ? '&theme=dark' : '' ?>"><?= $label ?></a>
<?php endforeach; ?>
  <a style="margin-left:auto" href="/?role=<?= $role ?><?= $dark ? '' : '&theme=dark' ?>"><?= $dark ? 'Giao diện sáng' : 'Giao diện tối' ?></a>
</div>
<?php if ($iframe): ?>
<div style="padding:20px 20px 0;font-size:22px;font-weight:600">WebNowBackup</div>
<div style="height:220px"></div>
<iframe src="/?role=<?= $role ?>&bare=<?= htmlspecialchars($_GET['iframe'] ?: 'fixed') ?>" style="display:block;width:100%;height:350px;border:0"></iframe>
<div style="height:600px"></div>
<?php else: ?>
<div class="da-page"><?php WNBUi::render($role); ?></div>
<?php endif; ?>
</body>
</html>
<?php
return true;
