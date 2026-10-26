import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/data-management-settings-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
await mkdir(artifactDir, { recursive: true });

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text());
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });

  const layout = await page.evaluate(() => {
    const rootElement = document.querySelector('#oj-ai-assessment-settings');
    const icons = [...document.querySelectorAll('.oj-ai-icon')];
    const visibleIcons = icons.filter((icon) => icon.getClientRects().length > 0);
    const controls = [...document.querySelectorAll('button, input')].filter((control) => control.getClientRects().length > 0);
    return {
      width: rootElement?.getBoundingClientRect().width || 0,
      height: rootElement?.getBoundingClientRect().height || 0,
      textLength: rootElement?.textContent.trim().length || 0,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      controlOverflow: controls.some((control) => control.scrollWidth > control.clientWidth + 1),
      iconCount: visibleIcons.length,
      brokenIconSize: visibleIcons.some((icon) => {
        const rect = icon.getBoundingClientRect();
        return rect.width < 12 || rect.height < 12;
      }),
      internalTextVisible: /oj_ai_|preview_token|plan_hash|source_fingerprint|\/var\/|s3:\/\//.test(rootElement?.textContent || ''),
    };
  });
  if (layout.width < 260 || layout.height < 260 || layout.textLength < 100) errors.push(`${label}: data management surface is blank`);
  if (layout.horizontalOverflow || layout.controlOverflow) errors.push(`${label}: horizontal or control overflow detected`);
  if (layout.iconCount < 4 || layout.brokenIconSize) errors.push(`${label}: Lucide icons are missing or have unstable dimensions`);
  if (layout.internalTextVisible) errors.push(`${label}: internal identifiers are visible`);

  await page.screenshot({ path: path.join(artifactDir, `data-management-settings-${label}.png`), fullPage: true });

  const migrationOpen = page.getByRole('button', { name: '가져오기', exact: true });
  await migrationOpen.click();
  const migrationDialog = page.locator('[data-data-dialog="migration"]');
  await migrationDialog.waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelector('[data-data-value="migration-candidates"]')?.textContent === '12');
  const migrationStart = migrationDialog.getByRole('button', { name: '가져오기 시작', exact: true });
  if (await migrationStart.isEnabled()) errors.push(`${label}: migration start enabled before acknowledgement`);
  await migrationDialog.getByLabel('원본 데이터는 바꾸거나 삭제하지 않습니다.').check();
  if (!(await migrationStart.isEnabled())) errors.push(`${label}: migration start did not enable after acknowledgement`);
  await migrationStart.click();
  await page.waitForFunction(() => document.querySelector('[data-data-role="migration-notice"]')?.textContent.includes('시작했습니다'));

  const migrationRequests = await page.evaluate(() => window.__dataManagementRequests.slice(0, 3));
  const expectedMigrationPaths = [
    '/migration-runs/commands/preview',
    '/migration-runs/commands/approve',
    '/migration-runs/01HZY3M4N5P6Q7R8S9T0V1W2X3/commands/execute',
  ];
  if (JSON.stringify(migrationRequests.map((request) => request.path)) !== JSON.stringify(expectedMigrationPaths)) {
    errors.push(`${label}: migration command sequence drifted`);
  }
  if (!migrationRequests[0]?.idempotencyKey || migrationRequests[0]?.idempotencyKey !== migrationRequests[1]?.idempotencyKey) {
    errors.push(`${label}: preview and approval must reuse the signed idempotency key`);
  }
  if (migrationRequests[1]?.body?.source?.profile !== 'oj_ai_dashboard_wpdb_prefixed@0.1.2') {
    errors.push(`${label}: legacy source profile drifted`);
  }
  await migrationDialog.getByRole('button', { name: '닫기', exact: true }).click();

  await page.getByRole('button', { name: '영구 삭제', exact: true }).click();
  const purgeDialog = page.locator('[data-data-dialog="purge"]');
  await purgeDialog.waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelector('[data-data-value="purge-rows"]')?.textContent === '90');
  const purgeStart = purgeDialog.getByRole('button', { name: '영구 삭제 시작', exact: true });
  if (await purgeStart.isEnabled()) errors.push(`${label}: purge start enabled before backup proof and confirmation`);
  await purgeDialog.getByLabel('최근 백업을 확인했습니다.').check();
  await purgeDialog.getByLabel('백업 확인 메모').fill('운영 백업 2026-08-27');
  await purgeDialog.getByLabel('영구 삭제를 입력하세요.').fill('영구 삭제');
  if (!(await purgeStart.isEnabled())) errors.push(`${label}: purge start did not enable after complete confirmation`);

  const dialogLayout = await purgeDialog.evaluate((dialog) => {
    const rect = dialog.getBoundingClientRect();
    return { left: rect.left, right: rect.right, width: rect.width };
  });
  if (dialogLayout.left < -1 || dialogLayout.right > viewport.width + 1) errors.push(`${label}: purge dialog exceeds viewport`);

  await purgeStart.click();
  await page.waitForFunction(() => document.querySelector('[data-data-role="purge-notice"]')?.textContent.includes('시작했습니다'));
  const purgeRequests = await page.evaluate(() => window.__dataManagementRequests.slice(3));
  const expectedPurgePaths = [
    '/purge-jobs/commands/preview',
    '/purge-jobs/commands/approve',
    '/purge-jobs/01HZY3M4N5P6Q7R8S9T0V1W2X4/commands/execute',
  ];
  if (JSON.stringify(purgeRequests.map((request) => request.path)) !== JSON.stringify(expectedPurgePaths)) {
    errors.push(`${label}: purge command sequence drifted`);
  }
  const approval = purgeRequests[1]?.body;
  if (approval?.confirmation?.phrase !== '영구 삭제'
    || !/^[a-f0-9]{64}$/.test(approval?.backup?.evidence_digest || '')
    || JSON.stringify(approval).includes('운영 백업')) {
    errors.push(`${label}: purge approval leaked raw backup evidence or lost exact confirmation`);
  }
  if (!purgeRequests[0]?.idempotencyKey || purgeRequests[0]?.idempotencyKey !== purgeRequests[1]?.idempotencyKey) {
    errors.push(`${label}: purge preview and approval idempotency binding drifted`);
  }

  await page.screenshot({ path: path.join(artifactDir, `data-management-purge-${label}.png`), fullPage: true });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] data management ${label} ${JSON.stringify(layout)}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) {
  errors.push(`Playwright data management check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('Data management settings visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Data management settings desktop/mobile interaction checks passed.');
