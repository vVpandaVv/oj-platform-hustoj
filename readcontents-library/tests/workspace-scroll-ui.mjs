import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(path.join(root, '_notes/readcontents-server-import-2026-09-12/editor-source/package.json'));
const { chromium, expect } = require('@playwright/test');
const assets = path.join(root, 'readcontents-library/assets/editor');
const output = path.join(root, '_notes/tmp/readcontents-scroll-2026-10-20');
fs.mkdirSync(output, { recursive: true });
const node = (id, type, title, parentId = null, order = 0) => ({
  id, type, title, parentId, order, slug: id, status: 'draft',
  contentType: 'reading', schemaVersion: '1.0.0', metadata: {},
});
const title = i => `레슨 ${String(i).padStart(2, '0')}`;
const trees = [{
  topic: node('topic', 'topic', '스크롤 검증 읽기자료'),
  chapters: [{
    chapter: node('chapter', 'chapter', '첫 번째 챕터', 'topic'),
    sections: [{
      section: node('section', 'section', '긴 목차', 'chapter'),
      lessons: Array.from({ length: 80 }, (_, i) => node(`lesson-${i + 1}`, 'lesson', title(i + 1), 'section', i)),
    }],
  }],
}, ...Array.from({ length: 12 }, (_, i) => ({
  topic: node(`extra-${i}`, 'topic', `추가 읽기자료 ${i + 1}`), chapters: [],
}))];
const documents = {};
function getDocument(id) {
  return documents[id] ??= {
    lessonId: id, schemaVersion: '1.0.0', revision: 1, assets: [], visuals: [],
    plateValue: [
      { type: 'h1', children: [{ text: `${id} 본문 시작` }] },
      ...Array.from({ length: 70 }, (_, i) => ({
        type: 'p', children: [{ text: `${i + 1}. 목차와 본문을 서로 독립적으로 움직이는지 확인하는 긴 읽기자료 본문입니다. 다른 레슨을 선택하면 본문의 처음부터 보여야 합니다.` }],
      })),
    ],
  };
}
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname.startsWith('/rest/')) {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    if (url.pathname.endsWith('/topics')) { res.end(JSON.stringify(trees)); return; }
    if (url.pathname.endsWith('/nodes')) { res.end('[]'); return; }
    const match = url.pathname.match(/\/lessons\/(lesson-\d+)\/document$/);
    if (match) {
      if (req.method === 'PUT') {
        let body = '';
        for await (const chunk of req) body += chunk;
        documents[match[1]] = { ...getDocument(match[1]), ...JSON.parse(body), revision: getDocument(match[1]).revision + 1 };
      }
      setTimeout(() => res.end(JSON.stringify(getDocument(match[1]))), 100);
      return;
    }
    res.writeHead(404).end('{}');
    return;
  }
  if (url.pathname === '/') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <link rel="stylesheet" href="/console.css"><link rel="stylesheet" href="/admin.css"><link rel="stylesheet" href="/workspace-layout.css">
      <style>body{margin:0;--ojp-header-height:64px}.test-header{height:64px;position:sticky;top:0;background:white;border-bottom:1px solid #ddd;z-index:50;padding:16px}.test-setup{margin-bottom:12px}.test-setup p{padding:24px}</style>
      </head><body class="ojp-app"><div class="ojp-console-shell"><header class="test-header">관리자 콘솔</header>
      <div class="ojp-console-layout"><main class="ojp-console-main"><div class="ojp-shell"><span hidden data-ojp-layout="immersive"></span>
      <details class="test-setup"><summary>서버 ZIP 가져오기 준비</summary><p>서버 설치 설정</p></details>
      <div id="contents-editor-admin-root" class="rcis-editor-root" data-rest-url="/rest/" data-can-publish="1" data-rest-nonce="test-only"></div>
      <div id="contents-editor-overlay-root"></div></div></main></div></div><script type="module" src="/admin.js"></script></body></html>`);
    return;
  }
  if (url.pathname === '/console.css') {
    res.setHeader('Content-Type', 'text/css');
    fs.createReadStream(path.join(root, 'oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css')).pipe(res);
    return;
  }
  const file = path.resolve(assets, url.pathname.slice(1));
  if (!file.startsWith(assets + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
    res.writeHead(404).end();
    return;
  }
  res.setHeader('Content-Type', { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2' }[path.extname(file)] ?? 'application/octet-stream');
  fs.createReadStream(file).pipe(res);
});
await new Promise(resolve => server.listen(process.argv.includes('--serve') ? Number(process.env.PORT ?? 8762) : 0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
if (process.argv.includes('--serve')) {
  console.log(`Editor fixture: ${base}`);
} else {
  const browser = await chromium.launch({ headless: true });
  const report = [];
  const pick = (scope, name) => scope.locator('button').filter({ has: scope.page().locator(`span[title="${name}"]`) }).first();
  const scrollTop = locator => locator.evaluate(el => el.scrollTop);
  const metrics = page => page.evaluate(() => {
    const workspace = document.querySelector('[data-contents-workspace]');
    const main = document.querySelector('[data-contents-content-scroll]');
    const outline = document.querySelector('[data-contents-outline-scroll]');
    return {
      pageX: document.documentElement.scrollWidth - innerWidth,
      pageY: document.documentElement.scrollHeight - innerHeight,
      windowY: scrollY,
      bottom: workspace.getBoundingClientRect().bottom,
      height: innerHeight,
      mainScrollable: main.scrollHeight > main.clientHeight,
      outlineScrollable: outline.clientHeight > 0 && outline.scrollHeight > outline.clientHeight,
      outerScrollable: workspace.scrollHeight > workspace.clientHeight,
    };
  });
  try {
    for (const width of [1440, 1024, 768, 390, 320]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.goto(base);
      const workspace = page.locator('[data-contents-workspace]');
      const card = page.getByRole('article', { name: '스크롤 검증 읽기자료', exact: true });
      await expect(card).toBeVisible();
      await expect(workspace).toHaveCSS('overflow-y', 'visible');
      await card.getByRole('button', { name: '열기', exact: true }).click();
      await page.locator('button').filter({ has: page.locator(`span[title="${title(1)}"]`) }).first().click();
      const editor = page.getByRole('textbox', { name: '레슨 본문 편집', exact: true });
      await expect(editor).toContainText('lesson-1 본문 시작');
      const content = page.locator('[data-contents-content-scroll]');
      const outline = page.locator('[data-contents-outline-scroll]');
      await expect.poll(async () => (await metrics(page)).pageY).toBe(0);
      const initial = await metrics(page);
      assert.equal(initial.pageX, 0);
      assert.equal(initial.outerScrollable, false);
      assert.ok(initial.mainScrollable);
      assert.ok(initial.bottom <= initial.height);

      if (width >= 1024) {
        assert.ok(initial.outlineScrollable);
        await outline.evaluate(el => { el.scrollTop = el.scrollHeight; });
        const position = await scrollTop(outline);
        assert.ok(position > 1000);
        assert.equal(await scrollTop(content), 0);
        await pick(outline, title(80)).click();
        await expect(editor).toContainText('lesson-80 본문 시작');
        assert.equal(await scrollTop(outline), position);
        assert.equal(await scrollTop(content), 0);
        await page.screenshot({ path: path.join(output, `last-lesson-${width}.png`) });
        await content.hover();
        await page.mouse.wheel(0, 800);
        await expect.poll(() => scrollTop(content)).toBeGreaterThan(0);
        assert.equal(await scrollTop(outline), position);
        await pick(outline, title(79)).click();
        await expect(editor).toContainText('lesson-79 본문 시작');
        assert.equal(await scrollTop(content), 0);
        assert.equal(await scrollTop(outline), position);
        await outline.focus();
        await outline.press('Home');
        await outline.press('PageUp');
        await expect.poll(() => scrollTop(outline)).toBeLessThan(position);
      } else {
        await content.evaluate(el => { el.scrollTop = 800; });
        await page.getByRole('button', { name: '목차 열기', exact: true }).click();
        const mobileOutline = page.locator('[data-contents-mobile-outline-scroll]');
        await mobileOutline.evaluate(el => { el.scrollTop = el.scrollHeight; });
        assert.equal(await scrollTop(content), 800);
        await page.screenshot({ path: path.join(output, `outline-${width}.png`) });
        await pick(mobileOutline, title(80)).click();
        await expect(page.getByRole('dialog', { name: '레슨 목차', exact: true })).toHaveCount(0);
        await expect(editor).toContainText('lesson-80 본문 시작');
        assert.equal(await scrollTop(content), 0);
      }
      await page.screenshot({ path: path.join(output, `editor-${width}.png`) });
      await content.evaluate(el => { el.scrollTop = 500; });
      const toolbar = page.locator('[data-contents-editor-fixed-toolbar]');
      const contentBox = await content.boundingBox();
      const toolbarBox = await toolbar.boundingBox();
      await page.screenshot({ path: path.join(output, `scrolled-${width}.png`) });
      const contentPadding = await content.evaluate(el => parseFloat(getComputedStyle(el).paddingTop));
      assert.ok(Math.abs(toolbarBox.y - contentBox.y - contentPadding) < 2,
        `toolbar sticks to the content pane: ${JSON.stringify({ toolbarBox, contentBox, contentPadding })}`);
      await expect(content).toHaveCSS('overscroll-behavior-y', 'contain');
      await content.evaluate(el => { el.scrollTop = el.scrollHeight; });
      await content.hover();
      await page.mouse.wheel(0, 1000);
      await expect.poll(async () => (await metrics(page)).windowY).toBe(0);
      if (width === 1440) {
        await editor.focus();
        await editor.press('Control+End');
        const saved = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().endsWith('/document'));
        await editor.pressSequentially(' Scroll position stays after saving.');
        const editingPosition = await scrollTop(content);
        assert.ok(editingPosition > 1000);
        await saved;
        await expect(workspace.locator('header [role="status"]')).toHaveText('저장됨');
        assert.equal(await scrollTop(content), editingPosition);
      }
      await content.evaluate(el => { el.scrollTop = 500; });
      await page.getByRole('button', { name: '보조 기능 열기', exact: true }).click();
      await page.getByRole('menuitem', { name: '보관함', exact: true }).click();
      await expect(page.getByRole('dialog', { name: '보관함', exact: true })).toBeVisible();
      await page.getByRole('dialog', { name: '보관함', exact: true }).getByRole('button', { name: '보관함 닫기', exact: true }).click();
      assert.equal(await scrollTop(content), 500);
      await page.setViewportSize({ width, height: 600 });
      await expect.poll(async () => (await metrics(page)).bottom).toBeLessThanOrEqual(600);
      await page.locator('.test-setup > summary').click();
      await expect.poll(async () => (await metrics(page)).bottom).toBeLessThanOrEqual(600);
      await expect.poll(async () => (await metrics(page)).pageY).toBe(0);
      await page.screenshot({ path: path.join(output, `short-${width}.png`) });
      assert.deepEqual(errors, []);
      report.push({ width, initial, resized: await metrics(page), errors });
      await page.getByRole('button', { name: '콘텐츠 라이브러리로 이동', exact: true }).click();
      await expect(workspace).toHaveAttribute('data-contents-workspace', 'library');
      await expect(workspace).toHaveCSS('overflow-y', 'visible');
      await expect(page.locator('[data-contents-content-scroll]')).toHaveCount(0);
      await page.close();
    }
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({ passed: true, viewports: report.length, output }));
  } finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
}
