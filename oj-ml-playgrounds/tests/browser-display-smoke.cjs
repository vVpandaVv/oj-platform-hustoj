'use strict';

// Real local host CSS, parent UI, and display controller in temporary Chrome/Edge
// profiles. REST, authentication transitions, and the learning iframe are fixtures;
// no live server, student data, or upstream training engine is accessed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {
  chromium, assets, makePage, check, noOverflow, mobileBounds,
  report, shots, origin,
} = require('./browser-workflow-smoke.cjs');
const plugin = path.resolve(__dirname, '..');
const hostCss = fs.readFileSync(path.resolve(plugin, '../oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css'), 'utf8');
const displaySource = fs.readFileSync(path.join(plugin, 'resources/js/display-mode.js'), 'utf8');
const displayCss = fs.readFileSync(path.join(plugin, 'resources/css/display-mode.css'), 'utf8');
const addonSource = assets['/addon.js'][1];
const dimensions = [];
const nativeFullscreen = [];

function installHostFixture(css, pluginCss) {
  const root = document.querySelector('.oj-ml-app'), previous = root.closest('main');
  document.body.classList.add('ojp-app');
  const hostStyle = document.createElement('style');
  hostStyle.textContent = css + '\n.ojp-console-main{max-width:none}.ojp-console-header__inner{margin:0 auto}.ojp-console-shell>main{padding:0}';
  document.head.append(hostStyle);
  const pluginStyle = document.createElement('style');
  pluginStyle.textContent = pluginCss;
  document.head.append(pluginStyle);
  const host = document.createElement('div');
  host.className = 'ojp-console-shell';
  host.innerHTML = '<header class="ojp-console-header"><div class="ojp-console-header__inner">OJ 합성 호스트 화면</div></header><div class="ojp-console-layout"><aside class="ojp-console-sidebar"><p>호스트 메뉴</p></aside><main class="ojp-console-main" id="ojp-main"><div class="ojp-shell"></div></main></div>';
  previous.before(host); host.querySelector('.ojp-shell').append(root); previous.remove();
  root.dataset.playgroundUrl = location.origin + '/synthetic-playground';
  root.dataset.labFocusOnStart = '1';
  if (root.dataset.ojMlPage === 'lab') {
    const notice = document.createElement('aside');
    notice.className = 'oj-ml-shared-device'; notice.setAttribute('data-oj-ml-shared-device', ''); notice.setAttribute('role', 'note');
    notice.innerHTML = '<div><strong>공용 기기 보호가 적용됩니다.</strong><p>합성 보호 안내입니다. 실제 계정 저장소에 접근하지 않습니다.</p></div><div class="oj-ml-actions"><button type="button" data-oj-ml-wipe-local>이 기기의 내 실습 데이터 삭제</button></div>';
    root.append(notice);
  }
}
assets['/addon.js'][1] = displaySource + '\n(' + installHostFixture.toString() + ')(' + JSON.stringify(hostCss) + ',' + JSON.stringify(assets['/addon.css'][1] + '\n' + displayCss) + ');\n' + addonSource;

