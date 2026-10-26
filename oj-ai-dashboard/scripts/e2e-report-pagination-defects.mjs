import assert from 'node:assert/strict';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(pathToFileURL(path.resolve('tests/fixtures/report-dashboard-visual.html')).href);
    await page.waitForFunction(() => document.querySelectorAll('.oj-ai-report__table tbody tr').length === 18);
    await page.evaluate(() => {
      const original = window.fetch;
      window.__reportPages = [];
      window.__reportPageFailure = false;
      window.fetch = async (url, options) => {
        const response = await original(url, options);
        const request = new URL(url);
        if (!request.pathname.endsWith('/reports')) return response;
        const body = await response.json();
        const cursor = request.searchParams.get('cursor') || '';
        window.__reportPages.push(cursor);
        if (cursor && window.__reportPageFailure) return { ok: false, status: 403, async json() { return { code: 'forbidden', message: '보고서 접근 권한이 변경되었습니다.' }; } };
        const sample = body.data.items[0];
        const start = cursor ? 25 : 0;
        body.data.items = Array.from({ length: cursor ? 6 : 25 }, (_, offset) => ({
          ...sample, worksheet: { ...sample.worksheet, id: `paging-${start + offset}`, title: `활동지 ${start + offset}` },
          progress: { ...sample.progress, target_count: 30 },
        }));
        body.data.metric_contract = 'oj_ai.reporting_metrics@1.0.0';
        body.data.baseline_at_utc = cursor ? '2026-09-08 01:00:02' : '2026-09-08 01:00:01';
        body.meta.next_cursor = cursor ? '' : 'page-2';
        return { ok: true, status: 200, async json() { return body; } };
      };
    });
    await page.getByRole('tab', { name: '배포 대상별', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('.oj-ai-report__table')?.textContent.includes('31개'));
    assert.match(await page.locator('.oj-ai-report__table').innerText(), /930명/);
    assert.deepEqual(await page.evaluate(() => window.__reportPages), ['', 'page-2']);
    assert.match(await page.locator('caption').innerText(), /조회 시각/);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    await page.evaluate(() => { window.__reportPageFailure = true; });
    await page.getByRole('button', { name: '조회', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('#oj-ai-report-app').dataset.access === 'denied');
    assert.equal(await page.locator('.oj-ai-report__table').count(), 0, 'do not leave partial totals after permission loss on page two');
    assert.deepEqual(errors, []);
    console.log(`PASS group report ${width}: 31 rows, 930 assignments, changing query time, page-two revocation clears totals`);
    await page.close();
  }
} finally { await browser.close(); }
