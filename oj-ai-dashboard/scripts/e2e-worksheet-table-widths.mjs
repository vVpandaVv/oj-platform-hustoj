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
  for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
    const page = await browser.newPage({ viewport });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.goto(pathToFileURL(path.join(root, 'tests/fixtures/worksheet-workbench-visual.html')).href);
    await page.waitForFunction(() => document.querySelector('#ws-plate-template-builder-root')?.getAttribute('aria-busy') === 'false');
    await page.waitForFunction(() => document.querySelector('#ws-template-title')?.value === '우리 동네 환경 조사');
    await page.evaluate(() => {
      const schema = JSON.parse(document.querySelector('#ws-builder-schema').value);
      schema.plate_value.unshift({ type: 'table', children: [
        { type: 'tr', children: ['항목', '관찰 내용'].map(text => ({ type: 'td', children: [{ type: 'p', children: [{ text }] }] })) },
        { type: 'tr', children: ['첫 번째', '근거를 자세히 기록합니다.'].map(text => ({ type: 'td', children: [{ type: 'p', children: [{ text }] }] })) },
      ] });
      window.dispatchEvent(new CustomEvent('ojai-plate-template-load', { detail: { schema } }));
    });
    const table = page.locator('[contenteditable="true"] table').first();
    await table.getByText('항목', { exact: true }).click();
    const control = page.getByRole('button', { name: '표 너비 설정', exact: true });
    await control.click();
    await page.getByLabel('표 너비 (px)', { exact: true }).fill('600');
    await page.getByLabel('열 너비 (px)', { exact: true }).fill('180');
    await page.getByLabel('열', { exact: true }).selectOption('1');
    await page.getByLabel('열 너비 (px)', { exact: true }).fill('420');
    assert.equal(await page.getByLabel('표 너비 (px)', { exact: true }).inputValue(), '600');
    const popover = await page.locator('.hws-table-size-popover').boundingBox();
    assert.ok(popover.x >= 0 && popover.x + popover.width <= viewport.width, 'Settings must fit the viewport.');
    await page.screenshot({ path: path.join(root, `tests/artifacts/table-widths-${viewport.width}-settings.png`), animations: 'disabled' });
    await page.getByRole('button', { name: '적용', exact: true }).click();
    await page.locator('[data-action="save"]').click();
    await page.waitForFunction(() => window.__lastSavedTeacherDocument?.nodes.some(node => node.type === 'table' && node.column_widths?.join(',') === '180,420'));
    // Re-open from the canonical saved document, not the original in-memory editor value.
    await page.evaluate(() => window.dispatchEvent(new CustomEvent('ojai-plate-template-load', { detail: {
      schema: window.OJAIWorksheetAuthoringWorkbench.teacherDocumentToPlateSchema(window.__lastSavedTeacherDocument),
    } })));
    await table.getByText('항목', { exact: true }).click();
    await control.click();
    assert.equal(await page.getByLabel('표 너비 (px)', { exact: true }).inputValue(), '600');
    assert.equal(await page.getByLabel('열 너비 (px)', { exact: true }).inputValue(), '180');
    await page.getByRole('button', { name: '열 너비 균등', exact: true }).click();
    assert.equal(await page.getByLabel('열 너비 (px)', { exact: true }).inputValue(), '300');
    await page.keyboard.press('Escape');
    // Cancel must not alter the saved widths.
    await page.locator('[data-action="preview-open"]').evaluate(button => button.click());
    const previewTable = page.locator('[data-role="preview-content"] table').first();
    await previewTable.waitFor({ state: 'visible' });
    const geometry = await previewTable.evaluate(element => ({
      width: element.getBoundingClientRect().width,
      columns: [...element.querySelector('tr').children].map(cell => cell.getBoundingClientRect().width),
      viewport: document.documentElement.clientWidth,
    }));
    assert.ok(Math.abs(geometry.columns[0] / (geometry.columns[0] + geometry.columns[1]) - 0.3) < 0.02, JSON.stringify(geometry));
    assert.ok(geometry.width <= viewport.width, 'Preview table must fit the viewport.');
    await page.screenshot({ path: path.join(root, `tests/artifacts/table-widths-${viewport.width}-preview.png`) });
    await page.locator('[data-action="preview-close"]').last().click();
    await table.getByText('항목', { exact: true }).click();
    await control.click();
    await page.getByLabel('너비 단위', { exact: true }).selectOption('%');
    assert.equal(await page.getByLabel('표 너비 (%)', { exact: true }).inputValue(), '100');
    assert.equal(await page.getByLabel('열 너비 (%)', { exact: true }).inputValue(), '30');
    await page.getByLabel('표 너비 (%)', { exact: true }).fill('101');
    await page.getByRole('button', { name: '적용', exact: true }).click();
    assert.equal(await page.locator('.hws-table-size-popover').isVisible(), true, 'Invalid percent must not apply.');
    await page.getByLabel('표 너비 (%)', { exact: true }).fill('100');
    await page.getByRole('button', { name: '적용', exact: true }).click();
    const renderedWidth = () => table.evaluate(element => element.getBoundingClientRect().width);
    const fullEditorWidth = await renderedWidth();
    for (const percent of [80, 100]) {
      await table.getByText('항목', { exact: true }).click();
      await control.click();
      await page.getByLabel('표 너비 (%)', { exact: true }).fill(String(percent));
      await page.getByLabel('열 너비 (%)', { exact: true }).fill('30');
      await page.getByLabel('열', { exact: true }).selectOption('1');
      assert.equal(await page.getByLabel('열 너비 (%)', { exact: true }).inputValue(), '70');
      await page.screenshot({ path: path.join(root, `tests/artifacts/table-percent-${viewport.width}-${percent}-settings.png`), animations: 'disabled' });
      await page.getByRole('button', { name: '적용', exact: true }).click();
      assert.ok(Math.abs((await renderedWidth()) / fullEditorWidth - percent / 100) < 0.02, 'Editor respects the page percentage.');
      const editorRatio = await table.evaluate(element => {
        const cells = [...element.querySelector('tr').querySelectorAll('td[data-table-cell-id]')];
        return cells[0].getBoundingClientRect().width / cells.reduce((sum, cell) => sum + cell.getBoundingClientRect().width, 0);
      });
      assert.ok(Math.abs(editorRatio - 0.3) < 0.02, `Editor column ratio: ${editorRatio}`);
      await page.locator('[data-action="save"]').click();
      await page.waitForFunction(percent => window.__lastSavedTeacherDocument?.nodes.some(node => node.type === 'table' && node.width_percent === percent && node.column_ratios?.join(',') === '3000,7000' && !node.column_widths), percent);
      await page.evaluate(() => window.dispatchEvent(new CustomEvent('ojai-plate-template-load', { detail: {
        schema: window.OJAIWorksheetAuthoringWorkbench.teacherDocumentToPlateSchema(window.__lastSavedTeacherDocument),
      } })));
      await table.getByText('항목', { exact: true }).click();
      await control.click();
      assert.equal(await page.getByLabel('너비 단위', { exact: true }).inputValue(), '%');
      assert.equal(await page.getByLabel('표 너비 (%)', { exact: true }).inputValue(), String(percent));
      assert.equal(await page.getByLabel('열 너비 (%)', { exact: true }).inputValue(), '30');
      await page.keyboard.press('Escape');
      await page.locator('[data-action="preview-open"]').evaluate(button => button.click());
      const relativeGeometry = await previewTable.evaluate(element => ({
        width: element.getBoundingClientRect().width, available: element.parentElement.clientWidth,
        columns: [...element.querySelector('tr').children].map(cell => cell.getBoundingClientRect().width),
      }));
      assert.ok(Math.abs(relativeGeometry.width / relativeGeometry.available - percent / 100) < 0.02, JSON.stringify(relativeGeometry));
      assert.ok(Math.abs(relativeGeometry.columns[0] / relativeGeometry.width - 0.3) < 0.02, JSON.stringify(relativeGeometry));
      await page.screenshot({ path: path.join(root, `tests/artifacts/table-percent-${viewport.width}-${percent}-preview.png`) });
      await page.locator('[data-action="preview-close"]').last().click();
    }
    const readTableSize = () => page.evaluate(() => {
      const node = JSON.parse(document.querySelector('#ws-builder-schema').value).plate_value.find(node => node.type === 'table');
      return { widthPercent: node.widthPercent, colSizes: node.colSizes };
    });
    const beforeResize = await readTableSize();
    await page.setViewportSize({ ...viewport, width: viewport.width > 1000 ? 1100 : 430 });
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.deepEqual(await readTableSize(), beforeResize, 'Viewport resizing must not persist measured pixel widths.');
    await page.setViewportSize(viewport);
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    // Dragging a relative column changes its ratio without dropping the table's percentage.
    const firstCell = table.locator('td[data-table-cell-id]').first();
    const rightHandle = firstCell.locator('.cursor-col-resize').first();
    const handleBox = await rightHandle.boundingBox();
    await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(handleBox.x + handleBox.width / 2 + 15, handleBox.y + handleBox.height / 2);
    await page.mouse.up();
    await page.locator('[data-action="save"]').click();
    await page.waitForFunction(() => window.__lastSavedTeacherDocument?.nodes.some(node => node.type === 'table' && node.width_percent === 100 && node.column_ratios?.[0] > 3000 && node.column_ratios.reduce((a, b) => a + b, 0) === 10000));
    await table.getByText('항목', { exact: true }).click();
    await control.click();
    await page.getByLabel('너비 단위', { exact: true }).selectOption('px');
    await page.getByLabel('표 너비 (px)', { exact: true }).fill('600');
    await page.getByRole('button', { name: '적용', exact: true }).click();
    await page.locator('[data-action="save"]').click();
    await page.waitForFunction(() => window.__lastSavedTeacherDocument?.nodes.some(node => node.type === 'table' && !node.width_percent && !node.column_ratios && node.column_widths?.reduce((a, b) => a + b, 0) === 600));
    await page.goto(`${pathToFileURL(path.join(root, 'tests/fixtures/student-assignments-visual.html')).href}?mode=detail&table_widths=1`);
    const studentTable = page.locator('.oj-ai-student-table-scroll table').first();
    await studentTable.waitFor({ state: 'visible' });
    const studentGeometry = await studentTable.evaluate(element => ({
      width: element.getBoundingClientRect().width,
      columns: [...element.querySelector('tr').children].map(cell => cell.getBoundingClientRect().width),
    }));
    assert.ok(Math.abs(studentGeometry.columns[0] / (studentGeometry.columns[0] + studentGeometry.columns[1]) - 0.3) < 0.02, JSON.stringify(studentGeometry));
    assert.ok(studentGeometry.width <= viewport.width, 'Student table must fit the viewport.');
    await page.screenshot({ path: path.join(root, `tests/artifacts/table-widths-${viewport.width}-student.png`) });
    for (const percent of [80, 100]) {
      await page.goto(`${pathToFileURL(path.join(root, 'tests/fixtures/student-assignments-visual.html')).href}?mode=detail&table_widths=1&table_percent=${percent}`);
      await studentTable.waitFor({ state: 'visible' });
      const ratio = await studentTable.evaluate(element => element.getBoundingClientRect().width / element.parentElement.clientWidth);
      assert.ok(Math.abs(ratio - percent / 100) < 0.02, `Student percentage: ${ratio}`);
      await page.screenshot({ path: path.join(root, `tests/artifacts/table-percent-${viewport.width}-${percent}-student.png`) });
    }
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log('Table width UI passed: desktop/mobile, px/percent, save/reopen, cancel, ratio drag, unit conversion, preview and student display.');
} finally {
  await browser.close();
}
