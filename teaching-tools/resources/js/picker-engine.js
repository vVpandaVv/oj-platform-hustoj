/**
 * Random draw engine — Fisher-Yates sample of N entries from a pool, same
 * shuffle used by the seating engine. Kept as its own tiny module (rather
 * than folded into host-picker.js) so the actual randomness is a one-line,
 * independently testable function.
 */
window.HttPickerEngine = (function () {
    'use strict';

    function draw(pool, count) {
        var shuffled = pool.slice();
        for (var i = shuffled.length - 1; i > 0; i -= 1) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = shuffled[i];
            shuffled[i] = shuffled[j];
            shuffled[j] = tmp;
        }
        return shuffled.slice(0, Math.max(0, Math.min(count, shuffled.length)));
    }

    return { draw: draw };
})();
