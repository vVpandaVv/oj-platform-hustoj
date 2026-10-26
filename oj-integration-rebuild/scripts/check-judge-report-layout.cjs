const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'judge-reports');
const css = fs.readFileSync(path.join(root, 'oj-platform/resources/ui/css/oj-ui-system.css'), 'utf8');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const results = [];
    for (const kind of ['runtime-error', 'wrong-answer', 'whitespace-answer']) {
      const html = execFileSync(php, ['oj-platform/tests/Support/render-judge-report.php', kind], { cwd: root, encoding: 'utf8' });
      for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(html);
        await page.addStyleTag({ content: css });
        const rowCount = await page.locator('[data-oj-test-case-results] tbody tr').count();
        const details = page.locator(kind === 'runtime-error' ? '[data-oj-case-error]' : '[data-oj-output-comparison]');
        const expectedRows = kind === 'whitespace-answer' ? 0 : 7;
        const expectedDetails = kind === 'runtime-error' ? 7 : kind === 'wrong-answer' ? 6 : 1;
        if (rowCount !== expectedRows || await details.count() !== expectedDetails) throw new Error('Missing parsed rows.');
        if (kind === 'whitespace-answer') {
          const markers = await page.locator('.ojp-output-whitespace').evaluateAll(nodes => nodes.map(node => ({
            marker: getComputedStyle(node, '::before').content,
            width: node.getBoundingClientRect().width,
            text: node.textContent,
          })));
          if (markers.length === 0 || markers.some(item => item.width <= 0 || !['"\u00b7"', '"\u2192"', '"\u2423"'].includes(item.marker))) {
            throw new Error('Whitespace markers are missing or collapsed: ' + JSON.stringify(markers));
          }
          const expectedCell = page.locator('.ojp-output-text').first();
          if (await expectedCell.textContent() !== '  Hello world  \u21a9') throw new Error('Original whitespace changed.');
          const selected = await expectedCell.evaluate(node => {
            const range = document.createRange();
            range.selectNodeContents(node);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            const text = selection.toString();
            selection.removeAllRanges();
            return text;
          });
          if (selected !== '  Hello world  \u21a9') throw new Error('Selecting output includes visual markers.');
        }
        for (const expanded of [false, true]) {
          if (expanded) {
            await details.evaluateAll(nodes => nodes.forEach(node => { node.open = true; }));
          }
          const geometry = await page.evaluate(() => {
            const nodes = [...document.querySelector('[data-oj-judge-report]').children];
            const boxes = nodes.map(node => node.getBoundingClientRect());
            return {
              client: document.documentElement.clientWidth,
              scroll: document.documentElement.scrollWidth,
              overlappingSections: boxes.some((box, i) => i > 0 && box.top < boxes[i - 1].bottom - 1),
            };
          });
          results.push({ kind, width, expanded, rows: rowCount, ...geometry });
          if (geometry.scroll > geometry.client + 1 || geometry.overlappingSections) throw new Error(JSON.stringify(results.at(-1)));
          await page.screenshot({ path: path.join(output, `${kind}-${width}-${expanded ? 'expanded' : 'initial'}.png`), fullPage: true });
        }
        if (kind === 'wrong-answer' && await page.locator('.ojp-output-difference').count() !== 6) throw new Error('Diff spans missing.');
      }
    }
    fs.writeFileSync(path.join(output, 'layout.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
