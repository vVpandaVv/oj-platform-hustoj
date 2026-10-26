'use strict';

/*
 * Development harness: runs the game server and a tiny stand-in for the
 * WordPress REST API so the browser screens can be tried without WordPress.
 *
 *   node tools/dev-harness.js                 built-in engine (PHP, like a fresh install)
 *   HARNESS_ENGINE=node node tools/dev-harness.js   Node game server
 *   http://localhost:3300/                       links
 *   http://localhost:3300/play?uid=11&name=가람   learner screen
 *   http://localhost:3300/teacher                 teacher console
 *   http://localhost:3300/board?session=1         projector
 *   http://localhost:3300/bank                    problem bank
 */
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');
const os = require('node:os');
const { issueTicket, signRequest, verifyRequest } = require('../game-server/src/sign');

const ROOT = path.join(__dirname, '..');
const PORT = Number(process.env.HARNESS_PORT || 3300);
const GAME_PORT = PORT + 1;
const SECRET = 'dev-secret-dev-secret-dev-secret-0123456789';
const WS_URL = `ws://localhost:${GAME_PORT}/arena`;
const GAME_API = `http://127.0.0.1:${GAME_PORT}/arena`;
const REST = '/wp-json/oj-code-arena/v1';
const ENGINE = process.env.HARNESS_ENGINE === 'node' ? 'node' : 'builtin';
const STORE = path.join(os.tmpdir(), `oca-harness-${PORT}.json`);
const RESULTS = path.join(os.tmpdir(), `oca-harness-${PORT}-results.jsonl`);
for (const f of [STORE, STORE + '.lock', RESULTS]) { try { fs.unlinkSync(f); } catch { /* fresh */ } }

// Built-in engine: the real PHP Relay on a File_Store, one php process per call.
function php(input) {
    const out = execFileSync('php', [path.join(__dirname, 'relay-cli.php')], {
        input: JSON.stringify(input),
        env: { ...process.env, OCA_STORE: STORE, OCA_RESULTS: RESULTS, OCA_SECRET: SECRET },
        maxBuffer: 16 * 1024 * 1024,
    });
    return JSON.parse(out.toString('utf8'));
}

function builtinRounds() {
    if (!fs.existsSync(RESULTS)) return [];
    return fs.readFileSync(RESULTS, 'utf8').split(/\r?\n/).filter(Boolean).map((l) => JSON.parse(l));
}

const bankJson = JSON.parse(fs.readFileSync(path.join(ROOT, 'data', 'default-bank.json'), 'utf8'));
let nextProblemId = 1;
const problems = [
    ...bankJson.normal.map((p) => ({ id: nextProblemId++, kind: 'normal', title: '', code: p.code, input: '', answers: [], tags: p.tags, enabled: true })),
    ...bankJson.boss.map((p) => ({ id: nextProblemId++, kind: 'boss', title: p.title, code: p.code, input: p.input, answers: p.answers, tags: p.tags, enabled: true })),
];
const ROSTER = [
    { uid: 11, name: '가람' }, { uid: 12, name: '나래' }, { uid: 13, name: '다온' }, { uid: 14, name: '라온' },
    { uid: 15, name: '마루' }, { uid: 16, name: '바다' }, { uid: 17, name: '사랑' }, { uid: 18, name: '아라' },
];
const sessions = [
    { id: 1, title: '데모 수업', context: '1학년 3반', context_type: 'class', context_id: 'class:3', status: 'open', roster: true,
        settings: { teams: 4, limitMin: 6, blocks: 0, occSec: 30, bossEverySec: 20, bossWaitSec: 8, bossLimitSec: 30, penalty: true, attacks: false, selfPick: true, botPace: 'normal', tags: [] } },
];
const nodeRounds = [];
const roundsNow = () => (ENGINE === 'builtin' ? builtinRounds() : nodeRounds);

const game = ENGINE === 'builtin' ? { kill() {} } : spawn(process.execPath, [path.join(ROOT, 'game-server', 'src', 'index.js')], {
    env: { ...process.env, ARENA_SECRET: SECRET, PORT: String(GAME_PORT), HOST: '127.0.0.1', WP_API: `http://127.0.0.1:${PORT}${REST}`, ALLOWED_ORIGINS: '' },
    stdio: 'inherit',
});
process.on('exit', () => game.kill());
process.on('SIGINT', () => process.exit(0));

