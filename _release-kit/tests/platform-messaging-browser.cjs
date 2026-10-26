const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const plugin = path.join(root, 'oj-integration-rebuild/oj-platform');
const output = path.join(root, '_notes/tmp/messaging-browser');
fs.mkdirSync(output, { recursive: true });
const rendered = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', [
  path.join(plugin, 'tests/Unit/messaging-navigation.php'), '--render'
], { encoding: 'utf8' });
const html = rendered.slice(rendered.toLowerCase().indexOf('<!doctype'));
assert(html.startsWith('<!DOCTYPE') || html.startsWith('<!doctype'));
(async () => {
  const browser = await chromium.launch({ headless: true });
  let checks = 0;
  try {
    for (const width of [1440, 390]) {
      for (const scheme of ['light', 'dark']) {
        const page = await browser.newPage({ viewport: { width, height: 900 }, colorScheme: scheme });
        const errors = [];
        let unread = 3;
        let requests = 0;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.searchParams.get('notification') === '1') {
            requests++;
            return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ unread }) });
          }
          const assets = {
            '/assets/app.css': ['resources/ui/css/oj-ui-system.css', 'text/css'],
            '/assets/app.js': ['resources/ui/js/oj-ui.js', 'application/javascript'],
            '/assets/icons.svg': ['resources/ui/icons/lucide.svg', 'image/svg+xml']
          };
          if (assets[url.pathname]) {
            const [file, contentType] = assets[url.pathname];
            return route.fulfill({ contentType, body: fs.readFileSync(path.join(plugin, file)) });
          }
          if (url.pathname === '/messages/find') { return route.fulfill({ contentType: 'text/html', body: html }); }
          return route.fulfill({ status: 204, body: '' });
        });
        await page.goto('http://messaging.test/messages/find');
        await page.evaluate(scheme => { document.documentElement.dataset.ojpScheme = scheme; }, scheme);
        const notification = page.locator('[data-ojp-message-notification]');
        await notification.waitFor({ state: 'visible' });
        await page.waitForFunction(() => document.querySelector('[data-ojp-message-count]').textContent === '3');
        await page.getByRole('button', { name: '내 정보 메뉴', exact: true }).click();
        await page.locator('#ojp-account-popover').waitFor({ state: 'visible' });
        assert(await page.locator('#ojp-account-popover a[href="/messages/find"]').isVisible());
        assert(await page.locator('a[href="/messages/new/2"]').isVisible());
        const geometry = await page.evaluate(() => {
          const message = document.querySelector('[data-ojp-message-notification]').getBoundingClientRect();
          const account = document.querySelector('.ojp-account-button').getBoundingClientRect();
          return { overflow: document.documentElement.scrollWidth > innerWidth + 1,
            overlap: message.right > account.left + 1,
            adjacent: account.left - message.right < 40,
            inside: message.left >= 0 && account.right <= innerWidth };
        });
        assert(!geometry.overflow && !geometry.overlap && geometry.inside && geometry.adjacent, JSON.stringify(geometry));
        checks += 4;
        await page.screenshot({ path: path.join(output, `${width}-${scheme}.png`), fullPage: true });
        unread = 0;
        await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
        await page.waitForFunction(() => [...document.querySelectorAll('[data-ojp-message-count]')].every(e => e.hidden));
        assert(await page.locator('[data-ojp-message-dot]').evaluate(e => e.hidden));
        checks += 2;
        unread = 124;
        await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
        await page.waitForFunction(() => document.querySelector('[data-ojp-message-count]').textContent === '99+');
        assert((await notification.getAttribute('aria-label')).includes('124'));
        assert(requests >= 3);
        assert.deepEqual(errors, []);
        checks += 3;
        await page.close();
      }
    }
    console.log(`Messaging browser: ${checks} checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