async function fixture(browser, name, viewport, pageType, options = {}) {
  const env = await makePage(browser, name, viewport, pageType, { api: async req => {
    if (options.api) { const override = await options.api(req); if (override !== undefined) return override; }
    if (req.path === 'assignments/assignment-1/attempts') return { data: { attempt: { id: 'display-attempt-1', attemptNo: 1, status: 'created' } } };
  } });
  await env.page.route(origin + '/synthetic-playground', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html lang="ko"><head><meta charset="utf-8"><style>body{font-family:Arial,"Malgun Gothic",sans-serif;margin:0;background:#edf8ff;color:#14273c}main{padding:24px}input{font-size:18px;width:80%}</style></head><body><main><h1>합성 실습 작업 공간</h1><p>학습 엔진을 실행하지 않는 상태 유지 시험입니다.</p><label>저장하지 않은 작업 <input id="work" value="변경 전"></label><input id="file" type="file"><p id="ticks"></p></main><script>window.workMarker={token:Math.random()};window.counter=0;setInterval(()=>{counter++;document.querySelector("#ticks").textContent="실행 중: "+counter},50)</script></body></html>' }));
  await env.page.locator('[data-oj-ml-display-toolbar]').waitFor();
  return env;
}

async function screenshot(page, name) {
  await page.screenshot({ path: path.join(shots, 'display-' + name + '.png'), fullPage: false });
}
async function mode(page, value) {
  await page.waitForFunction(expected => document.querySelector('.oj-ml-app').dataset.displayMode === expected, value);
}
async function rememberFrame(page) {
  return page.evaluate(() => {
    const frame = document.querySelector('.oj-ml-playground-frame');
    window.displayFixture = { frame, window: frame.contentWindow, marker: frame.contentWindow.workMarker, src: frame.getAttribute('src'), counter: frame.contentWindow.counter };
    return frame.contentWindow.counter;
  });
}
async function preserved(page, label) {
  const result = await page.evaluate(() => {
    const saved = window.displayFixture, current = document.querySelector('.oj-ml-playground-frame');
    return { sameNode: current === saved.frame, sameWindow: current.contentWindow === saved.window, sameMarker: current.contentWindow.workMarker === saved.marker,
      sameSrc: current.getAttribute('src') === saved.src, counter: current.contentWindow.counter, initialCounter: saved.counter, value: current.contentDocument.querySelector('#work').value };
  });
  check(label + ' preserves iframe DOM, src, JS runtime and unsaved input ' + JSON.stringify(result), result.sameNode && result.sameWindow && result.sameMarker && result.sameSrc && result.counter >= result.initialCounter && result.value === '화면 전환에도 보존할 실습 초안');
}

async function labScenario(browser, name, viewport) {
  const { page, context, requests, label } = await fixture(browser, name, viewport, 'lab');
  try {
    const root = page.locator('.oj-ml-app'), toolbar = page.locator('[data-oj-ml-display-toolbar]');
    const wide = toolbar.locator('[data-oj-ml-wide]'), focus = toolbar.locator('[data-oj-ml-focus]'), exit = toolbar.locator('[data-oj-ml-display-exit]');
    await mode(page, 'normal');
    const normalWidth = (await root.boundingBox()).width;
    if (viewport.width > 900) check(label + ' authentic host baseline is constrained by 1220px shell/sidebar', normalWidth <= 1000 && normalWidth > 900);
    await page.evaluate(() => window.OJMLDisplay.attach(document.querySelector('.oj-ml-app')));
    check(label + ' repeated controller attachment creates only one toolbar', await page.locator('[data-oj-ml-display-toolbar]').count() === 1);
    await wide.click(); await mode(page, 'wide');
    const wideWidth = (await root.boundingBox()).width;
    dimensions.push({ label, normalWidth, wideWidth });
    check(label + ' wide mode never narrows viewport content ' + JSON.stringify({ normalWidth, wideWidth }), wideWidth >= normalWidth - 1);
    if (viewport.width > 900) check(label + ' wide mode expands beyond host-constrained baseline ' + JSON.stringify({ normalWidth, wideWidth }), wideWidth > normalWidth + 10);
    await noOverflow(page, label + ':wide');
    await screenshot(page, name + '-' + viewport.width + '-wide');
    await exit.click(); await mode(page, 'normal');
    await page.locator('[data-oj-ml-start]').click();
    await page.locator('.oj-ml-playground-frame').waitFor();
    await page.waitForFunction(() => document.querySelector('.oj-ml-playground-frame').contentWindow.workMarker);
    const frame = page.frames().find(item => item.url() === origin + '/synthetic-playground');
    assert.ok(frame, label + ' synthetic iframe loaded through actual start button');
    await frame.locator('#work').fill('화면 전환에도 보존할 실습 초안');
    await frame.waitForFunction(() => counter > 0);
    await mode(page, 'focus');
    check(label + ' explicit lab start automatically enters focus mode', requests.filter(item => item.path.endsWith('/attempts')).length === 1);
    await rememberFrame(page);
    await preserved(page, label + ':initial-focus');
    dimensions[dimensions.length - 1].focusFrame = await page.locator('.oj-ml-playground-frame').boundingBox();
    check(label + ' focus mode preserves public-PC cleanup control', await page.locator('[data-oj-ml-wipe-local]').isVisible());
    await noOverflow(page, label + ':focus');
    await mobileBounds(page, toolbar.locator('button:visible'), label + ':toolbar');
    await screenshot(page, name + '-' + viewport.width + '-focus');
    await toolbar.locator('[data-oj-ml-guidance]').click();
    await page.waitForFunction(() => document.querySelector('.oj-ml-app').dataset.guidanceCollapsed === '0');
    check(label + ' guidance drawer can reopen after automatic collapse', await page.locator('.oj-ml-lab-guidance').isVisible());
    check(label + ' guidance toggle does not hide protection controls', await page.locator('[data-oj-ml-wipe-local]').isVisible());
    await preserved(page, label + ':guidance');
    await toolbar.locator('[data-oj-ml-guidance]').click();
    await page.locator('.oj-ml-lab-guidance').waitFor({ state: 'hidden' });
    await toolbar.locator('[data-oj-ml-submission-toggle]').click();
    check(label + ' submission confirmation drawer remains reachable in focus mode', await page.locator('[data-oj-ml-submit]').isVisible());
    await preserved(page, label + ':submission-drawer');
    await toolbar.locator('[data-oj-ml-submission-toggle]').click();
    await page.locator('[data-oj-ml-submit]').waitFor({ state: 'hidden' });
    await exit.click(); await mode(page, 'normal');
    await preserved(page, label + ':normal');
    await wide.focus(); await page.keyboard.press('Enter'); await mode(page, 'wide');
    check(label + ' native keyboard activation changes mode', await wide.getAttribute('aria-pressed') === 'true');
    await focus.click(); await mode(page, 'focus');
    await exit.focus(); await page.keyboard.press('Escape'); await mode(page, 'normal');
    await preserved(page, label + ':keyboard-exit');
    await focus.click(); await mode(page, 'focus');
    await frame.locator('#work').focus(); await page.keyboard.press('Tab');
    check(label + ' iframe internal Tab navigation is not hijacked by parent focus boundary', await frame.evaluate(() => document.activeElement.id === 'file'));
    await frame.locator('#work').focus(); await page.keyboard.press('Escape'); await mode(page, 'normal');
    await preserved(page, label + ':iframe-keyboard-exit');
    await focus.click(); await mode(page, 'focus');
    await wide.focus(); await page.keyboard.press('Shift+Tab');
    check(label + ' keyboard navigation stays inside focused work area', await page.evaluate(() => document.querySelector('.oj-ml-app').contains(document.activeElement)));

    // Authentication and file-picker outcomes are deliberately simulated here.
    // Browser native file selection cannot run the real OJ session verifier.
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcCovered = '1'; });
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.oj-ml-playground-frame')).opacity === '0');
    const covered = await page.locator('[data-oj-ml-frame]').evaluate(node => ({ content: getComputedStyle(node, '::after').content, pointerEvents: getComputedStyle(node, '::after').pointerEvents }));
    check(label + ' file-picker verification curtain stays above focused iframe', covered.content.includes('파일 선택 후') && covered.pointerEvents === 'auto');
    await preserved(page, label + ':covered');
    await screenshot(page, name + '-' + viewport.width + '-privacy-curtain');
    await page.evaluate(() => { delete document.querySelector('.oj-ml-app').dataset.publicPcCovered; });
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.oj-ml-playground-frame')).opacity === '1');
    await mode(page, 'focus');
    await preserved(page, label + ':file-picker-return');
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcLocked = '1'; });
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.oj-ml-playground-frame')).visibility === 'hidden');
    check(label + ' login verification lock cannot reveal iframe in focus mode', !(await page.locator('.oj-ml-playground-frame').isVisible()));
    await wide.focus(); await page.keyboard.press('Shift+Tab');
    check(label + ' locked invisible content is excluded from keyboard focus targets', await page.evaluate(() => {
      const active = document.activeElement;
      return getComputedStyle(active).visibility === 'visible' && !active.closest('[data-oj-ml-content]');
    }));
    await preserved(page, label + ':locked');
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.publicPcLocked = '0'; });
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.oj-ml-playground-frame')).visibility === 'visible');
    await mode(page, 'focus');
    await page.evaluate(() => {
      const app = document.querySelector('.oj-ml-app'), native = app.requestFullscreen;
      window.displayNativeFullscreen = { status: native ? 'pending' : 'unavailable' };
      if (native) app.requestFullscreen = function () {
        return native.call(this).then(value => { window.displayNativeFullscreen.status = 'entered'; return value; }, error => {
          window.displayNativeFullscreen = { status: 'rejected', name: error.name, message: error.message }; throw error;
        });
      };
    });
    await toolbar.locator('[data-oj-ml-fullscreen]').click();
    await page.waitForFunction(() => window.displayNativeFullscreen.status !== 'pending');
    const actualFullscreen = await page.evaluate(() => window.displayNativeFullscreen);
    nativeFullscreen.push({ label, ...actualFullscreen });
    if (actualFullscreen.status === 'entered') {
      check(label + ' actual browser native fullscreen targets protected parent root', await page.evaluate(() => document.fullscreenElement === document.querySelector('.oj-ml-app')));
      await preserved(page, label + ':native-fullscreen');
      await page.evaluate(() => document.exitFullscreen());
      await page.waitForFunction(() => !document.fullscreenElement);
      await mode(page, 'focus');
      await preserved(page, label + ':native-fullscreen-exit');
      check(label + ' actual native fullscreen exit retains CSS focus and usable controls', await exit.isVisible() && await page.locator('[data-oj-ml-wipe-local]').isVisible());
    } else {
      check(label + ' native fullscreen restriction leaves CSS focus available ' + actualFullscreen.status, await root.getAttribute('data-display-mode') === 'focus');
    }
    await page.evaluate(() => {
      window.displayFullscreenCalls = 0;
      document.querySelector('.oj-ml-app').requestFullscreen = function () { window.displayFullscreenCalls++; return Promise.reject(new DOMException('Synthetic fullscreen policy denial', 'NotAllowedError')); };
    });
    await toolbar.locator('[data-oj-ml-fullscreen]').click();
    await page.waitForFunction(() => window.displayFullscreenCalls > 0);
    await mode(page, 'focus');
    await toolbar.getByText('전체화면을 사용할 수 없어 집중 모드를 유지합니다. Esc로 돌아갈 수 있습니다.', { exact: true }).waitFor();
    check(label + ' rejected native fullscreen leaves functional focus fallback', await exit.isVisible() && await page.locator('.oj-ml-playground-frame').isVisible());
    await preserved(page, label + ':fullscreen-denied');
    await exit.click(); await mode(page, 'normal');
    await page.evaluate(() => { document.querySelector('.oj-ml-app').dataset.labFocusOnStart = '0'; });
    await toolbar.locator('[data-oj-ml-guidance]').click();
    await page.locator('[data-oj-ml-start]').click(); await mode(page, 'normal');
    await preserved(page, label + ':resume-opt-out');
    check(label + ' resuming same work never creates another attempt or iframe', requests.filter(item => item.path.endsWith('/attempts')).length === 1 && await page.locator('.oj-ml-playground-frame').count() === 1);
    await noOverflow(page, label + ':final');
    await focus.click(); await mode(page, 'focus');
    await page.evaluate(() => { const app = document.querySelector('.oj-ml-app'); app.dataset.publicPcLocked = '1'; app.dataset.publicPcTerminal = '1'; });
    await toolbar.waitFor({ state: 'hidden' });
    await mode(page, 'normal');
    await focus.dispatchEvent('click');
    check(label + ' terminal revocation hides display controls and cannot reopen focus', !(await toolbar.isVisible()) && await root.getAttribute('data-display-mode') === 'normal' && !(await page.locator('.oj-ml-playground-frame').isVisible()));
    check(label + ' terminal revocation restores host scroll and isolation', await page.evaluate(() => document.body.style.overflow !== 'hidden' && !document.querySelector('.ojp-console-header').inert));
  } finally { await context.close(); }
}

