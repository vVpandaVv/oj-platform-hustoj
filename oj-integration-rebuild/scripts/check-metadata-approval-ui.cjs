const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist/verification/metadata-approval');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  execFileSync(php, ['oj-platform/tests/Unit/problem-metadata-approval.php', '--render', output], { cwd: root });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    const layouts = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => { errors.push('Executable diagnostic text'); dialog.dismiss(); });
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const name of ['mixed', 'all-failed', 'escaped']) {
        await page.goto(pathToFileURL(path.join(output, `${name}.html`)).href);
        await page.getByRole('heading', { name: 'AI 메타데이터 승인 결과', exact: true }).waitFor();
        assert.equal(await page.locator('tbody tr').count(), name === 'escaped' ? 1 : 2);
        const reviewLinks = page.getByRole('link', { name: '검토', exact: true });
        assert.equal(await reviewLinks.count(), name === 'escaped' ? 1 : 2);
        const labels = await page.locator('.ojp-status-badge, thead th, tbody .ojp-button').evaluateAll(nodes => nodes.map(node => {
          const range = document.createRange();
          range.selectNodeContents(node);
          return { text: node.textContent, lines: range.getClientRects().length };
        }));
        for (const label of labels) assert.equal(label.lines, 1, `Unexpected wrapping: ${label.text}`);
        for (const link of await reviewLinks.all()) {
          assert.match(await link.getAttribute('href'), /^\/oj-console\/problems\/\d+\/edit\?metadata_draft=draft%3Av1%3A/);
          await link.scrollIntoViewIfNeeded();
          const box = await link.boundingBox();
          assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1, 'Review link must remain reachable.');
        }
        const geometry = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth }));
        assert.ok(geometry.scrollWidth <= width + 1, JSON.stringify(geometry));
        if (name === 'escaped') {
          assert.equal(await page.getByText('<script>alert("secret")</script>', { exact: true }).count(), 1);
        }
        await page.locator('.ojp-table-wrap').evaluate(node => { node.scrollLeft = 0; });
        await page.screenshot({ path: path.join(output, `${name}-${width}.png`), fullPage: true });
        layouts.push({ name, ...geometry });
      }
    }
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ layouts, errors }, null, 2));
    console.log(JSON.stringify({ layouts, errors }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
