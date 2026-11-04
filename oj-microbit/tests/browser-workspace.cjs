const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { canonicalProject } = require('../resources/js/microbit.js');
(async () => {
  const { fixture, phpSchema } = await import('./browser-server.mjs');
  const state = fixture(); await new Promise(resolve => state.server.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + state.server.address().port;
  const output = path.resolve(__dirname, '../../_notes/tmp/oj-microbit-workspace'); fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
  const page = await browser.newPage({ viewport: { width: 1600, height: 1100 } });
  await page.addInitScript(() => { window.__rawWorkspace = []; window.addEventListener('message', event => {
    if (event.origin === 'https://makecode.microbit.org' && event.data?.type === 'pxthost' && event.data.action === 'workspacesave') window.__rawWorkspace.push(event.data.project);
  }); });
  const captures = [];
  async function capture(label) {
    const raw = await page.evaluate(() => window.__rawWorkspace.at(-1)); assert(raw?.text);
    let config = {}; try { config = JSON.parse(raw.text['pxt.json']); } catch {}
    const project = canonicalProject(raw); let validation;
    try { const normalized = phpSchema(project); validation = { ok: true, files: Object.keys(normalized.files) }; }
    catch (error) { validation = { ok: false, message: error.message }; }
    captures.push({ label, rawFiles: Object.keys(raw.text), sourceFiles: config.files, configKeys: Object.keys(config), frontendFiles: Object.keys(project.files), validation });
    fs.writeFileSync(path.join(output, label + '.json'), JSON.stringify({ raw, project, validation }, null, 2) + '\n', 'utf8');
    process.stdout.write('[workspace] ' + JSON.stringify(captures.at(-1)) + '\n');
  }
  try {
    await page.goto(origin); await page.getByRole('button', { name: '실습 시작', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    await page.evaluate(async () => { await document.querySelector('[data-oj-microbit]').ojMicrobit.driver.capture(); });
    await capture('initial');
    await page.getByRole('button', { name: '기본 보드 실행', exact: true }).click();
    await page.waitForTimeout(1500);
    const frames = [];
    for (const frame of page.frames()) {
      const controls = await frame.evaluate(() => [...document.querySelectorAll('[role=slider],[role=button]')].slice(0, 30).map(node => ({ id: node.id, role: node.getAttribute('role'), label: node.getAttribute('aria-label') }))).catch(() => []);
      frames.push({ url: frame.url(), controls });
      if (/simulator|sim\.html/.test(frame.url())) {
        const sliders = frame.locator('[role=slider]'); if (await sliders.count()) { await sliders.first().focus(); await sliders.first().press('ArrowUp'); }
        const button = frame.locator('[role=button]'); if (await button.count()) { await button.first().click({ force: true }); }
      }
    }
    fs.writeFileSync(path.join(output, 'frames.json'), JSON.stringify(frames, null, 2), 'utf8');
    await page.waitForTimeout(1200);
    await page.evaluate(async () => { await document.querySelector('[data-oj-microbit]').ojMicrobit.driver.capture(); });
    await capture('simulator-input');
    await page.getByRole('button', { name: '블록', exact: true }).click(); await page.waitForTimeout(1000);
    await page.evaluate(async () => { await document.querySelector('[data-oj-microbit]').ojMicrobit.driver.capture(); });
    await capture('blocks');
    await page.getByRole('button', { name: '프로젝트 저장', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.projectRevision === 1, null, { timeout: 45000 });
    assert.equal(state.saved().project.files['.simstate.json'], undefined);
    await page.reload(); await page.getByRole('button', { name: '실습 시작', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    await page.evaluate(async () => { await document.querySelector('[data-oj-microbit]').ojMicrobit.driver.capture(); });
    await capture('reopened-saved');
    await page.evaluate(async () => { await document.querySelector('[data-oj-microbit]').ojMicrobit.driver.request('newproject', { options: { name: '빈 블록 프로젝트' } }); });
    await page.waitForTimeout(1800);
    await page.evaluate(async () => { await document.querySelector('[data-oj-microbit]').ojMicrobit.driver.request('saveproject'); });
    await capture('new-empty-blocks');
    assert(captures.some(capture => capture.rawFiles.includes('.simstate.json')), 'Actual simulator state metadata was not exercised');
    assert(captures.every(capture => capture.validation.ok), 'An actual workspace did not pass PHP ProjectSchema');
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(captures, null, 2) + '\n', 'utf8');
  } finally { await browser.close(); await new Promise(resolve => state.server.close(resolve)); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
