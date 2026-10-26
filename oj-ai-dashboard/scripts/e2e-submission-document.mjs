import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = await readFile(path.join(root, 'tests/artifacts/submission-screen.html'), 'utf8');
const artifacts = path.join(root, 'tests/artifacts');
await mkdir(artifacts, { recursive: true });
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
try {
  for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
    const page = await browser.newPage({ viewport, acceptDownloads: true });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto(pathToFileURL(path.join(root, 'tests/fixtures/assignment-workbench-visual.html')).href);
    await page.evaluate(fullHtml => {
      const app = document.querySelector('#oj-ai-assignment-app'); app.dataset.canExport = '1'; app.dataset.actorId = '42';
      const original = window.fetch;
      const jobId = '01HZY3M4N5P6Q7R8S9T0V1W2Y1'; const artifactId = '01HZY3M4N5P6Q7R8S9T0V1W2Y2';
      window.__pdfRequests = []; window.__pdfMode = 'queued'; window.__pdfReads = 0;
      const response = (data, status = 200) => new Response(JSON.stringify({ data }), { status, headers: { 'Content-Type': 'application/json' } });
      window.fetch = async (url, options = {}) => {
        const value = String(url);
        if (value.includes('/view?')) return response({ submission_view: { revision_id: revisionId, submission_id: submissionId, html: fullHtml } });
        if (value.includes('/commands/export-submissions-zip?')) {
          window.__pdfRequests.push({ url: value, body: JSON.parse(options.body), headers: options.headers });
          return response({ export_job: { id: jobId, source_id: assignmentId, artifact_kind: 'submission_bundle', state: 'queued' } }, 202);
        }
        if (value.includes('/export-jobs/')) {
          window.__pdfReads += 1;
          return response({ export_job: { job_id: jobId, source_id: assignmentId, artifact_kind: 'submission_bundle', state: window.__pdfMode, artifact_id: window.__pdfMode === 'ready' ? artifactId : null } });
        }
        if (value.includes('/artifacts/')) return new Response(new Blob(['PK\x05\x06', new Uint8Array(18)], { type: 'application/zip' }), { headers: { 'Content-Type': 'application/zip' } });
        const result = await original(url, options);
        if (value.includes('/assignments/' + assignmentId + '?')) {
          const body = await result.json(); body.data.assignment.content_hash = 'd'.repeat(64); body.data.assignment.schedule_version = 1; return response(body.data);
        }
        if (value.includes('/assignments/' + assignmentId + '/submissions?')) {
          const body = await result.json(); body.data.items[0].submission.official_revision_id = revisionId; return response(body.data, 200);
        }
        return result;
      };
    }, html);
    await page.selectOption('[data-field="context-filter"]', '0');
    await page.getByRole('button', { name: '지난 배포', exact: true }).click();
    await page.getByRole('button', { name: '제출 현황', exact: true }).click();
    await page.getByRole('button', { name: '보기', exact: true }).click();
    await page.getByRole('button', { name: '문제·답안 전체 보기', exact: true }).click();
    const frame = page.frameLocator('.oj-ai-document iframe');
    await frame.locator('.document-title').waitFor();
    assert.match(await frame.locator('main').innerText(), /김하늘/);
    assert.equal(await frame.locator('.response').count(), 10);
    assert.equal(await page.locator('.oj-ai-document iframe').getAttribute('sandbox'), '');
    const overflow = await frame.locator('body').evaluate(node => node.scrollWidth > node.clientWidth + 1);
    assert.equal(overflow, false, 'Full answers must fit the viewport.');
    await page.screenshot({ path: path.join(artifacts, `submission-screen-${viewport.width}.png`) });
    await page.locator('.oj-ai-document').getByRole('button', { name: '닫기', exact: true }).click();
    assert.equal(await page.getByRole('button', { name: '문제·답안 전체 보기' }).evaluate(node => document.activeElement === node), true);
    await page.getByRole('button', { name: '제출 목록', exact: true }).click();
    await page.getByRole('button', { name: 'PDF 일괄 다운로드', exact: true }).click();
    await page.waitForFunction(() => window.__pdfReads > 0);
    // Re-entering the list restores the durable job without another enqueue.
    await page.locator('[data-role="submission-panel"] [data-action="close-submissions"]').click();
    await page.getByRole('button', { name: '제출 현황', exact: true }).click();
    await page.waitForFunction(() => window.__pdfReads > 1);
    assert.equal(await page.evaluate(() => window.__pdfRequests.length), 1);
    await page.evaluate(() => { window.__pdfMode = 'ready'; });
    await page.getByRole('button', { name: 'PDF ZIP 다운로드', exact: true }).waitFor();
    const downloadWait = page.waitForEvent('download');
    await page.getByRole('button', { name: 'PDF ZIP 다운로드', exact: true }).click();
    const download = await downloadWait;
    assert.match(download.suggestedFilename(), /^submissions-.*\.zip$/);
    const request = await page.evaluate(() => window.__pdfRequests[0]);
    assert.equal(request.headers['Idempotency-Key'], request.body.command_id);
    assert.equal(request.body.payload.include_allowed_originals, false);
    assert.equal(request.body.expected.expected_assignment_hash, 'd'.repeat(64));
    assert.equal(request.body.payload.profile, 'submission_bundle.v1');
    assert.equal(await page.evaluate(() => window.__assessmentRequests.length), 0, 'No AI requests.');
    await page.evaluate(() => { window.__pdfMode = 'failed'; });
    await page.getByRole('button', { name: '최신 제출본으로 PDF 다시 생성' }).click();
    await page.getByText('PDF를 준비하지 못했습니다.', { exact: false }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'PDF 일괄 다운로드', exact: true }).isEnabled(), true);
    await page.evaluate(() => {
      const app = document.querySelector('#oj-ai-assignment-app');
      window.OJAISubmissionDocument.bundle(app, document.querySelector('[data-role="submission-bundle"]'), assignments[0], contexts[0], 0);
    });
    assert.equal(await page.getByRole('button', { name: 'PDF 일괄 다운로드', exact: true }).isDisabled(), true);
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log('Submission document browser passed: desktop/mobile full answers, safe iframe, ZIP, resume, failure, empty batch, no AI.');
} finally { await browser.close(); }
