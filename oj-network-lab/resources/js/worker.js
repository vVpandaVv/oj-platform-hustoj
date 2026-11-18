import {Engine} from './engine.js?ver=1.4.0-rc.4';
let engine = null, playing = false, timer = null, publishedRevision = 0, configurationRequest = 0;
function publish() {
    if (engine.revision !== publishedRevision) { publishedRevision = engine.revision; postMessage({type: 'changed'}); }
    for (const item of engine.drain()) postMessage({type: 'terminal', ...item});
    postMessage({type: 'state', ...engine.state(), playing, configurationRequest});
}
function stop() { playing = false; if (timer !== null) clearTimeout(timer); timer = null; }
function tick() { timer = null; if (!playing || !engine) return; if (!engine.step(8)) stop(); publish(); if (playing) timer = setTimeout(tick, 60); }
onmessage = event => {
    try {
        const m = event.data;
        if (m.type === 'load') { stop(); engine = new Engine(m.project); publishedRevision = 0; configurationRequest = 0; publish(); return; }
        if (!engine) throw new Error('실습 엔진이 아직 준비되지 않았습니다.');
        if (m.type === 'command' || m.type === 'batch') {
            const lines = m.type === 'batch' ? m.lines : [m.line];
            if (!Array.isArray(lines) || lines.length > 100 || lines.some(line => typeof line !== 'string' || line.length > 2048)) throw new Error('붙여넣기는 명령 100줄, 한 줄 2048자 이내입니다.');
            for (let i = 0; i < lines.length; i++) { const line = lines[i]; engine.say(m.node, `${engine.consolePrompt(m.node)} ${line}`); try { engine.execute(m.node, line); } catch (error) { engine.say(m.node, `${error.message}${lines.length > 1 ? `\n${i + 1}번째 줄에서 중지했습니다. 앞선 명령은 적용되었습니다.` : ''}`); postMessage({type: 'error', message: error.message, node: m.node, printed: true}); break; } }
            if (m.autoplay && engine.queue.length && !playing) { playing = true; timer = setTimeout(tick, 60); }
        }
        else if (m.type === 'configure') { engine.configure(m.project); configurationRequest = m.requestId; }
        else if (m.type === 'complete') { postMessage({type: 'complete', node: m.node, original: m.line, ...engine.complete(m.node, m.line)}); return; }
        else if (m.type === 'interrupt') engine.interrupt(m.node);
        else if (m.type === 'play') { if (!playing && engine.queue.length) { playing = true; timer = setTimeout(tick, 60); } }
        else if (m.type === 'pause') stop();
        else if (m.type === 'step') { stop(); engine.step(); }
        else if (m.type === 'cancel') { stop(); engine.cancel(); }
        else if (m.type === 'trace') postMessage({type: 'trace', trace: engine.trace});
        else throw new Error('지원하지 않는 엔진 요청입니다.');
        publish();
    } catch (error) { postMessage({type: 'error', node: event.data.node, configure: event.data.type === 'configure', requestId: event.data.requestId, message: error.message || '실습 처리 오류'}); if (engine) publish(); }
};
