const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'class-management');
const origin = 'http://class-management.test';

(async () => {
  fs.mkdirSync(output, { recursive: true });
  execFileSync(php, ['oj-platform/tests/Unit/class-management.php', '--render', output], { cwd: root });
  const assets = {
    '/assets/oj-ui-system.css': ['text/css', 'css/oj-ui-system.css'],
    '/assets/oj-ui.js': ['text/javascript', 'js/oj-ui.js'],
    '/assets/mathjax-loader.js': ['text/javascript', '../features/mathjax/js/loader.js'],
    '/assets/favicon.svg': ['image/svg+xml', 'icons/favicon.svg'],
    '/assets/lucide.svg': ['image/svg+xml', 'icons/lucide.svg'],
  };
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge', ignoreDefaultArgs: ['--hide-scrollbars'] });
  try {
    const page = await browser.newPage();
    const errors = [];
    const posts = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(`${origin}/**`, async route => {
      const url = new URL(route.request().url());
      if (route.request().method() === 'POST') {
        assert.equal(url.pathname, '/wp-admin/admin-post.php');
        posts.push(Object.fromEntries(new URLSearchParams(route.request().postData())));
        await route.fulfill({ contentType: 'text/html', body: '<h1>Saved</h1>' });
        return;
      }
      if (assets[url.pathname]) {
        const [contentType, file] = assets[url.pathname];
        await route.fulfill({ contentType, body: fs.readFileSync(path.join(root, 'oj-platform/resources/ui', file)) });
        return;
      }
      const screen = url.pathname.endsWith('/edit') ? 'edit'
        : url.pathname.endsWith('/new') ? (url.searchParams.has('copy_from') ? 'copy' : 'new')
        : url.pathname.endsWith('/progress') ? 'progress' : 'index';
      await route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(output, `class-${screen}.html`), 'utf8') });
    });
    const layouts = [];
    for (const width of [360, 390, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 950 });
      for (const screen of ['new', '1/edit', 'new?copy_from=1']) {
        await page.goto(`${origin}/oj-console/classes/${screen}`);
        const groups = page.locator('select[name="group_ids[]"]');
        const box = await groups.boundingBox();
        assert.ok(box.height >= 240, `Multiselect height at ${width}: ${box.height}`);
        await groups.selectOption(['7', '9']);
        assert.deepEqual(await groups.evaluate(node => [...node.selectedOptions].map(option => option.value)), ['7', '9']);
        const pageWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        assert.ok(pageWidth <= width + 1, `${screen} overflows at ${width}: ${pageWidth}`);
        await groups.scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(output, `groups-${screen.replaceAll('/', '-').replaceAll('?', '-').replaceAll('=', '-')}-${width}.png`) });
      }
      await page.goto(`${origin}/oj-console/classes/1/progress`);
      const matrix = page.locator('.ojp-class-progress-scroll');
      await matrix.scrollIntoViewIfNeeded();
      const layout = await matrix.evaluate(node => {
        const first = node.querySelector('tbody td');
        const x = first.getBoundingClientRect().left;
        const heading = node.querySelector('thead th');
        const y = heading.getBoundingClientRect().top;
        node.scrollLeft = 700;
        node.scrollTop = 250;
        return { width: innerWidth, pageWidth: document.documentElement.scrollWidth, clientWidth: node.clientWidth, scrollWidth: node.scrollWidth, scrollLeft: node.scrollLeft, scrollTop: node.scrollTop, stickyDelta: Math.abs(first.getBoundingClientRect().left - x), headingDelta: Math.abs(heading.getBoundingClientRect().top - y), problemWidth: node.querySelector('tbody td:nth-child(3)').getBoundingClientRect().width };
      });
      assert.ok(layout.pageWidth <= width + 1, JSON.stringify(layout));
      assert.ok(layout.scrollWidth > layout.clientWidth && layout.scrollLeft > 0, JSON.stringify(layout));
      assert.ok(layout.problemWidth >= 140 && layout.stickyDelta < 2, JSON.stringify(layout));
      assert.ok(layout.scrollTop > 0 && layout.headingDelta < 2, JSON.stringify(layout));
      assert.equal(await page.locator('.ojp-class-progress-count').first().textContent(), '1 / 20');
      await page.screenshot({ path: path.join(output, `progress-scrolled-${width}.png`) });
      await matrix.evaluate(node => { node.scrollLeft = 0; node.scrollTop = 0; });
      await matrix.focus();
      await page.keyboard.press('ArrowRight');
      await page.waitForFunction(() => document.querySelector('.ojp-class-progress-scroll').scrollLeft > 0);
      await matrix.evaluate(node => { node.scrollLeft = 0; });
      await page.screenshot({ path: path.join(output, `progress-${width}.png`) });
      layouts.push(layout);
    }
    await page.setViewportSize({ width: 1440, height: 950 });
    await page.goto(`${origin}/oj-console/classes`);
    await page.getByRole('link', { name: '수업 복사', exact: true }).first().click();
    assert.equal(new URL(page.url()).searchParams.get('copy_from'), '1');
    assert.equal(await page.locator('[name="class_id"]').inputValue(), '0');
    assert.equal(await page.locator('[name="name"]').inputValue(), '알고리즘 기초 (복사본)');
    assert.equal(await page.locator('[name="visibility"]').inputValue(), 'private');
    assert.equal(await page.locator('[name="enabled"]').isChecked(), false);
    assert.equal(await page.locator('[name="password"]').inputValue(), '');
    assert.equal(await page.locator('[name="starts_at"]').inputValue(), '');
    await page.locator('[name="slug"]').fill('copied-class');
    await page.getByRole('button', { name: '복사본 저장', exact: true }).click();
    await page.getByRole('heading', { name: 'Saved' }).waitFor();
    assert.equal(posts.length, 1);
    assert.equal(posts[0].action, 'oj_class_save');
    assert.equal(posts[0]._nonce, 'fixture');
    assert.equal(posts[0].class_id, '0');
    assert.equal(posts[0].slug, 'copied-class');
    assert.equal(posts[0]['problem_configured[1001]'], '1');
    assert.equal(posts[0]['problem_visible[1001]'], undefined);
    assert.equal(posts[0]['problem_visible[1000]'], '1');
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ layouts, copyPost: posts[0], errors }, null, 2));
    console.log(JSON.stringify({ layouts, copyPosts: posts.length, errors }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
