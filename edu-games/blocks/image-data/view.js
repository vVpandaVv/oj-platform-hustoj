(() => {
    const MODE_LABELS = {
        restore: "배열 보고 복원",
        draw: "직접 만들기",
    };
    const TOOL_LABELS = {
        pen: "펜",
        eraser: "지우개",
        fill: "채우기",
        pick: "스포이드",
    };

    const escapeHtml = (value) => String(value || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    const chunk = (items, size) => {
        const rows = [];
        for (let index = 0; index < items.length; index += size) {
            rows.push(items.slice(index, index + size));
        }
        return rows;
    };

    const randomItem = (items) => items[Math.floor(Math.random() * items.length)];
    const shuffle = (items) => {
        const copy = [...items];
        for (let index = copy.length - 1; index > 0; index -= 1) {
            const swapIndex = Math.floor(Math.random() * (index + 1));
            [copy[index], copy[swapIndex]] = [copy[swapIndex], copy[index]];
        }

        return copy;
    };

    class ImageDataActivity {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.rules = this.normalizeRules(this.config.rules || {});
            this.collectionKey = `edu-image-collection:20260911:${this.config.currentUserId || 0}`;
            try {
                const saved = JSON.parse(localStorage.getItem(this.collectionKey) || '[]');
                this.collection = new Set(Array.isArray(saved) ? saved.filter(id => this.rules.samples.some(sample => sample.id === id)) : []);
            } catch { this.collection = new Set(); }
            this.paletteMap = this.rules.palette.reduce((map, color) => {
                map[color.index] = color;
                return map;
            }, {});
            this.fields = {
                controls: root.querySelector(".oj-image-controls"),
                modes: root.querySelector('[data-field="modes"]'),
                sampleControl: root.querySelector(".oj-image-sample-select"),
                sample: root.querySelector('[data-field="sample"]'),
                size: root.querySelector('[data-field="size"]'),
                selected: root.querySelector('[data-field="selected"]'),
                filled: root.querySelector('[data-field="filled"]'),
                accuracy: root.querySelector('[data-field="accuracy"]'),
                sampleMeta: root.querySelector('[data-field="sample-meta"]'),
                sampleTitle: root.querySelector('[data-field="sample-title"]'),
                drawTools: root.querySelector('[data-field="draw-tools"]'),
                tools: root.querySelector('[data-field="tools"]'),
                preview: root.querySelector('[data-field="preview"]'),
                board: root.querySelector('[data-field="board"]'),
                feedback: root.querySelector('[data-field="feedback"]'),
                palette: root.querySelector('[data-field="palette"]'),
                dataTitle: root.querySelector('[data-field="data-title"]'),
                dataCount: root.querySelector('[data-field="data-count"]'),
                data: root.querySelector('[data-field="data"]'),
                loadSample: root.querySelector('[data-action="load-sample"]'),
                answerPanel: root.querySelector('[data-field="answer-panel"]'),
                answerPrompt: root.querySelector('[data-field="answer-prompt"]'),
                answerChoices: root.querySelector('[data-field="answer-choices"]'),
            };
            const initialSampleId = this.randomSampleId();
            this.state = {
                mode: this.rules.defaultMode,
                sampleId: initialSampleId,
                selected: this.rules.palette[1] ? this.rules.palette[1].index : this.rules.palette[0].index,
                pixels: this.blankPixels(),
                checked: false,
                accuracy: null,
                selectedAnswerId: "",
                answerOptions: this.answerOptionsFor(initialSampleId),
                answerChecked: false,
                isPainting: false,
                activePointerId: null,
                tool: "pen",
                undoStack: [],
                redoStack: [],
            };

            this.bindEvents();
            this.renderControls();
            this.resetPixels();
            this.account = window.EduGamesProgress?.connect(this.config, this.root, data => {
                this.collection = new Set(Object.keys(data.records || {}).filter(id => this.rules.samples.some(sample => sample.id === id)));
                this.renderCollection();
            });
        }

        readConfig() {
            try {
                return JSON.parse(this.root.dataset.config || "{}");
            } catch (error) {
                return {};
            }
        }

        normalizeRules(rules) {
            const palette = Array.isArray(rules.palette) && rules.palette.length
                ? rules.palette
                : [
                    { index: "0", name: "흰색", hex: "#ffffff" },
                    { index: "1", name: "검정", hex: "#111827" },
                ];
            const samples = Array.isArray(rules.samples) && rules.samples.length ? rules.samples : [];
            const width = Math.max(1, Number(rules.width) || 16);
            const height = Math.max(1, Number(rules.height) || 16);

            return {
                width,
                height,
                palette: palette.map((color) => ({
                    index: String(color.index || "0").toUpperCase(),
                    name: String(color.name || color.index || ""),
                    hex: String(color.hex || "#ffffff"),
                })),
                samples: samples.map((sample) => ({
                    ...sample,
                    data: Array.isArray(sample.data) ? sample.data.map((row) => String(row || "").toUpperCase()) : [],
                })),
                defaultMode: MODE_LABELS[rules.defaultMode] ? rules.defaultMode : "restore",
            };
        }

        bindEvents() {
            this.root.addEventListener("click", (event) => {
                const modeButton = event.target.closest("[data-mode]");
                if (modeButton) {
                    const nextMode = modeButton.dataset.mode;
                    const changedMode = nextMode !== this.state.mode;

                    if (changedMode && nextMode === "restore") {
                        this.state.sampleId = this.randomSampleId(this.state.sampleId);
                        this.state.tool = "pen";
                    }

                    this.state.mode = nextMode;
                    this.state.checked = false;
                    this.state.accuracy = null;
                    this.resetPixels();
                    return;
                }

                const paletteButton = event.target.closest("[data-color]");
                if (paletteButton) {
                    this.state.selected = paletteButton.dataset.color;
                    this.renderPalette();
                    this.renderStats();
                    return;
                }

                const toolButton = event.target.closest("[data-tool]");
                if (toolButton) {
                    this.state.tool = toolButton.dataset.tool;
                    this.renderTools();
                    this.setFeedback(this.toolFeedback());
                    return;
                }

                const answerButton = event.target.closest("[data-answer]");
                if (answerButton) {
                    this.state.selectedAnswerId = answerButton.dataset.answer;

                    if (this.state.checked && this.state.accuracy === 100) {
                        this.state.answerChecked = true;
                        this.collectSolved();
                        this.renderAll();
                        this.setFeedback(this.state.selectedAnswerId === this.state.sampleId
                            ? `복원 성공입니다. 정답은 ${this.currentSample().title || "보기 이미지"}입니다.`
                            : "그림은 맞게 복원했지만 선택한 정답이 다릅니다.");
                        return;
                    }

                    this.state.answerChecked = false;
                    this.renderAnswerChoices();
                    this.setFeedback("정답 보기를 선택했습니다. 그림을 완성한 뒤 정답 확인을 눌러 보세요.");
                    return;
                }

                const actionButton = event.target.closest("[data-action]");
                if (!actionButton) {
                    return;
                }

                if (actionButton.dataset.action === "clear") {
                    this.saveHistory();
                    this.resetPixels({ resetHistory: false });
                }

                if (actionButton.dataset.action === "undo") {
                    this.undo();
                }

                if (actionButton.dataset.action === "redo") {
                    this.redo();
                }

                if (actionButton.dataset.action === "load-sample") {
                    this.loadSampleAction();
                }

                if (actionButton.dataset.action === "check") {
                    this.checkAnswer();
                }
                if (actionButton.dataset.action === "export") this.exportImage();
            });

            this.root.querySelector('[data-field="grid-size"]').addEventListener('change', (event) => this.changeSize(Number(event.target.value)));
            this.fields.board.addEventListener('keydown', (event) => {
                const cell = event.target.closest('[data-pixel]'); if (!cell) return;
                const index = Number(cell.dataset.pixel);
                const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -this.rules.width, ArrowDown: this.rules.width }[event.key];
                if (step !== undefined) {
                    event.preventDefault();
                    this.fields.board.querySelector(`[data-pixel="${Math.max(0, Math.min(this.state.pixels.length - 1, index + step))}"]`)?.focus();
                } else if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    if (this.state.tool === 'pick') this.pickPixel(index);
                    else { this.saveHistory(); if (this.state.tool === 'fill') this.fillPixels(index); else this.paintPixel(index); }
                    this.fields.board.querySelector(`[data-pixel="${index}"]`)?.focus();
                }
            });
            this.root.querySelector('[data-field="import"]').addEventListener('change', async (event) => {
                const file = event.target.files[0]; if (!file) return;
                try {
                    if (file.size > 20000) throw new Error('파일이 너무 큽니다.');
                    const data = JSON.parse(await file.text());
                    if (data?.format !== 'edu-games-image-v1' || ![8, 16].includes(data.size) || !Array.isArray(data.rows) || data.rows.length !== data.size || !data.rows.every((row) => typeof row === 'string' && row.length === data.size && /^[0-9A-F]+$/.test(row)) || !Array.isArray(data.palette) || JSON.stringify(data.palette) !== JSON.stringify(this.rules.palette)) throw new Error('크기·색상 코드·팔레트를 확인하세요.');
                    this.changeSize(data.size); this.state.mode = 'draw'; this.state.pixels = data.rows.join('').split(''); this.renderAll(); this.setFeedback('이미지 배열을 불러왔습니다.');
                } catch (error) { this.setFeedback(error.message || '배열 파일을 읽지 못했습니다.'); }
                event.target.value = '';
            });

            this.fields.sample.addEventListener("change", () => {
                this.state.sampleId = this.fields.sample.value;
                this.state.checked = false;
                this.state.accuracy = null;
                if (this.state.mode === "restore") {
                    this.state.pixels = this.blankPixels();
                }
                this.renderAll();
                this.setFeedback(this.state.mode === "restore"
                    ? "색상 배열을 보고 빈 격자를 복원하세요."
                    : "샘플 불러오기를 누르면 선택한 이미지를 편집할 수 있습니다.");
            });

            this.fields.board.addEventListener("pointerdown", (event) => {
                const cell = event.target.closest("[data-pixel]");
                if (!cell || (event.button !== undefined && event.button !== 0)) {
                    return;
                }

                event.preventDefault();
                const index = Number(cell.dataset.pixel);

                if (this.state.tool === "pick") {
                    this.pickPixel(index);
                    return;
                }

                this.saveHistory();
                if (this.state.tool === "fill") {
                    this.fillPixels(index);
                    return;
                }

                this.state.isPainting = true;
                this.state.activePointerId = event.pointerId;
                if (this.fields.board.setPointerCapture) {
                    this.fields.board.setPointerCapture(event.pointerId);
                }
                this.paintPixel(index);
            });

            this.fields.board.addEventListener("pointermove", (event) => {
                if (!this.state.isPainting || this.state.activePointerId !== event.pointerId) {
                    return;
                }

                if (event.buttons !== 1) {
                    this.stopPainting(event.pointerId);
                    return;
                }

                const target = document.elementFromPoint(event.clientX, event.clientY);
                const cell = target ? target.closest("[data-pixel]") : null;
                if (cell && this.fields.board.contains(cell)) {
                    event.preventDefault();
                    this.paintPixel(Number(cell.dataset.pixel));
                }
            });

            this.fields.board.addEventListener("pointerup", (event) => {
                this.stopPainting(event.pointerId);
            });

            this.fields.board.addEventListener("pointercancel", (event) => {
                this.stopPainting(event.pointerId);
            });

            window.addEventListener("blur", () => {
                this.stopPainting();
            });
        }

        renderControls() {
            const isRestore = this.state.mode === "restore";

            this.fields.modes.innerHTML = Object.entries(MODE_LABELS).map(([mode, label]) => `
                <button type="button" class="${mode === this.state.mode ? "is-active" : ""}" data-mode="${escapeHtml(mode)}" role="tab" aria-selected="${mode === this.state.mode ? "true" : "false"}">
                    ${escapeHtml(label)}
                </button>
            `).join("");

            this.fields.sample.innerHTML = this.availableSamples().map((sample) => `
                <option value="${escapeHtml(sample.id)}"${sample.id === this.state.sampleId ? " selected" : ""}>
                    ${escapeHtml(sample.title || sample.id)}
                </option>
            `).join("");

            this.fields.controls.classList.toggle("is-restore", isRestore);
            this.fields.sampleControl.hidden = isRestore;
            this.fields.sample.disabled = isRestore;
            this.fields.loadSample.textContent = isRestore ? "새 보기" : "샘플 불러오기";
            this.fields.drawTools.hidden = isRestore;
        }

        resetPixels({ resetHistory = true } = {}) {
            this.state.pixels = this.blankPixels();
            this.state.checked = false;
            this.state.accuracy = null;
            this.state.selectedAnswerId = "";
            this.state.answerOptions = this.answerOptionsFor(this.state.sampleId);
            this.state.answerChecked = false;
            if (resetHistory) {
                this.state.undoStack = [];
                this.state.redoStack = [];
            }
            this.renderAll();
            this.setFeedback(this.state.mode === "restore"
                ? "오른쪽 행별 숫자를 보고 같은 위치에 팔레트 색을 찍은 뒤, 하단 보기에서 정답을 고르세요."
                : `${this.rules.width}x${this.rules.height} 이미지`);
        }

        loadSampleAction() {
            if (this.state.mode === "restore") {
                this.state.sampleId = this.randomSampleId(this.state.sampleId);
                this.fields.sample.value = this.state.sampleId;
                this.resetPixels();
                return;
            }

            this.saveHistory();
            this.state.pixels = this.targetPixels();
            this.state.checked = false;
            this.state.accuracy = null;
            this.state.answerChecked = false;
            this.renderAll();
            this.setFeedback("선택한 샘플을 캔버스에 불러왔습니다. 색을 바꾸면 아래 배열도 함께 바뀝니다.");
        }

        checkAnswer() {
            if (this.state.mode === "draw") {
                this.state.checked = false;
                this.state.accuracy = null;
                this.renderAll();
                this.setFeedback("직접 만든 이미지의 색상 배열이 오른쪽에 생성되었습니다.");
                return;
            }

            const target = this.targetPixels();
            let correct = 0;
            target.forEach((value, index) => {
                if (this.state.pixels[index] === value) {
                    correct += 1;
                }
            });

            this.state.checked = true;
            this.state.accuracy = target.length ? (correct / target.length) * 100 : 0;
            this.state.answerChecked = correct === target.length && Boolean(this.state.selectedAnswerId);
            this.collectSolved();
            this.renderAll();

            if (correct === target.length) {
                if (!this.state.selectedAnswerId) {
                    this.setFeedback("그림은 맞게 복원했습니다. 하단 보기에서 정답 이름을 선택하세요.");
                    return;
                }

                if (this.state.selectedAnswerId === this.state.sampleId) {
                    this.setFeedback(`복원 성공입니다. 정답은 ${this.currentSample().title || "보기 이미지"}입니다.`);
                    return;
                }

                this.setFeedback("그림은 맞게 복원했지만 선택한 정답이 다릅니다.");
                return;
            }

            this.setFeedback(`${target.length - correct}개의 픽셀이 배열과 다릅니다. 붉은 테두리의 칸을 다시 확인하세요.`);
        }

        stopPainting(pointerId = null) {
            if (pointerId !== null && this.state.activePointerId !== pointerId) {
                return;
            }

            if (
                this.state.activePointerId !== null
                && this.fields.board.hasPointerCapture
                && this.fields.board.hasPointerCapture(this.state.activePointerId)
            ) {
                this.fields.board.releasePointerCapture(this.state.activePointerId);
            }

            this.state.isPainting = false;
            this.state.activePointerId = null;
        }

        saveHistory() {
            const snapshot = this.state.pixels.join("");
            const last = this.state.undoStack[this.state.undoStack.length - 1];
            if (last && last.join("") === snapshot) {
                return;
            }

            this.state.undoStack.push([...this.state.pixels]);
            if (this.state.undoStack.length > 40) {
                this.state.undoStack.shift();
            }
            this.state.redoStack = [];
        }

        undo() {
            const previous = this.state.undoStack.pop();
            if (!previous) {
                this.setFeedback("되돌릴 작업이 없습니다.");
                return;
            }

            this.state.redoStack.push([...this.state.pixels]);
            this.applyPixels(previous);
            this.setFeedback("이전 상태로 되돌렸습니다.");
        }

        redo() {
            const next = this.state.redoStack.pop();
            if (!next) {
                this.setFeedback("다시 적용할 작업이 없습니다.");
                return;
            }

            this.state.undoStack.push([...this.state.pixels]);
            this.applyPixels(next);
            this.setFeedback("되돌린 작업을 다시 적용했습니다.");
        }

        applyPixels(pixels) {
            this.state.pixels = [...pixels];
            this.state.checked = false;
            this.state.accuracy = null;
            this.state.answerChecked = false;
            this.renderAll();
        }

        paintValue() {
            return this.state.mode === "draw" && this.state.tool === "eraser" ? "0" : this.state.selected;
        }

        pickPixel(index) {
            if (!Number.isInteger(index) || index < 0 || index >= this.state.pixels.length) {
                return;
            }

            this.state.selected = this.state.pixels[index];
            this.state.tool = "pen";
            this.renderTools();
            this.renderPalette();
            this.renderStats();
            this.setFeedback("스포이드로 색을 선택했습니다.");
        }

        fillPixels(index) {
            if (!Number.isInteger(index) || index < 0 || index >= this.state.pixels.length) {
                return;
            }

            const fromValue = this.state.pixels[index];
            const nextValue = this.paintValue();
            if (fromValue === nextValue) {
                this.renderTools();
                return;
            }

            const width = this.rules.width;
            const height = this.rules.height;
            const queue = [index];
            const visited = new Set();

            while (queue.length) {
                const current = queue.shift();
                if (visited.has(current) || this.state.pixels[current] !== fromValue) {
                    continue;
                }

                visited.add(current);
                this.state.pixels[current] = nextValue;

                const x = current % width;
                const y = Math.floor(current / width);
                if (x > 0) {
                    queue.push(current - 1);
                }
                if (x < width - 1) {
                    queue.push(current + 1);
                }
                if (y > 0) {
                    queue.push(current - width);
                }
                if (y < height - 1) {
                    queue.push(current + width);
                }
            }

            this.state.checked = false;
            this.state.accuracy = null;
            this.state.answerChecked = false;
            this.renderBoard();
            this.renderPreview();
            this.renderData();
            this.renderAnswerChoices();
            this.renderStats();
            this.renderTools();
        }

        paintPixel(index) {
            if (!Number.isInteger(index) || index < 0 || index >= this.state.pixels.length) {
                return;
            }

            const nextValue = this.paintValue();
            if (this.state.pixels[index] === nextValue) {
                return;
            }

            this.state.pixels[index] = nextValue;
            this.state.checked = false;
            this.state.accuracy = null;
            this.state.answerChecked = false;
            this.renderBoard();
            this.renderPreview();
            this.renderData();
            this.renderAnswerChoices();
            this.renderStats();
            this.renderTools();
        }

        renderAll() {
            this.renderControls();
            this.renderSampleInfo();
            this.renderTools();
            this.renderPalette();
            this.renderBoard();
            this.renderPreview();
            this.renderData();
            this.renderAnswerChoices();
            this.renderStats();
            this.renderCollection();
        }

        collectSolved() {
            if (this.state.mode !== 'restore' || this.state.accuracy !== 100 || this.state.selectedAnswerId !== this.state.sampleId) return;
            this.collection.add(this.state.sampleId);
            this.account?.save({ sampleId: this.state.sampleId, pixels: [...this.state.pixels] });
            try { localStorage.setItem(this.collectionKey, JSON.stringify([...this.collection])); } catch { /* Collection remains available until this page closes. */ }
        }

        renderCollection() {
            const gallery = this.root.querySelector('[data-field="collection"]');
            if (!gallery) return;
            this.root.querySelector('[data-field="collection-progress"]').textContent = `복원 도감 ${this.collection.size} / ${this.rules.samples.length} · ${this.account?.synced ? '계정' : '이 기기'}`;
            gallery.innerHTML = this.rules.samples.map(sample => {
                const found = this.collection.has(sample.id), size = sample.size || 16;
                const pixels = found ? sample.data.join('').split('').map(code => `<i style="background:${this.paletteMap[code].hex}"></i>`).join('') : '';
                return `<figure class="oj-image-collect-item"><div class="oj-image-collect-pixels${found ? '' : ' is-unknown'}" style="--collect-size:${size}" aria-hidden="true">${found ? pixels : '?'}</div><figcaption>${found ? escapeHtml(sample.title) : '미발견'}<small>${size}×${size}</small></figcaption></figure>`;
            }).join('');
        }

        renderSampleInfo() {
            const sample = this.currentSample();
            const solved = this.state.mode === "restore"
                && this.state.checked
                && this.state.accuracy === 100
                && this.state.answerChecked
                && this.state.selectedAnswerId === this.state.sampleId;

            this.fields.sampleTitle.textContent = this.state.mode === "restore"
                ? (solved ? `정답: ${sample.title || ""}` : "보기 이미지 복원")
                : "16색 픽셀 캔버스";
            this.fields.sampleMeta.textContent = this.state.mode === "restore"
                ? (solved ? "색상 배열을 정확하게 이미지로 복원했습니다." : "색상 배열을 보고 어떤 그림인지 직접 복원해 보세요.")
                : "직접 만든 이미지는 0-F 색상 배열로 변환됩니다.";
        }

        renderPalette() {
            this.fields.palette.innerHTML = this.rules.palette.map((color) => `
                <button type="button" class="${color.index === this.state.selected ? "is-active" : ""}" data-color="${escapeHtml(color.index)}" title="${escapeHtml(color.name)}">
                    <span style="background:${escapeHtml(color.hex)}"></span>
                    <strong>${escapeHtml(color.index)}</strong>
                    <em>${escapeHtml(color.name)}</em>
                </button>
            `).join("");
        }

        renderTools() {
            this.fields.tools.innerHTML = Object.entries(TOOL_LABELS).map(([tool, label]) => `
                <button type="button" class="${tool === this.state.tool ? "is-active" : ""}" data-tool="${escapeHtml(tool)}" aria-pressed="${tool === this.state.tool ? "true" : "false"}">
                    ${escapeHtml(label)}
                </button>
            `).join("");

            this.root.querySelectorAll('[data-action="undo"]').forEach((button) => {
                button.disabled = this.state.undoStack.length === 0;
            });
            this.root.querySelectorAll('[data-action="redo"]').forEach((button) => {
                button.disabled = this.state.redoStack.length === 0;
            });
        }

        renderPreview() {
            this.fields.preview.style.setProperty("--image-grid-size", String(this.rules.width));
            this.fields.preview.innerHTML = this.state.pixels.map((value) => {
                const color = this.paletteMap[value] || this.rules.palette[0];
                return `<span style="--pixel-color:${escapeHtml(color.hex)}"></span>`;
            }).join("");
        }

        renderBoard() {
            const target = this.targetPixels();
            this.fields.board.style.setProperty("--image-grid-size", String(this.rules.width));
            this.fields.board.innerHTML = this.state.pixels.map((value, index) => {
                const color = this.paletteMap[value] || this.rules.palette[0];
                const mismatch = this.state.mode === "restore" && this.state.checked && value !== target[index];
                return `
                    <button type="button" class="oj-image-cell${mismatch ? " is-mismatch" : ""}" data-pixel="${index}" style="--pixel-color:${escapeHtml(color.hex)}" aria-label="${index + 1} ${escapeHtml(value)}"></button>
                `;
            }).join("");
        }

        renderData() {
            const dataPixels = this.state.mode === "restore" ? this.targetPixels() : this.state.pixels;
            const rows = chunk(dataPixels, this.rules.width);

            if (this.fields.dataTitle) {
                this.fields.dataTitle.textContent = this.state.mode === "restore" ? "행별 코드" : "내 이미지 코드";
            }
            if (this.fields.dataCount) {
                this.fields.dataCount.textContent = `${rows.length}줄`;
            }
            this.fields.data.style.setProperty("--image-grid-size", String(this.rules.width));
            this.fields.data.style.setProperty("--image-grid-height", String(this.rules.height));
            this.fields.data.innerHTML = rows.map((row, rowIndex) => `
                <div class="oj-image-data-row">
                    <span class="oj-image-data-label">${rowIndex + 1}</span>
                    <span class="oj-image-data-cells">
                        ${row.map((value, columnIndex) => `
                            <span class="oj-image-data-cell" title="${rowIndex + 1}행 ${columnIndex + 1}열 ${escapeHtml(value)}">
                                ${escapeHtml(value)}
                            </span>
                        `).join("")}
                    </span>
                </div>
            `).join("");
        }

        renderAnswerChoices() {
            const isRestore = this.state.mode === "restore";
            this.fields.answerPanel.hidden = !isRestore;

            if (!isRestore) {
                this.fields.answerChoices.innerHTML = "";
                return;
            }

            this.fields.answerPrompt.textContent = this.state.answerChecked
                ? "선택한 답과 복원한 그림을 함께 확인합니다."
                : "복원한 그림의 이름을 선택하세요.";
            this.fields.answerChoices.innerHTML = this.state.answerOptions.map((sampleId) => {
                const sample = this.sampleById(sampleId);
                const isSelected = sampleId === this.state.selectedAnswerId;
                const isCorrect = this.state.answerChecked && sampleId === this.state.sampleId;
                const isWrong = this.state.answerChecked && isSelected && sampleId !== this.state.sampleId;

                return `
                    <button type="button" class="${isSelected ? "is-selected" : ""} ${isCorrect ? "is-correct" : ""} ${isWrong ? "is-wrong" : ""}" data-answer="${escapeHtml(sampleId)}" aria-pressed="${isSelected ? "true" : "false"}">
                        ${escapeHtml(sample.title || sample.id || "")}
                    </button>
                `;
            }).join("");
        }

        renderStats() {
            const selected = this.paletteMap[this.state.selected] || {};
            const filled = this.state.pixels.filter((value) => value !== "0").length;

            this.fields.size.textContent = `${this.rules.width}x${this.rules.height}`;
            this.fields.selected.textContent = `${this.state.selected} ${selected.name || ""}`.trim();
            this.fields.filled.textContent = String(filled);
            const target = this.targetPixels();
            const foreground = target.flatMap((value, i) => value !== '0' ? [i] : []);
            const correct = foreground.filter((i) => this.state.pixels[i] === target[i]).length;
            const errors = target.filter((value, i) => value === '0' && this.state.pixels[i] !== '0').length;
            this.fields.accuracy.textContent = this.state.accuracy === null ? '-' : `${(foreground.length ? correct / foreground.length * 100 : 0).toFixed(1)}%`;
            this.root.querySelector('[data-field="background-errors"]').textContent = this.state.accuracy === null ? '-' : String(errors);
        }

        currentSample() {
            return this.sampleById(this.state.sampleId) || this.rules.samples[0] || { data: [] };
        }

        sampleById(sampleId) {
            return this.rules.samples.find((sample) => sample.id === sampleId) || null;
        }

        randomSampleId(exceptId = "") {
            const candidates = this.availableSamples().filter((sample) => sample.id !== exceptId);
            const unseen = candidates.filter(sample => !this.collection?.has(sample.id));
            const sample = randomItem(unseen.length ? unseen : candidates.length ? candidates : this.availableSamples());

            return sample ? sample.id : "";
        }

        answerOptionsFor(sampleId) {
            const correct = this.sampleById(sampleId);
            if (!correct) {
                return [];
            }

            const distractors = shuffle(this.availableSamples().filter((sample) => sample.id !== sampleId)).slice(0, 3);
            return shuffle([correct, ...distractors]).map((sample) => sample.id);
        }

        targetPixels() {
            const sample = this.currentSample();
            const raw = (sample.data || []).join("").toUpperCase().replace(/[^0-9A-F]/g, "");
            const pixels = raw.split("").slice(0, this.rules.width * this.rules.height);

            while (pixels.length < this.rules.width * this.rules.height) {
                pixels.push("0");
            }

            return pixels;
        }

        blankPixels() {
            return Array.from({ length: this.rules.width * this.rules.height }, () => "0");
        }

        availableSamples() { return this.rules.samples.filter((sample) => (sample.size || 16) === this.rules.width); }

        changeSize(size) {
            if (![8, 16].includes(size)) return;
            this.stopPainting(); this.rules.width = size; this.rules.height = size;
            this.root.querySelector('[data-field="grid-size"]').value = String(size);
            this.state.sampleId = this.randomSampleId(); this.resetPixels();
        }

        exportImage() {
            const data = { format: 'edu-games-image-v1', size: this.rules.width, palette: this.rules.palette, rows: chunk(this.state.pixels, this.rules.width).map((row) => row.join('')) };
            const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
            const link = document.createElement('a'); link.href = url; link.download = `pixel-${this.rules.width}.json`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        }

        toolFeedback() {
            if (this.state.tool === "eraser") {
                return "지우개를 선택했습니다. 칠한 칸을 0으로 되돌립니다.";
            }
            if (this.state.tool === "fill") {
                return "채우기를 선택했습니다. 같은 색으로 이어진 영역을 한 번에 칠합니다.";
            }
            if (this.state.tool === "pick") {
                return "스포이드를 선택했습니다. 캔버스의 칸을 눌러 색을 가져옵니다.";
            }

            return "펜을 선택했습니다. 클릭하거나 드래그해서 색칠합니다.";
        }

        setFeedback(message) {
            this.fields.feedback.textContent = message;
        }
    }

    const init = () => {
        document.querySelectorAll("[data-image-data]").forEach((root) => {
            if (!root.__ImageData) {
                root.__ImageData = new ImageDataActivity(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
