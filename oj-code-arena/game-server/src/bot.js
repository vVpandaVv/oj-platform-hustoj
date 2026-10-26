'use strict';

// Practice bots: they walk to a nearby free block, "type" for a while and
// submit (sometimes wrong). They never fight bosses or use items.
const PACE_MS = { slow: 18000, normal: 11000, fast: 6500 };
const WRONG_RATE = 0.1;

function nearestFree(match, p, rand) {
    const found = [];
    for (let i = 0; i < match.cells.length; i++) {
        if (!match.isFree(i)) continue;
        const r = Math.floor(i / match.cols);
        const c = i % match.cols;
        found.push({ i, d: Math.abs(r - p.r) + Math.abs(c - p.c) });
    }
    if (!found.length) return -1;
    found.sort((a, b) => a.d - b.d);
    const top = found.slice(0, 3);
    return top[Math.floor(rand() * top.length)].i;
}

function tick(match, p, now) {
    const b = p.botState || (p.botState = { nextAt: now + 800 + match.rand() * 800, target: -1, typingUntil: 0 });
    if (now < b.nextAt) return;
    if (match.isFrozen(now)) { b.nextAt = now + 400; return; }

    if (p.occ >= 0) {
        if (now >= b.typingUntil) {
            const code = match.cells[p.occ].code;
            match.submit(p.id, match.rand() < WRONG_RATE ? code + '#' : code);
            b.target = -1;
            b.nextAt = now + 500 + match.rand() * 500;
        } else {
            b.nextAt = now + 300;
        }
        return;
    }

    if (b.target < 0 || !match.isFree(b.target)) b.target = nearestFree(match, p, match.rand);
    if (b.target < 0) { b.nextAt = now + 1000; return; }

    const tr = Math.floor(b.target / match.cols);
    const tc = b.target % match.cols;
    if (tr === p.r && tc === p.c) {
        const res = match.occupy(p.id);
        if (res.ok) {
            const pace = PACE_MS[match.botPace] || PACE_MS.normal;
            b.typingUntil = now + pace * (0.55 + match.rand() * 0.35);
        } else {
            b.target = -1;
        }
        b.nextAt = now + 300;
        return;
    }
    const tries = [];
    if (tr !== p.r) tries.push(tr > p.r ? 'D' : 'U');
    if (tc !== p.c) tries.push(tc > p.c ? 'R' : 'L');
    const start = p.r * match.cols + p.c;
    for (const dir of tries) {
        match.move(p.id, { dir, seq: -1 });
        if (p.r * match.cols + p.c !== start) break;
    }
    if (p.r * match.cols + p.c === start) b.target = -1; // blocked (boss in the way)
    b.nextAt = now + 260 + match.rand() * 140;
}

module.exports = { tick, PACE_MS };
