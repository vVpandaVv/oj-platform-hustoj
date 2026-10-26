import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const { chromium } = await loadPlaywright();
const output = path.resolve('tests/artifacts/answer-surfaces');
await mkdir(output, { recursive: true });
const browser = await chromium.launch(browserLaunchOptions());
const results = [];
const cases = [
  { name: 'student', fixture: 'student-assignments-visual.html?mode=detail', surface: '.oj-ai-student-response-anchor', text: '.oj-ai-student-response-label' },
  { name: 'teacher', fixture: 'submission-evaluation-review-workbench-visual.html', surface: '.oj-ai-submission-review__response', text: '.oj-ai-submission-review__answer' },
  { name: 'preview', fixture: 'worksheet-workbench-visual.html', surface: '.oj-ai-workbench__preview-response', text: 'legend' },
];

try {
  for (const width of [1280, 390]) {
    for (const item of cases) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      const [file, query = ''] = item.fixture.split('?');
      await page.goto(`${pathToFileURL(path.resolve('tests/fixtures', file)).href}${query ? `?${query}` : ''}`);
      if (item.name === 'preview') {
        await page.waitForFunction(() => document.querySelector('#ws-template-title')?.value);
        await page.locator('.oj-ai-workbench__more > summary').click();
        await page.getByRole('button', { name: '미리보기', exact: true }).click();
      }
      const surface = page.locator(item.surface).first();
      await surface.waitFor({ state: 'visible' });
      await surface.scrollIntoViewIfNeeded();
      const state = await surface.evaluate((element, textSelector) => {
        const style = getComputedStyle(element);
        const content = element.querySelector(textSelector);
        const textStyle = getComputedStyle(content);
        const rect = element.getBoundingClientRect();
        const linear = (value) => { const v = value / 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
        const channels = textStyle.color.match(/[\d.]+/g).slice(0, 3).map(Number).map(linear);
        const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        return {
          background: style.backgroundColor,
          shadow: style.boxShadow,
          fontStyle: textStyle.fontStyle,
          contrast: 1.05 / (luminance + 0.05),
          fits: rect.left >= -1 && rect.right <= innerWidth + 1,
          borderLeft: parseFloat(style.borderLeftWidth),
          borderRight: parseFloat(style.borderRightWidth),
          bodyOverflow: document.documentElement.scrollWidth > innerWidth + 1,
        };
      }, item.text);
      assert.equal(state.background, 'rgb(255, 255, 255)');
      assert.equal(state.shadow, 'none');
      assert.equal(state.fontStyle, 'normal');
      assert.ok(state.contrast >= 4.5, 'Answer text remains readable on white.');
      assert.equal(state.borderLeft, state.borderRight, 'No quotation-style left accent.');
      assert.ok(state.fits && !state.bodyOverflow);
      assert.deepEqual(errors, []);
      await surface.screenshot({ path: path.join(output, `${item.name}-${width}.png`) });
      results.push({ name: item.name, width, ...state });
      console.log(`PASS ${item.name} ${width}: white answer surface, neutral border, readable text, no overflow`);
      await page.close();
    }
  }
} finally {
  await browser.close();
}
await writeFile(path.join(output, 'result.json'), `${JSON.stringify({ passed: true, results }, null, 2)}\n`);
