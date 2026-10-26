const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'hidden-wrappers');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const detailPath = path.join(output, 'detail.html');
  const submissionPath = path.join(output, 'submission.html');
  execFileSync(php, ['oj-platform/tests/Unit/problem-detail-view.php'], {
    cwd: root, env: { ...process.env, OJ_PROBLEM_DETAIL_VISUAL_OUTPUT: detailPath },
  });
  execFileSync(php, ['oj-platform/tests/Unit/submissions.php'], {
    cwd: root, env: { ...process.env, OJ_SUBMISSION_POLICY_VISUAL_OUTPUT: submissionPath },
  });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const results = [];
    for (const [kind, filename, selector, visibleCode] of [
      ['detail', detailPath, '[aria-labelledby="problem-code-policy-heading"]', 'from sys import stdin'],
      ['submission', submissionPath, '.ojp-submission-policy', 'visible_front();'],
    ]) {
      let html = fs.readFileSync(filename, 'utf8');
      if (/#hidden|hidden_prefix_fixture|hidden_suffix_fixture|private_front|private_rear/.test(html)) {
        throw new Error('Hidden wrapper leaked into public HTML.');
      }
      html = html.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<link[^>]*>/g, '');
      if (kind === 'submission') {
        html = '<!doctype html><html lang="ko"><meta charset="utf-8"><body class="ojp-public-shell"><main class="ojp-public-main">'
          + html + '</main></body></html>';
      }
      for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(html);
        await page.addStyleTag({ content: fs.readFileSync(path.join(root, 'oj-platform/resources/ui/css/oj-ui-system.css'), 'utf8') });
        await page.locator('details').evaluateAll(nodes => nodes.forEach(node => { node.open = true; }));
        const panel = page.locator(selector);
        if (!await panel.getByText(visibleCode, { exact: true }).isVisible()) throw new Error('Visible code is missing.');
        if (!(await panel.textContent()).includes('비공개 코드')) throw new Error('Private wrapper status is missing.');
        const geometry = await page.evaluate(() => ({
          client: document.documentElement.clientWidth,
          scroll: document.documentElement.scrollWidth,
        }));
        if (geometry.scroll > geometry.client + 1) throw new Error('Horizontal page overflow.');
        await panel.screenshot({ path: path.join(output, kind + '-' + width + '.png') });
        results.push({ kind, width, ...geometry, hiddenHtmlAbsent: true });
      }
    }
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
