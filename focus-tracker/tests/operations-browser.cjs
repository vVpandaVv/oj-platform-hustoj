'use strict';

// Actual settings/operations handler and production save validation. The
// fixture invokes the private save method; WordPress nonce/redirect handling
// is deliberately outside this browser test's claim.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
function playwright() {
    const choices = [process.env.PLAYWRIGHT_MODULE_PATH, 'playwright', process.env.USERPROFILE && path.join(process.env.USERPROFILE, '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')];
    for (const item of choices.filter(Boolean)) { try { return require(item); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; } }
    throw new Error('Playwright is required.');
}
const root = path.resolve(__dirname, '../..');
const temporary = path.join(root, '_notes/tmp/focus-tracker-tests');
fs.mkdirSync(temporary, { recursive: true });
const db = path.join(temporary, `operations-browser-${process.pid}-${Date.now()}.sqlite`);
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php');
let browser; let server; let base; let output = ''; let assertions = 0;
const errors = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(condition, message) { assert.ok(condition, message); assertions += 1; }
async function until(fn, message) {
    const end = Date.now() + 15000;
    do { if (await fn()) return; await delay(60); } while (Date.now() < end);
    throw new Error(message);
}
async function api(url, body) {
    const response = await fetch(base + url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'focus-test-nonce' }, body: JSON.stringify(body) });
    const text = await response.text(); assert.ok(response.ok, `${url}: ${response.status} ${text.slice(0, 1000)}`); return JSON.parse(text);
}
async function saveFromForm(page, replacements) {
    return page.evaluate(async values => {
        const form = document.querySelector('form');
        const data = Object.fromEntries(new FormData(form));
        Object.assign(data, values);
        const response = await fetch('/__test/settings-save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
        return { status: response.status, body: await response.json() };
    }, replacements);
}
async function run() {
    await api('/__test/reset', {});
    await api('/__test/check-config', { reset: true, scenario: 'draft', initialDelayMs: 600, pollMs: 1000, gatePollMs: 500, cooldownSeconds: 60, dailyLimit: 20 });
    await api('/__test/activity', { name: '운영 상태 검증', startOffsetMs: -1000, endOffsetMs: 60000 });
    const context = await browser.newContext();
    const learner = await context.newPage();
    learner.on('pageerror', error => errors.push(error.message));
    await learner.goto(base + '/problems/123');
    await learner.locator('[data-oj-ace-editor][data-mounted="true"]').waitFor();
    await learner.locator('select[name="language_id"]').selectOption('6');
    await learner.evaluate(() => { const editor = ace.edit(document.querySelector('[data-oj-ace-surface]')); editor.setValue('# PRIVATE_OPERATIONS_SOURCE\nvalue = 8\nif value <= 10:\n    print(value)', -1); editor.focus(); });
    await learner.locator('[data-ftk-check]').waitFor();
    await learner.getByRole('button', { name: '모르겠음', exact: true }).click();
    await learner.getByRole('button', { name: '닫기', exact: true }).waitFor();
    await learner.close();
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/__test/settings');
    const panel = page.locator('.ftk-operations');
    await panel.waitFor();
    check(await panel.getByRole('heading', { name: '운영 상태', exact: true }).count() === 1, 'Actual settings handler renders an operations panel.');
    check((await panel.locator('[data-ftk-storage-health]').textContent()).includes('준비 완료'), 'Storage readiness is visible.');
    for (const label of ['정리 마지막 실행', '정리 마지막 성공', '남은 정리 작업', '정리 실패', '다음 이어 처리']) {
        check(await panel.getByText(label, { exact: true }).count() === 1, `Operations displays ${label}.`);
    }
    for (const label of ['기록 수집', '활동 시간 확인', '자동 문항']) {
        const row = panel.getByRole('row').filter({ has: page.getByRole('rowheader', { name: label, exact: true }) });
        check(await row.count() === 1 && /성공: [1-9]/.test(await row.textContent()), `${label} operational success comes from actual API usage.`);
    }
    check(!(await panel.textContent()).includes('PRIVATE_OPERATIONS_SOURCE') && !(await panel.textContent()).includes('value = 8'), 'Operational counters and audit summaries expose no learner source.');
    check((await panel.textContent()).includes('검증 가능한 문항 없음은 정상적인 불출제'), 'Normal no-candidate outcomes are distinguished from inattention.');
    const raw = page.getByLabel('원시 이벤트 보존 기간(일)', { exact: true });
    const checks = page.getByLabel('확인 문항·응답 보관 기간(일)', { exact: true });
    const aggregate = page.getByLabel('활동·일별 집계 보관 기간(일, 0은 무제한)', { exact: true });
    check(await raw.inputValue() === '60' && await checks.inputValue() === '60' && await aggregate.inputValue() === '0', 'Independent retention periods render their actual defaults.');
    check(await raw.getAttribute('min') === '1' && await checks.getAttribute('min') === '1' && await aggregate.getAttribute('min') === '0', 'Input bounds distinguish finite source lifetimes from unlimited aggregates.');
    await raw.fill('14'); await checks.fill('7'); await aggregate.fill('30');
    const saved = await saveFromForm(page, {});
    check(saved.status === 200 && saved.body.error === null, 'The real save validator accepts independent valid lifetimes.');
    check(saved.body.settings.retention_days === 14 && saved.body.settings.check_retention_days === 7 && saved.body.settings.aggregate_retention_days === 30, 'All three retention settings persist independently.');
    await page.reload();
    check(await raw.inputValue() === '14' && await checks.inputValue() === '7' && await aggregate.inputValue() === '30', 'Reloaded settings reflect persisted lifetimes.');
    await page.getByText('최근 설정·활동 변경', { exact: true }).click();
    const audit = page.locator('.ftk-operations details');
    check((await audit.textContent()).includes('설정 변경') && (await audit.textContent()).includes('aggregate_retention_days'), 'Settings changes are visible in the bounded operational audit.');
    const rejected = await saveFromForm(page, { aggregate_retention_days: '14' });
    check(rejected.status === 400 && /최소 1일/.test(rejected.body.error), 'A summary lifetime that cannot outlive source data is rejected.');
    check(rejected.body.settings.aggregate_retention_days === 30, 'Rejected validation preserves the previous settings.');
    const unlimited = await saveFromForm(page, { aggregate_retention_days: '0' });
    check(unlimited.status === 200 && unlimited.body.settings.aggregate_retention_days === 0, 'Unlimited cumulative summaries remain a supported explicit choice.');
    await page.reload(); await page.setViewportSize({ width: 390, height: 720 });
    const geometry = await page.evaluate(() => ({ width: innerWidth, document: document.documentElement.scrollWidth,
        panel: document.querySelector('.ftk-operations').getBoundingClientRect().toJSON() }));
    check(geometry.document <= geometry.width + 1 && geometry.panel.left >= -1 && geometry.panel.right <= geometry.width + 1,
        `Settings and operations fit a narrow mobile viewport: ${JSON.stringify(geometry)}`);
    await until(async () => await page.getByRole('button', { name: '저장', exact: true }).count() === 1, 'Settings retain their save action.');
    await context.close();
}
(async () => {
    const port = await new Promise(resolve => { const socket = net.createServer(); socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); }); });
    base = `http://127.0.0.1:${port}`;
    server = spawn(php, ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], { cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: db }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    server.stdout.on('data', data => { output += data; }); server.stderr.on('data', data => { output += data; });
    await until(async () => { try { return (await fetch(base + '/__test/state')).ok; } catch (_) { return false; } }, 'PHP operations fixture did not start.');
    const executablePath = [process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE, process.env.CHROME_PATH, process.platform === 'win32' && path.join(process.env.ProgramFiles || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')].find(item => item && fs.existsSync(item));
    browser = await playwright().chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    await run(); check(errors.length === 0, 'No browser errors: ' + errors.join('; '));
    console.log(`Operations browser: ${assertions} checks passed (real settings/operations UI, independent lifetimes, validated save, audit, narrow viewport).`);
})().catch(error => { console.error(error.stack || error); console.error(output.slice(-10000)); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
    for (const suffix of ['', '-journal', '-wal', '-shm']) { try { fs.unlinkSync(db + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; } }
});
