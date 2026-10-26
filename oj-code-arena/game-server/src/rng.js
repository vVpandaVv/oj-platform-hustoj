'use strict';

// Small seeded PRNG (mulberry32) so every team in a session gets the same
// board, boss order and gather-boss positions.
function seeded(seed) {
    let a = seed >>> 0;
    const next = () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    return {
        next,
        int: (n) => Math.floor(next() * n),
        pick: (arr) => arr[Math.floor(next() * arr.length)],
        shuffle(arr) {
            const out = arr.slice();
            for (let i = out.length - 1; i > 0; i--) {
                const j = Math.floor(next() * (i + 1));
                [out[i], out[j]] = [out[j], out[i]];
            }
            return out;
        },
    };
}

function randomSeed() {
    return (Math.random() * 0xffffffff) >>> 0;
}

module.exports = { seeded, randomSeed };
