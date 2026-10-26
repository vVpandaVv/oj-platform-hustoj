const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'release-hardening');
const html = execFileSync(php, ['oj-platform/tests/Support/render-test-data-workbench.php'], {
  cwd: root,
  encoding: 'utf8',
});
const css = fs.readFileSync(path.join(root, 'oj-platform/resources/ui/css/oj-ui-system.css'), 'utf8');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const results = [];
    await page.setViewportSize({ width: 390, height: 900 });
    await page.setContent(html);
    await page.addStyleTag({ content: css.replace(/\.ojp-file-workbench form\s*\{[^}]*\}/, '') });
    const baselineOverflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    if (!baselineOverflows) throw new Error('The fixture did not reproduce the original mobile overflow.');
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.setContent(html);
      await page.addStyleTag({ content: css });
      for (const selected of [false, true]) {
        await page.locator('[data-ojp-bulk-bar]').evaluate((bar, visible) => { bar.hidden = !visible; }, selected);
        const geometry = await page.evaluate(() => {
          const table = document.querySelector('.ojp-table-wrap');
          const workbench = document.querySelector('.ojp-file-workbench');
          const side = document.querySelector('.ojp-file-side');
          const list = workbench.firstElementChild;
          const a = list.getBoundingClientRect();
          const b = side.getBoundingClientRect();
          return {
            client: document.documentElement.clientWidth,
            scroll: document.documentElement.scrollWidth,
            tableClient: table.clientWidth,
            tableScroll: table.scrollWidth,
            overlaps: Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1
              && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1,
          };
        });
        results.push({ width, selected, ...geometry });
        if (geometry.scroll > geometry.client + 1 || geometry.overlaps) {
          throw new Error(`Layout regression: ${JSON.stringify(results.at(-1))}`);
        }
        await page.screenshot({ path: path.join(output, `test-data-${width}-${selected ? 'selected' : 'idle'}.png`), fullPage: true });
      }
    }
    fs.writeFileSync(path.join(output, 'layout.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
