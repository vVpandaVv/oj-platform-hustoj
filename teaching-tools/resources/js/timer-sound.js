/**
 * Timer end-of-countdown sounds — synthesized with the Web Audio API so no
 * external audio file needs to ship with the plugin. Shared by
 * host-timer.js (teacher device) and join.js (participant devices in live
 * mode) so both play the exact same tone for a given sound key.
 */
window.HttTimerSound = (function () {
    'use strict';

    var audioContext = null;

    function getContext() {
        var AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return null;
        }
        if (!audioContext) {
            audioContext = new AudioContextClass();
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
        return audioContext;
    }

    function tone(context, startTime, duration, frequency, waveform, peakGain) {
        var oscillator = context.createOscillator();
        var gain = context.createGain();
        oscillator.type = waveform;
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0, startTime);
        gain.gain.linearRampToValueAtTime(peakGain, startTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start(startTime);
        oscillator.stop(startTime + duration + 0.05);
    }

    var PATTERNS = {
        chime: function (context, now) {
            tone(context, now, 0.5, 880, 'sine', 0.3);
            tone(context, now + 0.15, 0.6, 1320, 'sine', 0.25);
        },
        bell: function (context, now) {
            tone(context, now, 1.3, 660, 'triangle', 0.35);
        },
        alarm: function (context, now) {
            [0, 0.3, 0.6].forEach(function (offset) {
                tone(context, now + offset, 0.22, 1000, 'square', 0.25);
            });
        },
        ding: function (context, now) {
            tone(context, now, 0.3, 1046.5, 'sine', 0.3);
            tone(context, now + 0.3, 0.35, 784, 'sine', 0.3);
        },
    };

    function play(soundKey) {
        if (!soundKey || soundKey === 'none' || !PATTERNS[soundKey]) {
            return;
        }
        var context = getContext();
        if (!context) {
            return;
        }
        PATTERNS[soundKey](context, context.currentTime);
    }

    return { play: play };
})();
