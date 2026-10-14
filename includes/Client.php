<?php
/**
 * WebNowBackup - phía web (chạy bằng UNIX user đang đăng nhập DirectAdmin).
 * Không đụng tới dữ liệu: mọi thứ chuyển cho helper setuid root qua stdin/stdout.
 */

final class WNBClient
{
    private const PREVIEW_USERS = ['admin' => 'admin', 'reseller' => 'reseller_vn', 'user' => 'shopgiay'];

    /** Tên user web hiện tại (DirectAdmin truyền qua biến môi trường USERNAME). */
    public static function webUser(string $role): string
    {
        if (WNB::isPreview()) {
            return self::PREVIEW_USERS[$role] ?? 'admin';
        }
        return (string)getenv('USERNAME');
    }

    public static function call(string $role, array $params): array
    {
        if (WNB::isPreview()) {
            return WNBApi::handle(self::webUser($role), $role, $params);
        }
        if (!WNB::helperReady()) {
            return self::engineMissing();
        }
        [$code, $stdout] = self::helper('api', json_encode(['role' => $role, 'params' => $params]));
        $res = json_decode($stdout, true);
        if (!is_array($res)) {
            return ['ok' => false, 'error' => 'Engine trả về dữ liệu không hợp lệ (mã ' . $code . '): ' . substr(trim($stdout), 0, 200)];
        }
        return $res;
    }

    public static function engineMissing(): array
    {
        return [
            'ok' => false,
            'code' => 'engine_missing',
            'error' => 'Engine sao lưu chưa được kích hoạt bằng quyền root.',
            'install' => WNB::installCommand(),
        ];
    }

    /** Gửi file tải về cho trình duyệt. $raw = đang chạy ở chế độ .raw của DirectAdmin (tự in header HTTP). */
    public static function streamDownload(string $role, string $id, bool $raw): void
    {
        if (WNB::isPreview()) {
            try {
                $d = WNBApi::downloadFile(self::webUser($role), $role, $id);
            } catch (Throwable $e) {
                self::plainError($e->getMessage(), $raw);
                return;
            }
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $d['filename'] . '"');
            header('Content-Length: ' . filesize($d['path']));
            readfile($d['path']);
            return;
        }
        if (!WNB::helperReady()) {
            self::plainError('Engine sao lưu chưa được kích hoạt.', $raw);
            return;
        }
        $proc = proc_open([WNB::helperPath(), 'download'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::plainError('Không gọi được engine.', $raw);
            return;
        }
        fwrite($pipes[0], json_encode(['role' => $role, 'id' => $id]));
        fclose($pipes[0]);
        $meta = json_decode((string)fgets($pipes[1], 65536), true);
        if (!is_array($meta) || empty($meta['ok'])) {
            self::plainError($meta['error'] ?? 'Không tải được file.', $raw);
        } else {
            $name = preg_replace('/[^A-Za-z0-9._\-]/', '', (string)$meta['filename']);
            self::headers(200, [
                'Content-Type' => 'application/gzip',
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Content-Length' => (string)(int)$meta['size'],
                'Cache-Control' => 'no-store',
            ], $raw);
            while (!feof($pipes[1])) {
                echo fread($pipes[1], 1048576);
                flush();
            }
        }
        fclose($pipes[1]);
        proc_close($proc);
    }

    /** @return array{0:int,1:string} */
    private static function helper(string $cmd, string $input): array
    {
        $proc = proc_open([WNB::helperPath(), $cmd], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [127, ''];
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, $out !== '' ? $out : $err];
    }

    public static function headers(int $status, array $headers, bool $raw): void
    {
        if ($raw && PHP_SAPI === 'cli') {
            echo 'HTTP/1.1 ' . $status . ' ' . ($status === 200 ? 'OK' : 'Error') . "\r\n";
            foreach ($headers as $k => $v) {
                echo $k . ': ' . $v . "\r\n";
            }
            echo "\r\n";
            return;
        }
        if (!headers_sent()) {
            http_response_code($status);
            foreach ($headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
    }

    private static function plainError(string $msg, bool $raw): void
    {
        self::headers(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], $raw);
        echo $msg;
    }

    /** Endpoint .raw: trả JSON thuần. */
    public static function serveRaw(string $role): void
    {
        $params = WNB::requestParams();
        if (($params['action'] ?? '') === 'download_file') {
            self::streamDownload($role, (string)($params['id'] ?? ''), true);
            return;
        }
        $res = self::call($role, $params);
        self::headers(200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], true);
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
