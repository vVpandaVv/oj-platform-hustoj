(() => {
    const STORAGE_KEY = "edu-games-character-code-best";

    const escapeHtml = (value) => String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    const labelFor = (symbol) => symbol === " " ? "공백" : symbol;
    const displayText = (text) => escapeHtml(text).replace(/ /g, '<span class="oj-code-space-token">공백</span>');
    const normalizePlainText = (value) => String(value || "").toUpperCase().replace(/[^A-Z0-9 ]/g, "").replace(/\s+/g, " ").trim();

    const shuffle = (items) => {
        const shuffled = [...items];
        for (let index = shuffled.length - 1; index > 0; index -= 1) {
            const swapIndex = Math.floor(Math.random() * (index + 1));
            [shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex], shuffled[index]];
        }
        return shuffled;
    };

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
            };

            this.bindEvents();
            this.newPracticeRound();
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
                phraseMetadata: rules.phraseMetadata || {},
                modes,
                difficulties,
                defaultMode: rules.defaultMode && modes[rules.defaultMode] ? rules.defaultMode : Object.keys(modes)[0],
                defaultDifficulty: rules.defaultDifficulty && difficulties[rules.defaultDifficulty]
                    ? rules.defaultDifficulty
                    : Object.keys(difficulties)[0],
                codeRange: {
                    min: Number(rules.codeRange && rules.codeRange.min) || 10,
                    max: Number(rules.codeRange && rules.codeRange.max) || 99,
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
                if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
                    event.preventDefault();
                    this.submit();
                }
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
            this.setFeedback("실전 시작을 누르면 문제마다 제한시간이 주어집니다. 정답이면 시간이 다시 채워지고, 오답이나 시간 초과는 즉시 종료됩니다.");
        }

        newPracticeRound() {
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
            this.state.currentMode = this.state.practiceMode;
            this.state.table = this.generateCodeTable();
            this.state.challenge = this.createChallenge();
            this.render();
            this.setFeedback("연습 문제입니다. 점수는 순위에 기록되지 않습니다.");
        }

        startRankedGame() {
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
            this.setFeedback(`실전 게임이 시작되었습니다. 이번 문제 제한시간은 ${this.state.timeLimitSeconds}초입니다.`);
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
            this.render();
        }

        advanceRankedRound() {
            if (this.state.status !== "playing") {
                return;
            }

            this.state.round += 1;
            this.prepareRankedRound();
            this.startCountdownTimer();
            this.setFeedback(`다음 문제가 출제되었습니다. 제한시간 ${this.state.timeLimitSeconds}초입니다.`);
        }

        async finishRankedGame(reason = "finished", message = "") {
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

            if (!this.config.isLoggedIn) {
                this.setFeedback(message || "실전 종료. 로그인하면 점수와 순위가 서버에 기록됩니다.");
                return;
            }

            this.setFeedback(message ? `${message} 점수를 저장하는 중입니다.` : "실전 종료. 점수를 저장하는 중입니다.");

            if (this.sessionRefreshPromise) {
                await this.sessionRefreshPromise;
            }

            this.saveRankedScore();
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
            const min = Math.min(this.rules.codeRange.min, this.rules.codeRange.max);
            const max = Math.max(this.rules.codeRange.min, this.rules.codeRange.max);
            const width = String(max).length;
            const codes = shuffle(Array.from({ length: (max - min) + 1 }, (_, index) => String(index + min).padStart(width, "0")));

            return this.symbols.reduce((table, symbol, index) => {
                table[symbol] = codes[index];
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
                usedSymbols: shuffle(usedSymbols),
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
            const meta = this.rules.phraseMetadata[this.state.challenge?.text];
            const metaNode = this.root.querySelector('[data-field="phrase-meta"]');
            if (metaNode) metaNode.textContent = meta && this.state.playType === 'practice' ? `${meta.tags.join(' · ')} · ${meta.length}글자 · 서로 다른 기호 ${meta.uniqueSymbols}개` : '';
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
            this.fields.difficulty.value = this.state.difficulty;
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
            if (goal) goal.textContent = this.state.playType === 'ranked' ? `이 기기 최고 ${this.bestScore}점 · 다음 목표 ${Math.min(999999, Math.max(500, Math.ceil((this.bestScore + 1) / 500) * 500))}점 · 무오답 생존` : '자유 연습 · 디코드·인코드·표 복원';
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
        }

        renderPrompt() {
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                const difficulty = this.getDifficulty();
                const start = Number(difficulty.roundTimeSeconds || difficulty.timeLimitSeconds) || this.currentRoundTimeLimit();
                const minimum = Number(difficulty.minimumRoundTimeSeconds) || Math.ceil(start * 0.55);
                const decrease = Number(difficulty.timeDecreasePerStep) || 2;
                const stepSize = Number(difficulty.correctAnswersPerStep) || 3;
                this.fields.prompt.innerHTML = `
                    <div class="oj-code-intro">
                        <strong>실전 게임</strong>
                        <span>문제마다 ${start}초에서 시작합니다. 정답이면 시간이 초기화되고, ${stepSize}문제마다 ${decrease}초씩 줄어 최소 ${minimum}초까지 내려갑니다.</span>
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
                    <div class="oj-code-array">${challenge.codes.map((code) => `<span>${code}</span>`).join("")}</div>
                `;
                return;
            }

            if (this.state.currentMode === "encode") {
                this.fields.prompt.innerHTML = `<div class="oj-code-plain">${displayText(challenge.text)}</div>`;
                return;
            }

            this.fields.prompt.innerHTML = `
                <div class="oj-code-pair-grid">
                    ${challenge.text.split("").map((symbol, index) => `
                        <div class="oj-code-pair">
                            <span>${labelFor(symbol)}</span>
                            <strong>${challenge.codes[index]}</strong>
                        </div>
                    `).join("")}
                </div>
            `;
        }

        renderAnswer() {
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                this.fields.answer.innerHTML = '<div class="oj-code-empty">실전 시작 버튼을 눌러 기록 도전을 시작하세요.</div>';
                return;
            }

            if (this.state.playType === "ranked" && this.state.status === "finished") {
                this.fields.answer.innerHTML = '<div class="oj-code-empty">점수 저장 후 순위표가 갱신됩니다.</div>';
                return;
            }

            if (this.state.currentMode === "decode") {
                this.fields.answer.innerHTML = `
                    <textarea class="oj-code-input" data-answer-input rows="3" spellcheck="false" autocomplete="off" placeholder="원문을 입력하세요"></textarea>
                `;
                return;
            }

            if (this.state.currentMode === "encode") {
                this.fields.answer.innerHTML = `
                    <textarea class="oj-code-input" data-answer-input rows="3" inputmode="numeric" spellcheck="false" autocomplete="off" placeholder="코드 배열을 입력하세요. 예: 42 17 30"></textarea>
                `;
                return;
            }

            const rows = this.state.challenge.usedSymbols
                .map((symbol, index) => `
                    <label class="oj-code-rebuild-cell">
                        <span>${labelFor(symbol)}</span>
                        <input type="text" inputmode="numeric" maxlength="2" data-rebuild-index="${index}" autocomplete="off" />
                    </label>
                `)
                .join("");

            this.fields.answer.innerHTML = `<div class="oj-code-rebuild-grid">${rows}</div>`;
        }

        renderCodeTable() {
            if (!this.state.challenge) {
                this.fields.tableCount.textContent = "0";
                this.fields.codeTable.innerHTML = '<div class="oj-code-table-empty">문제가 시작되면 코드표가 표시됩니다.</div>';
                return;
            }

            if (this.state.currentMode === "rebuild") {
                const symbols = this.state.challenge.usedSymbols;
                this.fields.tableCount.textContent = String(symbols.length);
                this.fields.codeTable.innerHTML = symbols
                    .map((symbol) => `
                        <div class="oj-code-table-cell is-missing">
                            <span>${labelFor(symbol)}</span>
                            <strong>??</strong>
                        </div>
                    `)
                    .join("");
                return;
            }

            this.fields.tableCount.textContent = String(this.symbols.length);
            this.fields.codeTable.innerHTML = this.symbols
                .map((symbol) => `
                    <div class="oj-code-table-cell">
                        <span>${labelFor(symbol)}</span>
                        <strong>${this.state.table[symbol]}</strong>
                    </div>
                `)
                .join("");
        }

        renderSubmitButton() {
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                this.fields.submitButton.textContent = "실전 시작";
                this.fields.submitButton.disabled = false;
                return;
            }

            if (this.state.playType === "ranked" && this.state.status === "finished") {
                this.fields.submitButton.textContent = "다시 시작";
                this.fields.submitButton.disabled = this.state.isSaving;
                return;
            }

            if (this.state.playType === "practice" && this.state.solved) {
                this.fields.submitButton.textContent = "다음 연습";
                this.fields.submitButton.disabled = false;
                return;
            }

            this.fields.submitButton.textContent = "제출";
            this.fields.submitButton.disabled = this.state.isSaving;
        }

        submit() {
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

            const result = this.evaluate();
            if (!result.ok) {
                this.state.streak = 0;
                this.state.currentMistakes += 1;
                this.state.mistakes += this.state.playType === "ranked" ? 1 : 0;
                this.renderStatsOnly();
                if (this.state.playType === "ranked") {
                    this.finishRankedGame("wrong", `${result.message} 오답 제출로 실전이 종료되었습니다.`);
                    return;
                }
                this.setFeedback(result.message);
                return;
            }

            const gained = this.scoreForRound();
            this.state.score += gained;
            this.state.streak += 1;
            this.state.correctCount += this.state.playType === "ranked" ? 1 : 0;
            this.state.solved = true;
            this.bestScore = Math.max(this.bestScore, this.state.score);
            try { window.localStorage.setItem(this.personalStorageKey, String(this.bestScore)); } catch { /* Storage can be disabled; the server record is unaffected. */ }
            this.renderStatsOnly();
            this.renderSubmitButton();

            if (this.state.playType === "practice") {
                this.setFeedback(`정답입니다. 연습 점수 +${gained}`);
                return;
            }

            this.setFeedback(`정답입니다. +${gained}점`);
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
                    : { ok: false, message: "원문을 다시 확인하세요." };
            }

            if (this.state.currentMode === "encode") {
                const input = this.root.querySelector("[data-answer-input]");
                const answer = this.parseCodeArray(input ? input.value : "");
                return this.arraysEqual(answer, this.state.challenge.codes)
                    ? { ok: true, message: "정답입니다." }
                    : { ok: false, message: "코드 배열을 다시 확인하세요." };
            }

            const missing = this.state.challenge.usedSymbols.find((symbol, index) => {
                const input = this.root.querySelector(`[data-rebuild-index="${index}"]`);
                return !input || input.value.trim() !== this.state.table[symbol];
            });

            return missing
                ? { ok: false, message: `${labelFor(missing)} 값을 다시 확인하세요.` }
                : { ok: true, message: "정답입니다." };
        }

        parseCodeArray(value) {
            const tokens = String(value || "").match(/\d+/g) || [];
            return tokens.flatMap((token) => {
                if (token.length > 2 && token.length % 2 === 0) {
                    return token.match(/.{2}/g) || [];
                }
                return [token];
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
                this.setFeedback("힌트를 적용했습니다.");
                return;
            }

            if (this.state.currentMode === "encode") {
                const input = this.root.querySelector("[data-answer-input]");
                const current = this.parseCodeArray(input ? input.value : "");
                const nextCodes = this.state.challenge.codes.slice(0, Math.min(current.length + 1, this.state.challenge.codes.length));
                input.value = nextCodes.join(" ");
                input.focus();
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

        setFeedback(message) {
            this.fields.feedback.textContent = message;
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
