import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(path.join(root, '_notes/readcontents-server-import-2026-09-12/editor-source/package.json'));
const { chromium, expect } = require('@playwright/test');
const output = path.join(root, '_notes/tmp/readcontents-archive-2026-10-18');
fs.mkdirSync(output, { recursive: true });
const base = 'http://127.0.0.1:8744';
const browser = await chromium.launch({ headless: true });
const report = [];
const state = async () => (await fetch(`${base}/test/state`)).json();
const reset = async () => { await fetch(`${base}/test/reset`); };
async function load(page, query = '') { await page.goto(base + query); await page.getByRole('article', { name: '읽기자료 A', exact: true }).waitFor(); }
async function confirm(page, label) { const sheet = page.getByRole('alertdialog'); await sheet.waitFor(); await sheet.getByRole('button', { name: label, exact: true }).click(); await sheet.waitFor({ state: 'hidden' }); }
async function archive(page) {
  await page.getByRole('button', { name: '보조 기능 열기', exact: true }).click();
  await page.getByRole('menuitem', { name: '보관함', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: '보관함', exact: true }); await dialog.waitFor(); return dialog;
}
async function closeArchive(page) { await page.getByRole('dialog', { name: '보관함', exact: true }).getByRole('button', { name: '보관함 닫기', exact: true }).click(); }
async function selectNode(page, name) { await page.locator('button').filter({ has: page.locator(`span[title="${name}"]`) }).first().click(); }
async function layout(page) {
  const result = await page.evaluate(() => ({ horizontal: document.documentElement.scrollWidth - innerWidth,
    nested: [...document.querySelectorAll('#contents-editor-admin-root, #contents-editor-admin-root *')].filter(el => ['auto','scroll'].includes(getComputedStyle(el).overflowY) && el.clientHeight > 0 && el.scrollHeight > el.clientHeight + 1 && !el.closest('[role="dialog"]')).length }));
  assert.equal(result.horizontal, 0); assert.equal(result.nested, 0); return result;
}
try {
  for (const width of [1400, 390, 320]) {
    await reset();
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await load(page);
    const card = page.getByRole('article', { name: '읽기자료 A', exact: true });
    await expect(card.getByRole('button', { name: '읽기자료 A 보관함으로 이동', exact: true })).toBeVisible();
    await expect(card.getByRole('button', { name: '읽기자료 A 영구 삭제', exact: true })).toBeVisible();
    await layout(page);
    await page.screenshot({ path: path.join(output, `library-${width}.png`) });
    await card.getByRole('button', { name: '읽기자료 A 보관함으로 이동', exact: true }).click();
    await confirm(page, '취소');
    assert.equal((await state()).mutations.length, 0);
    await card.getByRole('button', { name: '읽기자료 A 보관함으로 이동', exact: true }).click();
    await confirm(page, '보관함으로 이동');
    await expect(card).toHaveCount(0);
    await page.getByRole('button', { name: '보관함', exact: true }).click();
    let dialog = page.getByRole('dialog', { name: '보관함', exact: true });
    await dialog.getByRole('group', { name: '읽기자료 A 보관 항목', exact: true }).waitFor();
    await layout(page);
    await page.screenshot({ path: path.join(output, `archive-${width}.png`) });
    await dialog.getByRole('button', { name: '초안으로 복원', exact: true }).click();
    await confirm(page, '초안으로 복원');
    await dialog.getByText('보관한 항목이 없습니다.', { exact: true }).waitFor();
    await closeArchive(page);
    await expect(card).toBeVisible();
    assert.equal((await state()).nodes.find(n => n.id === '1').status, 'draft');
    assert.equal((await state()).documents['4'].revision, 1);

    await card.getByRole('button', { name: '열기', exact: true }).click();
    await selectNode(page, '첫 번째 레슨');
    await page.locator('[aria-label="레슨 본문 편집"]').waitFor();
    await page.getByRole('button', { name: '첫 번째 레슨 보관함으로 이동', exact: true }).click();
    await confirm(page, '보관함으로 이동');
    await expect(page.getByRole('button', { name: '첫 번째 레슨 보관함으로 이동', exact: true })).toHaveCount(0);
    dialog = await archive(page);
    await dialog.getByRole('group', { name: '첫 번째 레슨 보관 항목', exact: true }).waitFor();
    await dialog.getByRole('button', { name: '초안으로 복원', exact: true }).click();
    await confirm(page, '초안으로 복원');
    await dialog.getByText('보관한 항목이 없습니다.', { exact: true }).waitFor();
    assert.equal((await state()).documents['4'].revision, 1);
    await closeArchive(page);

    await load(page);
    await card.getByRole('button', { name: '열기', exact: true }).click();
    await selectNode(page, '첫 번째 장');
    await page.locator('[aria-label="선택 항목 관리"]').getByRole('button', { name: '첫 번째 장 보관함으로 이동', exact: true }).click();
    await confirm(page, '보관함으로 이동');
    await expect(page.locator('span[title="첫 번째 장"]')).toHaveCount(0);
    dialog = await archive(page);
    await dialog.getByRole('group', { name: '첫 번째 장 보관 항목', exact: true }).waitFor();
    await dialog.getByRole('button', { name: '초안으로 복원', exact: true }).click();
    await confirm(page, '초안으로 복원');
    await dialog.getByText('보관한 항목이 없습니다.', { exact: true }).waitFor();
    await closeArchive(page);
    await selectNode(page, '첫 번째 챕터');
    await page.locator('[aria-label="선택 항목 관리"]').getByRole('button', { name: '첫 번째 챕터 보관함으로 이동', exact: true }).click();
    await confirm(page, '보관함으로 이동');
    await expect(page.locator('span[title="첫 번째 챕터"]')).toHaveCount(0);
    await load(page);
    await card.getByRole('button', { name: '읽기자료 A 영구 삭제', exact: true }).click();
    await expect(page.getByRole('alertdialog')).toContainText('보관된 항목을 포함한 모든 하위 항목');
    await confirm(page, '영구 삭제');
    await expect(card).toHaveCount(0);
    const final = await state();
    assert.deepEqual(final.nodes.map(n => n.id), ['101','102','103','104','105']);
    assert.ok(!final.documents['4']);
    assert.ok(final.mutations.every(m => m.nonce === 'test-nonce'));
    assert.deepEqual(errors, []);
    report.push({ width, allTypesAccessible: true, cancelledWithoutWrite: true, restoredAsDraft: true, bodyPreserved: true, recursiveDelete: true, layout: await layout(page), errors });
    await page.close();
  }
  await reset();
  const page = await browser.newPage();
  await load(page, '/?no-publish');
  await expect(page.getByRole('button', { name: '읽기자료 A 영구 삭제', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: '보조 읽기자료 A 영구 삭제', exact: true })).toBeEnabled();
  await load(page);
  await page.getByRole('article', { name: '읽기자료 A', exact: true }).getByRole('button', { name: '열기', exact: true }).click();
  await selectNode(page, '첫 번째 레슨');
  const editor = page.locator('[contenteditable="true"]').first(); await editor.waitFor();
  await fetch(`${base}/test/fail-save`);
  await editor.click(); await editor.press('Control+End'); await editor.pressSequentially(' unsaved edit');
  await page.getByRole('button', { name: '첫 번째 레슨 보관함으로 이동', exact: true }).click();
  await confirm(page, '보관함으로 이동');
  await expect(page.locator('#contents-editor-admin-root')).toContainText('저장 실패 테스트');
  assert.ok(!(await state()).mutations.some(m => m.route === '/nodes/4'));
  assert.equal((await state()).nodes.find(n => n.id === '4').status, 'published');
  page.on('dialog', d => d.accept());
  await page.close();
  report.push({ permissions: true, failedSavePreventsArchive: true });

  await reset();
  const edited = await browser.newPage(); await load(edited);
  await edited.getByRole('article', { name: '읽기자료 A', exact: true }).getByRole('button', { name: '열기', exact: true }).click();
  await selectNode(edited, '첫 번째 레슨');
  const changedEditor = edited.locator('[contenteditable="true"]').first();
  await changedEditor.click(); await changedEditor.press('Control+End'); await changedEditor.pressSequentially(' saved before archive');
  await edited.getByRole('button', { name: '첫 번째 레슨 보관함으로 이동', exact: true }).click(); await confirm(edited, '보관함으로 이동');
  await expect(edited.getByRole('button', { name: '첫 번째 레슨 보관함으로 이동', exact: true })).toHaveCount(0);
  const saved = await state();
  assert.ok(JSON.stringify(saved.documents['4'].plateValue).includes('saved before archive'));
  assert.ok(saved.mutations.findIndex(m => m.route === '/lessons/4/document') < saved.mutations.findIndex(m => m.route === '/nodes/4'));
  const savedDrawer = await archive(edited);
  await savedDrawer.getByRole('button', { name: '영구 삭제', exact: true }).click(); await confirm(edited, '영구 삭제');
  await savedDrawer.getByText('보관한 항목이 없습니다.', { exact: true }).waitFor();
  assert.ok(!(await state()).documents['4']);
  await edited.close(); report.push({ pendingBodySavedBeforeArchive: true, permanentDeleteFromArchive: true });

  await reset();
  const failed = await browser.newPage(); await load(failed);
  await failed.getByRole('button', { name: '읽기자료 A 보관함으로 이동', exact: true }).click(); await confirm(failed, '보관함으로 이동');
  await expect(failed.getByRole('article', { name: '읽기자료 A', exact: true })).toHaveCount(0);
  await failed.getByRole('button', { name: '보관함', exact: true }).click();
  const drawer = failed.getByRole('dialog', { name: '보관함', exact: true });
  await fetch(`${base}/test/fail-delete`);
  await drawer.getByRole('button', { name: '영구 삭제', exact: true }).click(); await confirm(failed, '영구 삭제');
  await expect(drawer.getByRole('status')).toContainText('삭제 실패 테스트');
  await expect(drawer.getByRole('group', { name: '읽기자료 A 보관 항목', exact: true })).toBeVisible();
  assert.ok((await state()).documents['4']);
  await failed.close(); report.push({ failedDeleteRetainsItemAndReportsInsideDrawer: true });

  await reset();
  const fallback = await browser.newPage(); await load(fallback, '/?native');
  fallback.on('dialog', d => d.accept());
  await fallback.getByRole('button', { name: '읽기자료 A 보관함으로 이동', exact: true }).click();
  await expect(fallback.getByRole('article', { name: '읽기자료 A', exact: true })).toHaveCount(0);
  await fallback.close(); report.push({ nativeConfirmationFallback: true });
} finally { await browser.close(); }
fs.writeFileSync(path.join(output, 'ui-results.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
