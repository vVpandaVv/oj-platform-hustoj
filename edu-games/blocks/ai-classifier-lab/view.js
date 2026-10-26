(() => {
    const STORAGE_KEY = "edu-games-ai-classifier-lab-best";
    const CLASS_COLORS = { A: "#1d6fd8", B: "#e65d4f" };

    const escapeHtml = (value) => String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    const shuffle = (items) => {
        const shuffled = [...items];
        for (let index = shuffled.length - 1; index > 0; index -= 1) {
            const swapIndex = Math.floor(Math.random() * (index + 1));
            [shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex], shuffled[index]];
        }
        return shuffled;
    };

    class AIClassifierLabGame {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.rules = this.normalizeRules(this.config.rules || {});
            this.fields = {
                difficulty: root.querySelector('[data-field="difficulty"]'),
                score: root.querySelector('[data-field="score"]'),
                round: root.querySelector('[data-field="round"]'),
                correct: root.querySelector('[data-field="correct"]'),
                rank: root.querySelector('[data-field="rank"]'),
                progressLabel: root.querySelector('[data-field="progress-label"]'),
                sampleMeta: root.querySelector('[data-field="sample-meta"]'),
                sampleTitle: root.querySelector('[data-field="sample-title"]'),
                selectionStatus: root.querySelector('[data-field="selection-status"]'),
                context: root.querySelector('[data-field="context"]'),
                chart: root.querySelector('[data-field="chart"]'),
                rules: root.querySelector('[data-field="rules"]'),
                labels: root.querySelector('[data-field="labels"]'),
                feedback: root.querySelector('[data-field="feedback"]'),
                submitButton: root.querySelector('[data-action="submit"]'),
                leaderboard: root.querySelector('[data-field="leaderboard"]'),
                rankStatus: root.querySelector('[data-field="rank-status"]'),
            };
            this.bestScore = Number(window.localStorage.getItem(STORAGE_KEY) || 0);
            this.sessionRefreshPromise = null;
            this.state = this.initialState();

            this.bindEvents();
            this.renderDifficultyOptions();
            this.newPracticeRound();
            this.loadLeaderboard();
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
                : { easy: { label: "기초", baseScore: 100, samples: [] } };

            return {
                rankedRounds: Math.max(4, Number(rules.rankedRounds) || 10),
                defaultDifficulty: rules.defaultDifficulty && difficulties[rules.defaultDifficulty]
                    ? rules.defaultDifficulty
                    : Object.keys(difficulties)[0],
                difficulties,
                samples: Array.isArray(rules.samples) ? rules.samples : [],
                scoring: {
                    streakBonus: Number(rules.scoring && rules.scoring.streakBonus) || 12,
                    maxStreakBonus: Number(rules.scoring && rules.scoring.maxStreakBonus) || 84,
                    wrongPenalty: Number(rules.scoring && rules.scoring.wrongPenalty) || 25,
                    minimumCorrectScore: Number(rules.scoring && rules.scoring.minimumCorrectScore) || 45,
                },
            };
        }

        initialState() {
            return {
                playType: "practice",
                status: "practice",
                difficulty: this.rules.defaultDifficulty,
                score: 0,
                round: 0,
                correctCount: 0,
                wrongCount: 0,
                streak: 0,
                selectedRule: "",
                selectedLabel: "",
                currentSample: null,
                answered: false,
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

                const ruleButton = event.target.closest("[data-rule-id]");
                if (ruleButton) {
                    this.selectRule(ruleButton.dataset.ruleId);
                    return;
                }

                const labelButton = event.target.closest("[data-label-id]");
                if (labelButton) {
                    this.selectLabel(labelButton.dataset.labelId);
                    return;
                }

                const actionButton = event.target.closest("[data-action]");
                if (!actionButton) {
                    return;
                }

                if (actionButton.dataset.action === "new-practice") {
                    this.newPracticeRound();
                }

                if (actionButton.dataset.action === "start-ranked") {
                    this.startRanked();
                }

                if (actionButton.dataset.action === "submit") {
                    this.submit();
                }
            });

            this.fields.difficulty.addEventListener("change", () => {
                this.state.difficulty = this.fields.difficulty.value;
                if (this.state.playType === "practice") {
                    this.newPracticeRound();
                } else {
                    this.prepareRankedIdle();
                }
            });
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

            this.newPracticeRound();
        }

        newPracticeRound() {
            this.state.playType = "practice";
            this.state.status = "practice";
            this.state.round += 1;
            this.state.selectedRule = "";
            this.state.selectedLabel = "";
            this.state.answered = false;
            this.state.currentSample = this.pickSample();
            this.render();
            this.setFeedback("학습 데이터의 점들을 보고 가장 그럴듯한 분류 규칙과 새 데이터의 라벨을 고르세요.");
        }

        prepareRankedIdle() {
            this.state.playType = "ranked";
            this.state.status = "ranked-idle";
            this.state.score = 0;
            this.state.round = 0;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedRule = "";
            this.state.selectedLabel = "";
            this.state.answered = false;
            this.state.startedAt = 0;
            this.state.durationSeconds = 0;
            this.state.history = [];
            this.state.currentSample = null;
            this.render();
            this.setFeedback(`도전은 ${this.rules.rankedRounds}라운드입니다. 규칙과 예측 라벨을 모두 맞히면 점수를 얻습니다.`);
        }

        startRanked() {
            this.state.playType = "ranked";
            this.state.status = "ranked";
            this.state.score = 0;
            this.state.round = 1;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedRule = "";
            this.state.selectedLabel = "";
            this.state.answered = false;
            this.state.startedAt = performance.now();
            this.state.durationSeconds = 0;
            this.state.history = [];
            this.state.currentSample = this.pickRankedSample();
            // The token embedded in the page's initial config is single-use;
            // replaying ranked mode without a page reload needs a fresh one
            // or the eventual score save is rejected no matter how high the
            // score is. Kick this off now (fire-and-forget) so it's ready by
            // the time finishRanked() actually needs it.
            this.sessionRefreshPromise = this.refreshSessionToken();
            this.render();
            this.setFeedback("도전이 시작되었습니다. 점들의 색이 나뉘는 기준을 찾으세요.");
        }

        nextRankedRound() {
            if (this.state.round >= this.rules.rankedRounds) {
                this.finishRanked();
                return;
            }

            this.state.round += 1;
            this.state.selectedRule = "";
            this.state.selectedLabel = "";
            this.state.answered = false;
            this.state.currentSample = this.pickRankedSample();
            this.render();
            this.setFeedback("다음 학습 데이터입니다. 경계선이 어느 방향이어야 하는지 먼저 보세요.");
        }

        pickSample() {
            const difficulty = this.getDifficulty();
            const ids = Array.isArray(difficulty.samples) ? difficulty.samples : [];
            const candidates = this.rules.samples.filter((sample) => ids.includes(sample.id));
            const pool = candidates.length ? candidates : this.rules.samples;

            return this.cloneSample(pool[Math.floor(Math.random() * pool.length)]);
        }

        pickRankedSample() {
            const preferred = this.state.round <= 3
                ? "easy"
                : (this.state.round <= 7 ? this.state.difficulty : "hard");
            const nextDifficulty = this.rules.difficulties[preferred] ? preferred : this.state.difficulty;
            const previousIds = new Set(this.state.history.map((item) => item.sampleId));
            const difficulty = this.rules.difficulties[nextDifficulty] || this.getDifficulty();
            const ids = Array.isArray(difficulty.samples) ? difficulty.samples : [];
            let pool = this.rules.samples.filter((sample) => ids.includes(sample.id) && !previousIds.has(sample.id));

            if (!pool.length) {
                pool = this.rules.samples.filter((sample) => ids.includes(sample.id));
            }

            if (!pool.length) {
                pool = this.rules.samples;
            }

            const sample = this.cloneSample(pool[Math.floor(Math.random() * pool.length)]);
            sample.difficulty = nextDifficulty;
            return sample;
        }

        cloneSample(sample) {
            return {
                ...sample,
                points: Array.isArray(sample && sample.points)
                    ? sample.points.map((point) => ({ x: Number(point.x), y: Number(point.y), label: String(point.label) }))
                    : [],
                query: sample && sample.query ? { x: Number(sample.query.x), y: Number(sample.query.y) } : { x: 0, y: 0 },
                rules: shuffle(Array.isArray(sample && sample.rules) ? sample.rules : []),
                labels: sample && sample.labels ? { ...sample.labels } : {},
            };
        }

        getDifficulty() {
            return this.rules.difficulties[this.state.difficulty] || Object.values(this.rules.difficulties)[0];
        }

        selectRule(ruleId) {
            if (!this.state.currentSample || this.state.status === "ranked-idle" || this.state.answered) {
                return;
            }

            this.state.selectedRule = ruleId;
            this.renderChart();
            this.renderRuleOptions();
            this.renderSelectionStatus();
        }

        selectLabel(labelId) {
            if (!this.state.currentSample || this.state.status === "ranked-idle" || this.state.answered) {
                return;
            }

            this.state.selectedLabel = labelId;
            this.renderLabelOptions();
            this.renderSelectionStatus();
        }

        submit() {
            if (this.state.playType === "ranked" && this.state.status === "ranked-idle") {
                this.startRanked();
                return;
            }

            if (this.state.playType === "ranked" && this.state.status === "finished") {
                this.startRanked();
                return;
            }

            if (this.state.playType === "practice" && this.state.answered) {
                this.newPracticeRound();
                return;
            }

            if (!this.state.selectedRule) {
                this.setFeedback("먼저 분류 규칙을 선택하세요.");
                return;
            }

            if (!this.state.selectedLabel) {
                this.setFeedback("새 데이터의 예측 라벨도 선택하세요.");
                return;
            }

            const ok = this.isCorrectAnswer();
            if (this.state.playType === "practice") {
                this.handlePracticeSubmit(ok);
                return;
            }

            this.handleRankedSubmit(ok);
        }

        isCorrectAnswer() {
            const sample = this.state.currentSample;
            return this.state.selectedRule === sample.answerRule && this.state.selectedLabel === sample.answerLabel;
        }

        handlePracticeSubmit(ok) {
            if (!ok) {
                this.state.wrongCount += 1;
                this.renderStats();
                this.setFeedback("아직 맞지 않습니다. 두 색의 점을 가장 잘 나누는 기준과 새 점의 위치를 다시 확인하세요.");
                return;
            }

            this.state.correctCount += 1;
            this.state.answered = true;
            this.render();
            this.setFeedback(`정답입니다. ${this.state.currentSample.explanation}`);
        }

        handleRankedSubmit(ok) {
            const difficulty = this.rules.difficulties[this.state.currentSample.difficulty] || this.getDifficulty();
            let gained = 0;
            this.state.answered = true;

            if (ok) {
                const streakBonus = Math.min(this.rules.scoring.maxStreakBonus, this.state.streak * this.rules.scoring.streakBonus);
                gained = Math.max(this.rules.scoring.minimumCorrectScore, Number(difficulty.baseScore || 100) + streakBonus);
                this.state.score += gained;
                this.state.correctCount += 1;
                this.state.streak += 1;
            } else {
                this.state.score = Math.max(0, this.state.score - this.rules.scoring.wrongPenalty);
                this.state.wrongCount += 1;
                this.state.streak = 0;
            }

            this.state.history.push({
                sampleId: this.state.currentSample.id,
                difficulty: this.state.currentSample.difficulty || this.state.difficulty,
                selectedRule: this.state.selectedRule,
                selectedLabel: this.state.selectedLabel,
                answerRule: this.state.currentSample.answerRule,
                answerLabel: this.state.currentSample.answerLabel,
                correct: ok,
                gained,
            });

            this.render();
            this.setFeedback(ok
                ? `정답입니다. +${gained}점 ${this.state.currentSample.explanation}`
                : `오답입니다. ${this.state.currentSample.explanation}`);
            window.setTimeout(() => this.nextRankedRound(), 1400);
        }

        async finishRanked() {
            this.state.status = "finished";
            this.state.durationSeconds = this.elapsedSeconds();
            this.bestScore = Math.max(this.bestScore, this.state.score);
            window.localStorage.setItem(STORAGE_KEY, String(this.bestScore));
            this.render();
            this.setFeedback(`도전 완료. ${this.state.correctCount}/${this.rules.rankedRounds}문제를 맞혔고 점수는 ${this.state.score}점입니다.`);

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

        render() {
            this.fields.difficulty.value = this.state.difficulty;
            this.root.querySelectorAll("[data-play-type]").forEach((button) => {
                const active = button.dataset.playType === this.state.playType;
                button.classList.toggle("is-active", active);
                button.setAttribute("aria-selected", active ? "true" : "false");
            });
            this.renderStats();
            this.renderProblem();
            this.renderLeaderboard();
            this.renderSubmitButton();
        }

        renderStats() {
            this.fields.score.textContent = String(this.state.score);
            this.fields.progressLabel.textContent = this.state.playType === "ranked" ? "도전 라운드" : "연습 번호";
            this.fields.round.textContent = this.state.playType === "ranked"
                ? `${Math.max(0, this.state.round)} / ${this.rules.rankedRounds}`
                : String(this.state.round);
            this.fields.correct.textContent = String(this.state.correctCount);
            this.fields.rank.textContent = this.state.myRank ? `${this.state.myRank}위` : "-";
        }

        renderProblem() {
            if (this.state.status === "ranked-idle") {
                this.fields.sampleMeta.textContent = "도전 대기";
                this.fields.sampleTitle.textContent = `${this.rules.rankedRounds}라운드 AI 분류 경계`;
                this.fields.context.textContent = "도전 시작을 누르면 학습 데이터와 새 데이터가 나타납니다.";
                this.fields.chart.innerHTML = "";
                this.fields.rules.innerHTML = "";
                this.fields.labels.innerHTML = "";
                this.fields.selectionStatus.textContent = "규칙 미선택";
                return;
            }

            if (this.state.status === "finished") {
                this.fields.sampleMeta.textContent = "도전 완료";
                this.fields.sampleTitle.textContent = "결과가 기록되었습니다";
                this.fields.context.textContent = `정답 ${this.state.correctCount}개, 오답 ${this.state.wrongCount}개, 최고 점수 ${this.bestScore}점`;
                this.fields.chart.innerHTML = "";
                this.fields.rules.innerHTML = "";
                this.fields.labels.innerHTML = "";
                this.fields.selectionStatus.textContent = "완료";
                return;
            }

            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const difficulty = this.state.playType === "ranked"
                ? (this.rules.difficulties[sample.difficulty] || this.getDifficulty())
                : this.getDifficulty();
            this.fields.sampleMeta.textContent = `${difficulty.label || ""} · 지도학습`;
            this.fields.sampleTitle.textContent = sample.title || "";
            this.fields.context.textContent = sample.context || "";
            this.renderChart();
            this.renderRuleOptions();
            this.renderLabelOptions();
            this.renderSelectionStatus();
        }

        renderChart() {
            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const width = 540;
            const height = 350;
            const left = 56;
            const right = 24;
            const top = 24;
            const bottom = 52;
            const chartWidth = width - left - right;
            const chartHeight = height - top - bottom;
            const xs = [...sample.points.map((point) => point.x), sample.query.x];
            const ys = [...sample.points.map((point) => point.y), sample.query.y];
            const minX = Math.min(...xs, 0);
            const maxX = Math.max(...xs, 10);
            const minY = Math.min(...ys, 0);
            const maxY = Math.max(...ys, 10);
            const rangeX = Math.max(1, maxX - minX);
            const rangeY = Math.max(1, maxY - minY);
            const sx = (x) => left + (((x - minX) / rangeX) * chartWidth);
            const sy = (y) => top + chartHeight - (((y - minY) / rangeY) * chartHeight);
            const axisBottom = height - bottom;
            const axisRight = width - right;
            const xTicks = this.axisTicks(minX, maxX);
            const yTicks = this.axisTicks(minY, maxY);
            const selectedRule = sample.rules.find((rule) => rule.id === this.state.selectedRule)
                || (this.state.answered ? sample.rules.find((rule) => rule.id === sample.answerRule) : null);
            const bounds = { minX, maxX, minY, maxY, left, right, top, bottom, width, height, axisBottom, axisRight };
            const boundary = selectedRule ? this.boundaryGuide(selectedRule, bounds, sx, sy) : "";

            this.fields.chart.innerHTML = `
                <svg class="oj-ai-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="AI 분류 산점도">
                    ${xTicks.map((tick) => `
                        <line x1="${sx(tick)}" y1="${top}" x2="${sx(tick)}" y2="${axisBottom}" class="oj-ai-grid"></line>
                        <line x1="${sx(tick)}" y1="${axisBottom}" x2="${sx(tick)}" y2="${axisBottom + 6}" class="oj-ai-tick"></line>
                        <text x="${sx(tick)}" y="${axisBottom + 21}" class="oj-ai-tick-label">${escapeHtml(this.formatAxisValue(tick))}</text>
                    `).join("")}
                    ${yTicks.map((tick) => `
                        <line x1="${left}" y1="${sy(tick)}" x2="${axisRight}" y2="${sy(tick)}" class="oj-ai-grid"></line>
                        <line x1="${left - 6}" y1="${sy(tick)}" x2="${left}" y2="${sy(tick)}" class="oj-ai-tick"></line>
                        <text x="${left - 10}" y="${sy(tick) + 4}" class="oj-ai-tick-label oj-ai-tick-label-y">${escapeHtml(this.formatAxisValue(tick))}</text>
                    `).join("")}
                    <line x1="${left}" y1="${top}" x2="${left}" y2="${axisBottom}" class="oj-ai-axis"></line>
                    <line x1="${left}" y1="${axisBottom}" x2="${axisRight}" y2="${axisBottom}" class="oj-ai-axis"></line>
                    <text x="${width / 2}" y="${height - 14}" class="oj-ai-axis-label">${escapeHtml(sample.xLabel || "X")}</text>
                    <text x="14" y="${top + 12}" class="oj-ai-axis-label">${escapeHtml(sample.yLabel || "Y")}</text>
                    ${boundary}
                    ${sample.points.map((point) => `
                        <circle cx="${sx(point.x)}" cy="${sy(point.y)}" r="8" class="oj-ai-point is-${escapeHtml(point.label)}"></circle>
                    `).join("")}
                    <polygon points="${sx(sample.query.x)},${sy(sample.query.y) - 12} ${sx(sample.query.x) + 12},${sy(sample.query.y)} ${sx(sample.query.x)},${sy(sample.query.y) + 12} ${sx(sample.query.x) - 12},${sy(sample.query.y)}" class="oj-ai-query"></polygon>
                    <text x="${sx(sample.query.x)}" y="${sy(sample.query.y) - 18}" class="oj-ai-query-label">새 데이터</text>
                </svg>
                <div class="oj-ai-legend">
                    ${Object.entries(sample.labels).map(([label, text]) => `<span><i class="is-${escapeHtml(label)}"></i>${escapeHtml(text)}</span>`).join("")}
                    <span><i class="is-query"></i>새 데이터</span>
                </div>
            `;
        }

        axisTicks(min, max) {
            const lower = Math.floor(min);
            const upper = Math.ceil(max);
            const span = Math.max(1, upper - lower);
            const step = span <= 10 ? 1 : Math.ceil(span / 5);
            const ticks = [];
            for (let value = lower; value <= upper; value += step) {
                ticks.push(value);
            }
            if (!ticks.includes(upper)) {
                ticks.push(upper);
            }
            return ticks;
        }

        formatAxisValue(value) {
            return Number.isInteger(value) ? String(value) : String(Math.round(value * 10) / 10);
        }

        clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        ruleMarker(rule) {
            const threshold = this.formatAxisValue(Number(rule.threshold));
            if (rule.lineType === "vertical") {
                return `x = ${threshold}`;
            }
            if (rule.lineType === "horizontal") {
                return `y = ${threshold}`;
            }
            if (rule.lineType === "diagonal-neg") {
                return `x + y = ${threshold}`;
            }
            if (rule.lineType === "diagonal-pos") {
                return `y - x = ${threshold}`;
            }
            return `기준 ${threshold}`;
        }

        boundaryGuide(rule, bounds, sx, sy) {
            const threshold = Number(rule.threshold);
            const marker = this.ruleMarker(rule);
            if (rule.lineType === "vertical") {
                const x = sx(threshold);
                return `
                    <line x1="${x}" y1="${bounds.top}" x2="${x}" y2="${bounds.axisBottom}" class="oj-ai-boundary"></line>
                    <text x="${x}" y="${bounds.axisBottom - 10}" class="oj-ai-boundary-label">${escapeHtml(marker)}</text>
                `;
            }
            if (rule.lineType === "horizontal") {
                const y = sy(threshold);
                return `
                    <line x1="${bounds.left}" y1="${y}" x2="${bounds.axisRight}" y2="${y}" class="oj-ai-boundary"></line>
                    <text x="${bounds.left + 42}" y="${y - 10}" class="oj-ai-boundary-label">${escapeHtml(marker)}</text>
                `;
            }
            if (rule.lineType === "diagonal-neg") {
                let labelX = (bounds.minX + bounds.maxX) / 2;
                let labelY = threshold - labelX;
                if (labelY < bounds.minY || labelY > bounds.maxY) {
                    labelY = (bounds.minY + bounds.maxY) / 2;
                    labelX = threshold - labelY;
                }
                labelX = this.clamp(labelX, bounds.minX, bounds.maxX);
                labelY = this.clamp(labelY, bounds.minY, bounds.maxY);
                return `
                    <line x1="${sx(bounds.minX)}" y1="${sy(threshold - bounds.minX)}" x2="${sx(bounds.maxX)}" y2="${sy(threshold - bounds.maxX)}" class="oj-ai-boundary"></line>
                    <text x="${sx(labelX)}" y="${sy(labelY) - 10}" class="oj-ai-boundary-label">${escapeHtml(marker)}</text>
                `;
            }
            if (rule.lineType === "diagonal-pos") {
                let labelX = (bounds.minX + bounds.maxX) / 2;
                let labelY = labelX + threshold;
                if (labelY < bounds.minY || labelY > bounds.maxY) {
                    labelY = (bounds.minY + bounds.maxY) / 2;
                    labelX = labelY - threshold;
                }
                labelX = this.clamp(labelX, bounds.minX, bounds.maxX);
                labelY = this.clamp(labelY, bounds.minY, bounds.maxY);
                return `
                    <line x1="${sx(bounds.minX)}" y1="${sy(bounds.minX + threshold)}" x2="${sx(bounds.maxX)}" y2="${sy(bounds.maxX + threshold)}" class="oj-ai-boundary"></line>
                    <text x="${sx(labelX)}" y="${sy(labelY) - 10}" class="oj-ai-boundary-label">${escapeHtml(marker)}</text>
                `;
            }

            return "";
        }

        renderRuleOptions() {
            const sample = this.state.currentSample;
            this.fields.rules.innerHTML = sample.rules.map((rule) => {
                const classes = [
                    "oj-ai-rule",
                    this.state.selectedRule === rule.id ? "is-selected" : "",
                    this.state.answered && sample.answerRule === rule.id ? "is-answer" : "",
                ].filter(Boolean).join(" ");
                return `
                    <button type="button" class="${classes}" data-rule-id="${escapeHtml(rule.id)}" ${this.state.answered ? "disabled" : ""}>
                        <span>${escapeHtml(rule.label)}</span>
                        <small>${escapeHtml(this.ruleMarker(rule))}</small>
                    </button>
                `;
            }).join("");
        }

        renderLabelOptions() {
            const sample = this.state.currentSample;
            this.fields.labels.innerHTML = Object.entries(sample.labels).map(([label, text]) => {
                const classes = [
                    "oj-ai-label",
                    `is-${label}`,
                    this.state.selectedLabel === label ? "is-selected" : "",
                    this.state.answered && sample.answerLabel === label ? "is-answer" : "",
                ].filter(Boolean).join(" ");
                return `<button type="button" class="${classes}" data-label-id="${escapeHtml(label)}" ${this.state.answered ? "disabled" : ""}>${escapeHtml(text)}</button>`;
            }).join("");
        }

        renderSelectionStatus() {
            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const rule = sample.rules.find((item) => item.id === this.state.selectedRule);
            const label = this.state.selectedLabel ? sample.labels[this.state.selectedLabel] : "";
            if (rule && label) {
                this.fields.selectionStatus.textContent = "규칙 + 예측 선택";
            } else if (rule) {
                this.fields.selectionStatus.textContent = "규칙 선택";
            } else if (label) {
                this.fields.selectionStatus.textContent = "예측 선택";
            } else {
                this.fields.selectionStatus.textContent = "규칙 미선택";
            }
        }

        renderSubmitButton() {
            if (this.state.status === "ranked-idle") {
                this.fields.submitButton.textContent = "도전 시작";
                this.fields.submitButton.disabled = false;
                return;
            }

            if (this.state.status === "finished") {
                this.fields.submitButton.textContent = "다시 도전";
                this.fields.submitButton.disabled = this.state.isSaving;
                return;
            }

            if (this.state.playType === "practice" && this.state.answered) {
                this.fields.submitButton.textContent = "다음 연습";
                this.fields.submitButton.disabled = false;
                return;
            }

            this.fields.submitButton.textContent = "예측 제출";
            this.fields.submitButton.disabled = this.state.isSaving || this.state.answered;
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
            this.renderSubmitButton();

            const attempts = this.state.correctCount + this.state.wrongCount;
            const payload = {
                score: this.state.score,
                roundsPlayed: attempts,
                correctCount: this.state.correctCount,
                durationSeconds: this.state.durationSeconds,
                accuracy: attempts ? Math.round((this.state.correctCount / attempts) * 10000) / 100 : 0,
                meta: {
                    difficulty: this.state.difficulty,
                    history: this.state.history,
                    wrongCount: this.state.wrongCount,
                    bestScore: this.bestScore,
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
                this.renderSubmitButton();
            }
        }

        renderLeaderboard() {
            if (!this.fields.leaderboard) {
                return;
            }

            if (!this.state.leaderboard.length) {
                this.fields.rankStatus.textContent = "기록 없음";
                this.fields.leaderboard.innerHTML = '<div class="oj-ai-rank-empty">아직 저장된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.fields.rankStatus.textContent = this.state.myRank ? `${this.state.myRank}위` : "전체 참여자";
            this.fields.leaderboard.innerHTML = `
                <div class="oj-ai-rank-head">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.state.leaderboard.map((row) => `
                    <div data-rank-row class="oj-ai-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
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
        document.querySelectorAll("[data-ai-classifier-lab]").forEach((root) => {
            if (!root.__AIClassifierLab) {
                root.__AIClassifierLab = new AIClassifierLabGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
