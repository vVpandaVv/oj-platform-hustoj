const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const { chromium } = require(process.env.PLAYWRIGHT_PATH);

async function main() {
    const reserve = net.createServer();
    reserve.listen(0, '127.0.0.1'); await once(reserve, 'listening');
    const port = reserve.address().port; await new Promise(resolve => reserve.close(resolve));
    const root = path.resolve(__dirname, '..');
    const out = path.resolve(root, '../_notes/tmp/arena-interface');
    fs.mkdirSync(out, { recursive: true });
    const harness = spawn(process.execPath, ['tools/dev-harness.js'], {cwd:root,env:{...process.env,HARNESS_PORT:String(port),HARNESS_ENGINE:'builtin'},stdio:['ignore','pipe','pipe']});
    let browser, checks = 0;
    const errors = [];
    const check = (value, label) => { assert(value, label); checks++; };
    try {
        await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(Error('harness startup timed out')), 15000);
            harness.stdout.on('data', data => { if (data.toString().includes('[harness]')) {clearTimeout(timeout);resolve();} });
            harness.on('error', reject);
            harness.stderr.on('data', data => process.stderr.write(data));
        });
        browser = await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
        const page = await browser.newPage({viewport:{width:1280,height:950}});
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`http://127.0.0.1:${port}/play`);
        await page.waitForSelector('[data-act=join-session]');
        async function layout(phase) {
            for (const width of [1280,390]) {
                await page.setViewportSize({width,height:950});
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),phase+' no page overflow');
                check(await page.locator('.oca-app').evaluate(e => getComputedStyle(e).getPropertyValue('--oca-accent').trim()==='#0071e3'),phase+' shared accent');
                check(await page.locator('.oca-app').evaluate(e => getComputedStyle(e).isolation==='isolate'),phase+' layers contained');
                check(await page.locator('.oca-btn:visible').evaluateAll(es=>es.every(e=>e.scrollWidth<=e.clientWidth+1&&e.scrollHeight<=e.clientHeight+1)),phase+' button labels fit');
                await page.screenshot({path:path.join(out,`${phase}-${width}.png`),fullPage:true});
            }
        }
        await layout('home');
        await page.locator('[data-act=free]').click();
        await page.waitForSelector('[data-oca-create]');
        await layout('lobby');
        await page.locator('[name=title]').fill('디자인 검증');
        await page.locator('[data-oca-create] button').click();
        await page.waitForSelector('[data-act=start]');
        await page.locator('[data-act=bot-add]').click();
        await page.waitForSelector('[data-act=bot-remove]');
        await layout('waiting');
        await page.locator('[data-act=start]').click();
        await page.waitForSelector('[data-oca-grid] .oca-cell');
        await page.waitForFunction(()=>!document.querySelector('.oca-countdown'));
        await layout('game');
        check(await page.locator('.oca-cell').count()>0,'actual game board rendered');
        const before=await page.locator('.oca-cell.is-cursor').getAttribute('data-i');
        await page.keyboard.press('ArrowRight');
        await page.waitForFunction(before=>document.querySelector('.oca-cell.is-cursor')?.dataset.i!==before,before);
        check(true,'keyboard moves across real board');
        assert.deepEqual(errors, []);
        console.log(checks+' Arena interface checks passed');
    } finally {
        if(browser)await browser.close();
        harness.kill();
        if(harness.exitCode===null)await once(harness,'exit');
    }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
