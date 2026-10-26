(() => {
    const DEFAULT_LEVELS = [
        { lines: 6, spawnMs: 6200, binChance: 0.18 },
        { lines: 7, spawnMs: 5600, binChance: 0.22 },
        { lines: 8, spawnMs: 5000, binChance: 0.26 },
        { lines: 9, spawnMs: 4400, binChance: 0.3 },
        { lines: 10, spawnMs: 3900, binChance: 0.35 },
        { lines: 11, spawnMs: 3400, binChance: 0.4 },
        { lines: 12, spawnMs: 3000, binChance: 0.45 },
        { lines: 13, spawnMs: 2640, binChance: 0.5 },
    ];

    const STORAGE_KEY = "edu-games-binary-arcade-best";
    const formatBinary = (value, bitLength) => value.toString(2).padStart(bitLength, "0");
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const escapeHtml = (value) => String(value || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    class BinaryArcade {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.rules = this.normalizeRules(this.config.rules || {});
            this.maxBits = this.rules.bits.default;
            this.maximumDecimalDigits = String((2 ** this.maxBits) - 1).length;
            this.placeValues = Array.from({ length: this.maxBits }, (_, index) => 2 ** (this.maxBits - index - 1));
            this.fields = {
                score: root.querySelector('[data-field="score"]'),
                stage: root.querySelector('[data-field="stage"]'),
                time: root.querySelector('[data-field="time"]'),
                streak: root.querySelector('[data-field="streak"]'),
                best: root.querySelector('[data-field="best"]'),
                rank: root.querySelector('[data-field="rank"]'),
                scoreLabel: root.querySelector('[data-field="score-label"]'),
                stageLabel: root.querySelector('[data-field="stage-label"]'),
                timeLabel: root.querySelector('[data-field="time-label"]'),
                streakLabel: root.querySelector('[data-field="streak-label"]'),
                bestLabel: root.querySelector('[data-field="best-label"]'),
                rankLabel: root.querySelector('[data-field="rank-label"]'),
                lanes: root.querySelector('[data-field="lanes"]'),
                gridHead: root.querySelector('[data-field="grid-head"]'),
                gridFoot: root.querySelector('[data-field="grid-foot"]'),
                dangerLine: root.querySelector(".oj-binary-danger-line"),
                overlay: root.querySelector('[data-field="overlay"]'),
                overlayTitle: root.querySelector('[data-field="overlay-title"]'),
                overlayScore: root.querySelector('[data-field="overlay-score"]'),
                saveStatus: root.querySelector('[data-field="save-status"]'),
                rankStatus: root.querySelector('[data-field="rank-status"]'),
                leaderboard: root.querySelector('[data-field="leaderboard"]'),
            };

            this.bestScore = 0;
            try { this.bestScore = Number(window.localStorage.getItem(this.storageKey())) || 0; } catch { /* Storage can be disabled (private mode, blocked site data). */ }
            this.leaderboard = [];
            this.animationFrame = 0;
            this.lastTick = 0;
            this.problemId = 0;
            this.focusedInputId = null;
            this.sessionRefreshPromise = null;

            this.resetState();
            this.bindEvents();
            this.renderAll();
            this.loadLeaderboard();
            this.account = window.EduGamesProgress?.connect(this.config, this.root, data => { this.bestScore = Number(data.bestScore) || 0; this.renderStats(); });
        }

        storageKey() {
            return `${STORAGE_KEY}-${this.config.gameId || "binary-conversion"}:${this.config.currentUserId || 0}`;
        }

        readConfig() {
            try {
                return JSON.parse(this.root.dataset.config || "{}");
            } catch (error) {
                return {};
            }
        }

        normalizeRules(rules) {
            const bits = rules.bits || {};
            const minimum = clamp(Number(bits.minimum) || 4, 1, 12);
            const maximum = clamp(Number(bits.maximum) || 8, minimum, 12);
            const bitDefault = clamp(Number(bits.default) || maximum, minimum, maximum);
            const levels = Array.isArray(rules.levels) && rules.levels.length ? rules.levels : DEFAULT_LEVELS;
            const scoring = rules.scoring || {};

            return {
                bits: {
                    minimum,
                    maximum,
                    default: bitDefault,
                },
                modes: {
                    "dec-to-bin": {
                        label: "10진수 → 2진수",
                        shortLabel: "10→2",
                        ...(rules.modes && rules.modes["dec-to-bin"] ? rules.modes["dec-to-bin"] : {}),
                    },
                    "bin-to-dec": {
                        label: "2진수 → 10진수",
                        shortLabel: "2→10",
                        ...(rules.modes && rules.modes["bin-to-dec"] ? rules.modes["bin-to-dec"] : {}),
                    },
                },
                levels: levels.map((level) => ({
                    lines: Math.max(1, Number(level.lines) || 6),
                    spawnMs: Math.max(900, Number(level.spawnMs) || 6200),
                    binChance: clamp(Number(level.binChance) || 0.18, 0, 1),
                })),
                scoring: {
                    basePerBit: Number(scoring.basePerBit) || 10,
                    levelBonus: Number(scoring.levelBonus) || 10,
                    streakStep: Math.max(1, Number(scoring.streakStep) || 3),
                    streakBonusPerStep: Number(scoring.streakBonusPerStep) || 5,
                    minimumProblemScore: Number(scoring.minimumProblemScore) || 40,
                },
            };
        }

        resetState() {
            const firstLevel = this.getLevelSettings(1);
            this.state = {
                status: "idle",
                score: 0,
                streak: 0,
                level: 1,
                linesLeft: firstLevel.lines,
                problems: [],
                nextSpawnInMs: firstLevel.spawnMs,
                lastAnswerValue: null,
                endLabel: "게임 종료",
                saveStatus: this.config.isLoggedIn
                    ? "게임이 끝나면 점수와 순위가 저장됩니다."
                    : "로그인하면 점수와 순위가 저장됩니다.",
                popups: [],
                totalSpawned: 0,
                correctCount: 0,
                startedAt: 0,
                durationSeconds: 0,
                modeHistory: [],
                isSaving: false,
                myRank: null,
            };
        }

        getLevelSettings(level = this.state.level) {
            return this.rules.levels[Math.min(level - 1, this.rules.levels.length - 1)];
        }

        bindEvents() {
            this.root.addEventListener("pointerdown", (event) => {
                const bitButton = event.target.closest("[data-bit-index]");
                if (!bitButton) {
                    return;
                }

                event.preventDefault();
                this.toggleBit(bitButton.dataset.rowId, Number(bitButton.dataset.bitIndex));
            });

            this.root.addEventListener("click", (event) => {
                const actionButton = event.target.closest("[data-action]");
                if (actionButton) {
                    if (actionButton.dataset.action === "start" || actionButton.dataset.action === "restart") {
                        this.start();
                    }
                    if (actionButton.dataset.action === "practice") {
                        this.addPracticeProblem();
                    }
                    return;
                }

                const answerInput = event.target.closest("[data-answer-input]");
                if (answerInput) {
                    this.focusedInputId = answerInput.dataset.rowId;
                    window.requestAnimationFrame(() => answerInput.focus());
                }
            });

            this.root.addEventListener("focusin", (event) => {
                const answerInput = event.target.closest("[data-answer-input]");
                if (answerInput) {
                    this.focusedInputId = answerInput.dataset.rowId;
                }
            });

            this.root.addEventListener("input", (event) => {
                const answerInput = event.target.closest("[data-answer-input]");
                if (!answerInput) {
                    return;
                }

                const problem = this.findProblemById(answerInput.dataset.rowId);
                if (!problem || problem.mode !== "bin-to-dec") {
                    return;
                }

                problem.decimalInput = answerInput.value.replace(/[^0-9]/g, "").slice(0, this.maximumDecimalDigits);
                answerInput.value = problem.decimalInput;

                if (problem.decimalInput !== "" && Number(problem.decimalInput) === problem.decimal) {
                    this.solve(problem);
                    return;
                }

                this.renderStats();
            });

            this.root.addEventListener("keydown", (event) => {
                const answerInput = event.target.closest("[data-answer-input]");
                if (!answerInput) {
                    return;
                }

                const problem = this.findProblemById(answerInput.dataset.rowId);
                if (!problem) {
                    return;
                }

                if (event.key === "Enter") {
                    event.preventDefault();
                    if (Number(problem.decimalInput || "-1") === problem.decimal) {
                        this.solve(problem);
                    }
                }
            });

            window.addEventListener("resize", () => {
                const { baseBottom, stackGap } = this.getRowMetrics();
                // Keep live input nodes intact when the keyboard or viewport resizes.
                this.fields.lanes.querySelectorAll('.oj-binary-row').forEach((row, index) => {
                    row.style.bottom = `${baseBottom + index * stackGap}px`;
                });
            });
        }

        start() {
            window.cancelAnimationFrame(this.animationFrame);
            this.resetState();
            this.focusedInputId = null;
            this.state.status = "playing";
            this.state.saveStatus = "";
            this.state.startedAt = performance.now();
            // The token embedded in the page's initial config is single-use;
            // replaying without a page reload needs a fresh one or the
            // eventual score save is rejected no matter how high the score
            // is. Kick this off now (fire-and-forget) so it's ready by the
            // time end() actually needs it.
            this.sessionRefreshPromise = this.refreshSessionToken();
            this.spawnProblem(true);
            this.lastTick = performance.now();
            this.animationFrame = window.requestAnimationFrame((time) => this.loop(time));
            this.renderAll();
        }

        addPracticeProblem() {
            window.cancelAnimationFrame(this.animationFrame);

            if (this.state.status !== "practice") {
                this.resetState();
                this.focusedInputId = null;
                this.state.status = "practice";
                this.state.score = 0;
                this.state.streak = 0;
                this.state.correctCount = 0;
                this.state.totalSpawned = 0;
                this.state.modeHistory = [];
                this.state.problems = [];
                this.state.saveStatus = "연습 모드에서는 점수와 순위가 기록되지 않습니다.";
            }

            if (!this.canSpawnMore()) {
                this.addPopup("빈 칸 없음");
                this.renderAll();
                return;
            }

            this.spawnProblem(false, { practice: true });
            this.addPopup("연습 +1");
            this.renderAll();
        }

        loop(time) {
            if (this.state.status !== "playing") {
                return;
            }

            const delta = time - this.lastTick;
            this.lastTick = time;
            this.state.nextSpawnInMs -= delta;

            if (this.state.nextSpawnInMs <= 0) {
                const spawned = this.spawnProblem(false);
                if (!spawned) {
                    this.end("위험선 도달");
                    return;
                }
                this.state.nextSpawnInMs = this.getLevelSettings().spawnMs;
            }

            this.state.popups = this.state.popups
                .map((popup) => ({ ...popup, ageMs: popup.ageMs + delta }))
                .filter((popup) => popup.ageMs < 900);

            this.renderAll();
            this.restoreFocusedInput();
            this.animationFrame = window.requestAnimationFrame((nextTime) => this.loop(nextTime));
        }

        getRowMetrics() {
            const shell = this.root.querySelector(".oj-binary-shell") || this.root;
            const rowHeight = this.readCssNumber(shell, "--binary-row-height", 86);

            return {
                rowHeight,
                stackGap: rowHeight,
                baseBottom: this.readCssNumber(shell, "--binary-row-base", 18),
            };
        }

        getVisibleRowCapacity() {
            const shell = this.root.querySelector(".oj-binary-shell") || this.root;
            const fixedRows = Math.round(this.readCssNumber(shell, "--binary-rows", 8));
            if (fixedRows > 0) {
                return fixedRows;
            }

            const { rowHeight, stackGap, baseBottom } = this.getRowMetrics();
            const laneHeight = this.fields.lanes ? (this.fields.lanes.clientHeight || 804) : 804;
            let dangerOffsetTop = 108;

            if (this.fields.lanes && this.fields.dangerLine) {
                const lanesRect = this.fields.lanes.getBoundingClientRect();
                const dangerRect = this.fields.dangerLine.getBoundingClientRect();
                dangerOffsetTop = Math.max(0, dangerRect.top - lanesRect.top);
            }

            const usableHeight = Math.max(0, laneHeight - dangerOffsetTop - baseBottom);
            return Math.max(1, Math.floor((usableHeight - (rowHeight * 0.4)) / stackGap) + 1);
        }

        readCssNumber(element, property, fallback) {
            const value = window.getComputedStyle(element).getPropertyValue(property);
            const number = Number.parseFloat(value);

            return Number.isFinite(number) ? number : fallback;
        }

        canSpawnMore() {
            return this.state.problems.length < this.getVisibleRowCapacity();
        }

        spawnProblem(isOpening, options = {}) {
            if (!isOpening && !this.canSpawnMore()) {
                return false;
            }

            const level = this.getLevelSettings();
            const bitLength = this.maxBits;
            let mode = options.practice
                ? (Math.random() < 0.5 ? "bin-to-dec" : "dec-to-bin")
                : (Math.random() < level.binChance ? "bin-to-dec" : "dec-to-bin");
            const maximum = (2 ** bitLength) - 1;
            const power = 2 ** this.randomInt(1, bitLength - 1);
            const edges = [0, maximum, power - 1, power, power + 1];
            let decimal = Math.random() < 0.35 ? edges[this.randomInt(0, edges.length - 1)] : this.randomInt(0, maximum);

            if (this.state.lastAnswerValue !== null && decimal === this.state.lastAnswerValue) {
                decimal = (decimal + this.randomInt(1, maximum)) % (maximum + 1);
            }
            if (decimal === 0) mode = 'bin-to-dec';

            this.state.problems.push({
                id: String(++this.problemId),
                mode,
                bitLength,
                decimal,
                binary: formatBinary(decimal, bitLength),
                bits: Array.from({ length: bitLength }, () => 0),
                decimalInput: "",
            });

            this.state.totalSpawned += 1;
            this.state.modeHistory.push(mode);
            this.state.lastAnswerValue = decimal;
            return true;
        }

        findProblemById(id) {
            return this.state.problems.find((problem) => String(problem.id) === String(id)) || null;
        }

        toggleBit(id, bitIndex) {
            const problem = this.findProblemById(id);
            if (!problem || problem.mode !== "dec-to-bin") {
                return;
            }

            problem.bits[bitIndex] = problem.bits[bitIndex] === 1 ? 0 : 1;
            this.showPlaceSum(problem.bits);

            if (problem.bits.join("") === problem.binary) {
                this.solve(problem);
                return;
            }

            this.renderLanes();
        }

        solve(problem) {
            this.showPlaceSum(problem.binary.split('').map(Number));
            if (this.state.status === "practice") {
                this.state.correctCount += 1;
                this.state.problems = this.state.problems.filter((entry) => entry.id !== problem.id);
                this.addPopup("정답");

                if (this.focusedInputId === problem.id) {
                    this.focusedInputId = null;
                }

                this.renderAll();
                return;
            }

            const gained = this.scoreFor(problem);

            this.state.score += gained;
            this.state.streak += 1;
            this.state.correctCount += 1;
            this.state.linesLeft = Math.max(0, this.state.linesLeft - 1);
            this.addPopup(`+${gained}`);
            this.state.problems = this.state.problems.filter((entry) => entry.id !== problem.id);

            if (this.focusedInputId === problem.id) {
                this.focusedInputId = null;
            }

            if (this.state.linesLeft === 0) {
                this.levelUp();
                return;
            }

            this.renderAll();
        }

        showPlaceSum(bits) {
            const output = this.root.querySelector('[data-field="place-sum"]');
            if (!output) return;
            const weights = bits.map((bit, index) => bit ? 2 ** (bits.length - index - 1) : 0).filter(Boolean);
            output.textContent = `${weights.length ? weights.join(' + ') : '0'} = ${weights.reduce((sum, value) => sum + value, 0)}`;
        }

        scoreFor(problem) {
            const scoring = this.rules.scoring;
            const streakBonus = Math.floor(this.state.streak / scoring.streakStep) * scoring.streakBonusPerStep;
            const score = (problem.bitLength * scoring.basePerBit) + (this.state.level * scoring.levelBonus) + streakBonus;

            return Math.max(scoring.minimumProblemScore, Math.round(score));
        }

        levelUp() {
            this.state.level += 1;
            const nextLevel = this.getLevelSettings();
            this.state.linesLeft = nextLevel.lines;
            this.state.problems = [];
            this.state.nextSpawnInMs = Math.max(900, nextLevel.spawnMs - 400);
            this.addPopup(`L${this.state.level}`);
            this.renderAll();
        }

        addPopup(value) {
            const id = `${Date.now()}-${Math.random()}`;
            this.state.popups.push({
                id,
                value,
                ageMs: 0,
            });

            if (this.state.status !== "playing") {
                window.setTimeout(() => {
                    this.state.popups = this.state.popups.filter((popup) => popup.id !== id);
                    this.renderLanes();
                }, 900);
            }
        }

        async end(label) {
            if (this.state.status !== "playing") {
                return;
            }

            this.state.status = "ended";
            this.state.endLabel = label;
            this.state.durationSeconds = Math.max(1, Math.round((performance.now() - this.state.startedAt) / 1000));
            window.cancelAnimationFrame(this.animationFrame);
            this.bestScore = Math.max(this.bestScore, this.state.score);
            try { window.localStorage.setItem(this.storageKey(), String(this.bestScore)); } catch { /* Storage can be disabled; the server record is unaffected. */ }
            this.renderAll();

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

                const response = await window.fetch(`${this.config.restUrl}/games/${this.config.gameId || "binary-conversion"}/session`, {
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

        async saveScore() {
            if (this.state.score <= 0) {
                this.state.saveStatus = "점수가 없어 기록하지 않았습니다.";
                this.renderOverlay();
                return;
            }

            if (!this.config.isLoggedIn) {
                this.state.saveStatus = "로그인하면 점수와 순위가 서버에 기록됩니다.";
                this.renderOverlay();
                return;
            }

            if (!this.config.restUrl || this.state.isSaving) {
                return;
            }

            this.state.isSaving = true;
            this.state.saveStatus = "점수를 저장하는 중입니다.";
            this.renderOverlay();

            const payload = {
                score: this.state.score,
                roundsPlayed: this.state.totalSpawned,
                correctCount: this.state.correctCount,
                durationSeconds: this.state.durationSeconds,
                accuracy: this.state.totalSpawned > 0
                    ? Math.round((this.state.correctCount / this.state.totalSpawned) * 10000) / 100
                    : 0,
                meta: {
                    level: this.state.level,
                    maxBits: this.maxBits,
                    modeHistory: this.state.modeHistory,
                    activeProblemsAtEnd: this.state.problems.length,
                    endReason: this.state.endLabel,
                },
            };

            try {
                const headers = {
                    "Content-Type": "application/json",
                };
                if (this.config.nonce) {
                    headers["X-WP-Nonce"] = this.config.nonce;
                }

                const response = await window.fetch(`${this.config.restUrl}/games/${this.config.gameId || "binary-conversion"}/scores`, {
                    method: "POST",
                    credentials: "same-origin",
                    headers,
                    body: JSON.stringify({ ...payload, sessionToken: this.config.sessionToken }),
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(data && data.message ? data.message : "점수를 저장하지 못했습니다.");
                }

                this.state.myRank = data.rank || null;
                const savedScore = Number(data.score?.score);
                const savedMessage = Number.isFinite(savedScore) && savedScore < payload.score
                    ? `저장 상한인 ${savedScore.toLocaleString("ko-KR")}점으로 기록되었습니다.`
                    : "점수가 저장되었습니다.";
                this.state.saveStatus = savedMessage + (this.state.myRank ? ` 현재 ${this.state.myRank}위입니다.` : "");
                this.account?.read();
                await this.loadLeaderboard();
            } catch (error) {
                this.state.saveStatus = error.message || "점수 저장에 실패했습니다.";
            } finally {
                this.state.isSaving = false;
                this.renderStats();
                this.renderOverlay();
                this.renderLeaderboard();
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
                const response = await window.fetch(`${this.config.restUrl}/leaderboards/game/${this.config.gameId || "binary-conversion"}?page=${pager.page}&perPage=${pager.perPage}`, {
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
                this.fields.leaderboard.innerHTML = '<div class="oj-binary-rank-empty">아직 기록된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.setFieldText("rankStatus", this.state.myRank ? `${this.state.myRank}위` : "전체 참여자");
            this.fields.leaderboard.innerHTML = `
                <div class="oj-binary-rank-row oj-binary-rank-columns">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.leaderboard.map((row) => `
                    <div data-rank-row class="oj-binary-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
                        <strong>${Number(row.rank) || "-"}</strong>
                        <span>${escapeHtml(row.playerName || "플레이어")}</span>
                        <b>${Number(row.score) || 0}</b>
                    </div>
                `).join("")}
            `;
            window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
        }

        renderAll() {
            this.renderStats();
            this.renderHead();
            this.renderFoot();
            this.renderLanes();
            this.renderOverlay();
        }

        renderStats() {
            const goal = this.root.querySelector('[data-field="challenge-goal"]');
            if (goal) goal.textContent = this.state.status === 'practice' ? '자유 연습 · 8비트 자리값 익히기' : `이 기기 최고 ${this.bestScore}점 · 다음 목표 ${Math.min(999999, Math.max(500, Math.ceil((this.bestScore + 1) / 500) * 500))}점`;
            if (this.state.status === "practice") {
                this.setStatLabels({
                    score: "모드",
                    stage: "생성",
                    time: "빈 칸",
                    streak: "풀이",
                    best: "최고점",
                    rank: "기록",
                });
                this.setFieldText("score", "연습");
                this.setFieldText("stage", this.state.totalSpawned);
                this.setFieldText("time", Math.max(0, this.getVisibleRowCapacity() - this.state.problems.length));
                this.setFieldText("streak", this.state.correctCount);
                this.setFieldText("best", this.bestScore);
                this.setFieldText("rank", "-");
                return;
            }

            this.setStatLabels({
                score: "점수",
                stage: "레벨",
                time: "남은 줄",
                streak: "연속 정답",
                best: "최고점",
                rank: "내 순위",
            });
            this.setFieldText("score", this.state.score);
            this.setFieldText("stage", this.state.level);
            this.setFieldText("time", this.state.linesLeft);
            this.setFieldText("streak", this.state.streak);
            this.setFieldText("best", this.bestScore);
            this.setFieldText("rank", this.state.myRank || "-");
        }

        setStatLabels(labels) {
            this.setFieldText("scoreLabel", labels.score);
            this.setFieldText("stageLabel", labels.stage);
            this.setFieldText("timeLabel", labels.time);
            this.setFieldText("streakLabel", labels.streak);
            this.setFieldText("bestLabel", labels.best);
            this.setFieldText("rankLabel", labels.rank);
        }

        renderHead() {
            this.fields.gridHead.innerHTML = `
                <div class="oj-binary-grid-mode">형식</div>
                <div class="oj-binary-grid-prompt">문제</div>
                <div class="oj-binary-grid-bits">${this.renderPlaceValueCells()}</div>
                <div class="oj-binary-grid-answer">현재값</div>
            `;
        }

        renderFoot() {
            if (!this.fields.gridFoot) {
                return;
            }

            this.fields.gridFoot.innerHTML = `
                <div class="oj-binary-grid-mode">자리값</div>
                <div class="oj-binary-grid-prompt">10진수</div>
                <div class="oj-binary-grid-bits">${this.renderPlaceValueCells()}</div>
                <div class="oj-binary-grid-answer">합계</div>
            `;
        }

        renderPlaceValueCells() {
            return this.placeValues
                .map((value) => `<span class="oj-binary-grid-cell">${value}</span>`)
                .join("");
        }

        renderLanes() {
            const active = document.activeElement;
            const editing = this.fields.lanes.contains(active) && active.matches('[data-answer-input]');
            const selection = editing ? [active.selectionStart, active.selectionEnd] : null;
            const { baseBottom, stackGap } = this.getRowMetrics();
            const rowsHtml = this.state.problems
                .map((problem, index) => {
                    const bottom = baseBottom + (index * stackGap);
                    const leadingSpaces = this.maxBits - problem.bitLength;

                    const bitCells = Array.from({ length: this.maxBits }, (_, slotIndex) => {
                        if (slotIndex < leadingSpaces) {
                            return '<span class="oj-binary-row-bit is-empty" aria-hidden="true"></span>';
                        }

                        const bitIndex = slotIndex - leadingSpaces;
                        if (problem.mode === "dec-to-bin") {
                            const bit = problem.bits[bitIndex];
                            const classes = ["oj-binary-row-bit", bit === 1 ? "is-on" : ""].filter(Boolean).join(" ");
                            return `<button type="button" class="${classes}" data-row-id="${problem.id}" data-bit-index="${bitIndex}" aria-label="${bitIndex + 1}번 비트">${bit}</button>`;
                        }

                        const bit = problem.binary.charAt(bitIndex);
                        return `<span class="oj-binary-row-bit ${bit === "1" ? "is-on" : ""} is-readonly">${bit}</span>`;
                    }).join("");

                    const answer = problem.mode === "bin-to-dec"
                        ? `<input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" class="oj-binary-answer-input" data-answer-input data-row-id="${problem.id}" value="${problem.decimalInput}" placeholder="입력" aria-label="10진수 답 입력" />`
                        : `<div class="oj-binary-answer-output">${parseInt(problem.bits.join("") || "0", 2)}</div>`;
                    const promptClass = problem.mode === "bin-to-dec"
                        ? "oj-binary-row-prompt is-binary"
                        : "oj-binary-row-prompt";
                    const modeLabel = this.rules.modes[problem.mode] && this.rules.modes[problem.mode].shortLabel
                        ? this.rules.modes[problem.mode].shortLabel
                        : problem.mode;

                    return `
                        <article class="oj-binary-row${problem.mode === "bin-to-dec" ? " is-inverse" : ""}" data-row-id="${problem.id}" style="bottom:${bottom}px; z-index:${20 + index};">
                            <div class="oj-binary-row-mode">${modeLabel}</div>
                            <div class="${promptClass}">${problem.mode === "dec-to-bin" ? problem.decimal : problem.binary}</div>
                            <div class="oj-binary-row-bits">${bitCells}</div>
                            <div class="oj-binary-row-answer">${answer}</div>
                        </article>
                    `;
                })
                .join("");

            const popupsHtml = this.state.popups
                .map((popup, index) => {
                    const rise = popup.ageMs / 14;
                    return `<div class="oj-binary-popup" style="top:${88 + (index * 40) - rise}px; opacity:${1 - (popup.ageMs / 900)};">${popup.value}</div>`;
                })
                .join("");

            this.fields.lanes.innerHTML = `${rowsHtml}${popupsHtml}`;
            if (editing) {
                this.restoreFocusedInput();
                const next = document.activeElement;
                if (next?.matches('[data-answer-input]')) next.setSelectionRange(...selection);
            }
        }

        renderOverlay() {
            const visible = this.state.status !== "playing" && this.state.status !== "practice";
            this.fields.overlay.classList.toggle("is-visible", visible);
            this.fields.overlayTitle.textContent = this.state.status === "ended" ? this.state.endLabel : "준비";
            this.fields.overlayScore.textContent = String(this.state.status === "ended" ? this.state.score : this.bestScore);
            this.fields.saveStatus.textContent = visible ? this.state.saveStatus : "";
        }

        setFieldText(name, value) {
            if (this.fields[name]) {
                this.fields[name].textContent = String(value);
            }
        }

        restoreFocusedInput() {
            if (!this.focusedInputId) {
                return;
            }

            const input = this.root.querySelector(`[data-answer-input][data-row-id="${this.focusedInputId}"]`);
            if (input && document.activeElement !== input) {
                input.focus({ preventScroll: true });
                const valueLength = input.value.length;
                if (typeof input.setSelectionRange === "function") {
                    input.setSelectionRange(valueLength, valueLength);
                }
            }
        }

        randomInt(min, max) {
            return Math.floor(Math.random() * ((max - min) + 1)) + min;
        }
    }

    const init = () => {
        document.querySelectorAll("[data-binary-arcade]").forEach((root) => {
            if (!root.__BinaryArcade) {
                root.__BinaryArcade = new BinaryArcade(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