async function reviewScenario(browser, name, viewport) {
  const { page, context, requests, label } = await fixture(browser, name, viewport, 'console-results');
  try {
    await page.locator('[data-result-open="view"]').first().click();
    await page.locator('[data-oj-ml-preview][data-preview-state="loaded"]').waitFor();
    const details = page.locator('[data-oj-ml-result-details]').first();
    const form = details.locator('[data-oj-ml-review-form]');
    const expand = details.locator('[data-oj-ml-review-expand]');
    await form.locator('[name="score"]').fill('87.5');
    await form.locator('[name="maxScore"]').fill('100');
    await form.locator('[name="feedback"]').fill('화면을 넓혀도 유지할 교사 평가 초안');
    await form.evaluate(node => { window.savedReviewForm = node; });
    const initialWidth = (await details.boundingBox()).width;
    await expand.click();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-oj-ml-result-details]')).position === 'fixed');
    check(label + ' expanded review is viewport workbench', (await details.boundingBox()).width >= Math.min(viewport.width - 40, initialWidth));
    check(label + ' review expansion keeps same form and unsaved values', await form.evaluate(node => node === window.savedReviewForm) && await form.locator('[name="score"]').inputValue() === '87.5' && await form.locator('[name="feedback"]').inputValue() === '화면을 넓혀도 유지할 교사 평가 초안');
    check(label + ' expanded review exposes dialog semantics and isolates host navigation', await details.getAttribute('role') === 'dialog' && await details.getAttribute('aria-modal') === 'true' && await page.locator('.ojp-console-header').evaluate(node => node.inert));
    check(label + ' expanded sticky tools retain student identity', (await details.locator('.oj-ml-review-display-tools').innerText()).includes('김탐구') && (await details.locator('summary').first().innerText()).includes('김탐구'));
    if (viewport.width > 1000) {
      await details.locator('[data-oj-ml-review-split]').focus(); await page.keyboard.press('End');
      check(label + ' review divider adjusts without recreating draft form', await details.evaluate(node => node.style.getPropertyValue('--oj-ml-preview-width')) === '75%' && await form.evaluate(node => node === window.savedReviewForm));
    }
    await noOverflow(page, label + ':expanded');
    await mobileBounds(page, form.locator('input:not([type="hidden"]),textarea,button'), label + ':expanded-controls');
    await screenshot(page, name + '-' + viewport.width + '-expanded-review');
    await form.locator('[data-review-action="save"]').click();
    const displayStatus = details.locator('[data-oj-ml-review-display-status]');
    await displayStatus.getByText(/의도적인 평가 저장 실패.*입력한 점수와 피드백은 유지했습니다/).waitFor();
    check(label + ' expanded review keeps failure status visible inside the workbench', await displayStatus.isVisible());
    check(label + ' expanded review server refusal preserves score and feedback', await form.locator('[name="score"]').inputValue() === '87.5' && await form.locator('[name="feedback"]').inputValue() === '화면을 넓혀도 유지할 교사 평가 초안');
    check(label + ' display action does not alter evaluation payload', requests.find(item => item.path.endsWith('/review')).body.score === 87.5);
    await screenshot(page, name + '-' + viewport.width + '-expanded-review-error');
    await expand.click();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-oj-ml-result-details]')).position !== 'fixed');
    check(label + ' restoring review keeps same live form node', await form.evaluate(node => node === window.savedReviewForm));
    await expand.focus(); await page.keyboard.press('Enter');
    await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-oj-ml-result-details]')).position === 'fixed');
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-oj-ml-result-details]')).position !== 'fixed');
    check(label + ' keyboard Escape restores review without dropping draft', await form.locator('[name="feedback"]').inputValue() === '화면을 넓혀도 유지할 교사 평가 초안');
  } finally { await context.close(); }
}

