import { emptyProject, makeNode } from './model.js?v=1.0.2';
import { BeginnerEngine } from './modes.js?v=1.0.2';
export const EXERCISES = [
    { id: 'and', name: '01 · AND 회로', goal: 'A와 B가 모두 1일 때만 Y가 1이 되게 만드세요.', hints: ['AND 게이트 한 개를 추가하세요.', 'A의 Q → AND의 A, B의 Q → AND의 B를 연결하세요.', 'AND의 Q를 Y의 A에 연결하세요.'] },
    { id: 'xor', name: '02 · XOR 회로', goal: 'A와 B가 서로 다를 때만 Y가 1이 되게 만드세요.', hints: ['입력이 다른지 비교하는 XOR 게이트를 사용하세요.', '두 스위치를 XOR의 A와 B에 연결하세요.', 'XOR의 Q를 Y에 연결하세요.'] },
    { id: 'half', name: '03 · 반가산기', goal: '두 비트의 SUM과 CARRY를 구하세요.', hints: ['SUM은 두 입력이 다를 때 1입니다.', 'CARRY는 두 입력이 모두 1일 때 1입니다.', 'XOR → SUM, AND → CARRY로 연결하세요.'] },
    { id: 'full', name: '04 · 전가산기', goal: 'A+B+CI의 SUM과 CARRY를 구하세요.', hints: ['전가산기 한 개 또는 반가산기 두 개와 OR를 사용할 수 있습니다.', '첫 반가산기의 합과 CI를 두 번째 반가산기에 넣으세요.', '두 반가산기의 자리올림을 OR로 합치세요.'] },
    { id: 'dff', name: '05 · 값 기억하기', goal: 'DATA는 클록에서만 Q에 저장되고 RESET=1이면 즉시 0이 되게 만드세요.', hints: ['D 플립플롭을 추가하세요.', 'DATA → D, CLK → CLK, RESET → RST를 연결하세요.', 'D 플립플롭의 Q를 Q 출력에 연결하세요.'] },
    { id: 'counter', name: '06 · 2비트 카운터', goal: '클록마다 Q1Q0가 00 → 01 → 10 → 11 → 00으로 진행하고 RESET으로 00이 되게 만드세요.', hints: ['T 플립플롭 두 개와 상수 1을 사용하세요.', '두 CLK는 공통 클록에, 두 RST는 RESET에 연결하세요.', '하위 T에는 1, 상위 T에는 하위 Q를 연결하세요.'] },
];
export function exerciseProject(id) {
    const task = EXERCISES.find(e => e.id === id); if (!task) throw new Error('실습 과제가 없습니다.');
    const p = emptyProject(); p.title = task.name; p.exercise = { id, attempts: 0, solved: false };
    const add = (id, type, x, y, label, value = 0) => { const n = makeNode(type, x, y, id); n.label = label; n.config.value = value; p.nodes.push(n); };
    if (['and', 'xor', 'half', 'full'].includes(id)) {
        add('inA', 'SWITCH', 96, 96, 'A'); add('inB', 'SWITCH', 96, 312, 'B');
        if (id === 'full') add('inCI', 'SWITCH', 96, 528, 'CI');
        add('outY', 'LED', 960, 144, ['half', 'full'].includes(id) ? 'SUM' : 'Y');
        if (['half', 'full'].includes(id)) add('outC', 'LED', 960, 384, 'CARRY');
    } else {
        add('clock', 'CLOCK', 96, 288, 'CLK'); add('reset', 'SWITCH', 96, 528, 'RESET');
        if (id === 'dff') add('data', 'SWITCH', 96, 96, 'DATA');
        add('outY', 'LED', 960, 144, id === 'dff' ? 'Q' : 'Q0');
        if (id === 'counter') add('outC', 'LED', 960, 384, 'Q1');
    }
    return p;
}
export function gradeExercise(project) {
    const id = project.exercise?.id, task = EXERCISES.find(e => e.id === id);
    if (!task) throw new Error('먼저 실습 과제를 여세요.');
    const copy = structuredClone(project); copy.stimuli = [];
    const required = exerciseProject(id).nodes;
    if (required.some(n => !copy.nodes.some(o => o.id === n.id && o.type === n.type))) return { passed: false, checks: 0, failures: ['과제의 기본 입력·출력 장치를 삭제했습니다. 과제를 다시 열거나 실행 취소로 복원하세요.'] };
    const failures = []; let checks = 0;
    const test = (e, label, expected) => {
        for (const [node, value] of Object.entries(expected)) { checks++; const actual = e.input(node, 'A'); if (actual !== value) failures.push(`${label}: ${node === 'outY' ? ['half', 'full'].includes(id) ? 'SUM' : id === 'counter' ? 'Q0' : 'Y/Q' : id === 'counter' ? 'Q1' : 'CARRY'}=${actual}, 정답=${value}`); }
        if (e.fault) failures.push(e.fault);
    };
    if (['and', 'xor', 'half', 'full'].includes(id)) {
        for (const a of [0, 1]) for (const b of [0, 1]) for (const c of id === 'full' ? [0, 1] : [0]) {
            const p = structuredClone(copy); for (const [node, value] of [['inA', a], ['inB', b], ['inCI', c]]) { const n = p.nodes.find(n => n.id === node); if (n) n.config.value = value; }
            const sum = a + b + c, expected = id === 'and' ? { outY: a & b } : id === 'xor' ? { outY: a ^ b } : { outY: sum % 2, outC: sum >= 2 ? 1 : 0 };
            test(new BeginnerEngine(p), `A=${a}, B=${b}${id === 'full' ? `, CI=${c}` : ''}`, expected);
        }
    } else {
        for (const n of copy.nodes) if (n.config) { if (['DFF', 'TFF', 'JKFF', 'SRFF'].includes(n.type)) n.config.initial = 0; if (['reset', 'data'].includes(n.id)) n.config.value = 0; }
        const e = new BeginnerEngine(copy);
        test(e, '초기 상태', id === 'counter' ? { outY: 0, outC: 0 } : { outY: 0 });
        if (id === 'dff') {
            e.setInput('data', 1); test(e, '클록 전 유지', { outY: 0 }); e.pulse(); test(e, '클록에서 1 저장', { outY: 1 });
            e.setInput('data', 0); test(e, '클록 전 1 유지', { outY: 1 }); e.pulse(); test(e, '클록에서 0 저장', { outY: 0 });
            e.setInput('data', 1); e.pulse(); e.setInput('reset', 1); test(e, '비동기 리셋', { outY: 0 });
        } else {
            for (let i = 1; i <= 5; i++) { e.pulse(); test(e, `클록 ${i}회`, { outY: i % 2, outC: Math.floor(i / 2) % 2 }); }
            e.setInput('reset', 1); test(e, '비동기 리셋', { outY: 0, outC: 0 });
        }
    }
    return { passed: !failures.length, checks, failures: failures.slice(0, 12) };
}