function bankFor(tags) {
    const on = problems.filter((p) => p.enabled);
    return {
        normal: on.filter((p) => p.kind === 'normal').map((p) => ({ code: p.code, tags: p.tags })),
        boss: on.filter((p) => p.kind === 'boss').map((p) => ({ title: p.title, code: p.code, input: p.input, answers: p.answers, tags: p.tags })),
        tags: [...new Set(on.flatMap((p) => p.tags))],
    };
}

async function push(s) {
    if (ENGINE === 'builtin') {
        return php({ op: 'upsert', session: { id: s.id, title: s.title, context: s.context, settings: s.settings, roster: s.roster ? ROSTER : null, problems: bankFor() } });
    }
    const body = JSON.stringify({
        id: s.id, title: s.title, context: s.context, settings: s.settings,
        roster: s.roster ? ROSTER : null, problems: bankFor(),
    });
    const { time, signature } = signRequest(SECRET, 'POST', '/api/sessions', body);
    const res = await fetch(GAME_API + '/api/sessions', { method: 'POST', headers: { 'x-arena-time': time, 'x-arena-signature': signature }, body });
    return res.json();
}

function ticket(uid, name, role, sid) {
    if (ENGINE === 'builtin') {
        return { mode: 'poll', url: '/relay', rest: '/relay', ticket: issueTicket(SECRET, { v: 1, uid, name, role, sid, free: true, exp: Math.floor(Date.now() / 1000) + 300 }) };
    }
    return { mode: 'ws', url: WS_URL, ticket: issueTicket(SECRET, { v: 1, uid, name, role, sid, free: true, exp: Math.floor(Date.now() / 1000) + 300 }) };
}

function page(title, cssList, jsList, attr, config) {
    const css = cssList.map((c) => `<link rel="stylesheet" href="/${c}">`).join('');
    const js = jsList.map((j) => `<script src="/${j}"></script>`).join('');
    return `<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title>${css}
<style>body{margin:0;padding:16px;background:#e9edf3;font-family:system-ui}</style></head>
<body><div ${attr} data-config='${JSON.stringify(config).replace(/'/g, '&#39;')}'></div>${js}</body></html>`;
}

const SHARED = ['shared/arena-moves.js', 'shared/arena-grade.js', 'shared/arena-bank.js', 'resources/js/arena-net.js', 'resources/js/arena-look.js'];

function json(res, status, body) {
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(body));
}

function readBody(req) {
    return new Promise((resolve) => {
        let raw = '';
        req.on('data', (c) => { raw += c; });
        req.on('end', () => resolve(raw));
    });
}

const MIME = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json' };

