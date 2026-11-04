const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const root = path.resolve(__dirname, '../..');
const output = process.env.OJ_DIAGNOSTIC_ARTIFACTS;
const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(root, 'tests/Fixtures/account-protection-view.php')], { encoding: 'utf8' });
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH });
  try {
    if (output) fs.mkdirSync(output, { recursive: true });
    for (const [name, width, height] of [['desktop', 1280, 900], ['mobile', 390, 844]]) {
      const page = await browser.newPage({ viewport: { width, height } });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.setContent(html);
      await page.getByRole('heading', { name: '계정 보호 진단', exact: true }).waitFor();
      check(await page.getByText('oj_manage_settings', { exact: true }).first().isVisible(), `${name}: actual blocked capability is visible`);
      check(await page.getByText('애드온 학습자 권한 필터 실행에 실패해 초기화를 차단했습니다.', { exact: true }).isVisible(), `${name}: filter failure has a specific explanation`);
      check(await page.getByText('WordPress 계정을 조회하는 중 오류가 발생했습니다.', { exact: true }).isVisible(), `${name}: lookup failure has a separate explanation`);
      const summaries = page.getByText('계정 보호 진단 상세', { exact: true });
      check(await summaries.count() === 3, `${name}: each rejected row has diagnostic details`);
      await summaries.nth(0).click();
      check(await page.getByText('auth.account_protection.addon_filter_failed', { exact: true }).isVisible(), `${name}: details open to the recorded failure code`);
      check(await page.getByText('RuntimeException', { exact: true }).isVisible(), `${name}: safe exception category is visible`);
      check(await page.getByText('sample-addon/Defaults.php:25', { exact: true }).isVisible(), `${name}: safe source filename and line are visible`);
      await summaries.nth(0).click();
      check(!await page.getByText('auth.account_protection.addon_filter_failed', { exact: true }).isVisible(), `${name}: details can be collapsed`);
      for (const summary of await summaries.all()) await summary.click();
      check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${name}: long capability keys do not overflow the page`);
      check(await page.locator('.ojp-table-wrap').evaluate(element => element.scrollWidth <= element.clientWidth + 1), `${name}: diagnostic details fit without horizontal scrolling`);
      check(await page.locator('input[type="password"], form').count() === 0, `${name}: inspection has no credential-changing controls`);
      check(errors.length === 0, `${name}: no browser errors`);
      if (output) await page.screenshot({ path: path.join(output, `account-protection-${name}.png`), fullPage: true });
      await page.close();
    }
    console.log(`Account protection diagnostics UI: ${checks} checks passed.`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
