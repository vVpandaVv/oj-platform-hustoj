/**
 * Shared whiteboard rendering — used by both host-whiteboard.js (drawing)
 * and join.js (read-only mirror) so the two never draw pixel/pen elements
 * differently. Elements live in a fixed 1000×600 logical coordinate space
 * (LOGICAL_WIDTH/LOGICAL_HEIGHT) and get scaled to whatever pixel size the
 * actual <canvas> element has, so host projector and student phone always
 * agree on where everything is regardless of their real screen size.
 */
window.HttWhiteboardRender = (function () {
    'use strict';

    var LOGICAL_WIDTH = 1000;
    var LOGICAL_HEIGHT = 600;

    function toPixel(x, y, canvas) {
        return [x / LOGICAL_WIDTH * canvas.width, y / LOGICAL_HEIGHT * canvas.height];
    }

    function scaleLength(length, canvas) {
        return length * (canvas.width / LOGICAL_WIDTH);
    }

    function drawStroke(ctx, canvas, el, start) {
        if (!el.points || el.points.length < 2) {
            return;
        }
        ctx.save();
        ctx.globalCompositeOperation = el.type === 'erase' ? 'destination-out' : 'source-over';
        ctx.strokeStyle = el.color;
        ctx.lineWidth = scaleLength(el.width, canvas);
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        var first = Math.max(0, (start || 0) - 1);
        for (var index = first; index < el.points.length; index += 1) {
            var point = el.points[index];
            var p = toPixel(point[0], point[1], canvas);
            if (index === first) {
                ctx.moveTo(p[0], p[1]);
            } else {
                ctx.lineTo(p[0], p[1]);
            }
        }
        ctx.stroke();
        ctx.restore();
    }

    function drawArrowHead(ctx, from, to, lineWidth) {
        var angle = Math.atan2(to[1] - from[1], to[0] - from[0]);
        var headLength = Math.max(10, lineWidth * 3);
        ctx.beginPath();
        ctx.moveTo(to[0], to[1]);
        ctx.lineTo(to[0] - headLength * Math.cos(angle - Math.PI / 6), to[1] - headLength * Math.sin(angle - Math.PI / 6));
        ctx.moveTo(to[0], to[1]);
        ctx.lineTo(to[0] - headLength * Math.cos(angle + Math.PI / 6), to[1] - headLength * Math.sin(angle + Math.PI / 6));
        ctx.stroke();
    }

    function drawShape(ctx, canvas, el) {
        var p1 = toPixel(el.x1, el.y1, canvas);
        var p2 = toPixel(el.x2, el.y2, canvas);
        var lineWidth = scaleLength(el.width, canvas);

        ctx.save();
        ctx.strokeStyle = el.color;
        ctx.lineWidth = lineWidth;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        if (el.type === 'line' || el.type === 'arrow') {
            ctx.beginPath();
            ctx.moveTo(p1[0], p1[1]);
            ctx.lineTo(p2[0], p2[1]);
            ctx.stroke();
            if (el.type === 'arrow') {
                drawArrowHead(ctx, p1, p2, lineWidth);
            }
        } else if (el.type === 'rect') {
            var x = Math.min(p1[0], p2[0]);
            var y = Math.min(p1[1], p2[1]);
            ctx.strokeRect(x, y, Math.abs(p2[0] - p1[0]), Math.abs(p2[1] - p1[1]));
        } else if (el.type === 'circle') {
            var cx = (p1[0] + p2[0]) / 2;
            var cy = (p1[1] + p2[1]) / 2;
            var rx = Math.max(1, Math.abs(p2[0] - p1[0]) / 2);
            var ry = Math.max(1, Math.abs(p2[1] - p1[1]) / 2);
            ctx.beginPath();
            ctx.ellipse(cx, cy, rx, ry, 0, 0, Math.PI * 2);
            ctx.stroke();
        }
        ctx.restore();
    }

    function drawText(ctx, canvas, el) {
        var p = toPixel(el.x, el.y, canvas);
        ctx.save();
        ctx.fillStyle = el.color;
        ctx.font = scaleLength(el.fontSize || 28, canvas) + 'px sans-serif';
        ctx.textBaseline = 'top';
        ctx.fillText(el.text || '', p[0], p[1]);
        ctx.restore();
    }

    function drawImage(ctx, canvas, el, imageCache, onLoaded) {
        var img = imageCache[el.url];
        if (!img) {
            img = new Image();
            imageCache[el.url] = img;
            img.addEventListener('load', function () {
                if (onLoaded) {
                    onLoaded();
                }
            });
            img.src = el.url;
        }
        if (!img.complete || !img.naturalWidth) {
            return;
        }
        var p1 = toPixel(el.x, el.y, canvas);
        var p2 = toPixel(el.x + el.width, el.y + el.height, canvas);
        ctx.drawImage(img, p1[0], p1[1], p2[0] - p1[0], p2[1] - p1[1]);
    }

    /**
     * @param {Object<string, HTMLImageElement>} imageCache persists across
     *   calls (keyed by image URL) so an image already loaded doesn't
     *   re-fetch or re-decode on every redraw.
     * @param {function} onImageLoaded called once an image finishes loading
     *   so the caller can trigger one more redraw pass to actually show it.
     */
    function redrawElements(ctx, canvas, elements, imageCache, onImageLoaded) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        (elements || []).forEach(function (el) {
            drawElement(ctx, canvas, el, imageCache, onImageLoaded);
        });
    }

    function drawElement(ctx, canvas, el, imageCache, onImageLoaded) {
        if (el.type === 'draw' || el.type === 'erase') { drawStroke(ctx, canvas, el); }
        else if (el.type === 'line' || el.type === 'rect' || el.type === 'circle' || el.type === 'arrow') { drawShape(ctx, canvas, el); }
        else if (el.type === 'text') { drawText(ctx, canvas, el); }
        else if (el.type === 'image') { drawImage(ctx, canvas, el, imageCache, onImageLoaded); }
    }

    // Keep committed ink as a bitmap; live pen/eraser samples only paint their new segments.
    function createLiveLayer(canvas, imageCache) {
        var ctx = canvas.getContext('2d');
        var base = document.createElement('canvas');
        var baseCtx = base.getContext('2d');
        var elements = [];
        var active = null;
        var activeKey = null;
        var paintedKey = null;
        var paintedPoints = 0;

        function restore() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(base, 0, 0);
            paintedKey = null;
            paintedPoints = 0;
        }

        function preview(element, key) {
            active = element;
            activeKey = key;
            if (!element) {
                if (paintedKey !== null) { restore(); }
                return;
            }
            var stroke = element.type === 'draw' || element.type === 'erase';
            var count = stroke ? element.points.length : 0;
            if (paintedKey !== key || count < paintedPoints || !stroke) { restore(); }
            if (stroke && count > paintedPoints) { drawStroke(ctx, canvas, element, paintedPoints); }
            else if (!stroke) { drawShape(ctx, canvas, element); }
            paintedKey = key;
            paintedPoints = count;
        }

        function rebuild() {
            if (base.width !== canvas.width || base.height !== canvas.height) {
                base.width = canvas.width;
                base.height = canvas.height;
            }
            redrawElements(baseCtx, base, elements, imageCache, rebuild);
            restore();
            if (active) { preview(active, activeKey); }
        }

        return {
            setBase: function (next) { elements = next || []; active = null; activeKey = null; rebuild(); },
            append: function (element) {
                active = null;
                activeKey = null;
                drawElement(baseCtx, base, element, imageCache, rebuild);
                restore();
            },
            preview: preview,
        };
    }

    function prefersWindowExpansion() {
        // iPad desktop mode identifies as a Mac. Native Safari fullscreen can dismiss on a pen pan.
        return /iPad|iPhone|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    return {
        LOGICAL_WIDTH: LOGICAL_WIDTH,
        LOGICAL_HEIGHT: LOGICAL_HEIGHT,
        toPixel: toPixel,
        drawShape: drawShape,
        redrawElements: redrawElements,
        createLiveLayer: createLiveLayer,
        prefersWindowExpansion: prefersWindowExpansion,
    };
})();
