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
<link rel="stylesheet" href="/resources/css/admin-shell.css"><link rel="stylesheet" href="/resources/css/submission-evaluation-review-workbench.css"><link rel="stylesheet" href="/resources/css/worksheet-workflow.css"><style>body{margin:0;font:14px Arial,"Malgun Gothic",sans-serif;background:#fff}main{max-width:1280px;margin:auto;padding:20px}.oj-ai-icon{width:18px;height:18px;display:inline-block;vertical-align:middle;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}@media(max-width:600px){main{padding:8px}}</style></head>
<body><main id="fixture" class="oj-ai-assessment-surface">${markup}</main><script>const app=document.querySelector('#oj-ai-submission-review-app');document.querySelector('#fixture').replaceChildren(app);app.dataset.iconSprite='/resources/icons/lucide.svg';</script>
<script src="/resources/js/worksheet-workflow.js"></script><script src="/resources/js/learner-labels.js"></script><script src="/resources/js/submission-evaluation-review-workbench.js"></script></body></html>`;
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const artifacts = new URL('../tests/artifacts/', import.meta.url);
await mkdir(artifacts, { recursive: true });
async function pageFor(stage, viewport, mode = 'normal') {
  const page = await browser.newPage({ viewport });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('https://example.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.startsWith('/resources/')) {
      const contentType = path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'image/svg+xml';
      return route.fulfill({ contentType, body: await readFile(new URL('.' + path, root)) });
    }
    return route.fulfill({ contentType: 'text/html', body: html });
  });
  await page.addInitScript(installBulkFixture, { stage, mode });
  await page.goto(`https://example.test/bulk?stage=${stage}&assignment_id=assignment-1&context_type=group&context_id=group%3A10&context_revision=membership-4`);
  await page.waitForFunction(() => document.querySelector('[data-review-field="assignment"] option[value="0"]') && !document.querySelector('[data-review-field="assignment"]').disabled);
  if (await page.locator('[data-review-field="assignment"]').inputValue() !== '0') {
    assert.equal(await page.locator('[data-workflow-bulk="feedback"]').isDisabled(), true);
    await page.locator('[data-review-field="assignment"]').selectOption('0');
  }
  try {
    await page.waitForFunction(() => document.querySelector('[data-review-field="assignment"]')?.value === '0' && !document.querySelector('[data-review-role="content"]')?.hidden);
  } catch (error) {
    console.error(stage, errors, await page.locator('[data-review-role="notice"]').textContent(), await page.evaluate(() => window.__bulkReads));
    throw error;
  }
  return { page, errors };
}
async function preview(page, action, count) {
  await page.locator(`[data-workflow-bulk="${action}"]`).click();
  await page.waitForFunction(() => !document.querySelector('[data-review-role="workflow-preview-status"]').textContent.includes('중'));
  assert.equal(await page.locator('[data-review-role="workflow-targets"] input').count(), count);
  assert.equal(await page.locator('[data-review-role="workflow-scope"]').textContent().then(t => t.includes('검색·상태 필터와 무관')), true);
}
try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    for (const action of ['accept', 'feedback', 'note']) {
      const { page, errors } = await pageFor(action === 'accept' ? 'assessment' : 'report', viewport);
      await page.locator('[data-review-field="search"]').fill('없는 학생');
      await preview(page, action, 2);
      const confirm = page.locator('[data-workflow-control="confirm"]');
      assert.equal(await confirm.isDisabled(), action === 'accept');
      if (action === 'accept') await page.locator('[data-review-role="workflow-reviewed"]').check();
      await page.locator('[data-review-role="workflow-select-all"]').uncheck();
      assert.equal(await confirm.isDisabled(), true);
      await page.locator('[data-review-role="workflow-select-all"]').check();
      await page.screenshot({ path: fileURLToPath(new URL(`bulk-${action}-${name}.png`, artifacts)) });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
      const dialog = page.locator('[data-review-role="workflow-dialog"]');
      assert.equal(await dialog.evaluate(e => e.scrollWidth > e.clientWidth + 1), false);
      await confirm.click();
      await page.waitForFunction(() => document.querySelector('[data-review-role="workflow-progress-status"]').textContent.includes('처리 완료'));
      const posts = await page.evaluate(() => window.__bulkPosts);
      assert.equal(posts.length, 2);
      assert.ok(posts.every(post => post.key === post.body.command_id));
      assert.ok(posts.every(post => !/approve-feedback|student-notes.*approve|release/.test(post.path)));
      assert.ok((await page.evaluate(() => window.__bulkReads)).some(path => path.includes('cursor=page2')));
      assert.deepEqual(errors, []);
      await page.close();
    }
  }
  for (const mode of ['lost', 'conflict', 'denied', 'slow']) {
    const { page, errors } = await pageFor('assessment', { width: 1280, height: 900 }, mode);
    await preview(page, 'accept', 2);
    await page.locator('[data-review-role="workflow-reviewed"]').check();
    await page.locator('[data-workflow-control="confirm"]').click();
    if (mode === 'slow') {
      await page.waitForFunction(() => Boolean(window.__bulkResolve));
      assert.equal(await page.locator('[data-review-field="context"]').isDisabled(), true);
      await page.locator('[data-workflow-control="stop"]').click();
      await page.evaluate(() => { window.__bulkMode = 'normal'; window.__bulkResolve(); });
    }
    if (mode === 'lost' || mode === 'slow') {
      await page.locator('[data-workflow-control="resume"]:visible').waitFor();
      await page.locator('[data-workflow-control="resume"]').click();
    }
    if (mode === 'denied') {
      await page.locator('[data-workflow-control="dismiss"]:visible').waitFor();
      assert.equal(await page.locator('[data-workflow-control="resume"]').isHidden(), true);
      assert.equal(await page.evaluate(() => window.__bulkPosts.length), 1);
    } else {
      await page.waitForFunction(() => document.querySelector('[data-review-role="workflow-progress-status"]').textContent.includes('처리 완료'));
      const posts = await page.evaluate(() => window.__bulkPosts);
      assert.equal(posts.length, mode === 'lost' ? 3 : 2);
      if (mode === 'lost') assert.deepEqual(posts[0], posts[1]);
      if (mode === 'conflict') assert.match(await page.locator('[data-review-role="workflow-progress-status"]').textContent(), /실패 1명/);
    }
    assert.deepEqual(errors, []);
    await page.close();
  }
  {
    const { page, errors } = await pageFor('assessment', { width: 1280, height: 900 });
    await preview(page, 'accept', 2);
    await page.locator('[data-review-role="workflow-reviewed"]').check();
    await page.locator('[data-review-role="workflow-targets"] input').nth(1).uncheck();
    await page.evaluate(() => window.__bulkMutate('evaluation-1'));
    await page.locator('[data-workflow-control="confirm"]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-role="workflow-progress-status"]').textContent.includes('처리 완료'));
    assert.equal(await page.evaluate(() => window.__bulkPosts.length), 0, 'Changed score result cannot be approved from an old preview.');
    assert.match(await page.locator('[data-review-role="workflow-progress-status"]').textContent(), /제외 1명/);
    assert.deepEqual(errors, []);
    await page.close();
  }
  {
    const { page, errors } = await pageFor('report', { width: 1280, height: 900 });
    const editor = page.locator('[data-feedback-field="overall"]');
    await editor.waitFor();
    await editor.fill('교사가 작성 중인 문장임.');
    await page.locator('[data-workflow-bulk="feedback"]').click();
    assert.equal(await page.locator('[data-review-role="workflow-dialog"]').evaluate(e => e.open), false);
    assert.match(await page.locator('[data-review-role="notice"]').textContent(), /먼저 저장/);
    assert.equal(await editor.inputValue(), '교사가 작성 중인 문장임.');
    assert.equal(await page.evaluate(() => window.__bulkPosts.length), 0);
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log('PASS bulk review/report browser: production markup, desktop/mobile, complete roster, selection, confirmation, queue-only requests, response loss, partial conflicts, permission stop and resume.');
} finally { await browser.close(); }
