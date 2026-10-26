const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'login-security');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const htmlPath = path.join(output, 'settings.html');
  execFileSync(php, ['oj-platform/tests/Unit/login-security.php', '--render', htmlPath], { cwd: root });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    await page.goto(pathToFileURL(htmlPath).href);
    const results = [];
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const checkbox = page.getByRole('checkbox', { name: '로그인 횟수 제한 사용' });
      await checkbox.check();
      if (! await checkbox.isChecked()) throw new Error('Login toggle is not interactive.');
      await checkbox.uncheck();
      await page.locator('#login-security').scrollIntoViewIfNeeded();
      const geometry = await page.locator('#login-security').evaluate(section => {
        const boxes = [...section.children].map(node => node.getBoundingClientRect());
        const forms = [...section.querySelectorAll('form')];
        return {
          width: document.documentElement.clientWidth,
          scrollWidth: document.documentElement.scrollWidth,
          overlap: boxes.some((box, index) => index > 0 && box.top < boxes[index - 1].bottom - 1),
          operations: forms.map(form => new FormData(form).get('operation')),
          revisions: forms.map(form => new FormData(form).get('expected_revision')),
          styled: getComputedStyle(section.querySelector('button')).backgroundColor !== 'rgba(0, 0, 0, 0)',
        };
      });
      if (geometry.scrollWidth > width + 1 || geometry.overlap || ! geometry.styled) throw new Error(JSON.stringify(geometry));
      if (geometry.operations.join(',') !== 'save_login_throttle,reset_login_throttle' || geometry.revisions.some(value => value === null)) throw new Error('Admin form contract missing.');
      await page.screenshot({ path: path.join(output, `login-security-${width}.png`), fullPage: true });
      results.push(geometry);
    }
    fs.writeFileSync(path.join(output, 'layout.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
