const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const root = path.resolve(__dirname, '../..');
const plugin = path.join(root, 'oj-integration-rebuild/oj-platform');
const marker = '<!--ERROR_DIAGNOSTICS_FIXTURE-->';
const output = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', [
  '-d', 'extension=mysqli', '-d', 'extension=curl', '-d', 'extension=openssl',
  '-r', `require $argv[1]; echo '${marker}', json_encode(['admin' => $storageResponse->body(), 'limited' => $limitedResponse->body()], JSON_THROW_ON_ERROR);`,
  path.join(plugin, 'tests/Unit/operations.php'),
], { encoding: 'utf8' });
const fixtures = JSON.parse(output.split(marker)[1]);
const css = fs.readFileSync(path.join(plugin, 'resources/ui/css/oj-ui-system.css'), 'utf8');

(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  let checks = 0;
  try {
    for (const width of [1440, 390]) {
      for (const [role, content] of Object.entries(fixtures)) {
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        await page.setContent(`<!doctype html><html lang="ko"><meta charset="utf-8"><style>${css}</style><body class="ojp-app"><main style="padding:16px">${content}</main></body></html>`);
        // Match the narrow status columns of the full status dashboard.
        await page.locator('.ojp-status-grid').evaluate(grid => {
          for (let i = 0; i < 2; i++) {
            const item = document.createElement('article');
            item.className = 'ojp-status-card';
            item.textContent = '다른 시스템 상태';
            grid.append(item);
          }
        });
        assert.match(await page.locator('.ojp-status-card').first().innerText(), /쪽지 발송: 요청 제한 카운터를 저장하지 못했습니다/); checks++;
        assert.match(await page.locator('.ojp-status-card').first().innerText(), /오브젝트 캐시/); checks++;
        assert.equal(await page.locator('details[open]').count(), role === 'admin' ? 1 : 0); checks++;
        assert.equal(await page.getByText('rate_limit_storage_failed', { exact: true }).count(), role === 'admin' ? 1 : 0); checks++;
        assert.equal(await page.getByRole('button', { name: '마지막 오류 표시 지우기' }).count(), role === 'admin' ? 1 : 0); checks++;
        const overflow = await page.evaluate(() => ({
          page: document.documentElement.scrollWidth > innerWidth,
          values: [...document.querySelectorAll('.ojp-description-grid dd')].some(node => node.scrollWidth > node.clientWidth + 1),
        }));
        assert.deepEqual(overflow, { page: false, values: false }); checks++;
        if (process.env.OJ_DIAGNOSTIC_SCREENSHOTS && role === 'admin') {
          await page.screenshot({ path: path.join(process.env.OJ_DIAGNOSTIC_SCREENSHOTS, `error-diagnostics-${width}.png`), fullPage: true });
        }
        await page.close();
      }
    }
    process.stdout.write(`Error diagnostics browser: ${checks} checks passed.\n`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
