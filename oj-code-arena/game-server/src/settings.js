'use strict';

// Game settings. The WordPress plugin validates the same ranges
// (Support\Game_Settings); the server re-validates everything it receives.
const RANGES = {
    teams:        { min: 2, max: 8, def: 4 },
    maxPlayers:   { min: 2, max: 12, def: 6 },
    limitMin:     { min: 1, max: 30, def: 6 },
    blocks:       { min: 0, max: 144, def: 0 },
    occSec:       { min: 15, max: 60, def: 30 },
    bossEverySec: { min: 10, max: 120, def: 20 },
    bossWaitSec:  { min: 5, max: 20, def: 8 },
    bossLimitSec: { min: 15, max: 90, def: 30 },
};
const PACES = ['slow', 'normal', 'fast'];

function clampInt(v, r) {
    const n = parseInt(v, 10);
    if (!Number.isFinite(n)) return r.def;
    return Math.min(r.max, Math.max(r.min, n));
}

function sanitize(input, mode) {
    const s = input && typeof input === 'object' ? input : {};
    const out = {};
    for (const [k, r] of Object.entries(RANGES)) out[k] = clampInt(s[k], r);
    if (out.blocks > 0) out.blocks = Math.max(24, Math.round(out.blocks / 12) * 12);
    out.penalty = s.penalty === undefined ? true : !!s.penalty;
    out.attacks = mode === 'class' ? !!s.attacks : false;
    out.selfPick = s.selfPick === undefined ? true : !!s.selfPick;
    out.botPace = PACES.includes(s.botPace) ? s.botPace : 'normal';
    out.tags = Array.isArray(s.tags)
        ? [...new Set(s.tags.map((t) => String(t).replace(/[<>&"'`]/g, '').trim().slice(0, 20)).filter(Boolean))].slice(0, 20)
        : [];
    if (mode !== 'class') out.teams = 1;
    return out;
}

module.exports = { RANGES, PACES, sanitize };
