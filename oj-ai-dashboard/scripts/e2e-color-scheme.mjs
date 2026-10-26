import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile, mkdir } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(import.meta.dirname, '..');
const platform = path.resolve(process.env.OJ_PLATFORM_SOURCE || path.join(root, '../oj-integration-rebuild/oj-platform'));
const php = process.env.OJAI_PHP || 'php';
const render = (...args) => execFileSync(php, args, {
  cwd: root, encoding: 'utf8', env: {...process.env, OJAI_SCREEN_MODE: '1'},
});
const picker = render('-r', `require '${platform.replaceAll('\\', '/')}/src/Ui/Component/ColorSchemeChoice.php'; echo CodingPanda\\OjPlatform\\Ui\\Component\\ColorSchemeChoice::render();`);
const renderer = await readFile(path.join(platform, 'src/Ui/View/HtmlDocumentRenderer.php'), 'utf8');
// Exercise the platform's actual pre-paint preference initializer, not a second implementation.
const init = renderer.match(/'<script>\(function\(\)\{try\{var s=[\s\S]+?<\/script>'/)[0]
  .split(/\r?\n/).map(line => line.trim().replace(/^\.\s*/, '').slice(1, -1)).join('');
const styles = ['admin-shell', 'assignment-workbench', 'worksheet-authoring-workbench',
  'worksheet-evaluation-workbench', 'submission-evaluation-review-workbench', 'report-dashboard',
  'worksheet-workflow', 'assessment-projects', 'external-ai-consent-settings',
  'assessment-configuration-settings', 'data-management-settings', 'operations-diagnostics',
  'student-assignments', 'student-results'];
const head = `<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${init}
  <link rel="stylesheet" href="/platform/resources/ui/css/oj-ui-system.css">
  <link rel="stylesheet" href="/resources/assets/builder/plate-template-builder.css">
  ${styles.map(name => `<link rel="stylesheet" href="/resources/css/${name}.css">`).join('')}`;
const pages = new Map();
for (const name of ['projects', 'worksheets', 'assignment', 'assessment', 'report', 'criteria', 'settings']) {
  const markup = render('tests/fixtures/render-workflow-page.php', name);
  pages.set('/' + name, `<!doctype html><html lang="ko" data-ojp-scheme="auto"><head>${head}</head>
    <body class="ojp-body">${picker}<main class="ojp-shell">${markup}</main>
    <script src="/platform/resources/ui/js/oj-ui.js"></script></body></html>`);
}
let editor = await readFile(path.join(root, 'tests/fixtures/worksheet-workbench-visual.html'), 'utf8');
editor = editor.replace('<html lang="ko">', '<html lang="ko" data-ojp-scheme="auto">')
  .replace(/<style>[\s\S]*?<\/style>/, '').replace('</head>', head + '</head>')
  .replace('<body>', `<body class="ojp-body">${picker}`)
  .replaceAll('../../resources/', '/resources/')
  .replace('</body>', '<script src="/platform/resources/ui/js/oj-ui.js"></script></body>');
