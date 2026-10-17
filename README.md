# WebNowBackup

Plugin sao lưu và khôi phục cho **DirectAdmin** (giao diện Evolution, sáng/tối), dùng được ở cả ba cấp **Admin, Reseller và User**. Sao lưu lên ổ cứng máy chủ hoặc lên cloud (S3, R2, B2, Google Drive, OneDrive…). Mã hóa và incremental bằng restic, khôi phục được từng file. Song ngữ Tiếng Việt / English.

Phiên bản hiện tại: **2.6.2** · PHP 7.4 – 8.5 · DirectAdmin 1.6x (đã chạy trên 1.671)

---

## Tính năng chính

### Kho lưu trữ

| Kiểu kho | Cách lưu | Phù hợp khi |
|---|---|---|
| **Ổ cứng máy chủ** | Incremental bằng hardlink (`rsync --link-dest`), mỗi bản chỉ tốn dung lượng phần thay đổi | Có ổ đĩa riêng cho `/backup` |
| **Cloud · Incremental (restic)** *(khuyên dùng)* | Lần đầu tải đầy đủ, sau đó chỉ tải phần thay đổi. Dữ liệu trùng (giữa các lần, giữa các tài khoản) chỉ lưu một lần. **Mã hóa.** Database `mysqldump` thẳng vào restic | Muốn giữ nhiều bản mà tốn ít dung lượng cloud |
| **Cloud · Nén** | `tar \| gzip \| rclone rcat` đẩy thẳng lên cloud theo luồng; máy chủ chỉ cần vài trăm MB trống. Kèm danh sách file để vẫn duyệt và khôi phục được từng file | Máy chủ gần đầy đĩa, cần tải lên nhanh |
| **Cloud · Thư mục** | Mỗi file là một object, duyệt và khôi phục trực tiếp | Kho WebDAV, OneDrive |

- Cloud qua **rclone**: S3 / Wasabi / MinIO, Cloudflare R2, Backblaze B2, SFTP, FTP, WebDAV, Google Drive, OneDrive.
- **Google Drive / OneDrive kết nối bằng nút bấm**: bấm "Kết nối", đăng nhập, cho phép, rồi dán lại địa chỉ trang hiện ra. Không cần cài hay đăng ký gì thêm. Token không bao giờ gửi về trình duyệt.
- **Thư mục đích mặc định** là `WebNowBackup/<tên máy chủ>`, nên nhiều máy chủ dùng chung một kho mà không đè lên nhau. Khoảng trắng hay ký tự ẩn dính vào khi dán được tự bỏ.
- **Kho restic**: mỗi kho có mật khẩu riêng (plugin tự tạo, admin xem và cất giữ được). **Mất mật khẩu là mất toàn bộ bản sao lưu trong kho.** Kho tự bảo trì: prune mỗi ngày, check mỗi tuần. Có nút **"Quét lại kho"** để nạp lại danh sách bản sao lưu sau khi cài lại máy chủ.
- Sửa kho mà vị trí thay đổi thì plugin tự gỡ các bản cũ khỏi danh sách (không xóa dữ liệu ở vị trí cũ), tránh danh sách trỏ tới bản không còn.
- **"Quét lại kho" cho mọi kiểu kho** (Incremental, Nén, Thư mục, ổ cứng máy chủ): cài lại máy chủ thì thêm lại kho với cùng vị trí rồi bấm Quét lại để nạp lại danh sách bản sao lưu. Kiểu Nén/Thư mục đọc `manifest.json` của từng bản, kể cả bản tạo bằng các phiên bản cũ.
- **Chuyển kho** (nút ở bảng Kho lưu trữ): chuyển mọi bản sao lưu của một kho sang kho mới thêm, thư mục trống, ví dụ từ kho S3 nhỏ sang kho S3 lớn hơn, hoặc từ ổ cứng máy chủ lên cloud. Plugin chép dữ liệu, so lại từng file ở kho mới rồi mới chuyển danh sách, nên chủ sở hữu, lịch và nhãn giữ bản giữ nguyên (khách vẫn thấy bản của mình). Tùy chọn: lịch và snapshot từ nay lưu vào kho mới, xóa dữ liệu ở kho cũ sau khi kiểm tra xong. Lỗi hoặc bấm Dừng trong lúc chép thì phần đã chép ở kho mới được dọn, kho cũ không bị đụng tới. Trong lúc chuyển, các tác vụ khác của hai kho tự đợi. Kho restic chuyển nguyên kho (cùng mật khẩu) sang kho restic mới.
- **Dung lượng thật của kho** (2.6.1): Tổng quan, bảng Kho lưu trữ và hộp Chuyển kho hiện dung lượng kho thực sự chiếm, đo bằng `du` (ổ cứng, hardlink chỉ tính một lần) hoặc `rclone size` (cloud). Số này không phải tổng dung lượng từng bản: các bản Incremental dùng chung dữ liệu nên cộng lại sẽ lớn gấp nhiều lần. Plugin đo nền khi không có tác vụ: sau mỗi lần sao lưu, xóa, dọn kho hoặc chuyển kho, khi bấm Kiểm tra, và ít nhất mỗi ngày một lần.
- **Loại trừ đường dẫn** (Cài đặt): mỗi dòng một mẫu tính từ home, ví dụ `lscache/*`. Mẫu bắt đầu bằng `**/` khớp ở mọi cấp thư mục, ví dụ `**/node_modules`.
- **Tài khoản nhiều database dump nhanh**: database được `mysqldump` song song (4 luồng). Với kho restic, các database nhỏ được gom theo lô, mỗi lô một snapshot, thay vì mỗi database một lần gọi restic. Database lớn vẫn dump thẳng vào restic. Tài khoản 1200 database không còn mất hàng giờ.
- Giới hạn băng thông tải lên (`bwlimit`); tiến trình sao lưu chạy với `nice`/`ionice` để không làm chậm web của khách.

