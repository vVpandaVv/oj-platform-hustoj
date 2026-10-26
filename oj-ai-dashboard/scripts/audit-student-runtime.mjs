import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(import.meta.dirname, '..');
const output = path.join(root, 'tests/artifacts/student-runtime-acceptance');
await mkdir(output, { recursive: true });
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const results = [];
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`${pathToFileURL(path.join(root, 'tests/fixtures/student-assignments-visual.html')).href}?mode=detail&rich_document=1`);
    await page.locator('[data-action="start"]').click();
    const short = page.locator('[data-response-id="response-short"] input');
    await short.waitFor();
    await page.waitForFunction(() => !document.querySelector('[data-response-id="response-short"] input').disabled);
    await short.fill('Runtime acceptance baseline');
    await page.waitForFunction(() => document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    const canvas = page.locator('canvas.oj-ai-student-response-drawing');
    await canvas.scrollIntoViewIfNeeded();
    const before = await canvas.evaluate((node) => node.toDataURL());
    const rect = await canvas.boundingBox();
    await page.mouse.move(rect.x + rect.width * 0.2, rect.y + rect.height * 0.3);
    await page.mouse.down();
    await page.mouse.move(rect.x + rect.width * 0.8, rect.y + rect.height * 0.7, { steps: 12 });
    await page.mouse.up();
    const after = await canvas.evaluate((node) => node.toDataURL());
    await page.waitForFunction(() => Boolean(window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-drawing']?.document_attachment_id)
      && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    await short.fill('Runtime acceptance after drawing');
    await page.waitForFunction(() => window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-short'] === 'Runtime acceptance after drawing'
      && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    const drawingDocument = await page.evaluate(() => window.__studentAttachmentRequests.find((row) => row.fields.role === 'document')?.document);
    await writeFile(path.join(output, `drawing-${width}.json`), drawingDocument);
    await page.evaluate(() => {
      window.__studentHoldAttachmentContent = true;
      const root = document.getElementById('oj-ai-student-assignment-app');
      const canvas = root.querySelector('canvas.oj-ai-student-response-drawing');
      canvas.replaceWith(canvas.cloneNode(true));
      root.querySelector('fieldset[data-response-id="response-drawing"]').dataset.answered = 'false';
      root.dispatchEvent(new Event('change', { bubbles: true }));
      root.dispatchEvent(new CustomEvent('oj-ai:student-assignment-detail', {detail: {assignment: JSON.parse(root.dataset.assignment)}}));
    });
    await page.waitForFunction(() => typeof window.__studentReleaseAttachmentContent === 'function' && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    await page.evaluate(() => { window.__studentHoldAttachmentContent = false; window.__studentReleaseAttachmentContent(); });
    await page.waitForFunction(() => document.querySelector('fieldset[data-response-id="response-drawing"]')?.dataset.answered === 'true').catch(async (error) => {
      console.error(await page.evaluate(() => ({drawingStatus: document.querySelector('[data-role="drawing-status"]')?.textContent, save: document.querySelector('[data-role="save-label"]')?.textContent, answer: window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-drawing']})));
      throw error;
    });
    const document = page.locator('.oj-ai-student-document');
    const checks = {
      textAutosave: await page.evaluate(() => window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-short'] === 'Runtime acceptance after drawing'),
      drawingPixelsChanged: before !== after,
      drawingAnswerSaved: await page.evaluate(() => Boolean(window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-drawing'])),
      drawingPreviewSaved: await page.evaluate(() => Boolean(window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-drawing']?.preview_attachment_id)),
      drawingRestored: await canvas.evaluate((node) => node.toDataURL()) === after,
      restoredProgress: await page.locator('[data-action="jump-response"][data-response-id="response-drawing"]').getAttribute('data-complete') === 'true',
      sourceLink: await document.locator('a[href="https://example.test/source"]').count() === 1,
      sourceTable: await document.locator('table').filter({ hasText: 'Measure' }).count() === 1,
      equationVisible: await document.getByText('x^2 + y^2 = z^2', { exact: true }).count() > 0 || await document.locator('math, .katex, mjx-container').count() > 0,
      sourceImage: await document.getByRole('img', { name: 'Growth comparison', exact: true }).evaluate((node) => node.complete && node.naturalWidth > 0),
      audioTranscript: await document.getByText('Audio source transcript', { exact: true }).count() === 1,
      videoTranscript: await document.getByText('Video source transcript', { exact: true }).count() === 1,
      sourceFileControl: await document.getByRole('link', { name: 'Source document', exact: true }).count() + await document.getByRole('button', { name: 'Source document', exact: true }).count() > 0,
      noHorizontalOverflow: await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      noRuntimeExceptions: errors.length === 0,
    };
    results.push({ width, checks, errors, note: 'Local fixture; not a live HTTP authorization or database test.' });
    await page.screenshot({ path: path.join(output, `${width}.png`), fullPage: true });
    await page.close();
  }
} finally {
  await browser.close();
}
const failed = results.flatMap(({ width, checks }) => Object.entries(checks).filter(([, passed]) => !passed).map(([name]) => `${width}: ${name}`));
await writeFile(path.join(output, 'result.json'), `${JSON.stringify({ generatedAt: new Date().toISOString(), results, failed }, null, 2)}\n`);
console.log(JSON.stringify({ results, failed }, null, 2));
process.exitCode = failed.length ? 1 : 0;
