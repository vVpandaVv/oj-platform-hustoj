const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { savePublished } = require('./browser-publication.cjs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const { fixture } = await import('./browser-server.mjs');
  const output = path.resolve(__dirname, '../../_notes/tmp/oj-microbit-sequence'); fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } }), page = await context.newPage(), errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const state = fixture('sequence'); await new Promise(resolve => state.server.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + state.server.address().port;
  const ready = () => page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
  const closeServer = server => new Promise(resolve => { server.close(resolve); server.closeAllConnections(); });
  const edit = async base => { await page.goto(base + '/?mode=teacher'); await page.getByRole('button', { name: '과제 편집', exact: true }).click(); await ready(); };
  const save = () => savePublished(page);
  const learn = async base => { await page.goto(base); await page.getByRole('button', { name: '실습 시작', exact: true }).click(); await ready(); };
  const grade = async passed => { await page.getByRole('button', { name: '자동 채점', exact: true }).click(); await page.getByText(passed ? '모든 테스트를 통과했습니다.' : '채점이 끝났습니다. 테스트별 결과를 확인하세요.', { exact: true }).waitFor({ timeout: 60000 }); };
  try {
    await edit(origin);
    let first = page.locator('[data-mb-step]').first(); assert.equal(await first.locator('[data-mb-sequence-action]').count(), 2);
    await first.screenshot({ path: path.join(output, 'sequence-first-card.png') });
    const hold = first.locator('[data-mb-sequence-action]').first().locator('.ojmicrobit-sequence-main input'); await hold.fill('1.5'); await hold.press('Tab');
    assert.equal(state.exercise().scenarios[0].durationMs, 2000); // Unsaved edits do not mutate server state.
    await save(); assert.equal(state.exercise().scenarios[0].durationMs, 2200);
    await learn(origin); await page.getByRole('button', { name: '프로젝트 저장', exact: true }).click(); await page.getByText('프로젝트를 저장했습니다.', { exact: true }).waitFor({ timeout: 60000 });
    await grade(false); const failure = state.attempts.at(-1).result;
    assert.equal(failure.scenarios[0].outputSequences[0].steps[0].passed, false); assert.equal(failure.scenarios[0].outputSequences[0].steps[1].matchedAtMs, null);
    await page.getByText('앞 동작 실패로 검사하지 않음', { exact: true }).waitFor();
    await page.locator('.ojmicrobit-result-sequence').screenshot({ path: path.join(output, 'sequence-failure.png') });
    process.stdout.write('[sequence] hold 1.5s fails; following action explicitly skipped\n');
    await edit(origin); first = page.locator('[data-mb-step]').first(); const restore = first.locator('[data-mb-sequence-action]').first().locator('.ojmicrobit-sequence-main input'); await restore.fill('1'); await restore.press('Tab');
    // Reorder and restore via the actual controls, keeping on → off in the saved exercise.
    await page.locator('[data-mb-step]').first().getByRole('button', { name: '동작 아래로', exact: true }).click();
    await page.locator('[data-mb-step]').first().getByRole('button', { name: '동작 위로', exact: true }).click();
    await save(); assert.equal(state.exercise().scenarios[0].outputSequences[0].steps[0].holdMs, 1000);
    await page.locator('[data-mb-step]').first().screenshot({ path: path.join(output, 'sequence-first-card.png') });
    await learn(origin); await grade(true); const success = state.attempts.at(-1).result, actions = success.scenarios[0].outputSequences[0].steps;
    assert.equal(success.score, 100); assert.match(success.runtimeVersion, /oj-gpio\/3/); assert.equal(actions[0].matchedAtMs, 200); assert.equal(actions[0].completedAtMs, 1200); assert.equal(actions[1].matchedAtMs, 1200); assert.equal(actions[1].completedAtMs, 1300);
    await page.locator('.ojmicrobit-result-sequence summary').click(); await page.locator('.ojmicrobit-result-sequence').screenshot({ path: path.join(output, 'sequence-success.png') });
    fs.writeFileSync(path.join(output, 'actual-makecode-project.json'), JSON.stringify(state.saved().project, null, 2) + '\n', 'utf8');
    process.stdout.write('[sequence] actual MakeCode → PHP project/exercise schema → PXT → PHP result hash: 100\n');
    const servo = fixture('sequence'), spec = servo.exercise(); spec.connections[1].signal = 'servo'; spec.connections[1].device = 'servo'; spec.connections[1].label = '동작 서보';
    spec.scenarios[0].outputSequences[0].signal = 'servo'; spec.scenarios[0].outputSequences[0].steps[0].expected = 135; spec.scenarios[0].outputSequences[0].steps[1].expected = 45;
    spec.starterProject.files['main.ts'] = 'if (pins.digitalReadPin(DigitalPin.P0) == 1) {\n    basic.pause(200)\n    pins.servoWritePin(AnalogPin.P1, 135)\n    basic.pause(1000)\n    pins.servoWritePin(AnalogPin.P1, 45)\n}\n';
    await new Promise(resolve => servo.server.listen(0, '127.0.0.1', resolve));
    let servoResult;
    try { const servoOrigin = 'http://127.0.0.1:' + servo.server.address().port; await learn(servoOrigin); await page.getByRole('button', { name: '프로젝트 저장', exact: true }).click(); await page.getByText('프로젝트를 저장했습니다.', { exact: true }).waitFor({ timeout: 60000 }); await grade(true); servoResult = servo.attempts.at(-1).result; assert.equal(servoResult.score, 100); assert.equal(servoResult.scenarios[0].outputSequences[0].steps[0].matchedAtMs, 200); } finally { await closeServer(servo.server); }
    assert.equal(errors.length, 0);
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ primarySequenceCards: true, actualMakeCodeSourceSave: true, phpProjectAndExerciseSchema: true, phpRunnerResultSchema: true, automaticDurationMs: 2200, failureHoldMs: 1500, failureFollowingActionSkipped: true, gpioScore: success.score, servoScore: servoResult.score, matchedAndCompletedMs: actions.map(step => [step.matchedAtMs, step.completedAtMs]), runtimeVersion: success.runtimeVersion, projectHash: success.projectHash, uiErrors: errors, database: 'memory REST fixture' }, null, 2) + '\n', 'utf8');
  } catch (error) { await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {}); process.stdout.write('[sequence] ' + (await page.locator('body').innerText()).slice(-5000) + '\n'); throw error;
  } finally { await browser.close(); await closeServer(state.server); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
