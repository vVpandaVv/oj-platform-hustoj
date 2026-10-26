import assert from 'node:assert/strict';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const {chromium} = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({viewport: {width, height: 900}});
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`${pathToFileURL(path.resolve('tests/fixtures/student-assignments-visual.html')).href}?mode=detail&drawing_orphans=1`);
    await page.locator('[data-action="start"]').click();
    const section = page.locator('[data-response-id="response-drawing"]');
    await page.waitForFunction(() => !document.querySelector('[data-response-id="response-short"] input').disabled);
    const removals = section.getByRole('button', {name: '\uBBF8\uC644\uB8CC \uD30C\uC77C \uC0AD\uC81C', exact: true});
    assert.equal(await removals.count(), 2, 'unbound drawing uploads must be recoverable after a page reload');
    page.once('dialog', (dialog) => dialog.dismiss());
    await removals.first().click();
    assert.deepEqual(await page.evaluate(() => window.__studentAttachmentRemovals), [], 'cancel must not remove any file');
    for (let index = 0; index < 2; index += 1) {
      page.once('dialog', (dialog) => dialog.accept());
      await removals.first().click();
      await page.waitForFunction((count) => window.__studentAttachmentRemovals.length === count, index + 1);
      await page.waitForFunction((count) => document.querySelectorAll('[data-response-id="response-drawing"] [data-action="remove-answer-attachment"]').length === count, 1 - index);
    }
    const canvas = section.locator('canvas');
    await canvas.scrollIntoViewIfNeeded();
    const rect = await canvas.boundingBox();
    await page.mouse.move(rect.x + 20, rect.y + 20);
    await page.mouse.down();
    await page.mouse.move(rect.x + 120, rect.y + 80, {steps: 8});
    await page.mouse.up();
    const drawn = await canvas.evaluate((node) => node.toDataURL());
    await page.waitForFunction(() => Boolean(window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-drawing']?.preview_attachment_id)
      && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    assert.equal(await removals.count(), 0, 'bound answer files must not expose staged deletion');
    assert.equal(await canvas.evaluate((node) => node.toDataURL()), drawn, 'file recovery must not clear the canvas');
    assert.deepEqual(await page.evaluate(() => window.__studentAttachmentRemovals), ['00000000000000000000000780', '00000000000000000000000781']);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    assert.deepEqual(errors, []);
    await page.screenshot({path: `tests/artifacts/drawing-upload-recovery-${width}.png`, fullPage: true});
    console.log(`PASS drawing recovery ${width}: orphan visibility, cancel, explicit removal, unchanged quota, save, bound protection, canvas, layout`);
    await page.close();

    const partial = await browser.newPage({viewport: {width, height: 900}});
    partial.on('pageerror', (error) => errors.push(error.message));
    await partial.goto(`${pathToFileURL(path.resolve('tests/fixtures/student-assignments-visual.html')).href}?mode=detail&drawing_preview_reject_once=1&drawing_rejected=1`);
    await partial.locator('[data-action="start"]').click();
    await partial.waitForFunction(() => !document.querySelector('[data-response-id="response-short"] input').disabled);
    const partialCanvas = partial.locator('[data-response-id="response-drawing"] canvas');
    await partialCanvas.scrollIntoViewIfNeeded();
    const bounds = await partialCanvas.boundingBox();
    await partial.mouse.move(bounds.x + 20, bounds.y + 20);
    await partial.mouse.down();
    await partial.mouse.move(bounds.x + 100, bounds.y + 60, {steps: 8});
    await partial.mouse.up();
    await partial.waitForFunction(() => document.querySelector('[data-role="save-label"]')?.textContent === 'Attachment quota exceeded');
    assert.equal(await partial.evaluate(() => window.__studentAttachmentRequests.filter((row) => row.fields.role === 'document').length), 1);
    await partial.locator('[data-action="retry-save"]').click();
    await partial.waitForFunction(() => Boolean(window.__studentAutosaveRequests.at(-1)?.body.payload.answers['response-drawing']?.preview_attachment_id)
      && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    const requests = await partial.evaluate(() => window.__studentAttachmentRequests);
    assert.equal(requests.filter((row) => row.fields.role === 'document').length, 1, 'successful drawing source must not be re-uploaded after preview rejection');
    const previews = requests.filter((row) => row.fields.role === 'preview');
    assert.equal(previews.length, 2);
    assert.notEqual(previews[0].fields.command_id, previews[1].fields.command_id, 'known rejection retries use a fresh receipt; uncertain outcomes retain their receipt');
    assert.deepEqual(await partial.evaluate(() => window.__studentAttachmentRemovals), [], 'retry never silently deletes staged or saved files');
    assert.deepEqual(errors, []);
    console.log(`PASS drawing preview retry ${width}: rejected upload does not consume quota, source retained, explicit retry, new receipt, no file deletion`);
    await partial.close();
  }
} finally { await browser.close(); }
