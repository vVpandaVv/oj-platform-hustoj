import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/assessment-configuration-settings-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];

await mkdir(artifactDir, { recursive: true });

async function layoutSnapshot(page) {
  return page.evaluate(() => {
    const diagnostics = document.querySelector('#oj-ai-operations-diagnostics');
    const visible = (element) => element.getClientRects().length > 0;
    const clippedControl = [...document.querySelectorAll('#oj-ai-operations-diagnostics button, #oj-ai-operations-diagnostics a')]
      .filter(visible)
      .some((element) => {
        const rect = element.getBoundingClientRect();
        return rect.left < -1 || rect.right > window.innerWidth + 1;
      });
    return {
      viewport: window.innerWidth,
      bodyOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      rootOverflow: diagnostics ? diagnostics.scrollWidth > diagnostics.clientWidth + 1 : true,
      clippedControl,
      rows: document.querySelectorAll('[data-diagnostics-role="list"] [data-check-key]').length,
      rowTooShort: [...document.querySelectorAll('[data-diagnostics-role="list"] [data-check-key]')]
        .filter(visible)
        .some((element) => element.getBoundingClientRect().height < 68),
    };
  });
}

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') pageErrors.push(message.text()); });

  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.getByRole('tab', { name: '시스템 상태', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('#oj-ai-operations-diagnostics')?.dataset.access === 'granted');

  const mixed = await page.evaluate(() => {
    const diagnostics = document.querySelector('#oj-ai-operations-diagnostics');
    return {
      activeTab: document.querySelector('[data-settings-tab="system"]')?.getAttribute('aria-selected'),
      configHidden: document.querySelector('#oj-ai-assessment-configuration-app')?.hidden,
      subjectHidden: document.querySelector('[data-config-role="subject-switch"]')?.hidden,
      configRefreshHidden: document.querySelector('[data-config-action="refresh"]')?.hidden,
      summary: document.querySelector('[data-diagnostics-role="summary"]')?.textContent,
      keys: [...document.querySelectorAll('[data-check-key]')].map((row) => row.dataset.checkKey),
      statuses: [...document.querySelectorAll('[data-check-key]')].map((row) => row.dataset.status),
      queueGuidance: document.querySelector('[data-check-key="queue"] .oj-ai-diagnostics__guidance')?.textContent,
      storageAction: document.querySelector('[data-check-key="storage"] button')?.textContent,
      aiAction: document.querySelector('[data-check-key="ai_routing"] a')?.textContent,
      aiHref: document.querySelector('[data-check-key="ai_routing"] a')?.href,
      aiTasks: [...document.querySelectorAll('[data-check-key="ai_routing"] .oj-ai-diagnostics__task')].map((task) => ({
        name: task.querySelector('.oj-ai-diagnostics__task-name')?.textContent,
        status: task.dataset.taskStatus,
        label: task.querySelector('.oj-ai-diagnostics__task-status')?.textContent,
      })),
      text: diagnostics?.innerText || '',
      request: window.__diagnosticsRequests[0]
        ? {
            url: window.__diagnosticsRequests[0].url,
            nonce: window.__diagnosticsRequests[0].options?.headers?.['X-WP-Nonce'],
            method: window.__diagnosticsRequests[0].options?.method,
            credentials: window.__diagnosticsRequests[0].options?.credentials,
            cache: window.__diagnosticsRequests[0].options?.cache,
          }
        : null,
    };
  });

  if (mixed.activeTab !== 'true' || mixed.configHidden !== true || mixed.subjectHidden !== true || mixed.configRefreshHidden !== true) {
    errors.push(`${label}: system tab did not isolate configuration controls`);
  }
  if (mixed.summary !== '사용할 수 없음') errors.push(`${label}: unavailable aggregate label drifted`);
  if (mixed.keys.join(',') !== 'bridge,schema,queue,storage,ai_routing') errors.push(`${label}: five-check order drifted`);
  if (mixed.statuses.join(',') !== 'ready,ready,attention,unavailable,attention') errors.push(`${label}: mixed status rendering drifted`);
  if (mixed.queueGuidance !== '관리자 확인 필요' || mixed.storageAction !== '다시 확인') errors.push(`${label}: local recovery guidance drifted`);
  if (mixed.aiAction !== 'AI 작업 배정 열기' || mixed.aiHref !== 'https://example.test/oj-console/settings/ai-routing') errors.push(`${label}: platform AI routing action drifted`);
  if (JSON.stringify(mixed.aiTasks) !== JSON.stringify([
    { name: '활동지 자체 평가', status: 'unroutable', label: '설정 필요' },
    { name: '학생 제출 평가', status: 'ready', label: '연결됨' },
  ])) errors.push(`${label}: task-level AI routing state drifted ${JSON.stringify(mixed.aiTasks)}`);
  if (!mixed.request || mixed.request.url !== 'https://example.test/wp-json/oj-ai/v1/diagnostics' || mixed.request.nonce !== 'nonce-cfg' || mixed.request.method !== 'GET' || mixed.request.credentials !== 'same-origin' || mixed.request.cache !== 'no-store') {
    errors.push(`${label}: Q28 request boundary drifted`);
  }
  for (const forbidden of ['hidden-queue-reason', 'secret-file.pdf', 'secret-provider', 'provider_key', 'storage_observation_failed']) {
    if (mixed.text.includes(forbidden)) errors.push(`${label}: internal diagnostic value was rendered: ${forbidden}`);
  }

  const mixedLayout = await layoutSnapshot(page);
  if (mixedLayout.bodyOverflow || mixedLayout.rootOverflow || mixedLayout.clippedControl || mixedLayout.rows !== 5 || mixedLayout.rowTooShort) {
    errors.push(`${label}: mixed diagnostics layout failed ${JSON.stringify(mixedLayout)}`);
  }

  await page.evaluate(() => { window.__diagnosticsMode = 'runtime'; });
  await page.locator('.oj-ai-diagnostics__header [data-diagnostics-action="refresh"]').click();
  await page.waitForFunction(() => window.__diagnosticsRequests.length === 2 && document.querySelector('[data-check-key="ai_routing"] a')?.textContent === '플랫폼 상태 열기');
  const runtime = await page.evaluate(() => ({
    action: document.querySelector('[data-check-key="ai_routing"] a')?.textContent,
    href: document.querySelector('[data-check-key="ai_routing"] a')?.href,
    tasks: [...document.querySelectorAll('[data-check-key="ai_routing"] .oj-ai-diagnostics__task')].map((task) => ({
      status: task.dataset.taskStatus,
      label: task.querySelector('.oj-ai-diagnostics__task-status')?.textContent,
    })),
  }));
  if (runtime.action !== '플랫폼 상태 열기' || runtime.href !== 'https://example.test/oj-console/system-status') {
    errors.push(label + ': runtime AI failure action drifted ' + JSON.stringify(runtime));
  }
  if (runtime.tasks.length !== 2 || runtime.tasks.some((task) => task.status !== 'runtime_unavailable' || task.label !== '실행 연결 확인 필요')) {
    errors.push(label + ': runtime AI task guidance drifted ' + JSON.stringify(runtime.tasks));
  }
  const runtimeLayout = await layoutSnapshot(page);
  if (runtimeLayout.bodyOverflow || runtimeLayout.rootOverflow || runtimeLayout.clippedControl || runtimeLayout.rows !== 5 || runtimeLayout.rowTooShort) {
    errors.push(label + ': runtime diagnostics layout failed ' + JSON.stringify(runtimeLayout));
  }
  await page.screenshot({ path: path.join(artifactDir, 'operations-ai-routing-unavailable-' + label + '.png'), fullPage: true });

  await page.evaluate(() => { window.__diagnosticsMode = 'ready'; });
  await page.locator('.oj-ai-diagnostics__header [data-diagnostics-action="refresh"]').click();
  await page.waitForFunction(() => document.querySelector('[data-diagnostics-role="summary"]')?.textContent === '사용 준비됨');
  const ready = await page.evaluate(() => ({
    requests: window.__diagnosticsRequests.length,
    statuses: [...document.querySelectorAll('[data-check-key]')].map((row) => row.dataset.status),
    actionCount: document.querySelectorAll('[data-check-key] .oj-ai-diagnostics__action, [data-check-key] .oj-ai-diagnostics__guidance').length,
    storageDetailHref: document.querySelector('[data-check-key="storage"] a.oj-ai-diagnostics__action')?.getAttribute('href'),
    expectedStorageDetailHref: document.querySelector('#oj-ai-operations-diagnostics')?.dataset.storageDetailUrl,
    checkedAt: document.querySelector('[data-diagnostics-role="checked-at"]')?.textContent || '',
    aiTaskStatuses: [...document.querySelectorAll('[data-check-key="ai_routing"] .oj-ai-diagnostics__task')].map((task) => task.dataset.taskStatus),
  }));
  if (ready.requests !== 3 || ready.statuses.some((status) => status !== 'ready') || ready.actionCount !== 1 || !ready.expectedStorageDetailHref || ready.storageDetailHref !== ready.expectedStorageDetailHref || !ready.checkedAt.startsWith('마지막 확인') || ready.aiTaskStatuses.some((status) => status !== 'ready')) {
    errors.push(`${label}: ready refresh did not replace the mixed state ${JSON.stringify(ready)}`);
  }
  const readyLayout = await layoutSnapshot(page);
  if (readyLayout.bodyOverflow || readyLayout.rootOverflow || readyLayout.clippedControl || readyLayout.rows !== 5 || readyLayout.rowTooShort) {
    errors.push(`${label}: ready diagnostics layout failed ${JSON.stringify(readyLayout)}`);
  }

  await page.evaluate(() => {
    window.__diagnosticsMode = 'repair';
    window.setTimeout = () => 0;
    if (!window.crypto?.subtle) {
      Object.defineProperty(window.crypto, 'subtle', {
        configurable: true,
        value: { digest: async () => new Uint8Array(32).fill(7).buffer },
      });
    }
  });
  await page.locator('.oj-ai-diagnostics__header [data-diagnostics-action="refresh"]').click();
  await page.waitForFunction(() => document.querySelector('[data-diagnostics-role="repair"]')?.hidden === false);
  const repairInitial = await page.evaluate(() => ({
    rowAction: document.querySelector('[data-check-key="schema"] [data-diagnostics-action="open-repair"]')?.textContent,
    submitDisabled: document.querySelector('[data-diagnostics-action="repair"]')?.disabled,
    panelHidden: document.querySelector('[data-diagnostics-role="repair"]')?.hidden,
  }));
  if (repairInitial.rowAction !== '복구' || repairInitial.submitDisabled !== true || repairInitial.panelHidden !== false) {
    errors.push(`${label}: C75 repair affordance initial state drifted ${JSON.stringify(repairInitial)}`);
  }
  await page.locator('[data-check-key="schema"] [data-diagnostics-action="open-repair"]').click();
  const reference = page.locator('[data-diagnostics-field="backup-reference"]');
  if (!(await reference.evaluate((element) => element === document.activeElement))) {
    errors.push(`${label}: C75 row action did not focus the backup confirmation field`);
  }
  await reference.fill('backup-confirmation-g5-s04');
  await page.locator('[data-diagnostics-field="backup-verified-at"]').fill('2026-08-25T11:30');
  await page.locator('[data-diagnostics-field="forward-only"]').check();
  const submit = page.locator('[data-diagnostics-action="repair"]');
  if (await submit.isDisabled()) errors.push(`${label}: valid C75 confirmation did not enable repair`);
  await submit.click();
  await page.waitForFunction(() => document.querySelector('[data-diagnostics-role="repair-status"]')?.textContent.includes('완료'));
  const repairResult = await page.evaluate(() => {
    const request = window.__schemaRepairRequests[0];
    return {
      count: window.__schemaRepairRequests.length,
      url: request?.url,
      method: request?.options?.method,
      credentials: request?.options?.credentials,
      nonce: request?.options?.headers?.['X-WP-Nonce'],
      idempotency: request?.options?.headers?.['Idempotency-Key'],
      body: request?.body,
      rawBody: request?.options?.body || '',
      tone: document.querySelector('[data-diagnostics-role="repair-status"]')?.dataset.tone,
    };
  });
  if (repairResult.count !== 1 || repairResult.url !== 'https://example.test/wp-json/oj-ai/v1/operations/schema/commands/repair' || repairResult.method !== 'POST' || repairResult.credentials !== 'same-origin' || repairResult.nonce !== 'nonce-cfg') {
    errors.push(`${label}: C75 request boundary drifted ${JSON.stringify(repairResult)}`);
  }
  if (!/^schema-repair-[A-Za-z0-9_.:@-]{8,}$/.test(repairResult.idempotency || '') || repairResult.body?.payload?.forward_only !== true) {
    errors.push(`${label}: C75 idempotency or forward-only payload drifted`);
  }
  if (!/^[a-f0-9]{64}$/.test(repairResult.body?.backup?.evidence_digest || '') || repairResult.rawBody.includes('backup-confirmation-g5-s04')) {
    errors.push(`${label}: C75 sent raw backup confirmation instead of its digest`);
  }
  if (repairResult.body?.expected?.installation_state !== 'maintenance_required' || repairResult.body?.expected?.catalog_fingerprint !== 'e'.repeat(64) || repairResult.body?.expected?.repair_state_hash !== 'f'.repeat(64) || repairResult.tone !== 'success') {
    errors.push(`${label}: C75 expected-state or completion projection drifted ${JSON.stringify(repairResult)}`);
  }
  const repairLayout = await layoutSnapshot(page);
  if (repairLayout.bodyOverflow || repairLayout.rootOverflow || repairLayout.clippedControl || repairLayout.rows !== 5) {
    errors.push(`${label}: C75 repair layout failed ${JSON.stringify(repairLayout)}`);
  }
  await page.screenshot({ path: path.join(artifactDir, `operations-schema-repair-${label}.png`), fullPage: true });
  await page.screenshot({ path: path.join(artifactDir, `operations-diagnostics-${label}.png`), fullPage: true });

  await page.evaluate(() => {
    window.__diagnosticsMode = 'ready';
    const previous = window.fetch;
    window.fetch = async (url, options) => {
      const response = await previous(url, options);
      if (!String(url).endsWith('/diagnostics') || !response.ok) return response;
      const payload = await response.json();
      payload.data.status = 'attention';
      payload.data.checks.storage.status = 'attention';
      payload.data.checks.storage.next_action = 'check_file_scanner';
      return { ...response, json: async () => payload };
    };
  });
  await page.locator('.oj-ai-diagnostics__header [data-diagnostics-action="refresh"]').click();
  await page.getByText('파일 검사 서비스가 응답하지 않습니다. 관리자에게 연결 상태 확인을 요청해 주세요.', { exact:true }).waitFor();
  if (await page.locator('[data-check-key="storage"] [data-diagnostics-action="resume-storage-jobs"]').count()) {
    errors.push(`${label}: scanner outage must not suggest deleting files as its remedy`);
  }
  const scannerLayout = await layoutSnapshot(page);
  if (scannerLayout.bodyOverflow || scannerLayout.rootOverflow || scannerLayout.clippedControl || scannerLayout.rows !== 5) {
    errors.push(`${label}: scanner outage layout failed ${JSON.stringify(scannerLayout)}`);
  }
  await page.screenshot({ path: path.join(artifactDir, `operations-scanner-${label}.png`), fullPage: true });

  await page.evaluate(() => { window.__diagnosticsPermissionRevoked = true; });
  await page.locator('.oj-ai-diagnostics__header [data-diagnostics-action="refresh"]').click();
  await page.waitForFunction(() => document.querySelector('#oj-ai-operations-diagnostics')?.dataset.access === 'denied');
  const denied = await page.evaluate(() => ({
    summary: document.querySelector('[data-diagnostics-role="summary"]')?.textContent,
    notice: document.querySelector('[data-diagnostics-role="notice"]')?.textContent,
    rows: document.querySelector('[data-diagnostics-role="list"]')?.childElementCount,
    listHidden: document.querySelector('[data-diagnostics-role="list"]')?.hidden,
    refreshDisabled: document.querySelector('.oj-ai-diagnostics__header [data-diagnostics-action="refresh"]')?.disabled,
    repairHidden: document.querySelector('[data-diagnostics-role="repair"]')?.hidden,
    text: document.querySelector('#oj-ai-operations-diagnostics')?.innerText || '',
  }));
  if (denied.summary !== '볼 수 없음' || denied.notice !== '시스템 상태를 볼 수 없습니다.' || denied.rows !== 0 || denied.listHidden !== true || denied.refreshDisabled !== true || denied.repairHidden !== true) {
    errors.push(`${label}: permission revocation did not fail closed ${JSON.stringify(denied)}`);
  }
  if (denied.text.includes('두 평가 작업이 연결되었습니다.') || denied.text.includes('안전하게 보관되고 있습니다.')) {
    errors.push(`${label}: previously rendered diagnostics survived permission revocation`);
  }

  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] operations diagnostics ${label} ${JSON.stringify({ mixedLayout, runtimeLayout, readyLayout, repairLayout })}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) {
  errors.push(`Playwright operations diagnostics check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('Operations diagnostics visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Operations diagnostics desktop/mobile interaction checks passed.');
