# Prompt làm tiếp (dán vào phiên mới)

Dự án C:\laragon\www\vaultbackup là plugin DirectAdmin **WebNowBackup** (id `webnowbackup`, tên cũ VaultBackup — đã đổi hẳn, không còn chữ JetBackup). Trả lời bằng tiếng Việt; người dùng giao toàn quyền quyết, quan tâm giao diện đẹp hợp DA Evolution. Đọc README.md và tests/README.md trước.

## Kiến trúc (đừng phá)
- Trang plugin (admin/, reseller/, user/ index.html + api.raw) chạy bằng UNIX user đang đăng nhập DA, chỉ gọi helper setuid root `bin/wnb_exec` (C89, danh tính = getuid). Phía root: `daemon/webnowbackup-cli.php` → `includes/Api.php` (RBAC, CSRF HMAC, kiểm tra usertype) → `includes/Engine.php` (hàng đợi, worker, backup, restore, retention, lịch, thông báo Telegram + email). Dữ liệu `data/` root 0700. Backup ở `/backup/webnowbackup/<user>/<id>` (file thuộc root, rsync không -o/-g để không tính quota khách). Khôi phục vào home: dựng ở staging root rồi rsync BẰNG USER (runuser).
- Song ngữ: server dùng `WNBLang::t / WNBLang::msg / WNBError`, khóa là câu tiếng Việt, bản dịch ở `includes/lang/en.php`; giao diện `assets/app.js` dùng `t('...', {vars})`, bản dịch ở `assets/lang-en.js`. Câu mới phải có bản dịch (kiểm tra: `node tests/jskeys.js assets/app.js assets/lang-en.js` và `php -n tests/phpkeys.php` phải "missing 0").
- Thêm file PHP mà helper root nạp thì thêm vào danh sách kiểm tra quyền trong `bin/wnb_exec.c`.
- Giao diện nhúng trong iframe Evolution: đã có fitFrame (nới khung), tự tô nền sáng/tối, đặt modal theo vùng nhìn thấy. CSS nhãn form chỉ áp cho `.field > label` (đừng đổi lại, sẽ hỏng nút gạt và danh sách tick).
- Không có Linux/gcc trên máy dev: kiểm chứng bằng preview `php -S 127.0.0.1:8088 router.php` (`?role=admin|reseller|user`, `&theme=dark`, `&iframe=flex` giả lập iframe DA) và `tests/api_test.php` (104/104 pass trên PHP 7.4 và 8.5; `rm -rf data` trước khi chạy). Giữ tương thích PHP 7.4 và `php -n` (không mbstring/posix).
- Đóng gói: `php -n -d phar.readonly=0 build-package.php` → `dist/webnowbackup.tar.gz`, gửi file cho người dùng. Đổi file C thì nhắc chạy lại `sh /usr/local/directadmin/plugins/webnowbackup/scripts/install.sh` bằng root.

## Quyết định của người dùng
Lịch sao lưu theo **quy tắc loại trừ**: luôn sao lưu TẤT CẢ tài khoản (kể cả tạo sau này), trừ `exclude_users` của lịch và danh sách chung "Không bao giờ tự sao lưu" (`settings.exclude_accounts`). Không quay lại kiểu tick chọn tài khoản được sao lưu.

## Đã làm: loại trừ theo reseller (giống JetBackup Resellers Filter)
- Lịch có `exclude_resellers`, Cài đặt có `settings.exclude_resellers` (áp dụng mọi lịch). Giá trị hợp lệ: tài khoản usertype reseller hoặc admin (`WNBDirectAdmin::isOwnerAccount`).
- `WNBDirectAdmin::resellerGroup(r)` = chính r + khách usertype user trong users.list của r (với admin: không gồm các reseller, mỗi reseller là nhóm riêng). `WNBEngine::jobTargets` tính động mỗi lần chạy nên khách tạo sau cũng bị loại trừ.
- Giao diện: ô "Loại trừ theo reseller" trên "Loại trừ tài khoản" (form lịch và thẻ "Không bao giờ tự sao lưu" trong Cài đặt); tài khoản thuộc reseller đã loại trừ bị làm mờ, gắn nhãn "Thuộc {r}"; bảng lịch hiện số reseller bị loại trừ; badge "Loại trừ" ở tab Tài khoản và thẻ "Chưa sao lưu 48 giờ" tính cả loại trừ theo reseller. Đã sửa: picker trong tab Cài đặt trước đây chưa được bindPickers (tìm kiếm/đếm không chạy).
- Test: `WNBDirectAdmin::$demoExtra` (chỉ preview) để giả lập khách tạo sau; 114/114 pass trên PHP 7.4 và 8.5.
- Preview: nếu cổng 8088 bị phiên khác chiếm, dùng cấu hình `webnowbackup-preview-2` (8089) trong `.claude/launch.json`.

## Đang chờ người dùng
control3 báo `No space left on device` khi backup ra /backup/webnowbackup (tài khoản ~300 MB). Đã nhờ chạy `df -h; df -i; du -xsh /backup/* /usr/local/directadmin/plugins/*/data; du -sh /home/wnterm`. Khi có kết quả: nghi /backup nằm trên phân vùng / nhỏ → đề nghị đổi "Thư mục lưu trữ local" sang ổ lớn (vd /home/backup_wnb do root tạo); nếu `df -i` 100% thì là hết inode.
