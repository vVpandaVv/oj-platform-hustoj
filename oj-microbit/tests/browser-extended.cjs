// Actual remote Microsoft editor + local real PXT runtime acceptance for 0.2.
const assert = require('node:assert/strict');
const { savePublished } = require('./browser-publication.cjs');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { validHex } = require('../resources/js/microbit.js');
const assetRequire = createRequire(path.resolve(__dirname, '../resources/tools/package.json'));
const { createUniversalHexFlashDataSource } = assetRequire('@microbit/microbit-connection/universal-hex');

(async () => {
  const { fixture } = await import('./browser-server.mjs');
  const output = path.resolve(__dirname, '../../_notes/tmp/oj-microbit-browser-0.2');
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
  const summaries = [], errors = [];
  const progress = setInterval(() => process.stdout.write('[extended-browser] actual MakeCode/network work in progress\n'), 30000);
  try {
    for (const preset of process.env.MICROBIT_PRESET ? [process.env.MICROBIT_PRESET] : ['servo', 'i2c']) {
      const state = fixture(preset); await new Promise(resolve => state.server.listen(0, '127.0.0.1', resolve));
      const origin = 'http://127.0.0.1:' + state.server.address().port;
      const context = await browser.newContext({ viewport: { width: 1600, height: 1100 }, acceptDownloads: true });
      const page = await context.newPage(); page.on('pageerror', error => errors.push(preset + ': ' + error.message));
      await page.addInitScript(() => { window.addEventListener('message', event => { if (event.origin === 'https://makecode.microbit.org' && typeof event.data?.download === 'string') window.__mbNativeHex = event.data.download; }); });
      try {
        await page.goto(origin); await page.getByRole('button', { name: '실습 시작', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
        process.stdout.write('[extended-browser] ' + preset + ' official editor connected\n');
        await page.getByRole('button', { name: '블록', exact: true }).click();
        await page.waitForFunction(() => !document.querySelector('[data-oj-microbit]').ojMicrobit.busy && document.querySelector('[data-oj-microbit]').ojMicrobit.project.files['main.blocks']?.includes('<block '), null, { timeout: 60000 });
        await page.getByRole('button', { name: '프로젝트 저장', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.projectRevision === 1, null, { timeout: 45000 });
        const saved = state.saved().project;
        assert.match(saved.files['main.blocks'], /<block /);
        fs.writeFileSync(path.join(__dirname, 'fixtures/makecode-' + preset + '-project.json'), JSON.stringify(saved, null, 2) + '\n', 'utf8');
        await page.getByRole('button', { name: '자동 채점', exact: true }).click();
        await page.getByText('모든 테스트를 통과했습니다.', { exact: true }).waitFor({ timeout: 60000 });
        const graded = state.attempts.at(-1).result;
        process.stdout.write('[extended-browser] ' + preset + ' grade ' + graded.score + '\n');
        assert.equal(graded.score, 100); assert.equal(graded.status, 'graded'); assert.match(graded.runtimeVersion, /oj-gpio\/2/);
        if (preset === 'servo') {
          assert(graded.trace.some(entry => entry.signal === 'servo' && entry.value === 135));
          assert(graded.trace.some(entry => entry.signal === 'servo_pulse' && entry.value === 1800));
          await page.getByRole('slider', { name: '입력 센서 P0 입력' }).fill('800');
        } else {
          assert(graded.i2cTrace.some(entry => entry.operation === 'read' && entry.data[0] === 150));
          assert(graded.i2cTrace.some(entry => entry.operation === 'write' && JSON.stringify(entry.data) === '[1,90]'));
          assert.equal(graded.scenarios[0].i2cAssertions.length, 9);
          await page.getByRole('spinbutton', { name: 'I²C 레지스터 센서 주소 32 레지스터 0 입력' }).fill('90');
        }
        await page.getByLabel('실행 시간(ms)', { exact: true }).fill('600');
        await page.getByRole('button', { name: '센서 값으로 실행', exact: true }).click();
        await page.waitForFunction((kind) => {
          const app = document.querySelector('[data-oj-microbit]').ojMicrobit;
          return !app.busy && app.actuators.get('1').output.textContent === (kind === 'servo' ? '135°' : '90°') && (kind !== 'servo' || app.actuators.get('2').output.textContent === '1800µs');
        }, preset, { timeout: 60000 });
        if (preset === 'i2c') {
          const visual = await page.evaluate(() => {
            const app = document.querySelector('[data-oj-microbit]').ojMicrobit;
            return { register: app.i2cDisplays.get('32').outputs.get(1).textContent, log: app.i2cTraceTable.textContent };
          });
          assert.equal(visual.register, '90'); assert.match(visual.log, /0x20/); assert.match(visual.log, /1, 90/);
        }
        await page.waitForTimeout(650); await page.screenshot({ path: path.join(output, preset + '-student.png'), fullPage: true });
        process.stdout.write('[extended-browser] ' + preset + ' preview rendered, requesting native HEX\n');
        const downloadPromise = page.waitForEvent('download', { timeout: 150000 });
        await page.getByRole('button', { name: 'HEX 다운로드', exact: true }).click();
        const download = await Promise.race([downloadPromise, page.getByText('컴파일 결과가 올바른 Intel HEX 파일이 아닙니다.', { exact: true }).waitFor({ timeout: 150000 }).then(() => { throw new Error('Native HEX rejected by validation'); })]), hex = fs.readFileSync(await download.path(), 'utf8');
        assert(validHex(hex)); assert(hex.length > 10000); assert.doesNotMatch(download.suggestedFilename(), /\.hex\.hex$/i);
        const hexSource = createUniversalHexFlashDataSource(hex), usbDataBytes = { V1: (await hexSource('V1')).length, V2: (await hexSource('V2')).length };
        assert(usbDataBytes.V1 > 10000 && usbDataBytes.V2 > 10000);
        process.stdout.write('[extended-browser] ' + preset + ' HEX downloaded ' + hex.length + '\n');
        await page.goto(origin + '/?mode=teacher'); await page.getByRole('button', { name: '과제 편집', exact: true }).click();
        if (preset === 'i2c') {
          await page.getByLabel('7비트 주소(십진수)', { exact: true }).fill('32');
          assert.equal(await page.locator('[data-mb-i2c-devices] input[type=checkbox]').nth(1).isChecked(), true);
          assert.equal(await page.locator('.ojmicrobit-i2c-scenario .ojmicrobit-order').count(), 9);
        }
        await page.getByLabel('제목', { exact: true }).fill(preset === 'servo' ? '브라우저 검증 서보 과제' : '브라우저 검증 I²C 과제');
        await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
        await savePublished(page);
        assert.equal(state.exercise().connections[0].signal, preset === 'servo' ? 'analog' : 'servo');
        if (preset === 'i2c') { assert.equal(state.exercise().i2cDevices[0].registers[1].writable, true); assert.equal(state.exercise().scenarios[0].i2cAssertions.length, 9); }
        await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
        await page.screenshot({ path: path.join(output, preset + '-teacher.png'), fullPage: true });
        summaries.push({ preset, officialEditor: true, nativeBlocks: true, realGrade: graded.score, preview: true, teacherSave: true, actualHexBytes: hex.length, hexChecksums: true, usbDataPreparation: usbDataBytes });
        process.stdout.write('[extended-browser] ' + JSON.stringify(summaries.at(-1)) + '\n');
      } catch (error) {
        await page.screenshot({ path: path.join(output, preset + '-failure.png'), fullPage: true }).catch(() => {});
        const nativeHex = await page.evaluate(() => window.__mbNativeHex || null);
        if (nativeHex) { fs.writeFileSync(path.join(output, preset + '-native-debug.hex'), nativeHex, 'utf8'); process.stdout.write('[extended-browser] rejected HEX ' + nativeHex.length + ' bytes\n'); }
        process.stdout.write('[extended-browser] ' + preset + ' failure ' + JSON.stringify(await page.evaluate(() => ({ text: document.body.innerText.slice(-6000), status: document.querySelector('[data-oj-microbit]')?.ojMicrobit?.status?.textContent }))) + '\n'); throw error;
      } finally { await context.close(); await new Promise(resolve => state.server.close(resolve)); }
    }
    assert.equal(errors.length, 0, errors.join('\n'));
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ cases: summaries, uiErrors: errors, hardware: 'not tested', database: 'memory REST fixture' }, null, 2) + '\n', 'utf8');
  } finally { clearInterval(progress); await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
