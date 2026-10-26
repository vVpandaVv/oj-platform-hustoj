import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.OJAI_PHP || 'php';
const render = (...args) => execFileSync(php, args, { cwd: root, encoding: 'utf8' });
const editor = render('tests/fixtures/render-criteria-first-workflow.php', 'legacy').replaceAll('https://example.test', '');
const library = render('tests/fixtures/render-workflow-page.php', 'worksheets');
const fixture = await readFile(path.join(root, 'tests/fixtures/worksheet-workbench-visual.html'), 'utf8');
const prefix = '/oj-console/addons/oj-ai-dashboard/';
const worksheet = '01HZY3M4N5P6Q7R8S9T0V1W2X3';
const otherWorksheet = '01HZY3M4N5P6Q7R8S9T0V1W2X4';
const project = '01K00000000000000000090001';
const artifacts = path.join(root, 'tests/artifacts/worksheet-library-entry');
await mkdir(artifacts, { recursive: true });

function pageHtml(markup) {
  const injection = `<script>
    document.getElementById('oj-ai-worksheet-authoring-app').outerHTML = ${JSON.stringify(markup)};
    window.__entryRequests = [];
    window.__entryResolvers = [];
    const originalFetch = window.fetch;
    window.fetch = async (url, options = {}) => {
      window.__entryRequests.push({url: String(url), method: options.method || 'GET'});
      if (String(url).includes('/assessment-project')) {
        return new Promise(resolve => window.__entryResolvers.push(resolve));
      }
      if (/\\/worksheets\\?/.test(String(url))) {
        return {ok: true, json: async () => ({data: {items: [
          {id: '${worksheet}', title: '연결 활동지', state: 'draft'},
          {id: '${otherWorksheet}', title: '다른 활동지', state: 'draft'}
        ]}, meta: {next_cursor: null}})};
      }
      return originalFetch(url, options);
    };
    window.__prematureEditor = false;
    const sample = () => {
      const app = document.getElementById('oj-ai-worksheet-authoring-app');
      if (app?.dataset.view === 'edit' && app.getClientRects().length
        && app.querySelector('[data-role="save-status"]')?.textContent === '불러오는 중') window.__prematureEditor = true;
    };
    new MutationObserver(sample).observe(document.body, {childList:true, subtree:true, attributes:true});
    sample();
  </script><link rel="stylesheet" href="/resources/css/worksheet-workflow.css">`;
  return fixture.replace('  <script src="../../resources/assets/builder/plate-template-builder.js">',
    injection + '<script src="../../resources/assets/builder/plate-template-builder.js">')
    .replaceAll('../../resources/', '/resources/');
}

