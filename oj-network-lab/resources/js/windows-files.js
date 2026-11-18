import {diagnosticContext} from './diagnostics.js?ver=1.4.0-rc.4';
import {budget} from './text-pattern.js?ver=1.4.0-rc.4';
import {winNeed} from './windows-parser.js?ver=1.4.0-rc.4';
export function windowsPath(value, cwd = 'C:\\Lab') {
    winNeed(typeof value === 'string' && value.length <= 256 && !/[\x00-\x1f]/.test(value), '가상 Windows 경로를 확인하세요.');
    let path = value.replace(/\//g, '\\'); winNeed(!path.startsWith('\\\\'), 'UNC·원격 파일 경로는 미지원입니다.');
    if (/^[A-Za-z]:/.test(path)) winNeed(/^C:\\/i.test(path), '가상 C:\\Lab 안의 경로만 지원합니다.');
    else path = path.startsWith('\\') ? 'C:' + path : cwd + '\\' + path;
    const parts = []; for (const part of path.slice(3).split('\\')) { if (!part || part === '.') continue; if (part === '..') { winNeed(parts.length > 1, '가상 Lab 밖으로 이동할 수 없습니다.'); parts.pop(); } else { winNeed(/^[A-Za-z0-9_. -]+$/.test(part), '가상 경로 문자를 확인하세요.'); parts.push(part); } }
    winNeed(parts[0]?.toLowerCase() === 'lab', '가상 C:\\Lab 안의 경로만 지원합니다.'); parts[0] = 'Lab'; return 'C:\\' + parts.join('\\');
}
export function windowsFiles(e, n, s, previous) {
    const ctx = diagnosticContext(e, n, s, previous, budget()), files = ctx.paths.map(path => ({native: 'C:\\Lab' + path.replace(/\//g, '\\'), path}));
    const cwd = () => s.cwd || 'C:\\Lab';
    const read = value => { const key = windowsPath(value, cwd()).toLowerCase(), item = files.find(f => f.native.toLowerCase() === key); winNeed(item, '가상 파일이 없습니다. dir 또는 lab files로 확인하세요.'); return ctx.read(item.path); };
    const directory = value => { const path = windowsPath(value || '.', cwd()); winNeed(files.some(f => f.native.toLowerCase().startsWith(path.toLowerCase() + '\\')), '가상 디렉터리가 없습니다.'); return path; };
    const entries = value => { const path = windowsPath(value || '.', cwd()), exact = files.find(f => f.native.toLowerCase() === path.toLowerCase()); if (exact) return [{Name: exact.native.split('\\').at(-1), FullName: exact.native, Mode: '-r---', Length: new TextEncoder().encode(read(exact.native)).length}]; directory(path); const out = new Map(); for (const file of files) if (file.native.toLowerCase().startsWith(path.toLowerCase() + '\\')) { const rest = file.native.slice(path.length + 1), name = rest.split('\\')[0], nested = rest.includes('\\'); if (!out.has(name)) out.set(name, {Name: name, FullName: path + '\\' + name, Mode: nested ? 'd----' : '-r---', Length: nested ? 0 : new TextEncoder().encode(read(file.native)).length}); } return [...out.values()]; };
    return {read, directory, entries, cwd};
}
