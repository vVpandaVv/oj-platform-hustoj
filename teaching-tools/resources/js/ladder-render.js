/**
 * Draws an actual ladder (사다리) shape in SVG and animates a single path
 * descending through it. Used two ways:
 *  - join.js: after a student claims a column, animate ONLY that student's
 *    own path (server sends just their path, never the raw rungs/mapping —
 *    see Ladder_Controller — so this never has enough data to spoil anyone
 *    else's result even if it wanted to).
 *  - host-ladder.js: once the teacher clicks "전체 결과 공개", draw every
 *    rung at once for the full reveal (the host already has the complete
 *    rungs/mapping locally from when they generated the ladder).
 */
window.HttLadderRender = (function () {
    'use strict';

    var COL_SPACING = 70;
    var ROW_HEIGHT = 34;
    var MARGIN_TOP = 34;
    var MARGIN_BOTTOM = 42;
    var MARGIN_SIDE = 24;
    var FINAL_DROP = 18;
    var SVG_NS = 'http://www.w3.org/2000/svg';

    function el(name, attrs) {
        var node = document.createElementNS(SVG_NS, name);
        Object.keys(attrs || {}).forEach(function (key) {
            node.setAttribute(key, attrs[key]);
        });
        return node;
    }

    function createSvg(columnCount, rowCount) {
        var width = MARGIN_SIDE * 2 + Math.max(0, columnCount - 1) * COL_SPACING;
        var height = MARGIN_TOP + rowCount * ROW_HEIGHT + MARGIN_BOTTOM;

        var svg = el('svg', {
            viewBox: '0 0 ' + width + ' ' + height,
            class: 'htt-ladder-svg',
            preserveAspectRatio: 'xMidYMin meet',
        });

        var colX = [];
        for (var c = 0; c < columnCount; c += 1) {
            colX.push(MARGIN_SIDE + c * COL_SPACING);
        }

        var rails = el('g', { class: 'htt-ladder-rails' });
        colX.forEach(function (x) {
            rails.appendChild(el('line', { x1: x, y1: MARGIN_TOP, x2: x, y2: height - MARGIN_BOTTOM }));
        });
        svg.appendChild(rails);

        return { svg: svg, colX: colX, width: width, height: height, top: MARGIN_TOP, rowHeight: ROW_HEIGHT };
    }

    function labelColumns(layout, labels, activeColumn) {
        labels.forEach(function (label, index) {
            var text = el('text', {
                x: layout.colX[index],
                y: layout.top - 12,
                'text-anchor': 'middle',
                class: 'htt-ladder-col-label' + (index === activeColumn ? ' is-active' : ''),
            });
            text.textContent = String(label);
            layout.svg.appendChild(text);
        });
    }

    function drawFullLadder(layout, rungs) {
        var group = el('g', { class: 'htt-ladder-rungs' });
        rungs.forEach(function (rowRungs, rowIndex) {
            var y = layout.top + (rowIndex + 0.5) * layout.rowHeight;
            (rowRungs || []).forEach(function (hasRung, gapIndex) {
                if (!hasRung) {
                    return;
                }
                group.appendChild(el('line', {
                    x1: layout.colX[gapIndex],
                    y1: y,
                    x2: layout.colX[gapIndex + 1],
                    y2: y,
                }));
            });
        });
        layout.svg.appendChild(group);
    }

    function bottomLabels(layout, labels) {
        labels.forEach(function (label, index) {
            var text = el('text', {
                x: layout.colX[index],
                y: layout.height - 6,
                'text-anchor': 'middle',
                class: 'htt-ladder-result-label',
            });
            text.textContent = String(label);
            layout.svg.appendChild(text);
        });
    }

    /**
     * Animates a marker down through `path` (a list of column indices, one
     * per row boundary, starting with the chosen column) — one row at a
     * time: slide straight down, then, if the row switches columns, slide
     * sideways along that row's rung before continuing. Calls onDone once
     * the marker reaches the bottom.
     */
    function animatePath(layout, path, options, onDone) {
        var stepDelay = (options && options.stepDelay) || 220;

        var marker = el('circle', { r: 9, class: 'htt-ladder-marker', cx: layout.colX[path[0]], cy: layout.top });
        var pathGroup = el('g', { class: 'htt-ladder-path' });
        layout.svg.appendChild(pathGroup);
        layout.svg.appendChild(marker);

        var row = 0;

        function addSegment(x1, y1, x2, y2) {
            pathGroup.appendChild(el('line', { x1: x1, y1: y1, x2: x2, y2: y2 }));
        }

        function finish() {
            var finalCol = path[path.length - 1];
            var yBottom = layout.top + (path.length - 1) * layout.rowHeight;
            var yEnd = yBottom + FINAL_DROP;

            addSegment(layout.colX[finalCol], yBottom, layout.colX[finalCol], yEnd);
            marker.setAttribute('cy', yEnd);

            window.setTimeout(function () {
                marker.classList.add('is-done');
                if (onDone) {
                    onDone();
                }
            }, stepDelay);
        }

        function step() {
            if (row >= path.length - 1) {
                finish();
                return;
            }

            var fromCol = path[row];
            var toCol = path[row + 1];
            var yTop = layout.top + row * layout.rowHeight;
            var yBottom = layout.top + (row + 1) * layout.rowHeight;

            addSegment(layout.colX[fromCol], yTop, layout.colX[fromCol], yBottom);
            marker.setAttribute('cy', yBottom);

            window.setTimeout(function () {
                if (toCol !== fromCol) {
                    addSegment(layout.colX[fromCol], yBottom, layout.colX[toCol], yBottom);
                    marker.setAttribute('cx', layout.colX[toCol]);
                }
                row += 1;
                window.setTimeout(step, toCol !== fromCol ? stepDelay : Math.round(stepDelay * 0.55));
            }, stepDelay);
        }

        step();
    }

    return {
        createSvg: createSvg,
        labelColumns: labelColumns,
        drawFullLadder: drawFullLadder,
        bottomLabels: bottomLabels,
        animatePath: animatePath,
    };
})();
