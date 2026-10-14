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
Lịch có 2 chế độ (`filter_mode`, 2.4.0 — người dùng yêu cầu thêm chế độ chọn, giống Account Filter Include/Exclude của JetBackup nhưng đặt ngay trong form lịch):
- `exclude` (mặc định, lịch cũ không có trường này): sao lưu TẤT CẢ tài khoản (kể cả tạo sau này), trừ `exclude_users` / `exclude_resellers`.
- `include`: chỉ `include_users` + `include_resellers` (reseller = chính họ + khách, kể cả khách tạo sau). Lưu lịch include rỗng bị từ chối.
- Chỉ lưu danh sách của chế độ đang dùng (danh sách chế độ kia để []). Danh sách chung "Không bao giờ tự sao lưu" (`settings.exclude_accounts`/`exclude_resellers`) luôn thắng ở cả hai chế độ.
- State admin có `scheduled` (tài khoản thuộc ít nhất 1 lịch đang bật): tab Tài khoản gắn nhãn "Không có lịch", thẻ "chưa sao lưu 48 giờ" bỏ qua các tài khoản đó. Test: `tests/api_test5.php`; tổng 211/211 trên PHP 7.4 và 8.5.

## Đã làm: loại trừ theo reseller (giống JetBackup Resellers Filter)
- Lịch có `exclude_resellers`, Cài đặt có `settings.exclude_resellers` (áp dụng mọi lịch). Giá trị hợp lệ: tài khoản usertype reseller hoặc admin (`WNBDirectAdmin::isOwnerAccount`).
- `WNBDirectAdmin::resellerGroup(r)` = chính r + khách usertype user trong users.list của r (với admin: không gồm các reseller, mỗi reseller là nhóm riêng). `WNBEngine::jobTargets` tính động mỗi lần chạy nên khách tạo sau cũng bị loại trừ.
- Giao diện: ô "Loại trừ theo reseller" trên "Loại trừ tài khoản" (form lịch và thẻ "Không bao giờ tự sao lưu" trong Cài đặt); tài khoản thuộc reseller đã loại trừ bị làm mờ, gắn nhãn "Thuộc {r}"; bảng lịch hiện số reseller bị loại trừ; badge "Loại trừ" ở tab Tài khoản và thẻ "Chưa sao lưu 48 giờ" tính cả loại trừ theo reseller. Đã sửa: picker trong tab Cài đặt trước đây chưa được bindPickers (tìm kiếm/đếm không chạy).
- Test: `WNBDirectAdmin::$demoExtra` (chỉ preview) để giả lập khách tạo sau; 114/114 pass trên PHP 7.4 và 8.5.
- Preview: nếu cổng 8088 bị phiên khác chiếm, dùng cấu hình `webnowbackup-preview-2` (8089) trong `.claude/launch.json`.

## Đã làm: chế độ Nén cho kho cloud + giám sát (2.2.0)
- Kho cloud có `format` = `archive` (mặc định với `WNBRclone::ARCHIVE_TYPES`: s3, r2, b2, sftp, ftp, drive) hoặc `folder`. WebDAV/OneDrive luôn `folder` (rclone có thể phải đệm cả file ra đĩa). Bản ghi backup có `format`, `home_archive`; bản cũ không có `format` = `folder`.
- Bản nén trên cloud: `<user>/<id>/homedir.tar.gz` (tar -vv --index-file --quoting-style=literal | pigz/gzip | rclone rcat), `homedir.index.gz` (danh sách file), `databases/<db>.sql.gz` (mysqldump | gzip | rcat), cùng config/dns/emails/cron/manifest.json (nhỏ, rclone copy). Local chỉ cần `ARCHIVE_MIN_FREE_GB` 0.2 GB và 2000 inode.
- Duyệt: `WNBEngine::archiveIndex()` tải chỉ mục về `data/cache/idx_<id>.txt` (cleanup xóa sau 1 ngày), `WNBRclone::parseIndexLine()` đọc dòng tar -vv. Khôi phục home: `rclone cat | gzip -dc | runuser -u <user> -- tar -x` thẳng vào home (không staging). Mã thoát từng lệnh trong pipeline lấy qua dòng `WNB_PIPE ${PIPESTATUS[*]}`.
- Đã thử cờ tar bằng GNU tar 1.35 (Git Bash): loại trừ `--anchored --exclude='./backups/*'`, tên tiếng Việt, giải nén từng mục đều đúng. CHƯA chạy thật với rclone trên Linux: cần thử trên control3 (sao lưu 1 tài khoản lên S3/R2 kiểu Nén, duyệt, khôi phục 1 file và toàn bộ).
- Giám sát: `WNBEngine::healthIssues()` (không có lịch bật, ổ thiếu GB/inode theo kho của lịch, lịch quá giờ 2 tiếng, lượt chạy lỗi, tài khoản quá hạn = chu kỳ lịch + 12 giờ, bỏ qua tài khoản mới tạo theo `created_ts`), `healthCheck()` chạy trong cron 30 phút/lần, chống gửi trùng bằng chữ ký mã vấn đề (`data/health.json`), nhắc lại sau 24 giờ, báo khi hết. Cài đặt `notify_health`. State admin có `health` (banner ở Tổng quan).
- Test: `tests/api_test3.php`, tổng 152/152 trên PHP 7.4 và 8.5.