async function cleanupScenario(browser, name, viewport) {
  const { page, context, label } = await fixture(browser, name, viewport, 'lab');
  try {
    await page.locator('[data-oj-ml-start]').click(); await mode(page, 'focus');
    await page.evaluate(() => {
      window.detachedDisplayRoot = document.querySelector('.oj-ml-app');
      window.detachedDisplayRoot.remove();
    });
    await page.waitForFunction(() => window.detachedDisplayRoot.dataset.displayMode === 'normal' && document.body.style.overflow !== 'hidden');
    check(label + ' removing focused root restores host scrolling and inert state', await page.evaluate(() => !document.querySelector('.ojp-console-header').inert && !document.querySelector('.ojp-console-sidebar').inert));
    check(label + ' removing focused root restores ancestor width classes', await page.locator('.oj-ml-wide-host').count() === 0);
    await page.evaluate(() => window.detachedDisplayRoot.querySelector('[data-oj-ml-focus]').dispatchEvent(new MouseEvent('click', { bubbles: true })));
    check(label + ' detached controller cannot resume focus from old button', await page.evaluate(() => window.detachedDisplayRoot.dataset.displayMode === 'normal'));
  } finally { await context.close(); }
}

async function settingsScenario(browser, name, viewport) {
  const settings = { maxArtifactBytes: 25 * 1048576, siteQuotaBytes: 2 * 1073741824, minimumFreeBytes: 500 * 1048576, inspectionSlots: 2, retentionDays: 90,
    maintenanceMode: false, retentionHold: true, allowPersonalErasure: false, privacyUrl: 'https://school.example/privacy', labFocusOnStart: false, labGuidanceCollapsed: true };
  const { page, context, requests, label } = await fixture(browser, name, viewport, 'console-settings', { api: req => {
    if (req.path === 'console/health') return { data: { health: { ready: true, checks: [], metrics: {}, runtime: { status: 'ready' } } } };
    if (req.path === 'console/settings') return { data: { settings: req.method === 'POST' ? { ...settings, ...req.body } : settings } };
  } });
  try {
    const form = page.locator('[data-settings]');
    check(label + ' settings initialize opt-in focus false and guidance collapse true', !(await form.locator('[name="labFocusOnStart"]').isChecked()) && await form.locator('[name="labGuidanceCollapsed"]').isChecked());
    await form.locator('[name="labFocusOnStart"]').check();
    await form.locator('[name="labGuidanceCollapsed"]').uncheck();
    await form.locator('button[type="submit"]').click();
    await page.getByText('운영 설정을 저장했습니다. 상태를 다시 확인하려면 이 설정 페이지를 새로 여세요.', { exact: true }).waitFor();
    const posted = requests.find(item => item.path === 'console/settings' && item.method === 'POST').body;
    check(label + ' settings save real JSON booleans for display preferences', posted.labFocusOnStart === true && posted.labGuidanceCollapsed === false);
    check(label + ' changing display preferences preserves all existing operational settings', Object.keys(settings).filter(key => !key.startsWith('lab')).every(key => posted[key] === settings[key]));
    await noOverflow(page, label + ':settings');
    await form.locator('fieldset').scrollIntoViewIfNeeded();
    await screenshot(page, name + '-' + viewport.width + '-settings');
  } finally { await context.close(); }
}

