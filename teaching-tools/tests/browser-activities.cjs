const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const workspace = path.resolve(__dirname, '../..');
const output = path.join(workspace, '_notes/verification/2026-10-25/teaching-tools');
fs.mkdirSync(output, { recursive: true });
const pdfObjects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>'];
for (const color of ['1 0 0', '0 0 1']) {
    const content = `${color} rg 40 40 500 700 re f\n0 0 0 rg BT /F1 18 Tf 70 700 Td (PDF page - readable text) Tj 0 -28 Td /F1 10 Tf (Zoom quality and ink alignment) Tj ET`;
    const pageId = pdfObjects.length + 1;
    pdfObjects.push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 7 0 R >> >> /Contents ${pageId + 1} 0 R >>`);
    pdfObjects.push(`<< /Length ${Buffer.byteLength(content)} >>\nstream\n${content}\nendstream`);
}
pdfObjects.push('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
let fixturePdf = '%PDF-1.7\n';
const offsets = [0];
pdfObjects.forEach((object, index) => { offsets.push(Buffer.byteLength(fixturePdf)); fixturePdf += `${index + 1} 0 obj\n${object}\nendobj\n`; });
const xref = Buffer.byteLength(fixturePdf);
fixturePdf += `xref\n0 ${offsets.length}\n0000000000 65535 f \n` + offsets.slice(1).map((offset) => `${String(offset).padStart(10, '0')} 00000 n \n`).join('');
fixturePdf += `trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
const sessions = new Map();
const backgroundImages = new Map();
let lastPayload;
const joins = [];
const materialUrl = (id) => `/wp-admin/admin-post.php?action=learning_materials_view&material_id=${id}`;
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/host' || url.pathname === '/student') {
        const rendered = spawnSync(process.env.PHP_BINARY || 'D:/php/php.exe', [path.join(__dirname, 'render-fixture.php'), url.pathname === '/host' ? 'host' : 'student']);
        res.setHeader('Set-Cookie', ['material_role=allowed; Path=/; HttpOnly; SameSite=Lax', 'classroom_member=allowed; Path=/; HttpOnly; SameSite=Lax']);
        res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(rendered.stdout); return;
    }
    if (url.pathname === '/wp-admin/admin-post.php') {
        const role = (req.headers.cookie || '').includes('material_role=allowed') ? 'allowed' : 'denied';
        const result = spawnSync(process.env.PHP_BINARY || 'D:/php/php.exe', [path.join(__dirname, 'material-stream-fixture.php'), Buffer.from(fixturePdf).toString('base64'), role, url.searchParams.get('material_id'), req.headers.range || '']);
        const stream = JSON.parse(result.stdout.toString());
        res.statusCode = stream.status;
        res.setHeader('Content-Type', stream.status === 200 || stream.status === 206 ? 'application/pdf' : 'text/plain');
        res.setHeader('Cache-Control', 'private, no-store');
        res.setHeader('Content-Security-Policy', "default-src 'none'");
        res.end(Buffer.from(stream.body, 'base64')); return;
    }
    if (url.pathname.startsWith('/classroom-background/')) {
        const image = backgroundImages.get(url.pathname), session = sessions.get(image?.code);
        if (!image || session?.state.materialSharing !== 'session' || session.status !== 'active' || !(req.headers.cookie || '').includes('classroom_member=allowed')) { res.statusCode = 404; res.end(); return; }
        res.setHeader('Content-Type', image.type); res.setHeader('Cache-Control', 'private, no-store'); res.end(image.bytes); return;
    }
    if (url.pathname === '/other' || url.pathname === '/signals') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end('<html><head><link rel="stylesheet" href="/teaching-tools/resources/css/global-classroom.css"></head><body><main>문제 풀이 화면</main><script type="application/json" id="htt-global-classroom-config">{"canJoin":true,"currentUserId":2,"restUrl":"/api","nonce":"fixture","joinUrl":"/student"}</script>'
            + '<script src="/teaching-tools/resources/js/discovery-client.js"></script><script src="/teaching-tools/resources/js/activity-navigation.js"></script>'
            + (url.pathname === '/signals' ? '<script src="/teaching-tools/resources/js/global-classroom.js"></script>' : '')
            + (url.searchParams.has('late') ? '' : '<script src="/teaching-tools/resources/js/assigned-activities.js"></script>') + '</body></html>'); return;
    }
    if (url.pathname.startsWith('/api/')) {
        const chunks = [];
        req.on('data', (chunk) => { chunks.push(chunk); });
        req.on('end', async () => {
            const body = Buffer.concat(chunks);
            if (url.pathname.endsWith('/whiteboard-image')) {
                const form = await new Response(body, { headers: { 'Content-Type': req.headers['content-type'] } }).formData();
                const code = url.pathname.split('/')[3], session = sessions.get(code);
                assert.equal(form.get('purpose'), 'background'); assert.equal(session.state.materialSharing, 'session');
                const file = form.get('image'), target = '/classroom-background/' + backgroundImages.size;
                backgroundImages.set(target, { code, type: file.type, bytes: Buffer.from(await file.arrayBuffer()) });
                res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ url: target, width: 2000, height: 1200 })); return;
            }
            const payload = body.length ? JSON.parse(body.toString()) : {};
            let result = {};
            if (url.pathname === '/api/contexts') { result = { items: [{ type: 'group', id: '5', revision: 'r1', label: '3반' }] }; }
            else if (url.pathname === '/api/classroom/active') {
                result = { active: true, sessions: [{ joinCode: 'CLAS12', role: 'participant', label: '3반' }] };
            }
            else if (url.pathname === '/api/integrations/activities') { result = { items: [{ key: 'material:1', title: '수업 자료 A', url: materialUrl(1), contentType: 'application/pdf' }, { key: 'material:2', title: '수업 자료 B', url: materialUrl(2), contentType: 'application/pdf' }, { key: 'material:3', title: '권한이 회수된 자료', url: materialUrl(3), contentType: 'application/pdf' }] }; }
            else if (url.pathname === '/api/sessions' && req.method === 'POST') {
                lastPayload = payload;
                result = { joinCode: 'ABCD12', toolType: payload.toolType, mode: payload.mode, role: 'host', status: 'active', state: payload.state, autoOpen: payload.autoOpen === true };
                sessions.set('ABCD12', result);
            } else if (url.pathname === '/api/sessions/assigned' || url.pathname === '/api/discovery') {
                result = { items: [...sessions.values()].reverse().map((session) => ({ joinCode: session.joinCode, toolType: session.toolType, label: session.toolType === 'quiz' ? '빠른 응답' : '판서', contextLabel: '3반', autoOpen: session.autoOpen === true })) };
                if (url.pathname === '/api/discovery') {
                    result.classroom = sessions.has('CLAS12')
                        ? { active: true, sessions: [{ joinCode: 'CLAS12', role: 'participant', label: '3반' }] }
                        : { active: false };
                }
            } else {
                const parts = url.pathname.split('/');
                const session = sessions.get(parts[3]);
                if (session && parts[4] === 'join') { joins.push({ code: parts[3], source: req.headers.referer }); }
                if (session && parts[4] === 'participant-state' && Object.hasOwn(payload, 'signal')) { session.state.mySignal = payload.signal; }
                if (session && parts[4] === 'state') { session.state = payload.state; }
                if (session && parts[4] === 'end') { session.status = 'ended'; }
                result = session ? { ...session, role: 'participant' } : { message: 'no session' };
            }
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(result));
        });
        return;
    }
    const file = path.resolve(workspace, '.' + url.pathname);
    if (!file.startsWith(workspace + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.statusCode = 404; res.end(); return; }
    const extension = path.extname(file);
    // Emulate common servers that have no MIME mapping for MJS modules.
    res.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.wasm': 'application/wasm' })[extension] || 'application/octet-stream');
    fs.createReadStream(file).pipe(res);
});

