/* WebNowBackup - giao diện (vanilla JS, không phụ thuộc thư viện ngoài). Song ngữ Việt / English. */
(function () {
    'use strict';

    var B = window.WNB_BOOT || {};
    var root = document.getElementById('wnb');
    if (!root) return;
    var appEl = root.querySelector('[data-app]');
    var modalHost = root.querySelector('[data-modals]');
    var toastHost = root.querySelector('[data-toasts]');

    var S = B.state || null;
    var engineError = B.error || null;
    var transport = 'raw';
    var ROLE = B.role;

    /* ============================== Ngôn ngữ ============================== */

    var EN = window.WNB_EN || {};
    var LANG = 'vi';
    try { LANG = localStorage.getItem('wnb-lang') === 'en' ? 'en' : 'vi'; } catch (e) { /* bỏ qua */ }

    /** Dịch: khóa là câu tiếng Việt, biến dạng {ten}. */
    function t(s, vars) {
        var out = LANG === 'en' && EN[s] ? EN[s] : s;
        if (vars) out = out.replace(/\{(\w+)\}/g, function (m, k) { return vars[k] !== undefined ? vars[k] : m; });
        return out;
    }
    function setLang(l) {
        LANG = l === 'en' ? 'en' : 'vi';
        try { localStorage.setItem('wnb-lang', LANG); } catch (e) { /* bỏ qua */ }
        document.documentElement.lang = LANG;
        api('state').then(function () { render(); }, function () { render(); });
    }
    function weekdays() { return [t('Chủ nhật'), t('Thứ Hai'), t('Thứ Ba'), t('Thứ Tư'), t('Thứ Năm'), t('Thứ Sáu'), t('Thứ Bảy')]; }

    var ui = {
        tab: ROLE === 'user' ? 'restore' : (ROLE === 'reseller' ? 'accounts' : 'overview'),
        search: '',
        bkUser: '',
        bkType: '',
        taskStatus: ''
    };
    try { var saved = sessionStorage.getItem('wnb-tab-' + ROLE); if (saved) ui.tab = saved; } catch (e) { /* bỏ qua */ }

    /* ============================== Tiện ích ============================== */

    function esc(v) {
        return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function ic(name, cls) { return '<svg class="i ' + (cls || '') + '" aria-hidden="true"><use href="#wnb-i-' + name + '"></use></svg>'; }
    function parseDate(s) {
        if (!s) return null;
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(s);
        return m ? new Date(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null;
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function fmtDate(s, withYear) {
        var d = parseDate(s);
        if (!d) return '—';
        var day = LANG === 'en'
            ? (withYear === false ? '' : d.getFullYear() + '-') + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            : pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + (withYear === false ? '' : '/' + d.getFullYear());
        return day + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    var loadedAt = Date.now();
    function serverNow() {
        var d = parseDate(S && S.now);
        return d ? d.getTime() + (Date.now() - loadedAt) : Date.now();
    }
    function fmtRel(s) {
        var d = parseDate(s);
        if (!d) return '—';
        var diff = Math.round((serverNow() - d.getTime()) / 1000);
        var future = diff < 0;
        var a = Math.abs(diff);
        var n, unit;
        if (a < 60) return future ? t('trong giây lát') : t('vừa xong');
        if (a < 3600) { n = Math.round(a / 60); unit = t('phút'); }
        else if (a < 86400) { n = Math.round(a / 3600); unit = t('giờ'); }
        else if (a < 86400 * 30) { n = Math.round(a / 86400); unit = t('ngày'); }
        else return fmtDate(s);
        return future ? t('sau {n} {unit}', { n: n, unit: unit }) : t('{n} {unit} trước', { n: n, unit: unit });
    }
    /** Lần chạy tới: nếu đã quá giờ thì cron sẽ chạy trong vòng 1 phút. */
    function fmtNext(s) {
        var d = parseDate(s);
        if (d && d.getTime() <= serverNow()) return t('đến giờ, chạy trong 1 phút');
        return fmtRel(s);
    }
    function num(x, digits) {
        var s = (+x).toFixed(digits);
        return LANG === 'en' ? s : s.replace('.', ',');
    }
    function fmtMb(mb) {
        mb = +mb || 0;
        if (mb >= 1024) return num(mb / 1024, mb >= 10240 ? 0 : 1) + ' GB';
        return (mb >= 100 ? Math.round(mb) : num(mb, 1)) + ' MB';
    }
    function fmtBytes(b) {
        b = +b || 0;
        if (b < 1024) return b + ' B';
        if (b < 1048576) return num(b / 1024, 1) + ' KB';
        return fmtMb(b / 1048576);
    }
    function sep(url) { return url.indexOf('?') === -1 ? '?' : '&'; }
    function byId(id, list) { for (var i = 0; i < (list || []).length; i++) if (list[i].id === id) return list[i]; return null; }
    function perm(k) { return !!(S && S.perms && S.perms[k]); }
    /** Tên mặc định (kho, lịch) được lưu bằng tiếng Việt: dịch khi hiển thị. */
    function nameOf(x) { return x ? t(x) : ''; }

    /* ============================== API ============================== */

    function api(action, params) {
        var qs = new URLSearchParams();
        qs.set('action', action);
        qs.set('lang', LANG);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === undefined || v === null) return;
            qs.set(k, Array.isArray(v) ? JSON.stringify(v) : String(v));
        });
        if (S && S.csrf) qs.set('csrf', S.csrf);
        var url = transport === 'raw' ? B.api + sep(B.api) + qs : B.page + sep(B.page) + 'wnb_api=1&' + qs;
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (text) {
                var res = parseResponse(text);
                if (!res) {
                    if (transport === 'raw') { transport = 'page'; return api(action, params); }
                    throw new Error(t('Máy chủ trả về dữ liệu không hợp lệ.'));
                }
                if (!res.ok) {
                    if (res.code === 'engine_missing') { engineError = res; render(); }
                    throw new Error(res.error || t('Có lỗi xảy ra.'));
                }
                engineError = null;
                if (res.data && res.data.state) setState(res.data.state);
                else if (action === 'state') setState(res.data);
                return res.data;
            });
    }
    function parseResponse(text) {
        var m = /<!--WNB_JSON-->([\s\S]*?)<!--\/WNB_JSON-->/.exec(text);
        try { return JSON.parse(m ? m[1] : text); } catch (e) { return null; }
    }
    function setState(st) { S = st; loadedAt = Date.now(); }

    function run(btn, promise, okMsg) {
        if (btn) btn.classList.add('loading');
        return promise.then(function (data) {
            if (btn) btn.classList.remove('loading');
            var msg = okMsg || (data && data.message);
            if (msg) toast(msg);
            render();
            schedulePoll();
            return data;
        }, function (err) {
            if (btn) btn.classList.remove('loading');
            toast(err.message, true);
            throw err;
        });
    }

    /* ============================== Toast & modal ============================== */

    function toast(msg, bad) {
        var el = document.createElement('div');
        el.className = 'toast ' + (bad ? 'bad' : 'ok');
        el.setAttribute('role', bad ? 'alert' : 'status');
        el.innerHTML = ic(bad ? 'alert' : 'check') + '<div class="grow">' + esc(msg) + '</div><button class="btn btn-ghost btn-sm btn-icon" aria-label="' + esc(t('Đóng')) + '">' + ic('x') + '</button>';
        el.querySelector('button').onclick = function () { el.remove(); };
        toastHost.appendChild(el);
        setTimeout(function () { el.remove(); }, bad ? 8000 : 4500);
    }

    /* ---------- Iframe của DirectAdmin Evolution ---------- */

    function frameEl() { try { return window.frameElement || null; } catch (e) { return null; } }

    /** Phần iframe đang thật sự nằm trong màn hình của trang DA (tọa độ trong iframe). */
    function visibleArea() {
        var fe = frameEl();
        if (fe) {
            try {
                var r = fe.getBoundingClientRect();
                var ph = window.parent.innerHeight;
                var top = Math.max(0, -r.top);
                var bottom = Math.min(r.height, ph - r.top);
                return { top: top, height: Math.max(360, bottom - top) };
            } catch (e) { /* bỏ qua */ }
        }
        return { top: 0, height: window.innerHeight };
    }

    var modalNeed = 0;
    /**
     * Evolution đặt plugin trong iframe cao bằng nội dung lúc tải và bọc trong khung co theo nội dung.
     * Ép khung rộng 100% và giữ iframe đủ cao cho nội dung hiện tại lẫn modal đang mở.
     */
    function fitFrame() {
        var fe = frameEl();
        if (!fe) return;
        if (!fitFrame.widened) {
            fitFrame.widened = true;
            root.classList.add('in-frame');
            for (var el = root; el && el !== document.documentElement; el = el.parentElement) {
                var cs = getComputedStyle(el);
                if (cs.display === 'inline' || cs.display === 'inline-block' || cs.display === 'inline-flex') el.style.display = 'block';
                el.style.width = '100%';
                el.style.maxWidth = 'none';
                el.style.minWidth = '0';
                el.style.boxSizing = 'border-box';
                el.style.alignSelf = 'stretch';
                if (el !== document.body) {
                    el.style.marginLeft = '0';
                    el.style.marginRight = '0';
                    el.style.flex = '1 1 auto';
                }
            }
        }
        var content = root.getBoundingClientRect().bottom + (window.scrollY || 0) + 24;
        var need = Math.ceil(Math.max(content, modalNeed, 640));
        if (fe.style.minHeight !== need + 'px') fe.style.minHeight = need + 'px';
    }

    /**
     * Đặt modal vào phần màn hình đang nhìn thấy. Nếu phần iframe nhìn thấy quá thấp so với modal,
     * cuộn trang DA để iframe lên sát đầu màn hình rồi mới đặt, tránh phải cuộn bên trong modal.
     */
    function placeModal(bg) {
        var modalEl = bg.querySelector('.modal');
        var head = modalEl.querySelector('.modal-head');
        var body = modalEl.querySelector('.modal-body');
        var foot = modalEl.querySelector('.modal-foot');
        var natural = function () { return (head ? head.offsetHeight : 0) + body.scrollHeight + (foot ? foot.offsetHeight : 0) + 2; };
        var place = function () {
            if (!bg.isConnected) return;
            var nat = natural();
            var area = visibleArea();
            var fe = frameEl();
            if (fe && area.height < nat + 80 && area.top + 8 < fe.getBoundingClientRect().height) {
                modalNeed = Math.max(modalNeed, nat + 220);
                fitFrame();
                try {
                    var r = fe.getBoundingClientRect();
                    if (r.top > 8) window.parent.scrollTo(0, (window.parent.scrollY || 0) + r.top - 8);
                } catch (e) { /* bỏ qua */ }
                area = visibleArea();
            }
            var maxH = Math.max(320, area.height - 72);
            bg.style.paddingTop = (area.top + Math.min(40, area.height * 0.05)) + 'px';
            modalEl.style.maxHeight = maxH + 'px';
            modalNeed = Math.max(modalNeed, area.top + Math.min(nat, maxH) + 120);
            fitFrame();
        };
        place();
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(function () { place(); });
            Array.prototype.forEach.call(body.children, function (c) { ro.observe(c); });
            ro.observe(body);
        }
        if (window.MutationObserver) new MutationObserver(place).observe(body, { childList: true, subtree: true });
    }

    var modalStack = [];
    function openModal(opts) {
        var bg = document.createElement('div');
        bg.className = 'modal-bg';
        bg.innerHTML = '<div class="modal ' + (opts.size || '') + '" role="dialog" aria-modal="true">' +
            '<div class="modal-head"><div class="grow"><div class="modal-title">' + esc(opts.title) + '</div>' +
            (opts.sub ? '<div class="modal-sub">' + opts.sub + '</div>' : '') + '</div>' +
            '<button class="btn btn-ghost btn-icon" data-close aria-label="' + esc(t('Đóng')) + '">' + ic('x') + '</button></div>' +
            '<div class="modal-body">' + (opts.body || '') + '</div>' +
            (opts.foot ? '<div class="modal-foot">' + opts.foot + '</div>' : '') + '</div>';
        modalHost.appendChild(bg);
        bg.querySelectorAll('input:not([autocomplete])').forEach(function (i) { i.setAttribute('autocomplete', 'off'); });
        placeModal(bg);
        var m = { el: bg, body: bg.querySelector('.modal-body'), close: function () { bg.remove(); modalStack = modalStack.filter(function (x) { return x !== m; }); if (!modalStack.length) { modalNeed = 0; fitFrame(); } if (opts.onClose) opts.onClose(); } };
        bg.addEventListener('mousedown', function (e) { if (e.target === bg) m.close(); });
        bg.querySelector('[data-close]').onclick = m.close;
        modalStack.push(m);
        var first = bg.querySelector('.modal-body input:not([type=checkbox]):not([type=radio]), .modal-body select, .modal-body textarea');
        if (first) setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }, 30);
        return m;
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalStack.length) modalStack[modalStack.length - 1].close();
    });

    /** Hộp xác nhận. opts: title, body(html), ok, danger, typeToConfirm */
    function confirmBox(opts) {
        return new Promise(function (resolve) {
            var done = false;
            var body = opts.body || '';
            if (opts.typeToConfirm) {
                body += '<div class="field" style="margin-top:14px"><label>' + t('Nhập {name} để xác nhận', { name: '<b>' + esc(opts.typeToConfirm) + '</b>' }) + '</label><input class="input" data-confirm autocomplete="off" spellcheck="false"></div>';
            }
            var m = openModal({
                title: opts.title, body: body,
                foot: '<button class="btn" data-no>' + esc(t('Hủy')) + '</button><button class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" data-yes>' + esc(opts.ok || t('Đồng ý')) + '</button>',
                onClose: function () { if (!done) resolve(null); }
            });
            var yes = m.el.querySelector('[data-yes]');
            var input = m.el.querySelector('[data-confirm]');
            if (input) {
                yes.disabled = true;
                input.addEventListener('input', function () { yes.disabled = input.value.trim() !== opts.typeToConfirm; });
                input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !yes.disabled) yes.click(); });
            }
            m.el.querySelector('[data-no]').onclick = m.close;
            yes.onclick = function () { done = true; m.close(); resolve(input ? input.value.trim() : true); };
            if (!input) setTimeout(function () { yes.focus(); }, 30);
        });
    }

    /* ============================== Giao diện tổng ============================== */

    function tabsFor() {
        var running = S ? S.stats.running : 0;
        if (ROLE === 'admin') return [
            ['overview', t('Tổng quan'), 'home'], ['accounts', t('Tài khoản'), 'users'], ['backups', t('Bản sao lưu'), 'archive'],
            ['jobs', t('Lịch tự động'), 'clock'], ['destinations', t('Kho lưu trữ'), 'server'], ['tasks', t('Tác vụ'), 'activity', running], ['settings', t('Cài đặt'), 'sliders']
        ];
        if (ROLE === 'reseller') return [
            ['accounts', t('Tài khoản'), 'users'], ['backups', t('Bản sao lưu'), 'archive'], ['tasks', t('Tác vụ'), 'activity', running], ['policy', t('Quyền của khách'), 'shield']
        ];
        return [['restore', t('Khôi phục'), 'restore'], ['snapshots', t('Snapshot'), 'camera'], ['tasks', t('Tác vụ & tải về'), 'activity', running]];
    }

    function render() {
        var tabs = tabsFor();
        if (!tabs.some(function (x) { return x[0] === ui.tab; })) ui.tab = tabs[0][0];
        var subtitle = ROLE === 'admin' ? esc(t('Sao lưu & khôi phục toàn máy chủ'))
            : ROLE === 'reseller' ? esc(t('Sao lưu khách hàng của {user}', { user: S ? S.me.user : '' }))
            : esc(t('Sao lưu & khôi phục tài khoản {user}', { user: S ? S.me.user : '' }));
        var chips = '';
        if (S) {
            if (S.stats.running) chips += '<button class="btn btn-sm" data-act="tab" data-tab="tasks"><span class="badge info run"><span class="dot"></span>' + esc(t('{n} đang chạy', { n: S.stats.running })) + '</span></button>';
            if (ROLE === 'admin') chips += S.engine.rclone ? '<span class="badge ok">' + ic('cloud') + 'Rclone ' + esc(S.engine.rclone_version) + '</span>' : '<span class="badge warn">' + ic('cloud') + esc(t('Chưa cài rclone')) + '</span>';
        }
        chips += '<div class="seg seg-sm" role="group" aria-label="Language"><button class="' + (LANG === 'vi' ? 'active' : '') + '" data-act="lang" data-lang="vi">VI</button><button class="' + (LANG === 'en' ? 'active' : '') + '" data-act="lang" data-lang="en">EN</button></div>';
        chips += '<button class="btn btn-sm btn-icon" data-act="refresh" title="' + esc(t('Làm mới')) + '" aria-label="' + esc(t('Làm mới')) + '">' + ic('refresh') + '</button>';

        var html = '<header class="wnb-head"><div class="wnb-logo"><svg viewBox="0 0 24 24"><use href="#wnb-i-logo"></use></svg></div>' +
            '<div><h1 class="wnb-title">WebNowBackup</h1><div class="wnb-sub">' + subtitle + '</div></div>' +
            '<div class="wnb-head-right">' + chips + '</div></header>';

        if (engineError) html += engineBanner();
        if (S && S.preview) html += '<div class="banner info">' + ic('info', 'i-lg') + '<div><strong>' + esc(t('Chế độ xem thử')) + '</strong>' + esc(t('Đang chạy ngoài DirectAdmin với dữ liệu demo, không có thao tác nào chạm vào máy chủ thật.')) + '</div></div>';

        if (S) {
            html += '<nav class="wnb-tabs" role="tablist">' + tabs.map(function (x) {
                var count = x[3] ? '<span class="count">' + x[3] + '</span>' : '';
                return '<button class="wnb-tab' + (ui.tab === x[0] ? ' active' : '') + '" role="tab" aria-selected="' + (ui.tab === x[0]) + '" data-act="tab" data-tab="' + x[0] + '">' + ic(x[2]) + esc(x[1]) + count + '</button>';
            }).join('') + '</nav><main>' + view() + '</main>';
        }
        appEl.innerHTML = html;
        afterRender();
        fitFrame();
    }

    function engineBanner() {
        var title = engineError.code === 'engine_missing' ? t('Engine sao lưu chưa được kích hoạt bằng quyền root.') : (engineError.error || t('Engine chưa sẵn sàng'));
        return '<div class="banner bad">' + ic('alert', 'i-lg') + '<div class="grow"><strong>' + esc(title) + '</strong>' +
            (engineError.install ? esc(t('Đăng nhập SSH bằng root và chạy lệnh sau để biên dịch helper, tạo cron và thư mục lưu trữ:')) +
                '<div class="cmd"><code>' + esc(engineError.install) + '</code><button class="btn btn-sm" data-act="copy" data-text="' + esc(engineError.install) + '">' + ic('copy') + esc(t('Chép')) + '</button></div>' : '') +
            '</div></div>';
    }

    function view() {
        switch (ui.tab) {
            case 'overview': return viewOverview();
            case 'accounts': return viewAccounts();
            case 'backups': return viewBackups();
            case 'jobs': return viewJobs();
            case 'destinations': return viewDestinations();
            case 'tasks': return viewTasks();
            case 'settings': return viewSettings();
            case 'policy': return viewPolicy();
            case 'restore': return viewUserRestore();
            case 'snapshots': return viewSnapshots();
        }
        return '';
    }

    function afterRender() {
        if (ui.tab === 'restore' && R.username) mountRestore();
        var sf = ui.tab === 'settings' && appEl.querySelector('form[data-form="settings"]');
        if (sf) bindPickers(sf, function () { markCovered(sf, 'exclude_accounts', resellerExcludeMap(pickerValues(sf, 'exclude_resellers'))); });
    }

    /* ============================== Thành phần chung ============================== */

    function statCard(icon, tone, label, value, note) {
        return '<div class="card stat"><div class="stat-icon ' + (tone || '') + '">' + ic(icon, 'i-lg') + '</div><div class="grow">' +
            '<div class="stat-label">' + esc(label) + '</div><div class="stat-value">' + value + '</div>' + (note ? '<div class="stat-note">' + note + '</div>' : '') + '</div></div>';
    }
    function empty(icon, title, text, action) {
        return '<div class="empty">' + ic(icon, 'i-lg') + '<strong>' + esc(title) + '</strong>' + (text ? '<div>' + esc(text) + '</div>' : '') + (action || '') + '</div>';
    }
    /** Nhãn cho biết bản này được giữ vì là bản của ngày/tuần/tháng nào (theo lịch). */
    function keepBadges(b) {
        var complete = b.keep && b.keep.length === 1 && b.keep[0] === 'complete'
            ? ' <span class="badge ok" title="' + esc(t('Giữ lại vì các bản mới hơn bị thiếu phần (database lỗi hoặc không có home)')) + '">' + esc(t('Bản đầy đủ gần nhất')) + '</span>' : '';
        if (complete) return complete;
        if (!b.job_id || !b.keep) return '';
        var names = { daily: t('Ngày'), weekly: t('Tuần'), monthly: t('Tháng') };
        // Bản chỉ được giữ vì còn mới: báo cho biết sẽ tự xóa khi quá hạn
        if (b.keep.length === 1 && b.keep[0] === 'recent') return ' <span class="badge" title="' + esc(t('Giữ vì nằm trong khoảng giờ gần nhất của lịch, sau đó tự xóa nếu không thuộc quy tắc ngày/tuần/tháng')) + '">' + esc(t('Gần đây')) + '</span>';
        return b.keep.filter(function (k) { return names[k]; }).map(function (k) { return ' <span class="badge info" title="' + esc(t('Được giữ theo chính sách của lịch')) + '">' + esc(names[k]) + '</span>'; }).join('');
    }
    /** "7 ngày · 4 tuần · 3 tháng" */
    function policyText(p) {
        if (!p) return '';
        var parts = [];
        if (p.hours) parts.push(t('mọi bản {n} giờ qua', { n: p.hours }));
        if (p.last) parts.push(t('{n} bản mới nhất', { n: p.last }));
        if (p.daily) parts.push(t('{n} ngày', { n: p.daily }));
        if (p.weekly) parts.push(t('{n} tuần', { n: p.weekly }));
        if (p.monthly) parts.push(t('{n} tháng', { n: p.monthly }));
        return parts.join(' · ');
    }
    /** Bản thiếu phần: home không sao lưu được hoặc có database dump lỗi. */
    function missingBadge(b) {
        var m = b.missing || {};
        var lines = [];
        if (m.home) lines.push(t('Không có thư mục home'));
        if (m.databases && m.databases.length) {
            var named = m.databases.filter(function (x) { return x !== '*'; });
            lines.push(named.length ? t('Thiếu database: {list}', { list: named.join(', ') }) : t('Thiếu database'));
        }
        return lines.length ? ' <span class="badge bad" title="' + esc(lines.join('\n')) + '">' + esc(t('Thiếu phần')) + '</span>' : '';
    }
    /** Bản mồ côi sẽ tự xóa (Cài đặt "Tự xóa bản sao lưu mồ côi"). */
    function expiresBadge(at, why) {
        if (!at) return '';
        var d = parseDate(at);
        var title = { deleted: t('Tài khoản đã bị xóa khỏi DirectAdmin'), previous: t('Bản của tài khoản cũ cùng tên'), job: t('Lịch đã bị xóa hoặc không còn sao lưu tài khoản này') }[why] || '';
        return ' <span class="badge warn" title="' + esc(title + (title ? '. ' : '') + t('Đổi thời hạn trong Cài đặt → Tự xóa bản sao lưu mồ côi.')) + '">' +
            esc(d && d.getTime() <= serverNow() ? t('Sắp tự xóa') : t('Tự xóa {when}', { when: fmtRel(at) })) + '</span>';
    }
    /**
     * Bản cũ nhất còn giữ lùi về bao nhiêu ngày: [ít nhất, nhiều nhất] (tháng tính 30 ngày).
     * Ngày/tuần/tháng tính cả kỳ hiện tại (giống restic), nên "3 tháng" chỉ lùi về khoảng 31–60 ngày với lịch hằng ngày.
     */
    function keepSpan(p, type, hours) {
        var every = type === 'weekly' ? 7 : type === 'hourly' ? Math.max(1, +hours || 1) / 24 : 1;
        var lo = 0, hi = 0;
        var add = function (a, b) { lo = Math.max(lo, a); hi = Math.max(hi, b); };
        if (p.hours) add(p.hours / 24, p.hours / 24);
        if (p.last > 1) add((p.last - 1) * every, (p.last - 1) * every);
        [[p.daily, 1], [p.weekly, 7], [p.monthly, 30]].forEach(function (r) {
            var k = r[0], len = Math.max(r[1], every);
            if (k > 1) {
                if (r[1] <= every) add((k - 1) * len, (k - 1) * len);
                else add((k - 2) * len + every, (k - 1) * len);
            }
        });
        return [Math.round(lo), Math.round(hi)];
    }
    /** "lùi ~31–60 ngày" cho bảng lịch. */
    function keepSpanShort(j) {
        var s = keepSpan(j.policy || {}, j.schedule_type, j.interval_hours);
        return s[0] === s[1] ? t('lùi ~{n} ngày', { n: s[1] }) : t('lùi ~{lo}–{hi} ngày', { lo: s[0], hi: s[1] });
    }
    function keepSpanText(p, type, hours) {
        var s = keepSpan(p, type, hours);
        if (s[1] < 1) return t('Bản cũ nhất còn giữ: chưa tới 1 ngày trước.');
        return s[0] === s[1] ? t('Bản cũ nhất còn giữ lùi về khoảng {n} ngày trước.', { n: s[1] })
            : t('Bản cũ nhất còn giữ lùi về khoảng {lo}–{hi} ngày trước, tùy hôm nay là ngày nào trong tuần/tháng (tuần và tháng hiện tại cũng tính là một kỳ).', { lo: s[0], hi: s[1] });
    }
    function typeBadge(b) {
        if (b.type === 'snapshot') return '<span class="badge info">' + esc(t('Snapshot')) + '</span>';
        return b.job_id ? '<span class="badge">' + esc(t('Theo lịch')) + '</span>' : '<span class="badge">' + esc(t('Thủ công')) + '</span>';
    }
    function userTypeBadge(x) {
        return { admin: '<span class="badge info">Admin</span>', reseller: '<span class="badge warn">Reseller</span>' }[x] || '<span class="badge">User</span>';
    }
    function taskLabel(x) {
        var scope = { full: t('toàn bộ'), files: t('file'), databases: t('database'), emails: t('email'), dns: 'DNS', cron: 'cron' };
        switch (x.type) {
            case 'backup': return t('Sao lưu');
            case 'snapshot': return t('Tạo snapshot');
            case 'restore': return t('Khôi phục {scope}', { scope: scope[x.scope] || '' });
            case 'download': return t('Đóng gói tải về');
            case 'delete': return t('Xóa bản sao lưu');
            case 'maintain': return t('Bảo trì kho');
            case 'rescan': return t('Quét lại kho');
            case 'cleanup': return t('Dọn bản mồ côi');
            case 'move': return t('Chuyển kho');
        }
        return x.type;
    }
    function taskIcon(x) {
        return { backup: 'archive', snapshot: 'camera', restore: 'restore', download: 'download', delete: 'trash', maintain: 'shield', rescan: 'refresh', cleanup: 'trash', move: 'move' }[x.type] || 'activity';
    }
    function statusBadge(x) {
        if (x.status === 'running' && x.cancel_by) return '<span class="badge warn run"><span class="dot"></span>' + esc(t('Đang dừng…')) + '</span>';
        if (x.status === 'cancelled') return '<span class="badge">' + esc(t('Đã dừng')) + '</span>';
        if (x.status === 'running') return '<span class="badge info run"><span class="dot"></span>' + esc(t('Đang chạy')) + '</span>';
        if (x.status === 'pending' && x.run_after) return '<span class="badge warn">' + ic('refresh') + esc(t('Thử lại lúc {time}', { time: fmtDate(x.run_after) })) + '</span>';
        if (x.status === 'pending') return '<span class="badge"><span class="dot"></span>' + esc(t('Đang chờ')) + '</span>';
        if (x.status === 'failed' && x.retried_by) return '<span class="badge bad">' + esc(t('Lỗi · đã xếp thử lại')) + '</span>';
        if (x.status === 'failed') return '<span class="badge bad">' + esc(t('Lỗi')) + '</span>';
        if (x.warnings) return '<span class="badge warn">' + esc(t('Hoàn tất · {n} cảnh báo', { n: x.warnings })) + '</span>';
        return '<span class="badge ok">' + esc(t('Hoàn tất')) + '</span>';
    }
    function isActive(x) { return x.status === 'pending' || x.status === 'running'; }
    /** Nút Dừng: admin dừng mọi tác vụ; reseller/user chỉ tác vụ chính họ tạo. Tác vụ xóa đang chạy không dừng giữa chừng. */
    function canCancel(x) {
        if (!isActive(x) || x.cancel_by) return false;
        if (x.status === 'running' && (x.type === 'delete' || x.type === 'cleanup' || x.no_cancel)) return false;
        return ROLE === 'admin' || x.initiated_by === S.me.user;
    }
    function scheduleText(j) {
        if (j.schedule_type === 'hourly') return esc(t('Mỗi {n} giờ', { n: j.interval_hours }));
        if (j.schedule_type === 'weekly') return esc(t('{day} hằng tuần, {time}', { day: weekdays()[j.schedule_weekday], time: j.schedule_time }));
        return esc(t('Hằng ngày lúc {time}', { time: j.schedule_time }));
    }
    function targetText(j) {
        if (j.filter_mode === 'include') {
            var ir = (j.include_resellers || []).length, iu = (j.include_users || []).length;
            if (ir && iu) return t('Chỉ {r} reseller và {n} tài khoản', { r: ir, n: iu });
            return ir ? t('Chỉ {n} reseller (kèm khách)', { n: ir }) : t('Chỉ {n} tài khoản được chọn', { n: iu });
        }
        var r = (j.exclude_resellers || []).length, u = (j.exclude_users || []).length;
        if (r && u) return t('Tất cả, trừ {r} reseller và {n} tài khoản', { r: r, n: u });
        if (r) return t('Tất cả, trừ {n} reseller (kèm khách)', { n: r });
        return u ? t('Tất cả, trừ {n} tài khoản', { n: u }) : t('Tất cả tài khoản');
    }
    function destName(id) { var d = byId(id, S.destinations); return d ? nameOf(d.name) : t('(đã xóa)'); }
    /** Tài khoản của tác vụ, hoặc kho với tác vụ hệ thống (chuyển kho: kho cũ → kho mới). */
    function taskTarget(x) { return x.username || (x.type === 'move' ? destName(x.destination_id) + ' → ' + destName(x.target_id) : destName(x.destination_id)); }
    function backupsOf(u) { return S.backups.filter(function (b) { return b.username === u; }); }
    function switchHTML(attrs, checked, label) {
        return '<label class="switch"><input type="checkbox" ' + attrs + (checked ? ' checked' : '') + '><span class="track"></span>' + (label ? '<span class="switch-label">' + label + '</span>' : '') + '</label>';
    }

    /**
     * Ô chọn nhiều tài khoản có tìm kiếm. items: [{value, label, sub}]
     * Giá trị đọc bằng pickerValues(form, name).
     */
    function pickerHTML(name, items, selected, emptyText, countKey, searchText) {
        var sel = {};
        (selected || []).forEach(function (v) { sel[v] = true; });
        return '<div class="picker" data-picker="' + esc(name) + '">' +
            '<div class="picker-head"><div class="input-search grow">' + ic('search') + '<input class="input" type="search" data-picker-search placeholder="' + esc(searchText || t('Tìm tài khoản')) + '"></div>' +
            '<button type="button" class="btn btn-sm btn-ghost" data-picker-all>' + esc(t('Chọn tất cả')) + '</button>' +
            '<button type="button" class="btn btn-sm btn-ghost" data-picker-none>' + esc(t('Bỏ chọn')) + '</button></div>' +
            '<div class="picker-list">' + (items.length ? items.map(function (it) {
                return '<label class="pick" data-value="' + esc(it.value) + '" data-search="' + esc((it.value + ' ' + (it.sub || '')).toLowerCase()) + '"><input type="checkbox" class="cb" value="' + esc(it.value) + '"' + (sel[it.value] ? ' checked' : '') + '>' +
                    '<span class="grow"><b>' + esc(it.label) + '</b>' + (it.sub ? ' <span class="muted small">' + esc(it.sub) + '</span>' : '') + '</span></label>';
            }).join('') : '<div class="muted small" style="padding:12px">' + esc(emptyText || t('Không có tài khoản.')) + '</div>') + '</div>' +
            '<div class="picker-foot muted small" data-picker-count data-count-key="' + esc(countKey || 'Đã chọn {n}') + '"></div></div>';
    }
    function pickerValues(scope, name) {
        var box = scope.querySelector('[data-picker="' + name + '"]');
        if (!box) return [];
        return Array.prototype.map.call(box.querySelectorAll('.picker-list input:checked'), function (i) { return i.value; });
    }
    function bindPickers(scope, onChange) {
        scope.querySelectorAll('[data-picker]').forEach(function (box) {
            var count = box.querySelector('[data-picker-count]');
            var update = function () {
                var n = box.querySelectorAll('.picker-list input:checked').length;
                count.textContent = t(count.getAttribute('data-count-key'), { n: n });
                if (onChange) onChange();
            };
            box.querySelector('[data-picker-search]').addEventListener('input', function (e) {
                var q = e.target.value.trim().toLowerCase();
                box.querySelectorAll('.pick').forEach(function (p) { p.hidden = q !== '' && p.getAttribute('data-search').indexOf(q) === -1; });
            });
            box.querySelector('[data-picker-all]').onclick = function () { box.querySelectorAll('.pick:not([hidden]) input').forEach(function (i) { i.checked = true; }); update(); };
            box.querySelector('[data-picker-none]').onclick = function () { box.querySelectorAll('.pick input').forEach(function (i) { i.checked = false; }); update(); };
            box.addEventListener('change', update);
            update();
        });
    }
    function accountItems() {
        return S.accounts.map(function (a) { return { value: a.username, label: a.username, sub: a.domain + (a.suspended ? ' · ' + t('Tạm khóa') : '') }; });
    }
    /** Khách (usertype user) do reseller/admin r tạo. */
    function clientsOf(r) {
        return S.accounts.filter(function (a) { return a.usertype === 'user' && a.creator === r; });
    }
    /** Reseller và admin (đại diện cho khách do Admin tạo) dùng cho bộ lọc theo reseller. */
    function resellerItems() {
        return S.accounts.filter(function (a) { return a.usertype === 'reseller' || a.usertype === 'admin'; }).map(function (a) {
            var n = clientsOf(a.username).length;
            return { value: a.username, label: a.username, sub: (a.usertype === 'admin' ? t('Admin và khách do Admin tạo') : t('Reseller')) + ' · ' + t('{n} khách', { n: n }) };
        });
    }
    /** Tài khoản bị loại trừ khi loại trừ các reseller trong list (gồm chính reseller). {username: reseller} */
    function resellerExcludeMap(list) {
        var out = {};
        (list || []).forEach(function (r) {
            if (!S.accounts.some(function (a) { return a.username === r; })) return;
            out[r] = r;
            clientsOf(r).forEach(function (a) { if (!out[a.username]) out[a.username] = r; });
        });
        return out;
    }
    /** Tài khoản bị bỏ qua ở mọi lịch (Cài đặt): theo reseller + theo tài khoản. */
    function globalExcluded() {
        var ex = resellerExcludeMap(S.settings && S.settings.exclude_resellers);
        ((S.settings && S.settings.exclude_accounts) || []).forEach(function (u) { ex[u] = ex[u] || true; });
        return ex;
    }
    /** Tài khoản không lịch nào đang bật sao lưu (chỉ tính khi có ít nhất một lịch bật). {username: true} */
    function unscheduled() {
        var out = {};
        if (!S.scheduled || !(S.jobs || []).some(function (j) { return j.enabled; })) return out;
        var on = {};
        S.scheduled.forEach(function (u) { on[u] = true; });
        S.accounts.forEach(function (a) { if (!on[a.username]) out[a.username] = true; });
        return out;
    }
    /** Trong ô chọn tài khoản: làm mờ tài khoản đã bị loại trừ theo reseller. covered: {username: reseller} */
    function markCovered(form, name, covered) {
        var box = form.querySelector('[data-picker="' + name + '"]');
        if (!box) return;
        box.querySelectorAll('.pick').forEach(function (p) {
            var r = covered[p.getAttribute('data-value')];
            p.querySelector('input').disabled = !!r;
            p.classList.toggle('covered', !!r);
            var note = p.querySelector('.pick-note');
            if (r && !note) { note = document.createElement('span'); note.className = 'pick-note badge'; p.appendChild(note); }
            if (note) { if (r) note.textContent = t('Thuộc {r}', { r: r }); else note.parentNode.removeChild(note); }
        });
    }

    /* ============================== Tổng quan (admin) ============================== */

    function viewOverview() {
        var st = S.stats;
        var cutoff = serverNow() - 48 * 3600 * 1000;
        var excluded = globalExcluded(), noJob = unscheduled();
        var stale = S.accounts.filter(function (a) { var d = parseDate(a.last_backup_at); return !excluded[a.username] && !noJob[a.username] && (!d || d.getTime() < cutoff); });
        var html = healthBanner() + '<div class="grid grid-4" style="margin-bottom:16px">' +
            statCard('users', '', t('Tài khoản'), st.accounts, esc(stale.length ? t('{n} chưa sao lưu trong 48 giờ', { n: stale.length }) : t('Tất cả đã được sao lưu'))) +
            statCard('archive', 'ok', t('Bản sao lưu'), st.backups, esc(t('{size} trên các kho', { size: fmtMb(st.size_mb) }))) +
            statCard('activity', st.running ? '' : 'ok', t('Đang chạy'), st.running, esc(st.running ? t('Tác vụ trong hàng đợi') : t('Không có tác vụ'))) +
            statCard('alert', st.failed_24h ? 'bad' : 'ok', t('Lỗi 24 giờ qua'), st.failed_24h, st.failed_24h ? '<a href="#" data-act="tab" data-tab="tasks" data-filter="failed">' + esc(t('Xem chi tiết')) + '</a>' : esc(t('Không có lỗi'))) +
            '</div>';

        var recent = S.queue.slice(0, 8);
        html += '<div class="grid grid-main"><div class="card"><div class="card-head"><div class="card-title">' + esc(t('Hoạt động gần đây')) + '</div>' +
            '<button class="btn btn-sm btn-ghost right" data-act="tab" data-tab="tasks">' + esc(t('Xem tất cả')) + ic('chevron-right') + '</button></div>' +
            (recent.length ? '<div class="list">' + recent.map(taskListItem).join('') + '</div>' : empty('activity', t('Chưa có hoạt động'), t('Các lượt sao lưu, khôi phục sẽ hiển thị ở đây.'))) + '</div>';

        var enabledJobs = (S.jobs || []).filter(function (j) { return j.enabled; });
        var next = enabledJobs.map(function (j) { return j.next_run; }).filter(Boolean).sort()[0];
        var local = byId('dest_local', S.destinations);
        var notifyOk = S.settings.telegram_configured || S.settings.notify_email;
        html += '<div class="stack"><div class="card"><div class="card-head"><div class="card-title">' + esc(t('Tình trạng')) + '</div></div>' +
            healthRow(true, t('Engine sao lưu'), esc(t('Hoạt động với quyền root'))) +
            healthRow(S.engine.rclone, 'Rclone', esc(S.engine.rclone ? S.engine.rclone_version : t('Chưa cài, chỉ dùng được kho local'))) +
            healthRow(enabledJobs.length > 0, t('Lịch tự động'), esc(enabledJobs.length ? t('Lần tới {when} ({date})', { when: fmtNext(next), date: fmtDate(next, false) }) : t('Chưa bật lịch nào')), enabledJobs.length ? '' : '<button class="btn btn-sm" data-act="tab" data-tab="jobs">' + esc(t('Thiết lập')) + '</button>') +
            healthRow(!local || local.free_gb === null || local.free_gb === undefined || local.free_gb > Math.max(20, +S.settings.min_free_gb || 0), t('Kho local'), esc(local ? local.path + (local.free_gb !== null && local.free_gb !== undefined ? ' · ' + t('trống {n} GB', { n: local.free_gb }) : '') : '—')) +
            healthRow(!!notifyOk, t('Cảnh báo'), esc(notifyOk ? [S.settings.telegram_configured ? 'Telegram' : '', S.settings.notify_email ? 'Email' : ''].filter(Boolean).join(' + ') : t('Chưa cấu hình email hay Telegram')), notifyOk ? '' : '<button class="btn btn-sm" data-act="tab" data-tab="settings">' + esc(t('Thiết lập')) + '</button>') +
            '</div>';
        html += '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Chưa sao lưu trong 48 giờ')) + '</div></div>' +
            (stale.length ? '<div class="list">' + stale.slice(0, 6).map(function (a) {
                return '<div class="list-item"><div class="grow"><div class="cell-main">' + esc(a.username) + '</div><div class="cell-sub">' + esc(a.last_backup_at ? t('Lần cuối {when}', { when: fmtRel(a.last_backup_at) }) : t('Chưa từng sao lưu')) + '</div></div>' +
                    '<button class="btn btn-sm" data-act="backup-now" data-user="' + esc(a.username) + '">' + ic('archive') + esc(t('Sao lưu')) + '</button></div>';
            }).join('') + (stale.length > 6 ? '<div class="list-item muted small">' + esc(t('và {n} tài khoản khác', { n: stale.length - 6 })) + '</div>' : '') + '</div>'
                : '<div class="card-body muted">' + ic('check') + ' ' + esc(t('Mọi tài khoản đều có bản sao lưu gần đây.')) + '</div>') +
            '</div></div></div>';
        return html;
    }
    /** Nguy cơ mất dữ liệu do máy chủ phát hiện (ổ đầy, tài khoản quá hạn, lịch không chạy...). */
    function healthBanner() {
        var list = S.health || [];
        if (!list.length) return '';
        var notify = S.settings.telegram_configured || S.settings.notify_email;
        return '<div class="banner bad">' + ic('alert', 'i-lg') + '<div class="grow"><strong>' + esc(t('Có nguy cơ mất dữ liệu: {n} vấn đề cần xử lý', { n: list.length })) + '</strong>' +
            '<ul style="margin:6px 0 0;padding-left:18px">' + list.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' +
            (notify ? '' : '<div class="small" style="margin-top:6px">' + esc(t('Chưa cấu hình Telegram hay email nên máy chủ không báo được cho bạn. Hãy thiết lập trong Cài đặt.')) + '</div>') + '</div></div>';
    }
    function healthRow(ok, title, sub, action) {
        return '<div class="health"><span class="badge ' + (ok ? 'ok' : 'warn') + '" style="width:22px;padding:0;justify-content:center">' + ic(ok ? 'check' : 'alert') + '</span>' +
            '<div class="grow"><div class="cell-main">' + esc(title) + '</div><div class="cell-sub">' + sub + '</div></div>' + (action || '') + '</div>';
    }
    function taskListItem(x) {
        var tone = x.status === 'failed' ? 'bad' : (x.status === 'completed' ? 'ok' : (x.status === 'cancelled' ? '' : 'run'));
        return '<div class="list-item"><div class="list-icon ' + tone + '">' + ic(taskIcon(x)) + '</div>' +
            '<div class="grow"><div class="cell-main">' + esc(taskLabel(x)) + ' · ' + esc(x.username || destName(x.destination_id)) + '</div><div class="cell-sub ellipsis">' + esc(x.message) + '</div></div>' +
            '<div style="text-align:right"><div>' + statusBadge(x) + '</div><div class="cell-sub">' + esc(fmtRel(x.created_at)) + '</div></div></div>';
    }

    /* ============================== Tài khoản ============================== */

    function viewAccounts() {
        var q = ui.search.trim().toLowerCase();
        var list = S.accounts.filter(function (a) { return !q || a.username.toLowerCase().indexOf(q) !== -1 || (a.domain || '').toLowerCase().indexOf(q) !== -1; });
        var excluded = ROLE === 'admin' ? globalExcluded() : {};
        var noJob = ROLE === 'admin' ? unscheduled() : {};
        var destSel = '';
        if (perm('can_backup') && S.destinations.length > 1) {
            destSel = '<label class="row small muted">' + esc(t('Lưu vào')) + ' <select class="select" style="width:auto" data-bind="backupDest">' + S.destinations.map(function (d) {
                return '<option value="' + esc(d.id) + '"' + (ui.backupDest === d.id ? ' selected' : '') + '>' + esc(nameOf(d.name)) + '</option>';
            }).join('') + '</select></label>';
        }
        var rows = list.map(function (a) {
            var last = a.last_backup_at
                ? '<div>' + esc(fmtRel(a.last_backup_at)) + '</div><div class="cell-sub">' + esc(t('{n} bản', { n: a.backups_count })) + '</div>'
                : '<span class="badge warn">' + esc(t('Chưa có')) + '</span>';
            return '<tr><td><div class="cell-main">' + esc(a.username) + (a.suspended ? ' <span class="badge bad">' + esc(t('Tạm khóa')) + '</span>' : '') + (excluded[a.username] ? ' <span class="badge" title="' + esc(t('Không bao giờ tự sao lưu')) + '">' + esc(t('Loại trừ')) + '</span>' : noJob[a.username] ? ' <span class="badge" title="' + esc(t('Không lịch nào đang bật sao lưu tài khoản này')) + '">' + esc(t('Không có lịch')) + '</span>' : '') + '</div><div class="cell-sub">' + esc(a.domain || '—') + '</div></td>' +
                '<td class="hide-sm">' + userTypeBadge(a.usertype) + (ROLE === 'admin' && a.usertype === 'user' ? '<div class="cell-sub">' + esc(t('của {owner}', { owner: a.creator })) + '</div>' : '') + '</td>' +
                '<td class="num hide-sm">' + fmtMb(a.usage_mb) + '</td><td>' + last + '</td><td class="actions">' +
                (perm('can_backup') ? '<button class="btn btn-sm" data-act="backup-now" data-user="' + esc(a.username) + '">' + ic('archive') + esc(t('Sao lưu')) + '</button>' : '') +
                (perm('can_restore') && a.backups_count ? '<button class="btn btn-sm btn-primary" data-act="open-restore" data-user="' + esc(a.username) + '">' + ic('restore') + esc(t('Khôi phục')) + '</button>' : '') +
                '</td></tr>';
        }).join('');
        return '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Tài khoản')) + ' <span class="badge">' + S.accounts.length + '</span></div>' +
            '<div class="row-wrap right">' + destSel + '<div class="input-search">' + ic('search') + '<input class="input" type="search" placeholder="' + esc(t('Tìm tài khoản hoặc tên miền')) + '" value="' + esc(ui.search) + '" data-bind="search"></div></div></div>' +
            (list.length ? '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Tài khoản')) + '</th><th class="hide-sm">' + esc(t('Loại')) + '</th><th class="num hide-sm">' + esc(t('Dung lượng')) + '</th><th>' + esc(t('Sao lưu gần nhất')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                : empty('search', t('Không tìm thấy tài khoản'), q ? t('Thử từ khóa khác.') : '')) + '</div>' + deletedAccountsCard(q);
    }

    function deletedAccount(u) {
        var list = S.deleted_accounts || [];
        for (var i = 0; i < list.length; i++) if (list[i].username === u) return list[i];
        return null;
    }

    /** Admin: tài khoản đã bị xóa khỏi DirectAdmin nhưng bản sao lưu vẫn còn. */
    function deletedAccountsCard(q) {
        var list = (S.deleted_accounts || []).filter(function (a) { return !q || a.username.toLowerCase().indexOf(q) !== -1 || (a.domain || '').toLowerCase().indexOf(q) !== -1; });
        if (!list.length) return '';
        var rows = list.map(function (a) {
            return '<tr><td><div class="cell-main">' + esc(a.username) + ' <span class="badge bad">' + esc(t('Đã xóa')) + '</span></div><div class="cell-sub">' + esc(a.domain || '—') + '</div></td>' +
                '<td class="hide-sm">' + (a.reseller ? esc(a.reseller) : '—') + '</td>' +
                '<td class="num hide-sm">' + fmtMb(a.size_mb) + '</td>' +
                '<td><div>' + esc(fmtRel(a.last_backup_at)) + '</div><div class="cell-sub">' + esc(t('{n} bản', { n: a.backups_count })) + expiresBadge(a.expires_at, 'deleted') + '</div></td>' +
                '<td class="actions"><button class="btn btn-sm" data-act="deleted-backups" data-user="' + esc(a.username) + '">' + ic('archive') + esc(t('Xem bản sao lưu')) + '</button>' +
                (perm('can_restore') ? '<button class="btn btn-sm btn-primary" data-act="deleted-restore" data-user="' + esc(a.username) + '">' + ic('restore') + esc(t('Khôi phục')) + '</button>' : '') +
                '</td></tr>';
        }).join('');
        return '<div style="height:16px"></div><div class="card"><div class="card-head"><div class="card-title">' + esc(t('Tài khoản đã xóa')) + ' <span class="badge">' + list.length + '</span></div>' +
            '<span class="muted small right">' + esc(+S.settings.orphan_days > 0
                ? t('Đã bị xóa khỏi DirectAdmin. Bản sao lưu tự xóa sau {n} ngày kể từ bản cuối (Cài đặt).', { n: S.settings.orphan_days })
                : t('Đã bị xóa khỏi DirectAdmin, bản sao lưu vẫn được giữ cho tới khi bạn xóa.')) + '</span></div>' +
            '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Tài khoản')) + '</th><th class="hide-sm">' + esc(t('Chủ sở hữu')) + '</th><th class="num hide-sm">' + esc(t('Dung lượng')) + '</th><th>' + esc(t('Sao lưu gần nhất')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
    }

    /** Khôi phục tài khoản đã xóa: plugin không tự tạo tài khoản DirectAdmin, hướng dẫn tạo lại rồi khôi phục toàn bộ. */
    function deletedRestoreModal(u) {
        var a = deletedAccount(u);
        if (!a) { openRestoreModal(u); return; }
        var extra = a.domains.filter(function (d) { return d !== a.domain; });
        var chips = function (list) { return '<div class="chips">' + list.map(function (x) { return '<code>' + esc(x) + '</code>'; }).join('') + '</div>'; };
        var steps = '<li><strong>' + esc(t('Tạo lại tài khoản trong DirectAdmin')) + '</strong>' +
            esc(a.reseller && a.reseller !== 'admin' ? t('Đúng tên đăng nhập bên dưới, đặt dưới reseller {r}, tên miền chính như cũ.', { r: a.reseller }) : t('Đúng tên đăng nhập bên dưới, tên miền chính như cũ.')) +
            chips([u].concat(a.domain ? [a.domain] : [])) + '</li>';
        if (extra.length) steps += '<li><strong>' + esc(t('Thêm lại các tên miền khác')) + '</strong>' + esc(t('Email và DNS chỉ khôi phục được cho tên miền đã có trong tài khoản.')) + chips(extra) + '</li>';
        var subs = a.subdomains || [], ptrs = a.pointers || [];
        if (subs.length || ptrs.length) {
            steps += '<li><strong>' + esc(t('Tạo lại subdomain và domain pointer')) + '</strong>' + esc(t('Plugin không tạo lại các mục này. File của subdomain vẫn được khôi phục vào đúng thư mục cũ.')) +
                (subs.length ? chips(subs) : '') +
                (ptrs.length ? chips(ptrs.map(function (x) { return x.name + ' → ' + x.target + (x.alias ? '' : ' (' + t('chuyển hướng') + ')'); })) : '') + '</li>';
        }
        steps += '<li><strong>' + esc(t('Khôi phục toàn bộ')) + '</strong>' + esc(t('Bấm "Đã tạo lại, khôi phục" rồi chọn Toàn bộ: file, database, email, DNS và cron được đưa về như lúc sao lưu.')) + '</li>';
        if (a.databases.length) steps += '<li><strong>' + esc(t('Gán lại user database')) + '</strong>' + esc(t('Database được tạo lại nhưng user database thì không. Tạo user database trong DirectAdmin với mật khẩu cũ (xem trong file cấu hình website, ví dụ wp-config.php).')) + chips(a.databases) + '</li>';
        steps += '<li><strong>' + esc(t('Cấp lại SSL')) + '</strong>' + esc(t('Chứng chỉ SSL không được khôi phục. Bật lại Let\'s Encrypt (hoặc cài lại chứng chỉ) cho các tên miền và subdomain ở trên.')) + '</li>';
        var m = openModal({
            title: t('Khôi phục tài khoản đã xóa {user}', { user: u }),
            sub: esc(t('{n} bản sao lưu, gần nhất {when}', { n: a.backups_count, when: fmtDate(a.last_backup_at) })),
            body: '<div class="banner warn">' + ic('alert', 'i-lg') + '<div><strong>' + esc(t('Tài khoản {user} không còn trong DirectAdmin', { user: u })) + '</strong>' +
                esc(t('Bản sao lưu vẫn còn nguyên nhưng chỉ khôi phục được vào tài khoản đang tồn tại. WebNowBackup không tự tạo tài khoản DirectAdmin, hãy làm theo các bước sau.')) + '</div></div>' +
                '<ol class="steps">' + steps + '</ol>',
            foot: (perm('can_download') ? '<button class="btn" data-dl>' + ic('download') + esc(t('Tải về bản mới nhất')) + '</button>' : '') +
                '<span class="grow"></span><button class="btn" data-no>' + esc(t('Đóng')) + '</button><button class="btn btn-primary" data-recheck>' + ic('restore') + esc(t('Đã tạo lại, khôi phục')) + '</button>'
        });
        m.el.querySelector('[data-no]').onclick = m.close;
        var dl = m.el.querySelector('[data-dl]');
        if (dl) dl.onclick = function () { m.close(); run(null, api('download_create', { backup_id: a.last_backup_id })).then(null, function () { /* run đã báo lỗi */ }); };
        var re = m.el.querySelector('[data-recheck]');
        re.onclick = function () {
            run(re, api('state')).then(function () {
                if (S.accounts.some(function (x) { return x.username === u; })) {
                    m.close();
                    openRestoreModal(u, a.last_backup_id);
                } else {
                    toast(t('Chưa thấy tài khoản {user} trong DirectAdmin. Tạo lại tài khoản rồi thử lại.', { user: u }), true);
                }
            }, function () { /* run đã báo lỗi */ });
        };
    }

    /* ============================== Bản sao lưu ============================== */

    function viewBackups() {
        var users = S.accounts.map(function (a) { return a.username; });
        var gone = (S.deleted_accounts || []).map(function (a) { return a.username; });
        if (ui.bkUser === '*deleted' ? !gone.length : ui.bkUser && users.indexOf(ui.bkUser) === -1 && gone.indexOf(ui.bkUser) === -1) ui.bkUser = '';
        var list = S.backups.filter(function (b) {
            return (!ui.bkUser || (ui.bkUser === '*deleted' ? b.account_deleted : b.username === ui.bkUser)) && (!ui.bkType || (ui.bkType === 'snapshot' ? b.type === 'snapshot' : b.type !== 'snapshot'));
        });
        var opt = function (u) { return '<option' + (ui.bkUser === u ? ' selected' : '') + '>' + esc(u) + '</option>'; };
        var filters = '<div class="row-wrap right"><select class="select" style="width:auto" data-bind="bkUser"><option value="">' + esc(t('Tất cả tài khoản')) + '</option>' +
            (gone.length ? '<option value="*deleted"' + (ui.bkUser === '*deleted' ? ' selected' : '') + '>' + esc(t('Mọi tài khoản đã xóa ({n})', { n: gone.length })) + '</option><optgroup label="' + esc(t('Đang hoạt động')) + '">' + users.map(opt).join('') + '</optgroup><optgroup label="' + esc(t('Đã xóa')) + '">' + gone.map(opt).join('') + '</optgroup>'
                : users.map(opt).join('')) + '</select>' +
            '<select class="select" style="width:auto" data-bind="bkType"><option value="">' + esc(t('Mọi loại')) + '</option><option value="backup"' + (ui.bkType === 'backup' ? ' selected' : '') + '>' + esc(t('Sao lưu')) + '</option><option value="snapshot"' + (ui.bkType === 'snapshot' ? ' selected' : '') + '>Snapshot</option></select></div>';
        var rows = list.map(function (b) {
            var content = [];
            if (b.databases.length) content.push(ic('database') + ' ' + b.databases.length);
            if (b.emails.length) content.push(ic('mail') + ' ' + b.emails.length);
            if (b.domains.length) content.push(ic('globe') + ' ' + b.domains.length);
            return '<tr><td><div class="cell-main nowrap">' + esc(fmtDate(b.created_at)) + '</div><div class="cell-sub">' + esc(fmtRel(b.created_at)) + '</div></td>' +
                '<td><div class="cell-main">' + esc(b.username) + (b.account_deleted ? ' <span class="badge bad" title="' + esc(t('Tài khoản đã bị xóa khỏi DirectAdmin')) + '">' + esc(t('Đã xóa')) + '</span>' : '') + '</div><div class="cell-sub">' + esc(b.domain || '') + '</div></td>' +
                '<td>' + typeBadge(b) + keepBadges(b) + missingBadge(b) + expiresBadge(b.expires_at, b.expires_why) + (b.previous_owner ? ' <span class="badge warn" title="' + esc(t('Tạo trước khi tài khoản bị xóa rồi tạo lại cùng tên. Chỉ admin thấy bản này.')) + '">' + esc(t('Tài khoản cũ')) + '</span>' : '') + (b.warnings && b.warnings.length ? ' <span class="badge warn" title="' + esc(b.warnings.join('\n')) + '">' + esc(t('{n} cảnh báo', { n: b.warnings.length })) + '</span>' : '') + (b.note && b.type === 'snapshot' ? '<div class="cell-sub ellipsis" style="max-width:220px">' + esc(b.note) + '</div>' : '') + '</td>' +
                '<td class="hide-sm">' + esc(nameOf(b.destination_name)) + '</td>' +
                '<td class="num">' + fmtMb(b.size_mb) + (b.incremental ? '<div class="cell-sub">' + esc(t('mới {size}', { size: fmtMb(b.new_mb) })) + '</div>' : '') + '</td>' +
                '<td class="hide-sm muted nowrap">' + content.join('&nbsp;&nbsp;') + '</td>' +
                '<td class="actions">' +
                (perm('can_restore') ? '<button class="btn btn-sm btn-primary" data-act="' + (b.account_deleted ? 'deleted-restore' : 'open-restore') + '" data-user="' + esc(b.username) + '" data-backup="' + esc(b.id) + '">' + ic('restore') + esc(t('Khôi phục')) + '</button>' : '') +
                (perm('can_download') ? '<button class="btn btn-sm btn-ghost btn-icon" data-act="download-create" data-backup="' + esc(b.id) + '" title="' + esc(t('Đóng gói để tải về')) + '" aria-label="' + esc(t('Tải về')) + '">' + ic('download') + '</button>' : '') +
                (perm('can_backup') ? '<button class="btn btn-sm btn-ghost btn-icon danger" data-act="backup-delete" data-backup="' + esc(b.id) + '" title="' + esc(t('Xóa')) + '" aria-label="' + esc(t('Xóa')) + '">' + ic('trash') + '</button>' : '') +
                '</td></tr>';
        }).join('');
        var html = '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Bản sao lưu')) + ' <span class="badge">' + list.length + '</span></div>' + filters + '</div>' +
            (list.length ? '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Thời điểm')) + '</th><th>' + esc(t('Tài khoản')) + '</th><th>' + esc(t('Loại')) + '</th><th class="hide-sm">' + esc(t('Kho')) + '</th><th class="num">' + esc(t('Dung lượng')) + '</th><th class="hide-sm">' + esc(t('Nội dung')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                : empty('archive', t('Chưa có bản sao lưu'), ROLE === 'admin' ? t('Bật lịch tự động hoặc bấm "Sao lưu" ở mục Tài khoản.') : t('Bấm "Sao lưu" ở mục Tài khoản để tạo bản đầu tiên.'))) + '</div>';
        if (S.downloads.length) html += '<div style="height:16px"></div>' + downloadsCard();
        return html;
    }

    function downloadsCard() {
        var rows = S.downloads.map(function (d) {
            return '<tr><td><div class="cell-main mono">' + esc(d.filename) + '</div><div class="cell-sub">' + esc(d.username) + '</div></td>' +
                '<td class="num">' + fmtMb(d.size_mb) + '</td><td class="hide-sm">' + esc(fmtRel(d.created_at)) + '</td><td class="hide-sm">' + esc(t('Hết hạn {when}', { when: fmtRel(d.expires_at) })) + '</td>' +
                '<td class="actions"><a class="btn btn-sm btn-primary" href="' + esc(B.api + sep(B.api) + 'action=download_file&id=' + encodeURIComponent(d.id)) + '" target="_blank" rel="noopener">' + ic('download') + esc(t('Tải về')) + '</a>' +
                '<button class="btn btn-sm btn-ghost btn-icon danger" data-act="download-delete" data-id="' + esc(d.id) + '" aria-label="' + esc(t('Xóa')) + '">' + ic('trash') + '</button></td></tr>';
        }).join('');
        return '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('File tải về')) + ' <span class="badge">' + S.downloads.length + '</span></div><span class="muted small right">' + esc(t('Tự xóa sau {n} giờ', { n: S.settings.download_ttl_hours || 24 })) + '</span></div>' +
            '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('File')) + '</th><th class="num">' + esc(t('Dung lượng')) + '</th><th class="hide-sm">' + esc(t('Tạo')) + '</th><th class="hide-sm">' + esc(t('Thời hạn')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
    }

    /* ============================== Lịch tự động ============================== */

    function lastResultBadge(j) {
        var r = j.last_result;
        if (!r || !j.last_run) return '<span class="muted">' + esc(t('Chưa chạy')) + '</span>';
        var head = '<div>' + esc(fmtRel(j.last_run)) + '</div>';
        if (!r.finished_at) return head + '<div class="cell-sub">' + esc(t('Đang chạy {n} tài khoản', { n: r.total })) + '</div>';
        var cls = r.failed ? 'bad' : (r.warnings ? 'warn' : 'ok');
        return head + '<span class="badge ' + cls + '">' + esc(t('{done}/{total} thành công', { done: r.done, total: r.total })) + (r.failed ? ' · ' + esc(t('{n} lỗi', { n: r.failed })) : '') + (r.cancelled ? ' · ' + esc(t('{n} đã dừng', { n: r.cancelled })) : '') + '</span>';
    }

    function viewJobs() {
        var jobs = S.jobs || [];
        var rows = jobs.map(function (j) {
            var active = S.queue.filter(function (x) { return x.job_id === j.id && canCancel(x); }).length;
            return '<tr><td><div class="cell-main">' + esc(nameOf(j.name)) + '</div><div class="cell-sub">' + esc(destName(j.destination_id)) + ' · ' + esc(t('giữ {policy}', { policy: policyText(j.policy) })) + ' · ' + esc(keepSpanShort(j)) + '</div></td>' +
                '<td>' + scheduleText(j) + '</td>' +
                '<td class="hide-sm"><div>' + esc(targetText(j)) + '</div><div class="cell-sub">' + esc(t('{n} tài khoản sẽ sao lưu', { n: j.target_count })) + '</div></td>' +
                '<td class="hide-sm">' + lastResultBadge(j) + '</td>' +
                '<td>' + (j.enabled && j.next_run ? esc(fmtNext(j.next_run)) + '<div class="cell-sub">' + esc(fmtDate(j.next_run, false)) + '</div>' : '<span class="muted">—</span>') + '</td>' +
                '<td>' + switchHTML('data-act="job-toggle" data-id="' + esc(j.id) + '" aria-label="' + esc(t('Bật/tắt lịch')) + '"', j.enabled) + '</td>' +
                '<td class="actions">' + (active
                    ? '<button class="btn btn-sm btn-stop" data-act="job-stop" data-id="' + esc(j.id) + '" data-n="' + active + '">' + ic('stop') + esc(t('Dừng ({n})', { n: active })) + '</button>'
                    : '<button class="btn btn-sm" data-act="job-run" data-id="' + esc(j.id) + '">' + ic('play') + esc(t('Chạy ngay')) + '</button>') +
                '<button class="btn btn-sm btn-ghost btn-icon" data-act="job-edit" data-id="' + esc(j.id) + '" aria-label="' + esc(t('Sửa')) + '">' + ic('edit') + '</button>' +
                '<button class="btn btn-sm btn-ghost btn-icon danger" data-act="job-delete" data-id="' + esc(j.id) + '" aria-label="' + esc(t('Xóa')) + '">' + ic('trash') + '</button></td></tr>';
        }).join('');
        return '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Lịch tự động')) + '</div><button class="btn btn-primary btn-sm right" data-act="job-edit">' + ic('plus') + esc(t('Thêm lịch')) + '</button></div>' +
            (jobs.length ? '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Tên lịch')) + '</th><th>' + esc(t('Tần suất')) + '</th><th class="hide-sm">' + esc(t('Tài khoản')) + '</th><th class="hide-sm">' + esc(t('Lần chạy cuối')) + '</th><th>' + esc(t('Lần tới')) + '</th><th>' + esc(t('Bật')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                : empty('clock', t('Chưa có lịch sao lưu'), t('Tạo lịch để máy chủ tự sao lưu các tài khoản.'), '<button class="btn btn-primary" data-act="job-edit">' + ic('plus') + esc(t('Thêm lịch')) + '</button>')) +
            '<div class="card-foot" style="justify-content:flex-start"><span class="muted small">' + ic('info') + ' ' + esc(t('Mỗi lịch giữ số bản riêng, không ảnh hưởng bản sao lưu thủ công hay của lịch khác. Tài khoản và reseller trong "Không bao giờ tự sao lưu" (Cài đặt) bị bỏ qua ở mọi lịch.')) + '</span></div></div>';
    }

    function jobForm(job) {
        var j = job || { name: '', destination_id: 'dest_local', schedule_type: 'daily', schedule_time: '02:00', schedule_weekday: 0, interval_hours: 6, keep_hours: 24, keep_last: 0, keep_daily: 7, keep_weekly: 4, keep_monthly: 3, filter_mode: 'exclude', exclude_users: [], exclude_resellers: [], include_users: [], include_resellers: [], skip_suspended: false, enabled: true };
        var globalEx = S.settings.exclude_accounts || [];
        var globalRs = S.settings.exclude_resellers || [];
        var globalCovered = resellerExcludeMap(globalRs);
        var mode = j.filter_mode === 'include' ? 'include' : 'exclude';
        var rsItems = resellerItems().filter(function (it) { return globalRs.indexOf(it.value) === -1; });
        var accItems = accountItems().filter(function (it) { return globalEx.indexOf(it.value) === -1 && !globalCovered[it.value]; });
        var body = '<form class="form-grid" data-form="job" autocomplete="off">' +
            (job ? '<input type="hidden" name="id" value="' + esc(j.id) + '">' : '') +
            '<div class="field full"><label>' + esc(t('Tên lịch')) + '</label><input class="input" name="name" maxlength="60" required value="' + esc(nameOf(j.name)) + '" placeholder="' + esc(t('Ví dụ: Hằng đêm lên Cloudflare R2')) + '"></div>' +
            '<div class="field full"><label>' + esc(t('Kho lưu trữ')) + '</label><select class="select" name="destination_id">' + S.destinations.map(function (d) { return '<option value="' + esc(d.id) + '"' + (d.id === j.destination_id ? ' selected' : '') + '>' + esc(nameOf(d.name)) + '</option>'; }).join('') + '</select></div>' +
            '<div class="field full"><label>' + esc(t('Giữ bản sao lưu')) + '</label><div class="keep-grid">' +
            [['keep_hours', t('Mọi bản trong'), t('giờ gần nhất'), 168], ['keep_daily', t('Hằng ngày'), t('ngày gần nhất')], ['keep_weekly', t('Hằng tuần'), t('tuần gần nhất')], ['keep_monthly', t('Hằng tháng'), t('tháng gần nhất')], ['keep_last', t('Mới nhất'), t('bản bất kỳ')]].map(function (f) {
                var v = (j.policy && j.policy[f[0].slice(5)] !== undefined) ? j.policy[f[0].slice(5)] : j[f[0]];
                return '<label class="keep-box"><span class="keep-title">' + esc(f[1]) + '</span><input class="input" type="number" min="0" max="' + (f[3] || 365) + '" name="' + f[0] + '" value="' + esc(v || 0) + '"><span class="keep-sub">' + esc(f[2]) + '</span></label>';
            }).join('') + '</div><div class="hint" data-keep-hint></div></div>' +
            '<div class="field"><label>' + esc(t('Tần suất')) + '</label><select class="select" name="schedule_type">' +
            [['daily', t('Hằng ngày')], ['weekly', t('Hằng tuần')], ['hourly', t('Theo số giờ')]].map(function (o) { return '<option value="' + o[0] + '"' + (j.schedule_type === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></div>' +
            '<div class="field" data-show="daily weekly"><label>' + esc(t('Giờ chạy')) + '</label><div class="row"><select class="select" name="hh">' + range(0, 23).map(function (h) { return '<option' + (+j.schedule_time.split(':')[0] === h ? ' selected' : '') + '>' + pad(h) + '</option>'; }).join('') + '</select><b>:</b><select class="select" name="mm">' + [0, 5, 10, 15, 20, 30, 40, 45, 50].map(function (m) { return '<option' + (+j.schedule_time.split(':')[1] === m ? ' selected' : '') + '>' + pad(m) + '</option>'; }).join('') + '</select></div></div>' +
            '<div class="field" data-show="weekly"><label>' + esc(t('Ngày trong tuần')) + '</label><select class="select" name="schedule_weekday">' + weekdays().map(function (w, i) { return '<option value="' + i + '"' + (+j.schedule_weekday === i ? ' selected' : '') + '>' + esc(w) + '</option>'; }).join('') + '</select></div>' +
            '<div class="field" data-show="hourly"><label>' + esc(t('Chạy mỗi')) + '</label><select class="select" name="interval_hours">' + [1, 2, 3, 4, 6, 8, 12].map(function (h) { return '<option value="' + h + '"' + (+j.interval_hours === h ? ' selected' : '') + '>' + esc(t('{n} giờ', { n: h })) + '</option>'; }).join('') + '</select></div>' +
            '<div class="field full"><label>' + esc(t('Tài khoản được sao lưu')) + '</label><div class="mode-grid">' +
            [['exclude', t('Tất cả, trừ một số'), t('Sao lưu mọi tài khoản, kể cả tài khoản tạo sau này. Chỉ chọn những tài khoản KHÔNG cần sao lưu.')],
                ['include', t('Chỉ tài khoản được chọn'), t('Chỉ sao lưu tài khoản hoặc reseller bạn chọn. Chọn reseller thì khách tạo sau của họ cũng được sao lưu.')]].map(function (o) {
                    return '<label class="mode-card"><input type="radio" name="filter_mode" value="' + o[0] + '"' + (mode === o[0] ? ' checked' : '') + '><span class="grow"><span class="mode-title">' + esc(o[1]) + '</span><span class="mode-sub">' + esc(o[2]) + '</span></span></label>';
                }).join('') + '</div></div>' +
            '<div class="field full" data-mode="exclude"><label>' + esc(t('Loại trừ theo reseller')) + '</label>' +
            '<div class="hint" style="margin:0 0 8px">' + esc(t('Loại trừ một reseller thì cả tài khoản reseller lẫn toàn bộ khách của reseller đó, kể cả khách tạo sau này, đều không được sao lưu. Chọn admin để loại trừ admin và khách do Admin tạo.')) + '</div>' +
            pickerHTML('exclude_resellers', rsItems, j.exclude_resellers || [], t('Không có reseller.'), 'Đã loại trừ {n}', t('Tìm reseller')) + '</div>' +
            '<div class="field full" data-mode="exclude"><label>' + esc(t('Loại trừ tài khoản')) + '</label>' +
            '<div class="hint" style="margin:0 0 8px">' + esc(t('Tick những tài khoản KHÔNG cần sao lưu (vd tài khoản dev, test).')) + '</div>' +
            pickerHTML('exclude_users', accItems, j.exclude_users || [], t('Không có tài khoản.'), 'Đã loại trừ {n}') + '</div>' +
            '<div class="field full" data-mode="include"><label>' + esc(t('Sao lưu theo reseller')) + '</label>' +
            '<div class="hint" style="margin:0 0 8px">' + esc(t('Chọn một reseller thì sao lưu cả tài khoản reseller lẫn toàn bộ khách của reseller đó, kể cả khách tạo sau này. Chọn admin để gồm admin và khách do Admin tạo.')) + '</div>' +
            pickerHTML('include_resellers', rsItems, j.include_resellers || [], t('Không có reseller.'), 'Đã chọn {n}', t('Tìm reseller')) + '</div>' +
            '<div class="field full" data-mode="include"><label>' + esc(t('Sao lưu tài khoản')) + '</label>' +
            '<div class="hint" style="margin:0 0 8px">' + esc(t('Tick những tài khoản cần sao lưu. Tài khoản tạo sau này không tự được thêm, trừ khi thuộc reseller đã chọn ở trên.')) + '</div>' +
            pickerHTML('include_users', accItems, j.include_users || [], t('Không có tài khoản.'), 'Đã chọn {n}') + '</div>' +
            (globalRs.length || globalEx.length ? '<div class="field full"><div class="hint" style="margin:0">' + ic('info') + ' ' + esc(t('Luôn bỏ qua mục trong "Không bao giờ tự sao lưu" (Cài đặt): {list}', { list: globalRs.map(function (r) { return t('{r} (kèm khách)', { r: r }); }).concat(globalEx).join(', ') })) + '</div></div>' : '') +
            '<div class="field full">' + switchHTML('name="skip_suspended"', j.skip_suspended, esc(t('Bỏ qua tài khoản đang bị tạm khóa'))) + '</div>' +
            '<div class="field full">' + switchHTML('name="enabled"', j.enabled, esc(t('Bật lịch này'))) + '</div>' +
            '</form><div class="sel-bar" data-job-preview><span class="grow muted">…</span></div>';
        var m = openModal({ title: job ? t('Sửa lịch') : t('Thêm lịch sao lưu'), size: 'md', body: body, foot: '<button class="btn" data-close-m>' + esc(t('Hủy')) + '</button><button class="btn btn-primary" data-save>' + ic('check') + esc(t('Lưu lịch')) + '</button>' });
        var form = m.el.querySelector('form');
        var collect = function () {
            var data = formData(form);
            data.schedule_time = data.hh + ':' + data.mm;
            delete data.hh; delete data.mm;
            // Chỉ gửi danh sách của chế độ đang chọn
            var md = data.filter_mode === 'include' ? 'include' : 'exclude';
            data[md + '_resellers'] = pickerValues(form, md + '_resellers');
            var covered = resellerExcludeMap(data[md + '_resellers']);
            // Tài khoản đã thuộc reseller được chọn thì không cần lưu riêng
            data[md + '_users'] = pickerValues(form, md + '_users').filter(function (u) { return !covered[u]; });
            return data;
        };
        var previewTimer = null;
        var preview = function () {
            markCovered(form, 'exclude_users', resellerExcludeMap(pickerValues(form, 'exclude_resellers')));
            markCovered(form, 'include_users', resellerExcludeMap(pickerValues(form, 'include_resellers')));
            clearTimeout(previewTimer);
            previewTimer = setTimeout(function () {
                var box = m.el.querySelector('[data-job-preview] .grow');
                api('job_preview', collect()).then(function (r) {
                    var n = r.users.length;
                    var size = {}; S.accounts.forEach(function (a) { size[a.username] = +a.usage_mb || 0; });
                    var mb = r.users.reduce(function (s, u) { return s + (size[u] || 0); }, 0);
                    box.innerHTML = n ? '<b>' + esc(t('{n} tài khoản sẽ được sao lưu', { n: n })) + '</b> <span class="badge info" title="' + esc(t('Tổng dung lượng đang dùng của các tài khoản (home, database, email), trước khi nén. Kho Incremental chỉ tải toàn bộ ở lần đầu, các lần sau chỉ phần thay đổi.')) + '">' + esc(t('~{size} dữ liệu', { size: fmtMb(mb) })) + '</span> <span class="muted small">' + esc(r.users.slice(0, 12).join(', ') + (n > 12 ? ', …' : '')) + '</span>' : '<span class="badge warn">' + esc(t('Không có tài khoản nào được sao lưu')) + '</span>';
                }, function (err) { box.textContent = err.message; });
            }, 250);
        };
        var keepHint = function () {
            var v = function (n) { return Math.max(0, +form[n].value || 0); };
            var max = v('keep_last') + v('keep_daily') + v('keep_weekly') + v('keep_monthly');
            var hours = v('keep_hours');
            var span = keepSpanText({ hours: hours, last: v('keep_last'), daily: v('keep_daily'), weekly: v('keep_weekly'), monthly: v('keep_monthly') }, form.schedule_type.value, form.interval_hours.value);
            form.querySelector('[data-keep-hint]').textContent = !max && !hours ? t('Phải giữ ít nhất 1 bản.')
                : (hours ? t('Mọi bản trong {h} giờ gần nhất đều được giữ, kể cả khi chạy lại nhiều lần trong ngày.', { h: hours }) + ' ' : '') +
                (max ? t('Bản cũ hơn: giữ bản cuối của mỗi ngày, mỗi tuần, mỗi tháng (tối đa {n} bản, một bản có thể tính cho nhiều mục nên thực tế ít hơn), còn lại tự xóa.', { n: max }) + ' ' : '') + span;
        };
        form.addEventListener('input', keepHint);
        var sync = function () {
            keepHint();
            var st = form.schedule_type.value;
            form.querySelectorAll('[data-show]').forEach(function (el) { el.hidden = el.getAttribute('data-show').split(' ').indexOf(st) === -1; });
            var md = form.querySelector('[name=filter_mode]:checked').value;
            form.querySelectorAll('[data-mode]').forEach(function (el) { el.hidden = el.getAttribute('data-mode') !== md; });
            form.querySelectorAll('.mode-card').forEach(function (el) { el.classList.toggle('active', el.querySelector('input').checked); });
            preview();
        };
        form.addEventListener('change', sync);
        bindPickers(form, preview);
        sync();
        m.el.querySelector('[data-close-m]').onclick = m.close;
        m.el.querySelector('[data-save]').onclick = function (e) {
            run(e.currentTarget, api('job_save', collect())).then(m.close, function () { });
        };
    }
    function range(a, b) { var r = []; for (var i = a; i <= b; i++) r.push(i); return r; }

    /* ============================== Kho lưu trữ ============================== */

    var DEST_TYPES = {
        local: { label: 'Ổ cứng máy chủ', icon: 'server' },
        s3: { label: 'Amazon S3 / Wasabi / MinIO', icon: 'cloud' },
        r2: { label: 'Cloudflare R2', icon: 'cloud' },
        b2: { label: 'Backblaze B2', icon: 'cloud' },
        sftp: { label: 'SFTP', icon: 'server' },
        ftp: { label: 'FTP', icon: 'server' },
        webdav: { label: 'WebDAV / Nextcloud', icon: 'cloud' },
        drive: { label: 'Google Drive', icon: 'cloud' },
        onedrive: { label: 'Microsoft OneDrive', icon: 'cloud' }
    };
    var DEST_FIELDS = {
        s3: [['provider', 'Nhà cung cấp', 'select:AWS|Wasabi|Minio|DigitalOcean|Other'], ['access_key_id', 'Access Key ID'], ['secret_access_key', 'Secret Access Key', 'secret'], ['region', 'Region', '', 'vd ap-southeast-1'], ['endpoint', 'Endpoint', '', 'Để trống với AWS']],
        r2: [['access_key_id', 'Access Key ID'], ['secret_access_key', 'Secret Access Key', 'secret'], ['endpoint', 'Endpoint', '', 'https://<account-id>.r2.cloudflarestorage.com']],
        b2: [['account', 'Key ID'], ['key', 'Application Key', 'secret']],
        sftp: [['host', 'Máy chủ'], ['port', 'Cổng', '', '22'], ['user', 'Tài khoản'], ['pass', 'Mật khẩu', 'secret']],
        ftp: [['host', 'Máy chủ'], ['port', 'Cổng', '', '21'], ['user', 'Tài khoản'], ['pass', 'Mật khẩu', 'secret']],
        webdav: [['url', 'URL', '', 'https://cloud.example.com/remote.php/dav/files/user'], ['vendor', 'Loại', 'select:other|nextcloud|owncloud'], ['user', 'Tài khoản'], ['pass', 'Mật khẩu', 'secret']],
        drive: [['token', 'Token OAuth', 'secret-area', 'Dán kết quả của lệnh: rclone authorize "drive"']],
        onedrive: [['token', 'Token OAuth', 'secret-area', 'Dán kết quả của lệnh: rclone authorize "onedrive"'], ['drive_id', 'Drive ID'], ['drive_type', 'Drive type', 'select:personal|business|documentLibrary']]
    };

    function viewDestinations() {
        var counts = destCounts();
        var rows = S.destinations.map(function (d) {
            var ty = DEST_TYPES[d.type] || { label: d.type, icon: 'cloud' };
            var where = d.type === 'local' ? d.path : d.subpath + (d.account ? ' · ' + d.account : '');
            var mt = d.restic_maint || {};
            var maint = d.format === 'restic' ? (mt.prune_ts ? '<div class="cell-sub">' + esc(t('Dọn kho {when}', { when: fmtRel(new Date(mt.prune_ts * 1000).toISOString().slice(0, 19).replace('T', ' ')) })) + '</div>' : '') +
                ((mt.check_ok === false || mt.prune_ok === false) ? ' <span class="badge bad" title="' + esc(mt.check_msg || mt.prune_msg || '') + '">' + esc(t('Kho có lỗi')) + '</span>' : '') : '';
            var test = d.last_test ? '<span class="badge ' + (d.last_test.ok ? 'ok' : 'bad') + '" title="' + esc(d.last_test.message) + '">' + esc(d.last_test.ok ? t('Kết nối tốt') : t('Lỗi kết nối')) + '</span><div class="cell-sub">' + esc(fmtRel(d.last_test.at)) + '</div>' : '<span class="muted">' + esc(t('Chưa kiểm tra')) + '</span>';
            return '<tr><td><div class="row"><div class="list-icon">' + ic(ty.icon) + '</div><div><div class="cell-main">' + esc(nameOf(d.name)) + '</div><div class="cell-sub mono">' + esc(where) + '</div>' +
                (counts[d.id] ? '<div class="cell-sub">' + esc(t('{n} bản sao lưu · {size}', { n: counts[d.id].n, size: fmtMb(counts[d.id].mb) })) + '</div>' : '') + '</div></div></td>' +
                '<td class="hide-sm">' + esc(t(ty.label)) + '<div class="cell-sub">' + esc(formatLabel(d)) + '</div>' + maint + '</td><td class="num">' + (d.free_gb !== null && d.free_gb !== undefined ? esc(d.free_gb) + ' GB' : '—') + '</td><td>' + test + '</td>' +
                '<td class="actions"><button class="btn btn-sm" data-act="dest-test" data-id="' + esc(d.id) + '">' + ic('check') + esc(t('Kiểm tra')) + '</button>' +
                (d.format === 'restic' && d.restic_key ? '<button class="btn btn-sm btn-ghost btn-icon" data-act="dest-key" data-id="' + esc(d.id) + '" title="' + esc(t('Mật khẩu kho restic')) + '" aria-label="' + esc(t('Mật khẩu kho restic')) + '">' + ic('shield') + '</button>' : '') +
                (counts[d.id] ? '<button class="btn btn-sm btn-ghost btn-icon" data-act="dest-move" data-id="' + esc(d.id) + '" title="' + esc(t('Chuyển kho')) + '" aria-label="' + esc(t('Chuyển kho')) + '">' + ic('move') + '</button>' : '') +
                (d.format !== 'restic' || d.restic_key ? '<button class="btn btn-sm btn-ghost btn-icon" data-act="dest-rescan" data-id="' + esc(d.id) + '" title="' + esc(t('Quét lại kho')) + '" aria-label="' + esc(t('Quét lại kho')) + '">' + ic('refresh') + '</button>' : '') +
                '<button class="btn btn-sm btn-ghost btn-icon" data-act="dest-edit" data-id="' + esc(d.id) + '" aria-label="' + esc(t('Sửa')) + '">' + ic('edit') + '</button>' +
                (d.id !== 'dest_local' ? '<button class="btn btn-sm btn-ghost btn-icon danger" data-act="dest-delete" data-id="' + esc(d.id) + '" aria-label="' + esc(t('Xóa')) + '">' + ic('trash') + '</button>' : '') + '</td></tr>';
        }).join('');
        return (!S.engine.rclone ? '<div class="banner warn">' + ic('alert', 'i-lg') + '<div><strong>' + esc(t('Chưa cài rclone')) + '</strong>' + esc(t('Chỉ dùng được kho trên ổ cứng máy chủ. Chạy lại scripts/install.sh bằng root để cài rclone và dùng kho cloud.')) + '</div></div>' : '') +
            '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Kho lưu trữ')) + '</div><button class="btn btn-primary btn-sm right" data-act="dest-edit">' + ic('plus') + esc(t('Thêm kho')) + '</button></div>' +
            '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Kho')) + '</th><th class="hide-sm">' + esc(t('Loại')) + '</th><th class="num">' + esc(t('Còn trống')) + '</th><th>' + esc(t('Kết nối')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<div class="card-foot" style="justify-content:flex-start"><span class="muted small">' + ic('info') + ' ' + esc(t('Ổ cứng máy chủ và kho cloud kiểu Incremental (restic) chỉ lưu phần thay đổi: giữ nhiều bản (ngày, tuần, tháng) mà tốn ít dung lượng. Kiểu Nén đẩy bản đầy đủ mỗi lần.')) + '</span></div></div>';
    }

    /** Loại kho dùng được chế độ Nén (khớp WNBRclone::ARCHIVE_TYPES). */
    var ARCHIVE_TYPES = ['s3', 'r2', 'b2', 'sftp', 'ftp', 'drive'];
    /** Phần đầu thư mục đích là bucket (khớp WNBRclone::BUCKET_TYPES): không có thư mục mặc định. */
    var BUCKET_TYPES = ['s3', 'r2', 'b2'];
    /** Mật khẩu kho restic: hiện để admin cất giữ ngoài máy chủ. */
    function showResticKey(id, isNew) {
        var d = byId(id, S.destinations);
        var m = openModal({
            title: isNew ? t('Cất giữ mật khẩu kho ngay') : t('Mật khẩu kho restic'), sub: esc(d ? nameOf(d.name) : ''),
            body: '<div class="banner warn" style="margin:0 0 14px">' + ic('alert', 'i-lg') + '<div>' + esc(t('Mất mật khẩu này là mất TOÀN BỘ bản sao lưu trong kho, kể cả khi máy chủ hỏng hẳn. Hãy lưu vào trình quản lý mật khẩu hoặc nơi an toàn ngoài máy chủ này.')) + '</div></div>' +
                '<div class="field"><label>' + esc(t('Mật khẩu')) + '</label><div class="row"><input class="input mono grow" readonly data-key value="' + esc(t('Đang tải…')) + '"><button class="btn" data-copy-key>' + ic('copy') + esc(t('Chép')) + '</button></div></div>' +
                '<div class="field" style="margin-top:10px"><label>' + esc(t('Kho restic')) + '</label><input class="input mono" readonly data-repo></div>' +
                '<p class="muted small" style="margin:10px 0 0">' + esc(t('Khi cài lại máy chủ: thêm lại kho với cùng bucket/thư mục, chọn Incremental (restic), nhập mật khẩu này rồi bấm "Quét lại kho" để nạp lại danh sách bản sao lưu.')) + '</p>',
            foot: '<button class="btn btn-primary" data-close-m>' + ic('check') + esc(isNew ? t('Tôi đã cất giữ') : t('Đóng')) + '</button>'
        });
        m.el.querySelector('[data-close-m]').onclick = m.close;
        api('dest_restic_key', { id: id }).then(function (r) {
            m.el.querySelector('[data-key]').value = r.password;
            m.el.querySelector('[data-repo]').value = r.repository;
        }, function (err) { m.el.querySelector('[data-key]').value = err.message; });
        m.el.querySelector('[data-copy-key]').onclick = function () {
            var v = m.el.querySelector('[data-key]').value;
            if (navigator.clipboard) navigator.clipboard.writeText(v).then(function () { toast(t('Đã chép mật khẩu')); });
        };
    }
    /** Kho đăng nhập bằng tài khoản: có nút Kết nối, các trường token chuyển vào mục "nhập thủ công". */
    var OAUTH_TYPES = { drive: 'Google', onedrive: 'Microsoft' };

    function oauthBox(ty, d, dest) {
        var connected = dest && d.type === ty && d.has_token;
        return '<div class="field full" data-oauth><label>' + esc(t('Tài khoản')) + '</label><div class="oauth-box">' +
            '<div class="row-wrap"><span data-oauth-status>' + (connected
                ? '<span class="badge ok">' + ic('check') + esc(t('Đã kết nối')) + '</span>' + (d.account ? ' <span class="muted small">' + esc(d.account) + '</span>' : '')
                : '<span class="badge">' + esc(t('Chưa kết nối')) + '</span>') + '</span>' +
            '<button type="button" class="btn btn-sm' + (connected ? '' : ' btn-primary') + ' right" data-oauth-start>' + ic('cloud') + esc(connected ? t('Kết nối tài khoản khác') : t('Kết nối {name}', { name: DEST_TYPES[ty].label })) + '</button></div>' +
            '<div class="oauth-step" data-oauth-step hidden><ol>' +
            '<li>' + esc(t('Đăng nhập {provider} trong tab vừa mở rồi bấm Cho phép.', { provider: OAUTH_TYPES[ty] })) + '</li>' +
            '<li>' + esc(t('Tab đó sẽ báo "Không thể truy cập trang web này". Đây là bình thường.')) + '</li>' +
            '<li>' + esc(t('Copy toàn bộ thanh địa chỉ của tab đó và dán vào ô dưới đây.')) + '</li></ol>' +
            '<div class="row"><input class="input mono" data-oauth-url autocomplete="off" spellcheck="false" placeholder="http://127.0.0.1:53682/?state=…&code=…"><button type="button" class="btn btn-primary" data-oauth-finish>' + esc(t('Xác nhận')) + '</button></div>' +
            '<div class="hint">' + esc(t('Tab đăng nhập không tự mở?')) + ' <a href="#" target="_blank" rel="noopener noreferrer" data-oauth-link>' + esc(t('Mở trang đăng nhập')) + '</a></div></div>' +
            '</div><input type="hidden" name="oauth_ticket" value=""></div>';
    }

    function bindOauth(box, ty) {
        var el = function (s) { return box.querySelector(s); };
        var step = el('[data-oauth-step]'), urlInput = el('[data-oauth-url]');
        el('[data-oauth-start]').onclick = function (e) {
            var btn = e.currentTarget;
            // Mở tab ngay trong lúc bấm để trình duyệt không chặn popup, rồi mới trỏ tới trang đăng nhập.
            var w = window.open('', '_blank');
            if (w) w.opener = null;
            btn.classList.add('loading');
            api('dest_oauth_start', { kind: ty }).then(function (r) {
                btn.classList.remove('loading');
                el('[data-oauth-link]').href = r.url;
                if (w) w.location.href = r.url;
                step.hidden = false;
                urlInput.value = '';
                urlInput.focus();
            }, function (err) {
                btn.classList.remove('loading');
                if (w) w.close();
                toast(err.message, true);
            });
        };
        var finish = function () {
            var v = urlInput.value.trim();
            if (!v) return;
            var btn = el('[data-oauth-finish]');
            btn.classList.add('loading');
            api('dest_oauth_finish', { kind: ty, url: v }).then(function (r) {
                btn.classList.remove('loading');
                box.querySelector('[name="oauth_ticket"]').value = r.ticket;
                el('[data-oauth-status]').innerHTML = '<span class="badge ok">' + ic('check') + esc(t('Đã kết nối')) + '</span>' + (r.account ? ' <span class="muted small">' + esc(r.account) + '</span>' : '') +
                    ' <span class="muted small">' + esc(t('Bấm "Lưu & kiểm tra kết nối" để hoàn tất.')) + '</span>';
                var start = el('[data-oauth-start]');
                start.classList.remove('btn-primary');
                start.innerHTML = ic('cloud') + esc(t('Kết nối tài khoản khác'));
                step.hidden = true;
            }, function (err) { btn.classList.remove('loading'); toast(err.message, true); });
        };
        el('[data-oauth-finish]').onclick = finish;
        urlInput.addEventListener('paste', function () { setTimeout(finish, 0); });
        urlInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); finish(); } });
    }
    function formatLabel(d) {
        if (d.type === 'local') return t('Incremental (hardlink)');
        if (d.format === 'restic') return t('Incremental (restic)');
        return d.format === 'archive' ? t('Nén') : t('Thư mục');
    }
    /** Số bản sao lưu, dung lượng và kiểu bản theo kho. */
    function destCounts() {
        var c = {};
        S.backups.forEach(function (b) {
            var x = c[b.destination_id] || (c[b.destination_id] = { n: 0, mb: 0, restic: 0, archive: 0 });
            x.n++;
            x.mb += +b.size_mb || 0;
            if (b.format === 'restic') x.restic++;
            if (b.format === 'archive') x.archive++;
        });
        return c;
    }
    /** Vì sao kho to không nhận được bản của kho from (khớp WNBEngine::moveProblem); '' = được. */
    function moveBlock(from, to, counts) {
        var c = counts[from.id] || {};
        if (counts[to.id]) return t('đã có bản sao lưu');
        if (c.restic && to.format !== 'restic') return t('cần kho kiểu Incremental (restic)');
        if (!c.restic && to.format === 'restic') return t('kho restic không nhận bản Nén/Thư mục');
        if (c.archive && to.type === 'local') return t('bản Nén chỉ chuyển sang kho cloud');
        return '';
    }
    function moveForm(d) {
        if (!d) return;
        var counts = destCounts(), c = counts[d.id] || { n: 0, mb: 0 };
        var others = S.destinations.filter(function (x) { return x.id !== d.id; });
        var ok = others.filter(function (x) { return !moveBlock(d, x, counts); });
        var opts = others.map(function (x) {
            var why = moveBlock(d, x, counts);
            return '<option value="' + esc(x.id) + '"' + (why ? ' disabled' : '') + '>' + esc(nameOf(x.name) + ' · ' + formatLabel(x) + (why ? ' (' + why + ')' : '')) + '</option>';
        }).join('');
        var jobs = (S.jobs || []).filter(function (j) { return j.destination_id === d.id; }).length;
        var snap = S.settings && S.settings.snapshot_destination === d.id;
        var uses = [];
        if (jobs) uses.push(t('{n} lịch', { n: jobs }));
        if (snap) uses.push(t('snapshot'));
        var body = '<div class="banner info" style="margin:0 0 14px">' + ic('info', 'i-lg') + '<div>' + esc(t('Chép {n} bản sao lưu ({size}) sang kho mới, so lại từng file rồi mới chuyển danh sách. Chủ sở hữu, lịch và nhãn giữ bản giữ nguyên nên khách vẫn thấy bản của mình.', { n: c.n, size: fmtMb(c.mb) })) + '</div></div>' +
            (ok.length ? '' : '<div class="banner warn" style="margin:0 0 14px">' + ic('alert', 'i-lg') + '<div>' + esc(t('Chưa có kho đích phù hợp. Hãy thêm một kho mới (thư mục trống) kiểu {format} rồi quay lại.', { format: c.restic ? t('Incremental (restic)') : t('Nén hoặc Thư mục') })) + '</div></div>') +
            '<form class="form-grid" data-form="move" autocomplete="off">' +
            '<div class="field full"><label>' + esc(t('Chuyển sang kho')) + '</label><select class="select" name="target_id"' + (ok.length ? '' : ' disabled') + '>' + opts + '</select>' +
            '<div class="hint">' + esc(t('Kho đích phải là kho mới thêm: chưa có bản sao lưu nào và thư mục trống.')) + '</div></div>' +
            (uses.length ? '<div class="field full">' + switchHTML('name="jobs"', true, esc(t('Từ nay {what} lưu vào kho mới', { what: uses.join(' ' + t('và') + ' ') }))) + '</div>' : '') +
            '<div class="field full">' + switchHTML('name="delete_source"', true, esc(t('Xóa dữ liệu ở kho cũ sau khi chép và kiểm tra xong'))) + '</div>' +
            '</form><p class="muted small" style="margin:12px 0 0">' + esc(t('Trong lúc chuyển, sao lưu, khôi phục và tải về của hai kho sẽ đợi. Kho cũ vẫn còn trong danh sách (trống), bạn tự xóa khi không dùng nữa.')) + '</p>';
        var m = openModal({
            title: t('Chuyển kho'), sub: esc(nameOf(d.name)), body: body,
            foot: '<button class="btn" data-close-m>' + esc(t('Hủy')) + '</button><button class="btn btn-primary" data-move' + (ok.length ? '' : ' disabled') + '>' + ic('move') + esc(t('Chuyển kho')) + '</button>'
        });
        m.el.querySelector('[data-close-m]').onclick = m.close;
        var form = m.el.querySelector('form[data-form="move"]');
        if (ok.length) form.target_id.value = ok[0].id;
        m.el.querySelector('[data-move]').onclick = function () {
            run(this, api('dest_move', {
                id: d.id, target_id: form.target_id.value,
                jobs: !form.jobs || form.jobs.checked ? '1' : '0',
                delete_source: form.delete_source.checked ? '1' : '0'
            })).then(m.close, function () {});
        };
    }
    function destForm(dest) {
        var d = dest || { type: 'local', name: '', path: '', subpath: '', params: {}, format: S.engine.restic ? 'restic' : 'archive' };
        var typeOpts = Object.keys(DEST_TYPES).map(function (k) { return '<option value="' + k + '"' + (d.type === k ? ' selected' : '') + '>' + esc(t(DEST_TYPES[k].label)) + '</option>'; }).join('');
        var body = '<form class="form-grid" data-form="dest" autocomplete="off">' + (dest ? '<input type="hidden" name="id" value="' + esc(d.id) + '">' : '') +
            '<div class="field"><label>' + esc(t('Tên hiển thị')) + '</label><input class="input" name="name" maxlength="60" value="' + esc(nameOf(d.name)) + '" placeholder="' + esc(t('Ví dụ: Cloudflare R2')) + '"></div>' +
            '<div class="field"><label>' + esc(t('Loại kho')) + '</label><select class="select" name="type"' + (dest ? ' disabled' : '') + '>' + typeOpts + '</select></div>' +
            '<div class="field full" data-kind="local"><label>' + esc(t('Thư mục trên máy chủ')) + '</label><input class="input mono" name="path" value="' + esc(d.path || '') + '" placeholder="/backup/webnowbackup"><div class="hint">' + esc(t('Nên dùng ổ đĩa riêng. Không được nằm trong thư mục home của khách.')) + '</div></div>' +
            '<div data-kind="remote" class="full form-grid" style="grid-column:1/-1"></div>' +
            '<div class="field full" data-kind="remote"><label data-subpath-label>' + esc(t('Bucket / thư mục đích')) + '</label><input class="input mono" name="subpath" value="' + esc(d.subpath || '') + '" placeholder="' + esc(t('ten-bucket/server1')) + '"><div class="hint" data-subpath-hint></div>' +
            (dest ? '<div class="hint">' + esc(t('Đổi bucket, endpoint hoặc máy chủ = chuyển sang kho khác: danh sách bản sao lưu ở vị trí cũ được gỡ khỏi plugin (dữ liệu ở vị trí cũ không bị xóa). Chỉ đổi khóa truy cập thì giữ nguyên.')) + '</div>' : '') + '</div>' +
            '<div class="field full" data-kind="remote"><label>' + esc(t('Cách lưu trên cloud')) + '</label><select class="select" name="format">' +
            '<option value="restic"' + (d.format === 'restic' ? ' selected' : '') + (S.engine.restic ? '' : ' disabled') + '>' + esc(t('Incremental (restic), khuyên dùng: chỉ tải phần thay đổi')) + (S.engine.restic ? '' : ' (' + esc(t('chưa cài restic')) + ')') + '</option>' +
            '<option value="archive"' + (d.format === 'archive' ? ' selected' : '') + '>' + esc(t('Nén: mỗi lần một bản đầy đủ, không cần chỗ trống trên máy chủ')) + '</option>' +
            '<option value="folder"' + (d.format === 'folder' ? ' selected' : '') + '>' + esc(t('Thư mục: duyệt và khôi phục từng file trực tiếp, tải lên chậm')) + '</option></select>' +
            '<div class="hint" data-format-hint></div></div>' +
            '<div class="field full" data-kind="remote" data-restic-only><label>' + esc(t('Mật khẩu kho restic')) + '</label><input class="input mono" name="restic_password" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="' + esc(dest && d.restic_key ? t('Đã lưu, để trống nếu không đổi') : t('Để trống để tự tạo mật khẩu mạnh')) + '">' +
            '<div class="hint">' + esc(t('Chỉ nhập khi kết nối lại một kho restic đã có (vd sau khi cài lại máy chủ). Kho mới: để trống, plugin tự tạo và hiện mật khẩu để bạn cất giữ.')) + '</div></div>' +
            '</form>' + (!S.engine.rclone ? '<div class="banner warn" data-kind="remote" style="margin:14px 0 0">' + ic('alert', 'i-lg') + '<div>' + esc(t('Máy chủ chưa cài rclone nên chưa dùng được kho cloud.')) + '</div></div>' : '');
        var m = openModal({ title: dest ? t('Sửa kho lưu trữ') : t('Thêm kho lưu trữ'), body: body, foot: '<button class="btn" data-close-m>' + esc(t('Hủy')) + '</button><button class="btn btn-primary" data-save>' + ic('check') + esc(t('Lưu & kiểm tra kết nối')) + '</button>' });
        var form = m.el.querySelector('form');
        var remoteBox = form.querySelector('.form-grid[data-kind="remote"]');
        var sync = function () {
            var ty = form.type.value;
            m.el.querySelectorAll('[data-kind]').forEach(function (el) { el.hidden = (el.getAttribute('data-kind') === 'local') !== (ty === 'local'); });
            var canArchive = ARCHIVE_TYPES.indexOf(ty) !== -1;
            form.format.querySelector('option[value="archive"]').disabled = !canArchive;
            if (!canArchive && form.format.value === 'archive') form.format.value = S.engine.restic ? 'restic' : 'folder';
            var fields = (DEST_FIELDS[ty] || []).map(function (f) {
                var name = 'p_' + f[0], kind = f[2] || '', ph = t(f[3] || ''), val = (dest && d.type === ty && d.params && d.params[f[0]]) || '';
                var savedSecret = dest && d['has_' + f[0]];
                var input;
                if (kind.indexOf('select:') === 0) {
                    input = '<select class="select" name="' + name + '">' + kind.slice(7).split('|').map(function (o) { return '<option' + (val === o ? ' selected' : '') + '>' + o + '</option>'; }).join('') + '</select>';
                } else if (kind === 'secret-area') {
                    input = '<textarea class="textarea" name="' + name + '" placeholder="' + esc(savedSecret ? t('Đã lưu, để trống nếu không đổi') : ph) + '"></textarea>';
                } else {
                    input = '<input class="input" name="' + name + '" type="' + (kind === 'secret' ? 'password' : 'text') + '" autocomplete="' + (kind === 'secret' ? 'new-password' : 'off') + '" data-lpignore="true" data-1p-ignore spellcheck="false" value="' + esc(kind === 'secret' ? '' : val) + '" placeholder="' + esc(savedSecret ? t('Đã lưu, để trống nếu không đổi') : ph) + '">';
                }
                return '<div class="field' + (kind === 'secret-area' ? ' full' : '') + '"><label>' + esc(t(f[1])) + '</label>' + input + (kind === 'secret-area' ? '<div class="hint">' + esc(ph) + '</div>' : '') + '</div>';
            }).join('');
            form.querySelector('[data-subpath-label]').textContent = OAUTH_TYPES[ty] ? t('Thư mục trên {name}', { name: DEST_TYPES[ty].label }) : t('Bucket / thư mục đích');
            var bucket = BUCKET_TYPES.indexOf(ty) !== -1, def = S.default_subpath || 'WebNowBackup/server';
            form.subpath.placeholder = bucket ? t('ten-bucket/server1') : def;
            form.querySelector('[data-subpath-hint]').textContent = bucket ? '' : t('Để trống thì dùng {path}, plugin tự tạo thư mục.', { path: def });
            if (OAUTH_TYPES[ty]) {
                remoteBox.innerHTML = oauthBox(ty, d, dest) + '<details class="adv field full"><summary>' + esc(t('Nhập token thủ công (rclone authorize)')) + '</summary><div class="form-grid">' + fields + '</div></details>';
                bindOauth(remoteBox.querySelector('[data-oauth]'), ty);
            } else {
                remoteBox.innerHTML = fields;
            }
            syncFormat();
        };
        var syncFormat = function () {
            var f = form.format.value;
            var remote = form.type.value !== 'local';
            form.querySelector('[data-restic-only]').hidden = !remote || f !== 'restic';
            form.querySelector('[data-format-hint]').textContent = f === 'restic'
                ? t('Incremental: lần đầu tải đầy đủ, các lần sau chỉ tải phần thay đổi; dữ liệu trùng giữa các tài khoản chỉ lưu một lần. Giữ nhiều bản ngày/tuần/tháng mà tốn ít dung lượng. Có mã hóa, máy chủ chỉ cần vài trăm MB trống.')
                : f === 'archive'
                    ? t('Nén: home và database được nén rồi đẩy thẳng lên cloud, máy chủ chỉ cần vài trăm MB trống. Khôi phục một file sẽ tải cả bản nén của tài khoản.')
                    : t('Thư mục: cần chỗ trống trên máy chủ bằng dung lượng tài khoản trong lúc sao lưu.');
        };
        form.type.addEventListener('change', sync);
        form.format.addEventListener('change', syncFormat);
        sync();
        m.el.querySelector('[data-close-m]').onclick = m.close;
        m.el.querySelector('[data-save]').onclick = function (e) {
            var data = formData(form);
            data.type = form.type.value;
            data.format = form.format.value;
            if (OAUTH_TYPES[data.type] && !data.oauth_ticket && !(data.p_token || '').trim() && !(dest && d.type === data.type && d.has_token)) {
                toast(t('Hãy bấm "Kết nối {name}" trước khi lưu.', { name: DEST_TYPES[data.type].label }), true);
                return;
            }
            var btn = e.currentTarget;
            btn.classList.add('loading');
            api('dest_save', data).then(function (res) {
                btn.classList.remove('loading');
                if (form.oauth_ticket) form.oauth_ticket.value = ''; // vé chỉ dùng được một lần
                toast(res.message, !res.test_ok);
                render();
                if (res.test_ok) m.close();
                if (res.restic_new_key) {
                    var saved = S.destinations.filter(function (x) { return nameOf(x.name) === data.name && x.format === 'restic'; })[0];
                    if (saved) showResticKey(saved.id, true);
                }
            }, function (err) { btn.classList.remove('loading'); toast(err.message, true); });
        };
    }

    /* ============================== Tác vụ ============================== */

    function viewTasks() {
        var list = S.queue.filter(function (x) { return !ui.taskStatus || x.status === ui.taskStatus || (ui.taskStatus === 'active' && (x.status === 'running' || x.status === 'pending')); });
        var rows = list.map(function (x) {
            var progress = (x.status === 'running' || x.status === 'pending') ? '<div class="progress" style="margin-top:6px"><div style="width:' + (+x.progress || 0) + '%"></div></div>' : '';
            var dur = '';
            if (x.finished_at && x.started_at) {
                var s = Math.max(0, Math.round((parseDate(x.finished_at) - parseDate(x.started_at)) / 1000));
                dur = '<div class="cell-sub">' + esc(s < 60 ? t('{n} giây', { n: s }) : t('{n} phút', { n: Math.round(s / 60) })) + '</div>';
            }
            return '<tr><td><div class="row"><div class="list-icon ' + (x.status === 'failed' ? 'bad' : x.status === 'completed' ? 'ok' : x.status === 'cancelled' ? '' : 'run') + '">' + ic(taskIcon(x)) + '</div><div><div class="cell-main">' + esc(taskLabel(x)) + '</div><div class="cell-sub">' + esc(taskTarget(x)) + (ROLE !== 'user' ? ' · ' + esc(t('bởi {who}', { who: x.initiated_by === 'cron' ? t('lịch tự động') : x.initiated_by })) : '') + '</div></div></div></td>' +
                '<td style="min-width:220px">' + statusBadge(x) + '<div class="cell-sub" style="margin-top:3px;max-width:520px;overflow-wrap:anywhere">' + esc(x.message) + '</div>' + progress + '</td>' +
                '<td class="nowrap hide-sm">' + esc(fmtRel(x.created_at)) + dur + '</td>' +
                '<td class="actions">' + (canCancel(x) ? '<button class="btn btn-sm btn-stop" data-act="task-cancel" data-id="' + esc(x.id) + '">' + ic('stop') + esc(t('Dừng')) + '</button>' : '') +
                '<button class="btn btn-sm btn-ghost" data-act="task-log" data-id="' + esc(x.id) + '">' + ic('list') + 'Log</button></td></tr>';
        }).join('');
        var html = '';
        if (ROLE === 'user' && S.downloads.length) html += downloadsCard() + '<div style="height:16px"></div>';
        var stoppable = ROLE === 'admin' ? S.queue.filter(canCancel).length : 0;
        html += '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Tác vụ')) + '</div><div class="row-wrap right">' +
            (stoppable > 1 ? '<button class="btn btn-sm btn-stop" data-act="task-cancel-all">' + ic('stop') + esc(t('Dừng tất cả ({n})', { n: stoppable })) + '</button>' : '') +
            '<select class="select" style="width:auto" data-bind="taskStatus">' +
            [['', t('Tất cả')], ['active', t('Đang chạy / chờ')], ['completed', t('Hoàn tất')], ['failed', t('Lỗi')], ['cancelled', t('Đã dừng')]].map(function (o) { return '<option value="' + o[0] + '"' + (ui.taskStatus === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') +
            '</select></div></div>' +
            (list.length ? '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Tác vụ')) + '</th><th>' + esc(t('Trạng thái')) + '</th><th class="hide-sm">' + esc(t('Thời gian')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                : empty('activity', t('Không có tác vụ'), '')) + '</div>';
        if (ROLE === 'user' && !S.downloads.length && perm('can_download')) html += '<p class="muted small" style="margin-top:12px">' + ic('info') + ' ' + esc(t('Muốn tải bản sao lưu về máy? Chọn thời điểm ở mục Khôi phục rồi bấm "Tải về".')) + '</p>';
        return html;
    }

    function showLog(id) {
        var x = byId(id, S.queue);
        var m = openModal({ title: t('Log tác vụ'), sub: esc(x ? taskLabel(x) + ' · ' + x.username + ' · ' + fmtDate(x.created_at) : id), size: 'lg', body: '<pre class="log-box">' + esc(t('Đang tải…')) + '</pre>', foot: '<button class="btn" data-close-m>' + esc(t('Đóng')) + '</button>' });
        m.el.querySelector('[data-close-m]').onclick = m.close;
        api('task_log', { task_id: id }).then(function (r) {
            m.el.querySelector('.log-box').textContent = r.log || t('Chưa có log.');
        }, function (err) { m.el.querySelector('.log-box').textContent = err.message; });
    }

    /* ============================== Cài đặt (admin) ============================== */

    function viewSettings() {
        var s = S.settings;
        var destOpts = S.destinations.map(function (d) { return '<option value="' + esc(d.id) + '"' + (s.snapshot_destination === d.id ? ' selected' : '') + '>' + esc(nameOf(d.name)) + '</option>'; }).join('');
        var html = '<form data-form="settings" autocomplete="off"><div class="grid grid-2">' +
            '<div class="stack"><div class="card"><div class="card-head"><div class="card-title">' + esc(t('Lưu trữ & hiệu năng')) + '</div></div><div class="card-body form-grid">' +
            fieldInput('local_backup_root', t('Thư mục lưu trữ local'), s.local_backup_root, t('Kho "Ổ cứng máy chủ" dùng thư mục này. Nên đặt trên ổ đĩa riêng, không chung phân vùng /.'), 'full mono') +
            fieldInput('min_free_gb', t('Dung lượng trống tối thiểu (GB)'), s.min_free_gb, t('Không chạy sao lưu khi kho còn ít hơn mức này, tránh làm đầy ổ đĩa.'), '', 'number') +
            fieldInput('max_concurrent_tasks', t('Tác vụ chạy song song'), s.max_concurrent_tasks, t('1–8. Tăng nếu máy chủ mạnh.'), '', 'number') +
            fieldInput('nice_level', t('Độ ưu tiên CPU (nice)'), s.nice_level, t('19 = thấp nhất, ít ảnh hưởng web nhất.'), '', 'number') +
            fieldInput('job_retry_minutes', t('Thử lại tài khoản lỗi sau (phút)'), s.job_retry_minutes, t('Lịch tự động chạy lại một lần các tài khoản bị lỗi (mạng, cloud chập chờn) trước khi gửi báo cáo. 0 = không thử lại.'), '', 'number') +
            fieldInput('default_retention', t('Giữ lại bản sao lưu thủ công'), s.default_retention, t('Số bản mỗi tài khoản trên mỗi kho.'), '', 'number') +
            fieldInput('orphan_days', t('Tự xóa bản sao lưu mồ côi sau (ngày)'), s.orphan_days || 0, t('Bản của tài khoản đã xóa, và bản của lịch đã xóa hoặc không còn sao lưu tài khoản đó (mỗi tài khoản vẫn giữ bản mới nhất). Tính từ bản mới nhất. Lịch đang tắt không bị dọn. 0 = không tự xóa.'), '', 'number') +
            fieldInput('bwlimit', t('Giới hạn băng thông cloud'), s.bwlimit, t('Ví dụ 20M. Để trống = không giới hạn.'), 'full') +
            '<div class="field full"><label>' + esc(t('Không sao lưu các đường dẫn (trong home)')) + '</label><textarea class="textarea" name="excludes" rows="4">' + esc(s.excludes) + '</textarea><div class="hint">' + esc(t('Mỗi dòng một mẫu, tính từ thư mục home. Ví dụ: backups/*, lscache/*, domains/*/public_html/wp-content/cache/*. Bắt đầu bằng **/ để khớp ở mọi cấp thư mục, ví dụ **/node_modules.')) + '</div></div>' +
            '</div></div>' +
            '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Không bao giờ tự sao lưu')) + '</div></div><div class="card-body">' +
            '<p class="card-desc">' + esc(t('Các tài khoản này bị bỏ qua ở mọi lịch tự động (ví dụ tài khoản dev, test). Vẫn sao lưu tay được.')) + '</p>' +
            '<div class="field" style="margin-top:12px"><label>' + esc(t('Theo reseller')) + '</label><div class="hint" style="margin:0 0 8px">' + esc(t('Gồm reseller và mọi khách của reseller đó, kể cả khách tạo sau này.')) + '</div>' +
            pickerHTML('exclude_resellers', resellerItems(), s.exclude_resellers || [], t('Không có reseller.'), 'Đã loại trừ {n}', t('Tìm reseller')) + '</div>' +
            '<div class="field" style="margin-top:14px"><label>' + esc(t('Theo tài khoản')) + '</label>' +
            pickerHTML('exclude_accounts', accountItems(), s.exclude_accounts || [], '', 'Đã loại trừ {n}') + '</div></div></div></div>' +
            '<div class="stack"><div class="card"><div class="card-head"><div class="card-title">' + esc(t('Khách hàng')) + '</div></div><div class="card-body form-grid">' +
            fieldInput('max_snapshots_per_user', t('Số snapshot tối đa mỗi tài khoản'), s.max_snapshots_per_user, t('Snapshot cũ nhất bị xóa khi vượt.'), '', 'number') +
            '<div class="field"><label>' + esc(t('Kho lưu snapshot')) + '</label><select class="select" name="snapshot_destination">' + destOpts + '</select></div>' +
            fieldInput('download_ttl_hours', t('Giữ file tải về (giờ)'), s.download_ttl_hours, '', '', 'number') +
            '</div></div>' +
            '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Thông báo')) + '</div><button type="button" class="btn btn-sm right" data-act="notify-test">' + ic('check') + esc(t('Gửi thử')) + '</button></div><div class="card-body form-grid">' +
            '<div class="field full"><label>' + esc(t('Tên máy chủ trong thông báo')) + '</label><input class="input" name="server_name" maxlength="60" value="' + esc(s.server_name || '') + '" placeholder="' + esc(S.server_host || '') + '">' +
            '<div class="hint">' + esc(t('Hiện ở đầu mọi tin Telegram và tiêu đề email để phân biệt khi nhiều máy chủ gửi về cùng một nơi. Để trống thì dùng hostname {host}.', { host: S.server_host || '' })) + '</div></div>' +
            '<div class="field full"><label>Telegram</label><div class="secret-state">' + (s.telegram_configured ? '<span class="badge ok">' + ic('check') + esc(t('Đã cấu hình')) + '</span><span class="muted small">' + esc(t('Token và Chat ID được giấu. Nhập mới để thay thế.')) + '</span>' + switchHTML('name="telegram_clear"', false, esc(t('Xóa cấu hình Telegram'))) : '<span class="badge">' + esc(t('Chưa cấu hình')) + '</span>') + '</div></div>' +
            '<div class="field"><label>Bot Token</label><input class="input mono" name="telegram_token" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="' + esc(s.telegram_configured ? t('Đã lưu, để trống nếu không đổi') : '123456789:AA...') + '"></div>' +
            '<div class="field"><label>Chat ID</label><input class="input mono" name="telegram_chat_id" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="' + esc(s.telegram_configured ? t('Đã lưu, để trống nếu không đổi') : '-1001234567890') + '"></div>' +
            '<div class="field full"><div class="hint">' + esc(t('Tạo bot qua @BotFather, thêm bot vào nhóm, rồi lấy Chat ID của nhóm (thường bắt đầu bằng -100).')) + '</div></div>' +
            fieldInput('notify_email', t('Email nhận cảnh báo'), s.notify_email, '', 'full', 'email') +
            '<div class="field"><label>' + esc(t('Báo cáo sau mỗi lượt chạy lịch')) + '</label><select class="select" name="notify_job_report">' +
            [['errors', t('Chỉ khi có lỗi')], ['always', t('Luôn gửi')], ['off', t('Không gửi')]].map(function (o) { return '<option value="' + o[0] + '"' + (s.notify_job_report === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></div>' +
            '<div class="field"><label>' + esc(t('Ngôn ngữ thông báo')) + '</label><select class="select" name="language"><option value="vi"' + (s.language !== 'en' ? ' selected' : '') + '>Tiếng Việt</option><option value="en"' + (s.language === 'en' ? ' selected' : '') + '>English</option></select></div>' +
            '<div class="field full">' + switchHTML('name="notify_on_failure"', s.notify_on_failure, esc(t('Báo ngay khi tác vụ thủ công bị lỗi'))) + '</div>' +
            '<div class="field full">' + switchHTML('name="notify_health"', s.notify_health !== false, esc(t('Cảnh báo khi có nguy cơ mất dữ liệu (ổ đĩa gần đầy, tài khoản quá hạn chưa sao lưu, lịch không chạy). Nhắc lại mỗi ngày đến khi xử lý xong.'))) + '</div>' +
            '</div></div></div></div>' +
            '<div class="row" style="justify-content:flex-end;margin:16px 0 24px"><button type="button" class="btn btn-primary" data-act="settings-save">' + ic('check') + esc(t('Lưu cài đặt')) + '</button></div></form>';

        var rows = Object.keys(S.acl).map(function (r) {
            var a = S.acl[r];
            var isAdmin = r === 'admin';
            var sw = function (k, disabled) { return disabled ? '<span class="muted">—</span>' : switchHTML('data-acl="' + k + '"', a[k]); };
            return '<tr data-acl-row="' + esc(r) + '"><td><div class="cell-main">' + esc(isAdmin ? t('Khách do Admin tạo') : r) + '</div>' + (isAdmin ? '' : '<div class="cell-sub">Reseller</div>') + '</td>' +
                '<td>' + sw('allow_backup', isAdmin) + '</td><td>' + sw('allow_restore', isAdmin) + '</td>' +
                '<td>' + sw('allow_user_restore') + '</td><td>' + sw('allow_user_snapshots') + '</td><td>' + sw('allow_user_downloads') + '</td>' +
                '<td>' + (isAdmin ? '<span class="muted">—</span>' : '<input class="input" type="number" min="0" step="1" style="width:90px" data-acl="quota_gb" value="' + esc(a.quota_gb) + '">') + '</td>' +
                '<td class="actions"><button class="btn btn-sm" data-act="acl-save" data-target="' + esc(r) + '">' + esc(t('Lưu')) + '</button></td></tr>';
        }).join('');
        html += '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Phân quyền')) + '</div></div>' +
            '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Áp dụng cho')) + '</th><th>' + esc(t('Reseller sao lưu')) + '</th><th>' + esc(t('Reseller khôi phục')) + '</th><th>' + esc(t('Khách tự khôi phục')) + '</th><th>' + esc(t('Khách tạo snapshot')) + '</th><th>' + esc(t('Khách tải về')) + '</th><th>' + esc(t('Hạn mức (GB)')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<div class="card-foot" style="justify-content:flex-start"><span class="muted small">' + ic('info') + ' ' + esc(t('Hạn mức là tổng dung lượng bản sao lưu của khách thuộc reseller khi reseller tự tạo bản sao lưu; 0 = không giới hạn.')) + '</span></div></div>';
        return html;
    }
    function fieldInput(name, label, value, hint, cls, type) {
        return '<div class="field ' + (cls && cls.indexOf('full') !== -1 ? 'full' : '') + '"><label>' + esc(label) + '</label><input class="input ' + (cls && cls.indexOf('mono') !== -1 ? 'mono' : '') + '" name="' + name + '" type="' + (type || 'text') + '" value="' + esc(value) + '">' + (hint ? '<div class="hint">' + esc(hint) + '</div>' : '') + '</div>';
    }

    /* ============================== Quyền của khách (reseller) ============================== */

    function viewPolicy() {
        var a = S.acl[S.me.user] || {};
        var row = function (k, title, sub) {
            return '<div class="setting-row"><div class="grow"><div>' + esc(title) + '</div><div class="muted small">' + esc(sub) + '</div></div>' + switchHTML('data-acl="' + k + '"', a[k]) + '</div>';
        };
        var yes = function (v) { return v ? '<span class="badge ok">' + ic('check') + esc(t('Được phép')) + '</span>' : '<span class="badge bad">' + ic('x') + esc(t('Bị tắt')) + '</span>'; };
        return '<div class="grid grid-main"><div class="card" data-acl-row="' + esc(S.me.user) + '"><div class="card-head"><div class="card-title">' + esc(t('Khách hàng của bạn được phép')) + '</div></div><div class="card-body" style="padding-top:4px;padding-bottom:4px">' +
            row('allow_user_restore', t('Tự khôi phục'), t('File, database, email, DNS, cron và toàn bộ tài khoản')) +
            row('allow_user_snapshots', t('Tự tạo snapshot'), t('Lưu nhanh trước khi cập nhật website')) +
            row('allow_user_downloads', t('Tải bản sao lưu về máy'), t('Đóng gói .tar.gz để tải xuống')) +
            '</div><div class="card-foot"><button class="btn btn-primary" data-act="acl-save" data-target="' + esc(S.me.user) + '">' + ic('check') + esc(t('Lưu')) + '</button></div></div>' +
            '<div class="card"><div class="card-head"><div class="card-title">' + esc(t('Quyền Admin cấp cho bạn')) + '</div></div><div class="card-body"><dl class="kv">' +
            '<dt>' + esc(t('Sao lưu thủ công')) + '</dt><dd>' + yes(a.allow_backup) + '</dd><dt>' + esc(t('Khôi phục')) + '</dt><dd>' + yes(a.allow_restore) + '</dd>' +
            '<dt>' + esc(t('Hạn mức')) + '</dt><dd>' + esc(+a.quota_gb ? a.quota_gb + ' GB' : t('Không giới hạn')) + '</dd></dl></div></div></div>';
    }

    /* ============================== Snapshot (user) ============================== */

    function viewSnapshots() {
        var snaps = S.backups.filter(function (b) { return b.type === 'snapshot'; });
        var max = S.settings.max_snapshots_per_user;
        var rows = snaps.map(function (b) {
            return '<tr><td><div class="cell-main">' + esc(fmtDate(b.created_at)) + '</div><div class="cell-sub">' + esc(fmtRel(b.created_at)) + '</div></td><td>' + esc(b.note || '—') + '</td><td class="num">' + fmtMb(b.size_mb) + '</td>' +
                '<td class="actions">' + (perm('can_restore') ? '<button class="btn btn-sm btn-primary" data-act="goto-point" data-backup="' + esc(b.id) + '">' + ic('restore') + esc(t('Khôi phục')) + '</button>' : '') +
                '<button class="btn btn-sm btn-ghost btn-icon danger" data-act="backup-delete" data-backup="' + esc(b.id) + '" aria-label="' + esc(t('Xóa')) + '">' + ic('trash') + '</button></td></tr>';
        }).join('');
        var create = perm('can_snapshot') ? '<button class="btn btn-primary btn-sm right" data-act="snapshot">' + ic('camera') + esc(t('Tạo snapshot')) + '</button>' : '';
        return '<div class="card"><div class="card-head"><div class="card-title">Snapshot <span class="badge">' + snaps.length + ' / ' + max + '</span></div>' + create + '</div>' +
            '<div class="card-body" style="padding-bottom:0"><p class="card-desc">' + esc(t('Snapshot lưu toàn bộ website, database, email và cấu hình ngay lúc bấm. Nên tạo trước khi cập nhật WordPress, cài plugin hay sửa code. Khi vượt {max} bản, snapshot cũ nhất bị xóa.', { max: max })) + '</p></div>' +
            (snaps.length ? '<div class="table-wrap"><table class="t"><thead><tr><th>' + esc(t('Thời điểm')) + '</th><th>' + esc(t('Ghi chú')) + '</th><th class="num">' + esc(t('Dung lượng')) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                : empty('camera', t('Chưa có snapshot'), '', create ? '<button class="btn btn-primary" data-act="snapshot">' + ic('camera') + esc(t('Tạo snapshot đầu tiên')) + '</button>' : '')) + '</div>';
    }

    function snapshotForm(username) {
        var m = openModal({
            title: t('Tạo snapshot'), sub: esc(username),
            body: '<div class="field"><label>' + esc(t('Ghi chú')) + '</label><input class="input" maxlength="120" data-note placeholder="' + esc(t('Ví dụ: Trước khi cập nhật WordPress 6.7')) + '"></div>',
            foot: '<button class="btn" data-close-m>' + esc(t('Hủy')) + '</button><button class="btn btn-primary" data-save>' + ic('camera') + esc(t('Tạo snapshot')) + '</button>'
        });
        m.el.querySelector('[data-close-m]').onclick = m.close;
        var note = m.el.querySelector('[data-note]');
        var save = m.el.querySelector('[data-save]');
        note.addEventListener('keydown', function (e) { if (e.key === 'Enter') save.click(); });
        save.onclick = function () {
            run(save, api('snapshot', { username: username, note: note.value })).then(function () { m.close(); goTab('tasks'); }, function () { });
        };
    }

    /* ============================== Khôi phục ============================== */

    // Trạng thái của khu khôi phục (dùng chung cho trang user và modal admin/reseller).
    var R = { username: '', backupId: '', section: 'files', path: '', files: {}, sel: {}, picks: {}, host: null };

    function resetRestore(username, backupId) {
        var list = backupsOf(username);
        R.username = username;
        R.backupId = backupId || (list[0] && list[0].id) || '';
        R.section = 'files';
        R.path = '';
        R.sel = {};
        R.picks = {};
    }

    function viewUserRestore() {
        var list = backupsOf(S.me.user);
        if (!list.length) {
            return '<div class="card">' + empty('archive', t('Chưa có bản sao lưu nào'), t('Bản sao lưu tự động của nhà cung cấp sẽ xuất hiện ở đây.') + (perm('can_snapshot') ? ' ' + t('Bạn cũng có thể tự tạo snapshot ngay bây giờ.') : ''),
                perm('can_snapshot') ? '<button class="btn btn-primary" data-act="snapshot">' + ic('camera') + esc(t('Tạo snapshot')) + '</button>' : '') + '</div>';
        }
        if (R.username !== S.me.user || !byId(R.backupId, S.backups)) resetRestore(S.me.user, R.backupId && byId(R.backupId, S.backups) ? R.backupId : '');
        var html = '';
        if (!perm('can_restore')) html += '<div class="banner warn">' + ic('alert', 'i-lg') + '<div><strong>' + esc(t('Tự khôi phục đang tắt')) + '</strong>' + esc(t('Nhà cung cấp chưa cho phép tài khoản này tự khôi phục. Bạn vẫn xem được nội dung bản sao lưu.')) + '</div></div>';
        return html + '<div data-restore></div>';
    }

    function mountRestore(host) {
        R.host = host || appEl.querySelector('[data-restore]');
        if (!R.host) return;
        R.host.innerHTML = restorePanel();
        if (R.section === 'files') loadFiles(R.path);
    }

    function currentBackup() { return byId(R.backupId, S.backups); }

    function restorePanel() {
        var list = backupsOf(R.username);
        var b = currentBackup();
        if (!b) return empty('archive', t('Không còn bản sao lưu'), '');
        var points = list.map(function (x) {
            return '<button class="point' + (x.id === b.id ? ' active' : '') + '" data-act="pick-point" data-backup="' + esc(x.id) + '"><div class="point-date">' + esc(fmtDate(x.created_at)) + '</div>' +
                '<div class="point-meta">' + typeBadge(x) + '<span>' + fmtMb(x.size_mb) + '</span><span>· ' + esc(nameOf(x.destination_name)) + '</span></div>' +
                (x.note && x.type === 'snapshot' ? '<div class="point-meta ellipsis">' + esc(x.note) + '</div>' : '') + '</button>';
        }).join('');
        var secs = [
            ['files', t('Tệp tin'), 'folder', ''],
            ['databases', 'Database', 'database', b.databases.length],
            ['emails', 'Email', 'mail', b.emails.length],
            ['dns', 'DNS', 'globe', b.domains.length],
            ['cron', 'Cron', 'calendar', b.crons.length],
            ['full', t('Toàn bộ'), 'restore', '']
        ];
        var seg = '<div class="seg" role="tablist">' + secs.map(function (s) {
            return '<button class="' + (R.section === s[0] ? 'active' : '') + '" data-act="restore-sec" data-sec="' + s[0] + '">' + ic(s[2]) + esc(s[1]) + (s[3] !== '' ? '<span class="n">' + s[3] + '</span>' : '') + '</button>';
        }).join('') + '</div>';
        var actions = perm('can_download') ? '<button class="btn btn-sm" data-act="download-create" data-backup="' + esc(b.id) + '">' + ic('download') + esc(t('Tải về')) + '</button>' : '';
        return '<div class="restore-layout"><div class="card"><div class="card-head"><div class="card-title">' + esc(t('Thời điểm sao lưu')) + ' <span class="badge">' + list.length + '</span></div></div><div class="points">' + points + '</div></div>' +
            '<div class="card"><div class="card-head"><div class="grow"><div class="cell-main" style="font-size:15px">' + esc(fmtDate(b.created_at)) + ' <span class="muted small" style="font-weight:400">· ' + esc(fmtRel(b.created_at)) + '</span></div>' +
            '<div class="cell-sub">' + esc(b.username) + ' · ' + fmtMb(b.size_mb) + ' · ' + esc(nameOf(b.destination_name)) + '</div></div>' + actions + '</div>' +
            '<div class="card-body"><div style="margin-bottom:14px">' + seg + '</div><div data-sec-body>' + sectionBody(b) + '</div></div></div></div>';
    }

    function sectionBody(b) {
        var canR = perm('can_restore');
        switch (R.section) {
            case 'files':
                return (b.format === 'archive' ? '<p class="muted small" style="margin:0 0 10px">' + ic('info') + ' ' + esc(t('Bản nén: khôi phục file sẽ tải bản nén của tài khoản từ cloud rồi lấy đúng các mục đã chọn, có thể mất vài phút.')) + '</p>' : '') +
                    '<div class="files"><div class="files-head"><div class="crumbs" data-crumbs>' + crumbs() + '</div></div><div class="files-body" data-files><div class="card-body"><div class="skeleton" style="width:60%"></div></div></div></div>' + selectionBar(canR);
            case 'databases':
                return pickList(b.databases, 'database', t('Bản sao lưu này không có database.'), t('Database được import đè lên database hiện tại cùng tên. Database đã bị xóa sẽ được tạo lại (cần gán lại user database trong DirectAdmin).'), canR);
            case 'emails':
                return missingDomainsNote(b) + pickList(b.emails, 'mail', t('Bản sao lưu này không có hộp thư.'), t('Khôi phục thư trong hộp thư. Thư hiện có được giữ nguyên; hộp thư đã bị xóa sẽ được tạo lại.'), canR);
            case 'dns':
                return missingDomainsNote(b) + pickList(b.domains, 'globe', t('Bản sao lưu này không có DNS zone.'), t('Bản ghi DNS của tên miền được thay bằng bản trong bản sao lưu.'), canR);
            case 'cron':
                return (b.crons.length ? '<div class="picks">' + b.crons.map(function (c) { return '<div class="cron-line">' + esc(c) + '</div>'; }).join('') + '</div>' : '<div class="empty" style="padding:20px">' + esc(t('Bản sao lưu không có cron job nào.')) + '</div>') +
                    '<div class="sel-bar"><span class="grow muted small">' + esc(t('Danh sách cron job hiện tại sẽ được thay bằng danh sách trên.')) + '</span><button class="btn btn-primary" data-act="restore-cron"' + (canR ? '' : ' disabled') + '>' + ic('restore') + esc(t('Khôi phục cron')) + '</button></div>';
            case 'full':
                return missingDomainsNote(b) + missingSubsNote(b) + '<div class="danger-box">' +
                    '<div class="cell-main" style="margin-bottom:6px">' + ic('alert') + ' ' + esc(t('Khôi phục toàn bộ tài khoản {user} về {date}', { user: b.username, date: fmtDate(b.created_at) })) + '</div>' +
                    '<ul style="margin:0;padding-left:18px;line-height:1.8"><li>' + esc(t('Toàn bộ file trong thư mục home được ghi đè bằng bản sao lưu (file mới tạo sau thời điểm này vẫn được giữ).')) + '</li><li>' + esc(t('{n} database được import lại.', { n: b.databases.length })) + '</li><li>' + esc(t('{e} hộp thư, {d} DNS zone và cron job được khôi phục.', { e: b.emails.length, d: b.domains.length })) + '</li></ul></div>' +
                    '<div class="sel-bar"><span class="grow muted small">' + esc(t('Nên khôi phục từng phần nếu chỉ cần lấy lại vài file hoặc một database.')) + '</span><button class="btn btn-danger" data-act="restore-full"' + (canR ? '' : ' disabled') + '>' + ic('restore') + esc(t('Khôi phục toàn bộ')) + '</button></div>';
        }
        return '';
    }

    /** Tên miền có trong bản sao lưu nhưng tài khoản hiện chưa có (thường gặp khi tài khoản bị xóa rồi tạo lại). */
    function missingDomainsNote(b) {
        var acc = null;
        S.accounts.forEach(function (a) { if (a.username === b.username) acc = a; });
        var missing = acc ? b.domains.filter(function (d) { return (acc.domains || []).indexOf(d) === -1; }) : [];
        if (!missing.length) return '';
        return '<div class="banner warn">' + ic('alert', 'i-lg') + '<div><strong>' + esc(t('Tài khoản chưa có {n} tên miền của bản sao lưu', { n: missing.length })) + '</strong>' +
            esc(t('Email và DNS của {domains} sẽ bị bỏ qua. Thêm các tên miền này trong DirectAdmin trước khi khôi phục.', { domains: missing.join(', ') })) + '</div></div>';
    }

    /** Subdomain/domain pointer có trong bản sao lưu nhưng tài khoản hiện chưa có (plugin không tạo lại). */
    function missingSubsNote(b) {
        var acc = null;
        S.accounts.forEach(function (a) { if (a.username === b.username) acc = a; });
        if (!acc) return '';
        var subs = (b.subdomains || []).filter(function (d) { return (acc.subdomains || []).indexOf(d) === -1; });
        var have = (acc.pointers || []).map(function (x) { return x.name; });
        var ptrs = (b.pointers || []).filter(function (x) { return have.indexOf(x.name) === -1; }).map(function (x) { return x.name; });
        if (!subs.length && !ptrs.length) return '';
        var list = [];
        if (subs.length) list.push(t('subdomain: {list}', { list: subs.join(', ') }));
        if (ptrs.length) list.push(t('domain pointer: {list}', { list: ptrs.join(', ') }));
        return '<div class="banner warn">' + ic('alert', 'i-lg') + '<div><strong>' + esc(t('Tài khoản chưa có một số subdomain / domain pointer của bản sao lưu')) + '</strong>' +
            esc(t('File vẫn được khôi phục, nhưng các mục này chỉ chạy lại sau khi tạo lại trong DirectAdmin ({list}).', { list: list.join('; ') })) + '</div></div>';
    }

    function pickList(items, icon, emptyText, note, canR) {
        if (!items.length) return '<div class="empty" style="padding:24px">' + esc(emptyText) + '</div>';
        var picks = R.picks[R.section] || {};
        var n = items.filter(function (x) { return picks[x]; }).length;
        return '<div class="pick-head"><label class="check"><input type="checkbox" data-act="pick-all"' + (n === items.length ? ' checked' : '') + '>' + esc(t('Chọn tất cả')) + '</label><span class="muted small right">' + esc(note) + '</span></div>' +
            '<div class="picks">' + items.map(function (x) {
                return '<label class="pick"><input type="checkbox" class="cb" data-act="pick" data-val="' + esc(x) + '"' + (picks[x] ? ' checked' : '') + '>' + ic(icon) + '<span class="grow">' + esc(x) + '</span></label>';
            }).join('') + '</div>' +
            '<div class="sel-bar"><span class="grow">' + (n ? esc(t('{n} mục đã chọn', { n: n })) : '<span class="muted">' + esc(t('Chưa chọn mục nào')) + '</span>') + '</span><button class="btn btn-primary" data-act="restore-picks"' + (n && canR ? '' : ' disabled') + '>' + ic('restore') + esc(t('Khôi phục mục đã chọn')) + '</button></div>';
    }

    function crumbs() {
        var parts = R.path ? R.path.split('/') : [];
        var html = '<button data-act="nav" data-path="">' + ic('home') + ' home</button>';
        var acc = '';
        parts.forEach(function (p) {
            acc += (acc ? '/' : '') + p;
            html += '<span class="sep">/</span><button data-act="nav" data-path="' + esc(acc) + '">' + esc(p) + '</button>';
        });
        return html;
    }

    function selectionBar(canR) {
        var keys = Object.keys(R.sel);
        return '<div class="sel-bar" data-selbar><span class="grow">' + (keys.length ? esc(t('{n} mục đã chọn', { n: keys.length })) + ' <button class="btn btn-sm btn-ghost" data-act="sel-clear">' + esc(t('Bỏ chọn')) + '</button>' : '<span class="muted">' + esc(t('Đánh dấu file hoặc thư mục cần khôi phục. Có thể chọn ở nhiều thư mục khác nhau.')) + '</span>') + '</span>' +
            '<button class="btn btn-primary" data-act="restore-files"' + (keys.length && canR ? '' : ' disabled') + '>' + ic('restore') + esc(keys.length ? t('Khôi phục {n} mục', { n: keys.length }) : t('Khôi phục')) + '</button></div>';
    }

    function loadFiles(path) {
        R.path = path;
        var key = R.backupId + '|' + path;
        var body = R.host && R.host.querySelector('[data-files]');
        var cr = R.host && R.host.querySelector('[data-crumbs]');
        if (cr) cr.innerHTML = crumbs();
        if (R.files[key]) { if (body) body.innerHTML = fileRows(R.files[key]); return; }
        if (body) body.innerHTML = '<div class="card-body"><div class="skeleton" style="width:55%;margin-bottom:10px"></div><div class="skeleton" style="width:40%;margin-bottom:10px"></div><div class="skeleton" style="width:48%"></div></div>';
        api('browse', { backup_id: R.backupId, path: path }).then(function (r) {
            R.files[key] = r.items;
            if (R.path === path && R.host) { var b2 = R.host.querySelector('[data-files]'); if (b2) b2.innerHTML = fileRows(r.items); }
        }, function (err) {
            if (body) body.innerHTML = '<div class="empty" style="padding:24px">' + ic('alert', 'i-lg') + '<strong>' + esc(t('Không mở được thư mục')) + '</strong>' + esc(err.message) + '</div>';
        });
    }

    function fileRows(items) {
        var html = '';
        if (R.path) {
            var parent = R.path.indexOf('/') === -1 ? '' : R.path.slice(0, R.path.lastIndexOf('/'));
            html += '<div class="file"><span style="width:16px"></span><div class="name">' + ic('chevron-left') + '<button data-act="nav" data-path="' + esc(parent) + '">' + esc(t('Thư mục cha')) + '</button></div></div>';
        }
        if (!items.length) return html + '<div class="empty" style="padding:22px">' + esc(t('Thư mục trống.')) + '</div>';
        return html + items.map(function (f) {
            var icon = f.type === 'dir' ? ic('folder', 'ic-dir') : (f.type === 'link' ? ic('link') : ic('file'));
            var name = f.type === 'dir' ? '<button data-act="nav" data-path="' + esc(f.path) + '">' + esc(f.name) + '</button>' : '<span>' + esc(f.name) + '</span>';
            return '<div class="file"><input type="checkbox" class="cb" data-act="sel" data-path="' + esc(f.path) + '" data-type="' + esc(f.type) + '"' + (R.sel[f.path] ? ' checked' : '') + ' aria-label="' + esc(f.name) + '">' +
                '<div class="name">' + icon + name + '</div><span class="meta size">' + (f.type === 'file' ? fmtBytes(f.size) : '') + '</span><span class="meta date">' + (f.mtime ? esc(fmtDate(f.mtime)) : '') + '</span></div>';
        }).join('');
    }

    function refreshSelBar() {
        var bar = R.host && R.host.querySelector('[data-selbar]');
        if (bar) bar.outerHTML = selectionBar(perm('can_restore'));
    }

    function openRestoreModal(username, backupId) {
        resetRestore(username, backupId);
        var m = openModal({ title: t('Khôi phục {user}', { user: username }), sub: esc(t('Chọn thời điểm sao lưu và nội dung cần khôi phục')), size: 'lg', body: '<div data-restore></div>', onClose: function () { R.host = null; R.username = ''; } });
        m.body.style.background = 'var(--bg)';
        R.modal = m;
        mountRestore(m.body.querySelector('[data-restore]'));
    }

    function doRestore(scope, items) {
        var b = currentBackup();
        var labels = { files: t('file / thư mục'), databases: 'database', emails: t('hộp thư'), dns: 'DNS zone', cron: 'cron job' };
        var body = scope === 'full'
            ? '<div class="danger-box">' + esc(t('Toàn bộ dữ liệu của {user} sẽ được đưa về thời điểm {date}. Thao tác này không hoàn tác được.', { user: b.username, date: fmtDate(b.created_at) })) + '</div>'
            : '<p>' + esc(t('Khôi phục {n} {what} từ bản sao lưu {date}. Dữ liệu hiện tại cùng tên sẽ bị ghi đè.', { n: items.length || '', what: labels[scope], date: fmtDate(b.created_at) })) + '</p>' +
            (items.length ? '<ul class="items">' + items.slice(0, 50).map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + (items.length > 50 ? '<li>' + esc(t('… và {n} mục khác', { n: items.length - 50 })) + '</li>' : '') + '</ul>' : '');
        confirmBox({ title: scope === 'full' ? t('Khôi phục toàn bộ tài khoản?') : t('Xác nhận khôi phục'), body: body, ok: t('Khôi phục'), danger: scope === 'full', typeToConfirm: scope === 'full' ? b.username : null }).then(function (res) {
            if (!res) return;
            var params = { backup_id: b.id, scope: scope, items: items };
            if (scope === 'full') params.confirm = res;
            api('restore', params).then(function (data) {
                toast(data.message + ' ' + t('Theo dõi tiến độ ở mục Tác vụ.'));
                R.sel = {};
                R.picks = {};
                if (R.modal) { R.modal.close(); R.modal = null; }
                goTab('tasks');
                schedulePoll();
            }, function (err) { toast(err.message, true); });
        });
    }

    /* ============================== Sự kiện ============================== */

    function goTab(tab) {
        ui.tab = tab;
        try { sessionStorage.setItem('wnb-tab-' + ROLE, tab); } catch (e) { /* bỏ qua */ }
        render();
    }

    function formData(form) {
        var out = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.disabled || el.closest('[data-picker]')) return;
            if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; return; }
            out[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        });
        return out;
    }

    root.addEventListener('click', function (e) {
        var el = e.target.closest('[data-act]');
        if (!el || !root.contains(el)) return;
        var act = el.getAttribute('data-act');
        var d = el.dataset;
        if (el.tagName === 'A' && act !== 'tab') return;
        if (['sel', 'pick', 'pick-all', 'job-toggle'].indexOf(act) !== -1) return; // xử lý ở sự kiện change
        e.preventDefault();

        switch (act) {
            case 'tab':
                if (d.filter) ui.taskStatus = d.filter;
                goTab(d.tab);
                break;
            case 'lang':
                setLang(d.lang);
                break;
            case 'refresh':
                run(el, api('state'), t('Đã cập nhật'));
                break;
            case 'copy':
                if (navigator.clipboard) navigator.clipboard.writeText(d.text).then(function () { toast(t('Đã chép lệnh')); });
                break;
            case 'backup-now':
                run(el, api('backup_now', { username: d.user, destination_id: ui.backupDest || 'dest_local' }));
                break;
            case 'open-restore':
                openRestoreModal(d.user, d.backup);
                break;
            case 'deleted-restore':
                deletedRestoreModal(d.user);
                break;
            case 'deleted-backups':
                ui.bkUser = d.user;
                goTab('backups');
                break;
            case 'download-create':
                run(el, api('download_create', { backup_id: d.backup }));
                break;
            case 'download-delete':
                run(el, api('download_delete', { id: d.id }));
                break;
            case 'backup-delete': {
                var b = byId(d.backup, S.backups);
                confirmBox({ title: t('Xóa bản sao lưu?'), body: '<p>' + esc(t('Bản sao lưu {name} sẽ bị xóa vĩnh viễn khỏi kho lưu trữ.', { name: b ? b.username + ' · ' + fmtDate(b.created_at) : d.backup })) + '</p>', ok: t('Xóa'), danger: true })
                    .then(function (ok) { if (ok) run(null, api('backup_delete', { backup_id: d.backup })); });
                break;
            }
            case 'snapshot':
                snapshotForm(S.me.user);
                break;
            case 'goto-point':
                resetRestore(S.me.user, d.backup);
                goTab('restore');
                break;
            case 'task-log':
                showLog(d.id);
                break;
            case 'task-cancel': {
                var tk = byId(d.id, S.queue);
                if (!tk) break;
                var note = tk.status === 'pending' ? t('Tác vụ đang chờ sẽ được bỏ khỏi hàng đợi.')
                    : tk.type === 'restore' ? t('Dữ liệu có thể mới được khôi phục một phần. Sau khi dừng, hãy khôi phục lại nếu cần.')
                        : (tk.type === 'backup' || tk.type === 'snapshot') ? t('Bản sao lưu dở dang sẽ bị xóa, các bản cũ không bị ảnh hưởng.')
                            : tk.type === 'move' ? t('Phần đã chép sang kho mới sẽ được dọn, kho cũ và danh sách bản sao lưu giữ nguyên.')
                            : t('Lệnh đang chạy sẽ bị dừng ngay.');
                confirmBox({ title: t('Dừng tác vụ?'), body: '<p><b>' + esc(taskLabel(tk)) + '</b> · ' + esc(taskTarget(tk)) + '</p><p class="muted">' + esc(note) + '</p>', ok: t('Dừng'), danger: true })
                    .then(function (ok) { if (ok) run(el, api('task_cancel', { task_id: d.id })); });
                break;
            }
            case 'task-cancel-all':
                confirmBox({ title: t('Dừng mọi tác vụ?'), body: '<p>' + esc(t('Mọi tác vụ đang chờ và đang chạy sẽ bị dừng (trừ tác vụ xóa đang chạy). Bản sao lưu dở dang sẽ bị xóa.')) + '</p>', ok: t('Dừng tất cả'), danger: true })
                    .then(function (ok) { if (ok) run(el, api('task_cancel', { all: '1' })); });
                break;
            case 'job-stop': {
                var js = byId(d.id, S.jobs);
                confirmBox({ title: t('Dừng lượt chạy?'), body: '<p>' + esc(t('Dừng {n} tác vụ còn lại của lịch "{job}". Tài khoản đã sao lưu xong vẫn giữ bản sao lưu; lịch vẫn chạy lại vào lần tới.', { n: d.n, job: js ? nameOf(js.name) : '' })) + '</p>', ok: t('Dừng'), danger: true })
                    .then(function (ok) { if (ok) run(el, api('task_cancel', { job_id: d.id })); });
                break;
            }
            case 'job-edit':
                jobForm(d.id ? byId(d.id, S.jobs) : null);
                break;
            case 'job-run': {
                var j = byId(d.id, S.jobs);
                confirmBox({ title: t('Chạy lịch ngay?'), body: '<p>' + esc(t('Đưa {n} tài khoản vào hàng đợi sao lưu tới {dest}.', { n: j ? j.target_count : '?', dest: j ? destName(j.destination_id) : '' })) + '</p>', ok: t('Chạy ngay') })
                    .then(function (ok) { if (ok) run(el, api('job_run', { id: d.id })); });
                break;
            }
            case 'job-delete':
                confirmBox({ title: t('Xóa lịch?'), body: '<p>' + esc(+S.settings.orphan_days > 0
                    ? t('Bản sao lưu của lịch này tự xóa sau {n} ngày (Cài đặt → Tự xóa bản sao lưu mồ côi). Mỗi tài khoản vẫn giữ bản mới nhất nếu không có bản đầy đủ nào mới hơn.', { n: S.settings.orphan_days })
                    : t('Bản sao lưu đã tạo vẫn được giữ.')) + '</p>', ok: t('Xóa lịch'), danger: true })
                    .then(function (ok) { if (ok) run(null, api('job_delete', { id: d.id })); });
                break;
            case 'dest-edit':
                destForm(d.id ? byId(d.id, S.destinations) : null);
                break;
            case 'dest-key':
                showResticKey(d.id, false);
                break;
            case 'dest-move':
                moveForm(byId(d.id, S.destinations));
                break;
            case 'dest-rescan':
                confirmBox({ title: t('Quét lại kho?'), body: '<p>' + esc(d.format === 'restic'
                    ? t('Đọc toàn bộ snapshot trong kho restic và nạp lại các bản sao lưu chưa có trong danh sách (vd sau khi cài lại máy chủ). Bản nạp lại chỉ admin thấy.')
                    : t('Đọc manifest.json của từng bản sao lưu trong kho và nạp lại các bản chưa có trong danh sách (vd sau khi cài lại máy chủ), gỡ các bản không còn trong kho. Bản nạp lại chỉ admin thấy.')) + '</p>', ok: t('Quét lại kho') })
                    .then(function (ok) { if (ok) run(el, api('dest_rescan', { id: d.id })); });
                break;
            case 'dest-test':
                el.classList.add('loading');
                api('dest_test', { id: d.id }).then(function (r) { toast(r.message, !r.test_ok); render(); }, function (err) { el.classList.remove('loading'); toast(err.message, true); });
                break;
            case 'dest-delete':
                confirmBox({ title: t('Xóa kho lưu trữ?'), body: '<p>' + esc(t('Chỉ xóa được kho không còn bản sao lưu và không có lịch nào dùng.')) + '</p>', ok: t('Xóa kho'), danger: true })
                    .then(function (ok) { if (ok) run(null, api('dest_delete', { id: d.id })); });
                break;
            case 'settings-save': {
                var form = root.querySelector('form[data-form="settings"]');
                var data = formData(form);
                data.exclude_resellers = pickerValues(form, 'exclude_resellers');
                var covered = resellerExcludeMap(data.exclude_resellers);
                data.exclude_accounts = pickerValues(form, 'exclude_accounts').filter(function (u) { return !covered[u]; });
                run(el, api('settings_save', data));
                break;
            }
            case 'notify-test':
                run(el, api('notify_test', {}));
                break;
            case 'acl-save': {
                var row = root.querySelector('[data-acl-row="' + (window.CSS && CSS.escape ? CSS.escape(d.target) : d.target) + '"]');
                var params = { target: d.target };
                row.querySelectorAll('[data-acl]').forEach(function (inp) { params[inp.getAttribute('data-acl')] = inp.type === 'checkbox' ? (inp.checked ? '1' : '0') : inp.value; });
                run(el, api('acl_save', params));
                break;
            }
            /* --- khu khôi phục --- */
            case 'pick-point':
                R.backupId = d.backup; R.path = ''; R.sel = {}; R.picks = {};
                mountRestore(R.host);
                break;
            case 'restore-sec':
                R.section = d.sec;
                mountRestore(R.host);
                break;
            case 'nav':
                loadFiles(d.path);
                break;
            case 'sel-clear':
                R.sel = {};
                R.host.querySelectorAll('[data-act="sel"]').forEach(function (c) { c.checked = false; });
                refreshSelBar();
                break;
            case 'restore-files':
                doRestore('files', Object.keys(R.sel));
                break;
            case 'restore-picks': {
                var picks = R.picks[R.section] || {};
                doRestore(R.section, Object.keys(picks).filter(function (k) { return picks[k]; }));
                break;
            }
            case 'restore-cron':
                doRestore('cron', []);
                break;
            case 'restore-full':
                doRestore('full', []);
                break;
        }
    });

    root.addEventListener('change', function (e) {
        var el = e.target;
        var act = el.getAttribute('data-act');
        var bind = el.getAttribute('data-bind');
        if (bind && bind !== 'search') {
            ui[bind] = el.value;
            if (bind !== 'backupDest') render();
            return;
        }
        if (act === 'sel') {
            if (el.checked) {
                if (Object.keys(R.sel).length >= 200) { el.checked = false; toast(t('Chỉ chọn tối đa 200 mục mỗi lần, hãy chọn thư mục cha.'), true); return; }
                R.sel[el.dataset.path] = el.dataset.type;
            } else delete R.sel[el.dataset.path];
            refreshSelBar();
        } else if (act === 'pick' || act === 'pick-all') {
            var b = currentBackup();
            var all = { databases: b.databases, emails: b.emails, dns: b.domains }[R.section] || [];
            var picks = R.picks[R.section] = R.picks[R.section] || {};
            if (act === 'pick-all') all.forEach(function (x) { picks[x] = el.checked; });
            else picks[el.dataset.val] = el.checked;
            R.host.querySelector('[data-sec-body]').innerHTML = sectionBody(b);
        } else if (act === 'job-toggle') {
            run(null, api('job_toggle', { id: el.dataset.id, enabled: el.checked ? '1' : '0' })).catch(function () { el.checked = !el.checked; });
        }
    });

    root.addEventListener('input', function (e) {
        if (e.target.getAttribute('data-bind') === 'search') {
            ui.search = e.target.value;
            var pos = e.target.selectionStart;
            render();
            var inp = appEl.querySelector('[data-bind="search"]');
            if (inp) { inp.focus(); try { inp.setSelectionRange(pos, pos); } catch (x) { /* bỏ qua */ } }
        }
    });

    /* ============================== Tự cập nhật ============================== */

    var pollTimer = null;
    function hasActive() { return S && S.queue.some(function (x) { return x.status === 'pending' || x.status === 'running'; }); }
    function schedulePoll() {
        clearTimeout(pollTimer);
        if (!hasActive()) return;
        pollTimer = setTimeout(function () {
            if (document.hidden) { schedulePoll(); return; }
            var before = JSON.stringify([S.queue, S.backups.length, S.downloads.length]);
            api('state').then(function () {
                var after = JSON.stringify([S.queue, S.backups.length, S.downloads.length]);
                var typing = document.activeElement && root.contains(document.activeElement) && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName);
                if (before !== after && !typing && !modalStack.some(function (m) { return !R.modal || m !== R.modal; })) {
                    render();
                    if (R.host && R.modal) mountRestore(R.host);
                }
                schedulePoll();
            }, function () { schedulePoll(); });
        }, 3000);
    }

    /* ============================== Giao diện sáng/tối theo skin ============================== */

    function luminance(color) {
        var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(color || '');
        if (!m || (m[4] !== undefined && +m[4] < 0.1)) return null;
        return (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255;
    }
    /** Độ sáng nền của tài liệu chứa plugin (bỏ qua phần nền do chính plugin tự tô). */
    function ownBackground() {
        var el = root.parentElement;
        while (el) {
            if (!el.hasAttribute('data-wnb-painted')) {
                var lum = luminance(getComputedStyle(el).backgroundColor);
                if (lum !== null) return lum;
            }
            el = el.parentElement;
        }
        return null;
    }
    function parentBackground() {
        try {
            if (window.parent === window) return null;
            var d = window.parent.document;
            var l = luminance(getComputedStyle(d.body).backgroundColor);
            return l !== null ? l : luminance(getComputedStyle(d.documentElement).backgroundColor);
        } catch (e) { return null; } // khác origin
    }
    function applyTheme() {
        var own = ownBackground();
        var lum = own !== null ? own : parentBackground();
        var th = root.getAttribute('data-force-theme') ||
            (lum === null ? (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : (lum < 0.45 ? 'dark' : 'light'));
        if (root.getAttribute('data-theme') !== th) root.setAttribute('data-theme', th);
        // Evolution nhúng plugin trong iframe có trang trắng không CSS: tự tô nền trang theo giao diện đã chọn.
        if (own === null) {
            var bg = getComputedStyle(root).getPropertyValue('--bg').trim();
            [document.documentElement, document.body].forEach(function (el) {
                el.setAttribute('data-wnb-painted', '1');
                el.style.background = bg;
            });
            document.documentElement.style.colorScheme = th;
        }
    }
    applyTheme();
    setInterval(function () { applyTheme(); fitFrame(); }, 1500);
    window.addEventListener('resize', fitFrame);
    if (window.matchMedia) { try { matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme); } catch (e) { /* trình duyệt cũ */ } }

    window.WNB = { render: render, setTheme: function (th) { root.setAttribute('data-force-theme', th || ''); applyTheme(); }, setLang: setLang };

    document.documentElement.lang = LANG;
    if (LANG === 'en' && S) {
        // Trang được dựng với thông báo tiếng Việt từ server: tải lại state theo ngôn ngữ đã chọn.
        api('state').then(render, render);
    }
    render();
    schedulePoll();
})();
