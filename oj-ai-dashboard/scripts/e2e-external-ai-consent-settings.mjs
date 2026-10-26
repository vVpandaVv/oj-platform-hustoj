import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/external-ai-consent-settings-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
await mkdir(artifactDir, { recursive: true });

function intersects(a, b) {
  return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
}

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text());
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-role="status-badge"]')?.textContent === '승인 필요');

  const before = await page.evaluate(() => {
    const rect = (selector) => {
      const element = document.querySelector(selector);
      if (!element) return null;
      const value = element.getBoundingClientRect();
      return { left: value.left, right: value.right, top: value.top, bottom: value.bottom, width: value.width, height: value.height };
    };
    const rootElement = document.querySelector('#oj-ai-external-consent-app');
    const content = document.querySelector('[data-role="content"]');
    const controls = [...document.querySelectorAll('button')];
    return {
      root: rect('#oj-ai-external-consent-app'),
      header: rect('.oj-ai-consent-settings__header'),
      headerTitle: rect('.oj-ai-consent-settings__header > div'),
      refresh: rect('[data-action="refresh"]'),
      content: rect('[data-role="content"]'),
      status: rect('.oj-ai-consent-settings__status'),
      actions: rect('.oj-ai-consent-settings__actions'),
      textLength: rootElement?.textContent.trim().length || 0,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      contentOverflow: content ? content.scrollWidth > content.clientWidth + 1 : true,
      buttonOverflow: controls.some((control) => control.scrollWidth > control.clientWidth + 1),
    };
  });

  if (!before.root || before.root.width < 250 || before.root.height < 400 || before.textLength < 100) errors.push(`${label}: settings root is blank`);
  if (!before.content || !before.status || !before.actions) errors.push(`${label}: settings sections are missing`);
  if (before.horizontalOverflow || before.contentOverflow || before.buttonOverflow) errors.push(`${label}: horizontal or button overflow detected`);
  if (before.headerTitle && before.refresh && intersects(before.headerTitle, before.refresh)) errors.push(`${label}: header title overlaps refresh control`);

  await page.check('[data-field="acknowledgement"]');
  const approve = page.getByRole('button', { name: '승인', exact: true });
  if (!(await approve.isEnabled())) errors.push(`${label}: C76 did not become available after acknowledgement`);
  await approve.click();
  await page.waitForFunction(() => document.querySelector('[data-role="status-badge"]')?.textContent === '사용 가능');
  if (!(await page.getByRole('button', { name: '승인 철회', exact: true }).isEnabled())) errors.push(`${label}: C77 is not available after approval`);
  if ((await page.locator('[data-role="provider"]').first().textContent()) !== 'openai') errors.push(`${label}: provider projection did not render`);

  const worksheetTab = page.getByRole('tab', { name: '활동지 평가', exact: true });
  const submissionTab = page.getByRole('tab', { name: '학생 제출 평가', exact: true });
  await worksheetTab.focus();
  await worksheetTab.press('ArrowRight');
  if ((await submissionTab.getAttribute('aria-selected')) !== 'true'
    || !(await submissionTab.evaluate((node) => node === document.activeElement))) {
    errors.push(`${label}: consent ArrowRight did not select and focus the submission tab`);
  }
  const submission = page.locator('#oj-ai-submission-external-consent-app');
  await submission.locator('[data-role="status-badge"]').waitFor({ state: 'visible' });
  if ((await submission.locator('[data-role="status-badge"]').textContent()) !== '승인 필요') errors.push(`${label}: Q38 status did not render`);
  if ((await submission.locator('[data-role="categories"] li').count()) !== 4) errors.push(`${label}: student submission categories drifted`);
  await submission.locator('[data-field="acknowledgement"]').check();
  const submissionApprove = submission.getByRole('button', { name: '승인', exact: true });
  if (!(await submissionApprove.isEnabled())) errors.push(`${label}: C78 did not become available after acknowledgement`);
  await submissionApprove.click();
  await page.waitForFunction(() => document.querySelector('#oj-ai-submission-external-consent-app [data-role="status-badge"]')?.textContent === '사용 가능');
  if (!(await submission.getByRole('button', { name: '승인 철회', exact: true }).isEnabled())) errors.push(`${label}: C79 is not available after approval`);

  await page.screenshot({
    path: path.join(artifactDir, `external-ai-consent-settings-${label}.png`),
    fullPage: true,
  });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] consent settings ${label} ${JSON.stringify(before)}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) {
  errors.push(`Playwright consent settings check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('External AI consent settings visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('External AI consent settings desktop/mobile visual checks passed.');