(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) });
    const errors = [];
    try {
        const page = await browser.newPage({ viewport: { width: 1366, height: 1000 } });
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(origin + '/host');
        await page.route('**/build/pdf.js?*', (route) => route.fulfill({ status: 503, body: 'temporary asset failure' }));
        await page.selectOption('[data-field="material-select"]', 'material:1');
        await page.click('[data-action="preview-initial-material"]');
        await page.waitForSelector('[data-field="material-dialog"]:visible');
        await page.waitForFunction(() => document.querySelector('[data-field="pdf-status"]').textContent.includes('표시 모듈'));
        await page.click('[data-action="close-material"]');
        await page.unroute('**/build/pdf.js?*');
        await page.click('[data-action="preview-initial-material"]');
        await page.waitForFunction(() => document.querySelector('[data-action="apply-material"]').disabled === false);
        await page.click('[data-action="pdf-next"]');
        await page.waitForFunction(() => document.querySelector('[data-field="pdf-status"]').textContent.includes('2쪽'));
        await page.screenshot({ path: path.join(output, 'initial-pdf-preview.png') });
        await page.click('[data-action="close-material"]');
        await page.click('[data-action="preview-initial-material"]');
        await page.waitForFunction(() => document.querySelector('[data-action="apply-material"]').disabled === false);
        await page.click('[data-action="apply-material"]');
        await page.selectOption('[data-field="audience-mode"]', 'context');
        await page.selectOption('[data-field="audience-context"]', { label: '3반' });
        assert.equal(await page.locator('[data-field="audience-auto-open"]').isChecked(), false);
        await page.check('[data-field="audience-auto-open"]');
        assert.equal(await page.evaluate(() => window.HttAudience.prepare(document.querySelector('[data-htt-host]'), '/sessions', 'POST', { toolType: 'whiteboard' }).autoOpen), true);
        await page.uncheck('[data-field="audience-auto-open"]');
        await page.click('[data-action="start"]');
        await page.waitForSelector('[data-field="board"]:not([hidden])');
        assert.equal(lastPayload.mode, 'live');
        assert.equal(lastPayload.context.id, '5');
        assert.equal(lastPayload.autoOpen, false);
        assert.deepEqual(lastPayload.state.pages.map((page) => page.background.page), [1, 2], 'Starting from a PDF must create all document pages');
        await page.waitForSelector('[data-htt-join-qr] canvas');
        const expandedHeight = await page.locator('[data-field="join-info"]').evaluate((element) => element.getBoundingClientRect().height);
        await page.click('[data-field="join-info"] > summary');
        assert.equal(await page.locator('[data-field="join-code"]').isVisible(), false);
        assert.equal(await page.locator('[data-htt-join-qr]').isVisible(), false);
        const collapsedHeight = await page.locator('[data-field="join-info"]').evaluate((element) => element.getBoundingClientRect().height);
        assert(expandedHeight - collapsedHeight > 150, 'Collapsing the code and QR must free drawing space');
        await page.click('[data-field="join-info"] > summary');
        assert.equal(await page.locator('[data-field="join-code"]').textContent(), 'ABCD12');
        assert.equal(await page.locator('[data-htt-join-qr]').isVisible(), true);
        await page.click('[data-field="join-info"] > summary');
        await page.locator('[data-field="join-info"] > summary').press('Enter');
        assert.equal(await page.locator('[data-htt-join-qr]').isVisible(), true);
        await page.locator('[data-field="join-info"] > summary').press('Enter');
        assert.equal(await page.locator('[data-field="join-code"]').isVisible(), false);
        await page.click('[data-action="next-page"]');
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('PDF 2쪽'));
        assert.equal(await page.locator('[data-field="material-dialog"]').isVisible(), false);
        await page.click('[data-action="add-page"]');
        await page.selectOption('[data-field="pdf-material"]', 'material:1');
        await page.waitForFunction(() => document.querySelector('[data-action="insert-material"]').disabled === false);
        await page.click('[data-action="pdf-next"]');
        await page.waitForFunction(() => document.querySelector('[data-field="pdf-status"]').textContent.includes('2쪽'));
        await page.screenshot({ path: path.join(output, 'pdf-picker-desktop.png') });
        await page.click('[data-action="insert-material"]');
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('PDF 1쪽'));
        await page.click('[data-action="next-page"]');
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('PDF 2쪽'));
        await page.waitForFunction(() => {
            const pixel = document.querySelector('[data-field="material-frame"]').getContext('2d').getImageData(500, 300, 1, 1).data;
            return pixel[2] > 200 && pixel[0] < 40;
        });
        const drawing = await page.locator('[data-field="canvas"]').boundingBox();
        await page.mouse.move(drawing.x + 50, drawing.y + 50);
        await page.mouse.down(); await page.mouse.move(drawing.x + 120, drawing.y + 70); await page.mouse.up();
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('4 / 4'));
        await page.click('[data-action="choose-material"]');
        await page.selectOption('[data-field="pdf-import-mode"]', 'single');
        await page.click('[data-action="refresh-materials"]');
        await page.getByRole('option', { name: '권한이 회수된 자료' }).waitFor({ state: 'attached' });
        await page.selectOption('[data-field="pdf-material"]', 'material:3');
        await page.waitForFunction(() => document.querySelector('[data-field="pdf-status"]').textContent.includes('접근할 수 없습니다'));
        assert.equal(await page.locator('[data-action="apply-material"]').isDisabled(), true);
        await page.selectOption('[data-field="pdf-material"]', 'material:2');
        await page.waitForFunction(() => document.querySelector('[data-action="apply-material"]').disabled === false);
        await page.click('[data-action="apply-material"]');
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('PDF 1쪽'));
        await page.click('[data-action="prev-page"]');
        assert.equal(await page.locator('[data-field="canvas"]').evaluate((canvas) => canvas.getContext('2d').getImageData(50, 50, 1, 1).data[3]), 0, 'Annotations must not leak into another PDF page');
        await page.click('[data-action="next-page"]');
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('PDF 1쪽'));
        const downloadPromise = page.waitForEvent('download');
        await page.click('[data-action="save-image"]');
        const download = await downloadPromise;
        assert.equal(download.suggestedFilename(), 'whiteboard.png');
        const annotationPixels = await page.locator('[data-field="canvas"]').evaluate((canvas) => {
            const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
            let count = 0;
            for (let i = 3; i < pixels.length; i += 4) { if (pixels[i]) count++; }
            return count;
        });
        assert(annotationPixels > 10, 'Changing PDF backgrounds and navigating pages must preserve annotations');
        await page.screenshot({ path: path.join(output, 'whiteboard-desktop.png') });
        await page.click('[data-action="fullscreen"]');
        await page.waitForFunction(() => Boolean(document.fullscreenElement));
        assert.equal(await page.locator('[data-field="join-code"]').isVisible(), false);
        await page.click('[data-field="join-info"] > summary');
        assert.equal(await page.locator('[data-htt-join-qr]').isVisible(), true);
        await page.click('[data-field="join-info"] > summary');
        await page.click('[data-action="choose-material"]');
        await page.waitForSelector('[data-field="material-dialog"]:visible');
        await page.waitForFunction(() => document.querySelector('[data-action="apply-material"]').disabled === false);
        await page.click('[data-action="close-material"]');
        await page.evaluate(() => document.exitFullscreen());
        const other = await browser.newPage();
        other.on('pageerror', (error) => errors.push(error.message));
        await other.goto(origin + '/other?late=1');
        const autoJoin = other.waitForResponse((response) => response.url().endsWith('/ABCD12/join'));
        await other.addScriptTag({ url: origin + '/teaching-tools/resources/js/assigned-activities.js' });
        await autoJoin;
        await other.waitForSelector('.htt-assigned-activities a');
        assert(joins.some((join) => join.code === 'ABCD12' && join.source.includes('/other')), 'Assigned students must actually join from other OJ pages without a code');
        assert.equal(new URL(other.url()).pathname, '/other', 'Disabled auto-open must preserve the current page');
        sessions.set('AUTO11', { ...sessions.get('ABCD12'), joinCode: 'AUTO11', autoOpen: true });
        sessions.set('AUTO12', { ...sessions.get('ABCD12'), joinCode: 'AUTO12', autoOpen: true });
        await other.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await other.waitForURL('**/student?code=AUTO12');
        await other.waitForSelector('[data-field="whiteboard-view"]:not([hidden])');
        await other.goto(origin + '/other');
        await other.waitForSelector('.htt-assigned-activities a');
        assert.equal(new URL(other.url()).pathname, '/other', 'An opened activity must not pull the student back or reopen older activities');
        await other.reload();
        await other.waitForSelector('.htt-assigned-activities a');
        assert.equal(new URL(other.url()).pathname, '/other');
        await other.close();
        sessions.delete('AUTO11'); sessions.delete('AUTO12');
        const student = await browser.newPage({ viewport: { width: 390, height: 844 } });
        student.on('pageerror', (error) => errors.push(error.message));
        await student.goto(origin + '/student');
        await student.waitForSelector('[data-field="whiteboard-view"]:not([hidden])');
        assert.equal(await student.locator('[data-field="classroom-view"]').isVisible(), false);
        await student.waitForFunction(() => {
            const canvas = document.querySelector('[data-field="whiteboard-material-frame"]');
            const pixel = canvas.getContext('2d').getImageData(canvas.width / 2, canvas.height / 2, 1, 1).data;
            return pixel[0] > 200 && pixel[2] < 40;
        });
        await student.waitForFunction(() => {
            const viewport = document.querySelector('[data-field="whiteboard-viewport"]');
            const surface = document.querySelector('.htt-join-whiteboard-surface').getBoundingClientRect();
            return surface.width * 0.45 > viewport.clientWidth * 0.9;
        });
        const unchangedState = JSON.stringify(sessions.get('ABCD12').state);
        const fitWidth = await student.locator('.htt-join-whiteboard-surface').evaluate((el) => el.getBoundingClientRect().width);
        for (let step = 0; step < 4; step++) { await student.click('[data-action="whiteboard-zoom-in"]'); }
        await student.waitForFunction(() => document.querySelector('[data-field="whiteboard-zoom-value"]').textContent === '200%'
            && document.querySelector('[data-field="whiteboard-material-frame"]').width >= 2000);
        assert((await student.locator('.htt-join-whiteboard-surface').evaluate((el) => el.getBoundingClientRect().width)) > fitWidth * 1.9);
        const viewportBox = await student.locator('[data-field="whiteboard-viewport"]').boundingBox();
        const scrollBefore = await student.locator('[data-field="whiteboard-viewport"]').evaluate((el) => el.scrollTop);
        await student.mouse.move(viewportBox.x + viewportBox.width / 2, viewportBox.y + viewportBox.height / 2);
        await student.mouse.down();
        await student.mouse.move(viewportBox.x + viewportBox.width / 2 - 35, viewportBox.y + viewportBox.height / 2 - 60);
        await student.mouse.up();
        assert((await student.locator('[data-field="whiteboard-viewport"]').evaluate((el) => el.scrollTop)) > scrollBefore + 30, 'Enlarged PDF must pan with the mouse');
        await student.keyboard.down('Control'); await student.mouse.wheel(0, -100); await student.keyboard.up('Control');
        await student.waitForFunction(() => document.querySelector('[data-field="whiteboard-zoom-value"]').textContent === '225%');
        await student.locator('[data-field="whiteboard-viewport"]').evaluate((el) => {
            const scale = el.querySelector('.htt-join-whiteboard-surface').getBoundingClientRect().width / 1000;
            el.scrollTop = 0; el.scrollLeft = 275 * scale;
        });
        await student.screenshot({ path: path.join(output, 'student-pdf-zoom-mobile.png') });
        assert.equal(await student.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Zoom must scroll inside the viewer, not overflow the page');
        const touchViewer = await student.context().newCDPSession(student);
        await touchViewer.send('Emulation.setTouchEmulationEnabled', { enabled: true });
        const touchViewport = await student.locator('[data-field="whiteboard-viewport"]').boundingBox();
        const pageScrollBefore = await student.evaluate(() => window.scrollY);
        const touchX = touchViewport.x + touchViewport.width / 2;
        const touchY = touchViewport.y + touchViewport.height / 2;
        await touchViewer.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: touchX, y: touchY }] });
        for (let step = 1; step <= 6; step++) {
            await touchViewer.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: touchX, y: touchY - step * 15 }] });
        }
        await touchViewer.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await student.waitForFunction(() => document.querySelector('[data-field="whiteboard-viewport"]').scrollTop > 25);
        assert.equal(await student.evaluate(() => window.scrollY), pageScrollBefore, 'Touch panning must stay within the student viewer');
        await touchViewer.send('Emulation.setTouchEmulationEnabled', { enabled: false });
        await touchViewer.detach();
        await student.locator('[data-field="whiteboard-zoom"]').focus();
        await student.locator('[data-field="whiteboard-zoom"]').press('End');
        await student.waitForFunction(() => document.querySelector('[data-field="whiteboard-zoom-value"]').textContent === '400%');
        assert.equal(await student.locator('[data-action="whiteboard-zoom-in"]').isDisabled(), true);
        await student.locator('[data-field="whiteboard-zoom"]').press('Home');
        await student.waitForFunction(() => document.querySelector('[data-field="whiteboard-zoom-value"]').textContent === '25%');
        assert.equal(await student.locator('[data-action="whiteboard-zoom-out"]').isDisabled(), true);
        await student.click('[data-action="whiteboard-zoom-reset"]');
        assert.equal(JSON.stringify(sessions.get('ABCD12').state), unchangedState, 'Student zoom/pan must never change teacher state');
        await student.selectOption('[data-field="whiteboard-fit"]', 'board');
        await student.waitForFunction(() => document.querySelector('.htt-join-whiteboard-surface').getBoundingClientRect().width <= document.querySelector('[data-field="whiteboard-viewport"]').clientWidth + 1);
        await student.selectOption('[data-field="whiteboard-fit"]', 'page');
        await student.screenshot({ path: path.join(output, 'student-mobile.png') });
        await student.click('[data-action="student-fullscreen"]');
        await student.waitForFunction(() => document.fullscreenElement === document.querySelector('[data-htt-join]'));
        const studentLayers = await student.evaluate(() => ['whiteboard-material-frame', 'whiteboard-canvas'].map((key) => {
            const rect = document.querySelector('[data-field="' + key + '"]').getBoundingClientRect(); return [rect.x, rect.y, rect.width, rect.height];
        }));
        studentLayers[0].forEach((value, i) => assert(Math.abs(value - studentLayers[1][i]) < 2, 'Student fullscreen PDF and ink must align'));
        assert.equal(await student.locator('[data-action="leave-session"]').isVisible(), true);
        await student.screenshot({ path: path.join(output, 'student-pdf-fullscreen-mobile.png') });
        await student.setViewportSize({ width: 844, height: 390 });
        await student.selectOption('[data-field="whiteboard-fit"]', 'width');
        await student.waitForFunction(() => {
            const viewport = document.querySelector('[data-field="whiteboard-viewport"]');
            const surface = document.querySelector('.htt-join-whiteboard-surface').getBoundingClientRect();
            return viewport.clientHeight > 80 && surface.width * 0.45 >= viewport.clientWidth - 3 && viewport.scrollTop <= 1;
        });
        assert.equal(await student.locator('[data-action="leave-session"]').isVisible(), true);
        await student.screenshot({ path: path.join(output, 'student-pdf-landscape-width.png') });
        await student.selectOption('[data-field="whiteboard-fit"]', 'page');
        await student.setViewportSize({ width: 1366, height: 1000 });
        await student.waitForFunction(() => document.querySelector('[data-field="whiteboard-material-frame"]').width > 1000);
        await student.screenshot({ path: path.join(output, 'student-pdf-fullscreen-desktop.png') });
        await student.setViewportSize({ width: 320, height: 720 });
        await student.waitForFunction(() => document.querySelector('[data-field="whiteboard-viewport"]').clientHeight > 100);
        assert.equal(await student.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        assert.equal(await student.locator('[data-action="whiteboard-zoom-reset"]').isVisible(), true);
        await student.setViewportSize({ width: 390, height: 844 });
        await student.click('[data-action="student-fullscreen"]');
        await student.waitForFunction(() => !document.fullscreenElement);
        await student.click('[data-field="assigned-sessions"] button');
        assert.equal(await student.locator('[data-field="whiteboard-view"]').isVisible(), true);
        sessions.set('QUIZ12', { joinCode: 'QUIZ12', toolType: 'quiz', mode: 'live', status: 'active', state: { question: '활동 전환 확인', options: ['가', '나'] } });
        await student.getByRole('button', { name: '빠른 응답 · 3반' }).click();
        await student.waitForSelector('[data-field="quiz-view"]:not([hidden])');
        assert.equal(await student.locator('[data-field="whiteboard-view"]').isVisible(), false);
        assert.match(await student.locator('[data-field="quiz-view"]').textContent(), /활동 전환 확인/);
        sessions.set('TASK12', { ...sessions.get('ABCD12'), joinCode: 'TASK12', autoOpen: true });
        await student.waitForSelector('[data-field="whiteboard-view"]:not([hidden])');
        assert.equal(await student.locator('[data-field="quiz-view"]').isVisible(), false, 'A newly forced activity must switch the participation view too');
        sessions.delete('TASK12');
        sessions.set('CLAS12', { joinCode: 'CLAS12', toolType: 'classroom', mode: 'live', status: 'active', state: { mySignal: '', activity: null } });
        const signals = await browser.newPage();
        signals.on('pageerror', (error) => errors.push(error.message));
        await signals.goto(origin + '/signals');
        await signals.click('[data-htt-global-classroom] [data-field="trigger"]');
        const signalSent = signals.waitForResponse((response) => response.url().endsWith('/CLAS12/participant-state') && response.request().postDataJSON()?.signal === 'help');
        await signals.click('[data-htt-global-classroom] [data-signal="help"]');
        await signalSent;
        assert.equal(sessions.get('CLAS12').state.mySignal, 'help');
        await signals.close();
        await student.click('[data-action="leave-session"]');
        await student.fill('[data-field="code"]', 'CLAS12');
        await student.click('[data-action="join"]');
        await student.waitForSelector('[data-field="classroom-view"]:visible');
        const clearSignal = student.waitForResponse((response) => response.url().endsWith('/CLAS12/participant-state') && response.request().postDataJSON()?.signal === '');
        await student.click('[data-action="leave-session"]');
        await clearSignal;
        await student.waitForSelector('[data-field="form"]:visible');
        assert.equal(await student.evaluate(() => localStorage.getItem('teachingTools.classroom.active.v1')), null);
        assert.equal(await student.evaluate(() => JSON.parse(localStorage.getItem('teachingTools.classroom.dismissed.v1')).code), 'CLAS12');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.click('[data-action="choose-material"]');
        await page.screenshot({ path: path.join(output, 'pdf-picker-mobile.png') });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        assert.deepEqual(errors, []);
        const outsider = await browser.newContext();
        assert.equal((await outsider.request.get(origin + materialUrl(1))).status(), 404);
        await outsider.close();
        await student.close();
        await page.click('[data-action="close-material"]');
        await page.click('[data-action="close"]');
        await page.waitForSelector('[data-field="setup"]:not([hidden])');
        await page.selectOption('[data-field="material-select"]', '');
        await page.click('[data-action="start"]');
        await page.waitForSelector('[data-field="board"]:not([hidden])');
        await page.click('[data-action="add-page"]');
        await page.selectOption('[data-field="pdf-import-mode"]', 'all');
        await page.selectOption('[data-field="pdf-material"]', 'material:1');
        await page.waitForFunction(() => document.querySelector('[data-action="insert-material"]').disabled === false);
        await page.click('[data-action="insert-material"]');
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('1 / 2'));
        await page.click('[data-action="next-page"]');
        await page.waitForFunction(() => document.querySelector('[data-field="page-indicator"]').textContent.includes('PDF 2쪽'));
        const restricted = await browser.newPage({ viewport: { width: 1024, height: 768 } });
        restricted.on('pageerror', (error) => errors.push(error.message));
        await restricted.route('**/api/sessions/assigned', (route) => route.fulfill({ json: { items: [] } }));
        await restricted.route('**/api/classroom/active', (route) => route.fulfill({ json: { active: false, sessions: [] } }));
        await restricted.goto(origin + '/student');
        await restricted.context().addCookies([{ name: 'material_role', value: 'denied', url: origin, httpOnly: true }]);
        await restricted.fill('[data-field="code"]', 'ABCD12'); await restricted.click('[data-action="join"]');
        await restricted.waitForSelector('[data-field="whiteboard-view"]:visible');
        await restricted.waitForFunction(() => document.querySelector('[data-field="whiteboard-material-message"]').textContent.includes('접근할 수 없습니다'));
        assert.equal(await restricted.locator('[data-field="whiteboard-material-error"]').isVisible(), true);
        await page.selectOption('[data-field="background-sharing-live"]', 'session');
        await restricted.waitForFunction(() => {
            const canvas = document.querySelector('[data-field="whiteboard-material-frame"]'), px = canvas.getContext('2d').getImageData(canvas.width / 2, canvas.height / 2, 1, 1).data;
            return !canvas.hidden && px[2] > 200 && px[0] < 40;
        });
        assert.equal((await restricted.context().request.get(origin + materialUrl(1))).status(), 404, 'Session sharing must not grant the original PDF permission');
        const sharedUrl = sessions.get('ABCD12').state.pages[1].background.shared.url;
        await restricted.screenshot({ path: path.join(output, 'student-shared-background-denied-original.png') });
        await restricted.setViewportSize({ width: 390, height: 844 });
        await restricted.click('[data-action="student-fullscreen"]');
        await restricted.waitForFunction(() => Boolean(document.fullscreenElement));
        assert.equal(await restricted.evaluate(() => document.querySelector('[data-htt-join]').scrollWidth > innerWidth), false);
        await restricted.click('[data-action="student-fullscreen"]');
        await page.click('[data-action="prev-page"]');
        await restricted.waitForFunction(() => {
            const canvas = document.querySelector('[data-field="whiteboard-material-frame"]'), px = canvas.getContext('2d').getImageData(canvas.width / 2, canvas.height / 2, 1, 1).data;
            return !canvas.hidden && px[0] > 200 && px[2] < 40;
        });
        const noMember = await browser.newContext();
        assert.equal((await noMember.request.get(origin + sharedUrl)).status(), 404); await noMember.close();
        await page.selectOption('[data-field="background-sharing-live"]', 'permissions');
        await restricted.waitForFunction(() => document.querySelector('[data-field="whiteboard-material-message"]').textContent.includes('접근할 수 없습니다'));
        assert.equal((await restricted.context().request.get(origin + sharedUrl)).status(), 404, 'Disabling sharing must revoke the protected image stream');
        await page.selectOption('[data-field="background-sharing-live"]', 'session');
        await restricted.waitForFunction(() => document.querySelector('[data-field="whiteboard-material-error"]').hidden);
        await page.click('[data-action="close"]');
        assert.equal((await restricted.context().request.get(origin + sharedUrl)).status(), 404, 'Ending the session must revoke shared backgrounds');
        await restricted.close();
        await page.selectOption('[data-field="material-select"]', 'material:1');
        await page.selectOption('[data-field="background-sharing"]', 'session');
        await page.click('[data-action="start"]');
        await page.waitForFunction(() => document.querySelector('[data-field="background-sharing-status"]').textContent === '수업 배경 공유됨');
        assert.equal(sessions.get('ABCD12').state.materialSharing, 'session', 'Preconfigured sharing must be persisted at session creation');
        await page.click('[data-action="close"]');
        assert.deepEqual(errors, []);
        console.log('Browser tests passed: protected PDF, HD viewing, group navigation, material errors, preconfigured/live sharing, denied original, page changes and revocation/end.');
    } finally {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