### Lịch tự động

- Chạy **hằng ngày, hằng tuần hoặc mỗi N giờ**.
- Mỗi lịch chọn một trong hai chế độ:
  - **Tất cả, trừ một số**: sao lưu mọi tài khoản, kể cả tài khoản tạo sau này.
  - **Chỉ tài khoản được chọn**.
- **Chọn theo reseller** (giống Resellers Filter của JetBackup): chọn hoặc loại trừ một reseller là tính cả reseller đó và toàn bộ khách của họ, kể cả khách tạo sau.
- Danh sách **"Không bao giờ tự sao lưu"** áp dụng cho mọi lịch.
- Form lịch hiện tổng dung lượng ước tính. Tab Tài khoản gắn nhãn **"Không có lịch"** cho tài khoản chưa được lịch nào sao lưu.
- **Tự thử lại tài khoản lỗi**: lỗi mạng hay cloud chập chờn thì lượt chạy tự sao lưu lại tài khoản đó một lần sau N phút (mặc định 30) rồi mới gửi báo cáo. Lỗi không tự hết thì không thử lại: đầy đĩa, thiếu công cụ, tài khoản hoặc kho đã xóa.

### Giữ bản theo ngày / tuần / tháng

- Giống JetBackup và `restic forget`. Ví dụ giữ **7 ngày + 4 tuần + 3 tháng** (bản cuối của mỗi kỳ), bản cũ hơn tự xóa.
- **"Mọi bản trong N giờ"** (mặc định 24) giữ mọi bản gần đây, nên chạy lịch nhiều lần trong ngày không làm mất bản trước.
- Bản sao lưu thủ công là nhóm riêng, không bao giờ xóa bản của lịch.
- **Bản thiếu phần** (database dump lỗi, không có thư mục home) được gắn nhãn "Thiếu phần" và không đẩy mất bản đầy đủ: kỳ nào bản cuối bị thiếu thì giữ thêm bản đầy đủ của kỳ đó, và bản đầy đủ mới nhất luôn được giữ. Bản tạo bằng phiên bản cũ được nhận ra từ cảnh báo đã lưu.
- Form lịch cho biết **bản cũ nhất lùi về bao nhiêu ngày**. Lưu ý: ngày/tuần/tháng tính cả kỳ hiện tại (giống restic), nên "3 tháng" với lịch hằng ngày chỉ lùi về khoảng 31–60 ngày.
- **Tự xóa bản mồ côi** (Cài đặt, mặc định tắt): bản của tài khoản đã xóa, và bản của lịch đã xóa hoặc không còn sao lưu tài khoản đó, tự xóa sau N ngày kể từ bản cuối. Mỗi tài khoản vẫn giữ bản mới nhất nếu không có bản đầy đủ nào mới hơn. Lịch đang tắt không bị dọn. Bản sắp xóa có nhãn "Tự xóa …"; vừa bật hoặc rút ngắn thời hạn thì đợi thêm 1 ngày mới xóa.

