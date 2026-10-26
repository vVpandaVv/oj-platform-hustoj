const assert = require('node:assert/strict');
const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { createHost } = require('../../_shared/oj-realtime-hub/src/host');
const { createAdapter } = require('../realtime-server/src/realtime-adapter');
const workspace = path.resolve(__dirname, '../..');
const output = path.join(workspace, '_notes/verification/2026-10-25/teaching-tools');
fs.mkdirSync(output, { recursive: true });
const secret = 'browser-test-secret-'.repeat(4);
let session, origin, relayMuted = false, restFailure = false, websocketEnabled = true, statusMode = 'old';
let stateReads = 0, manualJoins = 0;
let assignedEnabled = false, holdReads = false;
const heldReads = [];
const previewPackets = [];
const pdfContent = '0.96 g 40 40 520 720 re f 0 g BT /F1 18 Tf 70 700 Td (Realtime PDF whiteboard) Tj ET';
const pdfObjects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    `<< /Length ${pdfContent.length} >>\nstream\n${pdfContent}\nendstream`, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
let fixturePdf = '%PDF-1.7\n';
const pdfOffsets = [0];
pdfObjects.forEach((object, i) => { pdfOffsets.push(fixturePdf.length); fixturePdf += `${i + 1} 0 obj\n${object}\nendobj\n`; });
const pdfXref = fixturePdf.length;
fixturePdf += `xref\n0 6\n0000000000 65535 f \n${pdfOffsets.slice(1).map((offset) => `${String(offset).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${pdfXref}\n%%EOF`;
const app = { id: 'teaching-tools', version: require('../realtime-addon.json').version, adapter: 'unused', path: '/classroom-live', secret, origins: [], env: {} };
const hub = createHost({ apps: [app] }, { adapters: { 'teaching-tools': (options) => {
    const adapter = createAdapter(options);
    return { ...adapter, message(connection, raw) {
        const message = JSON.parse(raw);
        if (message.t === 'preview-points' || message.t === 'preview-start') { previewPackets.push(message); }
        if (relayMuted && ['snapshot', 'preview-start', 'preview-points', 'preview-shape'].includes(message.t)) return;
        return adapter.message(connection, raw);
    } };
} } });
function ticket(host) {
    const now = Math.floor(Date.now() / 1000);
    const payload = Buffer.from(JSON.stringify({ v: 1, app: app.id, channel: 'whiteboard', room: 'whiteboard:42', uid: host ? 1 : 2, role: host ? 'host' : 'viewer', iat: now, exp: now + 120 })).toString('base64url');
    return payload + '.' + crypto.createHmac('sha256', secret).update(payload).digest('base64url');
}
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://local');
    if (url.pathname === '/wp-admin/admin-post.php') {
        res.setHeader('Content-Type', 'application/pdf'); res.end(fixturePdf); return;
    }
    if (['/host', '/student', '/settings'].includes(url.pathname)) {
        const settings = url.pathname === '/settings';
        const rendered = spawnSync('D:/php/php.exe', [path.join(__dirname, settings ? 'settings-update.php' : 'render-fixture.php'), settings ? 'render' : url.pathname.slice(1)]);
        assert.equal(rendered.status, 0, rendered.stderr.toString());
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(rendered.stdout); return;
    }
    if (url.pathname === '/other') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end('<html><body><main>Other OJ page</main><script type="application/json" id="htt-global-classroom-config">{"canJoin":true,"currentUserId":2,"restUrl":"/api","nonce":"fixture","joinUrl":"/student"}</script>'
            + '<script src="/teaching-tools/resources/js/activity-navigation.js"></script><script src="/teaching-tools/resources/js/assigned-activities.js"></script></body></html>');
        return;
    }
    if (url.pathname.startsWith('/api/')) {
        let body = '';
        req.on('data', (part) => { body += part; });
        req.on('end', () => {
            const payload = body ? JSON.parse(body) : {};
            let result = { items: [] };
            if (url.pathname === '/api/realtime/status') {
                if (statusMode === 'offline') { res.statusCode = 502; result = { message: '서버 연결 실패' }; }
                else {
                    const outdated = statusMode === 'old';
                    result = { rooms: 1, connections: 2, versions: { verified: true, updateRequired: outdated,
                        module: { installed: outdated ? '1.0.0' : app.version, status: outdated ? 'outdated' : 'current' },
                        core: { installed: '1.0.1', status: 'current' } } };
                }
            } else if (url.pathname === '/api/sessions/assigned') {
                result = { items: assignedEnabled ? [{ joinCode: 'INK123', toolType: 'whiteboard', label: '판서', autoOpen: true }] : [] };
            } else if (url.pathname === '/api/sessions' && req.method === 'POST') {
                session = { joinCode: 'INK123', mode: payload.mode, toolType: 'whiteboard', status: 'active', state: payload.state, role: 'host' };
                result = session;
            } else if (url.pathname.endsWith('/realtime-ticket')) {
                result = websocketEnabled ? { mode: 'websocket', url: `ws://127.0.0.1:${hub.server.address().port}${app.path}`, ticket: ticket(req.headers.referer.includes('/host')) } : { mode: 'poll' };
            } else if (url.pathname.endsWith('/state')) {
                if (restFailure) { res.statusCode = 413; result = { message: '테스트 저장 오류' }; }
                else { session.state = payload.state; result = session; }
            } else if (url.pathname.endsWith('/join')) { manualJoins++; result = session; }
            else if (url.pathname === '/api/sessions/INK123') {
                stateReads++; result = session;
                if (holdReads) { heldReads.push({ res, result: JSON.stringify(result) }); return; }
            }
            res.setHeader('Cache-Control', 'no-store');
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(result));
        }); return;
    }
    const file = path.resolve(workspace, '.' + url.pathname);
    if (!file.startsWith(workspace + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.statusCode = 404; res.end(); return; }
    res.setHeader('Content-Type', path.extname(file) === '.css' ? 'text/css' : 'text/javascript');
    fs.createReadStream(file).pipe(res);
});
async function waitInk(page, y, present = true, timeout = 10000) {
    await page.waitForFunction(({ y, present }) => {
        const canvas = document.querySelector('[data-field="whiteboard-canvas"]');
        const scale = canvas.width / 1000;
        const pixels = canvas.getContext('2d').getImageData(Math.round(190 * scale), Math.round((y - 8) * scale), Math.round(120 * scale), Math.round(16 * scale)).data;
        return pixels.some((value, index) => index % 4 === 3 && value > 0) === present;
    }, { y, present }, { timeout });
}
async function draw(page, y, finish = true) {
    await page.locator('[data-field="canvas"]').scrollIntoViewIfNeeded();
    const box = await page.locator('[data-field="canvas"]').boundingBox();
    await page.mouse.move(box.x + box.width * 0.2, box.y + box.height * y / 600);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.3, box.y + box.height * y / 600, { steps: 8 });
    if (finish) await page.mouse.up();
}
(async () => {
    await new Promise((resolve) => hub.server.listen(0, '127.0.0.1', resolve));
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    app.origins.push(origin);
    const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
    const errors = [];
    try {
        const host = await browser.newPage({ viewport: { width: 1366, height: 1000 } });
        const student = await browser.newPage({ viewport: { width: 390, height: 844 } });
        for (const page of [host, student]) page.on('pageerror', (error) => errors.push(error.message));
        await host.addInitScript(() => {
            const Socket = window.WebSocket;
            window.WebSocket = class extends Socket { constructor(...args) { super(...args); window.testSocket = this; } };
        });
        await host.goto(origin + '/host');
        await host.check('input[name="htt-whiteboard-mode"][value="live"]');
        await host.selectOption('[data-field="material-select"]', 'material:1');
        await host.click('[data-action="start"]');
        await host.waitForFunction(() => document.querySelector('[data-field="connection-status"]').textContent.includes('실시간 연결됨'));
        await student.goto(origin + '/student');
        await student.fill('[data-field="code"]', 'INK123');
        await student.click('[data-action="join"]');
        await student.waitForSelector('[data-field="whiteboard-view"]:visible');
        await student.waitForFunction(() => {
            const canvas = document.querySelector('[data-field="whiteboard-material-frame"]');
            return !canvas.hidden && canvas.getContext('2d').getImageData(canvas.width / 2, canvas.height / 2, 1, 1).data[3] > 0;
        });
        assert.equal(manualJoins, 1, 'Student must actually join using the code');
        await host.evaluate(() => window.testSocket.send(JSON.stringify({ t: 'preview', page: 0, element: { type: 'draw', color: '#111111', width: 4, points: [[200, 50], [300, 50]] } })));
        await waitInk(student, 50);
        await host.evaluate(() => window.testSocket.send(JSON.stringify({ t: 'snapshot', state: { pages: [{ elements: [], background: null }], currentPage: 0 } })));
        await waitInk(student, 50, false);
        const inputChecks = await host.evaluate(() => {
            const canvas = document.querySelector('[data-field="canvas"]');
            const rect = canvas.getBoundingClientRect();
            function fire(type, id, x, y, options = {}) {
                return canvas.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: id, pointerType: 'pen', isPrimary: true, button: 0, buttons: 1,
                    clientX: rect.x + x * rect.width / 1000, clientY: rect.y + y * rect.height / 600, ...options }));
            }
            const down = fire('pointerdown', 501, 100, 100);
            const move = fire('pointermove', 501, 110, 110);
            fire('pointerdown', 502, 900, 500, { isPrimary: false });
            fire('pointermove', 502, 900, 500);
            fire('pointercancel', 502, 0, 0);
            fire('pointermove', 501, 120, 120);
            fire('pointercancel', 501, 0, 0, { clientX: 0, clientY: 0 });
            fire('pointermove', 501, 900, 500, { buttons: 0 });
            fire('pointerdown', 601, 200, 200);
            fire('pointermove', 601, 210, 210);
            fire('lostpointercapture', 601, 0, 0);
            fire('pointerdown', 602, 900, 500, { button: 2 });
            fire('pointermove', 602, 950, 550);
            fire('pointerup', 602, 950, 550);
            fire('pointerdown', 603, 300, 300);
            fire('pointermove', 603, 310, 310);
            fire('pointermove', 603, 950, 550, { buttons: 0 });
            fire('pointerdown', 604, 400, 400);
            fire('pointermove', 604, 410, 410);
            canvas.style.transform = 'translateY(12px)';
            fire('pointermove', 604, 420, 420);
            canvas.style.transform = '';
            fire('pointerdown', 605, 500, 500);
            const coalesced = new PointerEvent('pointermove', { pointerId: 605, buttons: 1, cancelable: true });
            Object.defineProperty(coalesced, 'getCoalescedEvents', { value: () => [
                { clientX: rect.x + 510 * rect.width / 1000, clientY: rect.y + 510 * rect.height / 600 },
                { clientX: rect.x + 520 * rect.width / 1000, clientY: rect.y + 520 * rect.height / 600 },
            ] });
            canvas.dispatchEvent(coalesced);
            fire('pointercancel', 605, 0, 0);
            fire('pointerdown', 606, 990, 590);
            fire('pointermove', 606, 1600, 900);
            fire('pointercancel', 606, 0, 0);
            const drag = canvas.dispatchEvent(new Event('dragstart', { bubbles: true, cancelable: true }));
            const select = canvas.dispatchEvent(new Event('selectstart', { bubbles: true, cancelable: true }));
            return { down, move, drag, select, touch: getComputedStyle(canvas).touchAction };
        });
        assert.deepEqual(inputChecks, { down: false, move: false, drag: false, select: false, touch: 'none' });
        for (let attempt = 0; attempt < 30 && session.state.pages[0].elements.length < 6; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); }
        assert.deepEqual(session.state.pages[0].elements.map((element) => element.points), [
            [[100, 100], [110, 110], [120, 120]], [[200, 200], [210, 210]], [[300, 300], [310, 310]],
            [[400, 400], [410, 410]], [[500, 500], [510, 510], [520, 520]], [[990, 590], [1000, 600]],
        ], 'Pointer isolation, cancel/capture loss, hover, layout changes, coalesced samples and bounds must preserve valid ink');
        await host.click('[data-action="clear"]');
        for (let attempt = 0; attempt < 30 && session.state.pages[0].elements.length; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); }
        const denseState = structuredClone(session.state);
        denseState.revision++;
        denseState.pages[0].elements = Array.from({ length: 100 }, (_, line) => ({ type: 'draw', color: '#111111', width: 4,
            points: Array.from({ length: 100 }, (_, i) => [150 + i * 5, 350 + line * 2 + i % 2]) }));
        await host.evaluate((state) => window.testSocket.send(JSON.stringify({ t: 'snapshot', state })), denseState);
        await waitInk(student, 400);
        // A stalled/coalesced pointer burst must be visible before pointerup, not just in the final snapshot.
        await host.evaluate(() => {
            const canvas = document.querySelector('[data-field="canvas"]'), r = canvas.getBoundingClientRect();
            const event = (type, x, y) => new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 700, pointerType: 'pen', isPrimary: true, button: 0, buttons: 1,
                clientX: r.x + x * r.width / 1000, clientY: r.y + y * r.height / 600 });
            canvas.dispatchEvent(event('pointerdown', 100, 75)); canvas.dispatchEvent(event('pointermove', 110, 75));
        });
        // Observe packets and pixels rather than relying on REST, which may contain an in-progress stroke too.
        for (let attempt = 0; attempt < 30 && previewPackets.at(-1)?.t !== 'preview-start'; attempt++) { await new Promise((resolve) => setTimeout(resolve, 20)); }
        const burstPacketStart = previewPackets.length;
        await student.evaluate(() => {
            window.inkVectorCalls = 0;
            const original = CanvasRenderingContext2D.prototype.lineTo;
            CanvasRenderingContext2D.prototype.lineTo = function (...args) { window.inkVectorCalls++; return original.apply(this, args); };
        });
        await host.evaluate(() => {
            const canvas = document.querySelector('[data-field="canvas"]'), r = canvas.getBoundingClientRect();
            const move = new PointerEvent('pointermove', { pointerId: 700, buttons: 1, cancelable: true });
            Object.defineProperty(move, 'getCoalescedEvents', { value: () => Array.from({ length: 1537 }, (_, i) => ({
                clientX: r.x + (i === 1536 ? 280 : 200 + (i % 10) * 10) * r.width / 1000,
                clientY: r.y + (i === 1536 ? 250 : 150 + (i % 2) * 10) * r.height / 600,
            })) });
            canvas.dispatchEvent(move);
        });
        for (let attempt = 0; attempt < 60 && previewPackets.slice(burstPacketStart).reduce((count, packet) => count + (packet.points?.length || 0), 0) < 1537; attempt++) { await new Promise((resolve) => setTimeout(resolve, 20)); }
        const burstPackets = previewPackets.slice(burstPacketStart);
        assert.equal(burstPackets.reduce((count, packet) => count + (packet.points?.length || 0), 0), 1537);
        assert(burstPackets.every((packet) => packet.points.length <= 512));
        await waitInk(student, 250, true, 2000);
        assert.equal(await host.evaluate(() => Boolean(document.querySelector('[data-field="canvas"]').hasPointerCapture(700))), false, 'Synthetic pen remains down without a native pointer capture');
        const vectors = await student.evaluate(() => window.inkVectorCalls);
        assert(vectors >= 1537 && vectors <= 1540, `Incremental preview must paint all new segments without replaying committed ink (${vectors})`);
        await host.evaluate(() => document.querySelector('[data-field="canvas"]').dispatchEvent(new PointerEvent('pointercancel', { pointerId: 700 })));
        await host.click('[data-action="clear"]');
        await waitInk(student, 155, false);
        for (let attempt = 0; attempt < 30 && session.state.pages[0].elements.length; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); }
        await draw(host, 100, false);
        await waitInk(student, 100, true, 2000);
        assert.equal(session.state.pages[0].elements.length <= 1, true);
        await host.mouse.up();
        await waitInk(student, 100);
        const reload = await browser.newPage();
        await reload.goto(origin + '/student');
        await reload.fill('[data-field="code"]', 'INK123'); await reload.click('[data-action="join"]');
        await waitInk(reload, 100);
        await reload.close();

        await student.click('[data-action="student-fullscreen"]');
        await student.waitForFunction(() => document.fullscreenElement === document.querySelector('[data-htt-join]'));
        assert.equal(await student.locator('[data-action="leave-session"]').isVisible(), true);
        await waitInk(student, 100);
        await student.evaluate(() => window.history.replaceState(null, '', '/student?code=INK123'));
        await student.click('[data-action="leave-session"]');
        await student.waitForSelector('[data-field="form"]:visible');
        await student.waitForFunction(() => !document.fullscreenElement);
        assert.equal(new URL(student.url()).searchParams.has('code'), false);
        assert.equal(session.status, 'active', 'Leaving a student screen must not end the teacher session');
        assignedEnabled = true;
        const joinsAfterExit = manualJoins;
        const readsAfterExit = stateReads;
        await new Promise((resolve) => setTimeout(resolve, 5500));
        assert.equal(manualJoins, joinsAfterExit, 'Assigned auto-open must not rejoin a dismissed session');
        assert.equal(stateReads, readsAfterExit, 'Leaving must stop student state polling');
        await student.reload();
        await student.waitForSelector('[data-field="assigned-sessions"] button');
        assert.equal(await student.locator('[data-field="form"]').isVisible(), true);
        await student.locator('[data-field="assigned-sessions"] button').click();
        await student.waitForSelector('[data-field="view"]:visible');
        await waitInk(student, 100);
        await student.evaluate(() => { document.querySelector('[data-htt-join]').requestFullscreen = () => Promise.reject(new Error('denied')); });
        await student.click('[data-action="student-fullscreen"]');
        await student.waitForSelector('[data-htt-join].is-expanded');
        const studentExpanded = await student.locator('[data-htt-join]').boundingBox();
        assert.equal(studentExpanded.y, 0);
        assert(studentExpanded.height >= 840 && studentExpanded.width <= 390);
        assert.equal(await student.locator('[data-htt-join]').evaluate((element) => element.scrollWidth > element.clientWidth), false);
        assert.equal(await student.locator('[data-action="leave-session"]').isVisible(), true);
        await student.screenshot({ path: path.join(output, 'student-expanded-mobile.png') });
        await student.keyboard.press('Escape');
        await student.waitForSelector('[data-htt-join].is-expanded', { state: 'detached' });

        relayMuted = true;
        const previousReads = stateReads;
        await draw(host, 200);
        await waitInk(student, 200, true, 9000);
        assert(stateReads > previousReads, 'A connected but stale WebSocket must not disable backup reads');
        relayMuted = false;
        await host.click('[data-action="undo"]'); await waitInk(student, 200, false);

        restFailure = true;
        await draw(host, 300);
        await host.waitForFunction(() => document.querySelector('[data-field="connection-status"]').textContent.includes('판서 저장 실패'));
        await waitInk(student, 300);
        await new Promise((resolve) => setTimeout(resolve, 5500));
        await waitInk(student, 300);
        restFailure = false;
        await host.waitForFunction(() => !document.querySelector('[data-field="connection-status"]').textContent.includes('저장 실패'));

        // Unsupported/denied native fullscreen still expands, exits by Escape, and keeps drawing usable.
        await host.evaluate(() => { document.querySelector('[data-field="board"]').requestFullscreen = () => Promise.reject(new Error('permission denied')); });
        await host.click('[data-action="fullscreen"]');
        await host.waitForSelector('.htt-whiteboard-expanded');
        const expanded = await host.locator('[data-field="board"]').boundingBox();
        assert(expanded.width >= 1360 && expanded.height >= 990);
        assert.equal(expanded.y, 0, 'Expanded board must not be pushed below the page header');
        await host.screenshot({ path: path.join(output, 'fullscreen-fallback.png') });
        await host.keyboard.press('Escape'); await host.waitForSelector('.htt-whiteboard-expanded', { state: 'detached' });
        await host.evaluate(() => { delete document.querySelector('[data-field="board"]').requestFullscreen; });
        await host.click('[data-action="fullscreen"]');
        await host.waitForFunction(() => Boolean(document.fullscreenElement));
        await host.click('[data-field="join-info"] > summary');
        await draw(host, 400); await waitInk(student, 400);
        const touch = await host.context().newCDPSession(host);
        await touch.send('Emulation.setTouchEmulationEnabled', { enabled: true });
        const touchBox = await host.locator('[data-field="canvas"]').boundingBox();
        const touchX = touchBox.x + touchBox.width * 0.7;
        const startY = touchBox.y + touchBox.height * 0.1;
        await touch.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: touchX, y: startY }] });
        for (let step = 1; step <= 12; step++) {
            const y = Math.min(995, startY + (touchBox.height * 0.95) * step / 12);
            await touch.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: touchX, y }] });
        }
        await touch.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        assert.equal(await host.evaluate(() => Boolean(document.fullscreenElement)), true, 'Downward finger strokes must not scroll/exit fullscreen');
        assert.equal(await host.evaluate(() => String(window.getSelection())), '');
        await touch.send('Emulation.setTouchEmulationEnabled', { enabled: false });
        await touch.detach();
        await host.screenshot({ path: path.join(output, 'host-touch-stroke-fullscreen.png') });
        const layers = await host.evaluate(() => ['canvas', 'preview-canvas'].map((key) => {
            const r = document.querySelector('[data-field="' + key + '"]').getBoundingClientRect(); return [r.x, r.y, r.width, r.height];
        }));
        layers[0].forEach((value, i) => assert(Math.abs(value - layers[1][i]) < 2, 'Fullscreen ink and shape preview must align'));
        await host.evaluate(() => document.exitFullscreen());

        // Simulate both iPad desktop identification and a touch pan. This is not an iPad hardware test.
        for (const page of [host, student]) {
            await page.evaluate(() => {
                Object.defineProperty(navigator, 'platform', { configurable: true, value: 'MacIntel' });
                Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: 5 });
                const target = document.querySelector('[data-field="board"]') || document.querySelector('[data-htt-join]');
                window.nativeFullscreenRequests = 0;
                target.requestFullscreen = () => { window.nativeFullscreenRequests++; return Promise.reject(new Error('should not call native fullscreen on iPad')); };
            });
        }
        await host.click('[data-action="fullscreen"]'); await host.waitForSelector('.htt-whiteboard-expanded');
        await student.click('[data-action="student-fullscreen"]'); await student.waitForSelector('[data-htt-join].is-expanded');
        const ipadTouch = await host.context().newCDPSession(host);
        await ipadTouch.send('Emulation.setTouchEmulationEnabled', { enabled: true });
        const ipadBox = await host.locator('[data-field="canvas"]').boundingBox();
        const ipadX = ipadBox.x + ipadBox.width * 0.8;
        await ipadTouch.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: ipadX, y: ipadBox.y + ipadBox.height * 0.1 }] });
        for (let i = 1; i <= 12; i++) {
            await ipadTouch.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: ipadX, y: ipadBox.y + ipadBox.height * (0.1 + 0.8 * i / 12) }] });
        }
        await ipadTouch.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await ipadTouch.send('Emulation.setTouchEmulationEnabled', { enabled: false }); await ipadTouch.detach();
        assert.equal(await host.evaluate(() => window.nativeFullscreenRequests), 0);
        assert.equal(await student.evaluate(() => window.nativeFullscreenRequests), 0);
        assert.equal(await host.locator('.htt-whiteboard-expanded').count(), 1);
        assert.equal(await host.locator('[data-action="fullscreen"]').isVisible(), true);
        await host.screenshot({ path: path.join(output, 'ipad-window-expansion-touch.png') });
        await host.click('[data-action="fullscreen"]'); await student.click('[data-action="student-fullscreen"]');
        for (const page of [host, student]) {
            await page.evaluate(() => { delete navigator.platform; delete navigator.maxTouchPoints; });
        }

        const rendering = await student.evaluate(() => {
            const render = window.HttWhiteboardRender, canvas = document.createElement('canvas');
            canvas.width = 3000; canvas.height = 1800;
            const ctx = canvas.getContext('2d');
            const elements = Array.from({ length: 200 }, (_, line) => ({ type: 'draw', color: '#111111', width: 4,
                points: Array.from({ length: 300 }, (_, i) => [100 + i * 2, 50 + line * 2 + i % 2]) }));
            const active = { type: 'draw', color: '#123456', width: 4, points: [[10, 10], [20, 20]] };
            const layer = render.createLiveLayer(canvas, {}); layer.setBase(elements); layer.preview(active, 'bench');
            let start = performance.now();
            for (let i = 0; i < 20; i++) { render.redrawElements(ctx, canvas, elements.concat([active]), {}, () => {}); }
            const fullRedrawMs = performance.now() - start;
            layer.setBase(elements); layer.preview(active, 'bench'); start = performance.now();
            for (let i = 0; i < 20; i++) { active.points.push([30 + i * 2, 40]); layer.preview(active, 'bench'); }
            return { fullRedrawMs, incrementalMs: performance.now() - start, frames: 20, committedPoints: 60000, raster: [canvas.width, canvas.height] };
        });
        fs.writeFileSync(path.join(output, 'ink-render-performance.json'), JSON.stringify(rendering, null, 2) + '\n');
        console.log('Rendering microbenchmark (not network/iPad latency):', JSON.stringify(rendering));

        websocketEnabled = false;
        for (const socket of hub.apps.get(app.id).connections) socket.close();
        await host.waitForFunction(() => document.querySelector('[data-field="connection-status"]').textContent.includes('자동 새로고침'));
        await draw(host, 500); await waitInk(student, 500, true, 4000);
        await student.screenshot({ path: path.join(output, 'student-code-ink-mobile.png') });
        holdReads = true;
        for (let attempt = 0; attempt < 50 && !heldReads.length; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); }
        assert(heldReads.length > 0, 'Create an in-flight state read before leaving');
        await student.click('[data-action="student-fullscreen"]');
        await student.waitForSelector('[data-htt-join].is-expanded');
        await student.click('[data-action="leave-session"]');
        for (const pending of heldReads.splice(0)) { pending.res.setHeader('Content-Type', 'application/json'); pending.res.end(pending.result); }
        holdReads = false;
        await student.waitForSelector('[data-field="form"]:visible');
        assert.equal(await student.locator('[data-field="view"]').isVisible(), false);
        assert.equal(await student.locator('[data-htt-join]').evaluate((element) => element.classList.contains('is-expanded')), false);
        await student.screenshot({ path: path.join(output, 'student-left-mobile.png') });
        const joinsBeforeOtherPage = manualJoins;
        await student.goto(origin + '/other');
        await student.waitForSelector('.htt-assigned-activities a');
        await new Promise((resolve) => setTimeout(resolve, 500));
        assert.equal(manualJoins, joinsBeforeOtherPage, 'Other OJ pages must not automatically rejoin a lesson the student left');
        assert.equal(new URL(student.url()).pathname, '/other');

        const settings = await browser.newPage();
        settings.on('pageerror', (error) => errors.push(error.message));
        await settings.goto(origin + '/settings');
        await settings.waitForFunction(() => document.querySelector('[data-htt-version="module"]').textContent.includes('업데이트 필요'));
        assert.equal(await settings.locator('[data-htt-update-needed]').isVisible(), true);
        await settings.screenshot({ path: path.join(output, 'realtime-versions.png') });
        statusMode = 'current'; await settings.click('[data-htt-realtime-status]');
        await settings.waitForFunction(() => document.querySelector('[data-htt-version="module"]').textContent.includes('일치'));
        assert.equal(await settings.locator('[data-htt-update-needed]').isVisible(), false);
        statusMode = 'offline'; await settings.click('[data-htt-realtime-status]');
        await settings.waitForFunction(() => document.querySelector('[data-htt-version="module"]').textContent.includes('알 수 없음'));
        assert.deepEqual(errors, []);
        console.log('Real server browser checks passed: pointer isolation/cancel/capture loss/layout/coalesced samples/bounds, downward touch fullscreen; code join/live ink/recovery, student leave/fullscreen and version UI.');
    } finally { await browser.close(); await hub.close(); await new Promise((resolve) => server.close(resolve)); }
})().catch(async (error) => { console.error(error); await hub.close(); server.close(); process.exitCode = 1; });
