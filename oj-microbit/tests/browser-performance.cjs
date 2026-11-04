const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const { fixture } = await import('./browser-server.mjs');
  const output = path.resolve(__dirname, '../../_notes/tmp/oj-microbit-performance'); fs.mkdirSync(output, { recursive: true });
  const state = fixture('i2c', { exerciseCount: 125, attemptCount: 125 }); await new Promise(resolve => state.server.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + state.server.address().port;
  const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } }), errors = [], payloads = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', async response => { if (response.url().startsWith(origin + '/api/')) { try { const text = await response.text(); payloads.push({ path: response.url().slice(origin.length), bytes: Buffer.byteLength(text), json: JSON.parse(text) }); } catch {} } });
  const settle = () => page.waitForFunction(() => !document.querySelector('[data-oj-microbit]').ojMicrobit.busy);
  const pageButton = async (scope, name) => { await scope.getByRole('button', { name, exact: true }).click(); await settle(); };
  try {
    await page.goto(origin); await page.getByRole('button', { name: '실습 시작', exact: true }).first().waitFor(); await settle();
    const seen = new Set(); let exercisePages = 0;
    while (true) {
      const cards = page.locator('.ojmicrobit-list > .ojmicrobit-card'), titles = await cards.locator('h2').allTextContents(); assert(titles.length <= 20);
      for (const title of titles) { const id = Number(title.split(' ').at(-1)); assert(!seen.has(id)); seen.add(id); }
      exercisePages++; if (!await page.getByRole('button', { name: '다음 페이지', exact: true }).count()) break;
      await pageButton(page, '다음 페이지');
    }
    assert.equal(seen.size, 125); assert.equal(exercisePages, 7);
    await page.screenshot({ path: path.join(output, 'exercise-last-page.png'), fullPage: true });
    await pageButton(page, '이전 페이지'); assert.equal(await page.locator('.ojmicrobit-list > .ojmicrobit-card').count(), 20); await pageButton(page, '다음 페이지');
    const target = page.locator('.ojmicrobit-list > .ojmicrobit-card').filter({ has: page.getByRole('heading', { name: state.exercise().title + ' 1', exact: true }) });
    await target.getByRole('button', { name: '실습 시작', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    await pageButton(page, '채점 기록'); let historyPages = 1;
    while (await page.locator('[data-mb-pages]').last().getByRole('button', { name: '다음 페이지', exact: true }).count()) { assert(await page.locator('[data-mb-pages]').last().evaluate(node => node.parentNode.querySelectorAll('tbody tr').length) <= 20); await pageButton(page.locator('[data-mb-pages]').last(), '다음 페이지'); historyPages++; }
    assert.equal(historyPages, 7); assert.equal(await page.locator('[data-mb-pages]').last().evaluate(node => node.parentNode.querySelectorAll('tbody tr').length), 5);
    await pageButton(page.locator('[data-mb-pages]').last(), '이전 페이지');
    const detailsBefore = state.requests.filter(request => /attempts\/\d+$/.test(request.path)).length;
    await page.getByRole('button', { name: '결과 보기', exact: true }).first().click(); await settle(); assert.equal(state.requests.filter(request => /attempts\/\d+$/.test(request.path)).length, detailsBefore + 1);
    await pageButton(page, '채점 기록으로'); await page.locator('[data-mb-pages]').last().screenshot({ path: path.join(output, 'history-pages.png') });
    const beforeRuns = state.requests.filter(request => request.path.endsWith('/run')).length;
    await page.getByRole('button', { name: '자동 채점', exact: true }).evaluate(button => { button.click(); button.click(); });
    await page.getByText('모든 테스트를 통과했습니다.', { exact: true }).waitFor({ timeout: 60000 });
    assert.equal(state.requests.filter(request => request.path.endsWith('/run')).length, beforeRuns + 1); assert.equal(state.attempts.at(-1).result.score, 100);
    const register = page.getByRole('spinbutton', { name: 'I²C 레지스터 센서 주소 32 레지스터 0 입력', exact: true }); await register.fill('90'); await pageButton(page, '센서 값으로 실행');
    await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.actuators.get('1').meter.value === 90); assert.equal(await page.locator('[data-oj-microbit]').evaluate(node => node.ojMicrobit.traceTimers.length), 1);
    const replay = await page.locator('[data-oj-microbit]').evaluate(node => {
      const app = node.ojMicrobit, writes = Array.from({ length: 5000 }, (_, index) => ({ atMs: 0, pin: 1, signal: 'servo', value: index % 181, kind: 'write' })), bus = Array.from({ length: 5000 }, (_, index) => ({ atMs: 0, address: 32, operation: index % 2 ? 'read' : 'write', data: [index % 2 ? index % 256 : 0], repeated: false, kind: 'i2c' }));
      const started = performance.now(); app.playTrace(writes, 100, bus); return { initialTimerCount: app.traceTimers.length, setupMs: performance.now() - started };
    });
    await page.waitForFunction(() => { const app = document.querySelector('[data-oj-microbit]').ojMicrobit; return app.actuators.get('1').meter.value === 4999 % 181 && app.traceTimers.length === 0; });
    const dense = await page.locator('[data-oj-microbit]').evaluate(node => { const app = node.ojMicrobit; return { pinRows: app.traceTable.children.length, i2cRows: app.i2cTraceTable.children.length, finalPinValue: app.actuators.get('1').meter.value, finalRegisterValue: app.i2cDisplays.get('32').outputs.get(0).textContent }; });
    assert.equal(replay.initialTimerCount, 1); assert.equal(dense.pinRows, 60); assert.equal(dense.i2cRows, 100); assert.equal(dense.finalRegisterValue, String(4999 % 256));
    const summaryPayloads = payloads.filter(payload => /\/attempts\?/.test(payload.path)), exercisePayloads = payloads.filter(payload => /\/api\/exercises\?/.test(payload.path));
    assert(summaryPayloads.every(payload => !/"trace"|"scenarios"|"project"/.test(JSON.stringify(payload.json)))); assert(exercisePayloads.every(payload => !/"starterProject"|"scenarios"/.test(JSON.stringify(payload.json))));
    assert.equal(errors.length, 0); assert.equal(state.requests.filter(request => request.path === 'status').length, 1);
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ exerciseCount: seen.size, exercisePages, historyCount: 125, historyPages, maximumDomRowsPerPage: 20, summaryPayloadMaxBytes: Math.max(...summaryPayloads.map(payload => payload.bytes)), exercisePayloadMaxBytes: Math.max(...exercisePayloads.map(payload => payload.bytes)), lazyHistoryDetail: true, duplicateGradeRequests: 1, actualMakeCodeAndPxtScore: state.attempts.at(-1).result.score, actualPreviewServo: 90, denseEvents: 10000, replay, dense, readinessRequests: 1, automaticPolling: false, uiErrors: errors, database: 'memory REST fixture; SQL plans verified separately' }, null, 2) + '\n', 'utf8');
    process.stdout.write('[performance] 125 exercises + 125 histories paginated; actual MakeCode/PXT 100; 10000-event replay passed\n');
  } catch (error) { await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {}); process.stdout.write((await page.locator('body').innerText()).slice(-3000) + '\n'); throw error; }
  finally { await browser.close(); await new Promise(resolve => { state.server.close(resolve); state.server.closeAllConnections(); }); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
