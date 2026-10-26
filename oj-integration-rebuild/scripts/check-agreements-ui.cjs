const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'agreements');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const html = path.join(output, 'agreements.html');
  execFileSync(php, ['oj-platform/tests/Unit/agreements.php', '--render', html], { cwd: root });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const results = [];
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(pathToFileURL(html).href);
      await page.getByRole('combobox', { name: '문서 종류' }).selectOption('privacy');
      await page.getByRole('textbox', { name: '버전', exact: true }).fill('1.1');
      const editor = page.locator('[data-ojp-rich-text-surface]');
      await editor.fill('개인정보 처리방침');
      await editor.evaluate(surface => {
        const range = document.createRange();
        range.selectNodeContents(surface);
        const selection = getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        surface.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
      });
      await page.getByRole('button', { name: '굵게', exact: true }).click();
      await page.getByRole('combobox', { name: '글자 크기', exact: true }).selectOption('5');
      await page.getByRole('combobox', { name: '문단 형식', exact: true }).selectOption('h2');
      const result = await page.evaluate(() => {
        const controls = [...document.querySelectorAll('form input:not([type="hidden"]):not([type="checkbox"]), form select, form textarea, form button, [data-ojp-rich-text-surface]')].filter(node => node.getClientRects().length);
        const boxes = controls.map(node => node.getBoundingClientRect());
        const source = document.querySelector('[data-ojp-rich-text-source]').value;
        return {
          width: innerWidth,
          scrollWidth: document.documentElement.scrollWidth,
          overlap: boxes.some((a, i) => boxes.some((b, j) => i < j && Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1)),
          styled: getComputedStyle(document.querySelector('select')).borderRadius !== '0px',
          valid: document.querySelector('form').checkValidity(),
          type: document.querySelector('select').value,
          manualTypeName: Boolean(document.querySelector('[name="type_label"]')),
          heading: /<h2[>\s]/.test(source),
          bold: /<(b|strong)[>\s]/.test(source),
          fontSize: /<font[^>]*size="5"/.test(source),
          errorHidden: getComputedStyle(document.querySelector('[data-ojp-rich-text-error]')).display === 'none',
        };
      });
      if (result.scrollWidth > width + 1 || result.overlap || !result.styled || !result.valid || result.type !== 'privacy' || result.manualTypeName || !result.heading || !result.bold || !result.fontSize || !result.errorHidden) {
        throw new Error(JSON.stringify(result));
      }
      await page.screenshot({ path: path.join(output, `agreements-${width}.png`), fullPage: true });
      await editor.fill('');
      const emptyRejected = await page.locator('form').evaluate(form => !form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
      if (!emptyRejected || await editor.getAttribute('aria-invalid') !== 'true') throw new Error('Empty required HTML body must not submit.');
      if (!await page.locator('[data-ojp-rich-text-error]').isVisible()) throw new Error('Required error must be visible after an empty submission.');
      await page.getByRole('combobox', { name: '문서 종류' }).selectOption('guardian-consent');
      if (!await page.locator('[data-ojp-agreement-required]').isDisabled() || !await page.getByText('해당 가입자의 법정대리인 필수 동의', { exact: true }).isVisible()) throw new Error('Guardian-only document must not expose the global required setting.');
      await page.getByRole('combobox', { name: '문서 종류' }).selectOption('privacy');
      if (await page.locator('[data-ojp-agreement-required]').isDisabled()) throw new Error('Normal policy must restore the required control.');
      results.push(result);
    }
    const guardianHtml = path.join(output, 'guardian-consent.html');
    execFileSync(php, ['oj-platform/tests/Unit/guardian-consent.php', '--render', guardianHtml], { cwd: root });
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(pathToFileURL(guardianHtml).href);
      const form = page.locator('form');
      if (await form.evaluate(node => node.checkValidity())) throw new Error('Guardian form must require relationship and consent.');
      await page.getByRole('combobox', { name: '가입 신청자와의 관계' }).selectOption('parent');
      await page.getByRole('checkbox').check();
      if (!await form.evaluate(node => node.checkValidity())) throw new Error('Completed guardian form must be valid.');
      const layout = await page.evaluate(() => ({
        screen: 'guardian', width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
        document: Boolean(document.querySelector('.ojp-policy-content strong')),
        documentId: document.querySelector('[name="document_id"]').value,
      }));
      if (layout.scrollWidth > width + 1 || !layout.document || layout.documentId !== '33') throw new Error(JSON.stringify(layout));
      await page.screenshot({ path: path.join(output, `guardian-${width}.png`), fullPage: true });
      results.push(layout);
    }
    fs.writeFileSync(path.join(output, 'layout.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
