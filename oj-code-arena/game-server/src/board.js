'use strict';

const { seeded } = require('./rng');

const COLS = 12;
const MIN_BLOCKS = 24;
const MAX_BLOCKS = 144;

function normalizeBlocks(n) {
    const v = Math.round((Number(n) || 0) / COLS) * COLS;
    return Math.min(MAX_BLOCKS, Math.max(MIN_BLOCKS, v));
}

// "auto" (0): about 24 blocks per person, at most 96.
function blocksFor(setting, people) {
    if (Number(setting) > 0) return normalizeBlocks(setting);
    return normalizeBlocks(Math.min(96, Math.max(1, people) * 24));
}

// Same seed + same codes → same layout, so every team plays the same board.
function buildCells(seed, codes, blocks) {
    const n = normalizeBlocks(blocks);
    const rng = seeded(seed);
    const pool = codes.length ? codes : ['print(1)'];
    let order = [];
    while (order.length < n) order = order.concat(rng.shuffle(pool));
    return order.slice(0, n).map((code) => ({ code, solved: false, by: null, crown: null }));
}

function shortCodes(codes, max = 9) {
    const short = codes.filter((c) => c.replace(/\s+/g, '').length <= max);
    return short.length ? short : ['print(1)', 'x = 1', 'a = 0', "print('')"];
}

module.exports = { COLS, MIN_BLOCKS, MAX_BLOCKS, normalizeBlocks, blocksFor, buildCells, shortCodes };
