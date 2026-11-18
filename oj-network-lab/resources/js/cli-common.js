import {clone, validateProject, ipv4, ipString, cidr, contains, integer} from './model.js?ver=1.4.0-rc.4';
import {configSnapshot, portLabel} from './device-config.js?ver=1.4.0-rc.4';
export const need = (ok, message = '% Invalid input detected. 지원 문법은 ?로 확인하세요.') => { if (!ok) throw new Error(message); };
export function lex(line) {
    need(typeof line === 'string' && line.length <= 2048 && !/[\r\n\x00]/.test(line), '한 번에 한 명령, 2048자 이내로 입력하세요.');
    const words = []; let word = '', quote = '', active = false, escaped = false;
    for (const c of line.trim()) {
        if (escaped) { word += c; escaped = false; }
        else if (c === '\\' && quote) escaped = true;
        else if (quote) { if (c === quote) quote = ''; else word += c; }
        else if (c === '"' || c === "'") { quote = c; active = true; }
        else if (/\s/.test(c)) { if (active) { words.push(word); word = ''; active = false; } }
        else { word += c; active = true; }
    }
    need(!quote && !escaped, '따옴표를 닫아 주세요.'); if (active) words.push(word); return words;
}
export function maskPrefix(mask) {
    const bits = ipv4(mask), inverse = (~bits) >>> 0;
    need(((inverse & ((inverse + 1) >>> 0)) >>> 0) === 0, '연속된 IPv4 서브넷 마스크가 필요합니다.');
    return bits.toString(2).replace(/0/g, '').length;
}
export const maskOf = bits => ipString(bits === 0 ? 0 : (0xffffffff << (32 - bits)) >>> 0);
export const subnet = (ip, mask) => { const bits = maskPrefix(mask), c = cidr(`${ip}/${bits}`); return `${ipString(c.network)}/${bits}`; };
export const wildcard = (ip, mask) => subnet(ip, ipString((~ipv4(mask)) >>> 0));
export function findPort(n, name) {
    const lower = String(name).toLowerCase();
    const f = n.interfaces.find(f => f.name.toLowerCase() === lower || portLabel(n, f).toLowerCase() === lower || (n.profile === 'ios' && portLabel(n, f).toLowerCase().replace('gigabitethernet', 'gi') === lower));
    need(f, '% 인터페이스가 없습니다. interface ?로 포트를 확인하세요.'); return f;
}
export function virtualPort(n, name, kind, extra = {}) {
    need(n.interfaces.length < 10, '장비당 인터페이스는 최대 10개입니다.');
    const used = new Set(n.interfaces.map(f => f.mac)); let i = 1, mac;
    do { mac = `02:fe:${Number(n.id.replace(/\D/g, '') || 1).toString(16).slice(-2).padStart(2, '0')}:00:${(i >> 8).toString(16).padStart(2, '0')}:${(i++ & 255).toString(16).padStart(2, '0')}`; } while (used.has(mac));
    return {name, kind, address: '', mac, up: true, vlan: 1, mode: 'access', allowedVlans: [1], ...extra};
}
export function update(e, n, change, save = true) {
    const p = e.export(), target = p.nodes.find(x => x.id === n.id); change(target);
    const next = validateProject(p), after = next.nodes.find(x => x.id === n.id);
    if (save && after.profile === 'fortios' && after.device.saveMode === 'automatic') after.startup = configSnapshot(after);
    e.project = next; e.revision++; e.configurationChanged(n, after); return after;
}
export function saveStartup(e, n) { update(e, n, x => { x.startup = configSnapshot(x); }, false); }
export function reboot(e, n) {
    update(e, n, x => { const saved = clone(x.startup); for (const [key, value] of Object.entries(saved)) x[key] = value; }, false);
    e.resetDevice(n.id); e.log(n.id, 'reboot', {}, '장비 재부팅 · 시작 설정 복원');
}
export function runningDirty(n) { return JSON.stringify(configSnapshot(n)) !== JSON.stringify(n.startup); }
export function expand(tokens, patterns) {
    let candidates = patterns.map(p => p.toLowerCase().split(' ')); const out = [...tokens];
    for (let i = 0; i < tokens.length && candidates.length; i++) {
        const value = tokens[i].toLowerCase(), literal = [...new Set(candidates.map(p => p[i]).filter(x => x && !x.startsWith('<')))];
        const exact = literal.find(x => x === value), matches = exact ? [exact] : literal.filter(x => x.startsWith(value));
        need(matches.length <= 1, `% Ambiguous command: "${tokens.join(' ')}"`);
        if (matches.length) { out[i] = matches[0]; candidates = candidates.filter(p => p[i] === matches[0]); }
        else candidates = candidates.filter(p => p[i]?.startsWith('<'));
    }
    return out;
}
export function suggestions(n, line, patterns) {
    const words = line.trimStart().split(/\s+/), current = words.pop() || ''; let candidates = patterns.map(p => p.split(' '));
    for (let i = 0; i < words.length; i++) candidates = candidates.filter(p => p[i] && (p[i].startsWith('<') || p[i].startsWith(words[i].toLowerCase())));
    const index = words.length, available = new Set();
    for (const p of candidates) {
        const item = p[index]; if (!item) continue;
        const values = item === '<interface>' ? n.interfaces.map(f => portLabel(n, f)) : [item];
        for (const value of values) if (value.startsWith('<') || value.toLowerCase().startsWith(current.toLowerCase())) available.add(value);
    }
    const choices = [...available].sort(), concrete = choices.filter(c => !c.startsWith('<'));
    return {line: concrete.length === 1 ? [...words, concrete[0]].join(' ') + ' ' : line, choices};
}
export function aclMatch(acl, packet) {
    return acl?.entries.slice().sort((a, b) => a.seq - b.seq).find(r => (r.protocol === 'IP' || r.protocol === packet.protocol) && contains(r.src, packet.src) && contains(r.dst, packet.dst) && (!r.port || r.port === packet.dport));
}
export const portNumber = value => { const names = {www: 80, http: 80, https: 443, domain: 53, ssh: 22}; return integer(names[value] || Number(value), 1, 65535); };
