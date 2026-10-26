import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const contract = spawnSync(process.env.OJAI_PHP || 'php', ['tests/Contract/question-section-rendering.php'], { encoding: 'utf8' });
assert.equal(contract.status, 0, contract.stdout + contract.stderr);
console.log(contract.stdout.trim());
const output = path.resolve('tests/artifacts/question-sections');
await mkdir(output, { recursive: true });
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const results = [];
try {
  for (const width of [1280, 390]) {
    for (const name of ['student', 'preview', 'print-preview', 'worksheet-print', 'submission-print']) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      const isStudent = name === 'student';
      const isPreview = name.includes('preview');
      const file = isStudent ? 'tests/fixtures/student-assignments-visual.html'
        : isPreview ? 'tests/fixtures/worksheet-workbench-visual.html' : `tests/artifacts/question-sections/${name}.html`;
      await page.goto(`${pathToFileURL(path.resolve(file)).href}${isStudent || isPreview ? '?mode=detail&question_sections=1' : ''}`);
      if (isPreview) {
        await page.waitForFunction(() => document.querySelector('#ws-template-title')?.value);
        await page.locator('.oj-ai-workbench__more > summary').click();
        await page.getByRole('button', { name: '미리보기', exact: true }).click();
        if (name === 'print-preview') await page.getByRole('tab', { name: '인쇄', exact: true }).click();
      }
      const selector = isStudent ? '.oj-ai-student-document__question' : isPreview ? '.oj-ai-workbench__preview-question' : '.question';
      const section = page.locator(selector);
      await section.waitFor({ state: 'visible' });
      assert.equal(await section.count(), 1);
      const state = await section.evaluate(el => {
        const style = getComputedStyle(el);
        const rect = el.getBoundingClientRect();
        const quote = el.querySelector('blockquote');
        return { tag: el.tagName, background: style.backgroundColor, left: style.borderLeftWidth,
          top: style.borderTopWidth, fontStyle: style.fontStyle, shadow: style.boxShadow,
          quoteTag: quote?.tagName, quoteBackground: quote ? getComputedStyle(quote).backgroundColor : null,
          quoteBorder: quote ? getComputedStyle(quote).borderLeftWidth : null,
          fits: rect.width <= innerWidth && rect.left >= 0,
          responses: el.querySelectorAll('fieldset, section.response').length,
          noticeAncestors: el.closest('aside') !== null,
        };
      });
      assert.equal(state.tag, 'SECTION');
      assert.equal(state.background, 'rgb(255, 255, 255)');
      assert.equal(state.left, '0px');
      assert.equal(state.top, '1px');
      assert.equal(state.fontStyle, 'normal');
      assert.equal(state.shadow, 'none');
      assert.equal(state.quoteTag, 'BLOCKQUOTE');
      assert.equal(state.responses, isPreview ? 1 : 10);
      assert.equal(state.noticeAncestors, false);
      assert.ok(state.fits);
      if (!isStudent) {
        assert.equal(state.quoteBorder, '3px');
        assert.notEqual(state.quoteBackground, state.background);
      }
      assert.deepEqual(errors, []);
      await section.screenshot({ path: path.join(output, `${name}-${width}.png`) });
      results.push({ name, width, ...state });
      console.log(`PASS ${name} ${width}: answer section and real quotation remain distinct`);
      await page.close();
    }
  }
} finally { await browser.close(); }
await writeFile(path.join(output, 'result.json'), JSON.stringify({ passed: true, results }, null, 2));
