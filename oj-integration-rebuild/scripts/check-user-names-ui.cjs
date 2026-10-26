const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'user-names');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  execFileSync(php, ['oj-platform/tests/Unit/user-names.php', '--render', output], { cwd: root });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const results = [];
    for (const screen of ['settings', 'users', 'edit', 'profile']) {
      for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.goto(pathToFileURL(path.join(output, `${screen}.html`)).href);
        if (screen === 'settings' && (await page.locator('[name="nickname"]').count() || await page.getByText('학생 이름').count())) {
          throw new Error('Self settings expose the student name.');
        }
        if (screen === 'edit' && await page.getByLabel('학생 이름 (관리용)', { exact: true }).inputValue() !== '김학생') {
          throw new Error('Management form must load the student name.');
        }
        if (screen === 'users' && !await page.getByRole('columnheader', { name: '학생 이름', exact: true }).isVisible()) {
          throw new Error('Management list must include the student name.');
        }
        if (screen === 'profile' && await page.getByText('학습자', { exact: true }).count()) {
          throw new Error('Public profile exposes the student name.');
        }
        // Check the form's natural end position so its sticky save bar is not over scrolled content.
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        const layout = await page.evaluate(() => {
          const controls = [...document.querySelectorAll('input:not([type="hidden"]), select, textarea, button')].filter(node => node.getClientRects().length);
          const boxes = controls.map(node => node.getBoundingClientRect());
          return {
            width: innerWidth,
            scrollWidth: document.documentElement.scrollWidth,
            overlap: boxes.some((a, i) => boxes.some((b, j) => i < j && Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1)),
          };
        });
        if (layout.scrollWidth > width + 1 || layout.overlap) throw new Error(JSON.stringify({ screen, ...layout }));
        await page.screenshot({ path: path.join(output, `${screen}-${width}.png`), fullPage: true });
        results.push({ screen, ...layout });
      }
    }
    fs.writeFileSync(path.join(output, 'layout.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