http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://local');
    const p = url.pathname;
    const uid = Number(url.searchParams.get('uid') || req.headers['x-dev-uid'] || 11);
    const name = url.searchParams.get('name') || (ROSTER.find((r) => r.uid === uid) || {}).name || '학생' + uid;

    if (p === '/') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end('<h1>Code Arena dev</h1><ul>' + ROSTER.map((r) => `<li><a href="/play?uid=${r.uid}">${r.name}</a></li>`).join('') +
            '<li><a href="/teacher">교사</a></li><li><a href="/board?session=1">전광판</a></li><li><a href="/bank">문제은행</a></li><li><a href="/records">기록</a></li></ul>');
    }
    if (p === '/relay' && req.method === 'POST') {
        const raw = await readBody(req);
        let reqBody = {};
        try { reqBody = JSON.parse(raw); } catch { /* bad body */ }
        return json(res, 200, php({ op: 'handle', req: reqBody }));
    }
    if (p === '/play') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end(page('play', ['resources/css/arena.css'], [...SHARED, 'resources/js/play.js'], 'data-oca-play',
            { restUrl: `/u/${uid}/${encodeURIComponent(name)}${REST}`, nonce: 'dev', free: true, tags: bankFor().tags }));
    }
    if (p === '/teacher') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end(page('teacher', ['resources/css/console.css'], [...SHARED, 'resources/js/teacher.js'], 'data-oca-teacher',
            { restUrl: `/u/1/${encodeURIComponent('선생님')}${REST}`, nonce: 'dev', tags: bankFor().tags, boardUrl: '/board' }));
    }
    if (p === '/board') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end(page('board', ['resources/css/board.css'], [...SHARED, 'resources/js/board.js'], 'data-oca-board',
            { restUrl: `/u/1/${encodeURIComponent('선생님')}${REST}`, nonce: 'dev', session: Number(url.searchParams.get('session') || 1), joinHint: 'localhost:3300/play' }));
    }
    if (p === '/bank') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end(page('bank', ['resources/css/console.css'], [...SHARED, 'resources/js/bank.js'], 'data-oca-bank',
            { restUrl: `/u/1/${encodeURIComponent('선생님')}${REST}`, nonce: 'dev' }));
    }
    if (p === '/loadtest') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        const cfgJson = JSON.stringify({ restUrl: `/u/1/${encodeURIComponent('관리자')}${REST}`, nonce: 'dev' });
        return res.end(`<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>loadtest</title></head><body style="font-family:system-ui;padding:16px">
<h2>부하 시험</h2><div id="oca-loadtest" data-config='${cfgJson}'><p><label>가상 학생 <input type="number" data-lt-count value="30"></label>
<label>시간 <input type="number" data-lt-minutes value="1"></label> <button type="button" data-lt-start>부하 시험 시작</button></p><div data-lt-out></div></div>
<script src="/resources/js/admin-loadtest.js"></script></body></html>`);
    }
    if (p === '/records') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end(page('records', ['resources/css/console.css'], [...SHARED, 'resources/js/records.js'], 'data-oca-records',
            { restUrl: `/u/1/${encodeURIComponent('선생님')}${REST}`, nonce: 'dev' }));
    }

    // REST stand-in: /u/{uid}/{name}/wp-json/oj-code-arena/v1/...
    const m = p.match(/^\/u\/(\d+)\/([^/]+)\/wp-json\/oj-code-arena\/v1(\/.*)$/);
    const serverRoute = p.startsWith(REST) ? p.slice(REST.length) : null;
    if (m || serverRoute) {
        const raw = await readBody(req);
        const body = raw ? JSON.parse(raw) : {};
        if (serverRoute) {
            if (!verifyRequest(SECRET, req.method, serverRoute, req.headers['x-arena-time'], req.headers['x-arena-signature'], raw)) return json(res, 401, { ok: false });
            if (serverRoute === '/server/results') {
                nodeRounds.push({ id: nodeRounds.length + 1, ...body });
                console.log('[harness] result saved: round', body.round, 'teams', body.teams.length);
                return json(res, 200, { ok: true });
            }
            if (serverRoute === '/server/bank') return json(res, 200, bankFor());
            return json(res, 404, {});
        }
        const who = { uid: Number(m[1]), name: decodeURIComponent(m[2]) };
        const route = m[3];
        const method = req.method;
        if (method === 'GET' && route === '/play') return json(res, 200, { sessions: sessions.filter((s) => s.status === 'open').map((s) => ({ id: s.id, title: s.title, context: s.context })) });
        if (method === 'POST' && route === '/play/ticket') {
            const sid = Number(body.session) || 0;
            if (sid) {
                const s = sessions.find((x) => x.id === sid);
                if (!s) return json(res, 404, { message: '없는 세션' });
                await push(s);
            }
            return json(res, 200, ticket(who.uid, who.name, 'learner', sid));
        }
        if (method === 'GET' && route === '/me/records') {
            const out = [];
            for (const r of roundsNow()) for (const t of r.teams) for (const pl of t.players) {
                if (pl.uid === who.uid) out.push({ date: new Date(r.endedAt * 1000).toLocaleString('ko-KR'), title: r.title || '자유 대전', mode: r.mode, team_rank: t.rank, team_pct: t.pct, cleared: t.cleared, solved: pl.solved, bosses: pl.bosses });
            }
            return json(res, 200, { rounds: out.reverse() });
        }
        if (method === 'GET' && route === '/contexts') return json(res, 200, { contexts: [{ type: 'class', id: 'class:3', label: '1학년 3반' }, { type: 'group', id: 'group:5', label: '방과후 코딩반' }] });
        if (method === 'GET' && route === '/sessions') return json(res, 200, { sessions: sessions.filter((s) => s.status === 'open'), defaults: sessions[0].settings, tags: bankFor().tags });
        if (method === 'POST' && route === '/sessions') {
            const s = { id: sessions.length + 1, title: body.title || '새 수업', context: body.context_label || '', context_type: body.context_type || '', context_id: body.context_id || '', status: 'open', roster: !!body.context_id, settings: body.settings || {} };
            sessions.push(s);
            return json(res, 200, { session: s });
        }
        let sm = route.match(/^\/sessions\/(\d+)(\/ticket|\/records)?$/);
        if (sm) {
            const s = sessions.find((x) => x.id === Number(sm[1]));
            if (!s) return json(res, 404, { message: '없는 세션' });
            if (sm[2] === '/ticket') {
                await push(s);
                return json(res, 200, ticket(who.uid, who.name, body.role === 'board' ? 'board' : 'teacher', s.id));
            }
            if (sm[2] === '/records') return json(res, 200, { rounds: roundsNow().filter((r) => r.sessionId === s.id).reverse() });
            if (method === 'PUT') { Object.assign(s, { title: body.title || s.title, settings: body.settings || s.settings }); await push(s); return json(res, 200, { session: s }); }
            if (method === 'DELETE') { s.status = 'archived'; if (ENGINE === 'builtin') php({ op: 'close', id: s.id }); return json(res, 200, { ok: true }); }
        }
        if (method === 'POST' && route === '/admin/loadtest') {
            const count = Math.max(1, Math.min(60, Number(body.count) || 30));
            const s = { id: sessions.length + 1, title: '부하 시험 (지워짐)', context: '', status: 'open', roster: false,
                settings: { teams: Math.min(8, Math.max(2, Math.ceil(count / 6))), limitMin: Number(body.minutes) || 1, penalty: false, bossEverySec: 20 } };
            sessions.push(s);
            await push(s);
            const tickets = [];
            for (let k = 1; k <= count; k++) tickets.push(ticket(2000000000 + k, '가상학생 ' + k, 'learner', s.id).ticket);
            const t = ticket(1, '관리자', 'teacher', s.id);
            return json(res, 200, { session: s.id, teams: s.settings.teams, relay: t.url, rest: t.rest || '', teacher: t.ticket, tickets });
        }
        const lt = route.match(/^\/admin\/loadtest\/(\d+)$/);
        if (method === 'DELETE' && lt) {
            const s = sessions.find((x) => x.id === Number(lt[1]));
            if (s) { s.status = 'archived'; if (ENGINE === 'builtin') php({ op: 'close', id: s.id }); }
            return json(res, 200, { ok: true });
        }
        if (method === 'GET' && route === '/records') return json(res, 200, { rounds: roundsNow().slice().reverse().map((r) => ({ ...r, session_id: r.sessionId, session_title: r.title })) });
        if (method === 'GET' && route === '/bank') return json(res, 200, { items: problems.map((x) => ({ ...x, editable: true })), tags: bankFor().tags, operator: true });
        if (method === 'POST' && route === '/bank') { const it = { id: nextProblemId++, enabled: true, ...body }; problems.push(it); return json(res, 200, { item: it }); }
        if (method === 'POST' && route === '/bank/import') {
            if (body.replace) problems.length = 0;
            let added = 0;
            for (const it of body.items || []) { problems.push({ id: nextProblemId++, enabled: true, input: '', title: '', answers: [], ...it }); added++; }
            return json(res, 200, { added, skipped: 0 });
        }
        sm = route.match(/^\/bank\/(\d+)$/);
        if (sm) {
            const k = problems.findIndex((x) => x.id === Number(sm[1]));
            if (k < 0) return json(res, 404, { message: '없는 문제' });
            if (method === 'DELETE') { problems.splice(k, 1); return json(res, 200, { ok: true }); }
            Object.assign(problems[k], body);
            return json(res, 200, { item: problems[k] });
        }
        return json(res, 404, { message: 'no route ' + method + ' ' + route });
    }

    const file = path.join(ROOT, p);
    if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'content-type': MIME[path.extname(file)] || 'application/octet-stream', 'cache-control': 'no-store' });
    fs.createReadStream(file).pipe(res);
}).listen(PORT, () => console.log(`[harness] http://localhost:${PORT}/  (game server on ${GAME_PORT})`));
