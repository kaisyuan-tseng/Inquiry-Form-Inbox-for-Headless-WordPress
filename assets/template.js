/* Inquiry Form Inbox for Headless WordPress — 邮件模板设置页脚本（版本 3.0.9）
 * 编辑器用 WordPress 原生代码编辑器（CodeMirror）+ 原生媒体库；这里只负责：
 * 实时预览、占位符插入、Desktop/Mobile 预览切换、字段标签拖动排序。
 * 配置由 wp_add_inline_script 注入 window.IFITpl。
 */
(function () {
  function boot() {
    // 语言选择框停在默认占位项时，Add 按钮置灰不可点；选中语言后恢复。
    Array.prototype.forEach.call(document.querySelectorAll('.ifi-lang-add'), function (langForm) {
      var select = langForm.querySelector('select[name="newlang"]');
      var button = langForm.querySelector('button[type="submit"]');
      if (!select || !button) { return; }

      function sync() {
        button.disabled = select.value === '';
      }

      select.addEventListener('change', sync);
      langForm.addEventListener('submit', function (e) {
        if (select.value === '') {
          e.preventDefault();
          select.focus();
        }
      });
      sync();
    });

    var cfg = window.IFITpl || {};
    var form = document.getElementById('ifi-tpl-form') || document.getElementById('ifi-reply-form');
    if (!form) return;

    // 回复模板页没有实时预览，只有一张表单；预览相关的初始化都要按 preview 是否存在来走
    var preview = document.getElementById('ifi-preview');
    var ta = document.getElementById('ifi_html');
    var note = preview ? document.getElementById('ifi-preview-note') : null;
    var cm = null;

    // WordPress 原生代码编辑器（CodeMirror）：HTML 语法高亮
    if (ta && window.wp && wp.codeEditor && cfg.codeSettings) {
      try {
        // 关掉 HTML 校验：邮件模板里的 {{占位符}} 会被 htmlhint 判成错误，行号槽全是感叹号
        var settings = JSON.parse(JSON.stringify(cfg.codeSettings));
        if (settings.codemirror) {
          delete settings.codemirror.lintWith;
          settings.codemirror.lint = false;
          // 与页面里的编辑器一致：不要行号槽（占位符会被 htmlhint 标错），只要干净的代码区
          settings.codemirror.lineNumbers = false;
          settings.codemirror.styleActiveLine = false;
          settings.codemirror.gutters = [];
        }
        var inst = wp.codeEditor.initialize(ta, settings);
        cm = inst.codemirror || null;
        if (cm) {
          cm.setOption('lint', false);
          cm.setOption('lineNumbers', false);
          cm.setOption('styleActiveLine', false);
          cm.setOption('gutters', []);
          var stale = document.querySelector('.CodeMirror-lint-markers');
          if (stale && stale.parentNode) stale.parentNode.removeChild(stale);
          cm.refresh();
          cm.setSize(null, 480);
          cm.on('change', function () {
            cm.save(); // 同步回 textarea：预览取值和表单提交都读它
            schedule();
          });
        }
      } catch (e) { /* 高亮失败也不影响手写 */ }
    }

    // 插入点：HTML 编辑器优先；回复模板页没有编辑器，插到「最近聚焦」的输入框
    // （To / Cc / Bcc / Subject / Body 都认占位符，默认落在正文）
    var lastField = null;
    form.addEventListener('focusin', function (e) {
      var el = e.target;
      if (el && (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && el.type === 'text'))) {
        lastField = el;
      }
    });

    function insert(text) {
      if (cm) { cm.replaceSelection(text); cm.focus(); schedule(); return; }
      var target = ta || lastField || document.getElementById('ifi-reply-body');
      if (!target) return;
      var s, e;
      if (ta || lastField) {
        s = typeof target.selectionStart === 'number' ? target.selectionStart : target.value.length;
        e = typeof target.selectionEnd === 'number' ? target.selectionEnd : s;
      } else {
        // 还没点进过任何输入框：接在正文末尾，别插到第一个字前面
        s = e = target.value.length;
      }
      target.value = target.value.slice(0, s) + text + target.value.slice(e);
      target.focus();
      target.selectionStart = s;
      target.selectionEnd = s + text.length;
      schedule();
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-insert-ph]'), function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        insert(btn.getAttribute('data-insert-ph'));
      });
    });

    /* ---------------------------------------------------------------- 预览 */

    function collect() {
      var out = {};
      var prefix = cfg.name + '[';
      var els = form.querySelectorAll('[name^="' + prefix + '"]');
      Array.prototype.forEach.call(els, function (el) {
        // 未勾选的复选框不会随表单提交，预览里也不该当成 enabled=1
        if (el.type === 'checkbox' && !el.checked) return;
        var inner = el.name.slice(prefix.length, el.name.length - 1);
        if (inner) out[inner] = el.value;
      });
      return out;
    }

    function refresh() {
      if (!preview) return;
      var params = new URLSearchParams();
      params.set('action', 'ifi_preview');
      params.set('nonce', cfg.nonce);
      if (cfg.kind) params.set('kind', cfg.kind);
      var data = collect();
      Object.keys(data).forEach(function (k) { params.set('template[' + k + ']', data[k]); });

      fetch(cfg.ajax, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      }).then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.success || !j.data) return;
          if (j.data.html) preview.srcdoc = j.data.html;
          // 说明文字由服务端给：预览用的是哪一封询盘，前端不自己拼
          if (note && typeof j.data.note === 'string') note.textContent = j.data.note;
        })
        .catch(function () {});
    }

    var timer = null;
    function schedule() {
      if (!preview) return; // 回复模板页没有实时预览，不发起回环请求
      clearTimeout(timer);
      timer = setTimeout(refresh, 600);
    }

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);

    // 添加媒体：走 WordPress 原生媒体库，选图后把 <img> 插到光标处
    var mediaBtn = document.getElementById('ifi-add-media');
    if (mediaBtn && window.wp && wp.media) {
      var frame = null;
      mediaBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!frame) {
          frame = wp.media({
            title: cfg.mediaTitle || '',
            library: { type: 'image' },
            multiple: false,
            // 用插件自己的「插入」，不要用核心的「插入至文章」（那是给文章编辑器用的）
            button: { text: cfg.mediaInsert || '' }
          });
          frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            var alt = (att.alt || '').replace(/"/g, '&quot;');
            insert('<img src="' + att.url + '" alt="' + alt + '" style="max-width:100%;height:auto;" />');
          });
        }
        frame.open();
      });
    }

    // 预览：桌面 / 手机
    var previewBody = document.querySelector('.ifi-preview-body');
    Array.prototype.forEach.call(document.querySelectorAll('[data-preview-mode] button'), function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        var mobile = b.getAttribute('data-size') === 'mobile';
        if (previewBody) previewBody.classList.toggle('is-mobile', mobile);
        Array.prototype.forEach.call(document.querySelectorAll('[data-preview-mode] button'), function (x) {
          x.classList.toggle('is-active', x === b);
        });
      });
    });

    // 字段标签拖动排序：DOM 顺序 = 保存顺序 = {{rows}} 的行序
    if (window.jQuery && jQuery.fn.sortable) {
      var labelList = document.querySelector('[data-sortable-labels]');
      if (labelList) {
        jQuery(labelList).sortable({
          items: '> .ifi-tpl-label-row',
          handle: '.ifi-sort',
          axis: 'y',
          placeholder: 'ui-sortable-placeholder',
          forcePlaceholderSize: true,
          update: function () { schedule(); }
        });
      }
    }

    /* 字段标签手动增删：键名要和前端表单的字段名一致 */
    (function () {
      var list = document.querySelector('[data-sortable-labels]');
      var addBtn = document.getElementById('ifi-add-label');
      if (!list) return;

      function attr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      }

      function refreshSortable() {
        list.classList.toggle('is-empty', !list.children.length);
        if (window.jQuery && jQuery.fn.sortable) { jQuery(list).sortable('refresh'); }
      }

      function bindRow(row) {
        var keyInput = row.querySelector('.ifi-tpl-label-newkey');
        var labelInput = row.querySelector('.ifi-tpl-label-input') || row.querySelector('input[name*="[labels]"]');
        var del = row.querySelector('.ifi-tpl-label-del');

        if (keyInput && labelInput) {
          keyInput.addEventListener('input', function () {
            var key = keyInput.value.replace(/[^A-Za-z0-9_\-]/g, '');
            if (key !== keyInput.value) { keyInput.value = key; }
            if (key) {
              labelInput.name = cfg.name + '[labels][' + key + ']';
              labelInput.disabled = false;
            } else {
              labelInput.removeAttribute('name');
              labelInput.disabled = true;
            }
          });
        }

        if (del) {
          del.addEventListener('click', function () {
            if (row.parentNode) { row.parentNode.removeChild(row); }
            refreshSortable();
          });
        }
      }

      Array.prototype.forEach.call(list.querySelectorAll('.ifi-tpl-label-row'), bindRow);

      if (addBtn) {
        addBtn.addEventListener('click', function () {
          var hasList = !!document.getElementById('ifi-field-suggestions');
          var row = document.createElement('div');
          row.className = 'ifi-tpl-label-row is-new';
          row.innerHTML =
            '<span class="ifi-sort" aria-hidden="true">⠿</span>' +
            '<input type="text" class="ifi-tpl-label-newkey" placeholder="' + attr(cfg.labelKeyPh || '') + '"' +
            (hasList ? ' list="ifi-field-suggestions"' : '') + ' />' +
            '<input type="text" class="ifi-tpl-label-input" placeholder="' + attr(cfg.labelValuePh || '') + '" disabled />' +
            '<button type="button" class="ifi-tpl-label-del" aria-label="' + attr(cfg.labelRemove || '') + '">×</button>';
          list.appendChild(row);
          bindRow(row);
          list.classList.remove('is-empty');
          var keyInput = row.querySelector('.ifi-tpl-label-newkey');
          if (keyInput) { keyInput.focus(); }
          refreshSortable();
        });
      }
    })();

    /* 预览卡高度：统一按 inquiry-notification 的模板卡高度（去掉标签行与自动确认专属区块） */
    var editorCard = document.querySelector('.ifi-tpl-editor-card');
    var previewCard = document.querySelector('.ifi-tpl-preview');

    function notifyBaselineHeight() {
      // 两个页签统一按「通知模板 + Field labels 没有数据」测量：
      // 1) 自动确认页多出来的区块（语言组、启用开关、语言下拉、提示）临时隐藏；
      // 2) 标签行列表与其它提示隐藏；
      // 3) 示例提示（ifi-labels-sample）临时显示出来作为「没有数据」时的占位行。
      var labelsField = editorCard.querySelector('.ifi-field--labels');
      var sample = labelsField ? labelsField.querySelector('.ifi-labels-sample') : null;
      var sampleWasHidden = !!( sample && sample.hasAttribute( 'hidden' ) );
      if ( sampleWasHidden ) sample.removeAttribute( 'hidden' );

      var nodes = editorCard.querySelectorAll(
        '[data-ifi-notify-ignore], .ifi-tpl-labels, .ifi-field--labels .ifi-hint:not(.ifi-labels-sample)'
      );
      var saved = [];
      var i;
      for ( i = 0; i < nodes.length; i++ ) {
        saved.push( [ nodes[i], nodes[i].style.display ] );
        nodes[i].style.display = 'none';
      }
      var height = editorCard.getBoundingClientRect().height;
      for ( i = 0; i < saved.length; i++ ) {
        saved[i][0].style.display = saved[i][1];
      }
      if ( sampleWasHidden ) sample.setAttribute( 'hidden', '' );
      return height;
    }

    function syncPreviewHeight() {
      if (!editorCard || !previewCard) return;
      if (window.innerWidth <= 1240) {
        previewCard.classList.remove('is-sized');
        previewCard.style.height = '';
        return;
      }
      var target = notifyBaselineHeight();
      if (target > 0) {
        previewCard.style.height = Math.round(target) + 'px';
        previewCard.classList.add('is-sized');
      }
    }

    var sizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(sizeTimer);
      sizeTimer = setTimeout(syncPreviewHeight, 120);
    });
    syncPreviewHeight();
    window.addEventListener('load', syncPreviewHeight);

    refresh();
  }

  // 等页脚里其它脚本都就绪再初始化
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
