(function () {
    'use strict';
    var baseUrl = new URL('../vendor/pdfjs/', document.currentScript.src).href;
    var assetVersion = new URL(document.currentScript.src).searchParams.get('ver') || '1';
    var libraryAttempts = 0;
    var libraryPromise;
    var documents = new Map();
    var targets = new WeakMap();
    var bitmaps = new Map();

    function timeout(promise, cancel) {
        var timer;
        return Promise.race([promise, new Promise(function (resolve, reject) {
            timer = window.setTimeout(function () {
                reject(new Error('PDF 응답 시간이 초과되었습니다. 네트워크를 확인하고 다시 선택해 주세요.'));
                if (cancel) { cancel(); }
            }, 30000);
        })]).finally(function () { window.clearTimeout(timer); });
    }

    function library() {
        if (!libraryPromise) {
            var query = '?ver=' + encodeURIComponent(assetVersion) + '&retry=' + libraryAttempts++;
            libraryPromise = timeout(import(baseUrl + 'build/pdf.js' + query)).then(function (pdfjs) {
                pdfjs.GlobalWorkerOptions.workerSrc = baseUrl + 'build/pdf.worker.js' + query;
                return pdfjs;
            }).catch(function () {
                libraryPromise = null;
                throw new Error('PDF 표시 모듈을 불러오지 못했습니다. 플러그인 파일과 서버의 JavaScript 제공 설정을 확인해 주세요.');
            });
        }
        return libraryPromise;
    }

    function load(url) {
        var parsed;
        try { parsed = new URL(url, window.location.href); }
        catch (error) { return Promise.reject(new Error('PDF 자료 주소가 올바르지 않습니다. 자료를 다시 선택해 주세요.')); }
        if (parsed.origin !== window.location.origin || !/^https?:$/.test(parsed.protocol)) {
            return Promise.reject(new Error('이 사이트의 PDF 자료만 불러올 수 있습니다.'));
        }
        parsed.hash = '';
        var key = parsed.href;
        if (!documents.has(key)) {
            documents.set(key, library().then(function (pdfjs) {
                var task = pdfjs.getDocument({
                    url: key,
                    withCredentials: true,
                    isEvalSupported: false,
                    cMapUrl: baseUrl + 'cmaps/',
                    cMapPacked: true,
                    standardFontDataUrl: baseUrl + 'standard_fonts/',
                    wasmUrl: baseUrl + 'wasm/',
                    iccUrl: baseUrl + 'iccs/',
                });
                return timeout(task.promise, function () { task.destroy().catch(function () {}); });
            }).catch(function (error) { documents.delete(key); throw error; }));
        }
        return documents.get(key);
    }

    function errorMessage(error) {
        if (error && error.name === 'PasswordException') { return '암호가 설정된 PDF입니다. 암호를 해제한 자료를 등록해 주세요.'; }
        if (error && error.name === 'InvalidPDFException') { return '올바른 PDF 파일이 아니거나 파일이 손상되었습니다.'; }
        if (error && /fake worker|worker.*fetch/i.test(error.message || '')) { return 'PDF 처리 모듈을 불러오지 못했습니다. 플러그인 파일과 서버의 JavaScript 제공 설정을 확인해 주세요.'; }
        if (error && [401, 403, 404].indexOf(error.status) !== -1) {
            return 'PDF 자료에 접근할 수 없습니다. 로그인 상태와 학습자료 열람 권한·공개 기간·그룹을 확인해 주세요.';
        }
        if (error && (error.status === 0 || error.name === 'TypeError')) { return 'PDF 요청에 실패했습니다. 네트워크와 자료 주소를 확인하고 다시 선택해 주세요.'; }
        return (error && error.message) || 'PDF를 불러오지 못했습니다. 자료를 다시 선택해 주세요.';
    }

    function resolutionOf(options) { return Math.max(1, Math.min(3, Math.ceil(Number(options && options.resolution) || 1))); }

    function pageBitmap(background, resolution) {
        resolution = resolution || 1;
        var signature = background.url + '#' + background.page + '@' + resolution;
        if (bitmaps.has(signature)) {
            var cached = bitmaps.get(signature);
            bitmaps.delete(signature);
            bitmaps.set(signature, cached);
            return cached;
        }
        var source = background.type === 'image' ? sharedBitmap(background.url, resolution) : load(background.url).then(function (pdf) {
            return pdf.getPage(Math.max(1, Math.min(pdf.numPages, Number(background.page) || 1)));
        }).then(function (page) {
            var viewport = page.getViewport({ scale: 1 });
            viewport = page.getViewport({ scale: Math.min(1000 / viewport.width, 600 / viewport.height) * resolution });
            var offscreen = document.createElement('canvas');
            offscreen.width = Math.ceil(viewport.width);
            offscreen.height = Math.ceil(viewport.height);
            return page.render({ canvasContext: offscreen.getContext('2d'), viewport: viewport }).promise.then(function () { return offscreen; });
        });
        var result = source.catch(function (error) {
            if (bitmaps.get(signature) === result) { bitmaps.delete(signature); }
            throw error;
        });
        bitmaps.set(signature, result);
        // Keep nearby pages ready without rasterizing a whole document into RAM.
        while (bitmaps.size > 6) { bitmaps.delete(bitmaps.keys().next().value); }
        return result;
    }

    function sharedBitmap(url, resolution) {
        var parsed = new URL(url, window.location.href);
        if (parsed.origin !== window.location.origin || !/^https?:$/.test(parsed.protocol)) { return Promise.reject(new Error('올바른 수업 배경 주소가 아닙니다.')); }
        return timeout(fetch(parsed.href, { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
            if (!response.ok) { var error = new Error('공유 배경을 불러올 수 없습니다.'); error.status = response.status; throw error; }
            return response.blob();
        }).then(function (blob) {
            return new Promise(function (resolve, reject) {
                var url = URL.createObjectURL(blob);
                var img = new Image();
                img.onload = function () {
                    var canvas = document.createElement('canvas');
                    canvas.width = 1000 * resolution; canvas.height = 600 * resolution;
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    URL.revokeObjectURL(url); resolve(canvas);
                };
                img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('공유 배경 이미지가 올바르지 않습니다.')); };
                img.src = url;
            });
        }));
    }

    function preload(background, options) {
        if (!background || !background.url || background.type === 'image') { return; }
        load(background.url).then(function (pdf) {
            [Number(background.page) - 1, Number(background.page) + 1].forEach(function (number) {
                if (number >= 1 && number <= pdf.numPages) {
                    pageBitmap(Object.assign({}, background, { page: number }), resolutionOf(options)).catch(function () {});
                }
            });
        }).catch(function () {});
    }

    function measure(background) {
        if (!background || !background.url) { return Promise.resolve({ x: 0, y: 0, width: 1000, height: 600 }); }
        if (background.type === 'image') {
            var bounds = background.bounds;
            var valid = bounds && ['x', 'y', 'width', 'height'].every(function (key) { return Number.isFinite(bounds[key]); })
                && bounds.x >= 0 && bounds.y >= 0 && bounds.width > 0 && bounds.height > 0
                && bounds.x + bounds.width <= 1001 && bounds.y + bounds.height <= 601;
            return Promise.resolve(valid ? bounds : { x: 0, y: 0, width: 1000, height: 600 });
        }
        return load(background.url).then(function (pdf) {
            return pdf.getPage(Math.max(1, Math.min(pdf.numPages, Number(background.page) || 1)));
        }).then(function (page) {
            var viewport = page.getViewport({ scale: 1 });
            var scale = Math.min(1000 / viewport.width, 600 / viewport.height);
            var width = viewport.width * scale;
            var height = viewport.height * scale;
            return { x: (1000 - width) / 2, y: (600 - height) / 2, width: width, height: height };
        });
    }

    function render(background, canvas, options) {
        var resolution = resolutionOf(options);
        var signature = (background ? background.url + '#' + background.page : '') + '@' + resolution;
        var previous = targets.get(canvas);
        if (previous && previous.signature === signature) { return previous.promise; }
        var state = { signature: signature, promise: null };
        targets.set(canvas, state);
        canvas.width = 1000 * resolution;
        canvas.height = 600 * resolution;
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        if (!background || !background.url) {
            state.promise = Promise.resolve();
            return state.promise;
        }
        state.promise = pageBitmap(background, resolution).then(function (offscreen) {
            if (targets.get(canvas) !== state) { return; }
            var context = canvas.getContext('2d');
            context.fillStyle = 'white';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(offscreen, (canvas.width - offscreen.width) / 2, (canvas.height - offscreen.height) / 2);
        }).catch(function (error) {
            if (targets.get(canvas) === state) { targets.delete(canvas); }
            throw error;
        });
        return state.promise;
    }

    window.HttPdfPages = { load: load, render: render, preload: preload, measure: measure, errorMessage: errorMessage };
}());