### Tác vụ: theo dõi và điều khiển

- **Thanh tiến độ thật** cho bước sao lưu home, kèm dung lượng đã xử lý (vd `1.8 GB / ~3.4 GB`). Lấy từ `rsync --info=progress2`, chỉ mục tar hoặc trạng thái JSON của restic.
- **Nút Dừng** cho tác vụ đang chờ hoặc đang chạy:
  - **"Dừng tất cả"** (admin) và **"Dừng (n)"** cho cả một lượt chạy của lịch.
  - Bản sao lưu dở dang được dọn sạch, cả ở local và trên cloud.
  - Worker tự dừng cả cây tiến trình con (rsync, mysqldump, rclone, restic). Nếu sau 3 phút vẫn kẹt, cron dừng hẳn worker.
  - Reseller và user chỉ dừng được tác vụ do chính họ tạo. Tác vụ xóa đang chạy không cho dừng giữa chừng.
- Log chi tiết từng tác vụ. Các tác vụ chạy song song được, giới hạn số lượng trong Cài đặt.

### Khôi phục

- **Từng phần**: file/thư mục, database, hộp thư email, DNS zone, cron, hoặc **toàn bộ tài khoản**.
- Dữ liệu được dựng ở vùng staging của root, rồi **rsync vào home bằng quyền của chính user đó**. Symlink hay đường dẫn lừa không thể khiến root ghi ra ngoài.
- **Tài khoản đã xóa**: bản sao lưu vẫn được giữ (hoặc tự xóa sau N ngày nếu bật "Tự xóa bản mồ côi"). Admin xem, tải về, xóa ở thẻ "Tài khoản đã xóa". Plugin hướng dẫn từng bước tạo lại tài khoản, subdomain, domain pointer, SSL rồi khôi phục toàn bộ.
- Tài khoản tạo lại trùng tên: bản của tài khoản cũ được gắn nhãn riêng, và chính sách giữ bản của tài khoản mới không xóa mất chúng.
- Cảnh báo khi khôi phục vào tài khoản còn thiếu tên miền, subdomain hoặc pointer có trong bản sao lưu.

### Giám sát và thông báo

- Mỗi 30 phút kiểm tra các nguy cơ mất dữ liệu:
  - ổ đĩa sắp hết (cả GB lẫn inode);
  - tài khoản quá hạn chưa có bản sao lưu;
  - lịch không chạy đúng giờ;
  - lượt chạy bị lỗi.
- Báo qua **Telegram và email** khi có vấn đề mới, nhắc lại mỗi 24 giờ, báo khi đã ổn. Có banner ở trang Tổng quan.
- Gửi một báo cáo cho mỗi lượt chạy lịch (thành công / lỗi / đã dừng / thành công khi thử lại), không gửi lẻ từng tài khoản.
- Mọi tin đều mang **tên máy chủ** để phân biệt khi nhiều máy chủ báo về cùng một nhóm.

### Cho Reseller và User

- **Snapshot** do khách tự tạo, **tải bản sao lưu** dạng `.tar.gz`.
- **Phân quyền** theo từng reseller: bật/tắt sao lưu, khôi phục, snapshot, tải về. Có **hạn mức** dung lượng.
- File sao lưu thuộc root nên **không bị tính vào quota** của khách.

---

## Cài đặt

1. Đóng gói (hoặc dùng file `.tar.gz` có sẵn):

   ```bash
   php -n -d phar.readonly=0 build-package.php
   ```

   Lệnh tạo ra `dist/webnowbackup.tar.gz`.
2. DirectAdmin → **Plugin Manager** → Add Plugin → tải `webnowbackup.tar.gz` lên.
3. Kiểm tra engine bằng root (trên DirectAdmin mới, Plugin Manager đã tự chạy bước này):

   ```bash
   sh /usr/local/directadmin/plugins/webnowbackup/scripts/install.sh
   ```

   Lệnh này:
   - biên dịch helper setuid (cần `gcc`);
   - tạo `/backup/webnowbackup` và cron `/etc/cron.d/webnowbackup`;
   - cài **rclone** nếu chưa có (bỏ qua bằng `WNB_SKIP_RCLONE=1`);
   - cài **restic 0.17.3** có kiểm tra SHA256 (bỏ qua bằng `WNB_SKIP_RESTIC=1`);
   - vô hiệu hóa phần chạy bằng root của plugin VaultBackup cũ nếu máy còn sót.
