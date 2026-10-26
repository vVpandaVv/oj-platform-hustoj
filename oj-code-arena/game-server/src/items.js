'use strict';

// Item catalog. "help" items go into a player's deck (5 slots), "attack"
// items too (only when the teacher turned attacks on), "shield" is counted
// separately, and "penalty" items fire immediately on the finder's team.
const ITEMS = {
    bomb:   { kind: 'help', name: '폭탄', desc: '주변 8칸을 한 번에 풀어요' },
    sweep:  { kind: 'help', name: '가로 쓸기', desc: '내 오른쪽 칸을 줄 끝까지 풀어요' },
    drop:   { kind: 'help', name: '세로 쓸기', desc: '내 아래 칸을 맨 아래까지 풀어요' },
    hint:   { kind: 'help', name: '자동 완성', desc: '12초 동안 Tab 으로 코드를 끝까지 채워요' },
    shrink: { kind: 'help', name: '짧게 하기', desc: '주변 칸의 코드를 짧은 코드로 바꿔요' },

    stall:  { kind: 'penalty', name: '정전', desc: '우리 팀 전원이 5초 동안 멈춰요' },

    shield: { kind: 'shield', name: '방패', desc: '들어오는 공격 하나를 막아요 (Ctrl+Shift+9)' },

    ice:    { kind: 'attack', name: '얼음', desc: '상대 팀이 7초 동안 아무것도 못 해요' },
    fog:    { kind: 'attack', name: '안개', desc: '상대 팀 화면의 코드가 8초 동안 가려져요' },
    undo:   { kind: 'attack', name: '되돌리기', desc: '상대 팀이 푼 칸 5개를 다시 잠가요' },
    scatter:{ kind: 'attack', name: '흩어지기', desc: '상대 팀원을 아무 칸으로 순간 이동시켜요' },
    mirror: { kind: 'attack', name: '거울', desc: '상대 팀의 방향키가 8초 동안 반대로 바뀌어요' },
};

const HELP = ['bomb', 'sweep', 'drop', 'hint', 'shrink'];
const ATTACK = ['ice', 'fog', 'undo', 'scatter', 'mirror'];

const DURATION = { stall: 5000, hint: 12000, ice: 7000, fog: 8000, mirror: 8000 };
const UNDO_COUNT = 5;
const DECK_SIZE = 5;
const MAX_SHIELDS = 2;
const DEFEND_MS = 2500;
const PENALTY_RATE = 0.12;

// Item for beating a boss.
function rollReward(rand, { penalty, attacks }) {
    const r = rand();
    if (penalty && r < PENALTY_RATE) return 'stall';
    if (attacks) {
        if (r < PENALTY_RATE + 0.23) return 'shield';
        if (r < PENALTY_RATE + 0.48) return ATTACK[Math.floor(rand() * ATTACK.length)];
    }
    return HELP[Math.floor(rand() * HELP.length)];
}

// Item for everyone who joined a successful gather boss (never a penalty).
function rollGather(rand, { attacks }) {
    if (attacks && rand() < 0.3) return 'shield';
    return HELP[Math.floor(rand() * HELP.length)];
}

function publicItem(id) {
    const it = ITEMS[id];
    return it ? { id, kind: it.kind, name: it.name, desc: it.desc } : null;
}

module.exports = {
    ITEMS, HELP, ATTACK, DURATION, UNDO_COUNT, DECK_SIZE, MAX_SHIELDS, DEFEND_MS,
    rollReward, rollGather, publicItem,
};
