import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
await mkdir(path.join(root, 'tests/artifacts'), { recursive: true });
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(pathToFileURL(path.join(root, 'tests/fixtures/assignment-workbench-visual.html')).href);
    await page.evaluate(() => {
      const canvas = document.createElement('canvas'); canvas.width = 480; canvas.height = 220;
      const context = canvas.getContext('2d'); context.fillStyle = '#fff'; context.fillRect(0, 0, 480, 220);
      context.fillStyle = '#202020'; context.font = '64px sans-serif'; context.fillText('5 - 2 = 3', 35, 125);
      const bytes = Uint8Array.from(atob(canvas.toDataURL('image/png').split(',')[1]), character => character.charCodeAt(0));
      window.__attachmentBytes = bytes;
      window.__attachmentRequests = [];
      window.__lifecycleRequests = [];
      window.__denyAttachment = true;
      const original = window.fetch;
      window.fetch = async (url, options = {}) => {
        const value = String(url);
        if (value.includes('/attachments/') && value.includes('/content?')) {
          window.__attachmentRequests.push({ url: value, options });
          if (window.__denyAttachment) return new Response('{}', { status: 403 });
          if (window.__holdAttachment) await new Promise(resolve => { window.__releaseAttachment = resolve; });
          return new Response(bytes, { headers: { 'X-OJ-AI-File-Type': 'image/png', 'Content-Type': 'application/octet-stream' } });
        }
        if (value.includes('/commands/withdraw?')) {
          window.__lifecycleRequests.push(JSON.parse(options.body));
          return new Response(JSON.stringify({ data: {} }), { status: 200 });
        }
        if (/\/assignments\/[^/?]+\/submissions\?/.test(value) && window.__holdSubmissions) {
          await new Promise(resolve => { window.__releaseSubmissions = resolve; });
        }
        const response = await original(url, options);
        if (/\/assignments\/[^/?]+\/submissions\?/.test(value) && window.__holdSubmissions) window.__submissionsReturned = true;
        if (value.includes('/assignments?')) {
          const body = await response.json();
          body.data.items.forEach(item => { item.actions = ['assignment.withdraw']; });
          return new Response(JSON.stringify(body), { status: 200 });
        }
        if (/\/submissions\/[^/?]+\?/.test(value)) {
          const body = await response.json();
          const submission = body.data.submission;
          submission.attachments = [{ id: '01HZY3M4N5P6Q7R8S9T0V1W2Y1', name: 'drawing.png', mime_type: 'image/png', byte_size: bytes.length, state: 'bound', revision_scope: ['official'] }];
          submission.official_revision.response.answers.answer_2 = { preview_attachment_id: submission.attachments[0].id };
          return new Response(JSON.stringify(body), { status: 200 });
        }
        return response;
      };
      const revoke = URL.revokeObjectURL;
      window.__revokedAttachments = [];
      URL.revokeObjectURL = url => { window.__revokedAttachments.push(url); revoke(url); };
    });
    await page.selectOption('[data-field="context-filter"]', '0');
    await page.getByRole('button', { name: '지난 배포', exact: true }).click();
    await page.getByRole('button', { name: '제출 현황', exact: true }).click();
    await page.getByRole('button', { name: '보기', exact: true }).click();
    const assessmentHref = new URL(await page.getByRole('link', { name: '평가에서 확인', exact: true }).getAttribute('href'));
    assert.equal(assessmentHref.searchParams.get('context_id'), 'class-21', 'Copied and new-tab URLs retain class context');
    assert.ok(assessmentHref.searchParams.get('assignment_id'), 'Actual assessment href retains its deployment');
    assert.equal(assessmentHref.searchParams.get('stage'), 'assessment');
    const preview = page.getByRole('button', { name: 'drawing.png 그림 보기', exact: true });
    await preview.click();
    await page.getByText('첨부파일을 열 수 없습니다. 제출 내용을 새로 불러와 주세요.', { exact: true }).waitFor();
    assert.equal(await page.locator('.oj-ai-assignment__attachment-preview').count(), 0);
    await page.evaluate(() => { window.__denyAttachment = false; });
    await preview.click();
    await page.locator('.oj-ai-assignment__attachment-preview').waitFor();
    assert.equal(await page.locator('.oj-ai-assignment__attachment-preview').evaluate(image => image.naturalWidth), 480);
    const request = await page.evaluate(() => window.__attachmentRequests.at(-1));
    assert.equal(request.options.cache, 'no-store');
    assert.equal(request.options.credentials, 'same-origin');
    assert.ok(request.options.headers['X-WP-Nonce']);
    assert.match(request.url, /context_id=class-21/);
    assert.ok(await page.locator('.oj-ai-assignment__attachment-preview').evaluate(image => image.getBoundingClientRect().width <= image.parentElement.getBoundingClientRect().width + 1));
    await page.locator('[data-role="submission-detail"]').screenshot({ path: path.join(root, `tests/artifacts/teacher-attachment-${width}.png`) });
    await page.getByRole('button', { name: '제출 목록', exact: true }).click();
    assert.equal(await page.evaluate(() => window.__revokedAttachments.length), 1);
    await page.getByRole('button', { name: '보기', exact: true }).click();
    await page.evaluate(() => { window.__holdAttachment = true; });
    await preview.click();
    await page.waitForFunction(() => typeof window.__releaseAttachment === 'function');
    await page.getByRole('button', { name: '제출 목록', exact: true }).click();
    await page.evaluate(() => window.__releaseAttachment());
    await page.waitForFunction(() => !document.querySelector('[aria-label="drawing.png 그림 보기"]').disabled);
    assert.equal(await page.locator('.oj-ai-assignment__attachment-preview').count(), 0, 'late file cannot reappear after leaving');
    await page.locator('[data-action="close-submissions"]').click();
    await page.getByRole('button', { name: '지난 배포', exact: true }).click();
    await page.evaluate(() => { window.__holdSubmissions = true; });
    await page.getByRole('button', { name: '제출 현황', exact: true }).click();
    await page.waitForFunction(() => typeof window.__releaseSubmissions === 'function');
    await page.locator('[data-action="close-submissions"]').click();
    const beforeLate = await page.evaluate(() => ({url:location.href,title:document.querySelector('[data-role="submission-title"]').textContent}));
    await page.evaluate(() => window.__releaseSubmissions());
    await page.waitForFunction(() => window.__submissionsReturned);
    assert.equal(await page.locator('[data-role="submission-panel"]').isVisible(), false);
    assert.deepEqual(await page.evaluate(() => ({url:location.href,title:document.querySelector('[data-role="submission-title"]').textContent})), beforeLate, 'Closed submission queries cannot restore previous navigation scope');
    const withdraw = page.locator('[data-role="assignment-detail"] [data-command="withdraw"]');
    await withdraw.click();
    const dialog = page.getByRole('dialog', { name: '배포를 철회하시겠습니까?' });
    await dialog.waitFor();
    assert.equal(await page.evaluate(() => document.activeElement.textContent), '취소');
    await page.keyboard.press('Escape');
    assert.equal(await page.evaluate(() => window.__lifecycleRequests.length), 0);
    await withdraw.click();
    await dialog.getByRole('button', { name: '취소', exact: true }).click();
    assert.equal(await page.evaluate(() => window.__lifecycleRequests.length), 0);
    await withdraw.click();
    const dimensions = await dialog.boundingBox();
    assert.ok(dimensions.x >= 0 && dimensions.x + dimensions.width <= width);
    await dialog.screenshot({ path: path.join(root, `tests/artifacts/assignment-confirm-${width}.png`) });
    await dialog.getByRole('button', { name: '배포 철회', exact: true }).click();
    await page.waitForFunction(() => window.__lifecycleRequests.length === 1);
    assert.equal((await page.evaluate(() => window.__lifecycleRequests[0])).expected.expected_state, 'closed');
    assert.deepEqual(errors, []);
    await page.close();
    console.log(`Teacher attachment and lifecycle checks passed: ${width}px`);
  }
} finally { await browser.close(); }
