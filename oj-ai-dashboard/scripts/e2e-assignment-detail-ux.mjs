import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = process.cwd();
const markup = execFileSync(process.env.OJAI_PHP || 'D:/php/php.exe', ['tests/fixtures/render-workflow-page.php', 'assignment'], { encoding: 'utf8' });
const fixture = await readFile('tests/fixtures/assignment-lifecycle-visual.html', 'utf8');
const mock = [...fixture.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]).join('\n');
const html = `<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/resources/css/assignment-workbench.css"><link rel="stylesheet" href="/resources/css/worksheet-workflow.css"><style>body{margin:0;background:white;font-family:Arial,sans-serif}*{box-sizing:border-box}</style></head><body>${markup}<script>${mock}</script><script src="/resources/js/worksheet-workflow.js"></script><script src="/resources/js/learner-labels.js"></script><script src="/resources/js/assignment-workbench.js"></script></body></html>`;
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
await mkdir('tests/artifacts', { recursive: true });
try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const context = await browser.newContext({ viewport, locale: 'ko-KR', timezoneId: 'Asia/Seoul' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => { Date.now = () => Date.parse('2026-08-22T09:30:00Z'); });
    await page.route('http://ojai.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: html });
      const local = path.resolve(root, '.' + url.pathname);
      if (!local.startsWith(root + path.sep) || !url.pathname.startsWith('/resources/')) return route.fulfill({ status: 404, body: '' });
      const contentType = url.pathname.endsWith('.js') ? 'application/javascript' : url.pathname.endsWith('.svg') ? 'image/svg+xml' : 'text/css';
      await route.fulfill({ contentType, body: await readFile(local) });
    });
    await page.goto('http://ojai.test/');
    await page.waitForFunction(() => document.querySelector('[data-field="context-filter"]')?.options.length === 2);
    await page.selectOption('[data-field="context-filter"]', '0');
    const overview = page.locator('[data-role="assignment-overview"]');
    const detail = page.locator('[data-role="assignment-detail"]');
    await page.getByRole('button', { name: '분수 비교 활동지', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('[data-role="assignment-overview"]').hidden);
    assert.equal(await overview.isHidden(), true);
    assert.equal(await detail.isVisible(), true);
    assert.equal(await overview.locator('[data-action="assignment-command"]').count(), 0, 'List must not repeat lifecycle commands.');
    assert.equal(await detail.getByRole('button', { name: '제출 현황', exact: true }).count(), 1);
    assert.equal(await detail.getByRole('link', { name: '평가', exact: true }).count(), 1);
    assert.equal(await page.locator('[data-action="evaluate-assignment-submissions"]').count(), 0, 'Opening AI grading must not enqueue a batch.');
    assert.equal(await page.locator('[data-role="schedule-actions"] [data-command="update_schedule"]').count(), 1);
    assert.equal(await page.locator('[data-role="state-actions"] [data-command="open"]').count(), 1);
    assert.equal(await page.locator('[data-role="state-actions"] [data-command="withdraw"]').count(), 1);
    assert.equal(await page.locator('[data-detail="missing"]').textContent(), '21명');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await page.screenshot({ path: `tests/artifacts/assignment-detail-${name}.png`, fullPage: true });

    const schedule = page.locator('[data-role="schedule-actions"] button');
    const dialog = page.locator('[data-role="lifecycle-dialog"]');
    await schedule.click();
    assert.equal(await dialog.evaluate(element => element.matches(':modal')), true);
    assert.ok((await dialog.textContent()).includes('분수 비교 활동지'));
    for (let i = 0; i < 10; i++) {
      await page.keyboard.press('Tab');
      assert.equal(await dialog.evaluate(element => element.contains(document.activeElement) || document.activeElement === document.body), true, 'Tab cannot activate a background control.');
    }
    assert.equal(await dialog.evaluate(element => { const r = element.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 1 && r.top >= 0 && r.bottom <= innerHeight + 1; }), true);
    await page.screenshot({ path: `tests/artifacts/assignment-schedule-${name}.png`, fullPage: true });
    await page.keyboard.press('Escape');
    assert.equal(await dialog.isHidden(), true);
    assert.equal(await schedule.evaluate(element => element === document.activeElement), true);
    await schedule.click();
    await page.locator('[data-field="lifecycle-due"]').fill('2026-08-22T20:00');
    await page.locator('[data-field="lifecycle-closes"]').fill('2026-08-22T21:00');
    await page.evaluate(() => {
      const original = window.fetch;
      window.fetch = async (url, options = {}) => {
        const response = await original(url, options);
        if (options.method === 'POST') await new Promise(resolve => { window.__releaseWrite = resolve; });
        return response;
      };
    });
    await page.getByRole('button', { name: '변경 저장', exact: true }).click();
    await page.waitForFunction(() => window.__lifecycleRequests.length === 1);
    await page.keyboard.press('Escape');
    assert.equal(await dialog.isVisible(), true, 'An in-flight change cannot be dismissed.');
    assert.equal(await page.getByRole('button', { name: '변경 저장', exact: true }).isDisabled(), true);
    await page.evaluate(() => window.__releaseWrite());
    await page.waitForFunction(() => document.querySelector('[data-role="lifecycle-dialog"]').hidden && document.querySelector('[data-detail="due"]').textContent.includes('8:00'));
    assert.equal(await page.evaluate(() => window.__lifecycleRequests[0].body.expected.expected_lock_version), 1, 'Original CAS must be preserved.');

    await detail.getByRole('button', { name: '배포 열기', exact: true }).click();
    const confirmation = page.getByRole('dialog', { name: '배포를 여시겠습니까?', exact: true });
    await confirmation.getByRole('button', { name: '취소', exact: true }).click();
    assert.equal(await page.evaluate(() => window.__lifecycleRequests.length), 1);
    await detail.getByRole('button', { name: '배포 열기', exact: true }).click();
    await confirmation.getByRole('button', { name: '배포 열기', exact: true }).click();
    await page.waitForFunction(() => window.__lifecycleRequests.length === 2);
    await page.evaluate(() => window.__releaseWrite());
    await page.waitForFunction(() => document.querySelector('[data-detail="state"]').textContent === '진행');
    assert.equal(await page.locator('[data-action="add-targets"]').isEnabled(), true);

    await detail.getByRole('button', { name: '제출 현황', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-role="submission-rows"] tr').length === 3);
    assert.equal(await overview.isHidden(), true);
    assert.equal(await detail.isHidden(), true);
    assert.equal(await page.locator('[data-role="submission-notice"]').textContent(), '전체 3명 · 제출 1명 · 미제출 2명');
    const target = new URL(await page.locator('[data-role="submission-panel"] a[data-action="open-assessments"]').getAttribute('href'));
    assert.equal(target.searchParams.get('context_id'), 'class-21');
    assert.ok(target.searchParams.get('assignment_id'));
    await page.selectOption('[data-learner-label-mode]', 'login');
    assert.equal(await page.locator('[data-role="submission-rows"] tr').first().locator('td').first().textContent(), 'student10101');
    await page.locator('[data-action="view-submission"]').first().click();
    await page.waitForFunction(() => !document.querySelector('[data-role="submission-detail"]').hidden);
    await page.getByRole('button', { name: '추가 제출 1회 허용', exact: true }).click();
    const control = page.locator('[data-role="submission-control-dialog"]');
    assert.equal(await control.evaluate(element => element.matches(':modal')), true);
    await page.keyboard.press('Escape');
    assert.equal(await control.isHidden(), true);
    await page.getByRole('button', { name: '제출 목록', exact: true }).click();
    await page.getByRole('button', { name: '배포 상세', exact: true }).click();
    assert.equal(await detail.isVisible(), true);

    await detail.getByRole('button', { name: '배포 종료', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: '배포 종료', exact: true }).click();
    await page.waitForFunction(() => window.__lifecycleRequests.length === 3);
    await page.evaluate(() => window.__releaseWrite());
    await page.waitForFunction(() => document.querySelector('[data-detail="state"]').textContent === '종료');
    assert.equal(await page.locator('[data-action="add-targets"]').isDisabled(), true);
    assert.equal(await detail.getByRole('button', { name: '배포 재개', exact: true }).count(), 1);
    await detail.getByRole('button', { name: '배포 철회', exact: true }).click();
    const withdraw = page.getByRole('dialog', { name: '배포를 철회하시겠습니까?', exact: true });
    assert.ok((await withdraw.textContent()).includes('기존 제출 기록은 삭제되지 않습니다.'));
    await withdraw.getByRole('button', { name: '취소', exact: true }).click();
    await page.getByRole('button', { name: '배포 목록', exact: true }).click();
    assert.equal(await overview.isVisible(), true);
    assert.equal(await detail.isHidden(), true);
    assert.equal(await page.getByRole('button', { name: '분수 비교 활동지', exact: true }).evaluate(element => document.activeElement === element), true);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS ${name}: real PHP markup, focused navigation, workflow hierarchy, modal focus/busy/cancel, schedule CAS, submission labels, state actions, screenshots`);
  }
} finally { await browser.close(); }
