import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const workspace = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const output = path.join(workspace, '_notes/tmp/readcontents-reader-2026-10-25');
const require = createRequire(path.join(workspace, '_notes/readcontents-server-import-2026-09-12/editor-source/package.json'));
const bundled = 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright';
const { chromium } = require(process.env.PLAYWRIGHT_PATH || (fs.existsSync(bundled) ? bundled : '@playwright/test'));
const php = process.env.PHP_BINARY || 'D:/php/php.exe';
const fixture = path.join(workspace, 'readcontents-library/tests/reader-layout.php');
process.stdout.write(execFileSync(php, [fixture], { encoding: 'utf8' }));
fs.mkdirSync(output, { recursive: true });
const pages = new Map();
const url = number => `https://reader-layout.test/school/oj-addons/readcontents-library/lesson-${number}`;
const widthKey = 'readcontents-reader-outline-width', hiddenKey = 'readcontents-reader-outline-hidden';
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const near = (a, b) => Math.abs(a - b) <= 1.5;
async function poll(read, accept, message) {
  let value;
  for (let attempt = 0; attempt < 100; attempt++) {
    value = await read();
    if (accept(value)) return value;
    await pause(50);
  }
  assert.fail(`${message}: ${JSON.stringify(value)}`);
}
const chrome = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const browser = await chromium.launch({ headless: true, ...(fs.existsSync(chrome) ? { executablePath: chrome } : {}) });
const report = [];
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
  const page = await context.newPage();
  const errors = [], failures = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if (response.status() >= 400) failures.push([response.status(), response.url()]); });
  await context.route('https://reader-layout.test/**', async route => {
    const pathname = decodeURIComponent(new URL(route.request().url()).pathname);
    const lesson = pathname.match(/^\/school\/oj-addons\/readcontents-library\/lesson-(\d+)$/);
    if (lesson) {
      if (!pages.has(lesson[1])) pages.set(lesson[1], execFileSync(php, [fixture, '--fixture', `--lesson=lesson-${lesson[1]}`], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 }));
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: pages.get(lesson[1]) });
    }
    if (pathname === '/noop.js') return route.fulfill({ contentType: 'text/javascript', body: '' });
    if (pathname === '/empty.svg') return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg"/>' });
    let file;
    if (pathname.startsWith('/platform/')) file = path.resolve(workspace, 'oj-integration-rebuild/oj-platform', pathname.slice('/platform/'.length));
    if (pathname.startsWith('/plugin/')) file = path.resolve(workspace, 'readcontents-library', pathname.slice('/plugin/'.length));
    if (!file || !file.startsWith(workspace + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return route.fulfill({ status: 404, body: 'Missing reader fixture asset' });
    const mime = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.svg': 'image/svg+xml' }[path.extname(file)] || 'application/octet-stream';
    return route.fulfill({ contentType: mime, body: fs.readFileSync(file) });
  });
  const reader = () => page.locator('[data-rcis-reader]');
  const outline = () => page.locator('[data-rcis-reader-outline]');
  const handle = () => page.getByRole('separator', { name: '목차 너비 조절', exact: true });
  const toggle = () => page.locator('[data-rcis-outline-toggle]');
  const layout = () => page.locator('[data-rcis-reader-layout]');
  const article = () => page.locator('article.rcis-reader__content');
  const storage = key => page.evaluate(key => localStorage.getItem(key), key);
  const metrics = () => page.evaluate(() => {
    const root = document.querySelector('[data-rcis-reader]');
    const outline = root.querySelector('[data-rcis-reader-outline]');
    const handle = root.querySelector('[data-rcis-reader-resize]');
    const layout = root.querySelector('[data-rcis-reader-layout]');
    const article = root.querySelector('article');
    return { open: root.dataset.outlineOpen, width: outline.getBoundingClientRect().width, layout: layout.getBoundingClientRect().width,
      article: article.getBoundingClientRect().width, surface: article.querySelector('.contents-editor-content-surface').getBoundingClientRect().width,
      min: Number(handle.getAttribute('aria-valuemin')), max: Number(handle.getAttribute('aria-valuemax')), now: Number(handle.getAttribute('aria-valuenow')),
      outlineHidden: outline.hidden, handleHidden: handle.hidden, overflow: document.documentElement.scrollWidth - innerWidth, scroll: scrollY };
  });
  const expectWidth = value => poll(metrics, state => near(state.width, value) && near(state.now, value), `Outline width ${value}`);
  async function expectOpen(open) {
    await poll(metrics, state => state.open === String(open) && state.outlineHidden === !open, `Outline visibility ${open}`);
    assert.equal(await toggle().getAttribute('aria-expanded'), String(open));
  }
  async function clickToggleWithoutScrolling() {
    await toggle().evaluate(element => { element.focus({ preventScroll: true }); element.click(); });
  }
  async function drag(delta) {
    const box = await handle().boundingBox();
    const x = box.x + box.width / 2, y = box.y + Math.min(70, box.height / 2);
    await page.mouse.move(x, y); await page.mouse.down();
    await page.mouse.move(x + delta, y, { steps: 8 }); await page.mouse.up();
  }
  async function inspectTheme(expected) {
    const state = await page.evaluate(() => {
      const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      const rgba = value => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = value; ctx.fillRect(0, 0, 1, 1); const p = ctx.getImageData(0, 0, 1, 1).data; return [p[0], p[1], p[2], p[3] / 255]; };
      const luminance = color => color.slice(0, 3).map(c => c / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4).reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0);
      const background = element => {
        const ancestors = []; for (let node = element; node; node = node.parentElement) ancestors.unshift(node);
        return ancestors.reduce((base, node) => { const color = rgba(getComputedStyle(node).backgroundColor); return base.map((v, i) => i < 3 ? color[i] * color[3] + v * (1 - color[3]) : 1); }, [255, 255, 255, 1]);
      };
      const selectors = ['[data-rcis-outline-toggle]', '.rcis-reader__outline-heading', '.rcis-reader__topic', '.rcis-reader__chapter[open] summary', '.rcis-reader__chapter[open] .rcis-reader__section-title', '.rcis-reader__lesson[aria-current="page"]', '.rcis-reader__chapter[open] .rcis-reader__lesson:not([aria-current])', 'article h1', 'article .contents-editor-plate-content > p', '.contents-editor-quiz__choice-text'];
      const probes = selectors.map(selector => {
        const element = document.querySelector(selector);
        if (!element || !element.getClientRects().length) return null;
        const bg = background(element), fg = rgba(getComputedStyle(element).color), a = luminance(fg), b = luminance(bg);
        return { selector, contrast: (Math.max(a, b) + .05) / (Math.min(a, b) + .05) };
      }).filter(Boolean);
      const panel = document.querySelector('[data-rcis-reader-outline]');
      return { scheme: getComputedStyle(document.documentElement).colorScheme, overflow: document.documentElement.scrollWidth - innerWidth, probes,
        panelLuminance: panel.hidden ? null : luminance(background(panel)) };
    });
    assert.equal(state.scheme, expected);
    assert.ok(state.overflow <= 1, `No horizontal overflow: ${state.overflow}`);
    for (const probe of state.probes) assert.ok(probe.contrast >= (probe.selector === 'article h1' ? 3 : 4.5), `${expected} contrast: ${JSON.stringify(probe)}`);
    if (state.panelLuminance !== null) assert.ok(expected === 'dark' ? state.panelLuminance < .2 : state.panelLuminance > .7, 'Outline surface follows the platform mode');
    return state;
  }

  await page.goto(url(1));
  await reader().waitFor(); await expectOpen(true); await expectWidth(280);
  assert.equal(await handle().getAttribute('aria-orientation'), 'vertical');
  assert.equal(await toggle().getAttribute('aria-controls'), 'rcis-reader-outline');
  assert.equal(await outline().locator('a[aria-current="page"]').count(), 1);
  assert.equal(await outline().locator('.rcis-reader__lesson').count(), 24);
  assert.equal(await outline().locator('a[href$="/lesson-2"]').getAttribute('href'), url(2));
  assert.ok(!(await outline().textContent()).includes('SECRET_'));
  let state = await metrics();
  assert.ok(near(state.max, Math.min(560, state.layout - 480 - 12)));
  report.push({ check: 'default-public-outline-and-links', state });

  // Instrument the real article only with a form control to detect replacement;
  // the quiz below is produced and run by the real saved Plate body/public.js.
  await article().evaluate(element => {
    window.__readerArticle = element;
    const label = document.createElement('label'); label.textContent = '읽기 메모 ';
    const input = document.createElement('input'); input.setAttribute('aria-label', '읽기 메모'); label.append(input);
    element.querySelector('h1').after(label);
  });
  await page.getByLabel('읽기 메모', { exact: true }).fill('계속 읽는 중인 메모');
  await page.getByRole('radio', { name: '1 분류', exact: true }).check();
  await page.getByRole('button', { name: '정답 확인', exact: true }).click();
  await page.evaluate(() => scrollTo(0, 400));
  const beforeHide = await metrics();
  await clickToggleWithoutScrolling(); await expectOpen(false);
  state = await metrics();
  assert.ok(near(state.article, state.layout) && state.article > beforeHide.article + 200, 'Hidden outline gives its space to the article');
  assert.ok(state.surface > beforeHide.surface + 100, 'Hidden outline expands the body surface');
  assert.ok(near(state.scroll, beforeHide.scroll), 'Hiding must retain page scroll position');
  assert.equal(await handle().isVisible(), false);
  assert.equal(await article().evaluate(element => element === window.__readerArticle), true);
  assert.equal(await page.getByLabel('읽기 메모', { exact: true }).inputValue(), '계속 읽는 중인 메모');
  assert.equal(await page.getByRole('radio', { name: '1 분류', exact: true }).isChecked(), true);
  assert.equal(await page.locator('[data-contents-editor-quiz-result]').textContent(), '정답입니다.');
  await page.screenshot({ path: path.join(output, 'desktop-hidden.png') });
  await clickToggleWithoutScrolling(); await expectOpen(true);
  assert.ok(near((await metrics()).scroll, beforeHide.scroll), 'Showing must retain page scroll position');
  await outline().locator('[aria-current="page"]').evaluate(element => element.focus({ preventScroll: true }));
  await toggle().evaluate(element => element.click()); await expectOpen(false);
  assert.equal(await toggle().evaluate(element => document.activeElement === element), true, 'Focus must leave an outline that becomes hidden');
  await clickToggleWithoutScrolling(); await expectOpen(true);
  report.push({ check: 'show-hide-body-input-quiz-scroll-focus-preserved', before: beforeHide, hidden: state });

  await drag(120); await expectWidth(400);
  await handle().focus();
  for (const [key, width] of [['ArrowRight', 410], ['Shift+ArrowRight', 450], ['ArrowLeft', 440], ['Shift+ArrowLeft', 400], ['Home', 220], ['ArrowLeft', 220]]) {
    await handle().press(key); await expectWidth(width);
  }
  await inspectTheme('light');
  await handle().press('End'); state = await metrics(); await expectWidth(state.max);
  await handle().press('ArrowRight'); await expectWidth(state.max);
  await handle().dblclick(); await expectWidth(280);
  await drag(-1000); await expectWidth(220);
  await drag(2000); state = await metrics(); await expectWidth(state.max);
  const globalStyles = () => page.evaluate(() => [document.documentElement, document.body].map(e => ({ cursor: e.style.cursor, selection: e.style.userSelect })));
  const beforeStyles = await globalStyles();
  const box = await handle().boundingBox(), x = box.x + box.width / 2, y = box.y + 70;
  await page.mouse.move(x, y); await page.mouse.down(); await page.mouse.move(x - 60, y, { steps: 4 });
  await handle().dispatchEvent('pointercancel', { pointerId: 1, pointerType: 'mouse', bubbles: true });
  const cancelled = await metrics();
  await page.mouse.move(x - 180, y, { steps: 4 }); await page.mouse.up();
  await expectWidth(cancelled.width); assert.deepEqual(await globalStyles(), beforeStyles);
  await handle().focus(); await handle().press('Home');
  for (let i = 0; i < 5; i++) await handle().press('Shift+ArrowRight');
  await expectWidth(420); assert.equal(await storage(widthKey), '420');
  report.push({ check: 'drag-keyboard-bounds-default-cancellation', cancelled });

  await clickToggleWithoutScrolling(); await expectOpen(false); assert.equal(await storage(hiddenKey), 'true');
  await page.reload(); await expectOpen(false); assert.equal(await storage(widthKey), '420');
  await page.goto(url(2)); await expectOpen(false); assert.ok((await article().textContent()).includes('레슨 02'));
  await clickToggleWithoutScrolling(); await expectOpen(true); await expectWidth(420); assert.equal(await storage(hiddenKey), 'false');
  await page.reload(); await expectOpen(true); await expectWidth(420);
  const secondChapter = outline().locator('details').filter({ has: page.locator('a[href$="/lesson-13"]') });
  await secondChapter.locator('summary').click();
  await outline().locator('a[href$="/lesson-13"]').click();
  await page.waitForURL(url(13)); await expectOpen(true); await expectWidth(420);
  assert.ok((await outline().locator('a[aria-current="page"]').textContent()).includes('레슨 13'));
  assert.equal(await outline().locator('details[open] [aria-current="page"]').count(), 1);
  report.push({ check: 'preferences-reload-lesson-navigation-current-chapter', state: await metrics() });

  await handle().focus(); await handle().press('End'); const preferred = Number(await storage(widthKey));
  assert.equal(preferred, 560);
  await layout().evaluate(element => { element.style.width = '900px'; });
  const narrowContainer = await poll(metrics, value => near(value.width, value.max) && value.max < preferred, 'Container clamps the outline');
  assert.equal(await storage(widthKey), String(preferred)); assert.ok(narrowContainer.article >= 479);
  await layout().evaluate(element => { element.style.removeProperty('width'); }); await expectWidth(preferred);
  await page.setViewportSize({ width: 1024, height: 900 });
  const narrowWindow = await poll(metrics, value => near(value.width, value.max) && value.max < preferred, 'Narrow desktop clamps the outline');
  assert.equal(await storage(widthKey), String(preferred)); assert.ok(narrowWindow.article >= 479);
  await page.setViewportSize({ width: 1440, height: 900 }); await expectWidth(preferred);
  await handle().focus(); await page.setViewportSize({ width: 390, height: 900 }); await expectOpen(false);
  assert.equal(await toggle().evaluate(element => document.activeElement === element), true, 'Desktop-to-mobile must move focus out of the hidden separator');
  await page.setViewportSize({ width: 1440, height: 900 }); await expectOpen(true); await expectWidth(preferred);
  report.push({ check: 'container-window-clamp-preference-restoration-mobile-focus', narrowContainer, narrowWindow });

  for (const width of [1440, 1024, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url(1)); await expectOpen(width >= 1024);
    if (width < 1024) {
      assert.equal(await handle().isVisible(), false);
      await toggle().click(); await expectOpen(true);
      assert.equal(await page.getByRole('dialog').count(), 0, 'Mobile outline is inline, not a modal');
      const positions = await page.evaluate(() => ({ outline: document.querySelector('[data-rcis-reader-outline]').getBoundingClientRect().bottom, article: document.querySelector('article.rcis-reader__content').getBoundingClientRect().top }));
      assert.ok(positions.outline <= positions.article + 1, 'Mobile outline precedes the article');
      assert.equal(await storage(hiddenKey), 'false', 'Mobile show/hide must not alter the desktop preference');
    }
    const modes = [];
    for (const choice of ['light', 'dark']) {
      await page.getByRole('button', { name: choice === 'light' ? '라이트' : '다크', exact: true }).click();
      modes.push({ choice, state: await inspectTheme(choice) });
      await page.screenshot({ path: path.join(output, `reader-${width}-${choice}.png`) });
    }
    await page.getByRole('button', { name: '시스템', exact: true }).click();
    for (const os of ['dark', 'light']) {
      await page.emulateMedia({ colorScheme: os });
      modes.push({ choice: 'auto', os, state: await inspectTheme(os) });
    }
    if (width < 1024) {
      await toggle().click(); await expectOpen(false);
      assert.equal(await storage(widthKey), String(preferred));
      assert.equal(await storage(hiddenKey), 'false');
    }
    report.push({ check: 'responsive-inline-outline-and-screen-modes', width, modes });
  }
  assert.deepEqual(errors, [], 'No uncaught JavaScript errors'); assert.deepEqual(failures, [], 'All registered assets must load');
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ passed: true, report, errors, failures }, null, 2) + '\n');
  for (const [number, html] of pages) fs.writeFileSync(path.join(output, `fixture-lesson-${number}.html`), html);
  console.log(JSON.stringify({ passed: true, checks: report.length, screenModeStates: 16, output }));
  await context.close();
} finally {
  await browser.close();
}
