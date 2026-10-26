import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const markup = execFileSync(process.env.OJAI_PHP || 'php', ['tests/fixtures/render-criteria-first-workflow.php'], { cwd: root, encoding: 'utf8' });
const artifacts = path.join(root, 'tests/artifacts/review-container-layout');
await mkdir(artifacts, { recursive: true });
const { chromium } = await loadPlaywright();
const browser = await chromium.launch({ ...browserLaunchOptions(), headless: true });
const results = [];
try {
  // Console sidebars and embedded panels reduce space independently of viewport breakpoints.
  for (const [viewport, width] of [[1440, 1100], [1280, 960], [921, 610], [800, 489], [390, 358]]) {
    const page = await browser.newPage({ viewport: { width: viewport, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(pathToFileURL(path.join(root, 'tests/fixtures/worksheet-evaluation-workbench-visual.html')).href);
    await page.waitForSelector('.oj-ai-evaluation__dimension p');
    await page.addStyleTag({ path: path.join(root, 'resources/css/worksheet-authoring-workbench.css') });
    await page.evaluate(({ markup, width }) => {
      const evaluation = document.querySelector('#oj-ai-worksheet-evaluation-app');
      const host = document.createElement('section');
      host.className = 'oj-ai-workbench oj-ai-workbench--editor';
      host.style.cssText = `width:${width}px;max-width:100%;padding:0;margin:16px auto;background:white`;
      evaluation.replaceWith(host);
      evaluation.style.cssText = 'width:100%;max-width:none;margin:0;padding:0';
      host.append(evaluation);
      const parsed = new DOMParser().parseFromString(markup, 'text/html');
      const footer = parsed.querySelector('.oj-ai-workbench__workflow');
      footer.querySelector('[data-role="validation-result"]').hidden = false;
      footer.querySelector('[data-role="validation-title"]').textContent = '문서 확인 완료';
      footer.querySelector('[data-role="validation-message"]').textContent = '저장된 버전을 확인했습니다.';
      footer.querySelector('[data-role="release-title"]').textContent = '승인됨';
      footer.querySelector('[data-role="release-message"]').textContent = '배포용 버전을 발행할 수 있습니다.';
      host.append(footer);
    }, { markup, width });
    const measurement = await page.evaluate(() => {
      const width = element => Math.round(element.getBoundingClientRect().width);
      const texts = [...document.querySelectorAll('.oj-ai-evaluation__dimension p')];
      const statuses = [...document.querySelectorAll('.oj-ai-evaluation__timeline li > span:nth-child(2)')];
      const footerTexts = [...document.querySelectorAll('.oj-ai-workbench__workflow > section > div > span')];
      const sections = [...document.querySelectorAll('.oj-ai-workbench__workflow > section:not([hidden])')];
      const overflow = [...document.querySelectorAll('.oj-ai-evaluation__detail, .oj-ai-workbench__workflow > section, .oj-ai-workbench__workflow button')]
        .filter(element => element.getClientRects().length && element.scrollWidth > element.clientWidth + 1)
        .map(element => ({ className: element.className, text: element.textContent.slice(0, 40) }));
      return {
        detailWidth: width(document.querySelector('.oj-ai-evaluation__detail')),
        descriptionWidths: texts.map(width),
        statusWidths: statuses.map(width),
        footerTextWidths: footerTexts.map(width),
        footerSectionHeights: sections.map(section => section.getBoundingClientRect().height),
        headerHeight: document.querySelector('.oj-ai-evaluation__detail-header').getBoundingClientRect().height,
        stackedFooter: sections.every((section, i) => !i || section.getBoundingClientRect().top >= sections[i - 1].getBoundingClientRect().bottom - 1),
        horizontalOverflow: document.documentElement.scrollWidth > innerWidth + 1,
        overflow,
      };
    });
    results.push({ viewport, containerWidth: width, ...measurement, errors });
    await page.screenshot({ path: path.join(artifacts, `review-${viewport}-${width}.png`), fullPage: true });
    await page.locator('.oj-ai-workbench__workflow').screenshot({ path: path.join(artifacts, `publication-${viewport}-${width}.png`) });
    assert.equal(measurement.descriptionWidths.length, 6);
    assert.ok(measurement.descriptionWidths.every(value => value >= 180), JSON.stringify(results.at(-1)));
    assert.ok(measurement.statusWidths.length > 0 && measurement.statusWidths.every(value => value >= 120), JSON.stringify(results.at(-1)));
    assert.ok(measurement.footerTextWidths.every(value => value >= 150), JSON.stringify(results.at(-1)));
    assert.ok(measurement.footerSectionHeights.every(value => value < 190), 'Publication controls must not have empty flex-basis gaps');
    assert.ok(measurement.headerHeight < 190, 'Header must not grow to a column flex-basis height');
    assert.ok(measurement.stackedFooter);
    assert.equal(measurement.horizontalOverflow, false);
    assert.deepEqual(measurement.overflow, []);
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log(`PASS review container layout: ${results.length} viewport/container pairs`);
} finally {
  await writeFile(path.join(artifacts, 'result.json'), JSON.stringify(results, null, 2));
  await browser.close();
}
