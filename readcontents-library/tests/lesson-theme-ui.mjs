import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const workspace = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const output = path.join(workspace, '_notes/tmp/lesson-theme-2026-10-21');
const dependencyRoot = 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules';
const require = createRequire(path.join(workspace, '_notes/readcontents-server-import-2026-09-12/editor-source/package.json'));
const { chromium } = require(process.env.PLAYWRIGHT_PATH || (fs.existsSync(path.join(dependencyRoot, 'playwright')) ? path.join(dependencyRoot, 'playwright') : '@playwright/test'));
const php = process.env.PHP_BINARY || 'D:/php/php.exe';
const fixturePath = path.join(workspace, 'readcontents-library/tests/lesson-theme.php');
for (const args of [[], ['--legacy']]) {
  process.stdout.write(execFileSync(php, [fixturePath, ...args], { encoding: 'utf8' }));
}
const html = execFileSync(php, [fixturePath, '--fixture'], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
fs.mkdirSync(output, { recursive: true });
fs.writeFileSync(path.join(output, 'fixture.html'), html);
const chrome = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const browser = await chromium.launch({ headless: true, ...(fs.existsSync(chrome) ? { executablePath: chrome } : {}) });
const report = [];
const mime = file => ({ '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.svg': 'image/svg+xml' }[path.extname(file)] || 'application/octet-stream');

async function inspect(page, expectedMode) {
  const state = await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    function rgba(value) {
      ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = value; ctx.fillRect(0, 0, 1, 1);
      const p = ctx.getImageData(0, 0, 1, 1).data;
      return [p[0], p[1], p[2], p[3] / 255];
    }
    function background(element) {
      const ancestors = [];
      for (let current = element; current; current = current.parentElement) ancestors.unshift(current);
      return ancestors.reduce((base, current) => {
        const color = rgba(getComputedStyle(current).backgroundColor);
        return base.map((value, i) => i < 3 ? color[i] * color[3] + value * (1 - color[3]) : 1);
      }, [255, 255, 255, 1]);
    }
    function luminance(color) {
      return color.slice(0, 3).map(c => c / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4).reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0);
    }
    const probes = {
      title: '.contents-editor-plate-content h1',
      heading: '.contents-editor-plate-content h2',
      body: '.contents-editor-plate-content > p',
      quote: 'blockquote p',
      tableHeading: 'th p',
      tableBody: 'td p',
      callout: '.contents-editor-callout:not([style]) p',
      toggle: '.contents-editor-toggle summary',
      inlineCode: '.contents-editor-plate-content > p > code',
      codeBlock: 'pre code',
      highlight: 'mark',
      keyboard: 'kbd',
      date: '.contents-editor-date',
      mention: '.contents-editor-mention',
      mediaCaption: 'figcaption',
      file: '.contents-editor-plate-file a',
      quizQuestion: '.contents-editor-quiz__question',
      quizChoice: '.contents-editor-quiz__choice-text',
      quizButton: '.contents-editor-quiz__check',
      memo: '[aria-label="학습 메모"]',
      select: '[aria-label="학습 유형"]',
      textarea: '[aria-label="분석 내용"]',
      visualizationTitle: '.contents-editor-visualization-result-shell__header strong',
      visualizationButton: '.contents-editor-public-visualization__step-controls button:not(:disabled)',
      visualizationCount: '.contents-editor-public-visualization__step-count',
      visualizationNote: '.contents-editor-public-visualization__step-note',
      visualizationNoteTitle: '.contents-editor-public-visualization__step-note strong',
    };
    if (document.querySelector('[data-contents-editor-quiz-result]')?.textContent) probes.quizResult = '[data-contents-editor-quiz-result]';
    if (document.querySelector('[data-contents-editor-quiz-explanation]:not([hidden])')) {
      probes.quizExplanationTitle = '[data-contents-editor-quiz-explanation] strong';
      probes.quizExplanation = '[data-contents-editor-quiz-explanation] p';
    }
    const text = Object.entries(probes).map(([name, selector]) => {
      const element = document.querySelector(selector);
      if (!element) return { name, missing: true };
      const style = getComputedStyle(element), bg = background(element), foreground = rgba(style.color);
      const fg = foreground.map((v, i) => i < 3 ? v * foreground[3] + bg[i] * (1 - foreground[3]) : 1);
      const a = luminance(fg), b = luminance(bg);
      return { name, foreground: style.color, background: bg.slice(0, 3), contrast: (Math.max(a, b) + .05) / (Math.min(a, b) + .05) };
    });
    const surfaces = ['.contents-editor-callout:not([style])', 'th', '.contents-editor-quiz', '.contents-editor-quiz__choice', '[aria-label="학습 메모"]'].map(selector => ({ selector, luminance: luminance(background(document.querySelector(selector))) }));
    const image = document.querySelector('img');
    const filters = [];
    for (let element = image; element; element = element.parentElement) filters.push(getComputedStyle(element).filter);
    const authored = [...document.querySelectorAll('[style*="background-color"]')].map(element => ({ text: element.textContent.trim(), background: getComputedStyle(element).backgroundColor, foreground: getComputedStyle(element).color }));
    const coloredText = [...document.querySelectorAll('span[style*="color:"]')].map(element => ({ text: element.textContent, foreground: getComputedStyle(element).color, background: getComputedStyle(element).backgroundColor }));
    return { mode: getComputedStyle(document.documentElement).colorScheme, overflow: document.documentElement.scrollWidth - innerWidth, text, surfaces, authored, coloredText, filters, imageLoaded: image.complete && image.naturalWidth > 0, imageUrl: image.currentSrc, lightOnly: document.querySelectorAll('[data-ojp-light-only]').length };
  });
  assert.equal(state.mode, expectedMode);
  assert.equal(state.lightOnly, 0, 'Lesson must allow the platform mode to apply');
  assert.ok(state.overflow <= 1, `Page horizontal overflow: ${state.overflow}`);
  for (const probe of state.text) {
    assert.ok(!probe.missing, `Missing rendered ${probe.name}`);
    const minimum = ['title', 'heading', 'quizQuestion'].includes(probe.name) ? 3 : 4.5;
    assert.ok(probe.contrast >= minimum, `${expectedMode} ${probe.name} contrast ${probe.contrast.toFixed(2)} < ${minimum}: ${JSON.stringify(probe)}`);
  }
  for (const surface of state.surfaces) assert.ok(expectedMode === 'dark' ? surface.luminance < .2 : surface.luminance > .7, `${expectedMode} surface did not follow mode: ${JSON.stringify(surface)}`);
  assert.ok(state.imageLoaded);
  assert.ok(state.filters.every(filter => filter === 'none'), 'Mode must not invert/filter the screenshot or its ancestors');
  assert.equal(state.imageUrl, 'https://lesson-theme.test/sample.svg');
  assert.equal(state.authored.length, 3);
  for (const authored of state.authored) assert.equal(authored.background, 'rgb(255, 242, 204)', `${authored.text} background must preserve author selection`);
  for (const text of state.coloredText) assert.equal(text.foreground, text.text === '저자 지정 색상' ? 'rgb(139, 0, 0)' : 'rgb(18, 52, 86)', `${text.text} must preserve author text colour`);
  return state;
}