## Đã làm: giữ bản ngày/tuần/tháng + kho restic (2.3.0)
- Lịch có `keep_last`, `keep_daily`, `keep_weekly`, `keep_monthly` (mặc định 0/7/4/3, `Api::DEFAULT_KEEP`); lịch cũ chỉ có `retention` = giữ N bản mới nhất (`WNBEngine::jobPolicy`). `WNBEngine::retentionKeep()` chọn bản giữ giống `restic forget --keep-*` (bản mới nhất của mỗi ngày / tuần ISO / tháng, luôn giữ bản mới nhất), dùng chung cho mọi loại kho; nhóm = tài khoản + kho + loại + lịch (`groupKey`). State gắn `keep` cho từng bản (nhãn Ngày/Tuần/Tháng).
- Kho cloud `format = restic` (mọi loại kho, qua `restic -r rclone:<remote>:<subpath>/restic`, `-o rclone.program`, env RCLONE_CONFIG). Lớp `WNBRestic` nằm trong `includes/Rclone.php` (không thêm file PHP để khỏi sửa danh sách kiểm tra của `bin/wnb_exec.c`). Mật khẩu: `data/restic_<destid>.key` (0600), tự sinh khi tạo kho, `dest_restic_key` cho admin xem (cần CSRF), nhập tay để kết nối lại kho cũ (sai mật khẩu thì trả lại mật khẩu cũ). Cache `<local_backup_root>/.restic-cache`, TMPDIR = staging.
- Mỗi bản sao lưu restic: 1 snapshot home + thư mục cấu hình cố định `<staging>/rmeta/<user>` (để `--parent` đúng), mỗi database 1 snapshot `--stdin-from-command -- mysqldump ...` (file `/<db>.sql`). Tag: `wnb`, `u:`, `b:<backup id>`, `t:`, `j:`, `part:home`, `hp:<home>`, `mp:<meta>`, `db:<tên>`. Bản ghi có `restic` = {home, dbs, home_path, meta_path, host}.
- Khôi phục: `restic restore <home> --target <stage> --include ...` (thoát ký tự đại diện) rồi `finishHomeRestore` (chown + rsync bằng quyền user); database: `restic dump <snap> /<db>.sql | mysql`. Tải về: `resticExport` dựng lại cây giống bản local rồi tar. Xóa: `restic forget` (dọn dữ liệu ở tác vụ `maintain`).
- Cron: `scheduleMaintenance` (mỗi kho restic 1 lần/ngày khi không có tác vụ của kho: unlock, `prune --max-unused 10%`, `check` mỗi 7 ngày; kết quả ở `destinations[].restic_maint`, lỗi -> cảnh báo). Tác vụ `rescan` (nút "Quét lại kho") dựng lại catalog từ `restic snapshots --json --tag wnb` + manifest.json; bản nạp lại `owner_key = imported` nên chỉ admin thấy. Tác vụ hệ thống có `username = ''`, chỉ admin thấy.
- `install.sh` cài restic 0.17.3 (amd64/arm64) từ GitHub, kiểm tra SHA256SUMS.
- CHƯA chạy thật với restic (máy dev không có restic/Linux). Cần thử trên control3: tạo kho Incremental, sao lưu 1 tài khoản 2 lần (lần 2 phải "mới" rất nhỏ), duyệt, khôi phục 1 file, 1 database, toàn bộ, tải về, xóa, Quét lại kho, xem tác vụ Bảo trì kho hôm sau.
- Test: `tests/api_test4.php`; tổng 197/197 trên PHP 7.4 và 8.5.

## Đang chờ người dùng
control3: / (sda4, 64G, gồm cả /home và /backup) đầy 100% dung lượng VÀ 99% inode (còn ~31k). /backup/vaultbackup (dữ liệu plugin cũ, không dùng nữa) chiếm 14G; /backup/webnowbackup trống. Đã đề nghị: kiểm tra rồi xóa /backup/vaultbackup nếu không cần, tìm thư mục ngốn inode/dung lượng còn lại, về lâu dài dùng kho cloud hoặc gắn ổ riêng cho /backup. Bản 2.1.1 thêm kiểm tra inode (Engine::freeInodes, ngưỡng 50.000) trước khi sao lưu; lỗi đầy đĩa báo cả GB và inode trống. Người dùng chọn chuyển sang S3 kiểu Nén (2.2.0).
