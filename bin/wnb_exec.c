/*
 * WebNowBackup - helper setuid root.
 *
 * Script giao diện của plugin chạy bằng UNIX user đang đăng nhập DirectAdmin. Helper này là cầu nối
 * duy nhất lên quyền root, với các ràng buộc:
 *   - Danh tính lấy từ UID THẬT của tiến trình gọi (getuid), không nhận từ tham số hay biến môi trường.
 *   - Chỉ cho 2 lệnh: "api" và "download". Mọi phân quyền do PHP phía root kiểm tra lại.
 *   - Xóa sạch môi trường (chặn LD_PRELOAD, PHPRC...) và chạy php -n (không nạp php.ini).
 *   - Từ chối chạy nếu script PHP có thể bị sửa bởi người khác ngoài root/diradmin.
 *
 * Viết theo C89 (khai báo biến đầu khối, không khai báo trong for) để biên dịch được cả với gcc cũ
 * như gcc 4.8 của CentOS 7.
 */
#define _GNU_SOURCE
#include <errno.h>
#include <grp.h>
#include <pwd.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/resource.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>

#define PLUGIN_DIR "/usr/local/directadmin/plugins/webnowbackup"
#define CLI_SCRIPT PLUGIN_DIR "/daemon/webnowbackup-cli.php"

static uid_t diradmin_uid = (uid_t)-1;

static int trusted_owner(uid_t uid)
{
    return uid == 0 || (diradmin_uid != (uid_t)-1 && uid == diradmin_uid);
}

/* Đường dẫn phải thuộc root/diradmin, không cho nhóm/người khác ghi, không phải symlink (trừ khi follow). */
static int safe_path_ex(const char *path, int want_dir, int follow)
{
    struct stat st;
    int rc;

    rc = follow ? stat(path, &st) : lstat(path, &st);
    if (rc != 0) {
        return 0;
    }
    if (want_dir ? !S_ISDIR(st.st_mode) : !S_ISREG(st.st_mode)) {
        return 0;
    }
    if (!trusted_owner(st.st_uid) || (st.st_mode & (S_IWGRP | S_IWOTH))) {
        return 0;
    }
    return 1;
}

static int safe_path(const char *path, int want_dir)
{
    return safe_path_ex(path, want_dir, 0);
}

/*
 * Không thừa hưởng gì từ tiến trình gọi: giới hạn tài nguyên (vd RLIMIT_FSIZE thấp làm hỏng bản sao lưu
 * của người khác), tín hiệu bị bỏ qua/chặn, file descriptor mở sẵn, độ ưu tiên.
 */
static void reset_process_state(void)
{
    static const int unlimited[] = {RLIMIT_FSIZE, RLIMIT_CPU, RLIMIT_DATA, RLIMIT_AS, RLIMIT_STACK, -1};
    struct rlimit rl;
    sigset_t all;
    long maxfd;
    long fd;
    int i;
    int sig;

    for (i = 0; unlimited[i] != -1; i++) {
        rl.rlim_cur = RLIM_INFINITY;
        rl.rlim_max = RLIM_INFINITY;
        if (setrlimit(unlimited[i], &rl) != 0 && unlimited[i] == RLIMIT_STACK) {
            rl.rlim_cur = 8 * 1024 * 1024;
            rl.rlim_max = 8 * 1024 * 1024;
            setrlimit(RLIMIT_STACK, &rl);
        }
    }
    rl.rlim_cur = 65536;
    rl.rlim_max = 65536;
    setrlimit(RLIMIT_NOFILE, &rl);
    rl.rlim_cur = 0;
    rl.rlim_max = 0;
    setrlimit(RLIMIT_CORE, &rl);

    sigemptyset(&all);
    sigprocmask(SIG_SETMASK, &all, NULL);
    for (sig = 1; sig < NSIG; sig++) {
        if (sig != SIGKILL && sig != SIGSTOP) {
            signal(sig, SIG_DFL);
        }
    }

    maxfd = sysconf(_SC_OPEN_MAX);
    if (maxfd < 0 || maxfd > 65536) {
        maxfd = 65536;
    }
    for (fd = 3; fd < maxfd; fd++) {
        close((int)fd);
    }
    setpriority(PRIO_PROCESS, 0, 0);
}

