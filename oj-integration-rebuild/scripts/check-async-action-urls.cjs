const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const playwright = require(process.argv[2] || 'playwright');
const script = fs.readFileSync(path.join(root, 'oj-platform/resources/ui/js/oj-ui.js'), 'utf8');
const output = path.join(root, 'dist/verification/async-action-urls');

(async () => {
  const browser = await playwright.chromium.launch({ headless: true, channel: process.env.OJ_TEST_BROWSER || 'msedge' });
  const results = [];
  try {
    for (const action of ['/wp-admin/admin-post.php', 'http://actions.test/wordpress/wp-admin/admin-post.php']) {
      for (const operation of ['start', 'poll']) {
        const page = await browser.newPage();
        const errors = [];
        const requests = [];
        page.on('pageerror', error => errors.push(error.message));
        const marker = operation === 'start' ? 'data-ojp-ai-job-form' : 'data-ojp-job-poll data-job-status="running"';
        const html = `<form method="post" action="${action}" ${marker}>
          <input type="hidden" name="action" value="oj_fixture_${operation}">
          <input type="hidden" name="_oj_nonce" value="fixture">
          <button type="submit">Start</button><span data-ojp-ai-job-status></span>
          </form><script>${script}</script>`;
        await page.route('http://actions.test/**', async route => {
          if (route.request().method() !== 'POST') {
            await route.fulfill({ contentType: 'text/html', body: route.request().url().endsWith('/complete') ? 'Complete' : html });
            return;
          }
          const request = route.request();
          const form = await new Response(request.postDataBuffer(), { headers: { 'Content-Type': request.headers()['content-type'] } }).formData();
          requests.push({ url: request.url(), action: form.get('action'), nonce: form.get('_oj_nonce') });
          if (request.url() !== new URL(action, 'http://actions.test').href) {
            await route.fulfill({ status: 404, contentType: 'text/html', body: 'Not Found' });
            return;
          }
          await route.fulfill({ json: operation === 'start'
            ? { ok: true, message: 'Queued', status_url: '/complete' }
            : { ok: true, status: 'running', terminal: false } });
        });
        await page.goto('http://actions.test/wordpress/oj-console/test');
        assert.equal(await page.locator('form').evaluate(form => form.action instanceof HTMLInputElement), true,
          'The fixture must reproduce WordPress action-property shadowing.');
        const response = page.waitForResponse(reply => reply.request().method() === 'POST');
        if (operation === 'start') await page.getByRole('button', { name: 'Start', exact: true }).click();
        await response;
        assert.deepEqual(requests[0], { url: new URL(action, 'http://actions.test').href, action: `oj_fixture_${operation}`, nonce: 'fixture' });
        if (operation === 'start') {
          await page.waitForURL('http://actions.test/complete');
        } else {
          await page.waitForResponse(reply => reply.request().method() === 'POST');
          assert.equal(requests.length, 2, 'Polling must continue after a valid response.');
          assert.deepEqual(requests[1], requests[0]);
        }
        assert.deepEqual(errors, []);
        results.push({ operation, action, requests, errors });
        await page.close();
      }
    }
  } finally { await browser.close(); }
  fs.mkdirSync(output, { recursive: true });
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; });