(async () => {
  fs.mkdirSync(shots, { recursive: true });
  for (const [name, executablePath] of [['chrome', process.env.OJ_ML_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'], ['edge', process.env.OJ_ML_EDGE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']]) {
    assert.ok(fs.existsSync(executablePath), name + ' executable must exist');
    const browser = await chromium.launch({ executablePath, headless: true, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
    try {
      report.browsers.push({ name, version: browser.version() });
      for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
        await labScenario(browser, name, viewport);
        await reviewScenario(browser, name, viewport);
        await cleanupScenario(browser, name, viewport);
        await settingsScenario(browser, name, viewport);
      }
    } finally { await browser.close(); }
  }
  check('display UI has no uncaught browser errors', report.pageErrors.length === 0);
  // Existing upstream frame allow="webgpu; fullscreen" produces this exact
  // non-fatal warning in both installed browsers; report it, do not hide others.
  const knownFrameWarnings = report.console.filter(item => item.message === "Unrecognized feature: 'webgpu'.");
  check('display UI has no unexpected console errors', report.console.filter(item => !item.message.includes('503 (Service Unavailable)') && item.message !== "Unrecognized feature: 'webgpu'.").length === 0);
  process.stdout.write(JSON.stringify({ kind: 'real host CSS + local display/UI, synthetic REST/auth/iframe; no live acceptance', browsers: report.browsers, count: report.checks.length, pageErrors: report.pageErrors, knownFrameWarnings: knownFrameWarnings.length, dimensions, nativeFullscreen, screenshots: shots, requests: report.requests.length }, null, 2) + '\nDisplay-mode browser smoke tests passed.\n');
})().catch(error => { process.stderr.write(error.stack + '\n' + JSON.stringify({ browsers: report.browsers, checks: report.checks.slice(-10), pageErrors: report.pageErrors, console: report.console }, null, 2) + '\n'); process.exitCode = 1; });
