import {clone, cidr, integer, ipv4, token, text} from './model.js?ver=1.4.0-rc.4';
import {portLabel} from './device-config.js?ver=1.4.0-rc.4';
import {need, lex, suggestions, maskPrefix, maskOf, subnet, findPort, virtualPort, update, saveStartup, reboot} from './cli-common.js?ver=1.4.0-rc.4';
const TABLES = ['system interface', 'system global', 'router static', 'firewall address', 'firewall service custom', 'firewall policy', 'vpn ipsec phase1-interface', 'vpn ipsec phase2-interface'];
const LIST = {'firewall address': 'addresses', 'firewall service custom': 'services', 'firewall policy': 'policies', 'vpn ipsec phase1-interface': 'phase1', 'vpn ipsec phase2-interface': 'phase2'};
const FIELDS = {'system interface': ['ip <address> <mask>', 'status up', 'status down', 'alias <text>', 'mode static', 'allowaccess ping'], 'system global': ['hostname <name>', 'cfg-save automatic', 'cfg-save manual'], 'router static': ['dst <network> <mask>', 'gateway <address>', 'device <interface>', 'distance <number>'], 'firewall address': ['subnet <network> <mask>'], 'firewall service custom': ['tcp-portrange <range>', 'udp-portrange <range>'], 'firewall policy': ['name <name>', 'srcintf <interface>', 'dstintf <interface>', 'srcaddr <object>', 'dstaddr <object>', 'service <service>', 'action accept', 'action deny', 'nat enable', 'nat disable', 'status enable', 'status disable', 'schedule always', 'logtraffic all', 'logtraffic disable'], 'vpn ipsec phase1-interface': ['interface <interface>', 'remote-gw <address>', 'psksecret <virtual-key>', 'proposal aes256-sha256', 'proposal aes128-sha256', 'dhgrp 14', 'dhgrp 19', 'ike-version 2', 'keylife <seconds>'], 'vpn ipsec phase2-interface': ['phase1name <name>', 'src-subnet <network> <mask>', 'dst-subnet <network> <mask>', 'proposal aes256-sha256', 'proposal aes128-sha256', 'pfs enable', 'pfs disable', 'keylifeseconds <seconds>']};
export function fortiPatterns(s) {
    if (s.mode === 'root') return [...TABLES.map(t => `config ${t}`), ...TABLES.map(t => `show ${t}`), 'show', 'get system status', 'get router info routing-table all', 'diagnose sys session list', 'diagnose sys session clear', 'diagnose vpn tunnel list', 'diagnose vpn ike gateway list', 'execute ping <address>', 'execute traceroute <address>', 'execute cfg save', 'execute cfg reload', 'execute reboot', 'execute log display'];
    if (s.mode === 'edit' || s.mode === 'global') return [...FIELDS[s.table].map(f => 'set ' + f), ...FIELDS[s.table].map(f => 'unset ' + f.split(' ')[0]), 'show', 'next', 'end', 'abort'];
    return ['edit <name-or-id>', 'delete <name-or-id>', ...(s.table === 'firewall policy' ? ['move <id> before <id>', 'move <id> after <id>'] : []), 'show', 'end', 'abort'];
}
export function fortiPrompt(n, s) { return `${n.name}${s.mode === 'root' ? '' : ` (${s.mode === 'edit' ? s.id : s.table.split(' ').at(-1)})`} #`; }
function defaults(table, id) {
    if (table === 'firewall address') return {name: id, subnet: '0.0.0.0/0'};
    if (table === 'firewall service custom') return {name: id, protocol: 'TCP', from: 0, to: 0};
    if (table === 'firewall policy') return {id, name: '', srcintf: '', dstintf: '', srcaddr: [], dstaddr: [], service: [], action: 'deny', nat: false, status: true, logtraffic: false};
    if (table === 'router static') return {id, dest: '0.0.0.0/0', via: '', dev: '', metric: 10};
    if (table === 'vpn ipsec phase1-interface') return {name: id, interface: '', remote: '0.0.0.0', psk: '', proposal: 'aes256-sha256', dhgrp: 14, ikeversion: 2, lifetime: 28800};
    return {name: id, phase1: '', src: '0.0.0.0/0', dst: '0.0.0.0/0', proposal: 'aes256-sha256', pfs: true, lifetime: 3600};
}
function entries(n, table) { return table === 'system interface' ? n.interfaces : table === 'router static' ? n.routes : n.device[LIST[table]]; }
const objectKey = (table, x) => table === 'router static' || table === 'firewall policy' ? x.id : x.name;
const quoted = x => JSON.stringify(x);
function displayEntry(n, table, x) {
    const lines = [], ipmask = s => { const c = cidr(s); return `${c.address} ${maskOf(c.bits)}`; }, ifname = value => portLabel(n, findPort(n, value));
    const set = (key, value) => lines.push(`    set ${key} ${value}`);
    if (table === 'system interface') { if (x.address) set('ip', ipmask(x.address)); set('status', x.up ? 'up' : 'down'); if (x.description) set('alias', quoted(x.description)); if (x.allowPing) set('allowaccess', 'ping'); }
    if (table === 'router static') { set('dst', ipmask(x.dest)); if (x.via) set('gateway', x.via); if (x.dev) set('device', quoted(ifname(x.dev))); set('distance', x.metric || 10); }
    if (table === 'firewall address') set('subnet', ipmask(x.subnet));
    if (table === 'firewall service custom') set(x.protocol === 'TCP' ? 'tcp-portrange' : 'udp-portrange', x.from === x.to ? x.from : `${x.from}-${x.to}`);
    if (table === 'firewall policy') {
        if (x.name) set('name', quoted(x.name)); if (x.srcintf) set('srcintf', quoted(x.srcintf === 'any' ? 'any' : ifname(x.srcintf))); if (x.dstintf) set('dstintf', quoted(x.dstintf === 'any' ? 'any' : ifname(x.dstintf)));
        for (const key of ['srcaddr', 'dstaddr', 'service']) set(key, x[key].map(quoted).join(' '));
        set('action', x.action); set('schedule', 'always'); set('nat', x.nat ? 'enable' : 'disable'); set('status', x.status ? 'enable' : 'disable'); if (x.logtraffic) set('logtraffic', 'all');
    }
    if (table === 'vpn ipsec phase1-interface') { if (x.interface) set('interface', quoted(ifname(x.interface))); set('remote-gw', x.remote); set('ike-version', x.ikeversion); set('proposal', x.proposal); set('dhgrp', x.dhgrp); set('psksecret', quoted(x.psk)); set('keylife', x.lifetime); }
    if (table === 'vpn ipsec phase2-interface') { set('phase1name', quoted(x.phase1)); set('src-subnet', ipmask(x.src)); set('dst-subnet', ipmask(x.dst)); set('proposal', x.proposal); set('pfs', x.pfs ? 'enable' : 'disable'); set('keylifeseconds', x.lifetime); }
    return lines.join('\n');
}
function showTable(n, table, draft = null) {
    if (table === 'system global') return `config system global\n    set hostname ${quoted(n.name)}\n    set cfg-save ${n.device.saveMode}\nend`;
    const data = draft ? [draft] : entries(n, table);
    return `config ${table}\n${data.map(x => `  edit ${table === 'system interface' ? quoted(portLabel(n, x)) : quoted(objectKey(table, x))}\n${displayEntry(n, table, x)}\n  next`).join('\n')}\nend`;
}
function commit(e, n, s) {
    const draft = clone(s.draft);
    update(e, n, x => {
        if (s.table === 'system global') { x.name = draft.name; x.device.saveMode = draft.saveMode; return; }
        if (s.table === 'system interface') { const f = findPort(x, s.id); Object.assign(f, draft); return; }
        if (s.table === 'vpn ipsec phase1-interface') {
            need(draft.psk && draft.remote !== '0.0.0.0', '가상 PSK와 remote-gw를 설정하세요.'); need(findPort(x, draft.interface).kind === 'ethernet', '이 버전의 IPsec WAN은 물리 포트입니다.');
            if (!x.interfaces.some(f => f.name === draft.name)) x.interfaces.push(virtualPort(x, draft.name, 'ipsec'));
            need(findPort(x, draft.name).kind === 'ipsec', '기존 포트와 터널 이름이 겹칩니다.');
        }
        const list = entries(x, s.table), index = list.findIndex(item => objectKey(s.table, item) === s.id);
        if (index < 0) list.push(draft); else list[index] = draft;
    });
}
function setField(n, s, t) {
    const unset = t[0] === 'unset', key = t[1], values = t.slice(2), d = clone(s.draft), one = () => { need(values.length === 1); return values[0]; }, enabled = () => { const value = one(); need(['enable', 'disable'].includes(value)); return value === 'enable'; };
    if (unset) { need(t.length === 2); const known = FIELDS[s.table].some(f => f.split(' ')[0] === key); need(known, '지원하지 않는 필드입니다.');
        if (s.table === 'system interface') { if (key === 'ip') d.address = ''; else if (key === 'allowaccess') d.allowPing = false; else if (key === 'alias') d.description = ''; else if (key === 'status') d.up = true; else need(false); }
        else need(false, '이 프로필에서는 필수 필드를 set으로 수정하세요.'); s.draft = d; return;
    }
    if (s.table === 'system interface') {
        if (key === 'ip') { need(values.length === 2); ipv4(values[0]); d.address = `${values[0]}/${maskPrefix(values[1])}`; }
        else if (key === 'status') { const v = one(); need(['up', 'down'].includes(v)); d.up = v === 'up'; }
        else if (key === 'alias') d.description = text(one(), 80);
        else if (key === 'mode') need(one() === 'static', '정적 인터페이스만 지원합니다.');
        else if (key === 'allowaccess') { need(values.length === 1 && values[0] === 'ping', '관리 접근은 ping만 모델링합니다.'); d.allowPing = true; }
        else need(false);
    } else if (s.table === 'system global') {
        if (key === 'hostname') { const v = one(); need(/^[A-Za-z][A-Za-z0-9_-]{0,31}$/.test(v)); d.name = v; }
        else if (key === 'cfg-save') { const v = one(); need(['automatic', 'manual'].includes(v)); d.saveMode = v; } else need(false);
    } else if (s.table === 'router static') {
        if (key === 'dst') { need(values.length === 2); d.dest = subnet(...values); }
        else if (key === 'gateway') { const v = one(); ipv4(v); d.via = v === '0.0.0.0' ? '' : v; }
        else if (key === 'device') d.dev = findPort(n, one()).name;
        else if (key === 'distance') d.metric = integer(Number(one()), 1, 255); else need(false);
    } else if (s.table === 'firewall address') { need(key === 'subnet' && values.length === 2); d.subnet = subnet(...values); }
    else if (s.table === 'firewall service custom') { need(['tcp-portrange', 'udp-portrange'].includes(key)); const v = one(); need(/^\d+(?:-\d+)?$/.test(v), '하나의 포트 또는 연속 범위만 지원합니다.'); const [from, to = from] = v.split('-').map(Number); d.protocol = key.startsWith('tcp') ? 'TCP' : 'UDP'; d.from = integer(from, 1, 65535); d.to = integer(to, from, 65535); }
    else if (s.table === 'firewall policy') {
        if (key === 'name') d.name = text(one(), 40);
        else if (['srcintf', 'dstintf'].includes(key)) { const v = one(); d[key] = v === 'any' ? 'any' : findPort(n, v).name; }
        else if (['srcaddr', 'dstaddr', 'service'].includes(key)) { need(values.length > 0 && values.length <= 16); d[key] = values; }
        else if (key === 'action') { const v = one(); need(['accept', 'deny'].includes(v)); d.action = v; }
        else if (['nat', 'status'].includes(key)) d[key] = enabled();
        else if (key === 'schedule') need(one() === 'always', '일정은 always만 지원합니다.');
        else if (key === 'logtraffic') { const v = one(); need(['all', 'disable'].includes(v)); d.logtraffic = v === 'all'; } else need(false);
    } else if (s.table === 'vpn ipsec phase1-interface') {
        if (key === 'interface') d.interface = findPort(n, one()).name;
        else if (key === 'remote-gw') { const v = one(); ipv4(v); d.remote = v; }
        else if (key === 'psksecret') d.psk = text(one(), 64);
        else if (key === 'proposal') { const v = one(); need(['aes256-sha256', 'aes128-sha256'].includes(v)); d.proposal = v; }
        else if (key === 'dhgrp') { const v = Number(one()); need([14, 19].includes(v)); d.dhgrp = v; }
        else if (key === 'ike-version') { need(one() === '2', 'IKEv2 모델만 지원합니다.'); d.ikeversion = 2; }
        else if (key === 'keylife') d.lifetime = integer(Number(one()), 60, 86400); else need(false);
    } else if (s.table === 'vpn ipsec phase2-interface') {
        if (key === 'phase1name') d.phase1 = token(one());
        else if (['src-subnet', 'dst-subnet'].includes(key)) { need(values.length === 2); d[key.startsWith('src') ? 'src' : 'dst'] = subnet(...values); }
        else if (key === 'proposal') { const v = one(); need(['aes256-sha256', 'aes128-sha256'].includes(v)); d.proposal = v; }
        else if (key === 'pfs') d.pfs = enabled();
        else if (key === 'keylifeseconds') d.lifetime = integer(Number(one()), 60, 86400); else need(false);
    }
    s.draft = d;
}
export function fortiCommand(e, n, line, s) {
    if (s.pending) { const yes = ['y', 'yes'].includes(line.trim().toLowerCase()); s.pending = ''; if (yes) { reboot(e, n); return '장비가 저장 설정으로 재부팅되었습니다.'; } return 'Operation cancelled.'; }
    if (line.trim() === 'help' || line.trim().endsWith('?')) return `FortiOS 학습 프로필 · ${s.mode}\n${suggestions(n, line.trim() === 'help' ? '' : line.trim().slice(0, -1), fortiPatterns(s)).choices.join('\n')}`;
    const t = lex(line), key = t.join(' '); if (!t.length) return '';
    if (key === 'abort' && s.mode !== 'root') { if (s.mode === 'edit') s.mode = 'table'; else s.mode = 'root'; delete s.draft; return '현재 미확정 편집을 취소했습니다.'; }
    if (key === 'end' && s.mode !== 'root') { if (s.mode === 'edit' || s.mode === 'global') commit(e, n, s); s.mode = 'root'; delete s.draft; return ''; }
    if (key === 'next' && s.mode === 'edit') { commit(e, n, s); s.mode = 'table'; delete s.draft; return ''; }
    if ((s.mode === 'edit' || s.mode === 'global') && ['set', 'unset'].includes(t[0])) { setField(n, s, t); return ''; }
    if (key === 'show' && s.mode !== 'root') { if (s.mode === 'global') return `set hostname ${quoted(s.draft.name)}\nset cfg-save ${s.draft.saveMode}`; return s.draft ? displayEntry(n, s.table, s.draft) : showTable(n, s.table); }
    if (s.mode === 'table') {
        if (t[0] === 'edit' && t.length === 2) {
            let id = t[1]; if (['firewall policy', 'router static'].includes(s.table)) { id = integer(Number(id), 0, 2147483647); if (!id) id = Math.max(0, ...entries(n, s.table).map(x => x.id || 0)) + 1; }
            if (s.table === 'system interface') { const f = findPort(n, id); s.id = f.name; s.draft = clone(f); }
            else { need(id !== 'all' && !['ALL', 'PING', 'HTTP', 'HTTPS', 'DNS', 'SSH'].includes(id), '기본 객체는 편집할 수 없습니다.'); text(String(id), 40); s.id = id; s.draft = clone(entries(n, s.table).find(x => objectKey(s.table, x) === id) || defaults(s.table, id)); }
            s.mode = 'edit'; return '';
        }
        if (t[0] === 'delete' && t.length === 2) { need(s.table !== 'system interface', '물리 인터페이스 삭제는 지원하지 않습니다.'); const id = ['firewall policy', 'router static'].includes(s.table) ? Number(t[1]) : t[1]; update(e, n, x => { const list = entries(x, s.table), i = list.findIndex(v => objectKey(s.table, v) === id); need(i >= 0, '항목이 없습니다.'); list.splice(i, 1); if (s.table === 'vpn ipsec phase1-interface') x.interfaces = x.interfaces.filter(f => f.name !== id); }); return ''; }
        if (t[0] === 'move' && t.length === 4 && s.table === 'firewall policy') { need(['before', 'after'].includes(t[2])); const a = Number(t[1]), b = Number(t[3]); need(a !== b); update(e, n, x => { const list = x.device.policies, i = list.findIndex(p => p.id === a); need(i >= 0 && list.some(p => p.id === b), '정책 번호를 확인하세요.'); const [p] = list.splice(i, 1), j = list.findIndex(p => p.id === b); list.splice(j + (t[2] === 'after' ? 1 : 0), 0, p); }); return ''; }
    }
    if (s.mode === 'root') {
        if (t[0] === 'config' && TABLES.includes(t.slice(1).join(' '))) { s.table = t.slice(1).join(' '); s.mode = s.table === 'system global' ? 'global' : 'table'; if (s.mode === 'global') s.draft = {name: n.name, saveMode: n.device.saveMode}; return ''; }
        if (key === 'show') return TABLES.map(table => showTable(n, table)).join('\n');
        if (t[0] === 'show' && TABLES.includes(t.slice(1).join(' '))) return showTable(n, t.slice(1).join(' '));
        if (key === 'get system status') return `OJ Network Lab · FortiOS 7.4 문법 부분집합\nHostname: ${n.name}\nConfiguration save: ${n.device.saveMode}\n가상 장비 · 실제 Fortinet 펌웨어가 아닙니다.`;
        if (key === 'get router info routing-table all') return e.routes(n).map(r => `${r.connected ? 'C' : 'S'} ${r.dest} ${r.via ? 'via ' + r.via : 'directly connected'}, ${portLabel(n, findPort(n, r.dev))}`).join('\n') || '활성 경로가 없습니다.';
        if (t.length === 3 && ['execute ping', 'execute traceroute'].includes(t.slice(0, 2).join(' '))) { e.start(n, t[1] === 'ping' ? 'ping' : 'trace', t[2], {count: 1}); return ''; }
        if (key === 'execute cfg save') { saveStartup(e, n); return 'Configuration saved.'; }
        if (key === 'execute reboot' || key === 'execute cfg reload') { s.pending = 'reboot'; return 'This operation will reboot the system. Do you want to continue? (y/n)'; }
        if (key === 'diagnose sys session list') return [...e.rt(n).conn.values()].filter(c => c.until > e.now && c.allowed).map(c => `session ${c.tuple} policy_id=${c.policy} state=${c.state}`).join('\n') || '활성 허용 세션이 없습니다.';
        if (key === 'diagnose sys session clear') { e.rt(n).conn.clear(); e.rt(n).nat = []; return 'Sessions cleared.'; }
        if (key === 'diagnose vpn tunnel list' || key === 'diagnose vpn ike gateway list') return e.ipsecStatus(n).map(p => `name=${p.name} model_sa=${p.up ? 'up' : 'down'} ${p.reason}${p.up ? ` expires_in=${Math.max(0, Math.ceil((p.until - e.now) / 1000))}s` : ''}`).join('\n') || 'IPsec 터널이 없습니다.';
        if (key === 'execute log display') return e.trace.filter(t => t.node === n.id && ['allow', 'drop', 'ipsec'].includes(t.action)).slice(-30).map(t => `${t.at} ${t.action} ${t.src} → ${t.dst} ${t.detail}`).join('\n') || '관측 기록이 없습니다.';
    }
    throw new Error('Command parse error. 현재 모드에서 ?로 지원 문법을 확인하세요.');
}
export function fortiConfig(n) { return TABLES.map(table => showTable(n, table)).join('\n'); }
