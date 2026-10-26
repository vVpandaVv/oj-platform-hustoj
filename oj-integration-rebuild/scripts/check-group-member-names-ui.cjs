const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const php = process.argv[3] || 'php';
const output = path.join(root, 'dist', 'verification', 'group-member-names');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  execFileSync(php, ['oj-platform/tests/Unit/group-member-names.php', '--render', output], { cwd: root });
  const html = fs.readFileSync(path.join(output, 'group-members.html'), 'utf8');
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const requests = [];
    const requestUrls = [];
    let mode = 'success';
    await page.route('http://group-names.test/**', async route => {
      if (route.request().method() !== 'POST') { await route.fulfill({ contentType: 'text/html', body: html }); return; }
      requestUrls.push(route.request().url());
      if (route.request().url() !== 'http://group-names.test/wp-admin/admin-post.php') {
        await route.fulfill({ status: 404, contentType: 'text/html', body: 'Not Found' });
        return;
      }
      const form = await new Response(route.request().postDataBuffer(), { headers: { 'Content-Type': route.request().headers()['content-type'] } }).formData();
      assert.equal(form.get('action'), 'oj_group_save_member_names');
      assert.equal(form.get('group_id'), '1');
      assert.equal(form.get('_nonce'), 'fixture');
      assert.deepEqual([...form.keys()].sort(), ['_nonce', 'action', 'group_id', 'name_changes']);
      const changes = JSON.parse(form.get('name_changes'));
      requests.push(changes);
      if (mode === 'failure') { await route.fulfill({ status: 500, json: { error: '저장 실패 테스트' } }); return; }
      if (mode === 'html-failure') { await route.fulfill({ status: 502, contentType: 'text/html', body: 'Bad Gateway' }); return; }
      await route.fulfill({ json: { results: changes.map(change => mode === 'partial' && change.user_id === 2
        ? { user_id: 2, saved: false, error: '다른 관리자가 수정했습니다.' }
        : { user_id: change.user_id, saved: true, name: change.name.trim() }) } });
    });
    const layouts = [];
    for (const width of [360, 390, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto('http://group-names.test/');
      await page.locator('th[data-ojp-sort-key="managed-name"]').waitFor();
      assert.equal(await page.locator('[data-ojp-managed-name]').count(), 2);
      const layout = await page.evaluate(() => {
        const controls = [...document.querySelectorAll('input:not([type="hidden"]), button')].filter(node => node.getClientRects().length);
        const boxes = controls.map(node => node.getBoundingClientRect());
        return {
          width: innerWidth,
          scrollWidth: document.documentElement.scrollWidth,
          overlap: boxes.some((a, i) => boxes.some((b, j) => i < j && Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1)),
        };
      });
      assert.ok(layout.scrollWidth <= width + 1, JSON.stringify(layout));
      assert.equal(layout.overlap, false, JSON.stringify(layout));
      await page.screenshot({ path: path.join(output, `members-${width}.png`), fullPage: true });
      layouts.push(layout);
    }
    const first = page.locator('[data-ojp-managed-name][data-user-id="2"]');
    const second = page.locator('[data-ojp-managed-name][data-user-id="4"]');
    const save = page.getByRole('button', { name: '관리용 이름 일괄 저장', exact: true });
    const search = page.locator('[data-ojp-table-filter]');
    const status = page.locator('[data-ojp-name-status]');
    assert.equal(await save.isDisabled(), true);
    await first.fill('수정하늘');
    await second.fill('수정민준');
    await search.fill('수정하늘');
    assert.equal(await page.locator('tbody tr:not([hidden])').count(), 1, 'Search includes current edited names.');
    assert.equal(await page.locator('[data-ojp-name-count]').textContent(), '변경 2명');
    mode = 'partial';
    await save.click();
    await page.waitForFunction(() => document.querySelector('[data-ojp-name-status]').textContent !== '저장 중...');
    assert.equal(requestUrls[0], 'http://group-names.test/wp-admin/admin-post.php', 'The hidden WordPress action field must not override the POST URL.');
    await status.getByText('1명 저장 완료, 1명 저장 실패', { exact: true }).waitFor();
    assert.equal(requests[0].length, 2, 'Filtered-out dirty rows must still be saved.');
    assert.equal(await first.inputValue(), '수정하늘', 'Failed edits stay on screen.');
    assert.equal(await first.getAttribute('aria-invalid'), 'true');
    assert.equal(await second.getAttribute('data-original-name'), '수정민준');
    mode = 'success';
    await save.click();
    await status.getByText('1명 저장 완료', { exact: true }).waitFor();
    assert.deepEqual(requests[1].map(row => row.user_id), [2], 'Retry sends only failed dirty rows.');
    assert.equal(await save.isDisabled(), true);
    await search.fill('');
    await first.fill('가나다');
    await second.fill('하늘');
    await page.locator('th[data-ojp-sort-key="managed-name"]').click();
    const sorted = await page.locator('[data-ojp-managed-name]').evaluateAll(nodes => nodes.map(node => node.value));
    assert.deepEqual(sorted, ['가나다', '하늘'], 'Sorting uses current input values.');
    mode = 'failure';
    await save.click();
    await status.getByText('저장 실패 테스트', { exact: true }).waitFor();
    assert.equal(await first.inputValue(), '가나다');
    assert.equal(await save.isDisabled(), false);
    mode = 'html-failure';
    await save.click();
    await status.getByText('서버 응답을 확인하지 못했습니다. 입력 내용은 유지됩니다. 로그인과 연결 상태를 확인해 주세요.', { exact: true }).waitFor();
    assert.equal(await first.inputValue(), '가나다', 'Invalid server responses must preserve unsaved edits.');
    assert.equal(await second.inputValue(), '하늘');
    assert.equal(await save.isDisabled(), false);
    await page.getByRole('button', { name: '변경 취소', exact: true }).click();
    assert.equal(await first.inputValue(), '수정하늘');
    assert.equal(await second.inputValue(), '수정민준');
    assert.equal(await save.isDisabled(), true);
    await first.fill('   ');
    await save.click();
    assert.equal(requests.length, 4, 'Blank names are blocked without a request.');
    assert.equal(await first.getAttribute('aria-invalid'), 'true');
    await page.getByRole('button', { name: '변경 취소', exact: true }).click();
    await first.fill('최종하늘');
    await second.fill('최종민준');
    mode = 'success';
    await save.click();
    await status.getByText('2명 저장 완료', { exact: true }).waitFor();
    assert.equal(await first.getAttribute('data-original-name'), '최종하늘');
    assert.equal(await second.getAttribute('data-original-name'), '최종민준');
    assert.equal(await save.isDisabled(), true);
    assert.ok(requestUrls.every(url => url === 'http://group-names.test/wp-admin/admin-post.php'));
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ layouts, requestUrls, scenarios: ['action-url', 'batch-save', 'partial-failure', 'retry', 'filter', 'sort', 'server-failure', 'html-failure', 'reset', 'blank-name', 'full-success'], errors }, null, 2));
    console.log(JSON.stringify({ layouts, requests: requests.length, errors }, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
