const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const plugin = path.join(root, 'oj-integration-rebuild/oj-platform');
const php = process.env.PHP_BINARY || 'D:/php/php.exe';
const fixtures = JSON.parse(execFileSync(php, [path.join(plugin, 'tests/Support/problem-quiz-navigation.php')], { encoding: 'utf8' }).split('PROBLEM_QUIZ_FIXTURES=')[1]);

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_EXECUTABLE || undefined });
  let checks = 0;
  async function load(name, width) {
    const page = await browser.newPage({ viewport: { width, height: 940 } });
    const errors = [], posts = [];
    const fixture = fixtures[name];
    let stage = 'detail';
    const query = name === 'none' ? '' : new URLSearchParams(fixture.query).toString();
    const scoped = base => base + (query ? '?' + query : '');
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.pathname === '/app.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(path.join(plugin, 'resources/ui/css/oj-ui-system.css')) });
      if (url.pathname === '/app.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(plugin, 'resources/ui/js/oj-ui.js')) });
      if (url.pathname === '/lucide.svg') return route.fulfill({ contentType: 'image/svg+xml', body: fs.readFileSync(path.join(plugin, 'resources/ui/icons/lucide.svg')) });
      if (request.method() === 'POST') {
        const payload = new URLSearchParams(request.postData());
        posts.push(payload);
        const start = payload.get('action') === 'oj_quiz_start_attempt';
        stage = start ? 'continue' : 'exhausted';
        const destination = scoped('/quizzes/1024/' + (start ? 'attempt' : 'attempts/1'));
        return route.fulfill({ contentType: 'text/html', body: '<script>location.replace(' + JSON.stringify(destination) + ')</script>' });
      }
      let body = name === 'none' ? fixture : fixture.problem;
      if (name !== 'none' && url.pathname.startsWith('/quizzes/')) {
        const key = url.pathname.endsWith('/attempt') ? 'attempt'
          : url.pathname.includes('/attempts/') ? 'result'
            : url.pathname.endsWith('/statistics') ? 'statistics' : stage;
        body = fixture[key];
      }
      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/app.css"></head><body class="ojp-app"><main class="ojp-public-main"><div class="ojp-shell">' + body + '</div></main><script src="/app.js"></script></body></html>' });
    });
    await page.goto('http://problem-quiz.test' + scoped('/problems/1024'));
    return { page, errors, posts, scoped };
  }
  async function checkLayout(page) {
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false); checks++;
    const buttons = page.locator('.ojp-problem-detail-actions .ojp-button');
    for (const button of await buttons.all()) {
      const box = await button.boundingBox();
      assert.ok(box && box.x >= 0 && box.x + box.width <= (await page.viewportSize()).width); checks++;
      assert.ok(box.height <= 52, 'Header button text must not collapse into a vertical column'); checks++;
    }
  }
  async function clickTo(page, locator, url) {
    await Promise.all([page.waitForURL('http://problem-quiz.test' + url), locator.click()]);
  }
  try {
    for (const width of [1440, 390]) {
      for (const name of ['general', 'class', 'contest']) {
        const { page, errors, posts, scoped } = await load(name, width);
        await checkLayout(page);
        assert.equal(await page.locator('.ojp-problem-quiz').count(), 1); checks++;
        assert.equal(await page.getByRole('link', { name: '퀴즈 풀기', exact: true }).count(), 2); checks++;
        const top = page.locator('.ojp-problem-detail-actions').getByRole('link', { name: '퀴즈 풀기' });
        const box = await top.boundingBox();
        assert.ok(box && box.y < 940); checks++;
        assert.equal(await top.getAttribute('href'), scoped('/quizzes/1024')); checks++;
        if (process.env.OJ_QUIZ_SCREENSHOTS) await page.screenshot({ path: path.join(process.env.OJ_QUIZ_SCREENSHOTS, `problem-quiz-${name}-${width}.png`), fullPage: true });
        await clickTo(page, top, scoped('/quizzes/1024'));
        await checkLayout(page);
        assert.equal(await page.getByRole('link', { name: '문제로 돌아가기' }).getAttribute('href'), scoped('/problems/1024')); checks++;
        await clickTo(page, page.getByRole('button', { name: '응시 시작', exact: true }), scoped('/quizzes/1024/attempt'));
        assert.equal(posts.length, 1); checks++;
        assert.equal(posts[0].get('action'), 'oj_quiz_start_attempt'); checks++;
        for (const [key, value] of Object.entries(fixtures[name].query)) { assert.equal(posts[0].get(key), value); checks++; }
        await checkLayout(page);
        await clickTo(page, page.getByRole('link', { name: '나중에 계속' }), scoped('/quizzes/1024'));
        assert.equal(await page.getByRole('button', { name: '응시 시작' }).count(), 0); checks++;
        await clickTo(page, page.getByRole('link', { name: '응시 계속하기' }), scoped('/quizzes/1024/attempt'));
        await page.getByRole('radio').first().check();
        await clickTo(page, page.getByRole('button', { name: '최종 제출', exact: true }), scoped('/quizzes/1024/attempts/1'));
        assert.equal(posts.length, 2); checks++;
        assert.equal(posts[1].get('action'), 'oj_quiz_submit_attempt'); checks++;
        assert.equal(posts[1].get('problem_id'), '1024'); checks++;
        assert.equal(posts[1].get('attempt_id'), '1'); checks++;
        assert.equal([...posts[1].keys()].filter(key => key.startsWith('answers[')).length, 1); checks++;
        for (const [key, value] of Object.entries(fixtures[name].query)) { assert.equal(posts[1].get(key), value); checks++; }
        await checkLayout(page);
        await clickTo(page, page.getByRole('link', { name: '문제로 돌아가기' }), scoped('/problems/1024'));
        await clickTo(page, page.locator('.ojp-problem-quiz').getByRole('link', { name: '퀴즈 풀기' }), scoped('/quizzes/1024'));
        assert.ok(await page.getByText('응시 횟수 사용 완료', { exact: true }).isVisible()); checks++;
        await clickTo(page, page.getByRole('link', { name: '익명 통계 보기' }), scoped('/quizzes/1024/statistics'));
        await checkLayout(page);
        assert.equal(await page.getByRole('link', { name: '문제로 돌아가기' }).getAttribute('href'), scoped('/problems/1024')); checks++;
        assert.deepEqual(errors, []); checks++;
        await page.close();
      }
      const { page } = await load('none', width);
      assert.equal(await page.getByRole('link', { name: '퀴즈 풀기' }).count(), 0); checks++;
      assert.equal(await page.locator('.ojp-problem-quiz').count(), 0); checks++;
      await page.close();
    }
    console.log(`Problem quiz browser: ${checks} checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
