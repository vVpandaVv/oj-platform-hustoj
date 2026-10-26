const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
  const root = path.resolve(__dirname, '../..');
  const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', ['tests/Contract/addon-submission-draft-runtime.php', '--render'], {cwd:root, encoding:'utf8'}));
  const original = fixture.source.replace(/\r\n?/g, '\n');
  const nextKey = 'oj_submission_handoff_' + 'b'.repeat(64);
  const browser = await chromium.launch({headless:true, channel:'chrome'});
  try {
    const page = await browser.newPage({viewport:{width:1280,height:900}});
    const failures = [];
    const sent = [];
    page.on('pageerror', error => failures.push(error.message));
    page.on('request', request => { if (request.method() === 'POST') { sent.push(request.url()); } });
    await page.addInitScript(() => {
      if (!sessionStorage.getItem('fixture_initialized')) {
        sessionStorage.setItem('oj_submission_source_100', 'UNRELATED_OLD_DRAFT');
        sessionStorage.setItem('fixture_initialized', '1');
      }
    });
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      assert.equal(url.hostname, 'draft.test', 'the browser fixture makes no remote requests');
      if (url.pathname === '/resources/ui/js/oj-ui.js') {
        return route.fulfill({contentType:'text/javascript', body:fs.readFileSync(path.join(root, 'resources/ui/js/oj-ui.js'))});
      }
      let html = fixture.html;
      if (url.pathname === '/other-ticket') { html = html.replace(fixture.storage_key, nextKey); }
      if (url.pathname === '/ordinary') {
        html = html.replace(/ data-oj-handoff-storage="[^"]+"/, '').replace(/(<textarea[^>]*data-oj-source-editor[^>]*>)[\s\S]*?(<\/textarea>)/, '$1$2');
      }
      return route.fulfill({contentType:'text/html', body:'<!doctype html><html lang="ko"><meta charset="UTF-8"><body>' + html + '<script src="/resources/ui/js/oj-ui.js" defer></script></body></html>'});
    });
    const open = async (pathname = '/') => {
      await page.goto('http://draft.test' + pathname);
      await page.waitForFunction(() => document.querySelector('[data-oj-source-editor]') && document.readyState === 'complete');
    };
    const source = page.locator('[data-oj-source-editor]');
    const language = page.locator('[data-oj-language-select]');
    const save = value => page.evaluate(({key,value}) => sessionStorage.setItem(key, value), {key:fixture.storage_key,value});
    const stored = () => page.evaluate(key => JSON.parse(sessionStorage.getItem(key)), fixture.storage_key);
    await open();
    assert.equal(await source.inputValue(), original, 'HTML parsing preserves leading/trailing blank lines, tabs and Korean, with normal CRLF to LF conversion');
    assert.equal(await language.inputValue(), '6');
    assert.equal(await page.locator('input[name="submission_mode"][value="code"]').isChecked(), true);
    assert.equal(await page.locator('script').count(), 1, 'HTML-like source remains textarea text');
    assert.equal(await page.locator('textarea[name="source"]').count(), 1);
    assert.equal(await page.locator('[name="_oj_nonce"]').inputValue(), 'native-form-nonce');
    assert.equal(await page.evaluate(() => JSON.stringify({...localStorage}).includes('한글')), false, 'the transferred source is absent from persistent localStorage');
    const edited = '\n\t// 수정 </textarea></script>\nint main() {}\n\n';
    await source.fill(edited);
    await language.selectOption('1');
    assert.deepEqual(await stored(), {source:edited,language_id:'1'}, 'handoff edits store source and selected language together');
    await page.reload();
    assert.equal(await source.inputValue(), edited);
    assert.equal(await language.inputValue(), '1', 'refresh restores the edited C++ language rather than the initial Python language');
    await language.selectOption('3');
    await page.reload();
    assert.deepEqual(await stored(), {source:edited,language_id:'3'});
    assert.equal(await language.inputValue(), '3', 'language-only changes are saved immediately');

    await save(JSON.stringify({source:'DISALLOWED_LANGUAGE_DRAFT',language_id:'999'}));
    await page.reload();
    assert.equal(await source.inputValue(), original);
    assert.equal(await language.inputValue(), '6', 'a language absent from the current allowed options cannot restore its code');
    await save('{invalid JSON');
    await page.reload();
    assert.equal(await source.inputValue(), original, 'malformed tab recovery cannot overwrite the valid server prefill');
    await save(JSON.stringify({source:edited,language_id:'1'}));
    await open('/other-ticket');
    assert.equal(await source.inputValue(), original, 'another ticket cannot restore the prior actor/context/problem/language scoped draft');
    assert.equal(await language.inputValue(), '6');
    assert.equal(await page.evaluate(key => sessionStorage.getItem(key), nextKey), null);
    assert.equal(await page.evaluate(() => sessionStorage.getItem('oj_submission_source_100')), 'UNRELATED_OLD_DRAFT', 'handoff recovery never overwrites the ordinary draft key');
    assert.equal(sent.length, 0, 'preparing, opening, editing and refreshing never submit');
    const final = await page.locator('[data-oj-submission-form]').evaluate(form => {
      let count = 0;
      form.addEventListener('submit', event => { event.preventDefault(); count++; });
      form.querySelector('button[type="submit"]').click();
      const data = new FormData(form);
      return {count,source:data.get('source'),language:data.get('language_id'),action:data.get('action'),nonce:data.get('_oj_nonce')};
    });
    assert.deepEqual(final, {count:1,source:original,language:'6',action:'oj_submit_submission',nonce:'native-form-nonce'}, 'only an explicit click submits through the original native form contract');

    await open('/ordinary');
    assert.equal(await source.inputValue(), 'UNRELATED_OLD_DRAFT', 'ordinary problem draft restoration remains compatible');
    await source.fill('ordinary plain draft');
    await page.waitForFunction(() => sessionStorage.getItem('oj_submission_source_100') === 'ordinary plain draft');
    assert.equal(await page.evaluate(() => sessionStorage.getItem('oj_submission_source_100')), 'ordinary plain draft', 'the existing ordinary key keeps its plain source format');
    assert.deepEqual(failures, []);
    assert.equal(sent.length, 0);
    console.log('Submission draft browser: Unicode/HTML/blank lines, manual-only submit, source-language refresh, invalid language, ticket isolation and ordinary draft compatibility passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