const server = createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    let html;
    if (url.pathname === prefix + 'worksheets') html = pageHtml(library);
    else if (url.pathname.startsWith(prefix + 'worksheets/')) html = pageHtml(editor);
    else if (url.pathname === prefix + 'projects') html = '<!doctype html><html lang="ko"><meta charset="utf-8"><h1>평가 프로젝트 활동지</h1></html>';
    if (html) { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
    const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(root + path.sep)) { response.writeHead(403).end(); return; }
    response.setHeader('Content-Type', ({'.js':'text/javascript', '.css':'text/css'}[path.extname(file)] || 'application/octet-stream'));
    response.end(await readFile(file));
  } catch (error) { response.writeHead(500).end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const results = [];
let currentPage;
try {
  for (const width of [1440, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 950 } });
    currentPage = page;
    page.setDefaultTimeout(30000);
    const errors = [], documents = [], dialogs = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('requestfailed', request => console.error('Request failed:', request.url(), request.failure()?.errorText));
    page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });
    page.on('request', request => {
      if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url());
    });
    const libraryUrl = base + prefix + 'worksheets';
    const legacyUrl = base + prefix + 'worksheets/' + worksheet;
    const resolveProject = async (value, status = 200, index = 0) => {
      await page.waitForFunction(i => !!window.__entryResolvers?.[i], index);
      await page.evaluate(({value, status, index}) => {
        window.__entryResolvers[index]({ok: status === 200, status, json: async () => status === 200
          ? {data: {project: value}} : {message: '평가 연결 확인 실패'}});
      }, {value, status, index});
    };
    const openLinked = () => page.getByRole('link', { name: '연결 활동지', exact: true }).click();
    await page.goto(libraryUrl);
    await openLinked();
    assert.equal(page.url(), libraryUrl, 'Lookup stays on library');
    const before = documents.length;
    await resolveProject({project_id: project});
    await page.getByRole('heading', {name: '평가 프로젝트 활동지'}).waitFor();
    assert.equal(documents.length, before + 1);
    assert.equal(new URL(page.url()).pathname, prefix + 'projects');
    assert.equal(new URL(page.url()).searchParams.get('stage'), 'author');
    assert.equal(new URL(page.url()).searchParams.get('worksheet_id'), worksheet);

    await page.goto(libraryUrl);
    await page.getByRole('button', {name:'연결 활동지 복사', exact:true}).click();
    await resolveProject(null);
    await page.waitForFunction(() => window.__entryResolvers.length === 2);
    assert.equal(await page.evaluate(() => window.__entryRequests.at(-1).method), 'POST');
    assert.match(await page.evaluate(() => window.__entryRequests.at(-1).url), /01HZY3M4N5P6Q7R8S9T0V1W2XG\/assessment-project$/);
    await resolveProject({project_id:project}, 200, 1);
    await page.getByRole('heading', {name:'평가 프로젝트 활동지'}).waitFor();
    assert.equal(new URL(page.url()).searchParams.get('worksheet_id'),'01HZY3M4N5P6Q7R8S9T0V1W2XG','Copy entry must select the copy, never the source');
    assert.equal(new URL(page.url()).pathname,prefix+'projects');

    // Old bookmarks / new-tab hrefs remain hidden until their destination is known.
    await page.goto(legacyUrl + '?stage=review&context_type=group&context_id=group%3A10');
    await page.locator('[data-worksheet-entry]').waitFor();
    await page.waitForFunction(() => window.__entryResolvers.length === 1);
    assert.equal(await page.locator('#oj-ai-worksheet-authoring-app').isVisible(), false);
    assert.equal(await page.evaluate(() => window.__prematureEditor), false);
    assert.equal(await page.evaluate(() => window.__entryRequests.filter(r => /worksheet-revisions/.test(r.url)).length), 0);
    // Give Chromium user activation so an erroneous beforeunload guard would show a dialog.
    await page.locator('[data-worksheet-entry-message]').click();
    await page.screenshot({path: path.join(artifacts, `loading-${width}.png`), fullPage:true});
    await resolveProject({project_id: project});
    await page.getByRole('heading', {name: '평가 프로젝트 활동지'}).waitFor();
    assert.equal(new URL(page.url()).searchParams.get('stage'), 'review');
    assert.equal(new URL(page.url()).searchParams.get('context_id'), 'group:10');
    assert.deepEqual(dialogs, [], 'No false leave confirmation during redirect');

    await page.goto(legacyUrl);
    await resolveProject(null, 403);
    await page.getByRole('button', {name: '다시 시도', exact:true}).waitFor();
    assert.equal(await page.locator('#oj-ai-worksheet-authoring-app').isVisible(), false);
    await page.getByRole('button', {name: '다시 시도', exact:true}).click();
    await resolveProject(null, 200, 1);
    await page.waitForFunction(() => window.__entryResolvers.length === 3);
    assert.equal(await page.locator('[data-role="preparation"]').count(), 0, 'Old criteria markup is absent, not hidden');
    assert.equal(await page.locator('[data-slate-editor]').count(), 0, 'Old entry never mounts an editor');
    assert.equal(await page.evaluate(() => window.__entryRequests.at(-1).method), 'POST', 'Unlinked entry uses explicit migration command');
    await resolveProject({project_id:project}, 200, 2);
    await page.getByRole('heading', {name:'평가 프로젝트 활동지'}).waitFor();
    assert.equal(new URL(page.url()).pathname, prefix + 'projects');
    assert.deepEqual(dialogs, []);
    await page.screenshot({path:path.join(artifacts, `migrated-${width}.png`), fullPage:true});
    await page.close();
    assert.deepEqual(errors, []);

    const race = await browser.newPage({viewport:{width,height:950}});
    currentPage = race;
    await race.goto(libraryUrl);
    await race.getByRole('link', {name:'연결 활동지',exact:true}).click();
    await race.getByRole('link', {name:'다른 활동지',exact:true}).click();
    await race.waitForFunction(() => window.__entryResolvers.length === 2);
    await race.evaluate(({project}) => window.__entryResolvers[0]({ok:true,json:async()=>({data:{project:{project_id:project}}})}), {project});
    assert.equal(race.url(), libraryUrl, 'Stale selection must not navigate');
    await race.evaluate(() => window.__entryResolvers[1]({ok:false,status:403,json:async()=>({message:'평가 연결 확인 실패'})}));
    await race.getByText('평가 연결 확인 실패', {exact:true}).waitFor();
    assert.equal(race.url(), libraryUrl, 'Denied selection stays in library');
    await race.close();
    results.push({width, passed:true, errors, dialogs});
  }
  await writeFile(path.join(artifacts, 'result.json'), JSON.stringify({passed:true, results}, null, 2));
  console.log('PASS library entry: direct project navigation, copied worksheet adoption, old bookmark entry shell, scope, no false dialog, retry, unlinked adoption, stale selection, desktop/mobile');
} catch (error) {
  if (currentPage && !currentPage.isClosed()) {
    console.error(await currentPage.evaluate(() => ({requests:window.__entryRequests, scripts:[...document.scripts].map(script=>script.src), state:document.readyState})));
    await currentPage.screenshot({path:path.join(artifacts,'failure.png'),fullPage:true});
  }
  throw error;
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
