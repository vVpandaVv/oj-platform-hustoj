import assert from 'node:assert/strict';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(import.meta.dirname, '..');
const prefix = '/oj-console/addons/oj-ai-dashboard/';
const worksheet = '01HZY3M4N5P6Q7R8S9T0V1W2X3';
const otherWorksheet = '01HZY3M4N5P6Q7R8S9T0V1W2X4';
const project = '01HZY3M4N5P6Q7R8S9T0V1W2X5';
const fixture = (await readFile(path.join(root, 'tests/fixtures/worksheet-evaluation-workbench-visual.html'), 'utf8'))
  .replaceAll('../../resources/', '/resources/')
  .replace('data-edit-url-template="#worksheets/{worksheet_id}"', `data-edit-url-template="${prefix}worksheets/{worksheet_id}"`);
const navigation = worksheetId => `<nav data-oj-ai-workflow data-workflow-base="${prefix}" data-workflow-worksheet="${worksheetId}" data-current-step="criteria"><a data-workflow-step="author">활동지 작성</a></nav>`;
const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith(prefix)) {
      let html = fixture;
      if (url.pathname === prefix + 'projects') {
        if (url.searchParams.get('stage') === 'author') {
          html = '<!doctype html><html lang="ko"><meta charset="utf-8"><h1>평가 활동지 작성</h1></html>';
        } else {
          html = html.replace('<main class="fixture-shell">', `<main class="fixture-shell" id="oj-ai-projects">${navigation(url.searchParams.has('mismatch') ? otherWorksheet : worksheet)}`);
        }
      } else if (url.pathname.includes('/worksheets/')) {
        html = `<!doctype html><html><meta charset="utf-8"><section id="oj-ai-worksheet-authoring-app">${navigation(worksheet)}</section><script src="/resources/js/worksheet-workflow.js"></script></html>`;
      }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(html);
      return;
    }
    const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(root + path.sep)) { res.writeHead(403).end(); return; }
    res.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css' }[path.extname(file)] || 'application/octet-stream'));
    res.end(await readFile(file));
  } catch (error) { res.writeHead(500).end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const base = `http://127.0.0.1:${server.address().port}${prefix}`;
const artifacts = path.join(root, 'tests/artifacts/worksheet-review-navigation');
await mkdir(artifacts, { recursive: true });
const results = [];
let currentPage;
try {
  for (const width of [1440, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 950 } });
    currentPage = page;
    page.setDefaultTimeout(10000);
    const errors = [], documents = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url()); });
    const scope = new URLSearchParams({ project_id: project, worksheet_id: worksheet, stage: 'review', history: '1', context_type: 'class', context_id: 'class:30', context_revision: 'fixture-revision' });
    const reviewUrl = base + 'projects?' + scope;
    const edit = () => page.getByRole('link', { name: '활동지 열기', exact: true });
    const assertDirectLink = async () => {
      await edit().waitFor();
      const target = new URL(await edit().getAttribute('href'));
      assert.equal(target.pathname, prefix + 'projects');
      assert.equal(target.searchParams.get('stage'), 'author');
      for (const key of ['project_id', 'worksheet_id', 'context_type', 'context_id', 'context_revision']) assert.equal(target.searchParams.get(key), scope.get(key));
      return target.href;
    };
    await page.goto(reviewUrl);
    const target = await assertDirectLink();
    await page.evaluate(() => document.addEventListener('ojai:before-navigation', event => event.preventDefault(), { once: true }));
    await edit().click();
    assert.equal(page.url(), reviewUrl, 'Pending edits can cancel navigation');
    const before = documents.length;
    await edit().click();
    await page.getByRole('heading', { name: '평가 활동지 작성' }).waitFor();
    assert.deepEqual(documents.slice(before), [target], 'One document navigation, no legacy editor document');
    await page.goBack();
    await assertDirectLink();
    await page.getByRole('button', { name: '이력 더 보기', exact: true }).click();
    const history = page.getByRole('button').filter({ hasText: '이전 점검' });
    await history.first().waitFor();
    assert(await history.count() > 0, 'Previous review fixture must be present');
    await history.first().click();
    await assertDirectLink();
    await page.screenshot({ path: path.join(artifacts, `review-${width}.png`), fullPage: true });
    await page.goto(reviewUrl + '&mismatch=1');
    await edit().waitFor();
    assert.equal(new URL(await edit().getAttribute('href'), base).pathname, prefix + 'worksheets/' + worksheet, 'Do not attach a different worksheet to the selected project');
    await page.goto(base + 'assessments?worksheet_id=' + worksheet);
    await edit().waitFor();
    assert.equal(new URL(await edit().getAttribute('href'), base).pathname, prefix + 'worksheets/' + worksheet, 'Unscoped worksheets retain their own editor');
    await page.goto(base + 'worksheets/' + worksheet + '?project_id=' + project);
    await page.getByRole('heading', { name: '평가 활동지 작성' }).waitFor();
    assert.equal(new URL(page.url()).searchParams.get('stage'), 'author', 'Legacy project editor defaults to author, not criteria');
    assert.equal(new URL(page.url()).searchParams.get('worksheet_id'), worksheet);
    assert.deepEqual(errors, []);
    results.push({ width, passed: true, directNavigations: documents.slice(before, before + 1), runtimeErrors: errors });
    await page.close();
  }
  await writeFile(path.join(artifacts, 'result.json'), JSON.stringify({ passed: true, results }, null, 2));
  console.log('PASS review navigation: direct project author link, scope preservation, history/back, unsaved guard, unrelated/standalone isolation, legacy default, desktop/mobile');
} catch (error) {
  if (currentPage && !currentPage.isClosed()) console.error(JSON.stringify({ url: currentPage.url(), text: await currentPage.locator('body').innerText() }));
  throw error;
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
