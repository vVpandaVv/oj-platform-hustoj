import assert from 'node:assert/strict';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(import.meta.dirname, '..');
const fixture = pathToFileURL(path.join(root, 'tests/fixtures/worksheet-evaluation-workbench-visual.html')).href;
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
try {
  for (const width of [1440, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 950 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${fixture}?review_history=1&history=1&worksheet_id=01HZY3M4N5P6Q7R8S9T0V1W2X3`);
    const detail = page.locator('[data-role="evaluation-detail"]');
    await detail.getByRole('button', {name:'재검토',exact:true}).waitFor();
    const original = await page.evaluate(() => window.__reviewHistorySnapshot());
    assert.equal(await detail.locator('[data-field="finding-decision"]').count(), 0);
    await detail.getByRole('button').filter({hasText:'1차 검토'}).click();
    assert.match(await detail.locator('[data-role="recorded-review"]').innerText(), /1차 교사 검토 기록/);
    assert.match(await detail.innerText(), /문맥상 단계가 분명해 현재 안내를 유지합니다/);
    assert.match(await detail.innerText(), /당시 처리: 미처리/);
    assert.equal(await detail.locator('[data-field="finding-decision"]').count(), 0);
    assert.equal(await page.evaluate(() => window.__reviewRequests.length), 0);
    await detail.getByRole('button',{name:'현재 검토로 돌아가기',exact:true}).click();
    await detail.getByRole('button',{name:'재검토',exact:true}).click();
    await detail.getByRole('button',{name:'재검토 취소',exact:true}).click();
    assert.equal(await page.evaluate(() => window.__reviewRequests.length), 0);
    await detail.getByRole('button',{name:'재검토',exact:true}).click();
    const notes = detail.locator('[data-field="finding-note"]');
    await notes.first().fill('3차에서 새로 확인한 판단 <script>금지</script>');
    await page.evaluate(() => { window.__failNextReview = true; });
    await detail.getByRole('button',{name:'검토 완료',exact:true}).click();
    await page.getByText('검토 저장 실패',{exact:true}).waitFor();
    assert.match(await notes.first().inputValue(), /3차에서 새로 확인한 판단/);
    assert.deepEqual(await page.evaluate(() => window.__reviewHistorySnapshot()), original);
    // Dispatch two clicks before the asynchronous request resolves.
    await detail.getByRole('button',{name:'검토 완료',exact:true}).evaluate(button => { button.click(); button.click(); });
    await detail.getByRole('button').filter({hasText:'3차 검토'}).waitFor();
    const updated = await page.evaluate(() => window.__reviewHistorySnapshot());
    assert.equal(updated.length, 3);
    assert.deepEqual(updated.slice(0, 2), original);
    assert.equal(updated[2].previous_id, original[1].id);
    assert.match(updated[2].item_decisions[0].note, /3차에서 새로 확인한 판단/);
    assert.equal(await page.evaluate(() => window.__reviewRequests.length), 2, 'One failed request and one successful request, no double append');
    assert.equal(await page.evaluate(() => window.__evaluationCommandCount), 0, 'Teacher re-review never calls AI');
    await detail.getByRole('button').filter({hasText:'1차 검토'}).click();
    assert.match(await detail.innerText(), /문맥상 단계가 분명/);
    assert.doesNotMatch(await detail.locator('.oj-ai-evaluation__recorded-decision').first().innerText(), /3차에서/);
    await detail.getByRole('button').filter({hasText:'3차 검토'}).click();
    assert.match(await detail.innerText(), /<script>금지<\/script>/);
    assert.equal(await detail.locator('script').count(), 0);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    await page.screenshot({path:path.join(root,`tests/artifacts/worksheet-review-ledger-${width}.png`),fullPage:true});
    assert.deepEqual(errors, []);
    await page.goto(`${fixture}?review_history=1&can_review=0`);
    await page.getByRole('button').filter({hasText:'1차 검토'}).waitFor();
    await page.getByRole('button').filter({hasText:'1차 검토'}).click();
    assert.equal(await page.getByRole('button',{name:'재검토',exact:true}).count(), 0);
    assert.equal(await page.locator('[data-field="finding-decision"]').count(), 0);
    await page.close();
    console.log(`PASS review ledger ${width}: immutable snapshots, append, cancel, past decisions, safe text, failed save retains draft, duplicate click, permissions`);
  }
} finally { await browser.close(); }
