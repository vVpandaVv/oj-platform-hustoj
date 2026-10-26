(() => {
    const STORAGE_KEY = "edu-games-keyword-rain-best";
    const TOKEN_COLORS = ["#1d6fd8", "#1f8a55", "#e65d4f", "#7c5ce0", "#0e9aa7", "#d96b27"];

    const escapeHtml = (value) => String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    class KeywordRainGame {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.rules = this.normalizeRules(this.config.rules || {});
            this.fields = {
                language: root.querySelector('[data-field="language"]'),
                difficulty: root.querySelector('[data-field="difficulty"]'),
                score: root.querySelector('[data-field="score"]'),
                correct: root.querySelector('[data-field="correct"]'),
                combo: root.querySelector('[data-field="combo"]'),
                lives: root.querySelector('[data-field="lives"]'),
                level: root.querySelector('[data-field="level"]'),
                rank: root.querySelector('[data-field="rank"]'),
                meta: root.querySelector('[data-field="meta"]'),
                title: root.querySelector('[data-field="title"]'),
                status: root.querySelector('[data-field="status"]'),
                arena: root.querySelector('[data-field="arena"]'),
                drops: root.querySelector('[data-field="drops"]'),
                startCard: root.querySelector('[data-field="start-card"]'),
                input: root.querySelector('[data-field="input"]'),
                feedback: root.querySelector('[data-field="feedback"]'),
                startButton: root.querySelector('[data-action="start"]'),
                leaderboard: root.querySelector('[data-field="leaderboard"]'),
                rankStatus: root.querySelector('[data-field="rank-status"]'),
            };
            this.personalStorageKey = `${STORAGE_KEY}:${this.config.currentUserId || 0}`;
            this.bestScore = 0;
            try { this.bestScore = Number(window.localStorage.getItem(this.personalStorageKey)) || 0; } catch { /* Storage can be disabled (private mode, blocked site data). */ }
            this.frameId = 0;
            this.sessionRefreshPromise = null;
            this.state = this.initialState();

            this.bindEvents();
            this.renderLanguageOptions();
            this.renderDifficultyOptions();
            this.preparePracticeIdle();
            this.loadLeaderboard();
            this.account = window.EduGamesProgress?.connect(this.config, this.root, data => { this.bestScore = Number(data.bestScore) || 0; this.renderStats(); });
        }

        readConfig() {
            try {
                return JSON.parse(this.root.dataset.config || "{}");
            } catch (error) {
                return {};
            }
        }

        normalizeRules(rules) {
            const difficulties = rules.difficulties && Object.keys(rules.difficulties).length
                ? rules.difficulties
                : {
                    easy: {
                        label: "기초",
                        spawnMs: 1850,
                        minSpawnMs: 950,
                        fallMs: 8200,
                        minFallMs: 4300,
                        score: 90,
                    },
                };
            const languages = rules.languages && Object.keys(rules.languages).length
                ? rules.languages
                : { mixed: { label: "혼합", description: "키워드 연습", words: ["if", "else", "for", "return"] } };

            return {
                defaultLanguage: rules.defaultLanguage && languages[rules.defaultLanguage]
                    ? rules.defaultLanguage
                    : Object.keys(languages)[0],
                defaultDifficulty: rules.defaultDifficulty && difficulties[rules.defaultDifficulty]
                    ? rules.defaultDifficulty
                    : Object.keys(difficulties)[0],
                rankedLives: Math.max(1, Number(rules.rankedLives) || 5),
                maxActiveDrops: Math.max(3, Number(rules.maxActiveDrops) || 8),
                levelEvery: Math.max(4, Number(rules.levelEvery) || 10),
                difficulties,
                languages,
                scoring: {
                    comboBonus: Number(rules.scoring && rules.scoring.comboBonus) || 7,
                    maxComboBonus: Number(rules.scoring && rules.scoring.maxComboBonus) || 84,
                    levelBonus: Number(rules.scoring && rules.scoring.levelBonus) || 12,
                    missPenalty: Number(rules.scoring && rules.scoring.missPenalty) || 20,
                },
            };
        }

        initialState() {
            return {
                playType: "practice",
                status: "idle",
                language: this.rules.defaultLanguage,
                difficulty: this.rules.defaultDifficulty,
                score: 0,
                correctCount: 0,
                missedCount: 0,
                combo: 0,
                maxCombo: 0,
                level: 1,
                lives: this.rules.rankedLives,
                drops: [],
                nextDropId: 1,
                lastFrameAt: 0,
                nextSpawnAt: 0,
                startedAt: 0,
                durationSeconds: 0,
                myRank: null,
                leaderboard: [],
                isSaving: false,
                history: [],
            };
        }

        bindEvents() {
            this.root.addEventListener("click", (event) => {
                const playTypeButton = event.target.closest("[data-play-type]");
                if (playTypeButton) {
                    this.switchPlayType(playTypeButton.dataset.playType);
                    return;
                }

                const actionButton = event.target.closest("[data-action]");
                if (!actionButton) {
                    return;
                }

                if (actionButton.dataset.action === "practice") {
                    this.preparePracticeIdle();
                }

                if (actionButton.dataset.action === "start-ranked") {
                    this.startRanked();
                }

                if (actionButton.dataset.action === "start") {
                    this.handleStartButton();
                }
            });

            this.fields.input.addEventListener("input", () => {
                // Typing starts a fresh game, but never restarts a finished one:
                // students are usually still typing when the last life is lost,
                // and restarting then resumed the old score with zero lives.
                if (this.state.status === "finished") {
                    this.fields.input.value = "";
                    return;
                }
                if (this.state.status === "idle") {
                    this.startCurrentMode();
                }

                this.tryMatch(false);
            });

            this.fields.input.addEventListener("keydown", (event) => {
                if (event.key !== "Enter") {
                    return;
                }

                event.preventDefault();
                this.tryMatch(true);
            });

            this.fields.language.addEventListener("change", () => {
                this.state.language = this.fields.language.value;
                this.resetForCurrentMode();
            });

            this.fields.difficulty.addEventListener("change", () => {
                this.state.difficulty = this.fields.difficulty.value;
                this.resetForCurrentMode();
            });
        }

        renderLanguageOptions() {
            this.fields.language.innerHTML = Object.keys(this.rules.languages).map((language) => `
                <option value="${escapeHtml(language)}">${escapeHtml(this.rules.languages[language].label || language)}</option>
            `).join("");
            this.fields.language.value = this.state.language;
        }

        renderDifficultyOptions() {
            this.fields.difficulty.innerHTML = Object.keys(this.rules.difficulties).map((difficulty) => `
                <option value="${escapeHtml(difficulty)}">${escapeHtml(this.rules.difficulties[difficulty].label || difficulty)}</option>
            `).join("");
            this.fields.difficulty.value = this.state.difficulty;
        }

        switchPlayType(playType) {
            if (playType === "ranked") {
                this.prepareRankedIdle();
                return;
            }

            this.preparePracticeIdle();
        }

        resetForCurrentMode() {
            if (this.state.playType === "ranked") {
                this.prepareRankedIdle();
                return;
            }

            this.preparePracticeIdle();
        }

        preparePracticeIdle() {
            this.stopLoop();
            this.state = {
                ...this.initialState(),
                playType: "practice",
                status: "idle",
                language: this.state.language || this.rules.defaultLanguage,
                difficulty: this.state.difficulty || this.rules.defaultDifficulty,
                leaderboard: this.state.leaderboard || [],
                myRank: this.state.myRank || null,
            };
            this.fields.input.value = "";
            this.render();
            this.setFeedback("연습은 기회 제한 없이 진행됩니다. 시작 버튼을 누르거나 첫 단어를 입력하면 시작됩니다.");
        }

        prepareRankedIdle() {
            this.stopLoop();
            this.state = {
                ...this.initialState(),
                playType: "ranked",
                status: "idle",
                language: this.state.language || this.rules.defaultLanguage,
                difficulty: this.state.difficulty || this.rules.defaultDifficulty,
                lives: this.rules.rankedLives,
                leaderboard: this.state.leaderboard || [],
                myRank: this.state.myRank || null,
            };
            // The token embedded in the page's initial config is single-use;
            // replaying ranked mode without a page reload needs a fresh one
            // or the eventual score save is rejected no matter how high the
            // score is. Kick this off now (fire-and-forget) so it's ready by
            // the time finishRanked() actually needs it.
            this.sessionRefreshPromise = this.refreshSessionToken();
            this.fields.input.value = "";
            this.render();
            this.setFeedback(`도전은 기회 ${this.rules.rankedLives}개로 시작합니다. 놓친 단어가 바닥에 닿으면 기회가 줄어듭니다.`);
        }

        startRanked() {
            this.prepareRankedIdle();
            this.startCurrentMode();
        }

        handleStartButton() {
            if (this.state.status === "running") {
                this.pauseGame();
                return;
            }

            if (this.state.status === "paused") {
                this.resumeGame();
                return;
            }

            if (this.state.status === "finished") {
                this.resetForCurrentMode();
            }

            this.startCurrentMode();
        }

        startCurrentMode() {
            if (this.state.status === "running") {
                return;
            }

            this.state.status = "running";
            this.state.startedAt = performance.now();
            this.state.lastFrameAt = performance.now();
            this.state.nextSpawnAt = performance.now();
            this.fields.input.focus();
            this.render();
            this.loop(this.state.lastFrameAt);
        }

        pauseGame() {
            if (this.state.status !== "running") {
                return;
            }

            this.state.status = "paused";
            this.stopLoop();
            this.render();
            this.setFeedback("일시정지했습니다. 다시 시작을 누르면 이어집니다.");
        }

        resumeGame() {
            if (this.state.status !== "paused") {
                return;
            }

            this.state.status = "running";
            this.state.lastFrameAt = performance.now();
            this.state.nextSpawnAt = Math.min(this.state.nextSpawnAt || performance.now(), performance.now() + 400);
            this.fields.input.focus();
            this.render();
            this.loop(this.state.lastFrameAt);
        }

        stopLoop() {
            if (this.frameId) {
                window.cancelAnimationFrame(this.frameId);
                this.frameId = 0;
            }
        }

        loop(now) {
            if (this.state.status !== "running") {
                return;
            }

            const delta = Math.min(80, now - this.state.lastFrameAt);
            this.state.lastFrameAt = now;

            if (now >= this.state.nextSpawnAt && this.state.drops.length < this.rules.maxActiveDrops) {
                this.spawnDrop(now);
            }

            this.advanceDrops(delta);
            this.renderDrops();

            if (this.state.status === "running") {
                this.frameId = window.requestAnimationFrame((nextNow) => this.loop(nextNow));
            }
        }

        spawnDrop(now) {
            const words = this.getWords();
            if (!words.length) {
                return;
            }

            const activeWords = new Set(this.state.drops.map((drop) => drop.text));
            const pool = words.filter((word) => !activeWords.has(word));
            const word = (pool.length ? pool : words)[Math.floor(Math.random() * (pool.length ? pool.length : words.length))];
            const colorIndex = this.state.nextDropId % TOKEN_COLORS.length;

            this.state.drops.push({
                id: this.state.nextDropId,
                text: word,
                x: 5 + Math.random() * 84,
                y: -8,
                fallMs: this.currentFallMs() * (0.94 + Math.random() * 0.14),
                color: TOKEN_COLORS[colorIndex],
            });
            this.state.nextDropId += 1;
            this.state.nextSpawnAt = now + (this.currentSpawnMs() * (0.84 + Math.random() * 0.24));
        }

        advanceDrops(delta) {
            const remaining = [];
            const missed = [];

            this.state.drops.forEach((drop) => {
                const nextY = drop.y + ((delta / drop.fallMs) * 108);
                if (nextY >= 100) {
                    missed.push(drop);
                } else {
                    remaining.push({ ...drop, y: nextY });
                }
            });

            this.state.drops = remaining;
            missed.forEach((drop) => this.handleMiss(drop));
        }

        handleMiss(drop) {
            this.state.missedCount += 1;
            this.state.combo = 0;

            if (this.state.playType === "ranked") {
                this.state.lives = Math.max(0, this.state.lives - 1);
                this.state.score = Math.max(0, this.state.score - this.rules.scoring.missPenalty);
            }

            this.state.history.push({
                word: drop.text,
                result: "miss",
                level: this.state.level,
            });

            if (this.state.playType === "ranked" && this.state.lives <= 0) {
                this.finishRanked();
                return;
            }

            this.renderStats();
            this.setFeedback(`놓쳤습니다: ${drop.text}`);
        }

        tryMatch(force) {
            if (this.state.status !== "running") {
                return;
            }

            const value = this.fields.input.value.trim();
            if (!value) {
                return;
            }

            const match = this.state.drops
                .filter((drop) => drop.text === value)
                .sort((left, right) => right.y - left.y)[0];

            // "in" must not swallow "int"/"input" while they are on screen:
            // when the typed text is also the start of a longer falling word,
            // wait for more letters or Enter.
            if (match && !force && this.state.drops.some((drop) => drop.text !== value && drop.text.startsWith(value))) {
                this.setFeedback(`${value} 를 맞히려면 Enter, 더 긴 단어라면 계속 입력하세요.`);
                return;
            }

            if (!match) {
                if (force) {
                    this.fields.input.classList.remove("is-flash");
                    void this.fields.input.offsetWidth;
                    this.fields.input.classList.add("is-flash");
                    this.setFeedback("화면에 있는 단어와 정확히 일치해야 합니다.");
                }
                return;
            }

            this.handleHit(match);
            this.fields.input.value = "";
        }

        handleHit(drop) {
            this.state.drops = this.state.drops.filter((item) => item.id !== drop.id);
            const difficulty = this.getDifficulty();
            const comboBonus = Math.min(this.rules.scoring.maxComboBonus, this.state.combo * this.rules.scoring.comboBonus);
            const levelBonus = Math.max(0, this.state.level - 1) * this.rules.scoring.levelBonus;
            const lengthBonus = Math.max(0, drop.text.length - 5) * 2;
            const gained = Number(difficulty.score || 100) + comboBonus + levelBonus + lengthBonus;

            this.state.correctCount += 1;
            this.state.combo += 1;
            this.state.maxCombo = Math.max(this.state.maxCombo, this.state.combo);
            this.state.score += gained;
            this.state.level = Math.floor(this.state.correctCount / this.rules.levelEvery) + 1;
            this.state.history.push({
                word: drop.text,
                result: "hit",
                gained,
                level: this.state.level,
            });

            this.renderStats();
            this.renderDrops();
            this.setFeedback(`정확합니다. +${gained}점`);
        }

        async finishRanked() {
            if (this.state.status === "finished") {
                return; // already finishing: never save the same run twice
            }
            this.stopLoop();
            this.state.status = "finished";
            this.state.durationSeconds = this.elapsedSeconds();
            this.bestScore = Math.max(this.bestScore, this.state.score);
            try { window.localStorage.setItem(this.personalStorageKey, String(this.bestScore)); } catch { /* Storage can be disabled; the server record is unaffected. */ }
            this.render();
            this.setFeedback(`게임 종료. ${this.state.correctCount}개를 맞혔고 점수는 ${this.state.score}점입니다.`);

            if (this.sessionRefreshPromise) {
                await this.sessionRefreshPromise;
            }

            this.saveScore();
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

        elapsedSeconds() {
            if (!this.state.startedAt) {
                return this.state.durationSeconds;
            }

            return Math.max(1, Math.round((performance.now() - this.state.startedAt) / 1000));
        }

        getWords() {
            const language = this.rules.languages[this.state.language] || Object.values(this.rules.languages)[0];
            return Array.isArray(language.words) ? language.words : [];
        }

        getDifficulty() {
            return this.rules.difficulties[this.state.difficulty] || Object.values(this.rules.difficulties)[0];
        }

        currentSpawnMs() {
            const difficulty = this.getDifficulty();
            const base = Number(difficulty.spawnMs) || 1500;
            const min = Number(difficulty.minSpawnMs) || 700;
            return Math.max(min, base - ((this.state.level - 1) * 95));
        }

        currentFallMs() {
            const difficulty = this.getDifficulty();
            const base = Number(difficulty.fallMs) || 7000;
            const min = Number(difficulty.minFallMs) || 3300;
            return Math.max(min, base - ((this.state.level - 1) * 280));
        }

        render() {
            this.fields.language.value = this.state.language;
            this.fields.difficulty.value = this.state.difficulty;
            this.root.querySelectorAll("[data-play-type]").forEach((button) => {
                const active = button.dataset.playType === this.state.playType;
                button.classList.toggle("is-active", active);
                button.setAttribute("aria-selected", active ? "true" : "false");
            });

            this.renderStats();
            this.renderHeader();
            this.renderDrops();
            this.renderStartButton();
            this.renderLeaderboard();
            this.root.querySelector(".oj-rain-shell").classList.toggle("is-running", this.state.status === "running");
            this.root.querySelector(".oj-rain-shell").classList.toggle("is-finished", this.state.status === "finished");
        }

        renderHeader() {
            const language = this.rules.languages[this.state.language] || {};
            const difficulty = this.getDifficulty();
            this.fields.meta.textContent = `${language.label || this.state.language} · ${difficulty.label || this.state.difficulty}`;
            this.fields.title.textContent = language.description || "떨어지는 단어를 정확히 입력하세요";

            const statusLabels = {
                idle: "대기",
                running: "진행 중",
                paused: "일시정지",
                finished: "종료",
            };
            this.fields.status.textContent = statusLabels[this.state.status] || "대기";
            this.fields.startCard.hidden = this.state.status === "running" && this.state.drops.length > 0;
        }

        renderStats() {
            const goal = this.root.querySelector('[data-field="challenge-goal"]');
            if (goal) goal.textContent = `${this.account?.synced ? '계정' : '이 기기'} 최고 ${this.bestScore}점 · 다음 목표 ${Math.min(999999, Math.max(500, Math.ceil((this.bestScore + 1) / 500) * 500))}점 · 연속 명중`;
            this.fields.score.textContent = String(this.state.score);
            this.fields.correct.textContent = String(this.state.correctCount);
            this.fields.combo.textContent = String(this.state.combo);
            this.fields.lives.textContent = this.state.playType === "practice" ? "∞" : String(this.state.lives);
            this.fields.level.textContent = String(this.state.level);
            this.fields.rank.textContent = this.state.myRank ? `${this.state.myRank}위` : "-";
        }

        renderDrops() {
            if (!this.state.drops.length) {
                this.fields.drops.innerHTML = "";
                this.fields.startCard.hidden = this.state.status === "running";
                return;
            }

            this.fields.startCard.hidden = true;
            this.fields.drops.innerHTML = this.state.drops.map((drop) => {
                const danger = drop.y > 76 ? " is-danger" : "";
                return `
                    <span class="oj-rain-drop${danger}" style="left:${drop.x}%; top:${drop.y}%; --drop-shift:-${drop.x}%; --drop-color:${drop.color};" data-drop-id="${drop.id}">
                        ${escapeHtml(drop.text)}
                    </span>
                `;
            }).join("");
        }

        renderStartButton() {
            if (this.state.status === "running") {
                this.fields.startButton.textContent = "일시정지";
                this.fields.startButton.disabled = false;
                return;
            }

            if (this.state.status === "paused") {
                this.fields.startButton.textContent = "이어하기";
                this.fields.startButton.disabled = false;
                return;
            }

            if (this.state.status === "finished") {
                this.fields.startButton.textContent = "다시 시작";
                this.fields.startButton.disabled = this.state.isSaving;
                return;
            }

            this.fields.startButton.textContent = "시작";
            this.fields.startButton.disabled = false;
        }

        setFeedback(message) {
            this.fields.feedback.textContent = message;
        }

        async loadLeaderboard() {
            if (!this.config.restUrl || !this.fields.leaderboard) {
                return;
            }

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
                this.renderStats();
            } catch (error) {
                window.EduGamesRankPager.fail(this.fields.leaderboard, pager.ticket);
            }
        }

        async saveScore() {
            if (!this.config.isLoggedIn || !this.config.restUrl || this.state.isSaving || this.state.score <= 0) {
                if (!this.config.isLoggedIn) {
                    this.setFeedback(`${this.fields.feedback.textContent} 로그인하면 점수와 순위가 저장됩니다.`);
                }
                return;
            }

            this.state.isSaving = true;
            this.renderStartButton();

            const attempts = this.state.correctCount + this.state.missedCount;
            const payload = {
                score: this.state.score,
                roundsPlayed: attempts,
                correctCount: this.state.correctCount,
                durationSeconds: this.state.durationSeconds,
                accuracy: attempts ? Math.round((this.state.correctCount / attempts) * 10000) / 100 : 0,
                meta: {
                    language: this.state.language,
                    difficulty: this.state.difficulty,
                    missedCount: this.state.missedCount,
                    maxCombo: this.state.maxCombo,
                    bestScore: this.bestScore,
                    history: this.state.history.slice(-80),
                },
            };

            try {
                const headers = { "Content-Type": "application/json" };
                if (this.config.nonce) {
                    headers["X-WP-Nonce"] = this.config.nonce;
                }

                const response = await window.fetch(`${this.config.restUrl}/games/${this.config.gameId}/scores`, {
                    method: "POST",
                    credentials: "same-origin",
                    headers,
                    body: JSON.stringify({ ...payload, sessionToken: this.config.sessionToken }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data && data.message ? data.message : "점수를 저장하지 못했습니다.");
                }

                this.account?.read();
                await this.loadLeaderboard();
                this.state.myRank = data.rank || null;
                this.renderLeaderboard();
                this.renderStats();
                this.setFeedback(this.state.myRank
                    ? `${this.fields.feedback.textContent} 현재 ${this.state.myRank}위입니다.`
                    : `${this.fields.feedback.textContent} 점수가 저장되었습니다.`);
            } catch (error) {
                this.setFeedback(error.message || "점수 저장 중 오류가 발생했습니다.");
            } finally {
                this.state.isSaving = false;
                this.renderStartButton();
            }
        }

        renderLeaderboard() {
            if (!this.fields.leaderboard) {
                return;
            }

            if (!this.state.leaderboard.length) {
                this.fields.rankStatus.textContent = "기록 없음";
                this.fields.leaderboard.innerHTML = '<div class="oj-rain-rank-empty">아직 저장된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.fields.rankStatus.textContent = this.state.myRank ? `${this.state.myRank}위` : "전체 참여자";
            this.fields.leaderboard.innerHTML = `
                <div class="oj-rain-rank-head">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.state.leaderboard.map((row) => `
                    <div data-rank-row class="oj-rain-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
                        <strong>${row.rank}</strong>
                        <span>${escapeHtml(row.playerName || "플레이어")}</span>
                        <b>${row.score}</b>
                    </div>
                `).join("")}
            `;
            window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
        }
    }

    const init = () => {
        document.querySelectorAll("[data-keyword-rain]").forEach((root) => {
            if (!root.__KeywordRain) {
                root.__KeywordRain = new KeywordRainGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
