const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const { fixture } = await import('./browser-server.mjs');
  const output = path.resolve(__dirname, '../../_notes/tmp/oj-microbit-p1'); fs.mkdirSync(output, { recursive: true });
  let fieldFailure = true, publishConflict = true, busyOnce = true, unavailableOnce = false;
  const classes = Array.from({ length: 25 }, (_, index) => ({ id: index + 1, contextId: 'class:' + (index + 1), title: '로봇 ' + (index + 1) + '반', revision: 'fixture-' + index, lifecycleState: 'active' }));
  const requestId = '0123456789abcdef0123456789abcdef';
  const error = (status, code, message, extra) => ({ status, body: { code: 'oj_microbit_' + code, message, data: { status, requestId, ...extra } } });
  const state = fixture('sequence', { classes, respond: ({ path, method }) => {
    if (path === 'teacher/exercises/review' && fieldFailure) { fieldFailure = false; return error(400, 'invalid_input', '유지 시간을 확인해 주세요.', { fieldPath: 'exercise.scenarios.0.outputSequences.0.steps.1.holdMs' }); }
    if (path === 'teacher/exercises' && method === 'POST' && publishConflict) { publishConflict = false; return error(409, 'conflict', '저장 버전을 다시 확인해 주세요.'); }
    if (path.endsWith('/run') && busyOnce) { busyOnce = false; return error(429, 'busy', '실행 서비스가 사용 중입니다.', { retryAfterSeconds: 2 }); }
    if (path.endsWith('/run') && unavailableOnce) { unavailableOnce = false; return error(503, 'connection_failed', '실행 서비스에 연결하지 못했습니다.', { retryAfterSeconds: 1 }); }
    return null;
  } });
  state.exercise().scenarios.push({ ...JSON.parse(JSON.stringify(state.exercise().scenarios[0])), id: 'private-case', name: '숨김 전용 조건 913', visible: false });
  await new Promise(resolve => state.server.listen(0, '127.0.0.1', resolve)); const origin = 'http://127.0.0.1:' + state.server.address().port;
  const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined }), page = await browser.newPage({ viewport: { width: 1440, height: 1100 } }), errors = [];
  page.on('pageerror', exception => errors.push(exception.message));
  const settle = () => page.waitForFunction(() => !document.querySelector('[data-oj-microbit]').ojMicrobit.busy);
  const review = () => page.locator('[data-mb-publication-review]');
  const prepare = async mode => { await review().locator('[data-mb-field="publicationReview.starterMode"]').selectOption(mode); await review().getByRole('button', { name: '공개 내용 점검하기', exact: true }).click(); await settle(); };
  const approve = async () => { await review().locator('input[type=checkbox]').check(); await review().getByRole('button', { name: '확인하고 공개', exact: true }).click(); await settle(); };
  try {
    await page.goto(origin + '/?mode=teacher'); await page.getByRole('button', { name: '과제 편집', exact: true }).click(); await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    await page.locator('[data-oj-microbit]').evaluate(node => { window.originalAuthorFrame = node.ojMicrobit.driver.frame; });
    await page.locator('[data-mb-field="audience.mode"]').selectOption('classes'); await page.getByLabel('로봇 7반', { exact: true }).check();
    await page.getByRole('button', { name: '다음 학급 페이지', exact: true }).click(); await page.getByLabel('로봇 21반', { exact: true }).check();
    await page.getByRole('button', { name: '이전 학급 페이지', exact: true }).click(); assert.equal(await page.getByLabel('로봇 7반', { exact: true }).isChecked(), true);
    await page.getByRole('button', { name: '과제 저장', exact: true }).click(); await review().waitFor();
    await review().getByRole('button', { name: '공개 내용 점검하기', exact: true }).click(); await settle(); assert.equal(state.requests.filter(item => item.path === 'teacher/exercises/review').length, 0);
    await page.waitForFunction(() => document.activeElement?.getAttribute('data-mb-field') === 'publicationReview.starterMode');
    await prepare('blank'); await page.waitForFunction(() => document.activeElement?.getAttribute('data-mb-field') === 'scenarios.0.outputSequences.0.steps.1.holdMs');
    assert.equal(await page.locator('[aria-invalid=true]').count(), 1); await page.locator('[data-mb-step]').first().screenshot({ path: path.join(output, 'field-error.png') });
    await review().getByRole('button', { name: '공개 내용 점검하기', exact: true }).click(); await review().getByRole('button', { name: '확인하고 공개', exact: true }).waitFor();
    const blank = await page.locator('[data-oj-microbit]').evaluate(node => { const app = node.ojMicrobit; return { author: app.project.files['main.ts'], preview: app.publication.response.studentPreview.starterProject.files['main.ts'], hiddenCount: app.publication.response.studentPreview.scenarios.filter(item => !item.visible).length }; });
    assert(blank.author.includes('basic.pause(200)')); assert.equal(blank.preview, ''); assert.equal(blank.hiddenCount, 0);
    const referenceDownload = page.waitForEvent('download'); await review().getByRole('button', { name: '교사용 현재 프로젝트 내보내기', exact: true }).click(); const reference = await referenceDownload;
    const referencePath = path.join(output, 'teacher-reference.ojmicrobit.json'); await reference.saveAs(referencePath); assert(JSON.parse(fs.readFileSync(referencePath, 'utf8')).files['main.ts'].includes('basic.pause(200)'));
    await review().screenshot({ path: path.join(output, 'publication-review.png') });
    await page.locator('[data-mb-student-preview] summary').click(); assert.equal(await page.locator('[data-mb-student-preview]').getByText('숨김 전용 조건 913').count(), 0);
    await page.getByRole('button', { name: '학생 시작 프로젝트를 MakeCode로 보기', exact: true }).click(); await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.reviewDriver?.loaded, null, { timeout: 150000 });
    assert.equal((await page.locator('[data-oj-microbit]').evaluate(node => node.ojMicrobit.reviewDriver.project.files['main.ts'])).trim(), '');
    await review().getByRole('button', { name: '작성 계속하기', exact: true }).click(); assert.equal(await page.locator('[data-oj-microbit]').evaluate(node => node.ojMicrobit.driver.frame === window.originalAuthorFrame), true);
    await page.getByRole('button', { name: '과제 저장', exact: true }).click(); await prepare('current');
    const title = page.locator('[data-mb-field=title]'), originalTitle = await title.inputValue(); await title.fill(originalTitle + ' 변경'); await approve(); await page.getByText('점검 후 작성 내용이 바뀌었습니다. 공개 내용 점검을 다시 진행하세요.', { exact: true }).waitFor(); assert.equal(state.requests.filter(item => item.path === 'teacher/exercises' && item.method === 'POST').length, 0);
    await title.fill(originalTitle); await review().getByRole('button', { name: '공개 내용 점검하기', exact: true }).click(); await review().getByRole('button', { name: '확인하고 공개', exact: true }).waitFor(); await approve();
    await page.getByText('저장 버전을 다시 확인해 주세요. · 문의 번호 ' + requestId, { exact: true }).waitFor(); assert.equal(await page.locator('[data-oj-microbit]').evaluate(node => node.ojMicrobit.driver.frame === window.originalAuthorFrame), true);
    await review().getByRole('button', { name: '확인하고 공개', exact: true }).click(); await page.getByText('점검한 내용으로 과제를 공개했습니다.', { exact: true }).waitFor({ timeout: 60000 }); assert.deepEqual(state.exercise().audience, { mode: 'classes', classIds: [7, 21] });
    await page.goto(origin); await page.getByRole('button', { name: '실습 시작', exact: true }).click(); await page.waitForFunction(() => document.querySelector('[data-oj-microbit]').ojMicrobit.driver?.loaded, null, { timeout: 150000 });
    await page.getByRole('button', { name: '자동 채점', exact: true }).click(); await settle(); assert.equal(await page.getByRole('button', { name: '자동 채점', exact: true }).isDisabled(), true); assert.equal(await page.getByRole('button', { name: '센서 값으로 실행', exact: true }).isDisabled(), true);
    const runsBefore = state.requests.filter(item => item.path.endsWith('/run')).length; await page.waitForTimeout(2100); assert.equal(state.requests.filter(item => item.path.endsWith('/run')).length, runsBefore);
    await page.getByRole('button', { name: '자동 채점', exact: true }).click(); await page.getByText('모든 테스트를 통과했습니다.', { exact: true }).waitFor({ timeout: 60000 }); assert.equal(state.attempts.at(-1).result.score, 100); assert.equal(await page.getByText('숨김 전용 조건 913', { exact: true }).count(), 0);
    unavailableOnce = true; await page.getByRole('button', { name: '자동 채점', exact: true }).click(); await settle(); assert.equal(await page.getByText('100점', { exact: true }).count(), 1); await page.getByText('실행 서비스에 연결하지 못했습니다. · 1초 뒤 다시 실행하세요. · 문의 번호 ' + requestId, { exact: true }).waitFor();
    await page.screenshot({ path: path.join(output, 'retry-preserves-result.png'), fullPage: true }); assert.equal(errors.length, 0);
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ explicitPublicationReview: true, blankAuthorSourcePreserved: true, teacherReferenceExport: true, independentActualMakeCodePreview: true, hiddenPreviewAndResultRemoved: true, classSelectionAcrossPages: [7, 21], fieldPathFocus: true, changedSnapshotBlocked: true, casConflictPreservedDraft: true, actualMakeCodeSaveAndPxtScore: 100, phpPublicationSchemaAndResultVisibility: true, busyRetryNoAutoRun: true, previousResultPreservedOn503: true, frontendErrors: errors, environment: 'memory REST fixture with actual PHP contracts and remote MakeCode; not production WordPress/MySQL authorization' }, null, 2) + '\n', 'utf8');
    process.stdout.write('[p1] review/class/field focus/error UX + actual MakeCode/PHP/PXT 100 passed\n');
  } catch (exception) { await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {}); process.stdout.write((await page.locator('body').innerText()).slice(-4000) + '\n'); throw exception; }
  finally { await browser.close(); await new Promise(resolve => { state.server.close(resolve); state.server.closeAllConnections(); }); }
})().catch(exception => { console.error(exception.stack); process.exitCode = 1; });
