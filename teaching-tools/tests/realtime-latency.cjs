// Measure the actual ticket controller and clients, including stale-relay recovery.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { createHost } = require('../../_shared/oj-realtime-hub/src/host');
const { createAdapter } = require('../realtime-server/src/realtime-adapter');
const workspace = path.resolve(__dirname, '../..');
const output = path.join(workspace, '_notes/verification/2026-10-25/teaching-tools');
const fixture = spawnSync('D:/php/php.exe', [path.join(__dirname, 'realtime-ticket-fixture.php')]);
assert.equal(fixture.status, 0, fixture.stderr.toString());
const issued = JSON.parse(fixture.stdout);
const claims = (info) => JSON.parse(Buffer.from(info.ticket.split('.')[0], 'base64url'));
let origin, session, ownerView = false, relayMuted = false;
const packets = [], connections = [], closes = [], stateReads = [];
const app = { id: 'teaching-tools', version: require('../realtime-addon.json').version, adapter: 'unused', path: '/classroom-live', secret: 'diagnostic-only-'.repeat(4), origins: [], env: {} };
const hub = createHost({ apps: [app] }, { adapters: { 'teaching-tools': (options) => {
    const adapter = createAdapter(options);
    return { ...adapter,
        connect(socket, metadata) {
            socket.on('close', (code, reason) => closes.push({ at: Date.now(), code, reason: reason.toString() }));
            return adapter.connect(socket, metadata);
        },
        message(connection, raw) {
            const message = JSON.parse(raw);
            if (message.t === 'hello') { connections.push({ at: Date.now(), ...claims({ ticket: message.ticket }) }); }
            if (['snapshot', 'preview-start', 'preview-points', 'preview-shape'].includes(message.t)) {
                packets.push({ at: Date.now(), t: message.t, points: message.points?.length || message.element?.points?.length || 0 });
                if (relayMuted) { return; }
            }
            return adapter.message(connection, raw);
        },
    };
} } });
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://local');
    if (['/host', '/student'].includes(url.pathname)) {
        const rendered = spawnSync('D:/php/php.exe', [path.join(__dirname, 'render-fixture.php'), url.pathname.slice(1)]);
        assert.equal(rendered.status, 0, rendered.stderr.toString());
        res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(rendered.stdout); return;
    }
    if (url.pathname.startsWith('/api/')) {
        let body = '';
        req.on('data', (part) => { body += part; });
        req.on('end', () => {
            const payload = body ? JSON.parse(body) : {};
            let result = { items: [] };
            if (url.pathname === '/api/sessions' && req.method === 'POST') {
                session = { joinCode: 'INK123', mode: payload.mode, toolType: 'whiteboard', status: 'active', state: payload.state, role: 'host' }; result = session;
            } else if (url.pathname.endsWith('/realtime-ticket')) {
                const host = new URL(req.headers.referer).pathname === '/host';
                if (!host) { assert.equal(payload.role, 'viewer', 'Participation must explicitly request a read-only ticket'); }
                const info = host ? issued.teacher : (ownerView ? issued.ownerView : issued.student);
                result = { ...info, url: `ws://127.0.0.1:${hub.server.address().port}${app.path}` };
            } else if (url.pathname.endsWith('/state')) { session.state = payload.state; result = session; }
            else if (url.pathname.endsWith('/join')) { result = session; }
            else if (url.pathname === '/api/sessions/INK123') { stateReads.push(Date.now()); result = session; }
            res.setHeader('Cache-Control', 'no-store'); res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(result));
        }); return;
    }
    const file = path.resolve(workspace, '.' + url.pathname);
    if (!file.startsWith(workspace + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.statusCode = 404; res.end(); return; }
    res.setHeader('Content-Type', path.extname(file) === '.css' ? 'text/css' : 'text/javascript'); fs.createReadStream(file).pipe(res);
});
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function join(page) {
    await page.goto(origin + '/student');
    await page.evaluate(() => {
        window.receivedInk = [];
        const connect = window.HttRealtime.connect;
        window.HttRealtime.connect = (options) => connect({ ...options, onMessage(message) {
            if (['preview', 'preview-start', 'preview-points', 'snapshot'].includes(message.t)) {
                const item = { t: message.t, received: Date.now(), painted: null };
                window.receivedInk.push(item);
                options.onMessage(message);
                requestAnimationFrame(() => { item.painted = Date.now(); });
            } else { options.onMessage(message); }
        } });
    });
    await page.fill('[data-field="code"]', 'INK123'); await page.click('[data-action="join"]');
    await page.waitForSelector('[data-field="whiteboard-view"]:visible');
}
async function stroke(page, y, steps = 12) {
    await page.locator('[data-field="canvas"]').scrollIntoViewIfNeeded();
    const box = await page.locator('[data-field="canvas"]').boundingBox();
    await page.mouse.move(box.x + box.width * 0.2, box.y + box.height * y / 600); await page.mouse.down();
    const started = Date.now();
    for (let i = 1; i <= steps; i++) {
        await page.mouse.move(box.x + box.width * (0.2 + 0.15 * i / steps), box.y + box.height * y / 600); await pause(40);
    }
    return started;
}
async function inkPresent(page, y) {
    return page.evaluate((y) => {
        const canvas = document.querySelector('[data-field="whiteboard-canvas"]'), scale = canvas.width / 1000;
        return canvas.getContext('2d').getImageData(210 * scale, (y - 4) * scale, 120 * scale, 8 * scale).data.some((v, i) => i % 4 === 3 && v > 0);
    }, y);
}
async function run() {
    let edge, chrome;
    try {
        await new Promise((resolve) => hub.server.listen(0, '127.0.0.1', resolve));
        await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
        origin = `http://127.0.0.1:${server.address().port}`; app.origins.push(origin);
        edge = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
        chrome = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
        const host = await edge.newPage({ viewport: { width: 1366, height: 1000 } });
        const student = await chrome.newPage({ viewport: { width: 1366, height: 1000 } });
        await host.addInitScript(() => {
            window.sentInk = [];
            const send = WebSocket.prototype.send;
            WebSocket.prototype.send = function (raw) {
                const message = JSON.parse(raw);
                if (['preview-start', 'preview-points'].includes(message.t)) { window.sentInk.push({ t: message.t, sent: Date.now() }); }
                return send.call(this, raw);
            };
        });
        await host.goto(origin + '/host'); await host.check('input[name="htt-whiteboard-mode"][value="live"]'); await host.click('[data-action="start"]');
        await host.waitForFunction(() => document.querySelector('[data-field="connection-status"]').textContent.includes('실시간 연결됨'));
        await join(student); await pause(300);
        await host.evaluate(() => { window.sentInk = []; }); await student.evaluate(() => { window.receivedInk = []; });
        await stroke(host, 120); assert(await inkPresent(student, 120), 'Healthy live ink must arrive before pointerup');
        await host.mouse.up(); await pause(100);
        const sent = await host.evaluate(() => window.sentInk);
        const received = await student.evaluate(() => window.receivedInk.filter((message) => message.t !== 'snapshot'));
        const timings = sent.map((message, i) => received[i] && ({ transportMs: received[i].received - message.sent, toPaintCallbackMs: received[i].painted - message.sent })).filter(Boolean);
        assert(timings.length >= 5);
        const normal = { packets: timings.length, transportMs: timings.map((x) => x.transportMs), toPaintCallbackMs: timings.map((x) => x.toPaintCallbackMs) };

        // Leave sockets healthy but drop annotation frames at the server to expose the 5s recovery path.
        const lastRead = stateReads.length;
        for (let i = 0; i < 130 && stateReads.length === lastRead; i++) { await pause(50); }
        relayMuted = true;
        const staleStart = await stroke(host, 240, 2); await host.mouse.up();
        let staleDelay;
        for (let i = 0; i < 160; i++) { if (await inkPresent(student, 240)) { staleDelay = Date.now() - staleStart; break; } await pause(50); }
        assert(staleDelay !== undefined, 'REST backup must eventually recover');
        const stale = { elapsedMs: staleDelay, studentStatus: await student.locator('[data-field="whiteboard-connection"]').textContent() };
        relayMuted = false;

        ownerView = true;
        const firstClose = closes.length, firstConnection = connections.length;
        await join(student); await pause(3200);
        await student.evaluate(() => { window.receivedInk = []; });
        await stroke(host, 480, 3); await host.mouse.up(); await pause(100);
        const collision = { replacements: closes.slice(firstClose).filter((item) => item.code === 4000).length,
            newConnections: connections.length - firstConnection,
            studentPreviewFrames: await student.evaluate(() => window.receivedInk.filter((item) => item.t.startsWith('preview')).length),
            teacherStatus: await host.locator('[data-field="connection-status"]').textContent(),
            studentStatus: await student.locator('[data-field="whiteboard-connection"]').textContent() };
        assert.equal(claims(issued.ownerView).role, 'viewer');
        assert.equal(collision.replacements, 0, 'Owner participation must not replace the teaching socket');
        assert(collision.studentPreviewFrames > 0 && await inkPresent(student, 480));
        assert.equal(issued.forbiddenStatus, 403); assert.equal(issued.invalidStatus, 400);
        const version = fs.readFileSync(path.join(__dirname, '../teaching-tools.php'), 'utf8').match(/ \* Version: ([^\r\n]+)/)[1];
        const report = { plugin: version, engines: { edge: edge.version(), chrome: chrome.version() }, headless: true,
            rolesFromActualPhpController: { teacher: claims(issued.teacher).role, ownerParticipation: claims(issued.ownerView).role, studentParticipation: claims(issued.student).role },
            actualPhpTicketLimit: issued.rateLimit, normal, connectedButRelayMuted: stale, sameOwnerAccount: collision,
            limits: 'Loopback and PHP/WordPress stubs, not the production site. Playwright headless does not reproduce normal OS window occlusion. Paint timing is a callback proxy, not display scanout.' };
        fs.mkdirSync(output, { recursive: true }); fs.writeFileSync(path.join(output, 'realtime-latency.json'), JSON.stringify(report, null, 2) + '\n');
        console.log(JSON.stringify(report, null, 2));
    } finally {
        if (edge) { await edge.close(); } if (chrome) { await chrome.close(); }
        await hub.close(); await new Promise((resolve) => server.close(resolve));
    }
}
run().catch((error) => { console.error(error); process.exitCode = 1; });
