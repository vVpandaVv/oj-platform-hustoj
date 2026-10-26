const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const out = path.join(root, '_notes/tmp/css-footer-review-20261018');
fs.mkdirSync(out, { recursive: true });
(async () => {
    const browser = await chromium.launch({ headless: true });
    let checks = 0;
    try {
        for (const fixture of ['report-dashboard', 'student-assignments', 'assessment-configuration-settings']) {
            for (const width of [1440, 390]) {
                for (const scheme of ['light', 'dark']) {
                    const page = await browser.newPage({ viewport: { width, height: 1000 }, colorScheme: scheme });
                    try {
                        await page.goto(pathToFileURL(path.join(root, `oj-ai-dashboard/tests/fixtures/${fixture}-visual.html`)).href);
                        await page.addStyleTag({ path: path.join(root, 'oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css') });
                        await page.evaluate(scheme => { document.documentElement.dataset.ojpScheme = scheme; document.body.classList.add('ojp-app'); }, scheme);
                        await page.addStyleTag({ content: 'body,.fixture-shell{background:var(--ojp-color-surface);color:var(--ojp-color-ink)}' });
                        await page.waitForTimeout(150);
                        const result = await page.evaluate(() => {
                            const title = [...document.querySelectorAll('h1,h2')].find(e => e.getBoundingClientRect().height);
                            return { overflow: document.documentElement.scrollWidth > innerWidth + 1,
                                title: title?.textContent.trim(), foreground: title && getComputedStyle(title).color,
                                background: getComputedStyle(document.body).backgroundColor,
                                hiddenVisible: [...document.querySelectorAll('[hidden]')].filter(e => e.getBoundingClientRect().width && e.getBoundingClientRect().height).length };
                        });
                        assert(result.title, `${fixture}: missing title`);
                        assert.equal(result.overflow, false, `${fixture} ${width} ${scheme}: horizontal overflow`);
                        assert.equal(result.hiddenVisible, 0, `${fixture}: native hidden visibility`);
                        const components = color => color.match(/[\d.]+/g).slice(0, 3).map(Number);
                        const luminance = color => components(color).map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((n, v, i) => n + v * [.2126, .7152, .0722][i], 0);
                        const a = luminance(result.foreground), b = luminance(result.background);
                        assert((Math.max(a, b) + .05) / (Math.min(a, b) + .05) >= 3, `${fixture}: title contrast ${scheme}`);
                        checks += 4;
                        await page.screenshot({ path: path.join(out, `${fixture}-${width}-${scheme}.png`), fullPage: true });
                    } finally { await page.close(); }
                }
            }
        }
        const probes = [
            '<div class="oj-ai-dashboard-admin"><section class="oj-ai-dashboard-workspace-panel" hidden data-hidden-probe>Panel</section></div>',
            '<section id="oj-ai-projects" class="oj-ai-projects"><button class="project-command" hidden data-hidden-probe>Project</button></section>',
            '<section id="oj-ai-assignment-app" class="oj-ai-assignment"><button class="oj-ai-assignment__back ojp-button" hidden data-hidden-probe>Assignment</button></section>',
            '<section id="oj-ai-assessment-settings" class="oj-ai-settings"><div class="oj-ai-config__notice" hidden data-hidden-probe>Settings</div><section class="oj-ai-data"><div class="oj-ai-data__dialog"><button class="ojp-button" hidden data-hidden-probe>Data</button></div></section></section>',
            '<section id="oj-ai-operations-diagnostics" class="oj-ai-diagnostics"><button class="ojp-button" hidden data-hidden-probe>Diagnostics</button></section>',
            '<section id="oj-ai-report-app" class="oj-ai-report"><button class="ojp-button" hidden data-hidden-probe>Report</button></section>',
            '<section id="oj-ai-submission-review-app" class="oj-ai-assessment-surface oj-ai-submission-review"><button class="ojp-button" hidden data-hidden-probe>Review</button></section>',
            '<section id="oj-ai-worksheet-authoring-app" class="oj-ai-workbench oj-ai-workbench--editor"><button class="ojp-button" hidden data-hidden-probe>Editor</button></section>',
            '<section id="oj-ai-worksheet-evaluation-app" class="oj-ai-evaluation"><button class="ojp-button" hidden data-hidden-probe>Evaluation</button></section>',
            '<nav class="oj-ai-workflow" hidden data-hidden-probe><button>Workflow</button></nav><a class="oj-ai-workspace-return" hidden data-hidden-probe>Return</a>',
            '<section class="oj-ai-workflow-context" hidden data-hidden-probe>Context</section>',
        ].join('');
        const cssDirectory = path.join(root, 'oj-ai-dashboard/resources/css');
        const hostCss = path.join(root, 'oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css');
        for (const width of [1440, 390]) {
            for (const hostFirst of [true, false]) {
                const page = await browser.newPage({ viewport: { width, height: 1000 } });
                try {
                    await page.setContent('<body class="ojp-app">' + probes + '</body>');
                    if (hostFirst) await page.addStyleTag({ path: hostCss });
                    for (const file of fs.readdirSync(cssDirectory).filter(file => file.endsWith('.css'))) {
                        await page.addStyleTag({ path: path.join(cssDirectory, file) });
                    }
                    if (!hostFirst) await page.addStyleTag({ path: hostCss });
                    const visible = () => page.locator('[data-hidden-probe]').evaluateAll(elements => elements.filter(element => element.getBoundingClientRect().height > 0).length);
                    assert.equal(await visible(), 0, `Hidden controls escaped at ${width}, hostFirst=${hostFirst}`);
                    await page.locator('[data-hidden-probe]').evaluateAll(elements => elements.forEach(element => { element.hidden = false; }));
                    assert.equal(await visible(), await page.locator('[data-hidden-probe]').count(), 'Controls must be usable when unhidden');
                    checks += 2;
                } finally { await page.close(); }
            }
        }
        const page = await browser.newPage();
        try {
            await page.setContent('<body class="htt-print-seating"><nav>Menu</nav><section class="htt-seating-result"><h1>Controls</h1><div class="htt-seat-board"><div class="htt-seat">Student</div></div></section></body>');
            await page.addStyleTag({ path: path.join(root, 'teaching-tools/resources/css/seating.css') });
            await page.emulateMedia({ media: 'print' });
            assert.equal(await page.locator('nav').evaluate(e => getComputedStyle(e).visibility), 'hidden');
            assert.equal(await page.locator('.htt-seat').evaluate(e => getComputedStyle(e).visibility), 'visible');
            assert.equal(await page.locator('h1').evaluate(e => getComputedStyle(e).display), 'none');
            assert.equal(await page.locator('.htt-seating-result').evaluate(e => getComputedStyle(e).backgroundColor), 'rgb(255, 255, 255)');
            checks += 4;
            await page.emulateMedia({ media: 'screen' });
            assert.equal(await page.locator('nav').evaluate(e => getComputedStyle(e).visibility), 'visible');
            checks++;
        } finally { await page.close(); }
        console.log(`Addon CSS: ${checks} layout, title contrast, hidden and print checks passed.`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
