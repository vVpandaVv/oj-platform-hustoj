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
  for (const viewport of [{ width: 1440, height: 1000 }, { width: 1024, height: 768 }, { width: 390, height: 844 }]) {
    const page = await browser.newPage({ viewport });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.goto(pathToFileURL(path.join(root, 'tests/fixtures/worksheet-workbench-visual.html')).href);
    await page.waitForFunction(() => document.querySelector('#ws-plate-template-builder-root')?.getAttribute('aria-busy') === 'false');
    await page.waitForFunction(() => document.querySelector('#ws-template-title')?.value === '우리 동네 환경 조사');
    for (const unit of ['px', 'percent']) {
      await page.evaluate(unit => {
        const schema = JSON.parse(document.querySelector('#ws-builder-schema').value);
        schema.plate_value = schema.plate_value.filter(node => node.type !== 'table');
        schema.plate_value.unshift({
          type: 'table',
          ...(unit === 'percent' ? { widthPercent: 100, colSizes: [2000, 4000, 4000] } : { colSizes: [120, 300, 300] }),
          children: Array.from({ length: 24 }, (_, index) => ({
            type: 'tr', children: [String(index + 1), '식물의 잎 수, 성장 속도, 햇빛 시간과 물의 양을 기록함.', '관찰한 결과를 비교하여 식물의 특성과 성장 과정을 설명함.'].map(text => ({
              type: 'td', children: [{ type: 'p', children: [{ text }] }],
            })),
          })),
        });
        window.dispatchEvent(new CustomEvent('ojai-plate-template-load', { detail: { schema } }));
      }, unit);
      const canvas = page.locator('.hws-template-editor-container');
      const toolbar = canvas.locator('[role="toolbar"]').first();
      const table = canvas.locator('[contenteditable="true"] table').first();
      await table.locator('td[data-table-cell-id]').first().click();
      await page.keyboard.press('Escape');
      await canvas.evaluate(element => element.scrollIntoView({ block: 'start' }));
      await page.mouse.move(0, 0);
      const before = await page.locator('#ws-builder-schema').inputValue();
      for (const row of [3, 9]) {
        // Put real table text behind the sticky toolbar, not merely below it.
        await canvas.evaluate((element, row) => {
          const toolbar = element.querySelector('[role="toolbar"]');
          const text = element.querySelectorAll('tr')[row].querySelector('[data-slate-string]');
          element.scrollTop += text.getBoundingClientRect().top - toolbar.getBoundingClientRect().top - 16;
        }, row);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const overlap = await toolbar.evaluate(element => {
          const box = element.getBoundingClientRect();
          const bad = [];
          for (let y = box.top + 8; y < Math.min(box.bottom - 4, innerHeight); y += 12) {
            for (let x = box.left + 8; x < Math.min(box.right - 16, innerWidth); x += 16) {
              const top = document.elementFromPoint(x, y);
              if (top && top !== element && !element.contains(top)) bad.push({ x, y, tag: top.tagName, className: top.className });
            }
          }
          return { top: box.top, background: getComputedStyle(element).backgroundColor, bad };
        });
        await page.screenshot({ path: path.join(root, `tests/artifacts/toolbar-stacking-${viewport.width}-${unit}-${row}.png`), animations: 'disabled' });
        assert.equal(overlap.bad.length, 0, `Toolbar must cover scrolled table content: ${JSON.stringify({ ...overlap, bad: overlap.bad.slice(0, 3) })}`);
        assert.ok(!overlap.background.startsWith('rgba('), 'Toolbar background must be opaque.');
        const canvasTop = await canvas.evaluate(element => element.getBoundingClientRect().top);
        assert.ok(Math.abs(overlap.top - canvasTop) < 3, 'Toolbar remains pinned to the editor scrollport.');
      }
      assert.equal(await page.locator('#ws-builder-schema').inputValue(), before, 'Scrolling must not change worksheet content.');
      // The selected table stays editable through a real click on the fixed toolbar.
      await toolbar.getByRole('button', { name: '표 너비 설정', exact: true }).click();
      await page.locator('.hws-table-size-popover').waitFor({ state: 'visible' });
      await page.getByRole('button', { name: '열 너비 균등', exact: true }).click();
      await page.getByRole('button', { name: '적용', exact: true }).click();
      await page.locator('[data-action="save"]').click();
      await page.waitForFunction(() => window.__lastSavedTeacherDocument?.nodes.some(node => node.type === 'table'));
      assert.deepEqual(errors, []);
    }
    await page.close();
  }
  console.log('Worksheet toolbar stacking passed: desktop/tablet/mobile, long px/% tables, pinned toolbar hit testing, controls and save.');
} finally {
  await browser.close();
}
