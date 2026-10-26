(function () {
    'use strict';

    function create(options) {
        var root = options.root;
        var viewport = root.querySelector('[data-field="whiteboard-viewport"]');
        var stage = root.querySelector('[data-field="whiteboard-stage"]');
        var surface = root.querySelector('.htt-join-whiteboard-surface');
        var canvas = root.querySelector('[data-field="whiteboard-canvas"]');
        var fit = root.querySelector('[data-field="whiteboard-fit"]');
        var slider = root.querySelector('[data-field="whiteboard-zoom"]');
        var value = root.querySelector('[data-field="whiteboard-zoom-value"]');
        var zoomOut = root.querySelector('[data-action="whiteboard-zoom-out"]');
        var zoomIn = root.querySelector('[data-action="whiteboard-zoom-in"]');
        var zoom = 100;
        var resolution = 1;
        var backgroundKey = '';
        var bounds = { x: 0, y: 0, width: 1000, height: 600 };
        var layout = null;
        var frame = null;
        var recenter = true;
        var drag = null;

        function refresh(center) {
            if (center === true) { recenter = true; }
            if (frame !== null) { return; }
            frame = window.requestAnimationFrame(function () {
                frame = null;
                if (root.hidden || !viewport.clientWidth || !viewport.clientHeight) { return; }
                var width = viewport.clientWidth;
                var height = viewport.clientHeight;
                var content = fit.value === 'board' ? { x: 0, y: 0, width: 1000, height: 600 } : bounds;
                var base = fit.value === 'width' ? (width - 2) / content.width : Math.min((width - 2) / content.width, (height - 2) / content.height);
                var scale = Math.max(0.001, base * zoom / 100);
                var centerX = recenter || !layout ? content.x + content.width / 2 : (viewport.scrollLeft + layout.width / 2 - layout.left) / layout.scale;
                var initialCenterY = fit.value === 'width' ? content.y + height / (2 * scale) : content.y + content.height / 2;
                var centerY = recenter || !layout ? initialCenterY : (viewport.scrollTop + layout.height / 2 - layout.top) / layout.scale;
                var stageWidth = Math.max(width, Math.ceil(1000 * scale));
                var stageHeight = Math.max(height, Math.ceil(600 * scale));
                var left = (stageWidth - 1000 * scale) / 2;
                var top = (stageHeight - 600 * scale) / 2;
                stage.style.width = stageWidth + 'px';
                stage.style.height = stageHeight + 'px';
                surface.style.width = (1000 * scale) + 'px';
                surface.style.height = (600 * scale) + 'px';
                surface.style.left = left + 'px';
                surface.style.top = top + 'px';
                viewport.scrollLeft = left + centerX * scale - width / 2;
                viewport.scrollTop = top + centerY * scale - height / 2;
                layout = { width: width, height: height, left: left, top: top, scale: scale };
                recenter = false;
                // Preserve teacher coordinates; enlarge only the display and bounded raster backing.
                var nextResolution = Math.min(3, Math.max(1, Math.ceil(scale * (window.devicePixelRatio || 1))));
                if (resolution !== nextResolution) {
                    resolution = nextResolution;
                    canvas.width = 1000 * resolution;
                    canvas.height = 600 * resolution;
                    options.onResize();
                }
            });
        }

        function setZoom(next) {
            zoom = Math.max(25, Math.min(400, Number(next) || 100));
            slider.value = String(zoom);
            value.textContent = zoom + '%';
            zoomOut.disabled = zoom === 25;
            zoomIn.disabled = zoom === 400;
            refresh();
        }

        fit.addEventListener('change', function () { setZoom(100); refresh(true); });
        slider.addEventListener('input', function () { setZoom(slider.value); });
        zoomOut.addEventListener('click', function () { setZoom(zoom - 25); });
        zoomIn.addEventListener('click', function () { setZoom(zoom + 25); });
        root.querySelector('[data-action="whiteboard-zoom-reset"]').addEventListener('click', function () { setZoom(100); refresh(true); });
        viewport.addEventListener('wheel', function (event) {
            if (!event.ctrlKey && !event.metaKey) { return; }
            event.preventDefault();
            setZoom(zoom + (event.deltaY < 0 ? 25 : -25));
        }, { passive: false });
        viewport.addEventListener('keydown', function (event) {
            if (event.key === '+' || event.key === '=') { event.preventDefault(); setZoom(zoom + 25); }
            if (event.key === '-') { event.preventDefault(); setZoom(zoom - 25); }
        });
        viewport.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'touch' || event.button !== 0 || drag) { return; }
            event.preventDefault();
            viewport.focus({ preventScroll: true });
            viewport.setPointerCapture(event.pointerId);
            drag = { id: event.pointerId, x: event.clientX, y: event.clientY, left: viewport.scrollLeft, top: viewport.scrollTop };
            viewport.classList.add('is-panning');
        });
        viewport.addEventListener('pointermove', function (event) {
            if (!drag || event.pointerId !== drag.id) { return; }
            if (!event.buttons) { finishPan(event); return; }
            event.preventDefault();
            viewport.scrollLeft = drag.left - (event.clientX - drag.x);
            viewport.scrollTop = drag.top - (event.clientY - drag.y);
        });
        function finishPan(event) {
            if (!drag || event.pointerId !== drag.id) { return; }
            drag = null;
            viewport.classList.remove('is-panning');
            if (viewport.hasPointerCapture(event.pointerId)) { viewport.releasePointerCapture(event.pointerId); }
        }
        ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (name) { viewport.addEventListener(name, finishPan); });
        window.addEventListener('blur', function () { if (drag) { finishPan({ pointerId: drag.id }); } });
        viewport.addEventListener('dragstart', function (event) { event.preventDefault(); });
        if (window.ResizeObserver) { new ResizeObserver(function () { refresh(); }).observe(viewport); }

        return {
            refresh: refresh,
            resolution: function () { return resolution; },
            reset: function () { backgroundKey = ''; layout = null; fit.value = 'page'; setZoom(100); refresh(true); },
            setBackground: function (background, page) {
                var key = page + ':' + (background ? background.url + '#' + background.page : '');
                if (key === backgroundKey) { return; }
                backgroundKey = key;
                bounds = { x: 0, y: 0, width: 1000, height: 600 };
                refresh(true);
                if (window.HttPdfPages && background) {
                    window.HttPdfPages.measure(background).then(function (measured) {
                        if (key !== backgroundKey) { return; }
                        bounds = measured;
                        refresh(true);
                    }).catch(function () {});
                }
            },
        };
    }

    window.HttWhiteboardViewer = { create: create };
}());