4. Mở **DirectAdmin → WebNowBackup → Kho lưu trữ** để thêm kho cloud, rồi vào **Lịch tự động** tạo hoặc bật lịch.

Sau mỗi lần cập nhật mà file `bin/wnb_exec.c` thay đổi, chạy lại lệnh ở bước 3. Nếu gcc trên máy bị hỏng mà `bin/wnb_exec.c` không đổi so với lần biên dịch trước, script dùng tiếp helper cũ và báo rõ (engine vẫn chạy).

---

## Mô hình bảo mật

| Thành phần | Chạy bằng | Ghi chú |
|---|---|---|
| `admin/`, `reseller/`, `user/` | UNIX user đang đăng nhập | Không đọc/ghi dữ liệu, chỉ gọi helper |
| `bin/wnb_exec` | setuid root | Danh tính lấy từ UID thật; xóa môi trường; chỉ nhận lệnh `api` và `download`; từ chối chạy nếu mã PHP ghi được bởi người khác ngoài root/diradmin |
| `daemon/webnowbackup-cli.php` | root | Kiểm tra lại usertype trong DirectAdmin, token CSRF, phân quyền theo tài khoản |
| `data/` | root, 0700 | Catalog, `rclone.conf`, mật khẩu restic, log, khóa CSRF |
| `/backup/webnowbackup` | root, 0711 | User không liệt kê được bản sao lưu của người khác |

---

## Lệnh CLI (root)

```bash
php -n /usr/local/directadmin/plugins/webnowbackup/daemon/webnowbackup-cli.php status
```

```bash
php -n /usr/local/directadmin/plugins/webnowbackup/daemon/webnowbackup-cli.php run-task <task_id>
```

---

## Phát triển

Xem thử giao diện với dữ liệu demo (không cần DirectAdmin):

```bash
php -n tests/seed.php && php -S 127.0.0.1:8088 router.php
```

Mở `http://127.0.0.1:8088/?role=admin` (hoặc `reseller`, `user`). Thêm `&theme=dark` để xem giao diện tối, `&iframe=flex` để giả lập khung của DirectAdmin.

Chạy test (443 kiểm tra, PHP 7.4 và 8.5, xem thêm [tests/README.md](tests/README.md)):

```bash
rm -rf data && php -n tests/api_test.php
```

Kiểm tra bản dịch (phải ra `missing 0`):

```bash
node tests/jskeys.js assets/app.js assets/lang-en.js && php -n tests/phpkeys.php
```

---

## Giới hạn hiện tại

- **Khôi phục toàn bộ** chỉ áp dụng vào tài khoản **đang tồn tại**. Plugin không tự tạo tài khoản DirectAdmin. Nếu tài khoản đã bị xóa, hãy tạo lại theo hướng dẫn trong plugin trước.
- Plugin không tự tạo lại subdomain, domain pointer và SSL. Danh sách được ghi trong bản sao lưu để làm theo.
- Database được import lại nội dung, nhưng **chưa sao lưu user/quyền MySQL**. Nếu database đã bị xóa, cần gán lại user database trong DirectAdmin.
- Kho cloud kiểu **Nén** và **Thư mục** lưu mỗi bản đầy đủ (chỉ restic là incremental). Hãy đặt số bản giữ lại phù hợp.
- **Chuyển kho**: kho đích phải là kho mới (chưa có bản sao lưu, thư mục trống) và cùng cách lưu (restic sang restic; Nén/Thư mục sang Nén/Thư mục hoặc ổ cứng). Kho có cả bản restic lẫn bản Nén/Thư mục chưa chuyển được. Chuyển từ ổ cứng máy chủ lên cloud thì các file dùng chung giữa các bản (hardlink) được tải lên thành file riêng, tốn dung lượng như bản đầy đủ.
- Bản kiểu Nén: khôi phục một file phải tải (stream) cả bản nén home của tài khoản từ cloud.
- Giám sát chạy trong cron của plugin. Nếu cron hỏng thì không gửi được cảnh báo (banner trong giao diện vẫn hiện).
