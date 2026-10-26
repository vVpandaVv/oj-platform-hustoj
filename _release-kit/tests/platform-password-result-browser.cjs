const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const plugin = path.join(root, 'oj-integration-rebuild/oj-platform');
const html = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', [path.join(plugin, 'tests/Unit/password-result-visibility.php'), '--render'], { encoding: 'utf8' });
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  let checks = 0;
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 800 } });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/assets/app.js') { return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(plugin, 'resources/ui/js/oj-ui.js')) }); }
        if (pathname === '/assets/app.css') { return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(path.join(plugin, 'resources/ui/css/oj-ui-system.css')) }); }
        return route.fulfill({ contentType: 'text/html', body: html });
      });
      await page.goto('http://password-result.test/');
      const first = page.locator('#ojp-temporary-password-15');
      const second = page.locator('#ojp-temporary-password-16');
      const toggle = page.locator('[data-ojp-secret-toggle]').first();
      assert.equal(await first.getAttribute('type'), 'password');
      assert.equal(await toggle.getAttribute('aria-pressed'), 'false');
      assert.equal(await first.inputValue(), 'Synthetic-<>&"-Only!123');
      await toggle.click();
      assert.equal(await first.getAttribute('type'), 'text');
      assert.equal(await second.getAttribute('type'), 'password');
      assert.equal(await toggle.textContent(), '숨김');
      await toggle.click();
      assert.equal(await first.getAttribute('type'), 'password');
      assert.equal(await toggle.textContent(), '보임');
      await toggle.click();
      await page.keyboard.press('Escape');
      assert.equal(await first.getAttribute('type'), 'password');
      for (const event of ['pagehide', 'pageshow']) {
        await toggle.click();
        await page.evaluate(event => window.dispatchEvent(new Event(event)), event);
        assert.equal(await first.getAttribute('type'), 'password');
      }
      await toggle.click();
      await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        document.dispatchEvent(new Event('visibilitychange'));
      });
      assert.equal(await first.getAttribute('type'), 'password');
      assert.equal(await first.inputValue(), 'Synthetic-<>&"-Only!123');
      assert.deepEqual(errors, []);
      const fits = await toggle.evaluate(element => {
        const a = element.getBoundingClientRect();
        const b = element.closest('td').getBoundingClientRect();
        return a.right <= b.right && a.left >= b.left;
      });
      assert(fits);
      const form = page.locator('#password-form');
      const login = form.locator('[name="login_password"]');
      const loginToggle = login.locator('..').locator('[data-ojp-password-toggle]');
      assert.equal(await login.getAttribute('type'), 'password');
      assert.equal(await login.getAttribute('autocomplete'), 'current-password');
      assert.equal(await login.inputValue(), 'Login-Synthetic!123');
      await loginToggle.click();
      assert.equal(await login.getAttribute('type'), 'text');
      assert.equal(await form.locator('[name="new_password"]').getAttribute('type'), 'password');
      await loginToggle.click();
      await form.locator('[name="new_password"]').fill('New-Synthetic!456');
      await form.locator('[name="join_password"]').fill('Join-Synthetic!789');
      assert.equal(await form.locator('[name="new_password"]').getAttribute('autocomplete'), 'new-password');
      assert(await form.locator('[name="join_password"]').getAttribute('required') !== null);
      const generated = form.locator('[data-ojp-password-suggest]');
      const generatedValue = await generated.inputValue();
      assert(generatedValue.length >= 12);
      const generatedToggle = form.locator('[data-ojp-password-group] [data-ojp-password-toggle]');
      await generatedToggle.click();
      assert.equal(await generated.getAttribute('type'), 'text');
      await form.locator('[data-ojp-password-regenerate]').click();
      assert.equal(await generated.getAttribute('type'), 'password');
      assert.notEqual(await generated.inputValue(), generatedValue);
      const values = await form.evaluate(element => Object.fromEntries(new FormData(element)));
      assert.equal(values.login_password, 'Login-Synthetic!123');
      assert.equal(values.new_password, 'New-Synthetic!456');
      assert.equal(values.join_password, 'Join-Synthetic!789');
      const groupSecret = page.locator('#group-password');
      assert.equal(await groupSecret.getAttribute('type'), 'password');
      assert.equal(await page.locator('[data-ojp-password-group] [data-ojp-password-toggle]').count(), 1);
      assert.deepEqual(errors, []);
      await page.screenshot({ path: path.join(root, `_notes/tmp/password-result-${width}.png`), fullPage: true });
      checks += 32;
      await page.close();
    }
    console.log(`Password result browser: ${checks} checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