try {
  for (const width of [1440, 390, 320]) {
    const context = await browser.newContext({ viewport: { width, height: 1000 }, colorScheme: 'light' });
    const page = await context.newPage();
    const errors = [], failures = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 400) failures.push([response.status(), response.url()]); });
    await context.route('https://lesson-theme.test/**', async route => {
      const pathname = decodeURIComponent(new URL(route.request().url()).pathname);
      if (pathname === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
      if (pathname === '/noop.js') return route.fulfill({ contentType: 'text/javascript', body: '' });
      if (pathname === '/sample.svg' || pathname === '/empty.svg') return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="90"><rect width="240" height="90" fill="white"/><rect x="12" y="12" width="80" height="60" fill="#e63333"/><circle cx="165" cy="45" r="30" fill="#3366cc"/></svg>' });
      let file;
      if (pathname.startsWith('/platform/')) file = path.resolve(workspace, 'oj-integration-rebuild/oj-platform', pathname.slice('/platform/'.length));
      if (pathname.startsWith('/plugin/')) file = path.resolve(workspace, 'readcontents-library', pathname.slice('/plugin/'.length));
      if (!file || !file.startsWith(workspace + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return route.fulfill({ status: 404, body: 'Fixture asset missing' });
      return route.fulfill({ contentType: mime(file), body: fs.readFileSync(file) });
    });
    await page.goto('https://lesson-theme.test/');
    await page.locator('.contents-editor-public-visualization svg').waitFor();
    await page.locator('img').scrollIntoViewIfNeeded();
    await page.waitForFunction(() => document.querySelector('img').complete);
    const observations = [];
    observations.push({ choice: 'auto', os: 'light', state: await inspect(page, 'light') });
    for (const mode of ['dark', 'light']) {
      await page.getByRole('button', { name: mode === 'dark' ? '다크' : '라이트', exact: true }).click();
      assert.equal(await page.locator('html').getAttribute('data-ojp-scheme'), mode);
      assert.equal(await page.evaluate(() => localStorage.getItem('ojp-color-scheme')), mode);
      assert.equal(await page.getByRole('button', { name: mode === 'dark' ? '다크' : '라이트', exact: true }).getAttribute('aria-pressed'), 'true');
      observations.push({ choice: mode, os: 'light', state: await inspect(page, mode) });
      await page.screenshot({ path: path.join(output, `lesson-${width}-${mode}.png`), fullPage: true });
    }
    await page.getByRole('button', { name: '다크', exact: true }).click();
    await page.reload();
    await page.locator('.contents-editor-public-visualization svg').waitFor();
    assert.equal(await page.locator('html').getAttribute('data-ojp-scheme'), 'dark', 'Saved preference must survive reload');
    await inspect(page, 'dark');
    await page.getByRole('button', { name: '시스템', exact: true }).click();
    assert.equal(await page.evaluate(() => localStorage.getItem('ojp-color-scheme')), null);
    for (const os of ['dark', 'light']) {
      await page.emulateMedia({ colorScheme: os });
      assert.equal(await page.locator('html').getAttribute('data-ojp-scheme'), 'auto');
      observations.push({ choice: 'auto', os, state: await inspect(page, os) });
    }
    // Interaction states must remain readable after changing modes on this page.
    await page.getByRole('button', { name: '다크', exact: true }).click();
    await page.getByRole('radio', { name: '2 수치 예측', exact: true }).check();
    await page.getByRole('button', { name: '정답 확인', exact: true }).click();
    assert.equal(await page.locator('[data-contents-editor-quiz-result]').textContent(), '다시 생각해 보세요.');
    await inspect(page, 'dark');
    await page.getByRole('radio', { name: '1 분류', exact: true }).check();
    await page.getByRole('button', { name: '정답 확인', exact: true }).click();
    assert.equal(await page.locator('[data-contents-editor-quiz-result]').textContent(), '정답입니다.');
    assert.equal(await page.locator('[data-contents-editor-quiz-explanation]').isVisible(), true);
    const visual = page.locator('.contents-editor-public-visualization');
    assert.equal(await visual.locator('.contents-editor-public-visualization__step-count').textContent(), '1 / 2 단계');
    await visual.getByRole('button', { name: '다음', exact: true }).click();
    assert.equal(await visual.locator('.contents-editor-public-visualization__step-count').textContent(), '2 / 2 단계');
    assert.ok((await visual.locator('.contents-editor-public-visualization__step-note').textContent()).includes('준비한 데이터로 모델을 학습합니다.'));
    await inspect(page, 'dark');
    await page.screenshot({ path: path.join(output, `lesson-${width}-dark-interactive.png`), fullPage: true });
    await page.getByLabel('학습 메모', { exact: true }).fill('모드 전환 후 입력 유지');
    await page.getByRole('button', { name: '라이트', exact: true }).click();
    assert.equal(await page.getByLabel('학습 메모', { exact: true }).inputValue(), '모드 전환 후 입력 유지');
    assert.equal(await page.getByRole('radio', { name: '1 분류', exact: true }).isChecked(), true);
    await inspect(page, 'light');
    assert.deepEqual(errors, [], `JavaScript errors at ${width}`);
    assert.deepEqual(failures, [], `Missing assets at ${width}`);
    report.push({ width, observations, persistence: true, quizAndInputPreserved: true, errors, failures });
    await context.close();
  }
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ passed: true, report }, null, 2) + '\n');
  console.log(JSON.stringify({ passed: true, widths: report.length, modeStates: report.reduce((n, item) => n + item.observations.length, 0), output }));
} finally {
  await browser.close();
}
