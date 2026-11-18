(() => {
    const STORAGE_KEY = "edu-games-character-code-best";

    const escapeHtml = (value) => String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    const labelFor = (symbol) => symbol === " " ? "공백" : symbol;
    const normalizePlainText = (value) => String(value || "").toUpperCase().replace(/[^A-Z0-9 ]/g, "").replace(/\s+/g, " ").trim();
    const normalizeCode = (value) => /^\d{1,2}$/.test(String(value).trim()) ? String(value).trim().padStart(2, "0") : String(value).trim();
    const orderedSymbols = [..."ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789", " "];
    const orderedTable = Object.fromEntries(orderedSymbols.map((symbol, index) => [symbol, symbol === " " ? "00" : String(index + 1).padStart(2, "0")]));

    class CharacterCodeGame {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.rules = this.normalizeRules(this.config.rules || {});
            this.modeKeys = Object.keys(this.rules.modes);
            this.symbols = this.rules.symbols;
            this.fields = {
                difficulty: root.querySelector('[data-field="difficulty"]'),
                score: root.querySelector('[data-field="score"]'),
                round: root.querySelector('[data-field="round"]'),
                progressLabel: root.querySelector('[data-field="progress-label"]'),
                streak: root.querySelector('[data-field="streak"]'),
                best: root.querySelector('[data-field="best"]'),
                modeLabel: root.querySelector('[data-field="mode-label"]'),
                difficultyLabel: root.querySelector('[data-field="difficulty-label"]'),
                prompt: root.querySelector('[data-field="prompt"]'),
                answer: root.querySelector('[data-field="answer"]'),
                feedback: root.querySelector('[data-field="feedback"]'),
                codeTable: root.querySelector('[data-field="code-table"]'),
                tableCount: root.querySelector('[data-field="table-count"]'),
                practiceModes: root.querySelector('[data-field="practice-modes"]'),
                leaderboard: root.querySelector('[data-field="leaderboard"]'),
                rankStatus: root.querySelector('[data-field="rank-status"]'),
                submitButton: root.querySelector('[data-action="submit"]'),
                submitLabel: root.querySelector('[data-field="submit-label"]'),
                scoreBurst: root.querySelector('[data-field="score-burst"]'),
                scoreDetail: root.querySelector('[data-field="score-detail"]'),
                timeTrack: root.querySelector('[data-field="time-track"]'),
                timeFill: root.querySelector('[data-field="time-fill"]'),
            };
            this.personalStorageKey = `${STORAGE_KEY}:${this.config.currentUserId || 0}`;
            this.bestScore = 0;
            try { this.bestScore = Number(window.localStorage.getItem(this.personalStorageKey)) || 0; } catch { /* Storage can be disabled (private mode, blocked site data). */ }
            this.nextRoundTimer = 0;
            this.countdownTimer = 0;
            this.sessionRefreshPromise = null;
            this.state = {
                playType: "practice",
                status: "practice",
                practiceMode: this.config.defaultMode || this.rules.defaultMode,
                currentMode: this.config.defaultMode || this.rules.defaultMode,
                difficulty: this.config.defaultDifficulty || this.rules.defaultDifficulty,
                score: 0,
                round: 0,
                timeLimitSeconds: 0,
                timeLeftSeconds: 0,
                streak: 0,
                hintCount: 0,
                solved: false,
                table: {},
                challenge: null,
                correctCount: 0,
                mistakes: 0,
                currentMistakes: 0,
                startedAt: 0,
                roundStartedAt: 0,
                durationSeconds: 0,
                endReason: "",
                modeHistory: [],
                leaderboard: [],
                myRank: null,
                isSaving: false,
                isFinishing: false,
                lastAnswer: "",
            };

            this.bindEvents();
            this.newPracticeRound(false);
            this.loadLeaderboard();
            this.account = window.EduGamesProgress?.connect(this.config, this.root, data => { this.bestScore = Number(data.bestScore) || 0; this.renderStatsOnly(); });
        }

        readConfig() {
            try {
                return JSON.parse(this.root.dataset.config || "{}");
            } catch (error) {
                return {};
            }
        }

        normalizeRules(rules) {
            const modes = rules.modes && Object.keys(rules.modes).length
                ? rules.modes
                : { decode: { label: "복호화", baseScore: 90 } };
            const difficulties = rules.difficulties && Object.keys(rules.difficulties).length
                ? rules.difficulties
                : { easy: { label: "쉬움", multiplier: 1, roundTimeSeconds: 24, minimumRoundTimeSeconds: 12, timeDecreasePerStep: 2, correctAnswersPerStep: 3, phrases: ["CODE"] } };
            const symbols = Array.isArray(rules.symbols) && rules.symbols.length
                ? rules.symbols
                : [..."ABCDEFGHIJKLMNOPQRSTUVWXYZ".split(""), " ", ..."0123456789".split("")];

            return {
                symbols,
                codeTable: rules.codeTable || orderedTable,
                phraseMetadata: rules.phraseMetadata || {},
                modes,
                difficulties,
                defaultMode: rules.defaultMode && modes[rules.defaultMode] ? rules.defaultMode : Object.keys(modes)[0],
                defaultDifficulty: rules.defaultDifficulty && difficulties[rules.defaultDifficulty]
                    ? rules.defaultDifficulty
                    : Object.keys(difficulties)[0],
                codeRange: {
                    min: Number(rules.codeRange?.min ?? 0),
                    max: Number(rules.codeRange?.max ?? 36),
                },
                scoring: {
                    lengthBonusPerCharacter: Number(rules.scoring && rules.scoring.lengthBonusPerCharacter) || 4,
                    streakBonusPerAnswer: Number(rules.scoring && rules.scoring.streakBonusPerAnswer) || 8,
                    maxStreakBonus: Number(rules.scoring && rules.scoring.maxStreakBonus) || 60,
                    rankedMistakePenalty: Number(rules.scoring && rules.scoring.rankedMistakePenalty) || 20,
                    practiceMistakePenalty: Number(rules.scoring && rules.scoring.practiceMistakePenalty) || 8,
                    hintPenalty: Number(rules.scoring && rules.scoring.hintPenalty) || 10,
                    minimumRoundScore: Number(rules.scoring && rules.scoring.minimumRoundScore) || 20,
                },
            };
        }

        bindEvents() {
            this.root.addEventListener("click", (event) => {
                const typeButton = event.target.closest("[data-play-type]");
                if (typeButton) {
                    this.switchPlayType(typeButton.dataset.playType);
                    return;
                }

                const modeButton = event.target.closest("[data-mode]");
                if (modeButton && this.state.playType === "practice") {
                    this.state.practiceMode = modeButton.dataset.mode;
                    this.newPracticeRound();
                    return;
                }

                const actionButton = event.target.closest("[data-action]");
                if (!actionButton) {
                    return;
                }

                if (actionButton.dataset.action === "new-round") {
                    this.newPracticeRound();
                }

                if (actionButton.dataset.action === "start-ranked") {
                    this.startRankedGame();
                }

                if (actionButton.dataset.action === "submit") {
                    this.submit();
                }

                if (actionButton.dataset.action === "hint") {
                    this.hint();
                }
            });

            this.fields.difficulty.addEventListener("change", () => {
                this.state.difficulty = this.fields.difficulty.value;
                if (this.state.playType === "practice") {
                    this.newPracticeRound();
                    return;
                }

                this.switchPlayType("ranked");
            });

            this.root.addEventListener("keydown", (event) => {
                if (event.isComposing || event.key !== "Enter") return;
                const input = event.target.closest('[data-rebuild-index]');
                if (input && !event.ctrlKey && !event.metaKey) {
                    const next = this.root.querySelector(`[data-rebuild-index="${Number(input.dataset.rebuildIndex) + 1}"]`);
                    if (next) { event.preventDefault(); next.focus(); return; }
                }
                if (event.ctrlKey || event.metaKey || (!event.shiftKey && event.target.matches('[data-answer-input],[data-rebuild-index]'))) {
                    event.preventDefault();
                    this.submit();
                }
            });
            this.root.addEventListener("input", (event) => {
                if (event.target.matches('[data-answer-input],[data-rebuild-index]')) this.updateInputFeedback();
            });
        }

        switchPlayType(playType) {
            this.clearAdvanceTimer();
            this.clearCountdownTimer();

            if (playType === "practice") {
                this.newPracticeRound();
                return;
            }

            this.state.playType = "ranked";
            this.state.status = "ranked-idle";
            this.state.score = 0;
            this.state.round = 0;
            this.state.streak = 0;
            this.state.hintCount = 0;
            this.state.solved = false;
            this.state.challenge = null;
            this.state.correctCount = 0;
            this.state.mistakes = 0;
            this.state.currentMistakes = 0;
            this.state.timeLimitSeconds = this.currentRoundTimeLimit();
            this.state.timeLeftSeconds = this.state.timeLimitSeconds;
            this.state.startedAt = 0;
            this.state.roundStartedAt = 0;
            this.state.durationSeconds = 0;
            this.state.endReason = "";
            this.state.modeHistory = [];
            this.render();
            this.setFeedback("정답이면 시간 충전 · 오답·시간 초과로 종료");
        }

        newPracticeRound(focus = true) {
            const wasPractice = this.state.playType === "practice";
            this.clearAdvanceTimer();
            this.clearCountdownTimer();
            this.state.playType = "practice";
            this.state.status = "practice";
            this.state.round = wasPractice ? this.state.round + 1 : 1;
            this.state.timeLimitSeconds = 0;
            this.state.timeLeftSeconds = 0;
            this.state.hintCount = 0;
            this.state.currentMistakes = 0;
            this.state.endReason = "";
            this.state.solved = false;
            this.state.lastAnswer = "";
            this.state.currentMode = this.state.practiceMode;
            this.state.table = this.generateCodeTable();
            this.state.challenge = this.createChallenge();
            this.render();
            this.setFeedback("연습 · 순위 미반영");
            if (focus) this.focusAnswer();
        }

        startRankedGame() {
            if (this.state.isFinishing || this.state.isSaving) return;
            this.clearAdvanceTimer();
            this.clearCountdownTimer();
            this.state.playType = "ranked";
            this.state.status = "playing";
            this.state.score = 0;
            this.state.round = 1;
            this.state.streak = 0;
            this.state.hintCount = 0;
            this.state.solved = false;
            this.state.correctCount = 0;
            this.state.mistakes = 0;
            this.state.currentMistakes = 0;
            this.state.timeLimitSeconds = this.currentRoundTimeLimit();
            this.state.timeLeftSeconds = this.state.timeLimitSeconds;
            this.state.durationSeconds = 0;
            this.state.startedAt = performance.now();
            this.state.roundStartedAt = 0;
            this.state.endReason = "";
            this.state.modeHistory = [];
            // The token embedded in the page's initial config is single-use;
            // replaying ranked mode without a page reload needs a fresh one
            // or the eventual score save is rejected no matter how high the
            // score is. Kick this off now (fire-and-forget) so it's ready by
            // the time finishRankedGame() actually needs it.
            this.sessionRefreshPromise = this.refreshSessionToken();
            this.prepareRankedRound();
            this.startCountdownTimer();
            this.setFeedback("기록 도전 시작");
        }

        prepareRankedRound() {
            this.state.currentMode = this.modeKeys[Math.floor(Math.random() * this.modeKeys.length)];
            this.state.modeHistory.push(this.state.currentMode);
            this.state.table = this.generateCodeTable();
            this.state.challenge = this.createChallenge();
            this.state.timeLimitSeconds = this.currentRoundTimeLimit();
            this.state.timeLeftSeconds = this.state.timeLimitSeconds;
            this.state.roundStartedAt = performance.now();
            this.state.hintCount = 0;
            this.state.currentMistakes = 0;
            this.state.solved = false;
            this.state.lastAnswer = "";
            this.render();
            this.focusAnswer();
        }

        advanceRankedRound() {
            if (this.state.status !== "playing") {
                return;
            }

            this.state.round += 1;
            this.prepareRankedRound();
            this.startCountdownTimer();
            this.setFeedback("");
        }

        async finishRankedGame(reason = "finished", message = "") {
            if (this.state.isFinishing || this.state.status === "finished") return;
            this.state.isFinishing = true;
            this.state.lastAnswer = this.captureAnswer();
            this.clearAdvanceTimer();
            this.clearCountdownTimer();
            if (reason === "timeout" && !this.state.solved) {
                this.state.mistakes += 1;
            }
            this.state.status = "finished";
            this.state.endReason = reason;
            this.state.durationSeconds = this.totalElapsedSeconds();
            this.state.timeLeftSeconds = 0;
            this.bestScore = Math.max(this.bestScore, this.state.score);
            try { window.localStorage.setItem(this.personalStorageKey, String(this.bestScore)); } catch { /* Storage can be disabled; the server record is unaffected. */ }
            this.render();
            this.root.dataset.codeOutcome = reason === "wrong" || reason === "timeout" ? "wrong" : "";

            if (!this.config.isLoggedIn) {
                this.setFeedback(message || "실전 종료. 로그인하면 점수와 순위가 서버에 기록됩니다.");
                this.state.isFinishing = false;
                this.renderSubmitButton();
                return;
            }

            this.setFeedback(message ? `${message} 점수를 저장하는 중입니다.` : "실전 종료. 점수를 저장하는 중입니다.");

            if (this.sessionRefreshPromise) {
                await this.sessionRefreshPromise;
            }

            try {
                await this.saveRankedScore();
            } finally {
                this.state.isFinishing = false;
                this.renderSubmitButton();
            }
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

                const response = await window.fetch(`${this.config.restUrl}/games/${this.config.gameId}/session`, {
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

        generateCodeTable() {
            return this.symbols.reduce((table, symbol, index) => {
                table[symbol] = this.rules.codeTable?.[symbol] ?? orderedTable[symbol] ?? String(index + 1).padStart(2, "0");
                return table;
            }, {});
        }

        createChallenge() {
            const difficulty = this.getDifficulty();
            const text = this.pickPhrase(difficulty.phrases);
            const codes = this.encodeText(text);
            const usedSymbols = [...new Set(text.split(""))];

            return {
                text,
                codes,
                usedSymbols: usedSymbols.sort((left, right) => orderedSymbols.indexOf(left) - orderedSymbols.indexOf(right)),
            };
        }

        getDifficulty() {
            return this.rules.difficulties[this.state.difficulty] || Object.values(this.rules.difficulties)[0];
        }

        currentRoundTimeLimit() {
            const difficulty = this.getDifficulty();
            const start = Number(difficulty.roundTimeSeconds || difficulty.timeLimitSeconds) || 30;
            const minimum = Number(difficulty.minimumRoundTimeSeconds) || Math.ceil(start * 0.55);
            const decrease = Number(difficulty.timeDecreasePerStep) || 2;
            const stepSize = Math.max(1, Number(difficulty.correctAnswersPerStep) || 3);
            const steps = Math.floor(this.state.correctCount / stepSize);

            return Math.max(minimum, start - (steps * decrease));
        }

        totalElapsedSeconds() {
            if (!this.state.startedAt) {
                return this.state.durationSeconds;
            }

            return Math.max(1, Math.round((performance.now() - this.state.startedAt) / 1000));
        }

        endReasonLabel() {
            if (this.state.endReason === "wrong") {
                return "오답 제출";
            }

            if (this.state.endReason === "timeout") {
                return "시간 초과";
            }

            return "게임 종료";
        }

        pickPhrase(phrases) {
            return phrases[Math.floor(Math.random() * phrases.length)];
        }

        encodeText(text) {
            return text.split("").map((symbol) => this.state.table[symbol]);
        }

        render() {
            this.root.dataset.codePlay = this.state.playType;
            this.root.dataset.codeStatus = this.state.status;
            this.root.dataset.codeMode = this.state.currentMode;
            this.root.dataset.codeOutcome = "";
            if (this.fields.scoreBurst) this.fields.scoreBurst.textContent = "";
            if (this.fields.scoreDetail) this.fields.scoreDetail.textContent = "";
            this.root.querySelectorAll("[data-play-type]").forEach((button) => {
                const active = button.dataset.playType === this.state.playType;
                button.classList.toggle("is-active", active);
                button.setAttribute("aria-selected", active ? "true" : "false");
            });

            this.root.querySelectorAll("[data-mode]").forEach((button) => {
                const active = button.dataset.mode === this.state.practiceMode;
                button.classList.toggle("is-active", active);
                button.disabled = this.state.playType !== "practice";
                button.setAttribute("aria-selected", active ? "true" : "false");
            });

            this.fields.practiceModes.classList.toggle("is-disabled", this.state.playType !== "practice");
            this.fields.practiceModes.hidden = this.state.playType !== "practice";
            this.fields.difficulty.value = this.state.difficulty;
            this.fields.difficulty.disabled = this.state.status === "playing";
            this.root.querySelector('[data-action="hint"]').disabled = this.state.playType !== "practice" || this.state.solved;
            this.fields.modeLabel.textContent = this.getPanelModeLabel();
            this.fields.difficultyLabel.textContent = this.getDifficulty().label;
            this.renderStatsOnly();
            this.renderPrompt();
            this.renderAnswer();
            this.renderCodeTable();
            this.renderLeaderboard();
            this.renderSubmitButton();
        }

        getPanelModeLabel() {
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                return "실전 대기";
            }

            if (this.state.playType === "ranked" && this.state.status === "finished") {
                return "실전 완료";
            }

            const mode = this.rules.modes[this.state.currentMode] || this.rules.modes[this.rules.defaultMode];

            return mode.label || this.state.currentMode;
        }

        renderStatsOnly() {
            const goal = this.root.querySelector('[data-field="challenge-goal"]');
            if (goal) goal.textContent = this.state.status === "ranked-idle" ? "몇 문제까지 이어 갈 수 있을까요?"
                : this.state.status === "finished" ? this.endReasonLabel()
                : { decode: "숫자를 글자로 바꿔 보세요", encode: "글자를 숫자 코드로 바꿔 보세요", rebuild: "짝을 보고 빈 코드표를 채워 보세요" }[this.state.currentMode];
            this.fields.score.textContent = String(this.state.score);
            this.fields.progressLabel.textContent = this.state.playType === "ranked" ? "문제 시간" : "연습 번호";
            this.fields.round.textContent = this.state.playType === "ranked"
                ? this.formatTime(this.state.status === "ranked-idle" ? this.currentRoundTimeLimit() : this.state.timeLeftSeconds)
                : String(this.state.round);
            this.fields.round.classList.toggle(
                "is-warning",
                this.state.playType === "ranked" && this.state.status === "playing" && this.state.timeLeftSeconds <= 5
            );
            this.fields.streak.textContent = String(this.state.streak);
            this.fields.best.textContent = String(this.bestScore);
            if (this.fields.timeTrack) {
                const timed = this.state.playType === "ranked";
                const percent = Math.max(0, Math.min(100, this.state.timeLeftSeconds / Math.max(1, this.state.timeLimitSeconds) * 100));
                this.fields.timeTrack.hidden = !timed;
                this.fields.timeTrack.setAttribute("aria-valuenow", String(Math.round(percent)));
                this.fields.timeFill.style.width = `${percent}%`;
                this.fields.timeTrack.classList.toggle("is-warning", timed && this.state.timeLeftSeconds <= 5);
            }
        }

        renderPrompt() {
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                this.fields.prompt.innerHTML = `
                    <div class="oj-code-intro">
                        <strong>최고 ${this.bestScore}점</strong>
                        <span>복호화 · 암호화 · 코드표 복원</span>
                    </div>
                `;
                return;
            }

            if (this.state.playType === "ranked" && this.state.status === "finished") {
                this.fields.prompt.innerHTML = `
                    <div class="oj-code-result">
                        <strong>${this.state.score}점</strong>
                        <span>${this.state.correctCount}문제 해결 · ${this.endReasonLabel()} · 오답 ${this.state.mistakes}회 · ${this.state.durationSeconds}초</span>
                    </div>
                `;
                return;
            }

            const challenge = this.state.challenge;
            if (!challenge) {
                this.fields.prompt.innerHTML = "";
                return;
            }

            if (this.state.currentMode === "decode") {
                this.fields.prompt.innerHTML = `
                    <div class="oj-code-array">${challenge.codes.map((code, index) => `<span data-code-index="${index}">${escapeHtml(code)}</span>`).join("")}</div>
                `;
                return;
            }

            if (this.state.currentMode === "encode") {
                this.fields.prompt.innerHTML = `<div class="oj-code-plain">${challenge.text.split("").map((symbol, index) => `<span data-symbol-index="${index}"${symbol === " " ? ' class="oj-code-space-token"' : ''}>${escapeHtml(labelFor(symbol))}</span>`).join("")}</div>`;
                return;
            }

            this.fields.prompt.innerHTML = `
                <div class="oj-code-pair-grid">
                    ${challenge.text.split("").map((symbol, index) => `
                        <div class="oj-code-pair">
                            <span>${labelFor(symbol)}</span>
                            <strong>${escapeHtml(challenge.codes[index])}</strong>
                        </div>
                    `).join("")}
                </div>
            `;
        }

        renderAnswer() {
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                this.fields.answer.innerHTML = `<div class="oj-code-empty">문제당 ${this.currentRoundTimeLimit()}초부터 시작</div>`;
                return;
            }

            if (this.state.playType === "ranked" && this.state.status === "finished") {
                const expected = this.state.challenge ? (this.state.currentMode === "decode" ? this.state.challenge.text : this.state.currentMode === "encode" ? this.state.challenge.codes.join(" ") : this.state.challenge.usedSymbols.map(symbol => `${labelFor(symbol)}=${this.state.table[symbol]}`).join(" · ")) : "";
                this.fields.answer.innerHTML = expected ? `<div class="oj-code-last-answer"><span>내 입력</span><code>${escapeHtml(this.state.lastAnswer || "미입력")}</code><span>정답</span><code>${escapeHtml(expected)}</code></div>` : "";
                return;
            }

            if (this.state.currentMode === "decode") {
                this.fields.answer.innerHTML = `
                    <label class="oj-code-entry"><span class="oj-code-entry-head">원문 <small data-entry-count>0 / ${this.state.challenge.text.length}</small></span><input type="text" class="oj-code-input" data-answer-input spellcheck="false" autocomplete="off" autocapitalize="characters" aria-label="해독한 원문" placeholder="원문 입력"></label>
                `;
                return;
            }

            if (this.state.currentMode === "encode") {
                this.fields.answer.innerHTML = `
                    <label class="oj-code-entry"><span class="oj-code-entry-head">숫자 코드 <small data-entry-count>0 / ${this.state.challenge.codes.length}</small></span><textarea class="oj-code-input" data-answer-input rows="2" inputmode="text" spellcheck="false" autocomplete="off" aria-label="암호화한 숫자 코드열" placeholder="예: 01 02 00 27"></textarea></label>
                `;
                return;
            }

            const rows = this.state.challenge.usedSymbols
                .map((symbol, index) => `
                    <label class="oj-code-rebuild-cell">
                        <span>${labelFor(symbol)}</span>
                        <input type="text" inputmode="numeric" maxlength="2" data-rebuild-index="${index}" aria-label="${labelFor(symbol)}의 코드" autocomplete="off" />
                    </label>
                `)
                .join("");

            this.fields.answer.innerHTML = `<div class="oj-code-rebuild-grid">${rows}</div>`;
        }

        renderCodeTable() {
            this.fields.tableCount.textContent = String(this.symbols.length);
            const table = Object.keys(this.state.table).length ? this.state.table : this.generateCodeTable();
            const missing = this.state.currentMode === "rebuild" && !this.state.solved && this.state.status !== "finished" ? this.state.challenge?.usedSymbols || [] : [];
            const group = (label, symbols, kind) => `<section class="oj-code-table-group ${kind}"><h4>${label}</h4><div class="oj-code-table-grid">${symbols.map(symbol => `<div class="oj-code-table-cell${missing.includes(symbol) ? ' is-missing' : ''}" data-table-symbol="${escapeHtml(symbol)}"><span>${labelFor(symbol)}</span><strong>${missing.includes(symbol) ? '??' : escapeHtml(table[symbol])}</strong></div>`).join("")}</div></section>`;
            this.fields.codeTable.innerHTML = group("알파벳", [..."ABCDEFGHIJKLMNOPQRSTUVWXYZ"], "is-alphabet")
                + group("숫자 문자", [..."0123456789"], "is-digits") + group("공백", [" "], "is-space");
        }

        renderSubmitButton() {
            const label = this.fields.submitLabel || this.fields.submitButton;
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                label.textContent = "실전 시작";
                this.fields.submitButton.disabled = false;
                return;
            }

            if (this.state.playType === "ranked" && this.state.status === "finished") {
                label.textContent = "다시 시작";
                this.fields.submitButton.disabled = this.state.isSaving || this.state.isFinishing;
                return;
            }

            if (this.state.playType === "practice" && this.state.solved) {
                label.textContent = "다음 연습";
                this.fields.submitButton.disabled = false;
                return;
            }

            label.textContent = "확인";
            this.fields.submitButton.disabled = this.state.isSaving || (this.state.playType === "ranked" && this.state.solved);
        }

        submit() {
            if (this.state.isSaving || this.state.isFinishing) return;
            if (this.state.playType === "ranked" && this.state.status !== "playing") {
                this.startRankedGame();
                return;
            }

            if (this.state.playType === "ranked") {
                this.updateTimeLeft();
                if (this.state.timeLeftSeconds <= 0) {
                    this.finishRankedGame("timeout", "시간이 초과되어 실전이 종료되었습니다.");
                    return;
                }
            }

            if (this.state.playType === "practice" && this.state.solved) {
                this.newPracticeRound();
                return;
            }

            if (this.state.solved || !this.state.challenge) {
                return;
            }

            this.state.lastAnswer = this.captureAnswer();
            const result = this.evaluate();
            if (!result.ok) {
                this.state.streak = 0;
                this.state.currentMistakes += 1;
                this.state.mistakes += this.state.playType === "ranked" ? 1 : 0;
                this.renderStatsOnly();
                this.showOutcome(false, 0, result.badIndexes || []);
                if (this.state.playType === "ranked") {
                    this.finishRankedGame("wrong", `${result.message} 오답 제출로 실전이 종료되었습니다.`);
                    return;
                }
                this.setFeedback(result.message, "wrong");
                return;
            }

            const gained = this.scoreForRound();
            const details = this.scoreExplanation();
            this.state.score += gained;
            this.state.streak += 1;
            this.state.correctCount += this.state.playType === "ranked" ? 1 : 0;
            this.state.solved = true;
            this.bestScore = Math.max(this.bestScore, this.state.score);
            try { window.localStorage.setItem(this.personalStorageKey, String(this.bestScore)); } catch { /* Storage can be disabled; the server record is unaffected. */ }
            this.renderStatsOnly();
            this.renderSubmitButton();
            this.showOutcome(true, gained);
            if (this.fields.scoreDetail) this.fields.scoreDetail.textContent = details;
            this.root.querySelectorAll('[data-answer-input],[data-rebuild-index]').forEach(input => { input.readOnly = true; });
            if (this.state.currentMode === "rebuild") this.renderCodeTable();

            if (this.state.playType === "practice") {
                this.setFeedback("정답 · 다음 연습에 도전하세요", "correct");
                return;
            }

            this.setFeedback("정답 · 시간 충전", "correct");
            this.clearCountdownTimer();
            this.state.timeLeftSeconds = this.currentRoundTimeLimit();
            this.renderStatsOnly();
            this.nextRoundTimer = window.setTimeout(() => this.advanceRankedRound(), 700);
        }

        clearAdvanceTimer() {
            if (this.nextRoundTimer) {
                window.clearTimeout(this.nextRoundTimer);
                this.nextRoundTimer = 0;
            }
        }

        startCountdownTimer() {
            this.clearCountdownTimer();
            this.updateTimeLeft();
            this.countdownTimer = window.setInterval(() => {
                this.updateTimeLeft();
                this.renderStatsOnly();
                if (this.state.timeLeftSeconds <= 0) {
                    this.finishRankedGame("timeout", "시간이 초과되어 실전이 종료되었습니다.");
                }
            }, 250);
        }

        clearCountdownTimer() {
            if (this.countdownTimer) {
                window.clearInterval(this.countdownTimer);
                this.countdownTimer = 0;
            }
        }

        updateTimeLeft() {
            if (this.state.playType !== "ranked" || this.state.status !== "playing") {
                return;
            }

            const elapsedSeconds = Math.floor((performance.now() - this.state.roundStartedAt) / 1000);
            this.state.timeLeftSeconds = Math.max(0, this.state.timeLimitSeconds - elapsedSeconds);
        }

        formatTime(totalSeconds) {
            const seconds = Math.max(0, Number(totalSeconds) || 0);
            const minutes = Math.floor(seconds / 60);
            const remainder = seconds % 60;

            return `${minutes}:${String(remainder).padStart(2, "0")}`;
        }

        evaluate() {
            if (this.state.currentMode === "decode") {
                const input = this.root.querySelector("[data-answer-input]");
                const answer = normalizePlainText(input ? input.value : "");
                return answer === this.state.challenge.text
                    ? { ok: true, message: "정답입니다." }
                    : { ok: false, message: "표시한 글자를 확인하세요", badIndexes: this.differentIndexes([...answer], [...this.state.challenge.text]) };
            }

            if (this.state.currentMode === "encode") {
                const input = this.root.querySelector("[data-answer-input]");
                const answer = this.parseCodeArray(input ? input.value : "");
                return this.arraysEqual(answer, this.state.challenge.codes)
                    ? { ok: true, message: "정답입니다." }
                    : { ok: false, message: "표시한 숫자 코드를 확인하세요", badIndexes: this.differentIndexes(answer, this.state.challenge.codes) };
            }

            const badIndexes = [];
            this.state.challenge.usedSymbols.forEach((symbol, index) => {
                const input = this.root.querySelector(`[data-rebuild-index="${index}"]`);
                if (!input || normalizeCode(input.value) !== this.state.table[symbol]) badIndexes.push(index);
            });

            return badIndexes.length
                ? { ok: false, message: "표시한 빈칸을 확인하세요", badIndexes }
                : { ok: true, message: "정답입니다." };
        }

        differentIndexes(left, right) {
            const indexes = right.flatMap((value, index) => left[index] !== value ? [index] : []);
            if (left.length > right.length && right.length) indexes.push(right.length - 1);
            return indexes;
        }

        captureAnswer() {
            if (!this.state.challenge) return "";
            return this.state.currentMode === "rebuild"
                ? this.state.challenge.usedSymbols.map((symbol, index) => `${labelFor(symbol)}=${this.root.querySelector(`[data-rebuild-index="${index}"]`)?.value || "?"}`).join(" · ")
                : this.root.querySelector('[data-answer-input]')?.value || "";
        }

        focusAnswer() {
            this.root.querySelector('[data-answer-input],[data-rebuild-index]')?.focus({ preventScroll: true });
        }

        updateInputFeedback() {
            if (this.state.solved) return;
            const input = this.root.querySelector('[data-answer-input]');
            const count = input ? (this.state.currentMode === "encode" ? this.parseCodeArray(input.value).length : normalizePlainText(input.value).length) : -1;
            const counter = this.root.querySelector('[data-entry-count]');
            if (counter) counter.textContent = `${count} / ${this.state.challenge.text.length}`;
            this.root.querySelectorAll('[data-code-index],[data-symbol-index]').forEach((node, index) => {
                node.classList.toggle("is-entered", index < count);
                node.classList.toggle("is-current", index === count);
                node.classList.remove("is-wrong");
            });
            this.root.querySelectorAll('[aria-invalid="true"]').forEach(node => node.removeAttribute("aria-invalid"));
            this.root.dataset.codeOutcome = "";
            if (this.fields.feedback.dataset.tone === "wrong") this.setFeedback("수정 중");
        }

        showOutcome(correct, gained, badIndexes = []) {
            this.root.dataset.codeOutcome = correct ? "correct" : "wrong";
            if (this.fields.scoreBurst) this.fields.scoreBurst.textContent = correct ? `+${gained}` : "";
            const input = this.root.querySelector('[data-answer-input]');
            if (input) input.setAttribute("aria-invalid", correct ? "false" : "true");
            const selector = this.state.currentMode === "decode" ? '[data-code-index]' : '[data-symbol-index]';
            this.root.querySelectorAll(selector).forEach((node, index) => node.classList.toggle("is-wrong", !correct && badIndexes.includes(index)));
            this.root.querySelectorAll('[data-rebuild-index]').forEach((node, index) => node.setAttribute("aria-invalid", !correct && badIndexes.includes(index) ? "true" : "false"));
            const target = correct ? this.fields.scoreBurst : this.fields.answer;
            if (target?.animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                target.animate(correct ? [{ opacity: 0, transform: "translateY(8px)" }, { opacity: 1, transform: "translateY(0)" }] : [{ transform: "translateX(0)" }, { transform: "translateX(-4px)" }, { transform: "translateX(4px)" }, { transform: "translateX(0)" }], { duration: correct ? 300 : 180 });
            }
        }

        parseCodeArray(value) {
            const tokens = String(value || "").match(/\d+/g) || [];
            return tokens.flatMap((token) => {
                if (token.length > 2 && token.length % 2 === 0) {
                    return token.match(/.{2}/g) || [];
                }
                return [normalizeCode(token)];
            });
        }

        arraysEqual(left, right) {
            return left.length === right.length && left.every((value, index) => value === right[index]);
        }

        scoreForRound() {
            const difficulty = this.getDifficulty();
            const mode = this.rules.modes[this.state.currentMode] || this.rules.modes[this.rules.defaultMode];
            const scoring = this.rules.scoring;
            const lengthBonus = this.state.challenge.text.length * scoring.lengthBonusPerCharacter;
            const streakBonus = Math.min(scoring.maxStreakBonus, this.state.streak * scoring.streakBonusPerAnswer);
            const mistakePenalty = this.state.currentMistakes * (this.state.playType === "ranked" ? scoring.rankedMistakePenalty : scoring.practiceMistakePenalty);
            const hintPenalty = this.state.hintCount * scoring.hintPenalty;

            return Math.max(
                scoring.minimumRoundScore,
                Math.round(((Number(mode.baseScore) + lengthBonus) * difficulty.multiplier) + streakBonus - mistakePenalty - hintPenalty)
            );
        }

        scoreExplanation() {
            const mode = this.rules.modes[this.state.currentMode];
            const scoring = this.rules.scoring;
            const base = Math.round((Number(mode.baseScore) + this.state.challenge.text.length * scoring.lengthBonusPerCharacter) * this.getDifficulty().multiplier);
            const combo = Math.min(scoring.maxStreakBonus, this.state.streak * scoring.streakBonusPerAnswer);
            const penalty = this.state.currentMistakes * (this.state.playType === "ranked" ? scoring.rankedMistakePenalty : scoring.practiceMistakePenalty) + this.state.hintCount * scoring.hintPenalty;
            return `기본·길이 ${base}${combo ? ` · 연속 +${combo}` : ""}${penalty ? ` · 감점 −${penalty}` : ""}${base + combo - penalty < scoring.minimumRoundScore ? ` · 최소 ${scoring.minimumRoundScore}점` : ""}`;
        }

        hint() {
            if (this.state.playType === "ranked") {
                this.setFeedback("실전 게임에서는 힌트를 사용할 수 없습니다.");
                return;
            }

            if (this.state.solved || !this.state.challenge) {
                this.setFeedback("다음 문제로 넘어가세요.");
                return;
            }

            this.state.hintCount += 1;

            if (this.state.currentMode === "decode") {
                const input = this.root.querySelector("[data-answer-input]");
                const current = normalizePlainText(input ? input.value : "");
                const nextLength = Math.min(this.state.challenge.text.length, Math.max(current.length + 1, 1));
                input.value = this.state.challenge.text.slice(0, nextLength);
                input.focus();
                this.updateInputFeedback();
                this.setFeedback("힌트를 적용했습니다.");
                return;
            }

            if (this.state.currentMode === "encode") {
                const input = this.root.querySelector("[data-answer-input]");
                const current = this.parseCodeArray(input ? input.value : "");
                const nextCodes = this.state.challenge.codes.slice(0, Math.min(current.length + 1, this.state.challenge.codes.length));
                input.value = nextCodes.join(" ");
                input.focus();
                this.updateInputFeedback();
                this.setFeedback("힌트를 적용했습니다.");
                return;
            }

            const nextIndex = this.state.challenge.usedSymbols.findIndex((symbol, index) => {
                const input = this.root.querySelector(`[data-rebuild-index="${index}"]`);
                return input && input.value.trim() !== this.state.table[symbol];
            });

            if (nextIndex >= 0) {
                const input = this.root.querySelector(`[data-rebuild-index="${nextIndex}"]`);
                input.value = this.state.table[this.state.challenge.usedSymbols[nextIndex]];
                input.focus();
                this.updateInputFeedback();
            }

            this.setFeedback("힌트를 적용했습니다.");
        }

        async loadLeaderboard() {
            if (!this.config.restUrl || !this.fields.leaderboard) {
                return;
            }

            this.fields.rankStatus.textContent = "불러오는 중";

            const key = this.config.gameId;
            const pager = window.EduGamesRankPager.request(this.fields.leaderboard, key, () => this.loadLeaderboard());
            try {
                const response = await window.fetch(`${this.config.restUrl}/leaderboards/game/${this.config.gameId}?page=${pager.page}&perPage=${pager.perPage}`, {
                    credentials: "same-origin",
                    headers: this.config.nonce ? { "X-WP-Nonce": this.config.nonce } : {},
                });

                if (!response.ok) {
                    throw new Error("leaderboard failed");
                }

                const data = await response.json();
                if (!window.EduGamesRankPager.accept(this.fields.leaderboard, key, data, pager.ticket)) return;
                this.state.leaderboard = Array.isArray(data.leaderboard) ? data.leaderboard : [];
                this.state.myRank = data.myRank || null;
                this.renderLeaderboard();
            } catch (error) {
                window.EduGamesRankPager.fail(this.fields.leaderboard, pager.ticket);
            }
        }

        async saveRankedScore() {
            if (!this.config.restUrl || this.state.isSaving) {
                return;
            }
            // A run with no correct answer is not a record (the server would
            // refuse it with a scary "doesn't match the rules" message).
            if (this.state.correctCount <= 0 || this.state.score <= 0) {
                this.setFeedback("맞힌 문제가 없어 기록하지 않았습니다. 다시 도전해 보세요.");
                return;
            }

            this.state.isSaving = true;
            this.renderSubmitButton();

            const attempts = this.state.correctCount + this.state.mistakes;
            const payload = {
                score: this.state.score,
                roundsPlayed: this.state.correctCount,
                correctCount: this.state.correctCount,
                durationSeconds: this.state.durationSeconds,
                accuracy: attempts > 0 ? Math.round((this.state.correctCount / attempts) * 10000) / 100 : 100,
                meta: {
                    difficulty: this.state.difficulty,
                    mistakes: this.state.mistakes,
                    endReason: this.state.endReason,
                    finalRoundTimeLimit: this.state.timeLimitSeconds,
                    correctAnswersPerStep: Number(this.getDifficulty().correctAnswersPerStep) || 3,
                    minimumRoundTimeSeconds: Number(this.getDifficulty().minimumRoundTimeSeconds) || 0,
                    modeHistory: this.state.modeHistory,
                },
            };

            try {
                const headers = {
                    "Content-Type": "application/json",
                };
                if (this.config.nonce) {
                    headers["X-WP-Nonce"] = this.config.nonce;
                }

                const response = await window.fetch(`${this.config.restUrl}/games/${this.config.gameId}/scores`, {
                    method: "POST",
                    credentials: "same-origin",
                    headers,
                    body: JSON.stringify({ ...payload, sessionToken: this.config.sessionToken }),
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data && data.message ? data.message : "score failed");
                }

                this.account?.read();
                await this.loadLeaderboard();
                this.state.myRank = data.rank || null;
                this.setFeedback(this.state.myRank ? `점수가 저장되었습니다. 현재 ${this.state.myRank}위입니다.` : "점수가 저장되었습니다.");
            } catch (error) {
                this.setFeedback(error.message || "점수 저장에 실패했습니다.");
            } finally {
                this.state.isSaving = false;
                this.renderLeaderboard();
                this.renderSubmitButton();
            }
        }

        renderLeaderboard() {
            if (!this.fields.leaderboard) {
                return;
            }

            if (!this.state.leaderboard.length) {
                this.fields.rankStatus.textContent = "기록 없음";
                this.fields.leaderboard.innerHTML = '<div class="oj-code-empty">아직 기록된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.fields.rankStatus.textContent = this.state.myRank ? `${this.state.myRank}위` : "전체 참여자";
            this.fields.leaderboard.innerHTML = `
                <div class="oj-code-rank-head">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.state.leaderboard.map((row) => `
                    <div data-rank-row class="oj-code-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
                        <strong>${row.rank}</strong>
                        <span>${escapeHtml(row.playerName || "플레이어")}</span>
                        <b>${row.score}</b>
                    </div>
                `).join("")}
            `;
            window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
        }

        setFeedback(message, tone = "neutral") {
            this.fields.feedback.textContent = message;
            this.fields.feedback.dataset.tone = tone;
        }
    }

    const init = () => {
        document.querySelectorAll("[data-character-code]").forEach((root) => {
            if (!root.__CharacterCode) {
                root.__CharacterCode = new CharacterCodeGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
