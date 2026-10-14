# WebNowBackup

Plugin sao lưu và khôi phục cho DirectAdmin (Evolution), dùng cho cả Admin, Reseller và User.

- **Kho local**: incremental bằng hardlink (`rsync --link-dest`). Mỗi bản chỉ tốn dung lượng cho phần thay đổi.
- **Kho cloud** qua rclone: S3/Wasabi/MinIO, Cloudflare R2, Backblaze B2, SFTP, FTP, WebDAV, Google Drive, OneDrive. Ba kiểu lưu:
  - **Incremental (restic)**, khuyên dùng: lần đầu tải đầy đủ, sau đó chỉ tải phần thay đổi; dữ liệu trùng giữa các lần và giữa các tài khoản chỉ lưu một lần; mã hóa; database được `mysqldump` thẳng vào restic. Mỗi kho có mật khẩu riêng (plugin tự tạo, admin xem và cất giữ được); **mất mật khẩu là mất toàn bộ bản sao lưu trong kho**. Có "Quét lại kho" để nạp lại danh sách bản sao lưu sau khi cài lại máy chủ, bảo trì kho tự động (prune mỗi ngày, check mỗi tuần).
  - **Nén** (mặc định cho S3, R2, B2, SFTP, FTP, Google Drive): home và database được nén rồi đẩy thẳng lên cloud theo luồng (`tar | gzip | rclone rcat`), máy chủ chỉ cần vài trăm MB trống, nhanh hơn nhiều so với tải từng file. Kèm danh sách file (`homedir.index.gz`) để vẫn duyệt và khôi phục được từng file; khôi phục giải nén thẳng vào home bằng quyền của user.
  - **Thư mục**: mỗi file là một object, duyệt/khôi phục từng file trực tiếp nhưng tải lên chậm và cần chỗ trống local bằng dung lượng tài khoản.
- **Giám sát nguy cơ mất dữ liệu**: mỗi 30 phút kiểm tra ổ đĩa (GB và inode), tài khoản quá hạn chưa có bản sao lưu, lịch không chạy đúng giờ, lượt chạy lỗi; báo qua Telegram/email khi có vấn đề mới, nhắc lại mỗi 24 giờ và báo khi đã ổn. Hiện banner ở Tổng quan.
- **Khôi phục từng phần**: file/thư mục, database, hộp thư email, DNS zone, cron, hoặc toàn bộ tài khoản.
- **Giữ bản theo ngày/tuần/tháng** (như JetBackup): ví dụ một lịch hằng ngày giữ 7 ngày gần nhất + 4 tuần + 3 tháng (bản cuối của mỗi kỳ), bản cũ hơn tự xóa. Ô "Mọi bản trong N giờ" (mặc định 24) giữ MỌI bản của lịch trong N giờ gần nhất, nên chạy lại lịch nhiều lần trong ngày hay lịch theo số giờ không thay mất bản trước. Ô "Mới nhất" giữ thêm N bản bất kỳ gần nhất. Bản sao lưu thủ công (nút Sao lưu ở tab Tài khoản) là nhóm riêng, không bao giờ xóa bản của lịch.
- **Lịch tự động**: hằng ngày, hằng tuần hoặc theo số giờ. Mỗi lịch chọn một trong hai chế độ: **Tất cả, trừ một số** (mặc định: sao lưu mọi tài khoản kể cả tài khoản tạo sau này, trừ danh sách loại trừ) hoặc **Chỉ tài khoản được chọn**; mỗi lịch có danh sách và số bản giữ lại riêng. Ở cả hai chế độ có thể chọn **theo reseller** (như Resellers Filter của JetBackup): chọn/loại trừ một reseller là tính cả reseller lẫn toàn bộ khách của họ, kể cả khách tạo sau này (chọn `admin` cho khách do Admin tạo). Tab Tài khoản gắn nhãn "Không có lịch" cho tài khoản không lịch nào đang bật sao lưu. Danh sách "Không bao giờ tự sao lưu" (theo reseller và theo tài khoản) áp dụng cho mọi lịch.
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

   Lệnh này sẽ biên dịch helper (cần `gcc`), tạo `/backup/webnowbackup`, tạo cron `/etc/cron.d/webnowbackup`, cài rclone nếu chưa có (bỏ qua bằng `WNB_SKIP_RCLONE=1`) và cài restic 0.17.3 từ GitHub có kiểm tra SHA256 nếu chưa có restic 0.17 trở lên (bỏ qua bằng `WNB_SKIP_RESTIC=1`). Lệnh cũng vô hiệu hóa phần chạy bằng root của plugin VaultBackup cũ nếu máy còn sót.
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
- Bản kiểu Nén: khôi phục một file phải tải (stream) cả bản nén home của tài khoản từ cloud.
- Giám sát chạy trong cron của plugin: nếu cron hỏng thì không gửi được cảnh báo (banner trong giao diện vẫn hiện).
