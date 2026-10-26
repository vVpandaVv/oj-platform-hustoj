(() => {
    const STORAGE_KEY = "edu-games-code-typing-best";
    const RANDOM_KEYWORD_SAMPLE_ID = "__random-keywords";

    const escapeHtml = (value) => String(value || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    const normalizeNewlines = (value) => String(value || "").replace(/\r\n?/g, "\n");

    const randomItem = (items) => items[Math.floor(Math.random() * items.length)];

    const KEYBOARD_ROWS = [
        [
            { base: "`", shift: "~" }, { base: "1", shift: "!" }, { base: "2", shift: "@" },
            { base: "3", shift: "#" }, { base: "4", shift: "$" }, { base: "5", shift: "%" },
            { base: "6", shift: "^" }, { base: "7", shift: "&" }, { base: "8", shift: "*" },
            { base: "9", shift: "(" }, { base: "0", shift: ")" }, { base: "-", shift: "_" },
            { base: "=", shift: "+" },
        ],
        [
            { special: "tab", label: "Tab", wide: true },
            { base: "q", shift: "Q" }, { base: "w", shift: "W" }, { base: "e", shift: "E" },
            { base: "r", shift: "R" }, { base: "t", shift: "T" }, { base: "y", shift: "Y" },
            { base: "u", shift: "U" }, { base: "i", shift: "I" }, { base: "o", shift: "O" },
            { base: "p", shift: "P" }, { base: "[", shift: "{" }, { base: "]", shift: "}" },
            { base: "\\", shift: "|" },
        ],
        [
            { base: "a", shift: "A" }, { base: "s", shift: "S" }, { base: "d", shift: "D" },
            { base: "f", shift: "F" }, { base: "g", shift: "G" }, { base: "h", shift: "H" },
            { base: "j", shift: "J" }, { base: "k", shift: "K" }, { base: "l", shift: "L" },
            { base: ";", shift: ":" }, { base: "'", shift: "\"" },
            { special: "enter", label: "Enter", wide: true },
        ],
        [
            { special: "shift", label: "Shift", wide: true },
            { base: "z", shift: "Z" }, { base: "x", shift: "X" }, { base: "c", shift: "C" },
            { base: "v", shift: "V" }, { base: "b", shift: "B" }, { base: "n", shift: "N" },
            { base: "m", shift: "M" }, { base: ",", shift: "<" }, { base: ".", shift: ">" },
            { base: "/", shift: "?" },
        ],
        [
            { special: "space", label: "Space", wide: true, extraWide: true },
        ],
    ];

    class CodeTypingGame {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.rules = this.normalizeRules(this.config.rules || {});
            this.languageKeys = Object.keys(this.rules.languages);
            this.modeKeys = Object.keys(this.rules.modes);
            this.fields = {
                language: root.querySelector('[data-field="language"]'),
                modes: root.querySelector('[data-field="modes"]'),
                sample: root.querySelector('[data-field="sample"]'),
                progress: root.querySelector('[data-field="progress"]'),
                accuracy: root.querySelector('[data-field="accuracy"]'),
                speed: root.querySelector('[data-field="speed"]'),
                mistakes: root.querySelector('[data-field="mistakes"]'),
                score: root.querySelector('[data-field="score"]'),
                rank: root.querySelector('[data-field="rank"]'),
                sampleMeta: root.querySelector('[data-field="sample-meta"]'),
                sampleTitle: root.querySelector('[data-field="sample-title"]'),
                stream: root.querySelector('[data-field="stream"]'),
                input: root.querySelector('[data-field="input"]'),
                feedback: root.querySelector('[data-field="feedback"]'),
                time: root.querySelector('[data-field="time"]'),
                samples: root.querySelector('[data-field="samples"]'),
                keyboard: root.querySelector('[data-field="keyboard"]'),
                rankStatus: root.querySelector('[data-field="rank-status"]'),
                leaderboard: root.querySelector('[data-field="leaderboard"]'),
            };

            this.bestScore = 0;
            try { this.bestScore = Number(window.localStorage.getItem(this.storageKey())) || 0; } catch { /* Storage can be disabled (private mode, blocked site data). */ }
            this.challengeStorageKey = `${this.storageKey()}:challenge-20260911`;
            try {
                const saved = JSON.parse(localStorage.getItem(this.challengeStorageKey) || '{}');
                this.speedRecords = saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
            } catch { this.speedRecords = {}; }
            this.keywordStreak = 0;
            this.keywordStreakLanguage = '';
            this.leaderboard = [];
            this.timer = 0;
            this.activeSample = null;
            this.practiceRound = 0;
            this.sessionRefreshPromise = null;
            this.resetAssessmentSession();
            if (this.fields.keyboard) {
                this.fields.keyboard.innerHTML = this.buildKeyboardMarkup();
            }
            this.state = {
                language: this.rules.defaultLanguage,
                mode: this.rules.defaultMode,
                sampleId: "",
                status: "idle",
                input: "",
                startedAt: 0,
                durationSeconds: 0,
                score: 0,
                myRank: null,
                isSaving: false,
                finishReason: "",
                timeLimitSeconds: 0,
            };

            this.ensureCurrentSample();
            this.bindEvents();
            this.renderControls();
            this.resetSession(false);
            this.loadLeaderboard();
            this.account = window.EduGamesProgress?.connect(this.config, this.root, data => {
                this.speedRecords = data.records || {}; this.bestScore = Number(data.bestScore) || 0; this.renderChallenge();
            });
        }

        storageKey() {
            return `${STORAGE_KEY}-${this.config.gameId || "code-typing"}:${this.config.currentUserId || 0}`;
        }

        readConfig() {
            try {
                return JSON.parse(this.root.dataset.config || "{}");
            } catch (error) {
                return {};
            }
        }

        normalizeRules(rules) {
            const languages = rules.languages && Object.keys(rules.languages).length
                ? rules.languages
                : { python: { label: "Python", extension: "py" } };
            const modes = rules.modes && Object.keys(rules.modes).length
                ? rules.modes
                : { keyword: { label: "키워드 연습", baseScore: 80, records: false, keywordBatchSize: 16 } };
            const samples = Array.isArray(rules.samples) && rules.samples.length
                ? rules.samples.map((sample) => ({ ...sample, content: normalizeNewlines(sample.content) }))
                : [{ id: "sample", language: "python", mode: "keyword", title: "Python", description: "", content: "def return if else" }];

            return {
                languages,
                modes,
                samples,
                keywordBank: this.normalizeKeywordBank(rules.keywordBank, samples, languages),
                defaultLanguage: rules.defaultLanguage && languages[rules.defaultLanguage] ? rules.defaultLanguage : Object.keys(languages)[0],
                defaultMode: rules.defaultMode && modes[rules.defaultMode] ? rules.defaultMode : Object.keys(modes)[0],
                scoring: {
                    characterScore: Number(rules.scoring && rules.scoring.characterScore) || 1.2,
                    accuracyBonus: Number(rules.scoring && rules.scoring.accuracyBonus) || 2.4,
                    speedBonus: Number(rules.scoring && rules.scoring.speedBonus) || 4,
                    mistakePenalty: Number(rules.scoring && rules.scoring.mistakePenalty) || 2.2,
                    minimumScore: Number(rules.scoring && rules.scoring.minimumScore) || 20,
                },
            };
        }

        normalizeKeywordBank(keywordBank, samples, languages) {
            const bank = {};

            Object.keys(languages).forEach((language) => {
                const configured = keywordBank && Array.isArray(keywordBank[language]) ? keywordBank[language] : [];
                const fallback = samples
                    .filter((sample) => sample.language === language && sample.mode === "keyword")
                    .flatMap((sample) => sample.content.split(/\s+/));
                const unique = Array.from(new Set(configured.concat(fallback)
                    .map((word) => String(word || "").trim())
                    .filter(Boolean)));

                bank[language] = unique.length ? unique : ["if", "else", "for", "return"];
            });

            return bank;
        }

        resetAssessmentSession() {
            this.session = {
                totalCorrect: 0,
                totalMistakes: 0,
                totalTyped: 0,
                samplesCompleted: 0,
            };
            // The visible line-by-line stream (see renderStream) has its own
            // history independent of session scoring: lineLog holds every
            // completed line across the whole run (survives sample-chaining
            // in advanceAssessmentSample), loggedLineCount is how many lines
            // of the CURRENT sample have already been logged.
            this.lineLog = [];
            this.loggedLineCount = 0;
        }

        bindEvents() {
            this.root.addEventListener("click", (event) => {
                if (event.target.closest(".oj-typing-stream-panel") && !event.target.closest("[data-mode], [data-action]")) {
                    this.fields.input.focus();
                }

                const modeButton = event.target.closest("[data-mode]");
                if (modeButton) {
                    this.state.mode = modeButton.dataset.mode;
                    this.ensureCurrentSample(true);
                    this.renderControls();
                    this.resetSession(false);
                    return;
                }

                const actionButton = event.target.closest("[data-action]");
                if (!actionButton) {
                    return;
                }

                if (actionButton.dataset.action === "start") {
                    this.start();
                }

                if (actionButton.dataset.action === "reset") {
                    this.resetSession(true);
                }
            });

            this.fields.language.addEventListener("change", () => {
                this.state.language = this.fields.language.value;
                this.ensureCurrentSample(true);
                this.renderControls();
                this.resetSession(false);
            });

            this.fields.sample.addEventListener("change", () => {
                this.state.sampleId = this.fields.sample.value;
                this.activeSample = null;
                this.resetSession(false);
            });

            this.fields.input.addEventListener("input", () => this.handleInput());
            this.fields.input.addEventListener("keydown", (event) => this.handleEditorKeydown(event));
        }

        renderControls() {
            this.fields.language.innerHTML = this.languageKeys.map((language) => `
                <option value="${escapeHtml(language)}"${language === this.state.language ? " selected" : ""}>
                    ${escapeHtml(this.rules.languages[language].label || language)}
                </option>
            `).join("");

            this.fields.modes.innerHTML = this.modeKeys.map((mode) => `
                <button type="button" class="${mode === this.state.mode ? "is-active" : ""}" data-mode="${escapeHtml(mode)}" role="tab" aria-selected="${mode === this.state.mode ? "true" : "false"}">
                    ${escapeHtml(this.rules.modes[mode].label || mode)}
                </button>
            `).join("");

            this.renderSampleOptions();
        }

        renderSampleOptions() {
            if (this.isKeywordMode()) {
                this.fields.sample.innerHTML = `<option value="${RANDOM_KEYWORD_SAMPLE_ID}" selected>랜덤 키워드 계속 연습</option>`;
                this.fields.sample.disabled = true;
                return;
            }

            const samples = this.filteredSamples();
            // Assessment mode chains samples together automatically (see
            // advanceAssessmentSample) — the dropdown just reflects the
            // current one, it isn't meant to be changed mid-run.
            this.fields.sample.disabled = this.isAssessment();
            this.fields.sample.innerHTML = samples.map((sample) => `
                <option value="${escapeHtml(sample.id)}"${sample.id === this.state.sampleId ? " selected" : ""}>
                    ${escapeHtml(sample.title || sample.id)}
                </option>
            `).join("");
        }

        filteredSamples(mode = this.state.mode) {
            return this.rules.samples.filter((sample) => sample.language === this.state.language && sample.mode === mode);
        }

        ensureCurrentSample(force = false) {
            if (this.isKeywordMode()) {
                this.state.sampleId = RANDOM_KEYWORD_SAMPLE_ID;
                if (force) {
                    this.activeSample = this.buildKeywordSample();
                }
                return;
            }

            const samples = this.filteredSamples();
            const exists = samples.some((sample) => sample.id === this.state.sampleId);

            if (force || !exists) {
                this.state.sampleId = samples.length ? samples[0].id : "";
                this.activeSample = null;
            }
        }

        currentSample() {
            if (this.activeSample) {
                return this.activeSample;
            }

            if (this.isKeywordMode()) {
                this.activeSample = this.buildKeywordSample();
                return this.activeSample;
            }

            return this.selectedSample();
        }

        selectedSample() {
            return this.rules.samples.find((sample) => sample.id === this.state.sampleId) || this.filteredSamples()[0] || this.rules.samples[0];
        }

        buildKeywordSample() {
            const mode = this.rules.modes.keyword || {};
            const bank = this.rules.keywordBank[this.state.language] || [];
            const count = Math.max(8, Number(mode.keywordBatchSize) || 16);
            const words = [];
            let previous = "";

            for (let index = 0; index < count; index += 1) {
                let next = randomItem(bank);
                if (bank.length > 1) {
                    while (next === previous) {
                        next = randomItem(bank);
                    }
                }
                words.push(next);
                previous = next;
            }

            return {
                id: `${RANDOM_KEYWORD_SAMPLE_ID}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                language: this.state.language,
                mode: "keyword",
                title: "랜덤 키워드 묶음",
                description: "사용 가능한 키워드와 자주 쓰는 용어가 무작위로 계속 나타납니다.",
                content: words.join(" "),
            };
        }

        start() {
            this.clearTimer();
            if (this.state.status === "finished") {
                this.prepareNextRound();
            }
            this.state.status = "idle";
            this.state.input = "";
            this.state.startedAt = 0;
            this.state.durationSeconds = 0;
            this.state.score = 0;
            this.state.myRank = null;
            this.state.finishReason = "";
            this.state.timeLimitSeconds = this.sessionTimeLimit();
            this.fields.input.value = "";
            this.fields.input.disabled = false;
            this.fields.input.focus();
            this.updateShellState();
            this.renderAll();
            this.setFeedback("첫 글자를 입력하면 기록이 시작됩니다.");
        }

        beginRound(initialInput = "") {
            this.clearTimer();
            this.ensureCurrentSample();
            if (!this.activeSample) {
                this.activeSample = this.isKeywordMode() ? this.buildKeywordSample() : this.selectedSample();
            }
            this.resetAssessmentSession();
            this.state.status = "running";
            this.state.input = normalizeNewlines(initialInput);
            this.state.startedAt = performance.now();
            this.state.durationSeconds = 0;
            this.state.score = 0;
            this.state.myRank = null;
            this.state.finishReason = "";
            this.state.timeLimitSeconds = this.sessionTimeLimit();
            this.fields.input.value = this.state.input;
            this.fields.input.disabled = false;
            if (this.isAssessment()) {
                // The token embedded in the page's initial config is
                // single-use; replaying assessment mode without a page
                // reload needs a fresh one or the eventual score save is
                // rejected no matter how high the score is. Kick this off
                // now (fire-and-forget) so it's ready by the time finish()
                // actually needs it.
                this.sessionRefreshPromise = this.refreshSessionToken();
            }
            this.updateShellState();
            this.setFeedback(this.startMessage());
            this.renderAll();
            this.timer = window.setInterval(() => this.tick(), 250);
        }

        startMessage() {
            if (this.isAssessment()) {
                return `검정이 시작되었습니다. 제한시간은 ${this.formatTime(this.state.timeLimitSeconds)}입니다. 코드를 다 쓰면 자동으로 다음 코드가 이어지며, 시간이 끝날 때까지 계속됩니다.`;
            }

            if (this.isKeywordMode()) {
                return "랜덤 키워드 연습이 시작되었습니다. 마지막 글자까지 친 뒤 Enter 또는 Space로 결과를 확인하세요.";
            }

            return "연습이 시작되었습니다. 마지막 글자까지 친 뒤 Enter 또는 Space로 결과를 확인하세요.";
        }

        resetSession(showMessage) {
            this.clearTimer();
            this.state.status = "idle";
            this.state.input = "";
            this.state.startedAt = 0;
            this.state.durationSeconds = 0;
            this.state.score = 0;
            this.state.finishReason = "";
            this.state.timeLimitSeconds = this.sessionTimeLimit();
            this.practiceRound = 0;
            this.resetAssessmentSession();
            this.activeSample = this.isKeywordMode() ? this.buildKeywordSample() : null;
            this.fields.input.value = "";
            this.fields.input.disabled = false;
            this.updateShellState();
            this.renderAll();
            this.setFeedback(showMessage ? "초기화했습니다. 첫 글자를 입력하면 다시 시작됩니다." : "언어와 예제를 고른 뒤 첫 글자를 입력하세요.");
        }

        tick() {
            if (this.state.status !== "running") {
                return;
            }

            if (this.isAssessment() && this.remainingSeconds() <= 0) {
                this.finish("expired");
                return;
            }

            this.renderStats();
        }

        handleInput() {
            const value = normalizeNewlines(this.fields.input.value);

            if (this.state.status === "finished") {
                this.fields.input.value = this.state.input;
                return;
            }

            if (this.state.status === "idle") {
                this.state.input = value;
                this.fields.input.value = value;
                if (value.length > 0) {
                    this.beginRound(value);
                    return;
                }
                this.renderStream();
                this.renderStats();
                return;
            }

            if (this.state.status !== "running") {
                return;
            }

            this.state.input = value;
            this.fields.input.value = this.state.input;

            // Assessment is a fixed-duration session: finishing a sample
            // early must never end it early (a fast typist would just get
            // idle time back). Instead, chain straight into another sample
            // and keep the same clock running until time itself runs out.
            if (this.isAssessment() && this.hasReachedTargetEnd() && this.remainingSeconds() > 0) {
                this.advanceAssessmentSample();
                return;
            }

            this.renderStream();
            this.renderStats();
        }

        advanceAssessmentSample() {
            // Flush the sample that just finished into line history BEFORE
            // swapping currentSample() out from under it, then only reset
            // the per-sample line counter — lineLog itself keeps growing
            // across the whole chained run so the stream never resets.
            this.syncLineLog();
            this.recordCompletedSample();
            this.activeSample = this.nextPracticeSample(this.currentSample().id);
            this.state.input = "";
            this.loggedLineCount = 0;
            this.fields.input.value = "";
            this.renderAll();
            this.setFeedback(`${this.session.samplesCompleted}개 완료했습니다. 남은 시간 동안 계속 이어서 입력하세요.`);
        }

        recordCompletedSample() {
            const stats = this.currentSampleStats();
            this.session.totalCorrect += stats.correct;
            this.session.totalMistakes += stats.mistakes;
            this.session.totalTyped += stats.typedLength;
            this.session.samplesCompleted += 1;
        }

        handleEditorKeydown(event) {
            if (this.state.status === "finished") {
                if (this.isNextRoundKey(event)) {
                    event.preventDefault();
                    this.prepareNextRound();
                    this.fields.input.focus();
                    this.setFeedback("새 예제가 열렸습니다. 첫 글자를 입력하면 기록이 시작됩니다.");
                    return;
                }

                if (this.shouldBlockFinishedEdit(event)) {
                    event.preventDefault();
                    this.setFeedback("결과를 확인한 뒤 Space를 누르면 다음 예제가 열립니다.");
                }
                return;
            }

            if (
                this.state.status === "running"
                && !this.isAssessment()
                && this.isFinishKey(event)
                && this.hasReachedTargetEnd()
            ) {
                event.preventDefault();
                this.finish(this.state.input === this.currentSample().content ? "completed" : "submitted");
                return;
            }

            if (event.key !== "Tab" || (this.state.status !== "running" && this.state.status !== "idle")) {
                return;
            }

            event.preventDefault();
            const input = this.fields.input;
            const start = input.selectionStart;
            const end = input.selectionEnd;
            const spaces = "    ";
            input.value = `${input.value.slice(0, start)}${spaces}${input.value.slice(end)}`;
            input.selectionStart = start + spaces.length;
            input.selectionEnd = start + spaces.length;
            this.handleInput();
        }

        prepareNextRound() {
            const finishedSample = this.currentSample();
            this.clearTimer();
            this.activeSample = this.isAssessment() ? this.selectedSample() : this.nextPracticeSample(finishedSample.id);
            this.state.status = "idle";
            this.state.input = "";
            this.state.startedAt = 0;
            this.state.durationSeconds = 0;
            this.state.score = 0;
            this.state.myRank = null;
            this.state.finishReason = "";
            this.state.timeLimitSeconds = this.sessionTimeLimit();
            this.resetAssessmentSession();
            this.fields.input.value = "";
            this.fields.input.disabled = false;
            this.updateShellState();
            this.renderAll();
        }

        isNextRoundKey(event) {
            return !event.ctrlKey
                && !event.metaKey
                && !event.altKey
                && (event.key === " " || event.key === "Spacebar");
        }

        shouldBlockFinishedEdit(event) {
            return !event.ctrlKey
                && !event.metaKey
                && !event.altKey
                && (event.key.length === 1
                    || event.key === "Enter"
                    || event.key === "Tab"
                    || event.key === "Backspace"
                    || event.key === "Delete");
        }

        isFinishKey(event) {
            return event.key === "Enter" || event.key === " " || event.key === "Spacebar";
        }

        hasReachedTargetEnd() {
            return this.state.input.length >= this.currentSample().content.length;
        }

        nextPracticeSample(previousId) {
            if (this.isKeywordMode()) {
                return this.buildKeywordSample();
            }

            const samples = this.filteredSamples();
            const candidates = samples.filter((sample) => sample.id !== previousId);
            const next = randomItem(candidates.length ? candidates : samples) || this.selectedSample();
            this.state.sampleId = next.id;
            this.fields.sample.value = next.id;

            return next;
        }

        async finish(reason = "completed") {
            this.clearTimer();
            const metrics = this.metrics();
            this.state.status = "finished";
            this.state.finishReason = reason;
            this.state.durationSeconds = metrics.elapsedSeconds;
            this.state.score = metrics.score;
            this.fields.input.disabled = false;
            this.bestScore = Math.max(this.bestScore, metrics.score);
            try { window.localStorage.setItem(this.storageKey(), String(this.bestScore)); } catch { /* Storage can be disabled; the server record is unaffected. */ }
            this.recordChallenge(metrics);
            if (!this.isAssessment()) {
                this.practiceRound += 1;
            }
            this.updateShellState();
            this.renderAll();

            if (this.isAssessment()) {
                if (this.sessionRefreshPromise) {
                    await this.sessionRefreshPromise;
                }

                this.saveAssessmentScore(metrics);
                return;
            }

            this.setFeedback(`${this.practiceRound}회 완료했습니다. 점수 ${metrics.score}점, 정확도 ${metrics.accuracy.toFixed(1)}%, 속도 ${Math.round(metrics.cpm)} CPM입니다. Space를 누르면 입력 내용이 지워지고 다음 예제가 열립니다.`);
        }

        async refreshSessionToken() {
            if (!this.config.restUrl || !this.config.isLoggedIn) {
                return;
            }

            try {
                const headers = {};
                if (this.config.nonce) {
                    headers["X-WP-Nonce"] = this.config.nonce;
                }

                const response = await window.fetch(`${this.config.restUrl}/games/${this.config.gameId || "code-typing"}/session`, {
                    method: "POST",
                    credentials: "same-origin",
                    headers,
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json().catch(() => ({}));
                if (data && typeof data.sessionToken === "string" && data.sessionToken) {
                    this.config.sessionToken = data.sessionToken;
                }
            } catch (error) {
                // Best effort — keep whatever token we already have. The
                // score save may then be rejected, same as before this fix.
            }
        }

        clearTimer() {
            if (this.timer) {
                window.clearInterval(this.timer);
                this.timer = 0;
            }
        }

        updateShellState() {
            const shell = this.root.querySelector(".oj-typing-shell");

            shell.classList.toggle("is-running", this.state.status === "running");
            shell.classList.toggle("is-finished", this.state.status === "finished");
        }

        renderAll() {
            this.renderSampleInfo();
            this.renderStream();
            this.renderStats();
        }

        renderSampleInfo() {
            const sample = this.currentSample();
            const language = this.rules.languages[this.state.language] || {};
            const mode = this.rules.modes[this.state.mode] || {};
            const recordLabel = this.isAssessment()
                ? `기록 저장 · 총 ${this.formatTime(this.sessionTimeLimit())} · 다 쓰면 이어서 다음 코드`
                : (this.isKeywordMode() ? "랜덤 반복" : "입력 시 다음 예제");

            this.fields.sampleTitle.textContent = sample.title || "";
            const output = this.root.querySelector('[data-field="expected-output"]');
            if (output) {
                output.hidden = this.state.status !== 'finished' || typeof sample.expectedOutput !== 'string';
                output.querySelector('pre').textContent = sample.expectedOutput || '';
            }
            this.fields.sampleMeta.textContent = `${sample.languageVersion || language.label || this.state.language} · ${sample.sourceKind || '단어 목록'} · ${mode.label || this.state.mode} · ${recordLabel}`;
        }

        // Splits the current sample into per-line index ranges (each line's
        // trailing "\n" is included in its range, like a real editor row)
        // so the stream can render/compare one line at a time instead of one
        // long block — this is what makes long code scroll like a typing
        // tutor instead of needing its own big scrollbar.
        currentSampleLines() {
            const parts = this.currentSample().content.split("\n");
            const lines = [];
            let offset = 0;

            parts.forEach((text, index) => {
                const hasNewline = index < parts.length - 1;
                const length = text.length + (hasNewline ? 1 : 0);
                lines.push({ text, start: offset, end: offset + length });
                offset += length;
            });

            return lines;
        }

        // Moves any line of the CURRENT sample that's now fully typed out of
        // "in progress" and into lineLog (permanent, cross-sample history).
        // Idempotent — safe to call on every render.
        syncLineLog() {
            const lines = this.currentSampleLines();

            while (
                this.loggedLineCount < lines.length
                && lines[this.loggedLineCount].end <= this.state.input.length
            ) {
                const line = lines[this.loggedLineCount];
                this.lineLog.push({
                    target: line.text,
                    typed: this.state.input.slice(line.start, line.start + line.text.length),
                });
                this.loggedLineCount += 1;
            }
        }

        renderStream() {
            if (!this.fields.stream) {
                return;
            }

            this.syncLineLog();

            const lines = this.currentSampleLines();
            const currentIndex = Math.min(this.loggedLineCount, Math.max(0, lines.length - 1));
            const currentLine = lines[currentIndex] || null;
            const typedForCurrent = currentLine ? this.state.input.slice(currentLine.start) : "";
            const upcoming = lines.slice(currentIndex + 1, currentIndex + 5);

            let html = "";
            const historyStart = Math.max(0, this.lineLog.length - 30);
            for (let index = historyStart; index < this.lineLog.length; index += 1) {
                html += this.renderLineSlot(this.lineLog[index].target, this.lineLog[index].typed, "is-done");
            }

            if (currentLine) {
                html += this.renderLineSlot(currentLine.text, typedForCurrent, "is-current");
            }

            upcoming.forEach((line) => {
                html += this.renderLineSlot(line.text, "", "is-upcoming");
            });

            this.fields.stream.innerHTML = html;

            const currentElement = this.fields.stream.querySelector(".oj-typing-line-slot.is-current");
            if (currentElement) {
                currentElement.scrollIntoView({ block: "center", behavior: "smooth" });
            }

            this.highlightNextKey();
        }

        renderLineSlot(targetText, typedText, stateClass) {
            const showCursor = stateClass === "is-current" && this.state.status !== "finished";
            const targetHtml = this.renderLineTargetChars(targetText, typedText, showCursor);
            const rows = [`<div class="oj-typing-line-target">${targetHtml || "&nbsp;"}</div>`];

            if (stateClass !== "is-upcoming") {
                const typedHtml = this.renderLineTypedChars(targetText, typedText);
                rows.push(`<div class="oj-typing-line-typed">${typedHtml || "&nbsp;"}</div>`);
            }

            return `<div class="oj-typing-line-slot ${stateClass}">${rows.join("")}</div>`;
        }

        renderLineTargetChars(targetText, typedText, showCursor) {
            let html = "";

            for (let index = 0; index < targetText.length; index += 1) {
                const expected = targetText[index];
                const classes = ["oj-typing-char", this.charKindClass(expected)];

                if (index < typedText.length) {
                    classes.push(typedText[index] === expected ? "is-correct" : "is-wrong");
                } else if (showCursor && index === typedText.length) {
                    classes.push("is-current");
                } else {
                    classes.push("is-pending");
                }

                html += `<span class="${classes.filter(Boolean).join(" ")}">${this.renderChar(expected)}</span>`;
            }

            return html;
        }

        renderLineTypedChars(targetText, typedText) {
            let html = "";

            for (let index = 0; index < typedText.length; index += 1) {
                const typedChar = typedText[index];
                const expected = index < targetText.length ? targetText[index] : undefined;
                const classes = ["oj-typing-char", this.charKindClass(typedChar)];

                classes.push(typedChar === expected ? "is-correct" : "is-wrong");
                if (expected === undefined) {
                    classes.push("is-extra");
                }

                html += `<span class="${classes.filter(Boolean).join(" ")}">${this.renderChar(typedChar)}</span>`;
            }

            return html;
        }

        buildKeyboardMarkup() {
            return KEYBOARD_ROWS.map((row) => `
                <div class="oj-typing-key-row">
                    ${row.map((key) => this.buildKeyMarkup(key)).join("")}
                </div>
            `).join("");
        }

        buildKeyMarkup(key) {
            if (key.special) {
                const classes = ["oj-typing-key", "oj-typing-key-special", `oj-typing-key-${key.special}`];
                if (key.wide) {
                    classes.push("oj-typing-key-wide");
                }
                if (key.extraWide) {
                    classes.push("oj-typing-key-extra-wide");
                }

                return `<span class="${classes.join(" ")}" data-special-key="${key.special}">${escapeHtml(key.label)}</span>`;
            }

            return `
                <span class="oj-typing-key" data-base-key="${escapeHtml(key.base)}" data-shift-key="${escapeHtml(key.shift)}">
                    <span class="oj-typing-key-shift">${escapeHtml(key.shift)}</span>
                    <span class="oj-typing-key-base">${escapeHtml(key.base)}</span>
                </span>
            `;
        }

        highlightNextKey() {
            if (!this.fields.keyboard) {
                return;
            }

            this.fields.keyboard.querySelectorAll(".is-next, .is-next-shift").forEach((element) => {
                element.classList.remove("is-next", "is-next-shift");
            });

            if (this.state.status === "finished") {
                return;
            }

            const nextChar = this.currentSample().content[this.state.input.length];
            if (nextChar === undefined) {
                return;
            }

            if (nextChar === " ") {
                this.markKey('[data-special-key="space"]');
                return;
            }

            if (nextChar === "\n") {
                this.markKey('[data-special-key="enter"]');
                return;
            }

            if (nextChar === "\t") {
                this.markKey('[data-special-key="tab"]');
                return;
            }

            const baseMatch = this.fields.keyboard.querySelector(`[data-base-key="${this.cssEscape(nextChar)}"]`);
            if (baseMatch) {
                baseMatch.classList.add("is-next");
                return;
            }

            const shiftMatch = this.fields.keyboard.querySelector(`[data-shift-key="${this.cssEscape(nextChar)}"]`);
            if (shiftMatch) {
                shiftMatch.classList.add("is-next", "is-next-shift");
                this.markKey('[data-special-key="shift"]', "is-next-shift");
            }
        }

        markKey(selector, className = "is-next") {
            this.fields.keyboard.querySelectorAll(selector).forEach((element) => element.classList.add(className));
        }

        cssEscape(value) {
            return window.CSS && window.CSS.escape ? window.CSS.escape(value) : value.replace(/["\\]/g, "\\$&");
        }

        renderChar(char) {
            if (char === " ") {
                return "·";
            }

            if (char === "\t") {
                return "⇥";
            }

            if (char === "\n") {
                return "\n";
            }

            return escapeHtml(char);
        }

        charKindClass(char) {
            if (char === " ") {
                return "is-space";
            }

            if (char === "\t") {
                return "is-tab";
            }

            if (char === "\n") {
                return "is-newline";
            }

            return "";
        }

        renderStats() {
            const metrics = this.metrics();
            this.renderChallenge();
            this.fields.progress.textContent = `${metrics.progress.toFixed(0)}%`;
            this.fields.accuracy.textContent = `${metrics.accuracy.toFixed(1)}%`;
            this.fields.speed.textContent = `${Math.round(metrics.cpm)} CPM`;
            this.fields.mistakes.textContent = String(metrics.mistakes);
            this.fields.score.textContent = String(metrics.score);
            this.fields.rank.textContent = this.state.myRank ? `${this.state.myRank}위` : "-";
            if (this.fields.samples) {
                this.fields.samples.textContent = this.isAssessment()
                    ? String(metrics.samplesCompleted || 0)
                    : String(this.practiceRound);
            }

            if (this.isAssessment()) {
                const limit = this.sessionTimeLimit();
                const remaining = this.state.status === "running" ? this.remainingSeconds() : Math.max(0, limit - metrics.elapsedSeconds);
                this.fields.time.textContent = this.state.status === "running"
                    ? this.formatTime(remaining)
                    : `${this.formatTime(metrics.elapsedSeconds)} / ${this.formatTime(limit)}`;
                this.fields.time.classList.toggle("is-warning", this.state.status === "running" && remaining <= 30);
                return;
            }

            this.fields.time.textContent = this.formatTime(metrics.elapsedSeconds);
            this.fields.time.classList.remove("is-warning");
        }

        currentSampleStats() {
            const target = this.currentSample().content;
            const input = this.state.input;
            const compareLength = Math.min(input.length, target.length);
            let correct = 0;
            let mistakes = Math.max(0, input.length - target.length);

            for (let index = 0; index < compareLength; index += 1) {
                if (input[index] === target[index]) {
                    correct += 1;
                } else {
                    mistakes += 1;
                }
            }

            return { typedLength: input.length, correct, mistakes, targetLength: target.length };
        }

        challengeKey() {
            return `${this.state.language}:${this.state.mode}:${this.isAssessment() ? this.sessionTimeLimit() : this.isKeywordMode() ? 'streak' : this.currentSample().id}`;
        }

        recordChallenge(metrics) {
            const key = this.challengeKey();
            if (this.isKeywordMode()) {
                if (this.keywordStreakLanguage !== this.state.language) this.keywordStreak = 0;
                this.keywordStreakLanguage = this.state.language;
                this.keywordStreak = metrics.accuracy === 100 && metrics.progress === 100 ? this.keywordStreak + 1 : 0;
                this.speedRecords[key] = Math.max(Number(this.speedRecords[key]) || 0, this.keywordStreak);
            } else if (metrics.accuracy >= 97 && metrics.progress === 100 && metrics.typedLength >= Math.min(50, this.currentSample().content.length)) {
                this.speedRecords[key] = Math.max(Number(this.speedRecords[key]) || 0, Math.round(metrics.cpm));
            }
            try { localStorage.setItem(this.challengeStorageKey, JSON.stringify(this.speedRecords)); } catch { /* Local training goal, separate from server assessment records. */ }
            if (!this.isAssessment() && metrics.progress === 100 && metrics.accuracy >= (this.isKeywordMode() ? 100 : 97)) {
                this.account?.save({ key, sampleId: this.currentSample().id, input: this.state.input, target: this.currentSample().content, seconds: Math.max(1,this.effectiveElapsedSeconds()), streak: this.keywordStreak });
            }
        }

        renderChallenge() {
            const field = this.root.querySelector('[data-field="challenge-goal"]');
            if (!field) return;
            const best = Number(this.speedRecords[this.challengeKey()]) || 0;
            if (this.isKeywordMode()) {
                const current = this.keywordStreakLanguage === this.state.language ? this.keywordStreak : 0;
                field.textContent = `${this.account?.synced ? '계정' : '이 기기'} 정확도 100% 연속 ${current}개 · 최고 ${best}개 · 다음 목표 ${Math.max(5, Math.ceil((best + 1) / 5) * 5)}개`;
            } else {
                const ceiling = this.isAssessment() ? Infinity : this.currentSample().content.length * 60;
                const next = Math.min(ceiling, Math.max(60, Math.ceil((best + 1) / 20) * 20));
                const target = best >= ceiling ? '측정 상한 달성 · 다른 예제 도전' : `다음 목표 ${next} CPM`;
                field.textContent = `정확도 97% 이상 · ${this.account?.synced ? '계정' : '이 기기'} 최고 ${best || '-'} CPM · ${target} · ${this.isAssessment() ? '같은 언어·제한시간' : '같은 예제'} 기준`;
            }
        }

        metrics() {
            const elapsedSeconds = this.effectiveElapsedSeconds();

            return this.isAssessment() ? this.assessmentMetrics(elapsedSeconds) : this.practiceMetrics(elapsedSeconds);
        }

        practiceMetrics(elapsedSeconds) {
            const current = this.currentSampleStats();
            const completed = this.state.input === this.currentSample().content;
            const progress = current.targetLength ? Math.min(100, (current.typedLength / current.targetLength) * 100) : 0;
            const accuracy = current.typedLength ? (current.correct / current.typedLength) * 100 : 100;
            const minutes = Math.max(elapsedSeconds / 60, 1 / 60);
            const cpm = current.correct / minutes;
            const wpm = cpm / 5;
            const score = this.scoreFor({
                targetLength: current.targetLength,
                accuracy,
                cpm,
                mistakes: current.mistakes,
                progress,
                completed,
            });

            return {
                progress,
                accuracy,
                cpm,
                wpm,
                mistakes: current.mistakes,
                correct: current.correct,
                score,
                elapsedSeconds,
                completed,
                typedLength: current.typedLength,
            };
        }

        // Assessment metrics combine every sample chained through this
        // session (this.session, filled in by recordCompletedSample) with
        // the partial sample still in progress — the fixed clock is the
        // only thing that ever ends the run, so a single sample's "how much
        // of it is done" no longer means anything at the session level.
        assessmentMetrics(elapsedSeconds) {
            const current = this.currentSampleStats();
            const correct = this.session.totalCorrect + current.correct;
            const mistakes = this.session.totalMistakes + current.mistakes;
            const typedLength = this.session.totalTyped + current.typedLength;
            const limit = this.sessionTimeLimit();
            const progress = limit ? Math.min(100, (elapsedSeconds / limit) * 100) : 0;
            const accuracy = typedLength ? (correct / typedLength) * 100 : 100;
            const minutes = Math.max(elapsedSeconds / 60, 1 / 60);
            const cpm = correct / minutes;
            const wpm = cpm / 5;
            const score = this.scoreForAssessment({ correct, accuracy, cpm, mistakes });

            return {
                progress,
                accuracy,
                cpm,
                wpm,
                mistakes,
                correct,
                score,
                elapsedSeconds,
                completed: this.state.status === "finished",
                typedLength,
                samplesCompleted: this.session.samplesCompleted,
            };
        }

        effectiveElapsedSeconds() {
            let elapsed = this.state.durationSeconds;

            if (this.state.status === "running" && this.state.startedAt > 0) {
                elapsed = Math.max(0, Math.round((performance.now() - this.state.startedAt) / 1000));
            }

            if (this.isAssessment()) {
                const limit = this.sessionTimeLimit();
                return limit ? Math.min(elapsed, limit) : elapsed;
            }

            return elapsed;
        }

        remainingSeconds() {
            const limit = this.sessionTimeLimit();

            if (!limit) {
                return 0;
            }

            return Math.max(0, limit - this.effectiveElapsedSeconds());
        }

        scoreFor(metrics) {
            const mode = this.rules.modes[this.state.mode] || {};
            const scoring = this.rules.scoring;
            const raw = (Number(mode.baseScore) || 80)
                + (metrics.targetLength * scoring.characterScore)
                + (metrics.accuracy * scoring.accuracyBonus)
                + (metrics.cpm * scoring.speedBonus)
                - (metrics.mistakes * scoring.mistakePenalty);
            const shouldScale = this.state.status === "running" || !metrics.completed;
            const scaled = shouldScale ? raw * (metrics.progress / 100) : raw;

            return Math.max(scoring.minimumScore, Math.round(scaled));
        }

        // No progress-based scaling here: an assessment session's score is
        // simply built from everything actually typed across the whole
        // chained run (this.session totals + the in-progress sample), since
        // "how much of the CURRENT sample is done" isn't meaningful at the
        // session level once samples auto-chain.
        scoreForAssessment(metrics) {
            const mode = this.rules.modes[this.state.mode] || {};
            const scoring = this.rules.scoring;
            const raw = (Number(mode.baseScore) || 80)
                + (metrics.correct * scoring.characterScore)
                + (metrics.accuracy * scoring.accuracyBonus)
                + (metrics.cpm * scoring.speedBonus)
                - (metrics.mistakes * scoring.mistakePenalty);

            return Math.max(scoring.minimumScore, Math.round(raw));
        }

        // Fixed session duration for assessment mode, independent of which
        // sample is currently active — see advanceAssessmentSample.
        sessionTimeLimit() {
            const mode = this.rules.modes[this.state.mode] || {};

            return Math.max(0, Number(mode.timeLimitSeconds) || 0);
        }

        isKeywordMode() {
            return this.state.mode === "keyword";
        }

        isAssessment() {
            const mode = this.rules.modes[this.state.mode] || {};

            return Boolean(mode.records);
        }

        async saveAssessmentScore(metrics) {
            const sample = this.currentSample();
            const expired = this.state.finishReason === "expired";
            const timeLimitSeconds = this.sessionTimeLimit();
            const samplesCompleted = metrics.samplesCompleted || 0;

            if (!this.config.isLoggedIn) {
                this.setFeedback(expired
                    ? "제한시간이 끝났습니다. 로그인하면 작성한 부분까지의 점수와 순위가 서버에 기록됩니다. Space를 누르면 입력 내용이 지워지고 다시 준비됩니다."
                    : "검정이 종료되었습니다. 로그인하면 점수와 순위가 서버에 기록됩니다. Space를 누르면 입력 내용이 지워지고 다시 준비됩니다.");
                return;
            }

            if (!this.config.restUrl || this.state.isSaving) {
                return;
            }

            this.state.isSaving = true;
            this.setFeedback(expired ? "시간 종료 기록을 저장하는 중입니다." : "검정 기록을 저장하는 중입니다.");

            const payload = {
                score: metrics.score,
                roundsPlayed: samplesCompleted + 1,
                correctCount: samplesCompleted,
                durationSeconds: metrics.elapsedSeconds,
                accuracy: Math.round(metrics.accuracy * 100) / 100,
                meta: {
                    language: this.state.language,
                    mode: this.state.mode,
                    sampleId: sample.id,
                    sampleTitle: sample.title,
                    samplesCompleted,
                    typedCharacters: metrics.typedLength,
                    mistakes: metrics.mistakes,
                    finishReason: this.state.finishReason,
                    timeLimitSeconds,
                    cpm: Math.round(metrics.cpm * 10) / 10,
                    wpm: Math.round(metrics.wpm * 10) / 10,
                },
            };

            try {
                const headers = {
                    "Content-Type": "application/json",
                };
                if (this.config.nonce) {
                    headers["X-WP-Nonce"] = this.config.nonce;
                }

                const response = await window.fetch(`${this.config.restUrl}/games/${this.config.gameId || "code-typing"}/scores`, {
                    method: "POST",
                    credentials: "same-origin",
                    headers,
                    body: JSON.stringify({ ...payload, sessionToken: this.config.sessionToken }),
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(data && data.message ? data.message : "검정 기록을 저장하지 못했습니다.");
                }

                this.state.myRank = data.rank || null;
                if (metrics.progress === 100 && metrics.accuracy >= 97 && data.score?.id) this.account?.save({ scoreId: Number(data.score.id) });
                else this.account?.read();
                await this.loadLeaderboard();
                this.renderStats();
                this.renderLeaderboard();
                this.setFeedback(this.state.myRank
                    ? `검정 기록이 저장되었습니다. 현재 ${this.state.myRank}위입니다. Space를 누르면 입력 내용이 지워지고 다시 준비됩니다.`
                    : "검정 기록이 저장되었습니다. Space를 누르면 입력 내용이 지워지고 다시 준비됩니다.");
            } catch (error) {
                this.setFeedback(error.message || "검정 기록 저장에 실패했습니다.");
            } finally {
                this.state.isSaving = false;
            }
        }

        async loadLeaderboard() {
            if (!this.config.restUrl || !this.fields.leaderboard) {
                return;
            }

            this.setFieldText("rankStatus", "불러오는 중");

            const key = this.config.gameId;
            const pager = window.EduGamesRankPager.request(this.fields.leaderboard, key, () => this.loadLeaderboard());
            try {
                const response = await window.fetch(`${this.config.restUrl}/leaderboards/game/${this.config.gameId || "code-typing"}?page=${pager.page}&perPage=${pager.perPage}`, {
                    credentials: "same-origin",
                    headers: this.config.nonce ? { "X-WP-Nonce": this.config.nonce } : {},
                });
                if (!response.ok) {
                    throw new Error("leaderboard failed");
                }
                const data = await response.json();
                if (!window.EduGamesRankPager.accept(this.fields.leaderboard, key, data, pager.ticket)) return;
                this.leaderboard = Array.isArray(data.leaderboard) ? data.leaderboard : [];
                if (!this.state.myRank && data.myRank) {
                    this.state.myRank = data.myRank;
                    this.renderStats();
                }
                this.renderLeaderboard();
            } catch (error) {
                window.EduGamesRankPager.fail(this.fields.leaderboard, pager.ticket);
            }
        }

        renderLeaderboard() {
            if (!this.fields.leaderboard) {
                return;
            }

            if (!this.leaderboard.length) {
                this.setFieldText("rankStatus", "기록 없음");
                this.fields.leaderboard.innerHTML = '<div class="oj-typing-rank-empty">아직 기록된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.setFieldText("rankStatus", this.state.myRank ? `${this.state.myRank}위` : "전체 참여자");
            this.fields.leaderboard.innerHTML = `
                <div class="oj-typing-rank-row oj-typing-rank-columns">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.leaderboard.map((row) => `
                    <div data-rank-row class="oj-typing-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
                        <strong>${Number(row.rank) || "-"}</strong>
                        <span>${escapeHtml(row.playerName || "플레이어")}</span>
                        <b>${Number(row.score) || 0}</b>
                    </div>
                `).join("")}
            `;
            window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
        }

        setFieldText(name, value) {
            if (this.fields[name]) {
                this.fields[name].textContent = String(value);
            }
        }

        setFeedback(message) {
            this.fields.feedback.textContent = message;
        }

        formatTime(totalSeconds) {
            const seconds = Math.max(0, Number(totalSeconds) || 0);
            const minutes = Math.floor(seconds / 60);
            const remainder = seconds % 60;

            return `${minutes}:${String(remainder).padStart(2, "0")}`;
        }
    }

    const init = () => {
        document.querySelectorAll("[data-code-typing]").forEach((root) => {
            if (!root.__CodeTyping) {
                root.__CodeTyping = new CodeTypingGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
