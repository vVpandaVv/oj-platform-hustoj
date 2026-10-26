(function () {
  'use strict';

  var scriptUrl = document.currentScript.src;
  var base = new URL('../vendor/', scriptUrl);
  var version = new URL(scriptUrl).search;
  var runtime;

  function loadScript(name) {
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = new URL(name, base).href + version;
      script.onload = resolve;
      script.onerror = function () { reject(new Error('편집기 파일을 불러오지 못했습니다.')); };
      document.head.appendChild(script);
    });
  }

  function loadRuntime() {
    if (!runtime) {
      runtime = new Promise(function (resolve, reject) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = new URL('jodit.min.css', base).href + version;
        link.onload = resolve;
        link.onerror = function () { reject(new Error('편집기 스타일을 불러오지 못했습니다.')); };
        document.head.appendChild(link);
      }).then(function () { return loadScript('purify.min.js'); })
        .then(function () { return loadScript('jodit.min.js'); });
    }
    return runtime;
  }

  function sanitize(value) {
    return window.DOMPurify.sanitize(value, {
      ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'div', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col', 'font', 'hr', 'sub', 'sup'],
      ALLOWED_ATTR: ['href', 'title', 'target', 'rel', 'src', 'alt', 'width', 'height', 'style', 'colspan', 'rowspan', 'scope', 'size', 'start', 'type'],
      ALLOW_DATA_ATTR: false,
      ALLOW_ARIA_ATTR: false
    });
  }

  function makeEditor(root) {
    var source = root.querySelector('[data-ojp-rich-text-source]');
    if (!source || root.ojpEditor) return;
    var required = root.getAttribute('data-required') === 'true';
    var error = root.querySelector('[data-ojp-rich-text-error]');
    var status = root.querySelector('[data-ojp-rich-text-status]');
    var upload = root.querySelector('[data-ojp-rich-text-upload-fields]');
    var form = root.closest('form');
    var pendingUploads = 0;
    var buttons = ['paragraph', 'fontsize', '|', 'bold', 'italic', 'ul', 'ol', '|', 'link'];
    if (root.getAttribute('data-editor-tables') === 'true') buttons.push('table');
    if (upload) buttons.push('ojpImage');
    if (root.getAttribute('data-editor-latex') === 'true') buttons.push('ojpLatex');
    buttons.push('|', 'undo', 'redo', '|', 'source');
    source.value = sanitize(source.value);

    function showStatus(message) {
      status.textContent = message;
      status.hidden = !message;
    }

    function uploadImage(file) {
      if (!file) return;
      if (['image/png', 'image/jpeg', 'image/gif', 'image/webp'].indexOf(file.type) < 0 || file.size > 5 * 1024 * 1024) {
        showStatus('PNG, JPEG, GIF, WebP 이미지(5MB 이하)를 선택해 주세요.');
        return;
      }
      var fields = new FormData();
      upload.content.querySelectorAll('input').forEach(function (input) { fields.append(input.name, input.value); });
      fields.append('image', file, file.name || 'image');
      pendingUploads += 1;
      showStatus('이미지를 올리는 중입니다.');
      window.fetch(upload.getAttribute('data-upload-url'), {
        method: 'POST', body: fields, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}
      }).then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok || !body.ok) throw new Error(body.message || '이미지를 올리지 못했습니다.');
          var url = new URL(body.url, window.location.href);
          if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) throw new Error('이미지 주소가 올바르지 않습니다.');
          editor.s.restore();
          editor.s.insertImage(url.href, null, 300);
          showStatus('');
        });
      }).catch(function (failure) { showStatus(failure.message); })
        .finally(function () { pendingUploads -= 1; });
    }

    var editor = window.Jodit.make(source, {
      language: 'ko',
      theme: window.getComputedStyle(root).colorScheme === 'dark' ? 'dark' : 'default',
      height: root.getAttribute('data-editor-size') === 'large' ? 360 : 260,
      minHeight: 220,
      toolbarButtonSize: 'middle',
      toolbarAdaptive: true,
      buttons: buttons,
      buttonsMD: buttons,
      buttonsSM: ['bold', 'italic', 'ul', 'ol', 'table', 'source', 'dots'],
      buttonsXS: ['bold', 'italic', 'table', 'source', 'dots'],
      sourceEditor: 'area',
      sourceEditorCDNUrlsJS: [],
      beautifyHTML: false,
      beautifyHTMLCDNUrlsJS: [],
      useSplitMode: false,
      showCharsCounter: false,
      showWordsCounter: false,
      showXPathInStatusbar: false,
      toolbarSticky: false,
      askBeforePasteHTML: false,
      askBeforePasteFromWord: false,
      defaultActionOnPaste: 'insert_as_html',
      enableDragAndDropFileToEditor: false,
      disablePlugins: ['file', 'video', 'speech-recognize', 'powered-by-jodit'],
      cleanHTML: {
        sanitizer: sanitize,
        removeEventAttributes: true,
        safeJavaScriptLink: true,
        denyTags: 'script,style,iframe,object,embed,svg,math,form,input,button',
        allowedStyles: {'*': ['color', 'background-color', 'font-size', 'font-family', 'font-weight', 'font-style', 'text-align', 'text-decoration', 'border', 'border-width', 'border-style', 'border-color', 'border-collapse', 'padding', 'width', 'height', 'vertical-align']},
        collapseEmptyValueToEmptyString: true
      },
      controls: {
        ojpImage: {icon: 'image', tooltip: '이미지 업로드', exec: function () {
          editor.s.save();
          var input = document.createElement('input');
          input.type = 'file'; input.accept = 'image/png,image/jpeg,image/gif,image/webp';
          input.addEventListener('change', function () { uploadImage(input.files[0]); });
          input.click();
        }},
        ojpLatex: {text: 'LaTeX', tooltip: 'LaTeX 삽입', exec: function () {
          editor.s.save();
          editor.prompt('LaTeX', 'LaTeX 삽입', function (value) {
            if (value) { editor.s.restore(); editor.s.insertHTML(sanitize('<p>\\(' + value.replace(/[<>&]/g, function (c) { return {'<':'&lt;', '>':'&gt;', '&':'&amp;'}[c]; }) + '\\)</p>')); }
          });
        }}
      },
      events: {
        change: function () { if (error) error.hidden = true; source.removeAttribute('aria-invalid'); },
        beforePaste: function (event) {
          var files = event.clipboardData && event.clipboardData.files;
          if (files && files.length) {
            event.preventDefault();
            if (upload) { editor.s.save(); uploadImage(files[0]); }
            return false;
          }
        }
      }
    });
    root.ojpEditor = editor;
    source.required = false;
    editor.editor.setAttribute('aria-labelledby', source.getAttribute('aria-labelledby'));
    editor.editor.setAttribute('aria-required', required ? 'true' : 'false');
    root.classList.add('is-enhanced');
    if (form) form.addEventListener('submit', function (event) {
      var mirror = root.querySelector('.jodit-source__mirror');
      var raw = editor.getMode() === window.Jodit.MODE_SOURCE && mirror ? mirror.value : editor.value;
      source.value = sanitize(raw);
      var text = new DOMParser().parseFromString(source.value, 'text/html');
      var empty = !text.body.textContent.split(String.fromCharCode(160)).join(' ').trim() && !text.body.querySelector('img');
      if (pendingUploads || (required && empty)) {
        event.preventDefault();
        if (pendingUploads) showStatus('이미지 업로드가 끝난 뒤 저장해 주세요.');
        else { source.setAttribute('aria-invalid', 'true'); error.hidden = false; editor.focus(); }
      }
    });
  }

  window.OJPRichText = {initialize: function (root) { return loadRuntime().then(function () { makeEditor(root); }); }};
}());
