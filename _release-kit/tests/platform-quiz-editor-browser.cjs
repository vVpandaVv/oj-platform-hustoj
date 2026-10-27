const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const plugin = path.join(root, 'oj-integration-rebuild/oj-platform');
const fixtureFile = path.join(plugin, 'tests/Support/render-quiz-editor.php');
const php = process.env.PHP_BINARY || 'D:/php/php.exe';
const fixtures = JSON.parse(execFileSync(php, [fixtureFile], { encoding: 'utf8' }).split('QUIZ_EDITOR_FIXTURES=')[1]);

(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  let checks = 0;
  async function load(name, width) {
    const page = await browser.newPage({ viewport: { width, height: 950 } });
    page.setDefaultTimeout(10000);
    const posts = [];
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.pathname === '/quiz-action' && request.method() === 'POST') {
        posts.push(new URLSearchParams(request.postData()));
        return route.fulfill({ contentType: 'text/html', body: '<p>접수 완료</p>' });
      }
      if (url.pathname === '/app.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(plugin, 'resources/ui/js/oj-ui.js')) });
      if (url.pathname === '/app.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(path.join(plugin, 'resources/ui/css/oj-ui-system.css')) });
      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/app.css"><body class="ojp-app"><main class="ojp-shell">' + fixtures[name] + '</main><script src="/app.js"></script></body></html>' });
    });
    await page.goto('http://quiz-editor.test/fixture');
    const editor = page.locator('[data-oj-quiz-editor]');
    assert.equal(await editor.locator('[data-oj-quiz-question-field="title"]').first().getAttribute('name'), 'questions[0][title]'); checks++;
    return { page, editor, posts, errors };
  }
  async function fillQuestion(question, type) {
    await question.locator('[data-oj-quiz-question-type]').selectOption(type);
    assert.equal(await question.locator('[data-oj-quiz-type-label]').innerText(), { single_choice: '단일 선택', multi_choice: '다중 답', short_answer: '단답형' }[type]); checks++;
    assert.equal(await question.locator('[data-oj-quiz-choices]').isVisible(), type !== 'short_answer'); checks++;
    assert.equal(await question.locator('[data-oj-quiz-accepted-answers-section]').isVisible(), type === 'short_answer'); checks++;
    await question.locator('[data-oj-quiz-question-field="title"]').fill('개념 확인');
    await question.locator('[data-oj-quiz-question-field="body"]').fill('계산 결과는 무엇인가요?');
    if (type === 'short_answer') {
      await question.locator('[data-oj-quiz-question-field="accepted_answers_text"]').fill('4\n사');
      assert.equal(await question.locator('[data-oj-quiz-choices] input:enabled').count(), 0); checks++;
    } else {
      const bodies = question.locator('[data-oj-quiz-choice-field="body"]');
      await bodies.nth(0).fill('4'); await bodies.nth(1).fill('5');
      assert.equal(await question.locator('[data-oj-quiz-question-field="accepted_answers_text"]').isDisabled(), true); checks++;
      if (type === 'multi_choice') await question.locator('[data-oj-quiz-correct]').nth(1).check();
    }
  }
  async function send(context, button, action) {
    const { page, posts, errors } = context;
    assert.equal(await button.evaluate(node => Boolean(node.form)), true); checks++;
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false); checks++;
    await Promise.all([page.waitForURL('**/quiz-action'), button.click()]);
    assert.equal(posts.length, 1); checks++;
    const payload = posts[0];
    assert.deepEqual(payload.getAll('action'), [action]); checks++;
    assert.deepEqual(payload.getAll('_oj_nonce'), ['nonce-' + action]); checks++;
    assert.deepEqual(errors, []); checks++;
    return payload;
  }
  function validate(payload, types, bank = false) {
    const result = JSON.parse(execFileSync(php, [fixtureFile, bank ? '--validate-bank-post' : '--validate-post'], { encoding: 'utf8', input: payload.toString() }));
    assert.deepEqual(bank ? [result.type] : result.types, types); checks++;
  }
  try {
    for (const width of [1440, 390]) {
      for (const name of ['new_empty_bank', 'new_with_bank']) {
        for (const type of ['single_choice', 'multi_choice', 'short_answer']) {
          const context = await load(name, width);
          const { page, editor } = context;
          await editor.locator('input[name="title"]').fill('새 퀴즈');
          await fillQuestion(editor.locator('[data-oj-quiz-question]').first(), type);
          assert.equal(await editor.evaluate(form => form.checkValidity()), true); checks++;
          if (process.env.OJ_QUIZ_SCREENSHOTS && name === 'new_with_bank' && type === 'short_answer') {
            await page.screenshot({ path: path.join(process.env.OJ_QUIZ_SCREENSHOTS, `quiz-editor-${width}.png`), fullPage: true });
          }
          const payload = await send(context, editor.locator('button[value="save_draft"]'), 'oj_quiz_create');
          assert.equal(payload.get('intent'), 'save_draft'); checks++;
          assert.equal(payload.has('bank_id'), false); checks++;
          if (type === 'short_answer') { assert.equal([...payload.keys()].some(key => key.includes('[choices]')), false); checks++; }
          validate(payload, [type]);
          await page.close();
        }
      }
      for (const intent of ['save_draft', 'publish']) {
        const context = await load('edit', width);
        const question = context.editor.locator('[data-oj-quiz-question]').first();
        const values = await question.locator('[data-oj-quiz-choice-field="body"]').evaluateAll(nodes => nodes.map(node => node.value));
        await question.locator('[data-oj-quiz-question-type]').selectOption('short_answer');
        assert.equal(await question.locator('[data-oj-quiz-choices]').isVisible(), false); checks++;
        await question.locator('[data-oj-quiz-question-type]').selectOption('single_choice');
        assert.equal(await question.locator('[data-oj-quiz-choices]').isVisible(), true); checks++;
        assert.deepEqual(await question.locator('[data-oj-quiz-choice-field="body"]').evaluateAll(nodes => nodes.map(node => node.value)), values); checks++;
        const payload = await send(context, context.editor.locator(`button[value="${intent}"]`), 'oj_quiz_save');
        assert.equal(payload.get('intent'), intent); checks++;
        assert.equal(payload.has('bank_id'), false); checks++;
        validate(payload, ['single_choice']);
        await context.page.close();
      }
      for (const operation of ['import', 'export']) {
        const context = await load('edit', width);
        await context.editor.locator('input[name="title"]').fill('');
        if (operation === 'import') await context.page.locator('select[name="bank_id"]').selectOption('5');
        const action = operation === 'import' ? 'oj_quiz_import_bank_question' : 'oj_quiz_export_to_bank';
        const button = context.page.locator(operation === 'import' ? 'button[form="ojp-quiz-import-form"]' : '[data-oj-quiz-export-button]');
        const payload = await send(context, button, action);
        assert.equal(payload.has('title'), false); checks++;
        assert.equal([...payload.keys()].some(key => key.startsWith('questions[')), false); checks++;
        assert.equal(operation === 'import' ? payload.get('bank_id') : Boolean(payload.get('question_key')), operation === 'import' ? '5' : true); checks++;
        await context.page.close();
      }
      const context = await load('new_with_bank', width);
      await context.editor.locator('input[name="title"]').fill('혼합 문항');
      await fillQuestion(context.editor.locator('[data-oj-quiz-question]').first(), 'short_answer');
      await context.editor.locator('[data-oj-quiz-add-question]').click();
      const questions = context.editor.locator('[data-oj-quiz-question]');
      assert.equal(await questions.count(), 2); checks++;
      await fillQuestion(questions.nth(1), 'single_choice');
      const mixed = await send(context, context.editor.locator('button[value="save_draft"]'), 'oj_quiz_create');
      validate(mixed, ['short_answer', 'single_choice']);
      await context.page.close();
      const bank = await load('bank', width);
      await fillQuestion(bank.editor.locator('[data-oj-quiz-question]').first(), 'short_answer');
      const bankPayload = await send(bank, bank.editor.getByRole('button', { name: '저장', exact: true }), 'oj_quiz_bank_save');
      validate(bankPayload, ['short_answer'], true);
      await bank.page.close();
    }
    process.stdout.write(`Quiz editor browser: ${checks} checks passed.\n`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
