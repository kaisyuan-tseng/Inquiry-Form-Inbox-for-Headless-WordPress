/* Inquiry Form Inbox for Headless WordPress — 询盘列表详情弹窗（版本 3.0.0）
 * 从 PHP 内联脚本原样外置：仍从 .ifi-modal 的 data-* 属性读取配置。
 */
(function () {
    // 列表页概览看板：挪到标题下方、把列表包进面板
    (function initBoard() {
        var board = document.getElementById('ifi-board');
        if (!board) { return; }
        var anchor = document.querySelector('.wrap > .wp-header-end');
        // edit.php 在标题与列表之间没有 PHP 钩子，看板先在页头输出，这里再挪到标题下面
        if (anchor && anchor.parentNode) {
            anchor.parentNode.insertBefore(board, anchor.nextSibling);
        }

        // 标签 + 工具栏 + 表格 + 分页包进一张面板
        var subsub = document.querySelector('.wrap > .subsubsub');
        var postsForm = document.getElementById('posts-filter');
        if (subsub && postsForm && !document.querySelector('.ifi-list-panel')) {
            var panel = document.createElement('div');
            panel.className = 'ifi-list-panel';
            subsub.parentNode.insertBefore(panel, subsub);
            panel.appendChild(subsub);
            panel.appendChild(postsForm);
        }

        // 附件展开菜单：点空白处 / 按 Esc 收起（details 本身不会自动关）
        document.addEventListener('click', function (e) {
            var open = document.querySelectorAll('details.ifi-attach[open]');
            for (var i = 0; i < open.length; i++) {
                if (!open[i].contains(e.target)) { open[i].removeAttribute('open'); }
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            var open = document.querySelectorAll('details.ifi-attach[open]');
            for (var j = 0; j < open.length; j++) { open[j].removeAttribute('open'); }
        });

        // 环形图分段：悬停显示「名称 · 数量」
        var donutSegs = document.querySelectorAll('.ifi-donut [data-label]');
        if (donutSegs.length) {
            var tip = document.createElement('div');
            tip.className = 'ifi-tip';
            document.body.appendChild(tip);
            Array.prototype.forEach.call(donutSegs, function (seg) {
                seg.addEventListener('mouseenter', function () {
                    tip.textContent = seg.getAttribute('data-label');
                    tip.classList.add('is-on');
                });
                seg.addEventListener('mousemove', function (e) {
                    tip.style.left = (e.clientX + 14) + 'px';
                    tip.style.top = (e.clientY + 14) + 'px';
                });
                seg.addEventListener('mouseleave', function () {
                    tip.classList.remove('is-on');
                });
            });
        }
    })();

    var modal = document.getElementById('ifi-modal');
    if (!modal) { return; }
    var stage  = modal.querySelector('.ifi-modal__stage');
    var chip   = modal.querySelector('.ifi-modal__chip');
    var noEl   = modal.querySelector('.ifi-modal__no');
    var fromEl = modal.querySelector('.ifi-modal__from');
    var source = modal.querySelector('.ifi-modal__source');
    var attach = modal.querySelector('.ifi-modal__attach');
    var dateEl = modal.querySelector('.ifi-modal__date');
    var reply  = modal.querySelector('.ifi-modal__reply');
    var del    = modal.querySelector('.ifi-modal__delete');
    var prevB  = modal.querySelector('.ifi-modal__prev');
    var nextB  = modal.querySelector('.ifi-modal__next');
    var closers = modal.querySelectorAll('.ifi-modal__close');
    var ids = [];
    var current = -1;
    var lastFocus = null;

    function loading(text) {
        stage.innerHTML = '<p class="ifi-modal__loading"></p>';
        stage.firstChild.textContent = text;
    }
    function syncNav() {
        prevB.disabled = current <= 0;
        nextB.disabled = current === -1 || current >= ids.length - 1;
    }
    function fetch(id) {
        loading(modal.getAttribute('data-loading'));
        var url = ajaxurl + '?action=ifi_inquiry_detail&id=' + encodeURIComponent(id)
                + '&nonce=' + encodeURIComponent(modal.getAttribute('data-nonce'));
        fetchAbort();
        request = new AbortController();
        return window.fetch(url, { credentials: 'same-origin', signal: request.signal })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) { throw new Error('failed'); }
                return j.data;
            });
    }
    var request = null;
    function fetchAbort() {
        if (request) { request.abort(); }
    }
    function render(d) {
        stage.innerHTML = d.html || '';
        noEl.textContent = d.no || '\u2014';
        fromEl.innerHTML = d.from || '\u2014';
        source.textContent = d.source || '\u2014';
        attach.innerHTML = d.attach || '\u2014';
        dateEl.textContent = d.date || '\u2014';
        chip.hidden = !(d.state && d.status);
        chip.textContent = d.status || '';
        chip.className = 'ifi-modal__chip' + (d.state ? ' is-' + d.state : '');
        // 回复 = mailto：内容由详情接口按这条询盘的浏览器语言算好（d.reply，占位符已填）；
        // 接口没给时退回弹窗上的默认组模板 + 前端替换
        reply.hidden = !d.email;
        if (d.email) {
            var r = d.reply;
            if (!r) {
                var ctx = {
                    'rows.fullName.value': d.title || '', 'rows.email.value': d.email || '',
                    'rows.fullName.label': 'Full Name', 'rows.email.label': 'Email',
                    no: d.no || '', time: d.date || '', source: d.source || '',
                    // 兼容更早的模板：{{name}} / {{email}} / {{date}}
                    name: d.title || '', email: d.email || '', date: d.date || ''
                };
                var fill = function (s) {
                    return (s || '').replace(/\{\{([\w.]+)\}\}/g, function (_, k) {
                        return ctx[k] || '';
                    });
                };
                r = {
                    to: fill(modal.getAttribute('data-reply-to')),
                    cc: fill(modal.getAttribute('data-reply-cc')),
                    bcc: fill(modal.getAttribute('data-reply-bcc')),
                    subject: fill(modal.getAttribute('data-reply-subject')),
                    body: fill(modal.getAttribute('data-reply-body'))
                };
            }
            // to 也以模板为准（可为空，mailto:?… 合法）；cc/bcc/subject/body 非空才进 query
            var parts = [];
            if (r.cc) { parts.push('cc=' + encodeURIComponent(r.cc)); }
            if (r.bcc) { parts.push('bcc=' + encodeURIComponent(r.bcc)); }
            if (r.subject) { parts.push('subject=' + encodeURIComponent(r.subject)); }
            if (r.body) { parts.push('body=' + encodeURIComponent(r.body)); }
            reply.href = 'mailto:' + encodeURIComponent(r.to || '') + (parts.length ? '?' + parts.join('&') : '');
        }
        del.hidden = !d.delete;
        del.removeAttribute('href');
        if (d.delete) { del.href = d.delete; }
    }
    function show(pos) {
        if (pos < 0 || pos >= ids.length) { return; }
        current = pos;
        syncNav();
        fetch(ids[current]).then(function (d) {
            if (ids[current] !== ids[pos]) { return; } // 已翻到别条
            render(d);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') { return; }
            loading(modal.getAttribute('data-error'));
        });
    }
    function open(id) {
        id = String(id);
        // 同一屏上所有 .ifi-open 按出现顺序组成翻页序列：
        // 列表页是每一行的「查看」，仪表盘是挂件里的最近几条。
        var links = document.querySelectorAll('.ifi-open[data-id]');
        ids = [];
        for (var i = 0; i < links.length; i++) {
            var v = links[i].getAttribute('data-id');
            if (ids.indexOf(v) === -1) { ids.push(v); }
        }
        // 屏幕上没有对应链接时也照样打开这一条，别退回去显示序列里的第一条
        if (ids.indexOf(id) === -1) { ids.unshift(id); }
        lastFocus = document.activeElement;
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        noEl.textContent = ''; fromEl.innerHTML = ''; source.textContent = '';
        attach.innerHTML = ''; dateEl.textContent = '';
        reply.hidden = true; reply.removeAttribute('href');
        del.hidden = true; del.removeAttribute('href');
        chip.hidden = true;
        closers[0].focus();
        show(ids.indexOf(id));
    }
    function close() {
        fetchAbort();
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('.ifi-open');
        if (link) {
            e.preventDefault();
            open(link.getAttribute('data-id'));
        }
    });
    prevB.addEventListener('click', function () { show(current - 1); });
    nextB.addEventListener('click', function () { show(current + 1); });
    del.addEventListener('click', function () {
        var url = del.getAttribute('href');
        if (!url) { return; }
        if (window.confirm(modal.getAttribute('data-confirm-delete'))) {
            window.location.assign(url); // admin-post 处理完会 302 回列表页
        }
    });
    Array.prototype.forEach.call(closers, function (b) { b.addEventListener('click', close); });
    modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
    document.addEventListener('keydown', function (e) {
        if (!modal.classList.contains('is-open')) { return; }
        if (e.key === 'Escape') { close(); }
        else if (e.key === 'ArrowLeft') { show(current - 1); }
        else if (e.key === 'ArrowRight') { show(current + 1); }
    });

    // 无脚本 / 旧链接的兜底：带 ifi_view=<id> 打开列表页时，仍自动弹出同一条询盘。
    // 仪表盘挂件已经改成就地打开，正常不会走到这里。
    var auto = new URLSearchParams(window.location.search).get('ifi_view');
    if (auto) { open(auto); }
})();
