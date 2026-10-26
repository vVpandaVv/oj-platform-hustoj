import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const css = await readFile('resources/css/operations-diagnostics.css', 'utf8');
const script = await readFile('resources/js/operations-storage-detail.js', 'utf8');
await mkdir('tests/artifacts', { recursive: true });
try {
  for (const width of [1200, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent(`<html lang="ko"><meta charset="utf-8"><style>body{margin:16px;font-family:Arial,sans-serif}${css}</style>
      <section id="oj-ai-storage-operations-detail" class="oj-ai-diagnostics oj-ai-storage-detail" data-rest-root="https://example.test/wp-json/oj-ai/v1" data-rest-nonce="test">
      <header><h1>파일 보관 상세</h1><p data-storage-role="checked-at"></p><button class="oj-ai-diagnostics__refresh" data-storage-action="refresh" aria-label="새로고침">↻</button></header>
      <p class="oj-ai-diagnostics__notice" data-storage-role="notice"></p><div data-storage-role="metrics"></div><div data-storage-role="backlog"></div><div data-storage-role="workers"></div>
      <button data-storage-action="resume">지금 정리 실행</button><p data-storage-role="resume-status"></p></section></html>`);
    await page.evaluate(() => {
      window.__resumeSucceeded = false;
      window.__completed = 0;
      window.__backlog = null;
      window.__denied = false;
      window.__health = 'unavailable';
      window.__scanner = 'unknown';
      window.__metrics = null;
      window.__workerState = 'running';
      window.fetch = async (_url, options = {}) => ({ ok: !window.__denied, status: window.__denied ? 403 : 200, json: async () => options.method === 'POST'
        ? { meta: { all_succeeded: window.__resumeSucceeded }, data: { storage_jobs: { workers: Object.fromEntries(['attachment_scan','attachment_cleanup','zip_scan','zip_cleanup','export_cleanup'].map(key => [key, { iterations: key === 'export_cleanup' ? window.__completed : 0, succeeded: key === 'export_cleanup' ? window.__resumeSucceeded : true }])) } } }
        : { data: { profile: 'oj_ai.storage_operations_detail@1.0.0', checked_at_utc: '2026-09-08 11:00:00', status: window.__health, metrics: window.__metrics, scanner_status: window.__scanner,
          backlog: window.__backlog,
          workers: Object.fromEntries(['attachment_scan','attachment_cleanup','zip_scan','zip_cleanup','export_cleanup'].map(key => [key, { status: window.__workerState, last_heartbeat_utc: window.__workerState === 'never' ? null : '2026-09-08 10:59:00', minutes_since: window.__workerState === 'never' ? null : 1 }])) } } });
    });
    await page.addScriptTag({ content: script });
    await page.waitForFunction(() => document.querySelector('[data-storage-role="metrics"]').textContent.includes('확인 불가')).catch(async error => {
      console.error(await page.locator('body').innerText(), errors);
      throw error;
    });
    assert.equal(await page.locator('[data-storage-role="metrics"]').getByText('확인 불가', { exact: true }).count(), 7);
    assert.equal(await page.getByText('최근 호출 기록 있음', { exact: true }).count(), 5);
    await page.getByRole('button', { name: '지금 정리 실행' }).click();
    await page.waitForFunction(() => document.querySelector('[data-storage-role="resume-status"]').dataset.tone === 'danger');
    assert.match(await page.locator('[data-storage-role="resume-status"]').textContent(), /미완료.*내보내기 파일 정리/);
    assert.equal(await page.locator('[data-storage-role="backlog"] .oj-ai-diagnostics__copy').getByText('확인 불가', { exact: true }).count(), 5);
    await page.evaluate(() => {
      window.__backlog = Object.fromEntries(['attachment','zip','export','worksheet','other'].map(key => [key, { expired: key === 'export' ? 29 : 2, held: 1, bound: 1 }]));
      Object.assign(window.__backlog.export, { eligible: 26, preserved: 1, in_use: 1, blocked: 1 });
    });
    await page.getByRole('button', { name: '새로고침' }).click();
    await page.waitForFunction(() => document.querySelector('[data-backlog-key="export"]').textContent.includes('정리 가능 26'));
    assert.match(await page.locator('[data-backlog-key="export"]').textContent(), /참조·저장 기록 확인 필요/);
    assert.match(await page.locator('[data-backlog-key="attachment"]').textContent(), /만료 2 · 보존 지정 1 · 연결됨 1/);
    await page.screenshot({ path: `tests/artifacts/storage-defects-${width}.png`, fullPage: true });
    const layout = await page.evaluate(() => ({
      overflow: document.documentElement.scrollWidth > innerWidth + 1,
      narrow: [...document.querySelectorAll('.oj-ai-diagnostics__copy')].some(node => node.getBoundingClientRect().width < 120),
    }));
    assert.deepEqual(layout, { overflow: false, narrow: false });
    await page.evaluate(() => { window.__resumeSucceeded = true; });
    await page.getByRole('button', { name: '지금 정리 실행' }).click();
    await page.waitForFunction(() => document.querySelector('[data-storage-role="resume-status"]').dataset.tone === 'warning');
    assert.match(await page.locator('[data-storage-role="resume-status"]').textContent(), /처리할 파일이 없습니다/);
    await page.evaluate(() => { window.__completed = 3; });
    await page.getByRole('button', { name: '지금 정리 실행' }).click();
    await page.waitForFunction(() => document.querySelector('[data-storage-role="resume-status"]').dataset.tone === 'success');
    assert.match(await page.locator('[data-storage-role="resume-status"]').textContent(), /검사 처리 0건 · 파일 정리 3건/);
    await page.evaluate(() => { window.__resumeSucceeded = false; });
    await page.getByRole('button', { name: '지금 정리 실행' }).click();
    await page.waitForFunction(() => document.querySelector('[data-storage-role="resume-status"]').dataset.tone === 'danger');
    assert.match(await page.locator('[data-storage-role="resume-status"]').textContent(), /파일 정리 3건.*미완료/);
    await page.evaluate(() => {
      window.__health = 'attention'; window.__scanner = 'unavailable';
      window.__metrics = {expected_workers:5, scheduled_workers:5, pending_scans:1, delayed_scans:1, cleanup_due:0, delayed_cleanup:0, metadata_issues:0};
    });
    await page.getByRole('button', { name: '새로고침' }).click();
    await page.getByText(/서버 관리자는 ClamAV 서비스와 소켓 연결을 확인해 주세요/).waitFor();
    assert.equal(await page.locator('[data-metric-key="pending_scans"]').innerText(), '검사 대기\n1');
    assert.equal(await page.locator('[data-metric-key="delayed_scans"] .oj-ai-diagnostics__badge').getAttribute('data-status'), 'attention');
    const readyColor = await page.locator('[data-metric-key="expected_workers"] .oj-ai-diagnostics__badge').evaluate(node => getComputedStyle(node).color);
    const delayedColor = await page.locator('[data-metric-key="delayed_scans"] .oj-ai-diagnostics__badge').evaluate(node => getComputedStyle(node).color);
    assert.notEqual(readyColor, delayedColor, 'Delayed work must not have the ready color.');
    await page.screenshot({ path: `tests/artifacts/storage-scanner-${width}.png`, fullPage: true });
    for (const workerState of ['stale', 'never']) {
      await page.evaluate(state => { window.__workerState = state; window.__metrics.scheduled_workers = 0; }, workerState);
      await page.getByRole('button', { name: '새로고침' }).click();
      await page.waitForFunction(state => document.querySelector('[data-worker-key="attachment_scan"]').dataset.status === state, workerState);
      assert.notEqual(await page.locator('[data-worker-key="attachment_scan"] .oj-ai-diagnostics__badge').evaluate(node => getComputedStyle(node).color), readyColor);
      assert.equal(await page.locator('[data-metric-key="scheduled_workers"] .oj-ai-diagnostics__badge').getAttribute('data-status'), 'unavailable');
    }
    await page.evaluate(() => { window.__health = 'ready'; window.__scanner = 'responding'; });
    await page.getByRole('button', { name: '새로고침' }).click();
    await page.waitForFunction(() => document.querySelector('[data-storage-role="notice"]').hidden);
    await page.evaluate(() => { window.__scanner = 'unexpected private socket value'; });
    await page.getByRole('button', { name: '새로고침' }).click();
    await page.getByText('파일 검사 서비스의 연결 상태를 확인하지 못했습니다.', { exact:true }).waitFor();
    assert.ok(!(await page.locator('body').innerText()).includes('unexpected private socket value'));
    await page.evaluate(() => { window.__denied = true; });
    await page.getByRole('button', { name: '새로고침' }).click();
    await page.getByText('파일 보관 상세를 볼 수 없습니다.', { exact: true }).waitFor();
    assert.equal(await page.locator('[data-backlog-key]').count(), 0, 'Denied refresh must clear previously visible backlog counts.');
    assert.equal(await page.locator('[data-metric-key]').count(), 0, 'Denied refresh must clear previous metrics.');
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log('Storage defect UI passed: failed jobs, unknown metrics, honest heartbeat, desktop/mobile text width.');
} finally { await browser.close(); }
