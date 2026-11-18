import { makeNode, emptyProject } from './model.js?v=1.0.2';
export const EXAMPLES = [
    ['half', '반가산기', 'XOR과 AND로 합과 자리올림을 확인합니다.'],
    ['full', '전가산기', '반가산기 두 개와 OR로 1비트 가산기를 만듭니다.'],
    ['dff', 'D 플립플롭', '클록 에지·setup/hold·출력 지연을 관찰합니다.'],
    ['counter', '2비트 동기 카운터', 'T 플립플롭 두 개가 같은 클록을 공유합니다.'],
    ['hazard', '전파 지연과 글리치', 'A와 지연된 NOT A를 AND에 연결합니다.'],
    ['bus', '8비트 버스 가산기', '버스 입력과 가산기로 0x7F + 0x01을 계산합니다.'],
    ['alu', '4비트 ALU', 'S1S0=00 AND · 01 OR · 10 XOR · 11 ADD'],
    ['transfer', '레지스터 전송', '같은 에지에서 R2는 변경 전 R1 값을 받습니다.'],
    ['datapath', '간단한 데이터패스', 'LOAD=1이면 A+B를 저장하고 0이면 현재 값을 유지합니다.'],
];
export function example(name) {
    const p = emptyProject(); p.title = EXAMPLES.find(e => e[0] === name)?.[1] || '새 회로';
    const add = (id, type, x, y, label, config = {}) => { const n = makeNode(type, x, y, id); n.label = label; Object.assign(n.config, config); p.nodes.push(n); };
    const wire = (from, pin, to, input) => p.wires.push({ id: `w${p.wires.length}`, from: { node: from, pin }, to: { node: to, pin: input } });
    if (name === 'half') {
        add('a', 'SWITCH', 120, 120, 'A', { value: 1 }); add('b', 'SWITCH', 120, 336, 'B');
        add('xor', 'XOR', 456, 120, '합 · XOR'); add('and', 'AND', 456, 336, '자리올림 · AND');
        add('sum', 'LED', 804, 120, 'SUM'); add('carry', 'LED', 804, 336, 'CARRY');
        for (const gate of ['xor', 'and']) { wire('a', 'Q', gate, 'A'); wire('b', 'Q', gate, 'B'); }
        wire('xor', 'Q', 'sum', 'A'); wire('and', 'Q', 'carry', 'A');
    } else if (name === 'full') {
        add('a', 'SWITCH', 84, 84, 'A', { value: 1 }); add('b', 'SWITCH', 84, 264, 'B', { value: 1 }); add('ci', 'SWITCH', 84, 504, 'C IN');
        add('ha1', 'HA', 360, 108, '반가산기 1'); add('ha2', 'HA', 684, 228, '반가산기 2'); add('or', 'OR', 960, 480, '자리올림 OR');
        add('sum', 'LED', 1200, 228, 'SUM'); add('co', 'LED', 1200, 480, 'C OUT');
        wire('a', 'Q', 'ha1', 'A'); wire('b', 'Q', 'ha1', 'B'); wire('ha1', 'S', 'ha2', 'A'); wire('ci', 'Q', 'ha2', 'B');
        wire('ha1', 'C', 'or', 'A'); wire('ha2', 'C', 'or', 'B'); wire('ha2', 'S', 'sum', 'A'); wire('or', 'Q', 'co', 'A');
    } else if (name === 'dff') {
        add('d', 'SWITCH', 120, 96, 'DATA', { value: 1 }); add('clk', 'CLOCK', 120, 300, 'CLK', { phase: 10 }); add('rst', 'SWITCH', 120, 504, 'RESET');
        add('ff', 'DFF', 492, 192, 'D 레지스터');
        add('q', 'LED', 852, 192, 'Q'); add('qb', 'LED', 852, 396, 'Q̅');
        wire('d', 'Q', 'ff', 'D'); wire('clk', 'Q', 'ff', 'CLK'); wire('rst', 'Q', 'ff', 'RST'); wire('ff', 'Q', 'q', 'A'); wire('ff', 'QB', 'qb', 'A');
    } else if (name === 'counter') {
        add('clk', 'CLOCK', 96, 336, '공통 CLK', { phase: 10 }); add('one', 'ONE', 96, 96, 'T = 1'); add('rst', 'SWITCH', 96, 576, 'RESET');
        add('t0', 'TFF', 444, 120, '하위 비트'); add('t1', 'TFF', 792, 336, '상위 비트'); add('q0', 'LED', 1128, 120, 'Q0'); add('q1', 'LED', 1128, 336, 'Q1');
        wire('one', 'Q', 't0', 'T'); wire('t0', 'Q', 't1', 'T');
        for (const id of ['t0', 't1']) { wire('clk', 'Q', id, 'CLK'); wire('rst', 'Q', id, 'RST'); }
        wire('t0', 'Q', 'q0', 'A'); wire('t1', 'Q', 'q1', 'A');
    } else if (name === 'bus') {
        add('a', 'BUS_IN', 96, 96, 'A · 8비트', { width: 8, value: '01111111' });
        add('b', 'BUS_IN', 96, 216, 'B · 8비트', { width: 8, value: '00000001' });
        add('zero', 'ZERO', 96, 336, 'CI = 0');
        add('adder', 'WADD', 492, 168, '8비트 덧셈', { width: 8 });
        add('sum', 'BUS_OUT', 900, 168, 'SUM', { width: 8 }); add('co', 'LED', 900, 288, 'C OUT');
        wire('a', 'Q', 'adder', 'A'); wire('b', 'Q', 'adder', 'B'); wire('zero', 'Q', 'adder', 'CI'); wire('adder', 'Q', 'sum', 'A'); wire('adder', 'CO', 'co', 'A');
        p.settings.mode = 'advanced';
    } else if (name === 'alu') {
        add('a', 'BUS_IN', 60, 60, 'A', { width: 4, value: '0101' }); add('b', 'BUS_IN', 60, 204, 'B', { width: 4, value: '0011' });
        add('s0', 'SWITCH', 60, 396, 'S0'); add('s1', 'SWITCH', 60, 540, 'S1'); add('zero', 'ZERO', 60, 708, 'CI=0');
        for (const [id, type, y] of [['and', 'AND', 60], ['or', 'OR', 252], ['xor', 'XOR', 444], ['add', 'WADD', 636]]) { add(id, type, 384, y, id.toUpperCase(), { width: 4 }); wire('a', 'Q', id, 'A'); wire('b', 'Q', id, 'B'); }
        add('m0', 'MUX', 720, 132, '논리 1', { width: 4 }); add('m1', 'MUX', 720, 516, '논리 2', { width: 4 }); add('m2', 'MUX', 1056, 324, '연산 선택', { width: 4 }); add('y', 'BUS_OUT', 1392, 324, 'RESULT', { width: 4 });
        wire('zero', 'Q', 'add', 'CI'); wire('and', 'Q', 'm0', 'A'); wire('or', 'Q', 'm0', 'B'); wire('xor', 'Q', 'm1', 'A'); wire('add', 'Q', 'm1', 'B');
        for (const id of ['m0', 'm1']) wire('s0', 'Q', id, 'S'); wire('m0', 'Q', 'm2', 'A'); wire('m1', 'Q', 'm2', 'B'); wire('s1', 'Q', 'm2', 'S'); wire('m2', 'Q', 'y', 'A'); p.settings.mode = 'advanced';
        p.notes = [{ id: 'alu-guide', x: 1056, y: 588, text: 'S1S0\n00 AND · 01 OR\n10 XOR · 11 ADD\n4비트 결과: ADD는 mod 16' }]; p.netNames = { 'm2:Q': 'ALU_RESULT' };
    } else if (name === 'transfer') {
        add('d', 'BUS_IN', 60, 96, '새 입력', { width: 4, value: '1001' }); add('clk', 'CLOCK', 60, 312, '공통 CLK', { phase: 10 }); add('rst', 'SWITCH', 60, 528, 'RESET');
        add('r1', 'WREG', 456, 96, 'R1', { width: 4, initial: '0011' }); add('r2', 'WREG', 864, 96, 'R2', { width: 4, initial: '0000' }); add('q1', 'BUS_OUT', 456, 360, 'R1 값', { width: 4 }); add('q2', 'BUS_OUT', 864, 360, 'R2 값', { width: 4 });
        wire('d', 'Q', 'r1', 'D'); wire('r1', 'Q', 'r2', 'D'); for (const id of ['r1', 'r2']) { wire('clk', 'Q', id, 'CLK'); wire('rst', 'Q', id, 'RST'); } wire('r1', 'Q', 'q1', 'A'); wire('r2', 'Q', 'q2', 'A'); p.settings.mode = 'advanced';
        p.notes = [{ id: 'transfer-guide', x: 456, y: 540, text: '첫 클록: R1=9, R2=3\n두 번째 클록: R2=9\n동일 에지에서 이전 상태를 샘플링' }];
    } else if (name === 'datapath') {
        add('a', 'BUS_IN', 60, 48, 'A', { width: 4, value: '0111' }); add('b', 'BUS_IN', 60, 240, 'B', { width: 4, value: '0001' }); add('load', 'SWITCH', 60, 432, 'LOAD', { value: 1 }); add('clk', 'CLOCK', 60, 624, 'CLK', { phase: 10 }); add('zero', 'ZERO', 60, 816, 'CI/RST=0');
        add('alu', 'WADD', 432, 144, 'A + B', { width: 4 }); add('mux', 'MUX', 432, 420, '유지 / 저장', { width: 4 }); add('reg', 'WREG', 828, 144, '결과 레지스터', { width: 4 }); add('q', 'BUS_OUT', 1212, 144, '결과', { width: 4 });
        wire('a', 'Q', 'alu', 'A'); wire('b', 'Q', 'alu', 'B'); wire('zero', 'Q', 'alu', 'CI'); wire('reg', 'Q', 'mux', 'A'); wire('alu', 'Q', 'mux', 'B'); wire('load', 'Q', 'mux', 'S'); wire('mux', 'Q', 'reg', 'D'); wire('clk', 'Q', 'reg', 'CLK'); wire('zero', 'Q', 'reg', 'RST'); wire('reg', 'Q', 'q', 'A'); p.settings.mode = 'advanced';
        p.notes = [{ id: 'datapath-guide', x: 828, y: 420, text: 'LOAD=1: 클록에서 A+B 저장\nLOAD=0: 이전 결과 유지\n입력 → 조합회로 → 저장 → 출력' }]; p.netNames = { 'reg:Q': 'ACC', 'alu:Q': 'ADD_RESULT' };
    } else if (name === 'hazard') {
        add('a', 'SWITCH', 120, 144, 'A'); add('not', 'NOT', 432, 336, 'NOT · 8 ns', { delay: 8 }); add('and', 'AND', 768, 144, 'AND · 1 ns', { delay: 1 }); add('y', 'PROBE', 1104, 144, 'GLITCH');
        wire('a', 'Q', 'not', 'A'); wire('a', 'Q', 'and', 'A'); wire('not', 'Q', 'and', 'B'); wire('and', 'Q', 'y', 'A');
    }
    return p;
}
