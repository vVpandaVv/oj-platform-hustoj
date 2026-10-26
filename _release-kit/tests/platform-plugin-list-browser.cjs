const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const css = fs.readFileSync(path.join(root, 'oj-integration-rebuild/oj-platform/resources/admin/plugin-list.css'), 'utf8');
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  let checks = 0;
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 700 } });
      await page.setContent('<table><tbody id="the-list">'
        + '<tr data-plugin="oj-platform/oj-platform.php"><td><strong>OJ Platform</strong><p>기본 플랫폼</p><button disabled>비활성화</button><div class="required-by"><p>Focus Tracker, OJ AI Dashboard, OJ ML Playgrounds, OJ PRIMM</p></div></td></tr>'
        + '<tr data-plugin="other/other.php"><td><strong>Other</strong><div class="required-by">Other dependent</div></td></tr>'
        + '<tr data-plugin="focus-tracker/focus-tracker.php"><td><strong>Focus Tracker</strong><div class="requires">OJ Platform 필요</div><button disabled>활성화</button></td></tr></tbody></table>');
      await page.addStyleTag({ content: css });
      assert.equal(await page.locator('[data-plugin="oj-platform/oj-platform.php"] .required-by').isVisible(), false);
      assert.equal(await page.locator('[data-plugin="other/other.php"] .required-by').isVisible(), true);
      assert.equal(await page.locator('[data-plugin="focus-tracker/focus-tracker.php"] .requires').isVisible(), true);
      assert.equal(await page.locator('[data-plugin="oj-platform/oj-platform.php"] strong').isVisible(), true);
      assert.equal(await page.locator('[data-plugin="oj-platform/oj-platform.php"] button').isDisabled(), true);
      assert.equal(await page.locator('[data-plugin="focus-tracker/focus-tracker.php"] button').isDisabled(), true);
      checks += 6;
      await page.close();
    }
    console.log(`Plugin list display: ${checks} browser checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
