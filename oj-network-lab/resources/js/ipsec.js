// Bounded educational IKEv2/ESP state model. No encryption or real authentication.
import {clone, cidr, contains} from './model.js?ver=1.4.0-rc.4';
const proof = value => { let h = 2166136261; for (const c of value) h = Math.imul(h ^ c.charCodeAt(0), 16777619) >>> 0; return h.toString(16); };
const auth = (p1, state, suffix = '') => proof(`${p1.psk}/${state.nonce}/${state.cookie}/${suffix}`);
const selector = (n, name, p) => n.device.phase2.find(s => s.phase1 === name && contains(s.src, p.src) && contains(s.dst, p.dst));
const selectorsEqual = (a, b) => { const x = cidr(a), y = cidr(b); return x.network === y.network && x.bits === y.bits; };
function compatible(n, p1, payload) {
    if (p1.proposal !== payload.proposal || p1.dhgrp !== payload.dhgrp || p1.ikeversion !== payload.ikeversion) return {reason: 'IKE proposal·DH group·버전 불일치'};
    const p2 = n.device.phase2.find(s => s.phase1 === p1.name && selectorsEqual(s.src, payload.dst) && selectorsEqual(s.dst, payload.src));
    if (!p2) return {reason: 'phase2 트래픽 선택자 불일치'};
    if (p2.proposal !== payload.childProposal || p2.pfs !== payload.pfs) return {reason: 'CHILD_SA proposal·PFS 불일치'};
    return {p2};
}
function payloadFor(p1, p2, state, kind) { return {kind, nonce: state.nonce, cookie: state.cookie, proposal: p1.proposal, dhgrp: p1.dhgrp, ikeversion: p1.ikeversion, childProposal: p2.proposal, src: p2.src, dst: p2.dst, pfs: p2.pfs}; }
function send(e, n, p1, payload, protocol = 'UDP', size = 256) {
    const wan = n.interfaces.find(f => f.name === p1.interface), route = e.route(n, p1.remote);
    if (!wan?.up || !wan.address || route?.dev !== wan.name) { e.log(n, 'drop', {}, 'IPsec WAN 포트·endpoint 외부 경로를 확인하세요.', p1.interface); return; }
    e.transmit(n, e.packet(protocol, cidr(wan.address).address, p1.remote, {sport: protocol === 'UDP' ? 500 : 0, dport: protocol === 'UDP' ? 500 : 0, size, ipsec: payload}), true);
}
function esp(e, n, p1, state, packet) {
    const inner = clone(packet), seq = ++state.tx;
    send(e, n, p1, {kind: 'esp', nonce: state.nonce, cookie: state.cookie, seq, inner, tag: proof(`${auth(p1, state)}/${seq}/${JSON.stringify(inner)}`)}, 'ESP', (inner.size || 84) + 56);
}
function up(e, n, p1, p2, state) { state.state = 'up'; state.reason = '교육용 IKE_SA·CHILD_SA 수립'; state.until = e.now + Math.min(p1.lifetime, p2.lifetime) * 1000; e.log(n, 'ipsec', {}, `${p1.name}: ${state.reason}`); const waiting = state.waiting.splice(0); for (const packet of waiting) esp(e, n, p1, state, packet); }
export function ipsecTransmit(e, n, f, p) {
    const p1 = n.device.phase1.find(s => s.name === f.name), p2 = p1 && selector(n, f.name, p);
    if (!p1 || !p2) { e.log(n, 'drop', p, 'IPsec phase1·phase2 선택자가 통신과 맞지 않습니다.', f.name); return; }
    const rt = e.rt(n); let state = rt.ipsec.get(p1.name);
    if (state?.state === 'up' && state.until > e.now) { esp(e, n, p1, state, p); return; }
    if (!state || !['init', 'auth'].includes(state.state)) {
        state = {state: 'init', role: 'initiator', phase2: p2.name, nonce: `${n.id}-${++e.sequence}`, cookie: '', waiting: [], tx: 0, rx: 0, reason: 'IKE_SA_INIT 대기', until: 0, attempt: 0}; rt.ipsec.set(p1.name, state);
        send(e, n, p1, payloadFor(p1, p2, state, 'ike-init')); e.schedule(800, 'ipsecRetry', {node: n.id, name: p1.name, nonce: state.nonce});
    }
    if (state.waiting.length < 32) state.waiting.push(clone(p)); else e.log(n, 'drop', p, 'IPsec 협상 대기 큐 초과', f.name);
}
export function ipsecReceive(e, n, f, p) {
    const x = p.ipsec, p1 = n.profile === 'fortios' && n.device.phase1.find(s => s.interface === f.name && s.remote === p.src);
    if (!p1) { e.log(n, 'drop', p, 'IPsec 피어·수신 포트 불일치', f.name); return; }
    const rt = e.rt(n); let state = rt.ipsec.get(p1.name);
    if (x.kind === 'esp') {
        if (!state || state.state !== 'up' || state.until <= e.now || state.nonce !== x.nonce || state.cookie !== x.cookie || x.seq <= state.rx) { e.log(n, 'drop', p, 'ESP SA 없음·만료·재전송 차단', f.name); return; }
        const p2 = n.device.phase2.find(s => s.name === state.phase2);
        if (!p2 || !contains(p2.dst, x.inner.src) || !contains(p2.src, x.inner.dst) || x.tag !== proof(`${auth(p1, state)}/${x.seq}/${JSON.stringify(x.inner)}`)) { e.log(n, 'drop', p, 'ESP 선택자·모델 인증 태그 불일치', f.name); return; }
        state.rx = x.seq; e.log(n, 'decap', x.inner, `IPsec ${p1.name} 종단 복원 · 암호화 없는 모델`, p1.name); e.receiveIP(n, n.interfaces.find(i => i.name === p1.name), x.inner); return;
    }
    if (x.kind === 'ike-init') {
        const match = compatible(n, p1, x);
        if (!match.p2) { rt.ipsec.set(p1.name, {state: 'failed', reason: match.reason, waiting: [], until: 0}); e.log(n, 'ipsec', p, `${p1.name}: ${match.reason}`); return; }
        if (!state || state.nonce !== x.nonce || state.role !== 'responder') { state = {state: 'init', role: 'responder', phase2: match.p2.name, nonce: x.nonce, cookie: `${n.id}-${++e.sequence}`, tx: 0, rx: 0, waiting: [], until: 0, reason: 'IKE_AUTH 대기'}; rt.ipsec.set(p1.name, state); }
        send(e, n, p1, payloadFor(p1, match.p2, state, 'ike-response')); return;
    }
    if (!state || state.nonce !== x.nonce) return;
    const p2 = n.device.phase2.find(s => s.name === state.phase2); if (!p2) return;
    if (x.kind === 'ike-response' && state.role === 'initiator' && state.state === 'init') {
        const match = compatible(n, p1, x); if (!match.p2) { state.state = 'failed'; state.reason = match.reason; return; }
        state.cookie = x.cookie; state.state = 'auth'; state.reason = 'IKE_AUTH 대기'; const payload = payloadFor(p1, p2, state, 'ike-auth'); payload.proof = auth(p1, state); send(e, n, p1, payload); return;
    }
    if (x.kind === 'ike-auth' && state.role === 'responder' && state.cookie === x.cookie) {
        if (x.proof !== auth(p1, state)) { state.state = 'failed'; state.reason = '가상 PSK 불일치'; e.log(n, 'ipsec', p, state.reason); return; }
        if (state.state !== 'up') up(e, n, p1, p2, state);
        const payload = payloadFor(p1, p2, state, 'ike-auth-ok'); payload.proof = auth(p1, state, 'ok'); send(e, n, p1, payload); return;
    }
    if (x.kind === 'ike-auth-ok' && state.role === 'initiator' && state.state === 'auth' && state.cookie === x.cookie) { if (x.proof !== auth(p1, state, 'ok')) { state.state = 'failed'; state.reason = '가상 PSK 불일치'; return; } up(e, n, p1, p2, state); }
}
export function retryIPsec(e, data) {
    const n = e.node(data.node), state = e.rt(n).ipsec.get(data.name), p1 = n.device?.phase1.find(s => s.name === data.name), p2 = n.device?.phase2.find(s => s.name === state?.phase2);
    if (!state || !p1 || !p2 || state.nonce !== data.nonce || !['init', 'auth'].includes(state.state)) return;
    if (++state.attempt >= 3) { state.state = 'failed'; state.reason = 'IKE 응답 시간 초과 · WAN·피어·PSK·proposal 확인'; for (const p of state.waiting.splice(0)) e.log(n, 'drop', p, state.reason, p1.name); return; }
    const payload = payloadFor(p1, p2, state, state.state === 'init' ? 'ike-init' : 'ike-auth'); if (state.state === 'auth') payload.proof = auth(p1, state); send(e, n, p1, payload); e.schedule(800, 'ipsecRetry', {...data});
}
export function ipsecStatus(e, n) { return n.device.phase1.map(p => { const s = e.rt(n).ipsec.get(p.name); return {name: p.name, up: s?.state === 'up' && s.until > e.now, reason: s?.state === 'up' && s.until <= e.now ? 'SA lifetime 만료' : s?.reason || '대기 중', until: s?.until || 0}; }); }
