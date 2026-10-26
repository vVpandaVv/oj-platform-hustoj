const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'ai-usage');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const htmlPath = path.join(output, 'usage.html');
  execFileSync(php, ['oj-platform/tests/Unit/ai-usage.php', '--render', htmlPath], { cwd: root });
  const server = http.createServer((request, response) => {
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    response.end(fs.readFileSync(htmlPath));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  let browser;
  try {
    browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const url = `http://127.0.0.1:${server.address().port}/oj-console/settings/ai-usage`;
    const results = [];
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url);
      await page.getByText('세부 조건', { exact: true }).click();
      await page.locator('[name="purpose"]').selectOption('addon');
      await page.locator('[name="group_by"]').selectOption('model_key');
      await page.locator('[name="addon_id"]').fill('reader');
      const geometry = await page.evaluate(() => {
        const controls = [...document.querySelectorAll('form input, form select, form button')].filter(node => node.getClientRects().length);
        const boxes = controls.map(node => node.getBoundingClientRect());
        const overlap = boxes.some((a, i) => boxes.some((b, j) => i < j && Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1));
        return {
          width: innerWidth,
          scrollWidth: document.documentElement.scrollWidth,
          overlap,
          styled: getComputedStyle(controls[0]).borderRadius !== '0px',
          minimumHeight: Math.min(...boxes.map(box => box.height)),
          tables: [...document.querySelectorAll('.ojp-table-wrap')].map(node => ({ width: node.clientWidth, scroll: node.scrollWidth, overflow: getComputedStyle(node).overflowX })),
        };
      });
      if (geometry.scrollWidth > width + 1 || geometry.overlap || !geometry.styled || geometry.minimumHeight < 32) throw new Error(JSON.stringify(geometry));
      await page.getByRole('button', { name: '조회', exact: true }).click();
      await page.waitForURL(/purpose=addon/);
      const query = new URL(page.url()).searchParams;
      if (query.get('group_by') !== 'model_key' || query.get('addon_id') !== 'reader') throw new Error('GET filters lost.');
      const next = page.getByRole('link', { name: '다음', exact: true });
      if (!(await next.getAttribute('href')).includes('page=2')) throw new Error('Pagination missing.');
      await page.getByText('측정 정보', { exact: true }).first().click();
      await page.screenshot({ path: path.join(output, `ai-usage-${width}.png`), fullPage: true });
      results.push(geometry);
    }
    if (errors.length) throw new Error(errors.join('\n'));
    fs.writeFileSync(path.join(output, 'layout.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
