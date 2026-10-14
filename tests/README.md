# Test (không đóng gói vào plugin)

Chạy từ thư mục gốc dự án, dùng dữ liệu tạm trong `data/` (preview):

```bash
rm -rf data && /c/laragon/bin/php/php-7.4.33-Win32-vc15-x64/php.exe -n tests/api_test.php
rm -rf data && /c/laragon/bin/php/php-8.5.11-Win32-vs17-x64/php.exe -n tests/api_test.php
node tests/jskeys.js assets/app.js assets/lang-en.js      # bản dịch giao diện
php -n tests/phpkeys.php                                  # bản dịch phía server
rm -rf data && php -n tests/seed.php                      # dữ liệu demo cho preview (php -S 127.0.0.1:8088 router.php)
```
