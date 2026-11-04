// Actual MakeCode network acceptance. Requires Playwright and a downloaded Chromium.
const assert = require('node:assert/strict');
const { savePublished } = require('./browser-publication.cjs');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const { fixture } = await import('./browser-server.mjs');
  const state = fixture(); await new Promise(resolve => state.server.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + state.server.address().port;
  const output = path.resolve(__dirname, '../../_notes/tmp/oj-microbit-browser'); fs.mkdirSync(output, { recursive: true });
  let browser, currentPage;
  const progress = setInterval(() => process.stdout.write('[browser] waiting for actual MakeCode/network response\n'), 30000);
  try {
    browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1100 }, acceptDownloads: true });
    const page = await context.newPage(); currentPage = page;
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    page.on('response', async response => { if (/\/api\/exercises\/1\/(run|preview)$/.test(response.url())) {
      try { const result = await response.json(); process.stdout.write('[browser] runner ' + JSON.stringify({ status: result.status, passed: result.passed, score: result.score, diagnostics: result.diagnostics, message: result.message, scenarios: result.scenarios }) + '\n'); } catch {}
    } });
    await page.addInitScript(() => { window.__mbMessages = []; window.addEventListener('message', event => {
      if (event.origin === 'https://makecode.microbit.org' && event.data && typeof event.data === 'object') {
        if (typeof event.data.download === 'string') window.__mbNativeHex = event.data.download;
        window.__mbMessages.push({ type: event.data.type, action: event.data.action, cmd: event.data.cmd, keys: Object.keys(event.data) });
        if (window.__mbMessages.length > 100) window.__mbMessages.shift();
      }
    }); });
    await page.goto(origin); await page.getByRole('button', { name: '실습 시작', exact: true }).click();
    process.stdout.write('[browser] actual MakeCode iframe loading\n');
    try {
      await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    } catch (error) {
      await page.screenshot({ path: path.join(output, 'load-failure.png'), fullPage: true });
      process.stdout.write(JSON.stringify(await page.evaluate(() => ({ messages: window.__mbMessages, text: document.body.innerText.slice(-2500) }))) + '\n'); throw error;
    }
    process.stdout.write('[browser] actual MakeCode connected\n');
    await page.getByRole('button', { name: '블록', exact: true }).click();
    await page.waitForFunction(() => !document.querySelector('[data-oj-microbit]').ojMicrobit.busy && document.querySelector('[data-oj-microbit]').ojMicrobit.project.files['main.blocks']?.includes('<block '), null, { timeout: 60000 });
    await page.getByRole('button', { name: '프로젝트 저장', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.projectRevision === 1, null, { timeout: 45000 });
    assert.match(state.saved().project.files['main.ts'], /analogReadPin/);
    assert.match(state.saved().project.files['main.blocks'], /<block /);
    fs.mkdirSync(path.join(__dirname, 'fixtures'), { recursive: true });
    const capturedFixture = path.join(__dirname, 'fixtures/makecode-project.json');
    if (!fs.existsSync(capturedFixture) || process.env.UPDATE_MAKECODE_FIXTURE === '1') { fs.writeFileSync(capturedFixture, JSON.stringify(state.saved().project, null, 2) + '\n', 'utf8'); }
    process.stdout.write('[browser] saved files ' + JSON.stringify(Object.keys(state.saved().project.files)) + '\n');
    process.stdout.write('[browser] official editor source saved through workspace protocol\n');
    if (process.env.MICROBIT_CAPTURE_ONLY === '1') { process.stdout.write('[browser] native blocks project fixture captured\n'); return; }
    await page.getByRole('button', { name: '자동 채점', exact: true }).click();
    await page.getByText('모든 테스트를 통과했습니다.', { exact: true }).waitFor({ timeout: 60000 });
    assert.equal(state.attempts.at(-1).result.score, 100); assert.equal(state.attempts.at(-1).result.status, 'graded');
    assert(state.attempts.at(-1).result.trace.some(write => write.pin === 1 && write.value === 1));
    assert(state.attempts.at(-1).result.trace.some(write => write.pin === 2 && write.value === 800));
    process.stdout.write('[browser] real PXT GPIO grade passed, digital and PWM evidence recorded\n');
    const slider = page.getByRole('slider', { name: '조도센서 P0 입력' }); await slider.fill('800');
    await page.getByRole('button', { name: '센서 값으로 실행', exact: true }).click();
    await page.waitForFunction(() => [...document.querySelector('[data-oj-microbit]').ojMicrobit.actuators.values()].some(device => device.connection.pin === 2 && device.output.textContent.includes('800')), null, { timeout: 60000 });
    process.stdout.write('[browser] sensor control and actuator playback matched real output\n');
    const downloadPromise = page.waitForEvent('download', { timeout: 150000 });
    await page.getByRole('button', { name: 'HEX 다운로드', exact: true }).click();
    const download = await downloadPromise; const downloadPath = await download.path(); const hex = fs.readFileSync(downloadPath, 'utf8');
    assert.match(download.suggestedFilename(), /\.hex$/i); assert.doesNotMatch(download.suggestedFilename(), /\.hex\.hex$/i);
    const { validHex } = require('../resources/js/microbit.js'); assert(validHex(hex)); assert(hex.length > 10000);
    process.stdout.write('[browser] actual MakeCode native HEX downloaded and record checksums verified: ' + hex.length + ' bytes\n');
    await page.reload(); await page.getByRole('button', { name: '실습 시작', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    assert.equal(await page.evaluate(() => document.querySelector('[data-oj-microbit]').ojMicrobit.projectRevision), 1);
    await page.getByRole('button', { name: '채점 기록', exact: true }).click(); await page.getByText('100', { exact: true }).waitFor();
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(output, 'student.png'), fullPage: true });
    await page.goto(origin + '/?mode=teacher'); await page.getByRole('button', { name: '과제 편집', exact: true }).click();
    await page.getByLabel('제목', { exact: true }).fill('검증한 마이크로비트 과제');
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    await savePublished(page);
    assert.equal(state.exercise().title, '검증한 마이크로비트 과제');
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(output, 'teacher.png'), fullPage: true });
    const summary = { officialEditor: true, nativeBlocksSaveRestore: true, sourceSaveRestore: true, realPxtGrade: true, sensorActuatorPlayback: true, actualHexBytes: hex.length,
      hexRecordChecksums: true, teacherFormSave: true, uiErrors: errors, hardwareUsbFlash: 'not tested', wordpressDatabase: 'memory REST fixture' };
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(summary, null, 2));
    assert.equal(errors.length, 0, errors.join('\n')); process.stdout.write('[browser] ' + JSON.stringify(summary) + '\n');
  } catch (error) {
    if (currentPage && !currentPage.isClosed()) {
      await currentPage.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
      const nativeHex = await currentPage.evaluate(() => window.__mbNativeHex || null);
      if (nativeHex) { fs.writeFileSync(path.join(output, 'native-debug.hex'), nativeHex, 'utf8'); process.stdout.write('[browser] rejected HEX length ' + nativeHex.length + ', prefix ' + nativeHex.slice(0, 100) + '\n'); }
      process.stdout.write('[browser] failure state ' + JSON.stringify(await currentPage.evaluate(() => ({ messages: window.__mbMessages, text: document.body.innerText.slice(-5000) }))) + '\n');
    }
    throw error;
  } finally { clearInterval(progress); if (browser) await browser.close(); await new Promise(resolve => state.server.close(resolve)); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
