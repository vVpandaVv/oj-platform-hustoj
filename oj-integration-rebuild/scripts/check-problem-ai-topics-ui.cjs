const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist/verification/problem-ai-topics');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  execFileSync(php, ['oj-platform/tests/Unit/problem-ai-topics.php', '--render', output], { cwd: root });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => { errors.push('Unexpected executable AI output'); dialog.dismiss(); });
    const layouts = [];
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(pathToFileURL(path.join(output, 'topic-validation.html')).href);
      await page.getByText('주제 검증 상세', { exact: true }).click();
      const detail = page.locator('details');
      assert.equal(await detail.evaluate(node => node.open), true);
      assert.equal(await detail.locator('tbody tr').count(), 3);
      assert.equal(await detail.getByText('implementation', { exact: true }).count(), 1);
      assert.equal(await detail.getByText('<script>alert(1)</script>', { exact: true }).count(), 1);
      assert.equal(await detail.getByText('retired', { exact: true }).count(), 1);
      const geometry = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth }));
      assert.ok(geometry.scrollWidth <= width + 1, JSON.stringify(geometry));
      await page.screenshot({ path: path.join(output, `topics-${width}.png`), fullPage: true });
      await page.getByText('주제 검증 상세', { exact: true }).click();
      assert.equal(await detail.evaluate(node => node.open), false);
      layouts.push(geometry);
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ layouts, errors }, null, 2));
    console.log(JSON.stringify({ layouts, errors }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
