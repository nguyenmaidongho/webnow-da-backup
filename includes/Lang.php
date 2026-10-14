<?php
/**
 * WebNowBackup - đa ngôn ngữ (Tiếng Việt / English).
 * Khóa dịch chính là câu tiếng Việt, biến dạng {ten}. Bản tiếng Anh nằm trong lang/en.php.
 * Thông báo lưu lâu dài (tác vụ, cảnh báo) được lưu dạng ['k' => khóa, 'v' => biến] và dịch khi hiển thị.
 */

final class WNBLang
{
    private static string $lang = 'vi';
    private static ?array $en = null;

    public static function set(string $lang): void
    {
        self::$lang = $lang === 'en' ? 'en' : 'vi';
    }

    public static function get(): string
    {
        return self::$lang;
    }

    public static function t(string $key, array $vars = []): string
    {
        if (self::$lang === 'en') {
            if (self::$en === null) {
                self::$en = require __DIR__ . '/lang/en.php';
            }
            $key = self::$en[$key] ?? $key;
        }
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            if (!array_key_exists($m[1], $vars)) {
                return $m[0];
            }
            $v = $vars[$m[1]];
            return is_array($v) ? self::render($v) : (string)$v; // biến có thể là một thông báo con cần dịch
        }, $key);
    }

    /** Thông báo lưu trữ được: dịch lúc hiển thị theo ngôn ngữ người xem. */
    public static function msg(string $key, array $vars = []): array
    {
        return ['k' => $key, 'v' => $vars];
    }

    /** Hiển thị một thông báo đã lưu (mảng msg, hoặc chuỗi cũ/thô). */
    public static function render($msg): string
    {
        if (is_array($msg) && isset($msg['k'])) {
            return self::t((string)$msg['k'], is_array($msg['v'] ?? null) ? $msg['v'] : []);
        }
        return is_scalar($msg) ? (string)$msg : '';
    }
}

/** Lỗi có thể dịch: giữ lại khóa + biến để lưu vào tác vụ. */
class WNBError extends RuntimeException
{
    public string $key;
    public array $vars;

    public function __construct(string $key, array $vars = [])
    {
        $this->key = $key;
        $this->vars = $vars;
        parent::__construct(WNBLang::t($key, $vars));
    }

    public function msg(): array
    {
        return WNBLang::msg($this->key, $this->vars);
    }
}

function wnb_t(string $key, array $vars = []): string
{
    return WNBLang::t($key, $vars);
}
