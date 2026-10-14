#!/bin/sh
# WebNowBackup - gỡ cài đặt. Bản sao lưu trong /backup/webnowbackup và trên cloud được GIỮ NGUYÊN.
PLUGIN_DIR=/usr/local/directadmin/plugins/webnowbackup

if [ "$(id -u)" = "0" ]; then
    rm -f /etc/cron.d/webnowbackup
    rm -f "$PLUGIN_DIR/bin/wnb_exec"
    echo "Đã gỡ cron và helper của WebNowBackup."
else
    rm -f "$PLUGIN_DIR/bin/wnb_exec" 2>/dev/null
    echo "Hãy chạy bằng root để gỡ cron: rm -f /etc/cron.d/webnowbackup"
fi
echo "Dữ liệu sao lưu trong /backup/webnowbackup được giữ nguyên."
exit 0
