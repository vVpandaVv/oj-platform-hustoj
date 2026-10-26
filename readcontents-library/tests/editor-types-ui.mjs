import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const workspace = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = path.join(workspace, '_notes/readcontents-server-import-2026-09-12/editor-source');
const output = path.join(workspace, '_notes/tmp/readcontents-types-2026-10-18');
const web = path.join(output, 'web');
const require = createRequire(path.join(source, 'package.json'));
const { chromium, expect } = require('@playwright/test');
const { build } = await import(pathToFileURL(require.resolve('vite')));
process.chdir(source);
const { default: config } = await import(pathToFileURL(path.join(source, 'vite.config.mjs')));
let warnings = 0;
await build({ ...config, configFile: false, customLogger: {
  info() {}, warn() { warnings++; }, warnOnce() { warnings++; }, error(message) { console.error(message); },
  clearScreen() {}, hasErrorLogged() { return false; }, hasWarned: false,
}, build: { ...config.build, outDir: web, rollupOptions: {
  input: path.join(source, 'tests/editor-regression.tsx'),
  output: { entryFileNames: 'regression.js', assetFileNames: '[name][extname]' },
} } });
const css = fs.readdirSync(web).filter(name => name.endsWith('.css')).map(name => `<link rel="stylesheet" href="/${name}">`).join('');
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname === '/') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${css}<style>body{margin:0;background:white}main{max-width:1000px;margin:auto;padding:12px;min-width:0}output{display:block;overflow-wrap:anywhere}</style></head><body><main class="rcis-editor-root" id="test-root"></main><div id="contents-editor-overlay-root"></div><script type="module" src="/regression.js"></script></body></html>`);
    return;
  }
  const file = path.resolve(web, url.pathname.slice(1));
  if (!file.startsWith(web + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
  res.setHeader('Content-Type', { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2' }[path.extname(file)] ?? 'application/octet-stream');
  fs.createReadStream(file).pipe(res);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true });
const report = { warnings, cases: [] };
try {
  for (const width of [1400, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base);
    await expect(page.getByTestId('utilities')).toHaveText('passed');
    await expect(page.locator('[data-selected-single="true"]')).toBeFocused();
    await expect(page.locator('.rdp-month_grid')).toHaveCSS('border-collapse', 'collapse');
    await page.locator('button[data-day]').filter({ hasText: /^20$/ }).click();
    await expect(page.getByTestId('selected')).toHaveText('20');
    const button = page.getByRole('button', { name: '검증 버튼', exact: true });
    await button.hover();
    await expect(page.getByRole('tooltip')).toContainText('검증 버튼');
    await button.click();
    await expect(page.getByTestId('clicked')).toHaveText('BUTTON');
    await page.getByRole('textbox', { name: 'Debounce input' }).fill('updated');
    await expect(page.getByTestId('debounced')).toHaveText('updated');
    await page.screenshot({ path: path.join(output, `controls-${width}.png`) });
    for (const name of ['dp-table', 'dp-table:invalid', 'divide-conquer', 'divide-conquer:invalid', 'vector-space', 'scatter', 'scatter:empty', 'gantt', 'gantt:invalid', 'geometry-plane']) {
      await page.goto(`${base}/?case=${name}`);
      const svg = page.locator('#test-root svg').first();
      await expect(svg).toBeVisible();
      assert.ok(await svg.locator('rect, circle, line, path').count() > 3, `${name} blank`);
      assert.ok(!(await svg.innerHTML()).match(/(?:NaN|Infinity)/), `${name} invalid geometry`);
      if (name === 'dp-table') await expect(svg).toContainText('(match)');
      if (name === 'dp-table:invalid') { await expect(svg).toContainText('Selected calculation:'); await expect(svg).not.toContainText('(match)'); }
      if (name === 'gantt') await expect(svg).toContainText('시작');
      if (name === 'gantt:invalid') await expect(svg).not.toContainText('[object Object]');
      if (name === 'geometry-plane') assert.equal(await svg.locator('[data-visual-edge-id][marker-end]').count(), 0);
      if (name === 'vector-space') {
        const boundary = svg.locator('[data-visual-vector-boundary]');
        const box = await boundary.boundingBox();
        assert.ok(box);
        await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.7);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width * 0.5 + 24, box.y + box.height * 0.7 + 16, { steps: 4 });
        await page.mouse.up();
        await expect(page.getByTestId('events')).toContainText('"id":"C"');
        await expect(page.getByTestId('events')).toContainText('"id":"D"');
      }
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      assert.equal(overflow, 0, `${name} overflow at ${width}`);
      await page.screenshot({ path: path.join(output, `${name.replace(':', '-')}-${width}.png`) });
      report.cases.push({ name, width, rendered: true, overflow });
    }
    assert.deepEqual(errors, []);
    report.cases.push({ name: 'controls-utilities', width, errors });
    await page.close();
  }
  fs.writeFileSync(path.join(output, 'ui-results.json'), JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify({ status: 'passed', cases: report.cases.length, warnings, output }));
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
