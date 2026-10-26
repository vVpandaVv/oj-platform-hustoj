(function () {
    'use strict';

    var MIN_POINT_DISTANCE = 2;
    var MAX_ELEMENTS_PER_PAGE = 500;
    var DEFAULT_WIDTH = 4;
    var FALLBACK_SYNC_INTERVAL_MS = 300;
    var REALTIME_BACKUP_SYNC_INTERVAL_MS = 1000;
    var REALTIME_PREVIEW_INTERVAL_MS = 32;
    var REALTIME_POINT_BATCH_SIZE = 512;
    var SHAPE_TOOLS = { line: true, rect: true, circle: true, arrow: true };

    function initialize() {
        var render = window.HttWhiteboardRender;
        var root = document.querySelector('[data-htt-whiteboard-host]');
        if (!root || !render) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var maxPages = config.maxPages || 500;

        var setup = root.querySelector('[data-field="setup"]');
        var messagesEl = root.querySelector('[data-field="messages"]');
        var board = root.querySelector('[data-field="board"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var connectionStatus = root.querySelector('[data-field="connection-status"]');
        var canvas = root.querySelector('[data-field="canvas"]');
        var previewCanvas = root.querySelector('[data-field="preview-canvas"]');
        var canvasWrap = root.querySelector('[data-field="canvas-wrap"]');
        var materialFrame = root.querySelector('[data-field="material-frame"]');
        var materialSelect = root.querySelector('[data-field="material-select"]');
        var materialPageInput = root.querySelector('[data-field="material-page"]');
        var materialImportMode = root.querySelector('[data-field="material-import-mode"]');
        var backgroundSharing = root.querySelector('[data-field="background-sharing"]');
        var backgroundSharingLive = root.querySelector('[data-field="background-sharing-live"]');
        var backgroundSharingStatus = root.querySelector('[data-field="background-sharing-status"]');
        var backgroundSharingRetry = root.querySelector('[data-action="retry-background-sharing"]');
        var materialDialog = root.querySelector('[data-field="material-dialog"]');
        var pdfSelect = root.querySelector('[data-field="pdf-material"]');
        var pdfPageInput = root.querySelector('[data-field="pdf-page"]');
        var pdfPreview = root.querySelector('[data-field="pdf-preview"]');
        var pdfStatus = root.querySelector('[data-field="pdf-status"]');
        var pdfCount = root.querySelector('[data-field="pdf-page-count"]');
        var pdfImportMode = root.querySelector('[data-field="pdf-import-mode"]');
        var pdfPageCount = 1;
        var pdfReady = false;
        var pdfPreviewGeneration = 0;
        var pdfInitial = false;
        var initialBackground = null;
        var ctx = canvas.getContext('2d');
        var previewCtx = previewCanvas.getContext('2d');
        var toolsEl = root.querySelector('[data-field="tools"]');
        var colorsEl = root.querySelector('[data-field="colors"]');
        var widthInput = root.querySelector('[data-field="width"]');
        var pageIndicatorEl = root.querySelector('[data-field="page-indicator"]');
        var imageUploadInput = root.querySelector('[data-field="image-upload"]');

        var startButton = root.querySelector('[data-action="start"]');
        var undoButton = root.querySelector('[data-action="undo"]');
        var redoButton = root.querySelector('[data-action="redo"]');
        var clearButton = root.querySelector('[data-action="clear"]');
        var prevPageButton = root.querySelector('[data-action="prev-page"]');
        var nextPageButton = root.querySelector('[data-action="next-page"]');
        var addPageButton = root.querySelector('[data-action="add-page"]');
        var saveImageButton = root.querySelector('[data-action="save-image"]');
        var fullscreenButton = root.querySelector('[data-action="fullscreen"]');
        var closeButton = root.querySelector('[data-action="close"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('판서 사용법', '<ul>'
                    + '<li>"칠판 열기"를 누르면 그리기를 시작할 수 있습니다.</li>'
                    + '<li>펜/지우개/직선/사각형/원/화살표/텍스트 도구와 색상, 굵기를 선택할 수 있습니다.</li>'
                    + '<li>되돌리기/다시하기로 그린 내용을 취소하거나 복원할 수 있습니다.</li>'
                    + '<li>페이지를 여러 장 추가해 넘겨가며 사용할 수 있습니다.</li>'
                    + '<li>학습자료 플러그인의 PDF를 고르면 자료 쪽을 배경으로 두고 그 위에 판서합니다.</li>'
                    + '<li>이미지를 삽입해 그 위에 판서할 수 있습니다. 삽입한 이미지는 칠판을 닫으면 삭제됩니다.</li>'
                    + '<li>"전체화면"으로 칠판을 크게 볼 수 있습니다.</li>'
                    + '<li>"이미지로 저장"으로 현재 페이지를 PNG로 내려받을 수 있습니다.</li>'
                    + '<li>동시 참여 모드에서는 학생 화면에도 실시간으로 그림이 나타납니다.</li>'
                    + '</ul>');
            });
        }

        var joinCode = null;
        var pages = [{ elements: [], background: null }];
        var redoStacks = [[]];
        var currentPageIndex = 0;
        var currentTool = 'pen';
        var currentColor = '#1a1a1a';
        var currentStroke = null;
        var shapeStart = null;
        var currentShape = null;
        var activePointerId = null;
        var drawingRect = null;
        var drawingFrame = null;
        var gestureTool = '';
        var gestureColor = '';
        var gestureWidth = DEFAULT_WIDTH;
        var syncHandle = null;
        var syncInFlight = false;
        var liveSyncEnabled = false;
        var lastSyncAt = 0;
        var dirty = false;
        var imageCache = {};
        var inkLayer = render.createLiveLayer(canvas, imageCache);
        var realtimeConnection = null;
        var realtimeOpen = false;
        var realtimePreviewHandle = null;
        var lastRealtimePreviewAt = 0;
        var realtimePreviewCounter = 0;
        var activeRealtimePreviewId = '';
        var realtimeSentPointCount = 0;
        var syncFailures = 0;
        var syncError = '';
        var stateRevision = 0;
        var materialSharing = 'permissions';
        var requestedMaterialSharing = 'permissions';
        var sharingGeneration = 0;
        var sharingBusy = false;
        var sharedBackgrounds = new Map();
        var sharingFailure = null;

        function updateConnectionStatus() {
            connectionStatus.hidden = !liveSyncEnabled;
            connectionStatus.textContent = syncError ? '판서 저장 실패: ' + syncError + ' (자동 재시도 중)'
                : (realtimeOpen ? '실시간 연결됨 · 저장본 백업 중' : '자동 새로고침으로 공유 중 · 실시간 연결 확인 중');
        }

        function request(path, method, body) {
            try {
                if (window.HttAudience) { body = window.HttAudience.prepare(root, path, method, body); }
            } catch (error) { return Promise.reject(error); }
            var abort = new AbortController();
            var timeout = window.setTimeout(function () { abort.abort(); }, 10000);
            return fetch(config.restUrl + path, {
                method: method,
                headers: Object.assign(
                    { 'X-WP-Nonce': config.nonce },
                    body ? { 'Content-Type': 'application/json' } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: abort.signal,
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                    }
                    if (window.HttAudience) { window.HttAudience.completed(root, path, data); }
                    return data;
                });
            }).finally(function () { window.clearTimeout(timeout); });
        }

        function showMessages(messages) {
            messagesEl.innerHTML = '';
            messages.forEach(function (message) {
                var item = document.createElement('div');
                item.className = 'message ' + (message.type || 'info');
                item.textContent = message.text;
                messagesEl.appendChild(item);
            });
        }

        function selectedMaterialBackground() {
            if (initialBackground) { return initialBackground; }
            if (!materialSelect || !materialSelect.value) {
                return null;
            }
            var materials = Array.isArray(config.materials) ? config.materials : [];
            var selected = materials.find(function (material) {
                return String(material.id) === String(materialSelect.value);
            });
            if (!selected || !selected.url) {
                return null;
            }
            return {
                type: 'pdf',
                url: selected.url,
                title: selected.title || '학습자료',
                page: Math.max(1, parseInt(materialPageInput && materialPageInput.value, 10) || 1),
            };
        }

        function updateMaterialFrame() {
            if (!materialFrame || !canvasWrap) {
                return;
            }
            var background = pages[currentPageIndex] && pages[currentPageIndex].background;
            canvasWrap.classList.toggle('has-material', Boolean(background));
            materialFrame.hidden = !background;
            if (window.HttPdfPages) {
                window.HttPdfPages.render(background, materialFrame).then(function () {
                    window.HttPdfPages.preload(background);
                }).catch(function (error) {
                    if (background !== pages[currentPageIndex].background) { return; }
                    showMessages([{ type: 'warning', text: window.HttPdfPages.errorMessage(error) }]);
                });
            }
            shareCurrentBackground();
        }

        function uploadImage(file, purpose, code) {
            var formData = new FormData();
            formData.append('image', file, file.name || 'background.webp');
            if (purpose) { formData.append('purpose', purpose); }
            var abort = new AbortController();
            var timer = window.setTimeout(function () { abort.abort(); }, 30000);
            return fetch(config.restUrl + '/sessions/' + code + '/whiteboard-image', {
                method: 'POST', headers: { 'X-WP-Nonce': config.nonce }, body: formData,
                credentials: 'same-origin', signal: abort.signal,
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) { throw new Error((data && data.message) || '이미지를 업로드하지 못했습니다.'); }
                    return data;
                });
            }).finally(function () { window.clearTimeout(timer); });
        }

        function prepareSharedBackground(background, generation, code) {
            var key = background.url + '#' + background.page;
            if (sharedBackgrounds.has(key)) { return sharedBackgrounds.get(key); }
            var bitmap = document.createElement('canvas');
            var task = window.HttPdfPages.render(background, bitmap, { resolution: 2 }).then(function () {
                return Promise.all([window.HttPdfPages.measure(background), new Promise(function (resolve, reject) {
                    bitmap.toBlob(function (blob) { if (blob) { resolve(blob); } else { reject(new Error('배경 이미지를 만들지 못했습니다.')); } }, 'image/webp', 0.96);
                })]);
            }).then(function (values) {
                if (sharingGeneration !== generation || joinCode !== code) { throw new Error('배경 공유가 취소되었습니다.'); }
                return uploadImage(values[1], 'background', code).then(function (data) { return { url: data.url, bounds: values[0] }; });
            }).catch(function (error) { sharedBackgrounds.delete(key); throw error; });
            sharedBackgrounds.set(key, task);
            return task;
        }

        function shareCurrentBackground() {
            var current = pages[currentPageIndex] && pages[currentPageIndex].background;
            var enabled = liveSyncEnabled && materialSharing === 'session' && joinCode;
            backgroundSharingStatus.hidden = !enabled || !current;
            backgroundSharingRetry.hidden = true;
            if (!enabled || !current) { return; }
            if (sharingFailure && sharingFailure.key === current.url + '#' + current.page) {
                backgroundSharingStatus.textContent = '배경 공유 실패: ' + sharingFailure.message;
                backgroundSharingRetry.hidden = false;
                return;
            }
            backgroundSharingStatus.textContent = current.shared ? '수업 배경 공유됨' : '수업 배경 공유 준비 중';
            if (sharingBusy) { return; }
            var targets = [pages[currentPageIndex], pages[currentPageIndex + 1]].filter(function (page) {
                return page && page.background && page.background.type === 'pdf' && !page.background.shared;
            });
            if (!targets.length) { return; }
            var generation = sharingGeneration;
            var code = joinCode;
            var index = currentPageIndex;
            var failed = false;
            sharingBusy = true;
            // Prepare only the visible and following page, sequentially, while pen messages stay lightweight.
            targets.reduce(function (chain, page) {
                var background = page.background;
                return chain.then(function () { return prepareSharedBackground(background, generation, code); }).then(function (shared) {
                    if (generation !== sharingGeneration || page.background !== background || joinCode !== code) { return; }
                    background.shared = shared;
                    markStateDirty();
                    scheduleRealtimePreview();
                    if (pages[currentPageIndex].background === background) { backgroundSharingStatus.textContent = '수업 배경 공유됨'; }
                });
            }, Promise.resolve()).catch(function (error) {
                failed = true;
                if (generation === sharingGeneration) {
                    sharingFailure = { key: current.url + '#' + current.page, message: error.message };
                    backgroundSharingStatus.textContent = '배경 공유 실패: ' + error.message;
                    backgroundSharingRetry.hidden = false;
                }
            }).finally(function () {
                sharingBusy = false;
                if (generation !== sharingGeneration || !failed || index !== currentPageIndex) { shareCurrentBackground(); }
            });
        }

        backgroundSharingRetry.addEventListener('click', function () { sharingFailure = null; shareCurrentBackground(); });
        backgroundSharingLive.addEventListener('change', function () {
            requestedMaterialSharing = backgroundSharingLive.value;
            backgroundSharing.value = requestedMaterialSharing;
            dirty = true;
            stateRevision++;
            scheduleStatePush();
        });

        function chosenPdf() {
            var material = (config.materials || []).find(function (item) { return String(item.id) === pdfSelect.value; });
            return material ? {
                type: 'pdf', id: material.id, title: material.title, url: material.url,
                page: Math.max(1, Math.min(pdfPageCount, parseInt(pdfPageInput.value, 10) || 1)),
            } : null;
        }

        function setPdfActions(enabled) {
            root.querySelector('[data-action="apply-material"]').disabled = !enabled;
            root.querySelector('[data-action="insert-material"]').disabled = !enabled;
        }

        function updatePdfImportControls() {
            var all = pdfImportMode.value === 'all';
            root.querySelector('[data-action="apply-material"]').hidden = !pdfInitial && all;
            root.querySelector('[data-action="apply-material"]').textContent = pdfInitial
                ? (all ? '전체 페이지로 준비' : '시작 배경으로 선택') : '현재 페이지 배경으로 적용';
            root.querySelector('[data-action="insert-material"]').hidden = pdfInitial;
            root.querySelector('[data-action="insert-material"]').textContent = all ? '전체 ' + pdfPageCount + '쪽 추가' : '선택한 쪽 추가';
        }

        function pdfBoardPages(background, count, all) {
            return Array.from({ length: all ? count : 1 }, function (_, index) {
                return { elements: [], background: Object.assign({}, background, { page: all ? index + 1 : background.page }) };
            });
        }

        function availablePages() {
            return pdfInitial || isEmptyBoard() ? maxPages : maxPages - pages.length;
        }

        function isEmptyBoard() {
            return pages.length === 1 && !pages[0].background && pages[0].elements.length === 0 && redoStacks[0].length === 0;
        }

        function prepareInitialPages() {
            var background = selectedMaterialBackground();
            if (!background) { return Promise.resolve({ pages: [{ elements: [], background: null }], currentPage: 0 }); }
            if (!window.HttPdfPages) { return Promise.reject(new Error('PDF 표시 모듈을 불러오지 못했습니다.')); }
            return window.HttPdfPages.load(background.url).then(function (pdf) {
                var all = !materialImportMode || materialImportMode.value === 'all';
                if (all && pdf.numPages > maxPages) { throw new Error('문서가 ' + pdf.numPages + '쪽입니다. 판서는 최대 ' + maxPages + '페이지까지 불러올 수 있습니다.'); }
                background.page = Math.max(1, Math.min(pdf.numPages, Number(background.page) || 1));
                return { pages: pdfBoardPages(background, pdf.numPages, all), currentPage: all ? background.page - 1 : 0 };
            });
        }

        function previewPdf() {
            var generation = ++pdfPreviewGeneration;
            var selected = chosenPdf();
            pdfReady = false;
            setPdfActions(false);
            if (window.HttPdfPages) { window.HttPdfPages.render(null, pdfPreview); }
            pdfCount.textContent = '';
            root.querySelector('[data-action="pdf-prev"]').disabled = true;
            root.querySelector('[data-action="pdf-next"]').disabled = true;
            if (!selected || !window.HttPdfPages) {
                pdfStatus.textContent = 'PDF 자료를 선택하세요.';
                return Promise.resolve();
            }
            pdfStatus.textContent = '미리보기를 불러오고 있습니다.';
            return window.HttPdfPages.load(selected.url).then(function (pdf) {
                if (generation !== pdfPreviewGeneration) { return; }
                pdfPageCount = pdf.numPages;
                updatePdfImportControls();
                pdfPageInput.max = String(pdfPageCount);
                selected.page = Math.max(1, Math.min(pdfPageCount, parseInt(pdfPageInput.value, 10) || 1));
                pdfPageInput.value = String(selected.page);
                pdfCount.textContent = '/ ' + pdfPageCount;
                root.querySelector('[data-action="pdf-prev"]').disabled = selected.page <= 1;
                root.querySelector('[data-action="pdf-next"]').disabled = selected.page >= pdfPageCount;
                return window.HttPdfPages.render(selected, pdfPreview).then(function () {
                    if (generation !== pdfPreviewGeneration) { return; }
                    pdfReady = true;
                    var count = pdfImportMode.value === 'all' ? pdfPageCount : 1;
                    setPdfActions(count <= availablePages());
                    pdfStatus.textContent = count <= availablePages()
                        ? selected.title + ' · ' + selected.page + '쪽'
                        : '추가할 수 있는 페이지는 ' + availablePages() + '쪽입니다. 문서 전체를 추가할 공간이 부족합니다.';
                });
            }).catch(function (error) {
                if (generation !== pdfPreviewGeneration) { return; }
                pdfStatus.textContent = window.HttPdfPages.errorMessage(error);
            });
        }

        function fillPdfMaterials() {
            var previous = pdfSelect.value;
            pdfSelect.innerHTML = '';
            pdfSelect.add(new Option('PDF 자료 선택', ''));
            (config.materials || []).forEach(function (item) { pdfSelect.add(new Option(item.title, String(item.id))); });
            pdfSelect.value = previous;
        }

        function openPdfPicker(initial) {
            pdfInitial = initial;
            if (initial && materialImportMode) { pdfImportMode.value = materialImportMode.value; }
            fillPdfMaterials();
            var background = initial ? selectedMaterialBackground() : pages[currentPageIndex].background;
            var selected = background && (config.materials || []).find(function (item) { return item.url === background.url; });
            if (selected) {
                pdfSelect.value = String(selected.id);
                pdfPageInput.value = String(background.page || 1);
            } else if (!pdfSelect.value && materialSelect) {
                pdfSelect.value = materialSelect.value;
                pdfPageInput.value = materialPageInput ? materialPageInput.value : '1';
            }
            root.querySelector('[data-action="add-blank-page"]').hidden = initial;
            updatePdfImportControls();
            // Keep the picker visible before opening the board and in fullscreen.
            (document.fullscreenElement === board || root.classList.contains('htt-whiteboard-expanded') ? board : root).appendChild(materialDialog);
            if (!materialDialog.open) { materialDialog.showModal(); }
            previewPdf();
        }

        function addBoardPage(background) {
            if (pages.length >= maxPages) {
                showMessages([{ type: 'warning', text: '판서는 최대 ' + maxPages + '페이지까지 추가할 수 있습니다.' }]);
                return;
            }
            pages.push({ elements: [], background: background || null });
            redoStacks.push([]);
            currentPageIndex = pages.length - 1;
            markStateDirty();
            updatePageIndicator();
            redrawAll();
        }

        if (materialDialog) {
            pdfImportMode.addEventListener('change', function () { updatePdfImportControls(); previewPdf(); });
            materialDialog.addEventListener('close', function () { ++pdfPreviewGeneration; });
            pdfSelect.addEventListener('change', function () { pdfPageInput.value = '1'; pdfPageCount = 1; previewPdf(); });
            pdfPageInput.addEventListener('change', previewPdf);
            root.querySelector('[data-action="pdf-prev"]').addEventListener('click', function () { pdfPageInput.value = String(Math.max(1, Number(pdfPageInput.value) - 1)); previewPdf(); });
            root.querySelector('[data-action="pdf-next"]').addEventListener('click', function () { pdfPageInput.value = String(Math.min(pdfPageCount, Number(pdfPageInput.value) + 1)); previewPdf(); });
            root.querySelector('[data-action="close-material"]').addEventListener('click', function () { materialDialog.close(); });
            root.querySelector('[data-action="choose-material"]').addEventListener('click', function () { openPdfPicker(false); });
            var initialPreviewButton = root.querySelector('[data-action="preview-initial-material"]');
            if (initialPreviewButton) { initialPreviewButton.addEventListener('click', function () { openPdfPicker(true); }); }
            if (materialSelect) { materialSelect.addEventListener('change', function () { initialBackground = null; }); }
            if (materialPageInput) { materialPageInput.addEventListener('change', function () { initialBackground = null; }); }
            root.querySelector('[data-action="apply-material"]').addEventListener('click', function () {
                if (!pdfReady) { return; }
                if (pdfInitial) {
                    initialBackground = chosenPdf();
                    if (materialImportMode) { materialImportMode.value = pdfImportMode.value; }
                    if (materialSelect) { materialSelect.value = pdfSelect.value; }
                    if (materialPageInput) { materialPageInput.value = pdfPageInput.value; }
                } else {
                    pages[currentPageIndex].background = chosenPdf();
                    markStateDirty(); updatePageIndicator(); redrawAll();
                }
                materialDialog.close();
            });
            root.querySelector('[data-action="insert-material"]').addEventListener('click', function () {
                if (!pdfReady) { return; }
                var added = pdfBoardPages(chosenPdf(), pdfPageCount, pdfImportMode.value === 'all');
                if (added.length > availablePages()) { return; }
                var empty = isEmptyBoard();
                var index = empty ? 0 : currentPageIndex + 1;
                pages.splice.apply(pages, [index, empty ? 1 : 0].concat(added));
                redoStacks.splice.apply(redoStacks, [index, empty ? 1 : 0].concat(added.map(function () { return []; })));
                currentPageIndex = index;
                markStateDirty(); updatePageIndicator(); redrawAll(); materialDialog.close();
            });
            root.querySelector('[data-action="add-blank-page"]').addEventListener('click', function () { addBoardPage(null); materialDialog.close(); });
            root.querySelector('[data-action="refresh-materials"]').addEventListener('click', function () {
                pdfStatus.textContent = '자료 목록을 불러오고 있습니다.';
                var separator = config.restUrl.indexOf('?') === -1 ? '?' : '&';
                request('/integrations/activities' + separator + 'kinds=material', 'GET').then(function (data) {
                    config.materials = (data.items || []).filter(function (item) { return /^application\/pdf(?:;|$)/i.test(item.contentType || ''); })
                        .map(function (item) { return { id: item.key, title: item.title, url: item.url }; });
                    fillPdfMaterials(); previewPdf();
                }).catch(function (error) { pdfStatus.textContent = error.message; });
            });
        }

        function currentElements() {
            return pages[currentPageIndex].elements;
        }

        function syncedPages() {
            var transientElement = currentStroke || currentShape;
            return pages.map(function (page, pageIndex) {
                return {
                    elements: page.elements.concat(
                        transientElement && pageIndex === currentPageIndex ? [transientElement] : []
                    ),
                    background: page.background || null,
                };
            });
        }

        function snapshotState() {
            return { pages: pages, currentPage: currentPageIndex, revision: stateRevision, materialSharing: materialSharing };
        }

        function sendRealtimeSnapshot() {
            if (!realtimeConnection) {
                return;
            }
            if (realtimePreviewHandle !== null) {
                window.clearTimeout(realtimePreviewHandle);
                realtimePreviewHandle = null;
            }
            activeRealtimePreviewId = '';
            realtimeSentPointCount = 0;
            realtimeConnection.send({ t: 'snapshot', state: snapshotState() });
        }

        function flushRealtimePreview() {
            realtimePreviewHandle = null;
            if (!realtimeConnection || !realtimeOpen) {
                return;
            }
            if (!currentStroke && !currentShape) {
                return;
            }
            lastRealtimePreviewAt = Date.now();
            if (currentStroke) {
                if (!activeRealtimePreviewId) {
                    realtimePreviewCounter += 1;
                    activeRealtimePreviewId = String(realtimePreviewCounter);
                    realtimeSentPointCount = 0;
                }
                if (realtimeSentPointCount === 0) {
                    var started = realtimeConnection.send({
                        t: 'preview-start',
                        id: activeRealtimePreviewId,
                        page: currentPageIndex,
                        element: {
                            type: currentStroke.type,
                            color: currentStroke.color,
                            width: currentStroke.width,
                            points: currentStroke.points.slice(0, REALTIME_POINT_BATCH_SIZE),
                        },
                    });
                    if (!started) { return; }
                    realtimeSentPointCount = Math.min(currentStroke.points.length, REALTIME_POINT_BATCH_SIZE);
                }
                // Bound both packet size and packet rate, including coalesced samples after a stalled frame.
                if (currentStroke.points.length > realtimeSentPointCount) {
                    var end = Math.min(currentStroke.points.length, realtimeSentPointCount + REALTIME_POINT_BATCH_SIZE);
                    var sent = realtimeConnection.send({
                        t: 'preview-points',
                        id: activeRealtimePreviewId,
                        points: currentStroke.points.slice(realtimeSentPointCount, end),
                    });
                    if (sent) { realtimeSentPointCount = end; }
                }
                if (currentStroke.points.length > realtimeSentPointCount) { scheduleRealtimePreview(); }
                return;
            }

            if (!activeRealtimePreviewId) {
                realtimePreviewCounter += 1;
                activeRealtimePreviewId = String(realtimePreviewCounter);
            }
            realtimeConnection.send({
                t: 'preview-shape',
                id: activeRealtimePreviewId,
                page: currentPageIndex,
                element: currentShape,
            });
        }

        function scheduleRealtimePreview() {
            if (!realtimeConnection || !realtimeOpen || realtimePreviewHandle !== null) {
                return;
            }
            var elapsed = Date.now() - lastRealtimePreviewAt;
            realtimePreviewHandle = window.setTimeout(flushRealtimePreview, Math.max(0, REALTIME_PREVIEW_INTERVAL_MS - elapsed));
        }

        function markStateDirty(kind) {
            stateRevision += 1;
            dirty = true;
            scheduleStatePush();
            if (kind === 'preview') {
                scheduleRealtimePreview();
            } else {
                sendRealtimeSnapshot();
            }
        }

        function redrawAll() {
            updateMaterialFrame();
            inkLayer.setBase(currentElements());
            inkLayer.preview(currentStroke, activeRealtimePreviewId);
        }

        function updatePageIndicator() {
            var background = pages[currentPageIndex] && pages[currentPageIndex].background;
            pageIndicatorEl.textContent = background && background.type === 'pdf'
                ? ('PDF ' + background.page + '쪽 · ' + (currentPageIndex + 1) + ' / ' + pages.length)
                : ((currentPageIndex + 1) + ' / ' + pages.length);
            prevPageButton.disabled = currentPageIndex === 0;
            nextPageButton.disabled = currentPageIndex >= pages.length - 1;
        }

        function toLogical(clientX, clientY) {
            var rect = drawingRect || canvas.getBoundingClientRect();
            return {
                x: Math.max(0, Math.min(render.LOGICAL_WIDTH, (clientX - rect.left) / rect.width * render.LOGICAL_WIDTH)),
                y: Math.max(0, Math.min(render.LOGICAL_HEIGHT, (clientY - rect.top) / rect.height * render.LOGICAL_HEIGHT)),
            };
        }

        function addElement(el) {
            var elements = currentElements();
            elements.push(el);
            var trimmed = elements.length > MAX_ELEMENTS_PER_PAGE;
            if (elements.length > MAX_ELEMENTS_PER_PAGE) {
                elements.splice(0, elements.length - MAX_ELEMENTS_PER_PAGE);
            }
            redoStacks[currentPageIndex] = [];
            if (trimmed) { inkLayer.setBase(elements); }
            else { inkLayer.append(el); }
            markStateDirty();
        }

        function pointerDown(event) {
            if (activePointerId !== null || event.button !== 0 || event.isPrimary === false) { return; }
            event.preventDefault();
            canvas.focus({ preventScroll: true });
            var point = toLogical(event.clientX, event.clientY);

            if (currentTool === 'text') {
                var text = window.prompt('삽입할 텍스트를 입력하세요.', '');
                if (text) { addElement({ type: 'text', x: Math.round(point.x), y: Math.round(point.y), text: text, color: currentColor, fontSize: 28 }); }
                return;
            }
            activePointerId = event.pointerId;
            drawingRect = canvas.getBoundingClientRect();
            gestureTool = currentTool;
            gestureColor = currentColor;
            gestureWidth = parseInt(widthInput.value, 10) || DEFAULT_WIDTH;
            try { canvas.setPointerCapture(event.pointerId); } catch (error) {}

            if (currentTool === 'pen' || currentTool === 'eraser') {
                realtimePreviewCounter += 1;
                activeRealtimePreviewId = String(realtimePreviewCounter);
                realtimeSentPointCount = 0;
                currentStroke = {
                    type: currentTool === 'eraser' ? 'erase' : 'draw',
                    color: currentColor,
                    width: gestureWidth,
                    points: [[Math.round(point.x), Math.round(point.y)]],
                };
            } else if (SHAPE_TOOLS[currentTool]) {
                realtimePreviewCounter += 1;
                activeRealtimePreviewId = String(realtimePreviewCounter);
                realtimeSentPointCount = 0;
                shapeStart = point;
            }
        }

        function appendPointerPoint(event) {
            var point = toLogical(event.clientX, event.clientY);
            if (currentStroke) {
                var last = currentStroke.points[currentStroke.points.length - 1];
                var dx = point.x - last[0];
                var dy = point.y - last[1];
                if ((dx * dx + dy * dy) < MIN_POINT_DISTANCE * MIN_POINT_DISTANCE) {
                    return;
                }
                currentStroke.points.push([Math.round(point.x), Math.round(point.y)]);
            } else if (shapeStart) {
                currentShape = {
                    type: gestureTool,
                    x1: shapeStart.x,
                    y1: shapeStart.y,
                    x2: point.x,
                    y2: point.y,
                    color: gestureColor,
                    width: gestureWidth,
                };
            }
        }

        function scheduleDrawing() {
            if (drawingFrame !== null) { return; }
            drawingFrame = window.requestAnimationFrame(function () {
                drawingFrame = null;
                inkLayer.preview(currentStroke, activeRealtimePreviewId);
                previewCtx.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
                if (currentShape) { render.drawShape(previewCtx, previewCanvas, currentShape); }
            });
        }

        function pointerMove(event) {
            if (activePointerId === null || event.pointerId !== activePointerId) { return; }
            event.preventDefault();
            if (!event.buttons) { finishGesture(); return; }
            var rect = canvas.getBoundingClientRect();
            if (Math.abs(rect.left - drawingRect.left) > 1 || Math.abs(rect.top - drawingRect.top) > 1
                || Math.abs(rect.width - drawingRect.width) > 1 || Math.abs(rect.height - drawingRect.height) > 1) {
                finishGesture(); return;
            }
            var samples = event.getCoalescedEvents ? event.getCoalescedEvents() : [];
            (samples.length ? samples : [event]).forEach(appendPointerPoint);
            scheduleDrawing();
            markStateDirty('preview');
        }

        function finishGesture(event) {
            if (activePointerId === null) { return; }
            if (event && event.pointerId !== activePointerId) { return; }
            // Cancellation/capture loss coordinates can be zero or stale; retain only valid samples.
            if (event && event.type === 'pointerup') { event.preventDefault(); appendPointerPoint(event); }
            var pointerId = activePointerId;
            activePointerId = null;
            drawingRect = null;
            if (drawingFrame !== null) { window.cancelAnimationFrame(drawingFrame); drawingFrame = null; }
            previewCtx.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
            if (currentStroke) {
                var finishedStroke = currentStroke;
                currentStroke = null;
                activeRealtimePreviewId = '';
                realtimeSentPointCount = 0;
                if (finishedStroke.points.length > 1) {
                    addElement(finishedStroke);
                } else {
                    sendRealtimeSnapshot();
                    redrawAll();
                }
            } else if (shapeStart) {
                var finishedShape = currentShape;
                shapeStart = null;
                currentShape = null;
                activeRealtimePreviewId = '';
                if (finishedShape) { addElement(finishedShape); }
                else { sendRealtimeSnapshot(); redrawAll(); }
            }
            if (canvas.hasPointerCapture(pointerId)) { canvas.releasePointerCapture(pointerId); }
        }

        canvas.addEventListener('pointerdown', pointerDown, { passive: false });
        canvas.addEventListener('pointermove', pointerMove, { passive: false });
        canvas.addEventListener('pointerup', finishGesture, { passive: false });
        canvas.addEventListener('pointercancel', finishGesture);
        canvas.addEventListener('lostpointercapture', finishGesture);
        window.addEventListener('pointerup', finishGesture, { passive: false });
        window.addEventListener('blur', function () { finishGesture(); });
        canvasWrap.addEventListener('dragstart', function (event) { event.preventDefault(); });
        canvasWrap.addEventListener('selectstart', function (event) { event.preventDefault(); });
        canvasWrap.addEventListener('contextmenu', function (event) { event.preventDefault(); });
        canvasWrap.addEventListener('wheel', function (event) { if (activePointerId !== null) { event.preventDefault(); } }, { passive: false });

        toolsEl.querySelectorAll('.htt-whiteboard-tool').forEach(function (button) {
            button.addEventListener('click', function () {
                currentTool = button.getAttribute('data-tool');
                toolsEl.querySelectorAll('.htt-whiteboard-tool').forEach(function (b) {
                    b.classList.toggle('is-active', b === button);
                });
            });
        });

        colorsEl.querySelectorAll('.htt-whiteboard-color').forEach(function (button) {
            button.addEventListener('click', function () {
                currentColor = button.getAttribute('data-color');
                colorsEl.querySelectorAll('.htt-whiteboard-color').forEach(function (b) {
                    b.classList.toggle('is-active', b === button);
                });
            });
        });

        undoButton.addEventListener('click', function () {
            var elements = currentElements();
            if (!elements.length) {
                return;
            }
            redoStacks[currentPageIndex].push(elements.pop());
            markStateDirty();
            redrawAll();
        });

        redoButton.addEventListener('click', function () {
            var redoStack = redoStacks[currentPageIndex];
            if (!redoStack.length) {
                return;
            }
            currentElements().push(redoStack.pop());
            markStateDirty();
            redrawAll();
        });

        clearButton.addEventListener('click', function () {
            pages[currentPageIndex].elements = [];
            redoStacks[currentPageIndex] = [];
            markStateDirty();
            redrawAll();
        });

        prevPageButton.addEventListener('click', function () {
            if (currentPageIndex === 0) {
                return;
            }
            currentPageIndex -= 1;
            markStateDirty();
            updatePageIndicator();
            redrawAll();
        });

        nextPageButton.addEventListener('click', function () {
            if (currentPageIndex >= pages.length - 1) {
                return;
            }
            currentPageIndex += 1;
            markStateDirty();
            updatePageIndicator();
            redrawAll();
        });

        addPageButton.addEventListener('click', function () {
            openPdfPicker(false);
        });

        if (imageUploadInput) {
            imageUploadInput.addEventListener('change', function () {
                var file = imageUploadInput.files && imageUploadInput.files[0];
                imageUploadInput.value = '';
                if (!file) {
                    return;
                }
                if (!joinCode) {
                    showMessages([{ type: 'warning', text: '칠판을 먼저 열어주세요.' }]);
                    return;
                }

                uploadImage(file, '', joinCode).then(function (data) {
                    var width = 400;
                    var height = 300;
                    addElement({
                        type: 'image',
                        url: data.url,
                        x: (render.LOGICAL_WIDTH - width) / 2,
                        y: (render.LOGICAL_HEIGHT - height) / 2,
                        width: width,
                        height: height,
                    });
                }).catch(function (error) {
                    showMessages([{ type: 'warning', text: error.message }]);
                });
            });
        }

        saveImageButton.addEventListener('click', function () {
            var background = pages[currentPageIndex].background;
            var pageIndex = currentPageIndex;
            saveImageButton.disabled = true;
            var ready = background && window.HttPdfPages ? window.HttPdfPages.render(background, materialFrame) : Promise.resolve();
            ready.then(function () {
                if (pageIndex !== currentPageIndex) { return; }
                var output = document.createElement('canvas');
                output.width = canvas.width; output.height = canvas.height;
                var outputContext = output.getContext('2d');
                outputContext.fillStyle = 'white';
                outputContext.fillRect(0, 0, output.width, output.height);
                if (background) { outputContext.drawImage(materialFrame, 0, 0, output.width, output.height); }
                outputContext.drawImage(canvas, 0, 0);
                var link = document.createElement('a');
                link.download = 'whiteboard.png';
                link.href = output.toDataURL('image/png');
                document.body.appendChild(link); link.click(); link.remove();
            }).catch(function () {
                showMessages([{ type: 'warning', text: 'PDF 배경을 불러온 뒤 다시 저장해 주세요.' }]);
            }).finally(function () { saveImageButton.disabled = false; });
        });

        var expandedPlaceholder = null;

        function fitExpandedCanvas() {
            var expanded = document.fullscreenElement === board || root.classList.contains('htt-whiteboard-expanded');
            if (!expanded) {
                if (canvasWrap.style.width && activePointerId !== null) { finishGesture(); }
                canvasWrap.style.width = ''; return;
            }
            var available = board.clientHeight - 32;
            Array.prototype.forEach.call(board.children, function (child) {
                if (child === canvasWrap || child.hidden || child.tagName === 'DIALOG') { return; }
                var style = window.getComputedStyle(child);
                available -= child.getBoundingClientRect().height + parseFloat(style.marginTop || 0) + parseFloat(style.marginBottom || 0);
            });
            var width = Math.max(100, Math.min(board.clientWidth - 32, available * 5 / 3)) + 'px';
            if (width !== canvasWrap.style.width && activePointerId !== null) { finishGesture(); }
            canvasWrap.style.width = width;
        }

        function updateFullscreenButton() {
            var expanded = document.fullscreenElement === board || root.classList.contains('htt-whiteboard-expanded');
            fullscreenButton.setAttribute('aria-pressed', String(expanded));
            fullscreenButton.textContent = expanded ? '전체화면 해제' : '전체화면';
            window.requestAnimationFrame(fitExpandedCanvas);
        }

        function leaveExpanded() {
            root.classList.remove('htt-whiteboard-expanded');
            board.classList.remove('is-expanded');
            if (expandedPlaceholder) { expandedPlaceholder.replaceWith(root); expandedPlaceholder = null; }
            updateFullscreenButton();
        }

        function expandInWindow() {
            expandedPlaceholder = document.createComment('whiteboard position');
            root.before(expandedPlaceholder);
            document.body.appendChild(root);
            root.classList.add('htt-whiteboard-expanded');
            board.classList.add('is-expanded');
            updateFullscreenButton();
            fullscreenButton.focus();
        }

        if (fullscreenButton) {
            fullscreenButton.setAttribute('aria-pressed', 'false');
            fullscreenButton.addEventListener('click', function () {
                if (root.classList.contains('htt-whiteboard-expanded')) { leaveExpanded(); return; }
                if (document.fullscreenElement === board) {
                    Promise.resolve(document.exitFullscreen()).catch(leaveExpanded);
                } else {
                    try {
                        if (render.prefersWindowExpansion() || !board.requestFullscreen) { expandInWindow(); return; }
                        Promise.resolve(board.requestFullscreen()).catch(expandInWindow);
                    } catch (error) { expandInWindow(); }
                }
            });
            document.addEventListener('fullscreenchange', updateFullscreenButton);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && root.classList.contains('htt-whiteboard-expanded') && !materialDialog.open) { leaveExpanded(); fullscreenButton.focus(); }
            });
            window.addEventListener('resize', fitExpandedCanvas);
            joinInfo.addEventListener('toggle', fitExpandedCanvas);
            if (window.ResizeObserver) {
                var toolbarObserver = new ResizeObserver(fitExpandedCanvas);
                toolbarObserver.observe(root.querySelector('.htt-whiteboard-toolbar'));
                toolbarObserver.observe(backgroundSharingStatus);
            }
        }

        function scheduleStatePush() {
            if (!liveSyncEnabled || !joinCode || !dirty || syncInFlight || syncHandle !== null) {
                return;
            }

            var interval = realtimeOpen ? REALTIME_BACKUP_SYNC_INTERVAL_MS : FALLBACK_SYNC_INTERVAL_MS;
            var elapsed = Date.now() - lastSyncAt;
            var delay = Math.max(0, interval - elapsed);
            if (syncFailures) { delay = Math.max(delay, Math.min(15000, 1000 * Math.pow(2, syncFailures - 1))); }
            syncHandle = window.setTimeout(function () {
                syncHandle = null;
                pushState();
            }, delay);
        }

        function pushState() {
            if (!liveSyncEnabled || !joinCode || !dirty || syncInFlight) {
                return;
            }

            var state = { pages: syncedPages(), currentPage: currentPageIndex, revision: stateRevision, materialSharing: requestedMaterialSharing };
            dirty = false;
            syncInFlight = true;
            lastSyncAt = Date.now();
            request('/sessions/' + joinCode + '/state', 'POST', {
                state: state,
            }).then(function () {
                syncFailures = 0;
                syncError = '';
                updateConnectionStatus();
                syncInFlight = false;
                if (state.materialSharing === requestedMaterialSharing && materialSharing !== requestedMaterialSharing) {
                    materialSharing = requestedMaterialSharing;
                    sharingGeneration++;
                    sharingFailure = null;
                    sendRealtimeSnapshot();
                    scheduleRealtimePreview();
                }
                shareCurrentBackground();
                scheduleStatePush();
            }).catch(function (error) {
                syncFailures = Math.min(5, syncFailures + 1);
                syncError = error.name === 'AbortError' ? '서버 응답 시간이 초과되었습니다.' : error.message;
                updateConnectionStatus();
                syncInFlight = false;
                dirty = true;
                scheduleStatePush();
            });
        }

        function stopRealtime() {
            realtimeOpen = false;
            if (realtimePreviewHandle !== null) {
                window.clearTimeout(realtimePreviewHandle);
                realtimePreviewHandle = null;
            }
            if (realtimeConnection) {
                realtimeConnection.stop();
                realtimeConnection = null;
            }
        }

        function startRealtime() {
            stopRealtime();
            if (!window.HttRealtime || !joinCode) {
                return;
            }

            realtimeConnection = window.HttRealtime.connect({
                ticket: function () {
                    return request('/sessions/' + joinCode + '/realtime-ticket', 'POST');
                },
                onStatus: function (status) {
                    realtimeOpen = status === 'open';
                    updateConnectionStatus();
                    if (realtimeOpen) {
                        activeRealtimePreviewId = '';
                        realtimeSentPointCount = 0;
                        sendRealtimeSnapshot();
                        scheduleRealtimePreview();
                    } else {
                        scheduleStatePush();
                    }
                },
            });
        }

        if (copyLinkButton) {
            copyLinkButton.addEventListener('click', function () {
                if (!joinCode) {
                    return;
                }
                var link = config.joinUrl + '?code=' + joinCode;
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(link).then(function () {
                        if (window.OJP && typeof window.OJP.toast === 'function') {
                            window.OJP.toast('참여 링크를 복사했습니다');
                        }
                    }).catch(function () { window.prompt('참여 링크', link); });
                } else {
                    window.prompt('참여 링크', link);
                }
            });
        }

        startButton.addEventListener('click', function () {
            if (startButton.disabled) { return; }
            startButton.disabled = true;
            var mode = root.querySelector('input[name="htt-whiteboard-mode"]:checked').value;
            materialSharing = requestedMaterialSharing = backgroundSharing.value;
            backgroundSharingLive.value = materialSharing;
            backgroundSharingLive.hidden = mode !== 'live';
            sharingGeneration++;
            sharedBackgrounds = new Map();
            sharingFailure = null;
            showMessages([]);
            prepareInitialPages().then(function (initial) {
                pages = initial.pages;
                redoStacks = pages.map(function () { return []; });
                currentPageIndex = initial.currentPage;
                currentStroke = null;
                shapeStart = null;
                currentShape = null;
                dirty = false;
                liveSyncEnabled = false;
                lastSyncAt = 0;
                syncFailures = 0;
                syncError = '';
                stateRevision = 0;
                if (syncHandle !== null) {
                    window.clearTimeout(syncHandle);
                    syncHandle = null;
                }
                imageCache = {};
                stopRealtime();
                updatePageIndicator();
                redrawAll();

                return request('/sessions', 'POST', {
                    toolType: 'whiteboard',
                    mode: mode,
                    state: snapshotState(),
                });
            }).then(function (data) {
                joinCode = data.joinCode;
                liveSyncEnabled = data.mode === 'live';
                updateConnectionStatus();
                setup.hidden = true;
                board.hidden = false;

                if (liveSyncEnabled) {
                    joinInfo.hidden = false;
                    joinCodeEl.textContent = joinCode;
                    startRealtime();
                    shareCurrentBackground();
                } else {
                    joinInfo.hidden = true;
                }
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: window.HttPdfPages ? window.HttPdfPages.errorMessage(error) : error.message }]);
            }).finally(function () { startButton.disabled = false; });
        });

        closeButton.addEventListener('click', function () {
            sharingGeneration++;
            finishGesture();
            leaveExpanded();
            liveSyncEnabled = false;
            if (syncHandle !== null) {
                window.clearTimeout(syncHandle);
                syncHandle = null;
            }
            if (document.fullscreenElement) {
                Promise.resolve(document.exitFullscreen()).catch(function () {});
            }
            if (realtimeConnection) {
                realtimeConnection.send({ t: 'close' });
            }
            stopRealtime();
            var endRequest = joinCode ? request('/sessions/' + joinCode + '/end', 'POST').catch(function () {}) : Promise.resolve();
            endRequest.then(function () {
                joinCode = null;
                pages = [{ elements: [], background: null }];
                redoStacks = [[]];
                currentPageIndex = 0;
                imageCache = {};
                board.hidden = true;
                setup.hidden = false;
                showMessages([]);
            });
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize); }
    else { initialize(); }
})();
