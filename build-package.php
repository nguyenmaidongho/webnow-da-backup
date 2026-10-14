<?php
/**
 * Đóng gói plugin: php build-package.php -> dist/webnowbackup.tar.gz
 * Chuẩn hóa xuống dòng LF (shebang trên Linux) và đặt quyền 0644/0755, không gói data/ hay file preview.
 */

$root = __DIR__;
$include = ['plugin.conf', 'README.md', 'admin', 'reseller', 'user', 'hooks', 'images', 'includes', 'assets', 'daemon', 'bin/wnb_exec.c', 'scripts'];
$executable = '#^(admin|reseller|user)/|^daemon/.+\.php$|^scripts/.+\.sh$#';

$dist = $root . '/dist';
@mkdir($dist, 0755, true);
$tar = $dist . '/webnowbackup.tar';
@unlink($tar);
@unlink($tar . '.gz');

$files = [];
foreach ($include as $entry) {
    $path = $root . '/' . $entry;
    if (is_file($path)) {
        $files[$entry] = $path;
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $files[$rel] = $f->getPathname();
    }
}
ksort($files);

$phar = new PharData($tar);
foreach ($files as $rel => $abs) {
    $content = (string)file_get_contents($abs);
    if (preg_match('/\.(php|html|raw|sh|conf|c|css|js|svg|md)$/', $rel)) {
        $content = str_replace("\r\n", "\n", $content);
    }
    $phar->addFromString($rel, $content);
    $phar[$rel]->chmod(preg_match($executable, $rel) ? 0755 : 0644);
}
$phar->compress(Phar::GZ);
unset($phar);
@unlink($tar);
echo 'Đã tạo ' . $dist . '/webnowbackup.tar.gz (' . count($files) . " file)\n";
