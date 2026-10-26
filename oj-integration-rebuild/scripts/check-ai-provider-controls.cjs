const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'ai-provider-controls');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const htmlPath = path.join(output, 'settings.html');
  execFileSync(php, ['oj-platform/tests/Unit/ai-provider-controls.php', '--render', htmlPath], { cwd: root });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const results = [];
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(pathToFileURL(htmlPath).href);
      const form = page.locator('[data-ojp-provider-form]');
      const approval = form.locator('[name="allow_submission_source"]');
      if (await approval.isChecked()) throw new Error('Approval must default off.');
      for (const field of ['profile_key', 'base_url', 'model', 'secret']) {
        await approval.check();
        await form.locator(`[name="${field}"]`).fill('changed-value');
        if (await approval.isChecked()) throw new Error(`${field} change retained approval.`);
      }
      await approval.check();
      await form.locator('[name="provider_key"]').selectOption('gemini');
      if (await approval.isChecked() || await form.locator('[name="secret"]').inputValue()) throw new Error('Provider change retained approval or credentials.');
      if (!await page.locator('[data-ojp-provider-example="gemini"]').isVisible()) throw new Error('Provider example missing.');
      await approval.check();
      if (!await approval.isChecked()) throw new Error('External AI approval must be selectable.');
      await form.locator('[name="provider_key"]').selectOption('openai_compatible_gateway');
      await approval.check();
      await form.locator('[name="authentication_method"]').selectOption('x_api_key');
      if (await approval.isChecked()) throw new Error('Authentication change retained approval.');
      const geometry = await form.evaluate(node => {
        const buttons = [...node.querySelectorAll('button[name="operation"]')];
        const boxes = buttons.map(button => button.getBoundingClientRect());
        const overlap = boxes.length === 2 && Math.min(boxes[0].right, boxes[1].right) > Math.max(boxes[0].left, boxes[1].left) + 1
          && Math.min(boxes[0].bottom, boxes[1].bottom) > Math.max(boxes[0].top, boxes[1].top) + 1;
        const reset = buttons.find(button => button.value === 'reset_probe');
        return {
          width: document.documentElement.clientWidth,
          scrollWidth: document.documentElement.scrollWidth,
          overlap,
          operations: buttons.map(button => new FormData(node, button).getAll('operation')),
          resetWithoutValidation: reset.formNoValidate,
          styled: getComputedStyle(buttons[0]).borderRadius !== '0px',
        };
      });
      if (geometry.scrollWidth > width + 1 || geometry.overlap || !geometry.styled || !geometry.resetWithoutValidation) throw new Error(JSON.stringify(geometry));
      if (JSON.stringify(geometry.operations) !== '[["test"],["reset_probe"]]') throw new Error('Ambiguous form operations.');
      await form.locator('[name="profile_key"]').fill('feedback');
      await form.locator('[name="secret"]').fill('');
      await approval.scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(output, `ai-profiles-${width}.png`), fullPage: true });
      results.push(geometry);
    }
    if (errors.length) throw new Error(errors.join('\n'));
    fs.writeFileSync(path.join(output, 'layout.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
