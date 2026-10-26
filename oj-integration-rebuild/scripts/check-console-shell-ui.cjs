const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist/verification/console-shell');
const assets = {
  '/assets/oj-ui-system.css': ['css/oj-ui-system.css', 'text/css'],
  '/assets/oj-ui.js': ['js/oj-ui.js', 'text/javascript'],
  '/assets/lucide.svg': ['icons/lucide.svg', 'image/svg+xml'],
  '/assets/favicon.svg': ['icons/favicon.svg', 'image/svg+xml'],
};

(async () => {
  fs.mkdirSync(output, { recursive: true });
  execFileSync(php, ['oj-platform/tests/Unit/console-shell.php', '--render', output], { cwd: root });
  const html = fs.readFileSync(path.join(output, 'console-shell.html'), 'utf8');
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://console-ui.test/**', async route => {
      const pathname = new URL(route.request().url()).pathname;
      if (assets[pathname]) {
        const [file, contentType] = assets[pathname];
        await route.fulfill({ contentType, body: fs.readFileSync(path.join(root, 'oj-platform/resources/ui', file)) });
      } else if (pathname === '/assets/mathjax-loader.js') {
        await route.fulfill({ contentType: 'text/javascript', body: '' });
      } else {
        await route.fulfill({ contentType: 'text/html', body: html });
      }
    });
    const results = [];
    for (const [width, height] of [[1440, 900], [1280, 720], [1024, 600], [901, 700]]) {
      await page.setViewportSize({ width, height });
      await page.goto('http://console-ui.test/oj-console/problems');
      await page.evaluate(() => { sessionStorage.clear(); localStorage.clear(); });
      await page.reload();
      const sidebar = page.locator('.ojp-console-sidebar');
      const users = sidebar.locator('[data-menu-key="users"]');
      const problems = sidebar.locator('[data-menu-key="problems"]');
      assert.equal(await users.getAttribute('aria-expanded'), 'false');
      assert.equal(await problems.getAttribute('aria-expanded'), 'true');
      await users.focus();
      await users.press('Space');
      assert.equal(await users.getAttribute('aria-expanded'), 'true');
      assert.equal(await page.locator('#ojp-console-drawer [data-menu-key="users"]').getAttribute('aria-expanded'), 'true');
      assert.equal(await sidebar.locator('a[href="/oj-console/users/12"]').count(), 1);
      await page.reload();
      assert.equal(await users.getAttribute('aria-expanded'), 'true', 'Keep non-current menu preferences across navigation.');
      await problems.click();
      assert.equal(await problems.getAttribute('aria-expanded'), 'false');
      await page.reload();
      assert.equal(await problems.getAttribute('aria-expanded'), 'true', 'The current menu must reopen on a new page.');
      await page.evaluate(() => window.scrollTo(0, 1400));
      const before = await sidebar.boundingBox();
      assert.ok(Math.abs(before.y - 64) <= 1, 'Sidebar stays below the fixed header.');
      assert.ok(before.y + before.height <= height + 1, 'Sidebar fits the viewport height.');
      const nav = sidebar.locator('nav');
      const windowY = await page.evaluate(() => scrollY);
      const box = await nav.boundingBox();
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      await page.mouse.wheel(0, 350);
      await page.waitForFunction(() => document.querySelector('.ojp-console-sidebar nav').scrollTop > 0);
      assert.equal(await page.evaluate(() => scrollY), windowY, 'Menu wheel scrolling must not move the main page.');
      await nav.evaluate(node => { node.scrollTop = node.scrollHeight; });
      await page.mouse.wheel(0, 700);
      await page.waitForTimeout(150);
      assert.equal(await page.evaluate(() => scrollY), windowY, 'Scrolling beyond the menu end must not chain to the main page.');
      const navScroll = await nav.evaluate(node => node.scrollTop);
      await page.evaluate(() => window.scrollTo(0, 2200));
      assert.equal(await nav.evaluate(node => node.scrollTop), navScroll, 'Main scrolling must preserve the menu position.');
      const after = await sidebar.boundingBox();
      assert.ok(Math.abs(after.y - before.y) <= 1);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await page.screenshot({ path: path.join(output, `console-${width}.png`), fullPage: false });
      await page.locator('[data-ojp-sidebar-toggle]').click();
      assert.equal(await users.isVisible(), false);
      assert.ok((await sidebar.boundingBox()).width <= 61);
      await page.locator('[data-ojp-sidebar-toggle]').click();
      assert.equal(await users.isVisible(), true);
      results.push({ width, height, independentScroll: true, toggles: true });
    }
    await page.locator('[data-ojp-sidebar-toggle]').click();
    for (const width of [390, 768, 900]) {
      await page.setViewportSize({ width, height: 850 });
      await page.reload();
      assert.equal(await page.locator('.ojp-console-sidebar').isVisible(), false);
      assert.ok((await page.locator('.ojp-console-main').boundingBox()).width > width - 70, 'Desktop collapse must not constrain mobile content.');
      const drawer = page.locator('#ojp-console-drawer');
      assert.equal(await drawer.evaluate(node => node.inert), true);
      await page.getByRole('button', { name: '관리 메뉴 열기', exact: true }).click();
      assert.equal(await drawer.evaluate(node => node.inert), false);
      const users = drawer.locator('[data-menu-key="users"]');
      assert.equal(await users.isVisible(), true, 'Collapsed desktop state must not hide mobile menu labels or toggles.');
      await users.click();
      await users.press('Enter');
      assert.equal(await users.getAttribute('aria-expanded'), 'true');
      const mainY = await page.evaluate(() => scrollY);
      await drawer.locator('.ojp-drawer__body').evaluate(node => { node.scrollTop = 180; });
      assert.equal(await page.evaluate(() => scrollY), mainY);
      await page.screenshot({ path: path.join(output, `console-mobile-${width}.png`), fullPage: false });
      await page.getByRole('button', { name: '관리 메뉴 닫기', exact: true }).click();
      assert.equal(await drawer.evaluate(node => node.inert), true);
      assert.equal(await page.getByRole('button', { name: '관리 메뉴 열기', exact: true }).getAttribute('aria-expanded'), 'false');
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      results.push({ width, mobileDrawer: true });
    }
    const noStorage = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await noStorage.addInitScript(() => {
      Storage.prototype.getItem = function () { throw new Error('Storage disabled'); };
      Storage.prototype.setItem = function () { throw new Error('Storage disabled'); };
    });
    await noStorage.route('http://console-ui.test/**', async route => {
      const asset = assets[new URL(route.request().url()).pathname];
      await route.fulfill(asset ? { contentType: asset[1], body: fs.readFileSync(path.join(root, 'oj-platform/resources/ui', asset[0])) }
        : { contentType: new URL(route.request().url()).pathname.endsWith('.js') ? 'text/javascript' : 'text/html', body: new URL(route.request().url()).pathname.endsWith('.js') ? '' : html });
    });
    await noStorage.goto('http://console-ui.test/oj-console/problems');
    const toggle = noStorage.locator('.ojp-console-sidebar [data-menu-key="users"]');
    await toggle.click();
    assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
    await noStorage.close();
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ results, errors, storageBlocked: 'passed' }, null, 2));
    console.log(JSON.stringify({ results, errors }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
