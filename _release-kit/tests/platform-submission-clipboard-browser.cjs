const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const plugin = path.join(root, 'oj-integration-rebuild/oj-platform');
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  let checks = 0;
  try {
    for (const width of [1440, 390]) {
      for (const useAce of [false, true]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let html = '<!doctype html><html lang="ko"><head><meta charset="utf-8"><link rel="stylesheet" href="/app.css"></head><body class="ojp-app">';
        for (const [copy, paste] of [[1,1],[1,0],[0,1],[0,0]]) {
          const id = `${copy}${paste}`;
          const assets = useAce ? JSON.stringify({ core: '/ace/ace.js', base: '/ace' }).replaceAll('"', '&quot;') : '{}';
          html += `<form data-oj-submission-form data-oj-code-copy="${copy}" data-oj-code-paste="${paste}" id="form-${id}">`
            + `<input name="problem_id" value="${id}"><input type="radio" name="submission_mode" value="code" checked>`
            + `<section data-oj-mode-panel="code"><div data-oj-ace-editor data-assets="${assets}" data-oj-ace-theme="chrome"><div class="ojp-ace-editor-surface" id="ace-${id}" data-oj-ace-surface></div>`
            + '<textarea name="source" data-oj-source-editor>ORIGINAL</textarea><p data-oj-ace-status hidden></p></div></section>'
            + '<div data-oj-completion-editor><label class="ojp-completion-blank"><input name="completion_answers[x]"></label></div></form>';
        }
        html += '<input id="outside"><script src="/app.js"></script></body></html>';
        await page.route('**/*', route => {
          const url = new URL(route.request().url());
          if (url.pathname === '/app.js') { return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(plugin, 'resources/ui/js/oj-ui.js')) }); }
          if (url.pathname === '/app.css') { return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(path.join(plugin, 'resources/ui/css/oj-ui-system.css')) }); }
          if (/^\/ace\/[A-Za-z0-9_-]+\.js$/.test(url.pathname)) { return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(plugin, 'resources/features/ace/vendor', path.basename(url.pathname))) }); }
          return route.fulfill({ contentType: 'text/html', body: html });
        });
        await page.goto('http://submission-clipboard.test/');
        if (useAce) { await page.waitForFunction(() => document.querySelectorAll('[data-mounted="true"]').length === 4); }
        for (const [copy, paste] of [[1,1],[1,0],[0,1],[0,0]]) {
          const result = await page.evaluate(({ copy, paste, useAce }) => {
            const form = document.querySelector(`#form-${copy}${paste}`);
            const input = useAce ? form.querySelector('.ace_text-input') : form.querySelector('[data-oj-source-editor]');
            function fire(target, type, options = {}) {
              const event = type === 'keydown' ? new KeyboardEvent(type, { bubbles: true, cancelable: true, ...options })
                : type === 'beforeinput' ? new InputEvent(type, { bubbles: true, cancelable: true, ...options }) : new Event(type, { bubbles: true, cancelable: true });
              target.dispatchEvent(event);
              return event.defaultPrevented;
            }
            const result = {};
            // Stop allowed synthetic events before the Ace native clipboard handlers run.
            for (const type of ['paste','copy','cut','drop','beforeinput','keydown']) {
              form.addEventListener(type, event => event.stopImmediatePropagation(), true);
            }
            for (const type of ['copy','cut','paste','drop']) { result[type] = fire(input, type); }
            result.beforeinput = fire(input, 'beforeinput', { inputType: 'insertFromPaste', data: 'PASTED' });
            result.ctrlV = fire(input, 'keydown', { key: 'v', ctrlKey: true });
            result.shiftInsert = fire(input, 'keydown', { key: 'Insert', shiftKey: true });
            result.ctrlC = fire(input, 'keydown', { key: 'c', ctrlKey: true });
            result.typing = fire(input, 'keydown', { key: 'a' });
            result.blankPaste = fire(form.querySelector('[name="completion_answers[x]"]'), 'paste');
            result.problemPaste = fire(form.querySelector('[name="problem_id"]'), 'paste');
            if (useAce) {
              const editor = window.ace.edit(form.querySelector('[data-oj-ace-surface]'));
              editor.setValue('ORIGINAL', -1);
              editor.selectAll();
              editor.execCommand('paste', 'PASTED');
              result.commandValue = editor.getValue();
            }
            return result;
          }, { copy, paste, useAce });
          for (const key of ['copy','cut','ctrlC']) { assert.equal(result[key], !copy, `${key} policy`); checks++; }
          for (const key of ['paste','drop','beforeinput','ctrlV','shiftInsert','blankPaste']) { assert.equal(result[key], !paste, `${key} policy`); checks++; }
          assert.equal(result.typing, false); assert.equal(result.problemPaste, false); checks += 2;
          if (useAce) { assert.equal(result.commandValue, paste ? 'PASTED' : 'ORIGINAL'); checks++; }
        }
        assert.deepEqual(errors, []); checks++;
        await page.close();
      }
    }
    console.log(`Submission clipboard browser: ${checks} checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
