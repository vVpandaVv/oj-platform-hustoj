import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const workspace = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(path.join(workspace, '_notes/readcontents-server-import-2026-09-12/editor-source/package.json'));
const { chromium, expect } = require('@playwright/test');
const output = path.join(workspace, '_notes/tmp/readcontents-types-2026-10-18');
fs.mkdirSync(output, { recursive: true });
const base = process.env.READCONTENTS_PREVIEW_URL || 'http://127.0.0.1:8744';
await fetch(`${base}/test/reset`);
await fetch(`${base}/wp-json/readcontents-library/v1/lessons/4/document`, {
  method: 'PUT', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ schemaVersion: '1.0.0', plateValue: [
    { type: 'p', children: [{ text: 'Date: ' }, { type: 'date', date: '2026-10-18', children: [{ text: '' }] }, { text: '' }] },
    { type: 'p', children: [{ text: '' }] },
  ], assets: [], visuals: [] }),
});
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: Number(process.env.READCONTENTS_VIEWPORT_WIDTH || 1400), height: 1000 } });
// Keep the selected fixture date from becoming the relative label "Today" as time passes.
await page.clock.setFixedTime(new Date('2026-10-18T12:00:00Z'));
const errors = [];
page.on('pageerror', error => errors.push(error.message));
try {
  await page.goto(base);
  await page.getByRole('article', { name: '읽기자료 A', exact: true }).getByRole('button', { name: '열기', exact: true }).click();
  await page.locator('button').filter({ has: page.locator('span[title="첫 번째 레슨"]') }).first().click();
  const editor = page.locator('[aria-label="레슨 본문 편집"]');
  await editor.locator('span[draggable="true"]').click();
  const calendar = page.locator('[data-slot="calendar"]');
  await expect(calendar).toBeVisible();
  await expect(calendar.locator('[data-selected-single="true"]')).toBeFocused();
  await calendar.locator('button[data-day]').filter({ hasText: /^20$/ }).click();
  await expect(calendar.locator('[data-selected-single="true"]')).toHaveText('20');
  await page.keyboard.press('Escape');
  await expect(editor).toContainText('20');
  await editor.locator('[data-slate-node="element"]').last().click();
  await expect(editor).toBeFocused();
  await page.keyboard.press('Control+End');
  await page.keyboard.press('Enter');
  await page.keyboard.type('/');
  const combo = editor.getByRole('combobox');
  await expect(combo).toBeFocused();
  await expect(page.getByRole('listbox')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(combo).toHaveCount(0);
  await expect(editor).toBeFocused();
  await page.keyboard.press('Enter');
  await page.keyboard.type('/');
  await expect(combo).toBeFocused();
  await page.keyboard.type('quote');
  await page.getByRole('option', { name: '인용문', exact: true }).click();
  await expect(editor.locator('blockquote')).toHaveCount(1);
  await expect(editor).toBeFocused();
  await page.keyboard.type('Quote test');
  await expect(editor.locator('blockquote')).toContainText('Quote test');
  await page.screenshot({ path: path.join(output, 'editor-integration.png') });
  assert.deepEqual(errors, []);
  fs.writeFileSync(path.join(output, 'integration-results.json'), JSON.stringify({ dateSelection: true, dateAutoFocus: true, slashEscape: true, slashInsert: true, errors }, null, 2) + '\n');
  console.log('PASS: production date node focus/selection, slash escape, block insertion.');
} catch (error) {
  console.error(await page.evaluate(() => ({
    active: document.activeElement?.outerHTML.slice(0, 600),
    selection: window.getSelection()?.anchorNode?.parentElement?.outerHTML.slice(0, 600),
  })));
  fs.mkdirSync(output, { recursive: true });
  await page.screenshot({ path: path.join(output, 'integration-failure.png') });
  throw error;
} finally {
  await browser.close();
  await fetch(`${base}/test/reset`);
}