static int valid_name(const char *s)
{
    size_t n;
    size_t i;
    char c;

    n = strlen(s);
    if (n == 0 || n > 32) {
        return 0;
    }
    for (i = 0; i < n; i++) {
        c = s[i];
        if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '_' || c == '-')) {
            return 0;
        }
    }
    return 1;
}

int main(int argc, char **argv)
{
    static const char *files[] = {
        CLI_SCRIPT,
        PLUGIN_DIR "/includes/bootstrap.php",
        PLUGIN_DIR "/includes/Lang.php",
        PLUGIN_DIR "/includes/lang/en.php",
        PLUGIN_DIR "/includes/Storage.php",
        PLUGIN_DIR "/includes/DirectAdmin.php",
        PLUGIN_DIR "/includes/Rclone.php",
        PLUGIN_DIR "/includes/Engine.php",
        PLUGIN_DIR "/includes/Api.php",
        PLUGIN_DIR "/includes/Client.php",
        NULL
    };
    static const char *dirs[] = {PLUGIN_DIR, PLUGIN_DIR "/daemon", PLUGIN_DIR "/includes", PLUGIN_DIR "/includes/lang", NULL};
    uid_t ruid;
    struct passwd *pw;
    struct passwd *da;
    char caller[64];
    char caller_uid[32];
    const char *php;
    char *nargv[7];
    int i;

    if (argc != 2 || (strcmp(argv[1], "api") != 0 && strcmp(argv[1], "download") != 0)) {
        fprintf(stderr, "usage: wnb_exec api|download\n");
        return 64;
    }

    ruid = getuid();
    pw = getpwuid(ruid);
    if (pw == NULL || !valid_name(pw->pw_name)) {
        fprintf(stderr, "unknown caller\n");
        return 65;
    }
    snprintf(caller, sizeof(caller), "%s", pw->pw_name);
    snprintf(caller_uid, sizeof(caller_uid), "%u", (unsigned)ruid);

    da = getpwnam("diradmin");
    if (da != NULL) {
        diradmin_uid = da->pw_uid;
    }

    /* php của CustomBuild thường là symlink do root tạo trong /usr/local/bin: kiểm tra file đích. */
    php = "/usr/local/bin/php";
    if (!safe_path_ex(php, 0, 1)) {
        php = "/usr/bin/php";
        if (!safe_path_ex(php, 0, 1)) {
            fprintf(stderr, "php binary not found or unsafe\n");
            return 69;
        }
    }
    for (i = 0; dirs[i] != NULL; i++) {
        if (!safe_path(dirs[i], 1)) {
            fprintf(stderr, "unsafe permissions: %s\n", dirs[i]);
            return 77;
        }
    }
    for (i = 0; files[i] != NULL; i++) {
        if (!safe_path(files[i], 0)) {
            fprintf(stderr, "unsafe permissions: %s\n", files[i]);
            return 77;
        }
    }

    if (setgroups(0, NULL) != 0 || setgid(0) != 0 || setuid(0) != 0 || getuid() != 0 || geteuid() != 0) {
        fprintf(stderr, "wnb_exec must be owned by root with the setuid bit (run scripts/install.sh as root)\n");
        return 77;
    }

    reset_process_state();

    if (clearenv() != 0) {
        return 71;
    }
    setenv("PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", 1);
    setenv("HOME", "/root", 1);
    setenv("WNB_CALLER_USER", caller, 1);
    setenv("WNB_CALLER_UID", caller_uid, 1);
    umask(077);
    if (chdir("/") != 0) {
        return 71;
    }

    nargv[0] = (char *)php;
    nargv[1] = "-n";
    nargv[2] = "-d";
    nargv[3] = "memory_limit=512M";
    nargv[4] = CLI_SCRIPT;
    nargv[5] = argv[1];
    nargv[6] = NULL;
    execv(php, nargv);
    fprintf(stderr, "execv failed: %s\n", strerror(errno));
    return 71;
}
