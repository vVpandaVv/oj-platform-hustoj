const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const plugin = path.join(root, 'oj-integration-rebuild/oj-platform');
const output = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', [
  path.join(plugin, 'tests/Unit/completion-language-selector.php'),
], { encoding: 'utf8', env: { ...process.env, OJ_COMPLETION_LANGUAGE_FIXTURE: '1' } });
const fixtures = JSON.parse(output.split('COMPLETION_LANGUAGE_FIXTURES=')[1]);

(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  let checks = 0;
  try {
    for (const width of [1440, 390]) {
      for (const key of ['new', 'python3', 'c++', 'js', 'PYTHON3', 'unsupported']) {
        const fixture = fixtures[key];
        const page = await browser.newPage({ viewport: { width, height: 950 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
          const url = new URL(route.request().url());
          if (url.pathname.endsWith('/ui/js/oj-ui.js')) return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(plugin, 'resources/ui/js/oj-ui.js')) });
          if (url.pathname.endsWith('/ui/css/oj-ui-system.css')) return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(path.join(plugin, 'resources/ui/css/oj-ui-system.css')) });
          if (url.pathname.endsWith('/features/richtext/js/editor.js')) return route.fulfill({ contentType: 'application/javascript', body: 'window.OJPRichText={initialize:function(){return Promise.resolve();}};' });
          return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/resources/ui/css/oj-ui-system.css"><body class="ojp-app"><main class="ojp-shell">' + fixture.html + '</main><script src="/resources/ui/js/oj-ui.js"></script></body></html>' });
        });
        await page.goto('http://completion-language.test/');
        const select = page.locator('select[name="completion_language_key"]');
        const schema = page.locator('[name="code_completion_schema"]');
        const mode = value => page.locator(`input[name="code_entry_mode"][value="${value}"]`);
        const readSchema = async () => JSON.parse(await schema.inputValue());
        assert.equal(await select.evaluate(node => node.tagName), 'SELECT'); checks++;
        assert.equal(await select.locator('..').locator('.ojp-label').innerText(), '빈칸 문제 언어'); checks++;
        if (key === 'new') {
          await mode('code_completion').locator('..').click();
          assert.equal(await select.evaluate(node => node.checkValidity()), false); checks++;
          for (const value of ['c', 'cpp', 'java', 'python', 'php', 'csharp', 'javascript', 'go']) {
            await select.selectOption(value);
            assert.equal((await readSchema()).language_key, value); checks++;
          }
          await select.selectOption('python');
          const template = page.locator('[name="starter_code"]');
          await template.fill('print(1 + 2)');
          await template.evaluate(node => node.setSelectionRange(6, 11));
          await page.locator('[data-ojp-add-blank]').click();
          const before = (await readSchema()).blanks;
          assert.equal(before.length, 1); checks++;
          await select.selectOption('cpp');
          assert.deepEqual((await readSchema()).blanks, before); checks++;
          assert.equal((await readSchema()).language_key, 'cpp'); checks++;
          await mode('full_code').locator('..').click();
          assert.equal(await select.isDisabled(), true); checks++;
          assert.equal(await select.evaluate(node => node.required), false); checks++;
          await mode('code_completion').locator('..').click();
          assert.equal(await select.inputValue(), 'cpp'); checks++;
          const submitted = await select.evaluate(node => {
            const form = node.form;
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            const data = new FormData(form);
            return { key: data.get('completion_language_key'), schema: JSON.parse(data.get('code_completion_schema')) };
          });
          assert.equal(submitted.key, 'cpp'); assert.equal(submitted.schema.language_key, 'cpp'); checks += 2;
        } else if (key === 'unsupported') {
          assert.equal(await select.inputValue(), ''); checks++;
          assert.match(await select.locator('option:checked').innerText(), /다시 선택/); checks++;
          assert.equal(await select.evaluate(node => node.checkValidity()), false); checks++;
        } else {
          assert.equal(await select.inputValue(), key); checks++;
          assert.equal(await schema.inputValue(), fixture.schema); checks++;
          assert.equal(await select.locator('option').count(), 9); checks++;
          const labels = await select.locator('option').allTextContents();
          assert.equal(new Set(labels).size, labels.length); checks++;
        }
        await select.scrollIntoViewIfNeeded();
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false); checks++;
        assert.deepEqual(errors, []); checks++;
        if (process.env.OJ_COMPLETION_SCREENSHOTS && key === 'new') {
          await select.locator('..').screenshot({ path: path.join(process.env.OJ_COMPLETION_SCREENSHOTS, `completion-language-${width}.png`) });
        }
        await page.close();
      }
    }
    process.stdout.write(`Completion language browser: ${checks} checks passed.\n`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
