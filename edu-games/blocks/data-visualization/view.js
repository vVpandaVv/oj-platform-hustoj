(() => {
    const STORAGE_KEY = "edu-games-data-visualization-best";
    const COLORS = ["#1d6fd8", "#1f8a55", "#e65d4f", "#f2b830", "#7c5ce0", "#0e9aa7", "#d96b27"];

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

    class DataVisualizationGame {
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
                chartStatus: root.querySelector('[data-field="chart-status"]'),
                context: root.querySelector('[data-field="context"]'),
                table: root.querySelector('[data-field="table"]'),
                chart: root.querySelector('[data-field="chart"]'),
                chartTypes: root.querySelector('[data-field="chart-types"]'),
                insights: root.querySelector('[data-field="insights"]'),
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
            const chartTypes = rules.chartTypes && Object.keys(rules.chartTypes).length ? rules.chartTypes : {
                bar: "막대그래프",
                line: "꺾은선그래프",
                pie: "원그래프",
                scatter: "산점도",
                histogram: "히스토그램",
            };

            return {
                rankedRounds: Math.max(4, Number(rules.rankedRounds) || 10),
                defaultDifficulty: rules.defaultDifficulty && difficulties[rules.defaultDifficulty]
                    ? rules.defaultDifficulty
                    : Object.keys(difficulties)[0],
                chartTypes,
                difficulties,
                samples: Array.isArray(rules.samples) ? rules.samples : [],
                scoring: {
                    streakBonus: Number(rules.scoring && rules.scoring.streakBonus) || 10,
                    maxStreakBonus: Number(rules.scoring && rules.scoring.maxStreakBonus) || 70,
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
                selectedChart: "",
                selectedInsight: "",
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

                const chartButton = event.target.closest("[data-chart-type]");
                if (chartButton) {
                    this.selectChart(chartButton.dataset.chartType);
                    return;
                }

                const insightButton = event.target.closest("[data-insight]");
                if (insightButton) {
                    this.selectInsight(insightButton.dataset.insight);
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
            this.state.selectedChart = "";
            this.state.selectedInsight = "";
            this.state.answered = false;
            this.state.currentSample = this.pickSample();
            this.render();
            this.setFeedback("자료의 목적을 보고 알맞은 차트 유형과 그래프에서 읽을 수 있는 해석을 고르세요.");
        }

        prepareRankedIdle() {
            this.state.playType = "ranked";
            this.state.status = "ranked-idle";
            this.state.score = 0;
            this.state.round = 0;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedChart = "";
            this.state.selectedInsight = "";
            this.state.answered = false;
            this.state.startedAt = 0;
            this.state.durationSeconds = 0;
            this.state.history = [];
            this.state.currentSample = null;
            this.render();
            this.setFeedback(`도전은 ${this.rules.rankedRounds}라운드입니다. 차트 유형과 해석을 모두 맞혀야 점수를 얻습니다.`);
        }

        startRanked() {
            this.state.playType = "ranked";
            this.state.status = "ranked";
            this.state.score = 0;
            this.state.round = 1;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedChart = "";
            this.state.selectedInsight = "";
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
            this.setFeedback("도전이 시작되었습니다. 표를 먼저 읽고 차트의 목적을 판단하세요.");
        }

        nextRankedRound() {
            if (this.state.round >= this.rules.rankedRounds) {
                this.finishRanked();
                return;
            }

            this.state.round += 1;
            this.state.selectedChart = "";
            this.state.selectedInsight = "";
            this.state.answered = false;
            this.state.currentSample = this.pickRankedSample();
            this.render();
            this.setFeedback("다음 자료입니다. 비교, 변화, 비율, 분포, 관계 중 무엇을 보려는지 먼저 찾으세요.");
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
                labels: Array.isArray(sample && sample.labels) ? [...sample.labels] : [],
                values: Array.isArray(sample && sample.values) ? sample.values.map(Number) : [],
                points: Array.isArray(sample && sample.points)
                    ? sample.points.map((point) => ({ x: Number(point.x), y: Number(point.y) }))
                    : [],
                insightOptions: shuffle(Array.isArray(sample && sample.insightOptions) ? sample.insightOptions : []),
            };
        }

        getDifficulty() {
            return this.rules.difficulties[this.state.difficulty] || Object.values(this.rules.difficulties)[0];
        }

        selectChart(chartType) {
            if (!this.state.currentSample || this.state.status === "ranked-idle" || this.state.answered) {
                return;
            }

            this.state.selectedChart = chartType;
            this.renderChartTypes();
            this.renderChart();
            this.renderChartStatus();
        }

        selectInsight(insight) {
            if (!this.state.currentSample || this.state.status === "ranked-idle" || this.state.answered) {
                return;
            }

            this.state.selectedInsight = insight;
            this.renderInsights();
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

            if (!this.state.currentSample) {
                return;
            }

            if (!this.state.selectedChart) {
                this.setFeedback("먼저 차트 유형을 선택하세요.");
                return;
            }

            if (!this.state.selectedInsight) {
                this.setFeedback("그래프에서 읽을 수 있는 해석도 선택하세요.");
                return;
            }

            const ok = this.isCorrectAnswer();
            if (this.state.playType === "practice") {
                this.handlePracticeSubmit(ok);
                return;
            }

            this.handleRankedSubmit(ok);
        }

        handlePracticeSubmit(ok) {
            if (!ok) {
                this.state.wrongCount += 1;
                this.renderStats();
                this.setFeedback("아직 맞지 않습니다. 자료가 비교, 변화, 비율, 분포, 관계 중 무엇을 보여주려는지 다시 확인하세요.");
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
                chart: this.state.selectedChart,
                insight: this.state.selectedInsight,
                expectedChart: this.state.currentSample.chartType,
                expectedInsight: this.state.currentSample.insight,
                correct: ok,
                gained,
            });

            this.render();
            this.setFeedback(ok
                ? `정답입니다. +${gained}점 ${this.state.currentSample.explanation}`
                : `오답입니다. ${this.state.currentSample.explanation}`);

            window.setTimeout(() => this.nextRankedRound(), 1400);
        }

        isCorrectAnswer() {
            return this.state.selectedChart === this.state.currentSample.chartType
                && this.state.selectedInsight === this.state.currentSample.insight;
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
                this.fields.sampleTitle.textContent = `${this.rules.rankedRounds}라운드 데이터 시각화`;
                this.fields.context.textContent = "도전 시작을 누르면 표 자료를 보고 차트 유형과 해석을 고르는 문제가 나옵니다.";
                this.fields.table.innerHTML = "";
                this.fields.chart.innerHTML = "";
                this.fields.chartTypes.innerHTML = "";
                this.fields.insights.innerHTML = "";
                this.fields.chartStatus.textContent = "차트 미선택";
                return;
            }

            if (this.state.status === "finished") {
                this.fields.sampleMeta.textContent = "도전 완료";
                this.fields.sampleTitle.textContent = "결과가 기록되었습니다";
                this.fields.context.textContent = `정답 ${this.state.correctCount}개, 오답 ${this.state.wrongCount}개, 최고 점수 ${this.bestScore}점`;
                this.fields.table.innerHTML = "";
                this.fields.chart.innerHTML = "";
                this.fields.chartTypes.innerHTML = "";
                this.fields.insights.innerHTML = "";
                this.fields.chartStatus.textContent = "완료";
                return;
            }

            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const difficulty = this.state.playType === "ranked"
                ? (this.rules.difficulties[sample.difficulty] || this.getDifficulty())
                : this.getDifficulty();
            this.fields.sampleMeta.textContent = `${difficulty.label || ""} · ${this.describeDataKind(sample.dataKind)}`;
            this.fields.sampleTitle.textContent = sample.title || "";
            this.fields.context.textContent = sample.context || "";
            this.renderTable(sample);
            this.renderChart();
            this.renderChartTypes();
            this.renderInsights();
            this.renderChartStatus();
        }

        renderTable(sample) {
            if (sample.dataKind === "relationship") {
                this.fields.table.innerHTML = `
                    <table class="oj-visual-table">
                        <thead>
                            <tr>
                                <th>번호</th>
                                <th>${escapeHtml(sample.xLabel || "X")}</th>
                                <th>${escapeHtml(sample.yLabel || "Y")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${sample.points.map((point, index) => `
                                <tr>
                                    <th scope="row">${index + 1}</th>
                                    <td>${this.formatNumber(point.x)}</td>
                                    <td>${this.formatNumber(point.y)}</td>
                                </tr>
                            `).join("")}
                        </tbody>
                    </table>
                `;
                return;
            }

            this.fields.table.innerHTML = `
                <table class="oj-visual-table">
                    <thead>
                        <tr>
                            <th>항목</th>
                            <th>값</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${sample.values.map((value, index) => `
                            <tr>
                                <th scope="row">${escapeHtml(sample.labels[index] || `${index + 1}번`)}</th>
                                <td>${this.formatValue(value, sample.unit)}</td>
                            </tr>
                        `).join("")}
                    </tbody>
                </table>
            `;
        }

        renderChartTypes() {
            const answer = this.state.currentSample ? this.state.currentSample.chartType : "";
            this.fields.chartTypes.innerHTML = Object.entries(this.rules.chartTypes).map(([chartType, label]) => {
                const classes = [
                    "oj-visual-chart-type",
                    this.state.selectedChart === chartType ? "is-selected" : "",
                    this.state.answered && answer === chartType ? "is-answer" : "",
                ].filter(Boolean).join(" ");
                return `<button type="button" class="${classes}" data-chart-type="${escapeHtml(chartType)}" ${this.state.answered ? "disabled" : ""}>${escapeHtml(label)}</button>`;
            }).join("");
        }

        renderInsights() {
            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            this.fields.insights.innerHTML = sample.insightOptions.map((insight) => {
                const classes = [
                    "oj-visual-insight",
                    this.state.selectedInsight === insight ? "is-selected" : "",
                    this.state.answered && sample.insight === insight ? "is-answer" : "",
                ].filter(Boolean).join(" ");
                return `<button type="button" class="${classes}" data-insight="${escapeHtml(insight)}" ${this.state.answered ? "disabled" : ""}>${escapeHtml(insight)}</button>`;
            }).join("");
        }

        renderChartStatus() {
            if (!this.state.selectedChart) {
                this.fields.chartStatus.textContent = "차트 미선택";
                return;
            }

            this.fields.chartStatus.textContent = this.rules.chartTypes[this.state.selectedChart] || this.state.selectedChart;
        }

        renderChart() {
            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const chartType = this.state.selectedChart || sample.chartType;
            const title = this.state.selectedChart
                ? (this.rules.chartTypes[chartType] || chartType)
                : "미리보기";
            let chart = "";

            if (chartType === "bar") {
                chart = this.renderBarChart(sample, false);
            } else if (chartType === "line") {
                chart = this.renderLineChart(sample);
            } else if (chartType === "pie") {
                chart = this.renderPieChart(sample);
            } else if (chartType === "scatter") {
                chart = this.renderScatterChart(sample);
            } else if (chartType === "histogram") {
                chart = this.renderBarChart(sample, true);
            }

            this.fields.chart.innerHTML = `
                <div class="oj-visual-chart-title">
                    <span>${escapeHtml(title)}</span>
                    ${this.state.selectedChart ? "" : "<em>차트 유형을 선택하면 같은 자료가 해당 방식으로 그려집니다.</em>"}
                </div>
                ${chart}
            `;
        }

        getSeries(sample) {
            if (sample.dataKind === "relationship") {
                return {
                    labels: sample.points.map((point, index) => `${index + 1}`),
                    values: sample.points.map((point) => Number(point.y)),
                };
            }

            return {
                labels: sample.labels || [],
                values: sample.values || [],
            };
        }

        renderBarChart(sample, histogram) {
            const series = this.getSeries(sample);
            const width = 520;
            const height = 280;
            const left = 44;
            const bottom = 42;
            const top = 20;
            const chartWidth = width - left - 18;
            const chartHeight = height - top - bottom;
            const max = Math.max(1, ...series.values);
            const barWidth = chartWidth / Math.max(1, series.values.length);
            const gap = histogram ? 1 : Math.min(14, barWidth * 0.22);

            return `
                <svg class="oj-visual-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="막대형 차트">
                    <line x1="${left}" y1="${top}" x2="${left}" y2="${height - bottom}" class="oj-visual-axis"></line>
                    <line x1="${left}" y1="${height - bottom}" x2="${width - 18}" y2="${height - bottom}" class="oj-visual-axis"></line>
                    ${series.values.map((value, index) => {
                        const barHeight = (Number(value) / max) * chartHeight;
                        const x = left + (index * barWidth) + (gap / 2);
                        const y = (height - bottom) - barHeight;
                        const w = Math.max(6, barWidth - gap);
                        return `
                            <rect x="${x}" y="${y}" width="${w}" height="${barHeight}" rx="${histogram ? 0 : 6}" class="oj-visual-bar" style="--bar-color:${COLORS[index % COLORS.length]}"></rect>
                            <text x="${x + (w / 2)}" y="${Math.max(14, y - 6)}" class="oj-visual-value-label">${this.formatNumber(value)}</text>
                            <text x="${x + (w / 2)}" y="${height - 16}" class="oj-visual-x-label">${escapeHtml(series.labels[index] || "")}</text>
                        `;
                    }).join("")}
                </svg>
            `;
        }

        renderLineChart(sample) {
            const series = this.getSeries(sample);
            const width = 520;
            const height = 280;
            const left = 44;
            const bottom = 42;
            const top = 20;
            const chartWidth = width - left - 20;
            const chartHeight = height - top - bottom;
            const min = Math.min(...series.values);
            const max = Math.max(...series.values);
            const range = Math.max(1, max - min);
            const points = series.values.map((value, index) => {
                const x = left + (index * (chartWidth / Math.max(1, series.values.length - 1)));
                const y = top + chartHeight - (((value - min) / range) * chartHeight);
                return { x, y, value, label: series.labels[index] || "" };
            });

            return `
                <svg class="oj-visual-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="꺾은선 차트">
                    <line x1="${left}" y1="${top}" x2="${left}" y2="${height - bottom}" class="oj-visual-axis"></line>
                    <line x1="${left}" y1="${height - bottom}" x2="${width - 18}" y2="${height - bottom}" class="oj-visual-axis"></line>
                    <polyline points="${points.map((point) => `${point.x},${point.y}`).join(" ")}" class="oj-visual-line"></polyline>
                    ${points.map((point) => `
                        <circle cx="${point.x}" cy="${point.y}" r="6" class="oj-visual-dot"></circle>
                        <text x="${point.x}" y="${Math.max(14, point.y - 10)}" class="oj-visual-value-label">${this.formatNumber(point.value)}</text>
                        <text x="${point.x}" y="${height - 16}" class="oj-visual-x-label">${escapeHtml(point.label)}</text>
                    `).join("")}
                </svg>
            `;
        }

        renderPieChart(sample) {
            const series = this.getSeries(sample);
            const total = series.values.reduce((sum, value) => sum + Number(value), 0) || 1;
            let cursor = 0;
            const stops = series.values.map((value, index) => {
                const start = (cursor / total) * 100;
                cursor += Number(value);
                const end = (cursor / total) * 100;
                return `${COLORS[index % COLORS.length]} ${start}% ${end}%`;
            }).join(", ");

            return `
                <div class="oj-visual-pie-wrap">
                    <div class="oj-visual-pie" style="background: conic-gradient(${stops});"></div>
                    <div class="oj-visual-legend">
                        ${series.values.map((value, index) => {
                            const percent = Math.round((Number(value) / total) * 1000) / 10;
                            return `
                                <span><i style="background:${COLORS[index % COLORS.length]}"></i>${escapeHtml(series.labels[index] || "")} ${percent}%</span>
                            `;
                        }).join("")}
                    </div>
                </div>
            `;
        }

        renderScatterChart(sample) {
            if (sample.dataKind !== "relationship" || !sample.points.length) {
                return '<div class="oj-visual-chart-empty">산점도는 두 숫자 변수가 함께 있는 자료에 가장 잘 맞습니다.</div>';
            }

            const width = 520;
            const height = 280;
            const left = 54;
            const bottom = 46;
            const top = 18;
            const chartWidth = width - left - 24;
            const chartHeight = height - top - bottom;
            const xs = sample.points.map((point) => point.x);
            const ys = sample.points.map((point) => point.y);
            const minX = Math.min(...xs);
            const maxX = Math.max(...xs);
            const minY = Math.min(...ys);
            const maxY = Math.max(...ys);
            const rangeX = Math.max(1, maxX - minX);
            const rangeY = Math.max(1, maxY - minY);

            return `
                <svg class="oj-visual-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="산점도">
                    <line x1="${left}" y1="${top}" x2="${left}" y2="${height - bottom}" class="oj-visual-axis"></line>
                    <line x1="${left}" y1="${height - bottom}" x2="${width - 18}" y2="${height - bottom}" class="oj-visual-axis"></line>
                    <text x="${width / 2}" y="${height - 10}" class="oj-visual-axis-label">${escapeHtml(sample.xLabel || "X")}</text>
                    <text x="12" y="${top + 12}" class="oj-visual-axis-label">${escapeHtml(sample.yLabel || "Y")}</text>
                    ${sample.points.map((point, index) => {
                        const x = left + (((point.x - minX) / rangeX) * chartWidth);
                        const y = top + chartHeight - (((point.y - minY) / rangeY) * chartHeight);
                        return `
                            <circle cx="${x}" cy="${y}" r="7" class="oj-visual-scatter-dot" style="--dot-color:${COLORS[index % COLORS.length]}"></circle>
                            <text x="${x}" y="${y - 11}" class="oj-visual-value-label">${index + 1}</text>
                        `;
                    }).join("")}
                </svg>
            `;
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

            this.fields.submitButton.textContent = "시각화 결과 제출";
            this.fields.submitButton.disabled = this.state.isSaving || this.state.answered;
        }

        describeDataKind(kind) {
            const labels = {
                category: "항목 비교",
                distribution: "구간 분포",
                relationship: "두 변수 관계",
            };

            return labels[kind] || "자료";
        }

        formatNumber(value) {
            const number = Number(value);
            return Number.isInteger(number) ? String(number) : number.toFixed(1);
        }

        formatValue(value, unit) {
            return `${this.formatNumber(value)}${unit ? ` ${unit}` : ""}`;
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
                this.fields.leaderboard.innerHTML = '<div class="oj-visual-rank-empty">아직 저장된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.fields.rankStatus.textContent = this.state.myRank ? `${this.state.myRank}위` : "전체 참여자";
            this.fields.leaderboard.innerHTML = `
                <div class="oj-visual-rank-head">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.state.leaderboard.map((row) => `
                    <div data-rank-row class="oj-visual-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
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
        document.querySelectorAll("[data-data-visualization]").forEach((root) => {
            if (!root.__DataVisualization) {
                root.__DataVisualization = new DataVisualizationGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
