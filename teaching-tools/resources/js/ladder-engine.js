/**
 * Ladder (사다리타기) generator. Each row places rungs between non-adjacent
 * column gaps, so every row is a disjoint set of column swaps — a valid
 * permutation on its own — and the composition of all rows top-to-bottom
 * is therefore guaranteed to be a permutation too (a bijection between
 * starting and ending columns), with no post-hoc validation needed.
 */
window.HttLadderEngine = (function () {
    'use strict';

    function generateRungs(columnCount, rowCount) {
        var rows = [];
        for (var r = 0; r < rowCount; r += 1) {
            var rungs = new Array(Math.max(0, columnCount - 1)).fill(false);
            var gap = 0;
            while (gap < rungs.length) {
                if (Math.random() < 0.5) {
                    rungs[gap] = true;
                    gap += 2;
                } else {
                    gap += 1;
                }
            }
            rows.push(rungs);
        }
        return rows;
    }

    function traceColumn(rungs, startColumn, columnCount) {
        var col = startColumn;
        var path = [col];
        rungs.forEach(function (rowRungs) {
            if (col > 0 && rowRungs[col - 1]) {
                col -= 1;
            } else if (col < columnCount - 1 && rowRungs[col]) {
                col += 1;
            }
            path.push(col);
        });
        return { endColumn: col, path: path };
    }

    function build(columnCount, rowCount) {
        var count = Math.max(2, Math.min(30, columnCount));
        var rows = Math.max(4, rowCount || count * 2);
        var rungs = generateRungs(count, rows);
        var mapping = [];
        for (var c = 0; c < count; c += 1) {
            mapping.push(traceColumn(rungs, c, count).endColumn);
        }
        return { columnCount: count, rowCount: rows, rungs: rungs, mapping: mapping };
    }

    return { build: build, traceColumn: traceColumn };
})();
