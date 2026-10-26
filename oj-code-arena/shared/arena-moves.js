/*
 * Code Arena movement rules — shared by the game server (Node) and the
 * browser client (prediction). The server is always authoritative.
 *
 * A grid is { cols, rows, isSolved(i), isBlocked(i) }.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ArenaMoves = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var DIRS = { L: [0, -1], R: [0, 1], U: [-1, 0], D: [1, 0] };
    var INVERT = { L: 'R', R: 'L', U: 'D', D: 'U' };

    function inside(grid, r, c) {
        return r >= 0 && c >= 0 && r < grid.rows && c < grid.cols;
    }

    function open(grid, r, c) {
        return inside(grid, r, c) && !grid.isBlocked(r * grid.cols + c);
    }

    // One cell, or a "jump" (Ctrl+arrow): along a run of unsolved cells it
    // slides to the last unsolved cell of the run; otherwise it skips solved
    // cells and lands on the next unsolved one (or the last open cell).
    function step(grid, r, c, dir, jump) {
        var d = DIRS[dir];
        if (!d) return { r: r, c: c };
        if (!jump) {
            return open(grid, r + d[0], c + d[1]) ? { r: r + d[0], c: c + d[1] } : { r: r, c: c };
        }
        var nr = r + d[0], nc = c + d[1];
        if (!open(grid, nr, nc)) return { r: r, c: c };
        var here = grid.isSolved(r * grid.cols + c);
        var next = grid.isSolved(nr * grid.cols + nc);
        if (!here && !next) {
            while (open(grid, nr + d[0], nc + d[1]) && !grid.isSolved((nr + d[0]) * grid.cols + nc + d[1])) {
                nr += d[0]; nc += d[1];
            }
            return { r: nr, c: nc };
        }
        while (grid.isSolved(nr * grid.cols + nc) && open(grid, nr + d[0], nc + d[1])) {
            nr += d[0]; nc += d[1];
        }
        return { r: nr, c: nc };
    }

    // Home/End: row edges; first/last: board corners. Stops before a blocked cell.
    function edge(grid, r, c, to) {
        if (to === 'home' || to === 'end') {
            var dc = to === 'home' ? -1 : 1;
            var cc = c;
            while (open(grid, r, cc + dc)) cc += dc;
            return { r: r, c: cc };
        }
        if (to === 'first' || to === 'last') {
            var total = grid.rows * grid.cols;
            var i = to === 'first' ? 0 : total - 1;
            var di = to === 'first' ? 1 : -1;
            while (i >= 0 && i < total && grid.isBlocked(i)) i += di;
            if (i < 0 || i >= total) return { r: r, c: c };
            return { r: Math.floor(i / grid.cols), c: i % grid.cols };
        }
        return { r: r, c: c };
    }

    function move(grid, r, c, cmd, flipped) {
        if (cmd && cmd.to) return edge(grid, r, c, cmd.to);
        var dir = cmd && cmd.dir;
        if (flipped && INVERT[dir]) dir = INVERT[dir];
        return step(grid, r, c, dir, !!(cmd && cmd.jump));
    }

    return { DIRS: DIRS, INVERT: INVERT, step: step, edge: edge, move: move };
});