pages.set('/editor', editor);
for (const name of ['student-assignments', 'student-result']) {
  const fixture = await readFile(path.join(root, `tests/fixtures/${name}-visual.html`), 'utf8');
  pages.set('/' + name, fixture.replace('<html lang="ko">', '<html lang="ko" data-ojp-scheme="auto">')
    .replace(/<style>[\s\S]*?<\/style>/, '').replace('</head>', head + '</head>')
    .replace('<body>', `<body class="ojp-body">${picker}`)
    .replaceAll('../../resources/', '/resources/')
    .replace('</body>', '<script src="/platform/resources/ui/js/oj-ui.js"></script></body>'));
}
const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (pages.has(url.pathname)) { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(pages.get(url.pathname)); return; }
    const isPlatform = url.pathname.startsWith('/platform/');
    const base = isPlatform ? platform : root;
    const file = path.resolve(base, '.' + (isPlatform ? url.pathname.slice(9) : url.pathname));
    if (!file.startsWith(base + path.sep)) { res.writeHead(403).end(); return; }
    res.setHeader('Content-Type', ({'.css':'text/css', '.js':'text/javascript'}[path.extname(file)] || 'application/octet-stream'));
    res.end(await readFile(file));
  } catch { res.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const {chromium} = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const artifacts = path.join(root, 'tests/artifacts/color-scheme');
await mkdir(artifacts, {recursive:true});
const base = `http://127.0.0.1:${server.address().port}`;
const results = [];
try {
  for (const width of [1440, 390]) {
    const page = await browser.newPage({viewport:{width, height:950}, colorScheme:'light'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const mode = async (choice, expected) => {
      await page.locator(`[data-ojp-scheme-choice="${choice}"]`).click();
      await page.waitForFunction(expected => getComputedStyle(document.documentElement).colorScheme === expected, expected);
      assert.equal(await page.locator(`[data-ojp-scheme-choice="${choice}"]`).getAttribute('aria-pressed'), 'true');
    };
    const ink = () => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ojp-color-ink').trim());
    for (const route of pages.keys()) {
      await page.goto(base + route);
      await mode('light', 'light');
      const lightInk = await ink();
      await mode('dark', 'dark');
      assert.notEqual(await ink(), lightInk, `${route}: palette changes`);
      const control = page.locator('main input:not([type="checkbox"]):visible, main select:visible, .oj-ai-workbench input:visible').first();
      if (await control.count()) {
        const colors = await control.evaluate(el => ({bg:getComputedStyle(el).backgroundColor, fg:getComputedStyle(el).color}));
        assert.notEqual(colors.bg, 'rgb(255, 255, 255)', `${route}: dark input background`);
        assert.notEqual(colors.bg, colors.fg, `${route}: readable input`);
      }
      await page.reload();
      assert.equal(await page.evaluate(() => document.documentElement.dataset.ojpScheme), 'dark');
      assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme), 'dark');
      if (route === '/editor') {
        await page.locator('[data-slate-editor]').waitFor();
        const canvas = await page.locator('.hws-template-editor-page').evaluate(el => ({bg:getComputedStyle(el).backgroundColor, fg:getComputedStyle(el).color}));
        assert.equal(canvas.bg, 'rgb(28, 28, 30)');
        assert.equal(canvas.fg, 'rgb(245, 245, 247)');
        const drawingBackgrounds = await page.evaluate(() => [
          'hws-response-node__student-drawing', 'hws-response-node__image', 'hws-excalidraw-response__canvas',
        ].map(className => {
          const sample = document.createElement('div');
          sample.className = className;
          document.getElementById('ws-plate-template-builder-root').append(sample);
          const color = getComputedStyle(sample).backgroundColor;
          sample.remove();
          return color;
        }));
        assert.deepEqual(drawingBackgrounds, Array(3).fill('rgb(255, 255, 255)'), 'Drawing/image source backgrounds retain their original colors');
      }
      if (route.startsWith('/student-')) {
        await page.locator(route === '/student-result' ? '.oj-ai-result__title' : '.oj-ai-student-card__title').first().waitFor();
        assert.equal(await page.locator('.oj-ai-student-page').evaluate(el => getComputedStyle(el).color), 'rgb(245, 245, 247)');
      }
      await page.screenshot({path:path.join(artifacts, `${route.slice(1)}-dark-${width}.png`), fullPage:true});
      await mode('auto', 'light');
      await page.emulateMedia({colorScheme:'dark'});
      await page.waitForFunction(() => getComputedStyle(document.documentElement).colorScheme === 'dark');
      assert.equal(await page.evaluate(() => localStorage.getItem('ojp-color-scheme')), null);
      await mode('light', 'light');
      await page.emulateMedia({colorScheme:'light'});
      results.push({route, width, result:'PASS'});
    }
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log(JSON.stringify(results));
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
