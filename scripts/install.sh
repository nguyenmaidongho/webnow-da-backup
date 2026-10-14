#!/bin/sh
# WebNowBackup - cài đặt / kích hoạt engine.
# Plugin Manager của DirectAdmin chạy script này bằng user diradmin: khi đó chỉ chỉnh quyền file và in hướng dẫn.
# Phần cần root (helper setuid, cron, thư mục lưu trữ, rclone) phải chạy lại bằng root qua SSH:
#   sh /usr/local/directadmin/plugins/webnowbackup/scripts/install.sh

PLUGIN_DIR=/usr/local/directadmin/plugins/webnowbackup
BACKUP_ROOT=/backup/webnowbackup
PHP=/usr/local/bin/php
[ -x "$PHP" ] || PHP=/usr/bin/php

chmod 755 "$PLUGIN_DIR"/admin/* "$PLUGIN_DIR"/reseller/* "$PLUGIN_DIR"/user/* "$PLUGIN_DIR"/daemon/*.php "$PLUGIN_DIR"/scripts/*.sh 2>/dev/null
# Helper root từ chối chạy nếu mã nguồn bị ghi được bởi người khác.
chmod -R go-w "$PLUGIN_DIR" 2>/dev/null

if [ "$(id -u)" != "0" ]; then
    echo "WebNowBackup: đã giải nén plugin."
    echo "Để kích hoạt engine sao lưu, đăng nhập SSH bằng root và chạy:"
    echo "  sh $PLUGIN_DIR/scripts/install.sh"
    exit 0
fi

echo "== WebNowBackup: kích hoạt engine =="

for bin in rsync tar gzip mysqldump mysql; do
    command -v "$bin" >/dev/null 2>&1 || echo "[!] Thiếu lệnh $bin, hãy cài trước khi sao lưu."
done

HELPER_OK=0

# 1. Dữ liệu chỉ root đọc được (catalog, rclone.conf, log, khóa CSRF).
mkdir -p "$PLUGIN_DIR/data"
chown root:root "$PLUGIN_DIR/data"
chmod 700 "$PLUGIN_DIR/data"

# 2. Helper setuid root (xác định người gọi bằng UID thật).
#    In nguyên văn lỗi gcc ra stdout vì Plugin Manager của DirectAdmin chỉ hiển thị stdout.
if ! command -v gcc >/dev/null 2>&1; then
    echo "[x] Chưa có gcc nên chưa biên dịch được helper."
    echo "    Cài gcc:  yum install -y gcc   (hoặc: apt-get install -y gcc)"
else
    GCC_LOG=$(mktemp /tmp/wnb_gcc.XXXXXX)
    if gcc -std=gnu99 -O2 -Wall -o "$PLUGIN_DIR/bin/wnb_exec.new" "$PLUGIN_DIR/bin/wnb_exec.c" >"$GCC_LOG" 2>&1         || gcc -O2 -Wall -o "$PLUGIN_DIR/bin/wnb_exec.new" "$PLUGIN_DIR/bin/wnb_exec.c" >"$GCC_LOG" 2>&1; then
        chown root:root "$PLUGIN_DIR/bin/wnb_exec.new"
        chmod 4755 "$PLUGIN_DIR/bin/wnb_exec.new"
        mv -f "$PLUGIN_DIR/bin/wnb_exec.new" "$PLUGIN_DIR/bin/wnb_exec"
        HELPER_OK=1
        echo "[+] Đã biên dịch helper."
    else
        echo "[x] Biên dịch helper thất bại. Lỗi của gcc:"
        sed 's/^/    /' "$GCC_LOG" | head -n 40
        if command -v dnf >/dev/null 2>&1; then PKG="dnf"; elif command -v yum >/dev/null 2>&1; then PKG="yum"; else PKG="apt-get"; fi
        if grep -q "cc1" "$GCC_LOG"; then
            echo "    gcc trên máy bị cài thiếu (không có trình biên dịch cc1). Cài lại gcc:"
            if [ "$PKG" = "apt-get" ]; then
                echo "      apt-get update && apt-get install --reinstall -y gcc cpp libc6-dev"
            else
                echo "      $PKG reinstall -y gcc cpp || $PKG install -y gcc cpp glibc-devel"
            fi
        elif grep -qE "\.h: No such file|fatal error: .*\.h" "$GCC_LOG"; then
            echo "    Thiếu header thư viện C. Cài:"
            if [ "$PKG" = "apt-get" ]; then
                echo "      apt-get install -y libc6-dev"
            else
                echo "      $PKG install -y glibc-devel kernel-headers"
            fi
        fi
    fi
    rm -f "$GCC_LOG" "$PLUGIN_DIR/bin/wnb_exec.new"
fi

# 3. Thư mục lưu trữ mặc định: user thường không liệt kê được nội dung.
mkdir -p "$BACKUP_ROOT"
chown root:root "$BACKUP_ROOT"
chmod 711 "$BACKUP_ROOT"

# 4. Cron mỗi phút: lịch tự động, khởi động worker, dọn dẹp.
if [ -d /etc/cron.d ]; then
    cat > /etc/cron.d/webnowbackup <<CRON
# WebNowBackup
* * * * * root $PHP -n $PLUGIN_DIR/daemon/webnowbackup-cli.php cron >/dev/null 2>&1
CRON
    chmod 644 /etc/cron.d/webnowbackup
    echo "[+] Đã tạo /etc/cron.d/webnowbackup."
else
    echo "[!] Không có /etc/cron.d, hãy tự thêm vào crontab của root:"
    echo "    * * * * * $PHP -n $PLUGIN_DIR/daemon/webnowbackup-cli.php cron >/dev/null 2>&1"
fi

# 5. Rclone cho kho cloud (bỏ qua bằng WNB_SKIP_RCLONE=1).
if ! command -v rclone >/dev/null 2>&1 && [ "${WNB_SKIP_RCLONE:-0}" != "1" ]; then
    echo "[*] Cài rclone từ rclone.org..."
    if curl -fsSL https://rclone.org/install.sh | bash >/dev/null 2>&1; then
        echo "[+] Đã cài rclone."
    else
        echo "[!] Không cài được rclone. Kho cloud sẽ chưa dùng được."
    fi
fi

# 6. Vô hiệu hóa tàn dư của bản VaultBackup cũ (helper setuid không an toàn, socket 0666, dữ liệu 0777).
OLD=/usr/local/directadmin/plugins/vaultbackup
if [ -e "$OLD" ] || [ -f /etc/systemd/system/vaultbackup.service ] || [ -f /etc/cron.d/vaultbackup ]; then
    echo "[*] Phát hiện plugin VaultBackup cũ, đang vô hiệu hóa phần chạy bằng root..."
    [ -f "$OLD/bin/vb_exec" ] && chmod u-s,g-s "$OLD/bin/vb_exec" && rm -f "$OLD/bin/vb_exec"
    if command -v systemctl >/dev/null 2>&1; then
        systemctl disable --now vaultbackup.service >/dev/null 2>&1
        rm -f /etc/systemd/system/vaultbackup.service
        systemctl daemon-reload >/dev/null 2>&1
    fi
    rm -f /etc/cron.d/vaultbackup /var/run/vaultbackup.sock
    [ -d "$OLD/data" ] && rm -f "$OLD/data/da_mysql_cache.json" && chmod 700 "$OLD/data"
    [ -d /tmp/vaultbackup_data ] && rm -f /tmp/vaultbackup_data/da_mysql_cache.json && chmod 700 /tmp/vaultbackup_data
    echo "[!] Hãy gỡ plugin VaultBackup cũ trong Plugin Manager."
fi

$PHP -n "$PLUGIN_DIR/daemon/webnowbackup-cli.php" status

if [ "$HELPER_OK" = "1" ]; then
    echo "== Hoàn tất. Mở DirectAdmin > WebNowBackup để bật lịch sao lưu. =="
else
    echo "== Plugin đã cài nhưng ENGINE CHƯA CHẠY. Sửa lỗi gcc ở trên rồi chạy lại bằng root:"
    echo "   sh $PLUGIN_DIR/scripts/install.sh"
fi
# Không trả lỗi để Plugin Manager vẫn cài xong plugin; trang plugin sẽ hiện hướng dẫn nếu engine chưa sẵn sàng.
exit 0
