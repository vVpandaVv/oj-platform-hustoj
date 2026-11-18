import {cidr} from './model.js?ver=1.4.0-rc.4';
import {configSnapshot, portLabel} from './device-config.js?ver=1.4.0-rc.4';
import {iosConfig} from './cli-ios.js?ver=1.4.0-rc.4';
import {fortiConfig} from './cli-fortios.js?ver=1.4.0-rc.4';
export {TOOLS_HELP} from './text-help.js?ver=1.4.0-rc.4';
export const textFile = value => value ? String(value).replace(/\n?$/, '\n') : '';
export function virtualPath(value = '/') {
    if (typeof value !== 'string' || /[\\\x00]/.test(value) || !/^[A-Za-z0-9_./-]+$/.test(value)) throw new Error('가상 파일 경로를 확인하세요.');
    const parts = value.split('/'); if (parts.includes('..')) throw new Error('상위 경로 탐색은 지원하지 않습니다.'); return '/' + parts.filter(x => x && x !== '.').join('/');
}
export function diagnosticContext(e, n, s, previous, meter) {
    const rt = e.rt(n), paths = new Map(), cache = new Map();
    const add = (path, read) => paths.set(path, () => textFile(read()));
    const config = (node, startup = false) => { if (node.profile === 'ios' && s.mode === 'user') throw new Error('설정 파일 조회는 enable 후 사용하세요.'); const selected = startup ? {...node, ...node.startup} : node; return selected.profile === 'ios' ? iosConfig(selected) : selected.profile === 'fortios' ? fortiConfig(selected) : JSON.stringify(configSnapshot(selected), null, 2); };
    add('/config/running.conf', () => config(n)); if (['ios', 'fortios'].includes(n.profile)) add('/config/startup.conf', () => config(n, true));
    if (n.profile === 'windows') add('/state/firewall.txt', () => JSON.stringify(n.windows, null, 2));
    add('/etc/hostname', () => n.name); add('/etc/resolv.conf', () => n.resolver ? `nameserver ${n.resolver}` : '# DNS 서버가 설정되지 않았습니다.');
    add('/state/interfaces.txt', () => ['Interface Address Admin Link Mode VLAN', ...n.interfaces.map(f => `${portLabel(n, f)} ${f.address || 'unassigned'} ${f.up ? 'up' : 'down'} ${e.portUp(n, f) ? 'up' : 'down'} ${f.mode} ${f.vlan}`)].join('\n'));
    add('/state/routes.txt', () => ['Destination Gateway Interface Metric', ...e.routes(n).map(r => `${r.dest} ${r.via || 'direct'} ${portLabel(n, n.interfaces.find(f => f.name === r.dev))} ${r.metric}`)].join('\n'));
    add('/state/arp.txt', () => [...rt.arp.values()].filter(a => a.until > e.now).map(a => `${a.ip} ${a.mac} ${portLabel(n, n.interfaces.find(f => f.name === a.port))}`).join('\n'));
    add('/state/mac.txt', () => [...rt.fdb.values()].filter(a => a.until > e.now).map(a => `${a.vlan} ${a.mac} ${portLabel(n, n.interfaces.find(f => f.name === a.port))}`).join('\n'));
    add('/state/sessions.txt', () => [...rt.conn.values()].filter(a => a.until > e.now).map(a => `${a.tuple} ${a.state} policy=${a.policy ?? '-'} allowed=${a.allowed ?? '-'}`).join('\n'));
    add('/state/nat.txt', () => rt.nat.filter(a => a.until > e.now).map(a => `${a.before.protocol} ${a.before.src}:${a.before.sport} ${a.before.dst}:${a.before.dport} -> ${a.after.src}:${a.after.sport} ${a.after.dst}:${a.after.dport}`).join('\n'));
    add('/state/vpn.txt', () => [...rt.vpn.values()].map(v => `WireGuard ${v.interface} handshake=${v.handshake ?? 'never'} endpoint=${v.endpoint || '-'}`).concat(n.profile === 'fortios' ? e.ipsecStatus(n).map(v => `IPsec ${v.name} up=${v.up} ${v.reason}`) : []).join('\n'));
    add('/var/log/network.log', () => e.trace.filter(v => v.node === n.id).map(v => `${v.at}ms ${v.action} ${v.protocol || '-'} ${v.src || '-'}:${v.sport} -> ${v.dst || '-'}:${v.dport} ${v.port} ${v.detail}`).join('\n'));
    add('/history/commands.txt', () => (s.history || []).map((line, i) => `${i + 1}  ${line}`).join('\n'));
    const read = value => { const path = virtualPath(value); if (!paths.has(path)) throw new Error(`가상 파일이 없습니다: ${path} (lab files로 확인하세요)`); if (!cache.has(path)) cache.set(path, paths.get(path)()); return cache.get(path); };
    const list = value => { const path = virtualPath(value); if (paths.has(path)) return [path]; const prefix = path === '/' ? '/' : path + '/', result = new Set(); for (const file of paths.keys()) if (file.startsWith(prefix)) { const rest = file.slice(prefix.length), slash = rest.indexOf('/'); result.add(slash < 0 ? rest : rest.slice(0, slash) + '/'); } if (!result.size) throw new Error('가상 디렉터리가 없습니다.'); return [...result].sort(); };
    return {read, list, paths: [...paths.keys()], previous, meter, hostname: n.name, profile: n.profile,
        stats: () => `가상 장비 ${n.name} (${n.profile})\nvirtual_time_ms=${e.now}\nprocessed_events=${e.processed}\nqueued_events=${e.queue.length}\nnode_trace_entries=${e.trace.filter(t => t.node === n.id).length}\narp_entries=${rt.arp.size}\nmac_entries=${rt.fdb.size}\nsession_entries=${rt.conn.size}\nnat_bindings=${rt.nat.length}\n실제 서버 CPU·메모리·가동 시간이 아닙니다.`,
    };
}
