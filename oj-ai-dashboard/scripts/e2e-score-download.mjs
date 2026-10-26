import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';
import { installBulkFixture } from '../tests/fixtures/submission-bulk-review-report.mjs';

const root = new URL('../', import.meta.url);
const markup = execFileSync(process.env.OJAI_PHP || 'php', ['tests/Contract/submission-bulk-review-report.php'], {
  cwd: fileURLToPath(root), env: { ...process.env, OJAI_BULK_FIXTURE: '1' }, encoding: 'utf8', maxBuffer: 1024 * 1024,
});
const html = `<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/resources/css/admin-shell.css"><link rel="stylesheet" href="/resources/css/submission-evaluation-review-workbench.css"><link rel="stylesheet" href="/resources/css/worksheet-workflow.css">
<style>body{margin:0;font:14px Arial,"Malgun Gothic",sans-serif;background:#fff}main{max-width:1280px;margin:auto;padding:20px}.oj-ai-icon{width:18px;height:18px;display:inline-block;vertical-align:middle;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}@media(max-width:600px){main{padding:8px}}</style></head>
<body><main id="fixture" class="oj-ai-assessment-surface">${markup}</main><script>const app=document.querySelector('#oj-ai-submission-review-app');document.querySelector('#fixture').replaceChildren(app);app.dataset.iconSprite='/resources/icons/lucide.svg';</script>
<script src="/resources/js/worksheet-workflow.js"></script><script src="/resources/js/learner-labels.js"></script><script src="/resources/js/submission-evaluation-review-workbench.js"></script></body></html>`;
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const artifacts = new URL('../tests/artifacts/', import.meta.url);
await mkdir(artifacts, { recursive: true });
const button = '[data-review-action="download-scores"]';
const dialog = '[data-review-role="score-download-dialog"]';
const confirm = '[data-review-action="confirm-score-download"]';
const modeSelect = '[data-review-field="score-download-mode"]';
const status = '[data-review-role="score-download-status"]';
async function pageFor(viewport, stage = 'assessment') {
  const page = await browser.newPage({ viewport, acceptDownloads: true });
  const errors = [];
  const downloads = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('download', download => downloads.push(download));
  await page.route('https://example.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.startsWith('/resources/')) return route.fulfill({ body: await readFile(new URL('.' + path, root)),
      contentType: path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'image/svg+xml' });
    return route.fulfill({ contentType: 'text/html', body: html });
  });
  await page.addInitScript(installBulkFixture, { stage });
  await page.addInitScript(() => {
    const base = window.fetch;
    window.__downloadMode = 'normal';
    window.__downloadResolvers = [];
    window.fetch = async (url, options = {}) => {
      const path = new URL(url).pathname;
      if (window.__downloadMode === 'bad-page' && new URL(url).searchParams.has('cursor')) {
        return { ok: true, status: 200, json: async () => ({ data: {} }) };
      }
      if (path.includes('/submission-evaluations/')) {
        if (window.__downloadMode === 'denied') return { ok: false, status: 403, json: async () => ({ message: '권한이 변경되었습니다.', data: { status: 403 } }) };
        if (window.__downloadMode === 'slow') await new Promise(resolve => window.__downloadResolvers.push(resolve));
      }
      const result = await base(url, options);
      const payload = await result.json();
      const identities = payload.data?.items || [];
      if (payload.data?.evaluation) identities.push(payload.data.evaluation);
      for (const row of identities) {
        if (row.learner) row.learner.labels = { management_name: `관리 ${row.learner.user_id}`, login: `0010${row.learner.user_id}`, display_name: row.learner.display_name };
      }
      const detail = payload.data;
      if (detail?.evaluation?.id === 'evaluation-4') {
        detail.evaluation.review = { official_scope: 'this_evaluation', official_id: 'official-4' };
        detail.reviews = [{ id: 'official-4', is_official: true, decision: 'adjusted', official_score: '18', review_no: 1, result_id: 'result-4' }];
      }
      if (detail?.evaluation?.id === 'evaluation-1') detail.evaluation.candidate.score = '0';
      if (detail?.evaluation && window.__downloadMode === 'empty') detail.evaluation.state = 'failed';
      return { ...result, json: async () => payload };
    };
  });
  await page.goto(`https://example.test/scores?stage=${stage}&assignment_id=assignment-1&context_type=group&context_id=group%3A10&context_revision=membership-4`);
  await page.waitForFunction(() => document.querySelector('[data-review-field="assignment"] option[value="0"]') && !document.querySelector('[data-review-field="assignment"]').disabled);
  if (await page.locator('[data-review-field="assignment"]').inputValue() !== '0') await page.locator('[data-review-field="assignment"]').selectOption('0');
  if (stage === 'assessment') await page.waitForFunction(() => !document.querySelector('[data-review-action="download-scores"]').disabled);
  return { page, errors, downloads };
}
async function downloadCsv(page, onlyOfficial = false) {
  await page.locator(button).click();
  await page.locator(modeSelect).selectOption(onlyOfficial ? 'official' : 'all');
  const pending = page.waitForEvent('download');
  await page.locator(confirm).click();
  const download = await pending;
  const bytes = await readFile(await download.path());
  assert.notEqual(bytes.subarray(0, 3).toString('hex'), 'efbbbf');
  assert.match(download.suggestedFilename(), /^점수_.*\.csv$/);
  return bytes.toString('utf8');
}
try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const { page, errors } = await pageFor(viewport);
    await page.locator('[data-review-field="search"]').fill('검색에 없는 학생');
    await page.locator(button).click();
    await page.screenshot({ path: fileURLToPath(new URL(`score-download-${name}.png`, artifacts)) });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    assert.equal(await page.locator(dialog).evaluate(e => e.scrollWidth > e.clientWidth + 1), false);
    const bounds = await page.locator(confirm).boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= viewport.width && bounds.y + bounds.height <= viewport.height);
    await page.locator(dialog + ' button[value="cancel"]').last().click();
    const csv = await downloadCsv(page);
    assert.equal(csv.split('\r\n').filter(Boolean).length, 4, 'Three eligible scores, despite the empty search.');
    assert.ok(csv.includes('"0.0000","","20.0000","검토 대기"'));
    assert.ok(csv.includes('"16.0000","18.0000","20.0000","확정"'));
    assert.ok(csv.includes('"관리 1","00101","학생 1"'));
    assert.ok(!csv.includes('학생 3') && !csv.includes('학생 5') && !csv.includes('학생 6'));
    const official = await downloadCsv(page, true);
    assert.equal(official.split('\r\n').filter(Boolean).length, 2);
    assert.ok(official.includes('학생 4'));
    assert.equal(await page.evaluate(() => window.__bulkPosts.length), 0);
    assert.deepEqual(errors, []);
    await page.close();
  }
  for (const mode of ['denied', 'bad-page', 'empty', 'cancel', 'pagehide']) {
    const { page, errors, downloads } = await pageFor({ width: 1280, height: 900 });
    await page.locator(button).click();
    await page.evaluate(mode => { window.__downloadMode = ['cancel', 'pagehide'].includes(mode) ? 'slow' : mode; }, mode);
    await page.locator(confirm).click();
    if (['cancel', 'pagehide'].includes(mode)) {
      await page.waitForFunction(() => window.__downloadResolvers.length > 0);
      assert.equal(await page.locator(confirm).isDisabled(), true);
      if (mode === 'cancel') await page.keyboard.press('Escape');
      else await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
      await page.waitForFunction(() => !document.querySelector('[data-review-action="download-scores"]').disabled);
      await page.evaluate(() => { window.__downloadMode = 'normal'; window.__downloadResolvers.forEach(resolve => resolve()); window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); });
    } else {
      await page.waitForFunction(() => !document.querySelector('[data-review-action="confirm-score-download"]').disabled);
      assert.ok(await page.locator(status).textContent());
      await page.locator(dialog + ' button[value="cancel"]').last().click();
      await page.evaluate(() => { window.__downloadMode = 'normal'; });
    }
    assert.equal(downloads.length, 0, 'No partial or cancelled file may be downloaded.');
    const csv = await downloadCsv(page);
    assert.equal(downloads.length, 1);
    assert.equal(csv.split('\r\n').filter(Boolean).length, 4);
    assert.equal(await page.evaluate(() => window.__bulkPosts.length), 0);
    assert.deepEqual(errors, []);
    await page.close();
  }
  const { page, errors } = await pageFor({ width: 1280, height: 900 }, 'report');
  assert.equal(await page.locator(button).isHidden(), true);
  assert.deepEqual(errors, []);
  await page.close();
  console.log('PASS score download browser: production markup, desktop/mobile, actual UTF-8 CSV, AI/official scores, whole assignment, exclusions, permission/pagination errors, cancel/retry and BFCache recovery; no mutations.');
} finally { await browser.close(); }
