/*
 * Code Arena avatars (8 shapes × 8 colors, inline SVG) and sound effects
 * (WebAudio, no audio files).
 */
(function (window) {
    'use strict';

    var COLORS = ['#ff7a59', '#ffc23d', '#5ad17c', '#3fb8ff', '#9b7bff', '#ff6fb5', '#2fd3c4', '#c7d0dd'];
    var NAMES = ['방울', '로봇', '고양이', '유령', '별', '새', '상자', '버섯'];

    var EYES = '<circle cx="12" cy="15" r="2.2" fill="#14213d"/><circle cx="20" cy="15" r="2.2" fill="#14213d"/>';
    var BODIES = [
        '<path d="M16 4c7 0 12 6 12 13s-5 11-12 11S4 24 4 17 9 4 16 4z" fill="C"/>' + EYES,
        '<rect x="6" y="8" width="20" height="18" rx="4" fill="C"/><rect x="15" y="3" width="2" height="5" fill="C"/><circle cx="16" cy="3" r="2" fill="C"/>' +
            '<rect x="10" y="13" width="4" height="4" rx="1" fill="#14213d"/><rect x="18" y="13" width="4" height="4" rx="1" fill="#14213d"/><rect x="11" y="20" width="10" height="2" rx="1" fill="#14213d"/>',
        '<path d="M6 10l3-7 5 5h4l5-5 3 7v10c0 5-4 8-10 8S6 25 6 20z" fill="C"/>' + EYES + '<path d="M14 20h4l-2 2z" fill="#14213d"/>',
        '<path d="M6 16c0-7 4-12 10-12s10 5 10 12v12l-3-3-3 3-4-3-4 3-3-3-3 3z" fill="C"/>' + EYES,
        '<path d="M16 3l3.6 7.6 8.4 1-6.2 5.8 1.6 8.3L16 21.6l-7.4 4.1 1.6-8.3L4 11.6l8.4-1z" fill="C"/><circle cx="13.5" cy="15" r="1.6" fill="#14213d"/><circle cx="18.5" cy="15" r="1.6" fill="#14213d"/>',
        '<ellipse cx="16" cy="18" rx="11" ry="9" fill="C"/><path d="M24 17l6 2-6 2z" fill="#ffb703"/><path d="M9 6c3 0 5 3 5 6H8z" fill="C"/>' +
            '<circle cx="19" cy="15" r="2.2" fill="#14213d"/><circle cx="13" cy="15" r="2.2" fill="#14213d"/>',
        '<rect x="5" y="7" width="22" height="20" rx="3" fill="C"/><path d="M5 12h22" stroke="#14213d" stroke-opacity=".25" stroke-width="2"/>' + EYES,
        '<path d="M4 16c0-7 5-12 12-12s12 5 12 12z" fill="C"/><rect x="10" y="16" width="12" height="11" rx="4" fill="#f5efe6"/>' +
            '<circle cx="11" cy="10" r="2" fill="#fff" fill-opacity=".7"/><circle cx="21" cy="9" r="2.4" fill="#fff" fill-opacity=".7"/><circle cx="13.5" cy="21" r="1.6" fill="#14213d"/><circle cx="18.5" cy="21" r="1.6" fill="#14213d"/>',
    ];

    function svg(avatar, color, size) {
        var a = Math.abs(parseInt(avatar, 10) || 0) % BODIES.length;
        var c = COLORS[Math.abs(parseInt(color, 10) || 0) % COLORS.length];
        var s = size || 24;
        return '<svg class="oca-avatar" width="' + s + '" height="' + s + '" viewBox="0 0 32 32" aria-hidden="true">' +
            BODIES[a].replace(/fill="C"/g, 'fill="' + c + '"') + '</svg>';
    }

    // ── sound ────────────────────────────────────────────────
    var ctx = null;
    var muted = false;
    try { muted = window.localStorage.getItem('oca.mute') === '1'; } catch (e) { /* storage off */ }

    function audio() {
        if (!ctx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return null;
            ctx = new AC();
        }
        if (ctx.state === 'suspended') ctx.resume();
        return ctx;
    }

    function tone(freq, ms, type, gain, delay) {
        var a = audio();
        if (!a || muted) return;
        var t0 = a.currentTime + (delay || 0) / 1000;
        var o = a.createOscillator();
        var g = a.createGain();
        o.type = type || 'square';
        o.frequency.setValueAtTime(freq, t0);
        g.gain.setValueAtTime(gain || 0.05, t0);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + ms / 1000);
        o.connect(g).connect(a.destination);
        o.start(t0);
        o.stop(t0 + ms / 1000 + 0.02);
    }

    var SFX = {
        move: function () { tone(520, 40, 'triangle', 0.025); },
        occupy: function () { tone(660, 70, 'square', 0.04); },
        right: function () { tone(784, 80, 'square', 0.05); tone(1046, 120, 'square', 0.05, 80); },
        wrong: function () { tone(220, 160, 'sawtooth', 0.05); },
        boss: function () { tone(330, 120, 'sawtooth', 0.05); tone(262, 200, 'sawtooth', 0.05, 130); },
        win: function () { [523, 659, 784, 1046].forEach(function (f, k) { tone(f, 120, 'square', 0.05, k * 90); }); },
        item: function () { tone(988, 70, 'triangle', 0.05); tone(1318, 90, 'triangle', 0.05, 70); },
        hit: function () { tone(150, 260, 'sawtooth', 0.06); },
        tick: function () { tone(880, 50, 'square', 0.03); },
        start: function () { tone(440, 160, 'square', 0.05); tone(880, 260, 'square', 0.05, 180); },
    };

    window.ArenaLook = {
        COLORS: COLORS,
        NAMES: NAMES,
        count: BODIES.length,
        svg: svg,
        sfx: function (name) { if (SFX[name]) SFX[name](); },
        unlock: audio,
        muted: function () { return muted; },
        setMuted: function (on) {
            muted = !!on;
            try { window.localStorage.setItem('oca.mute', muted ? '1' : '0'); } catch (e) { /* storage off */ }
        },
    };
})(window);
