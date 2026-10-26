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
    await page.goto(`${pathToFileURL(path.resolve('tests/fixtures/student-assignments-visual.html')).href}?mode=detail`);
    await page.locator('[data-action="start"]').click();
    const file = page.locator('[data-response-kind="file_upload"] input[type="file"]');
    await page.waitForFunction(() => !document.querySelector('[data-response-kind="file_upload"] input').disabled);
    await file.setInputFiles({ name: 'evidence.txt', mimeType: 'text/plain', buffer: Buffer.from('student evidence') });
    await page.waitForFunction(() => window.__studentAutosaveRequests.some((request) => request.body.payload.staged_attachment_ids.length === 1));
    const upload = await page.evaluate(() => window.__studentAttachmentRequests[0]);
    assert.equal(upload.headers['Idempotency-Key'], upload.fields.command_id);
    assert.equal(upload.headers['Content-Type'], undefined, 'browser must supply multipart boundary');
    assert.equal(upload.fields.role, 'file');
    assert.equal(upload.fields.expected_current_revision_id, '00000000000000000000000201', 'flush current answers before uploading');
    assert.equal(upload.fields.expected_lock_version, '1');
    assert.deepEqual(Object.keys(upload.fields).sort(), ['command_id', 'expected_current_revision_id', 'expected_lock_version', 'expected_publication_hash', 'expected_save_token', 'expected_schedule_hash', 'expected_schedule_version', 'expected_state', 'response_id', 'role'].sort());
    const section = page.locator('[data-response-kind="file_upload"]');
    await section.getByRole('button', { name: '삭제', exact: true }).click();
    await page.waitForFunction(() => {
      const answers = window.__studentAutosaveRequests.at(-1)?.body.payload.answers || {};
      return !Object.values(answers).some((value) => Array.isArray(value) && value.includes('00000000000000000000000700'));
    });
    await page.evaluate(() => { window.__studentAttachmentMode = 'network-after-commit'; });
    await file.setInputFiles({ name: 'retry.txt', mimeType: 'text/plain', buffer: Buffer.from('retry evidence') });
    await section.getByRole('button', { name: '업로드 다시 시도', exact: true }).click();
    await page.waitForFunction(() => window.__studentAutosaveRequests.at(-1)?.body.payload.staged_attachment_ids.includes('00000000000000000000000701'));
    const requests = await page.evaluate(() => window.__studentAttachmentRequests.slice(-2));
    assert.equal(requests[0].fields.command_id, requests[1].fields.command_id, 'uncertain upload must reuse receipt identity');
    assert.deepEqual(requests[0].fields, requests[1].fields);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    assert.deepEqual(errors, []);
    await page.screenshot({ path: `tests/artifacts/student-attachments-${width}.png`, fullPage: true });
    console.log(`PASS student attachments ${width}: multipart, scan, revision binding, removal, replay, layout`);
    await page.close();
    for (const delayedPath of ['/attachments', '/commands/autosave']) {
      const delayed = await browser.newPage({ viewport: { width, height: 900 } });
      delayed.on('pageerror', (error) => errors.push(error.message));
      await delayed.goto(`${pathToFileURL(path.resolve('tests/fixtures/student-assignments-visual.html')).href}?mode=detail`);
      await delayed.locator('[data-action="start"]').click();
      await delayed.waitForFunction(() => !document.querySelector('[data-response-kind="file_upload"] input').disabled);
      await delayed.evaluate((suffix) => {
        const fetch = window.fetch;
        window.fetch = async (url, options = {}) => {
          const response = await fetch(url, options);
          if (options.method === 'POST' && String(url).endsWith(suffix)) {
            await new Promise((resolve) => { window.__releaseDelayed = resolve; });
            window.__delayedReturned = true;
          }
          return response;
        };
      }, delayedPath);
      if (delayedPath === '/attachments') {
        await delayed.locator('[data-response-kind="file_upload"] input').setInputFiles({ name: 'late.txt', mimeType: 'text/plain', buffer: Buffer.from('old assignment') });
      } else {
        await delayed.locator('[data-response-id="response-short"] input').fill('old assignment answer');
      }
      await delayed.waitForFunction(() => typeof window.__releaseDelayed === 'function');
      await delayed.evaluate(() => {
        const root = document.getElementById('oj-ai-student-assignment-app');
        const assignment = JSON.parse(root.dataset.assignment);
        assignment.id = '00000000000000000000000999';
        assignment.submission = null;
        root.dataset.assignment = JSON.stringify(assignment);
        root.dispatchEvent(new CustomEvent('oj-ai:student-assignment-detail', { detail: { assignment } }));
        window.__releaseDelayed();
      });
      await delayed.waitForFunction(() => window.__delayedReturned);
      await delayed.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      await assert.doesNotReject(() => delayed.getByText('시작하면 자동 저장됩니다', { exact: true }).waitFor());
      assert.equal(await delayed.locator('[data-response-kind="file_upload"] input').isDisabled(), true);
      assert.deepEqual(errors, []);
      console.log(`PASS late ${delayedPath} ${width}: new assignment state preserved`);
      await delayed.close();
    }
  }
} finally { await browser.close(); }
