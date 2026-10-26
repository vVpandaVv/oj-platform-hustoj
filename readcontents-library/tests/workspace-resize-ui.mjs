import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { Readable, Writable } from 'node:stream';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const workspace = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const output = path.join(workspace, '_notes/tmp/readcontents-resize-2026-10-25');
const require = createRequire(path.join(workspace, '_notes/readcontents-server-import-2026-09-12/editor-source/package.json'));
const bundledPlaywright = 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright';
const { chromium } = require(process.env.PLAYWRIGHT_PATH || (fs.existsSync(bundledPlaywright) ? bundledPlaywright : '@playwright/test'));
const storageKey = 'readcontents-outline-width';
// Import the existing fixture in serve-only mode and capture its real handler.
// Adapting its request/response streams avoids network sockets even on machines
// where sandbox policy blocks localhost. Its fixture data/API stay in one place.
let fixtureHandler;
const createServer = http.createServer;
const originalArguments = process.argv;
try {
  http.createServer = handler => {
    fixtureHandler = handler;
    return { listen(_port, _host, ready) { ready(); }, address() { return { port: 0 }; } };
  };
  process.argv = [...originalArguments, '--serve'];
  await import('./workspace-scroll-ui.mjs');
} finally {
  http.createServer = createServer;
  process.argv = originalArguments;
}
assert.equal(typeof fixtureHandler, 'function', 'Existing scroll fixture handler must be available');
async function requestFixture(url, request) {
  const body = request.postDataBuffer();
  const input = Readable.from(body ? [body] : []);
  input.url = url.pathname + url.search;
  input.method = request.method();
  input.headers = request.headers();
  return new Promise((resolve, reject) => {
    const chunks = [], headers = {};
    const response = new Writable({ write(chunk, _encoding, done) { chunks.push(Buffer.from(chunk)); done(); } });
    response.statusCode = 200;
    response.setHeader = (name, value) => { headers[name.toLowerCase()] = value; };
    response.writeHead = (status, values = {}) => {
      response.statusCode = status;
      for (const [name, value] of Object.entries(values)) response.setHeader(name, value);
      return response;
    };
    response.once('error', reject);
    response.once('finish', () => resolve({ status: response.statusCode, headers, body: Buffer.concat(chunks) }));
    Promise.resolve(fixtureHandler(input, response)).catch(reject);
  });
}
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function poll(read, accept, message, timeout = 7000) {
  const deadline = Date.now() + timeout;
  let value;
  do {
    value = await read();
    if (accept(value)) return value;
    await pause(50);
  } while (Date.now() < deadline);
  assert.fail(`${message}: ${JSON.stringify(value)}`);
}
const near = (actual, expected) => Math.abs(actual - expected) <= 1.5;
let browser;
try {
  const chrome = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
  browser = await chromium.launch({ headless: true, ...(fs.existsSync(chrome) ? { executablePath: chrome } : {}) });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const errors = [], failures = [], writes = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if (response.status() >= 400) failures.push([response.status(), response.url()]); });
  page.on('request', request => { if (request.method() === 'PUT' && request.url().endsWith('/document')) writes.push(request.postData()); });
  // All browser requests stay on this intercepted origin, with no local socket.
  await context.route('https://readcontents-resize.test/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/ai/copilot') {
      await route.fulfill({ contentType: 'text/plain; charset=utf-8', body: '0' });
      return;
    }
    if (url.pathname.startsWith('/fonts/pretendard/')) {
      const fonts = path.resolve(workspace, 'oj-integration-rebuild/oj-platform/resources/ui/fonts/pretendard');
      const file = path.resolve(fonts, path.basename(url.pathname));
      if (file.startsWith(fonts + path.sep) && fs.existsSync(file)) {
        await route.fulfill({ contentType: 'font/woff2', body: fs.readFileSync(file) });
        return;
      }
    }
    await route.fulfill(await requestFixture(url, route.request()));
  });
  fs.mkdirSync(output, { recursive: true });
  const report = [];
  const handle = () => page.getByRole('separator', { name: '목차 너비 조절', exact: true });
  const split = () => page.locator('[data-contents-workspace-split]');
  const outline = () => page.locator('[data-contents-outline-scroll]');
  const content = () => page.locator('[data-contents-content-scroll]');
  const editor = () => page.getByRole('textbox', { name: '레슨 본문 편집', exact: true });
  const pick = (scope, index) => scope.locator('button').filter({ has: page.locator(`span[title="레슨 ${String(index).padStart(2, '0')}"]`) }).first();
  const storedWidth = () => page.evaluate(key => Number(localStorage.getItem(key)), storageKey);
  async function metrics() {
    return page.evaluate(() => {
      const grid = document.querySelector('[data-contents-workspace-split]');
      const separator = document.querySelector('[role="separator"][aria-label="목차 너비 조절"]');
      const outline = document.querySelector('[data-contents-outline-scroll]');
      const content = document.querySelector('[data-contents-content-scroll]');
      return {
        grid: grid?.getBoundingClientRect().width,
        width: outline?.getBoundingClientRect().width,
        mainWidth: content?.getBoundingClientRect().width,
        min: Number(separator?.getAttribute('aria-valuemin')),
        max: Number(separator?.getAttribute('aria-valuemax')),
        now: Number(separator?.getAttribute('aria-valuenow')),
        outlineTop: outline?.scrollTop,
        contentTop: content?.scrollTop,
        overflow: document.documentElement.scrollWidth - innerWidth,
      };
    });
  }
  async function expectWidth(value, message) {
    return poll(metrics, state => near(state.width, value) && near(state.now, value), message);
  }
  async function openLesson(index = 1) {
    const card = page.getByRole('article', { name: '스크롤 검증 읽기자료', exact: true });
    await card.waitFor();
    await card.getByRole('button', { name: '열기', exact: true }).click();
    await pick(page, index).click();
    await poll(() => editor().textContent(), text => text.includes(`lesson-${index} 본문 시작`), 'Lesson must open');
  }
  async function dragBy(delta) {
    const box = await handle().boundingBox();
    assert.ok(box?.width > 0, 'Visible separator must have a pointer target');
    const x = box.x + box.width / 2, y = box.y + Math.min(80, box.height / 2);
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + delta, y, { steps: 8 });
    await page.mouse.up();
  }

  await page.goto('https://readcontents-resize.test/');
  await openLesson();
  await handle().waitFor();
  assert.equal(await handle().getAttribute('aria-orientation'), 'vertical');
  assert.equal(await handle().getAttribute('tabindex'), '0');
  let state = await expectWidth(280, 'Default outline width');
  assert.equal(state.min, 220);
  assert.ok(near(state.max, Math.min(560, state.grid - 480 - 12)), `Computed maximum: ${JSON.stringify(state)}`);
  assert.ok(state.mainWidth >= 480, 'Body must keep its minimum readable width');
  await page.screenshot({ path: path.join(output, 'desktop-before.png') });
  report.push({ check: 'default-and-accessibility', state });

  // An edit is saved first; resizing must not replace its editor/document.
  await editor().focus();
  await editor().press('Control+End');
  await editor().pressSequentially(' 너비 조절 후에도 보존되는 본문.');
  await poll(() => page.locator('[data-contents-workspace] header [role="status"]').textContent(), value => value === '저장됨', 'Edited lesson must save');
  const writesBeforeResize = writes.length;
  await content().evaluate(element => { element.scrollTop = 500; });
  await outline().evaluate(element => { element.scrollTop = 700; });
  const beforeDrag = await metrics();
  await dragBy(120);
  state = await expectWidth(400, 'Pointer drag should enlarge the outline');
  assert.ok(near(state.outlineTop, beforeDrag.outlineTop), 'Outline scroll position must survive resizing');
  assert.ok(state.contentTop > 100, 'Body scroll must not reset to the beginning');
  assert.ok((await editor().textContent()).includes('너비 조절 후에도 보존되는 본문.'), 'Current edit must survive resizing');
  assert.equal(writes.length, writesBeforeResize, 'Resizing must not save or remount a document');
  await page.screenshot({ path: path.join(output, 'desktop-after-drag.png') });
  report.push({ check: 'pointer-drag-edit-and-scroll-preserved', before: beforeDrag, state });

  await handle().focus();
  await handle().press('ArrowRight'); await expectWidth(410, 'ArrowRight increments by 10');
  await handle().press('Shift+ArrowRight'); await expectWidth(450, 'Shift+ArrowRight increments by 40');
  await handle().press('ArrowLeft'); await expectWidth(440, 'ArrowLeft decrements by 10');
  await handle().press('Shift+ArrowLeft'); await expectWidth(400, 'Shift+ArrowLeft decrements by 40');
  await handle().press('Home'); await expectWidth(220, 'Home selects minimum');
  await handle().press('ArrowLeft'); await expectWidth(220, 'Keyboard minimum is clamped');
  await handle().press('End');
  state = await metrics(); await expectWidth(state.max, 'End selects available maximum');
  await handle().press('ArrowRight'); await expectWidth(state.max, 'Keyboard maximum is clamped');
  await handle().dblclick(); await expectWidth(280, 'Double click restores default');
  await dragBy(-1000); await expectWidth(220, 'Pointer minimum is clamped');
  await dragBy(2000); state = await metrics(); await expectWidth(state.max, 'Pointer maximum is clamped');
  report.push({ check: 'keyboard-default-and-pointer-bounds', state });

  const dragStyles = () => page.evaluate(() => [document.documentElement, document.body].map(element => ({ userSelect: element.style.userSelect, cursor: element.style.cursor })));
  const stylesBeforeCancel = await dragStyles();
  const box = await handle().boundingBox();
  const x = box.x + box.width / 2, y = box.y + 80;
  await page.mouse.move(x, y); await page.mouse.down();
  await page.mouse.move(x - 60, y, { steps: 4 });
  const cancelled = await metrics();
  await handle().dispatchEvent('pointercancel', { pointerId: 1, pointerType: 'mouse', bubbles: true });
  const afterCancel = await metrics();
  await page.mouse.move(x - 180, y, { steps: 4 });
  await page.mouse.up();
  await expectWidth(afterCancel.width, 'A cancelled drag must stop changing the width');
  assert.deepEqual(await dragStyles(), stylesBeforeCancel, 'A cancelled drag must restore text selection and cursor styles');
  await handle().focus(); await handle().press('Home'); await expectWidth(220, 'Controls must remain usable after pointer cancellation');
  report.push({ check: 'pointer-cancellation', cancelled, afterCancel });

  for (let i = 0; i < 5; i++) await handle().press('Shift+ArrowRight');
  await expectWidth(420, 'Preferred width before reopening');
  assert.equal(await storedWidth(), 420);
  await page.reload(); await openLesson(); await expectWidth(420, 'Preferred width survives reload');
  await page.getByRole('button', { name: '콘텐츠 라이브러리로 이동', exact: true }).click();
  await openLesson(2); await expectWidth(420, 'Preferred width survives library and lesson navigation');
  report.push({ check: 'storage-reload-and-view-navigation', state: await metrics(), stored: await storedWidth() });

  // Container changes and window changes must clamp the current width without
  // overwriting the wider preference that fits again when space is restored.
  await handle().focus(); await handle().press('End');
  const preferred = await storedWidth();
  assert.equal(preferred, 560);
  await split().evaluate(element => { element.style.width = '900px'; });
  state = await poll(metrics, current => near(current.width, current.max) && current.max < preferred, 'Narrow container must clamp width');
  assert.equal(await storedWidth(), preferred, 'Container clamping must retain preferred width');
  assert.ok(state.mainWidth >= 479, 'Narrow container must preserve body width');
  const narrowContainer = state;
  await split().evaluate(element => { element.style.removeProperty('width'); });
  await expectWidth(preferred, 'Expanded container restores preference');
  await page.setViewportSize({ width: 1024, height: 900 });
  state = await poll(metrics, current => near(current.width, current.max) && current.max < preferred, 'Narrow desktop must clamp width');
  assert.equal(await storedWidth(), preferred, 'Window clamping must retain preferred width');
  assert.ok(state.overflow <= 1, 'Narrow desktop must not overflow horizontally');
  await page.screenshot({ path: path.join(output, 'desktop-1024-clamped.png') });
  report.push({ check: 'container-and-window-clamping', preferred, container: narrowContainer, window: state });
  await page.setViewportSize({ width: 1440, height: 900 });
  await expectWidth(preferred, 'Expanded desktop restores preference');

  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await poll(async () => await handle().count() === 0 || !await handle().isVisible(), value => value, 'Mobile must hide the separator');
    await page.getByRole('button', { name: '목차 열기', exact: true }).click();
    const drawer = page.getByRole('dialog', { name: '레슨 목차', exact: true });
    await drawer.waitFor();
    const mobileOutline = page.locator('[data-contents-mobile-outline-scroll]');
    await mobileOutline.evaluate(element => { element.scrollTop = element.scrollHeight; });
    await page.screenshot({ path: path.join(output, `mobile-${width}-outline.png`) });
    await pick(mobileOutline, 80).click();
    await poll(() => drawer.count(), value => value === 0, 'Choosing a mobile lesson closes the drawer');
    await poll(() => editor().textContent(), text => text.includes('lesson-80 본문 시작'), 'Mobile lesson selection must work');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    assert.ok(overflow <= 1, `Mobile horizontal overflow: ${overflow}`);
    assert.equal(await storedWidth(), preferred, 'Mobile layout must retain the desktop preference');
    await page.screenshot({ path: path.join(output, `mobile-${width}-editor.png`) });
    report.push({ check: 'mobile-drawer', width, overflow, stored: await storedWidth() });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await expectWidth(preferred, 'Desktop preference survives mobile layouts');
  assert.deepEqual(errors, [], 'No uncaught browser errors');
  assert.deepEqual(failures, [], 'No missing fixture or bundle requests');
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ passed: true, report, errors, failures }, null, 2) + '\n');
  console.log(JSON.stringify({ passed: true, checks: report.length, output }));
} finally {
  if (browser) await browser.close();
}
