import {command, HELP} from './commands.js?ver=1.4.0-rc.4';
import {iosCommand, iosPatterns, iosPrompt, iosHelp} from './cli-ios.js?ver=1.4.0-rc.4';
import {fortiCommand, fortiPatterns, fortiPrompt} from './cli-fortios.js?ver=1.4.0-rc.4';
import {suggestions} from './cli-common.js?ver=1.4.0-rc.4';
import {outputCommand, outputComplete, pipelineParts} from './output-tools.js?ver=1.4.0-rc.4';
import {TOOLS_HELP} from './diagnostics.js?ver=1.4.0-rc.4';
import {windowsCommand, windowsComplete} from './cli-windows.js?ver=1.4.0-rc.4';
import {WINDOWS_HELP} from './windows-help.js?ver=1.4.0-rc.4';
export function session(e, n) { let s = e.consoles.get(n.id); if (!s || s.profile !== n.profile) { s = {profile: n.profile, mode: n.profile === 'ios' ? 'user' : 'root', pending: '', status: 0, history: [], ...(n.profile === 'windows' ? {shell: 'cmd', cwd: 'C:\\Lab'} : {})}; e.consoles.set(n.id, s); } return s; }
export function prompt(e, n) { const s = session(e, n); return n.profile === 'windows' ? `${s.shell === 'powershell' ? 'PS ' : ''}${s.cwd}>` : n.profile === 'ios' ? iosPrompt(n, s) : n.profile === 'fortios' ? fortiPrompt(n, s) : `${n.name} $`; }
export function deviceCommand(e, n, line, previous = 0) {
    const s = session(e, n), native = text => n.profile === 'ios' ? iosCommand(e, n, text, s) : n.profile === 'fortios' ? fortiCommand(e, n, text, s) : command(e, n, text);
    if (n.profile === 'windows' && !/^\s*lab(?:\s|$)/.test(line)) { const result = windowsCommand(e, n, s, line, previous); s.status = result.status; return result.text.replace(/\n$/, ''); }
    if (n.type === 'hub' || s.pending) return native(line);
    if (n.profile !== 'linux' && line.includes('|') && line.trimEnd().endsWith('?')) { let help = false; const prefix = line.trimEnd().slice(0, -1); try { help = pipelineParts(prefix, false).length > 1; } catch {} if (help) { const result = outputComplete(e, n, s, prefix); return `출력 분석 도움말\n${result?.choices.join('\n') || 'lab help로 전체 문법을 확인하세요.'}`; } }
    const output = outputCommand(e, n, s, n.profile === 'linux' ? line.replace(/^\s*sudo\s+/, '') : line, native, previous);
    if (output) { s.status = output.status; return output.text.replace(/\n$/, ''); }
    const result = native(line); return ['help', '?'].includes(line.trim()) ? result + '\n\n' + TOOLS_HELP : result;
}
export function complete(e, n, line) { const s = session(e, n); if (n.profile === 'windows' && !/^\s*lab(?:\s|$)/.test(line)) return windowsComplete(n, s, line); const tools = outputComplete(e, n, s, line); return tools || suggestions(n, line, n.profile === 'ios' ? iosPatterns(s) : fortiPatterns(s)); }
export function interrupt(e, n) { const s = session(e, n); s.pending = ''; if (n.profile === 'ios' && !['user', 'priv'].includes(s.mode)) s.mode = 'priv'; if (n.profile === 'fortios') { s.mode = 'root'; delete s.draft; } e.rt(n).capture = null; return '^C'; }
export function deviceHelp(e, n) { return n.profile === 'windows' ? WINDOWS_HELP : n.profile === 'ios' ? iosHelp(n, session(e, n)) : n.profile === 'fortios' ? fortiCommand(e, n, 'help', session(e, n)) : HELP; }
