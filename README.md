# WebNowBackup

Plugin sao lưu và khôi phục cho DirectAdmin (Evolution), dùng cho cả Admin, Reseller và User.

- **Kho local**: incremental bằng hardlink (`rsync --link-dest`). Mỗi bản chỉ tốn dung lượng cho phần thay đổi.
- **Kho cloud** qua rclone: S3/Wasabi/MinIO, Cloudflare R2, Backblaze B2, SFTP, FTP, WebDAV, Google Drive, OneDrive. Bản sao lưu lưu dạng thư mục nên vẫn duyệt và khôi phục được từng file.
- **Khôi phục từng phần**: file/thư mục, database, hộp thư email, DNS zone, cron, hoặc toàn bộ tài khoản.
- **Lịch tự động**: hằng ngày, hằng tuần hoặc theo số giờ. Lịch luôn sao lưu tất cả tài khoản (kể cả tài khoản tạo sau này), chỉ trừ các tài khoản bị loại trừ; mỗi lịch có danh sách loại trừ và số bản giữ lại riêng. **Loại trừ theo reseller** (như Resellers Filter của JetBackup): loại trừ một reseller thì cả reseller lẫn toàn bộ khách của họ, kể cả khách tạo sau này, không được sao lưu (chọn `admin` cho khách do Admin tạo). Danh sách "Không bao giờ tự sao lưu" (theo reseller và theo tài khoản) áp dụng cho mọi lịch.
- **Snapshot** do khách tự tạo, **tải bản sao lưu** dạng `.tar.gz`, **phân quyền** theo từng reseller, **hạn mức** dung lượng.
- **Thông báo** qua Telegram và email: báo cáo sau mỗi lượt chạy lịch, báo lỗi tác vụ thủ công. Token Telegram chỉ ghi đè được, không xem lại được.
- **Song ngữ** Tiếng Việt / English (nút chuyển ở đầu trang; thông báo tác vụ dịch theo người xem).
- File sao lưu thuộc root nên **không bị tính vào quota** của khách; không chạy sao lưu khi kho còn ít hơn mức trống tối thiểu.

## Cài đặt

1. Đóng gói: `php build-package.php` → `dist/webnowbackup.tar.gz`
2. DirectAdmin → Plugin Manager → Add Plugin → tải `webnowbackup.tar.gz` lên.
3. **Bắt buộc**: đăng nhập SSH bằng root để kích hoạt engine:

   ```bash
   sh /usr/local/directadmin/plugins/webnowbackup/scripts/install.sh
   ```

   Lệnh này sẽ biên dịch helper (cần `gcc`), tạo `/backup/webnowbackup`, tạo cron `/etc/cron.d/webnowbackup` và cài rclone nếu chưa có (bỏ qua bằng `WNB_SKIP_RCLONE=1`). Lệnh cũng vô hiệu hóa phần chạy bằng root của plugin VaultBackup cũ nếu máy còn sót.
4. Mở DirectAdmin → WebNowBackup → **Lịch tự động** rồi bật lịch.

Sau mỗi lần cập nhật plugin, nên chạy lại lệnh ở bước 3.

## Mô hình bảo mật

| Thành phần | Chạy bằng | Ghi chú |
|---|---|---|
| `admin/`, `reseller/`, `user/` | UNIX user đang đăng nhập | Không đọc/ghi dữ liệu, chỉ gọi helper |
| `bin/wnb_exec` | setuid root | Danh tính lấy từ UID thật; xóa môi trường; chỉ nhận lệnh `api` và `download`; từ chối chạy nếu mã PHP ghi được bởi người khác ngoài root/diradmin |
| `daemon/webnowbackup-cli.php` | root | Kiểm tra lại usertype trong DirectAdmin, token CSRF, phân quyền theo tài khoản |
| `data/` | root, 0700 | Catalog, `rclone.conf`, log, khóa CSRF |
| `/backup/webnowbackup` | root, 0711 | User không liệt kê được bản sao lưu của người khác |

Khi khôi phục vào thư mục home, dữ liệu được dựng ở vùng staging của root rồi rsync vào home **bằng quyền của chính user đó**. Symlink hay đường dẫn lừa trong home không thể khiến root ghi ra ngoài.

## Lệnh CLI (root)

```bash
php -n /usr/local/directadmin/plugins/webnowbackup/daemon/webnowbackup-cli.php status
php -n /usr/local/directadmin/plugins/webnowbackup/daemon/webnowbackup-cli.php run-task <task_id>
```

## Phát triển

Xem thử giao diện với dữ liệu demo (không cần DirectAdmin):

```bash
php -S 127.0.0.1:8088 router.php
```

Rồi mở `http://127.0.0.1:8088/?role=admin` (hoặc `reseller`, `user`). Thêm `&theme=dark` để xem giao diện tối.

## Giới hạn hiện tại

- **Khôi phục toàn bộ** áp dụng vào tài khoản **đang tồn tại**. Nếu tài khoản đã bị xóa, hãy tạo lại tài khoản trong DirectAdmin trước.
- Database được import lại nội dung, nhưng **chưa sao lưu user/quyền MySQL**. Nếu database đã bị xóa, cần gán lại user database trong DirectAdmin.
- Kho cloud không dùng hardlink: mỗi bản là một bản đầy đủ trên cloud. Hãy đặt số bản giữ lại phù hợp.
