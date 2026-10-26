(() => {
    const STORAGE_KEY = "edu-games-data-extraction-best";

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

    class DataExtractionGame {
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
                selectionCount: root.querySelector('[data-field="selection-count"]'),
                context: root.querySelector('[data-field="context"]'),
                table: root.querySelector('[data-field="table"]'),
                processingOptions: root.querySelector('[data-field="processing-options"]'),
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
            const samples = Array.isArray(rules.samples) ? rules.samples : [];

            return {
                rankedRounds: Math.max(3, Number(rules.rankedRounds) || 6),
                defaultDifficulty: rules.defaultDifficulty && difficulties[rules.defaultDifficulty]
                    ? rules.defaultDifficulty
                    : Object.keys(difficulties)[0],
                difficulties,
                samples,
                scoring: {
                    streakBonus: Number(rules.scoring && rules.scoring.streakBonus) || 12,
                    maxStreakBonus: Number(rules.scoring && rules.scoring.maxStreakBonus) || 72,
                    wrongPenalty: Number(rules.scoring && rules.scoring.wrongPenalty) || 20,
                    minimumCorrectScore: Number(rules.scoring && rules.scoring.minimumCorrectScore) || 40,
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
                selectedColumns: [],
                selectedProcessing: "",
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

                const columnButton = event.target.closest("button[data-column-key]");
                if (columnButton) {
                    this.toggleColumn(columnButton.dataset.columnKey);
                    return;
                }

                const processingButton = event.target.closest("[data-processing-key]");
                if (processingButton) {
                    this.selectProcessing(processingButton.dataset.processingKey);
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
            this.state.selectedColumns = [];
            this.state.selectedProcessing = "";
            this.state.answered = false;
            this.state.currentSample = this.pickSample();
            this.render();
            this.setFeedback("문제 해결에 필요한 열을 모두 선택하고, 데이터를 어떻게 활용할지 고른 뒤 제출하세요. 연습은 틀려도 다시 고를 수 있습니다.");
        }

        prepareRankedIdle() {
            this.state.playType = "ranked";
            this.state.status = "ranked-idle";
            this.state.score = 0;
            this.state.round = 0;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedColumns = [];
            this.state.selectedProcessing = "";
            this.state.answered = false;
            this.state.startedAt = 0;
            this.state.durationSeconds = 0;
            this.state.history = [];
            this.state.currentSample = null;
            this.render();
            this.setFeedback(`도전은 ${this.rules.rankedRounds}라운드입니다. 필요한 열과 활용 방법을 모두 맞혀야 점수를 얻습니다.`);
        }

        startRanked() {
            this.state.playType = "ranked";
            this.state.status = "ranked";
            this.state.score = 0;
            this.state.round = 1;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedColumns = [];
            this.state.selectedProcessing = "";
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
            this.setFeedback("도전이 시작되었습니다. 표를 보고 필요한 열을 골라보세요.");
        }

        nextRankedRound() {
            if (this.state.round >= this.rules.rankedRounds) {
                this.finishRanked();
                return;
            }

            this.state.round += 1;
            this.state.selectedColumns = [];
            this.state.selectedProcessing = "";
            this.state.answered = false;
            this.state.currentSample = this.pickRankedSample();
            this.render();
            this.setFeedback("다음 문제입니다. 어떤 열이 진짜 필요한지 먼저 살펴보세요.");
        }

        pickSample() {
            const difficulty = this.getDifficulty();
            const ids = Array.isArray(difficulty.samples) ? difficulty.samples : [];
            const candidates = this.rules.samples.filter((sample) => ids.includes(sample.id));
            const pool = candidates.length ? candidates : this.rules.samples;

            return this.cloneSample(pool[Math.floor(Math.random() * pool.length)]);
        }

        pickRankedSample() {
            const difficultyKeys = Object.keys(this.rules.difficulties);
            const preferred = this.state.round <= 2
                ? "easy"
                : (this.state.round <= 5 ? this.state.difficulty : "hard");
            const nextDifficulty = this.rules.difficulties[preferred] ? preferred : this.state.difficulty;
            const previousIds = new Set(this.state.history.map((item) => item.sampleId));
            const difficulty = this.rules.difficulties[nextDifficulty] || this.getDifficulty();
            const ids = Array.isArray(difficulty.samples) ? difficulty.samples : [];
            let pool = this.rules.samples.filter((sample) => ids.includes(sample.id) && !previousIds.has(sample.id));

            if (!pool.length) {
                // Exhausted the fresh samples in this difficulty tier (this game
                // ships only two samples per tier) — fall back to allowing repeats
                // within the same tier rather than getting stuck with no pool.
                pool = this.rules.samples.filter((sample) => ids.includes(sample.id));
            }

            if (!pool.length && difficultyKeys.length) {
                pool = this.rules.samples;
            }

            const sample = this.cloneSample(pool[Math.floor(Math.random() * pool.length)]);
            sample.difficulty = nextDifficulty;

            return sample;
        }

        cloneSample(sample) {
            return {
                ...sample,
                columns: Array.isArray(sample && sample.columns) ? sample.columns.map((column) => ({ ...column })) : [],
                rows: Array.isArray(sample && sample.rows) ? sample.rows.map((row) => ({ ...row })) : [],
                relevantColumns: Array.isArray(sample && sample.relevantColumns) ? [...sample.relevantColumns] : [],
                processingOptions: shuffle(Array.isArray(sample && sample.processingOptions) ? sample.processingOptions.map((option) => ({ ...option })) : []),
            };
        }

        getDifficulty() {
            return this.rules.difficulties[this.state.difficulty] || Object.values(this.rules.difficulties)[0];
        }

        toggleColumn(columnKey) {
            if (!this.state.currentSample || this.state.status === "ranked-idle" || this.state.answered) {
                return;
            }

            if (this.state.selectedColumns.includes(columnKey)) {
                this.state.selectedColumns = this.state.selectedColumns.filter((key) => key !== columnKey);
            } else {
                this.state.selectedColumns = [...this.state.selectedColumns, columnKey];
            }

            this.renderColumnSelections();
        }

        selectProcessing(optionKey) {
            if (!this.state.currentSample || this.state.status === "ranked-idle" || this.state.answered) {
                return;
            }

            this.state.selectedProcessing = optionKey;
            this.renderProcessingOptions();
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

            if (this.state.playType === "ranked" && this.state.answered) {
                return;
            }

            if (!this.state.currentSample) {
                return;
            }

            if (!this.state.selectedColumns.length) {
                this.setFeedback("문제에 필요한 열을 먼저 선택하세요.");
                return;
            }

            if (!this.state.selectedProcessing) {
                this.setFeedback("추출한 데이터를 어떻게 활용할지도 선택하세요.");
                return;
            }

            const ok = this.isCorrectSelection();
            if (this.state.playType === "practice") {
                this.handlePracticeSubmit(ok);
                return;
            }

            this.handleRankedSubmit(ok);
        }

        handlePracticeSubmit(ok) {
            if (!ok) {
                this.state.wrongCount += 1;
                this.setFeedback("아직 맞지 않습니다. 문제와 직접 관련된 열과, 그 데이터로 무엇을 할지 다시 살펴보세요.");
                this.renderStats();
                return;
            }

            this.state.correctCount += 1;
            this.state.answered = true;
            this.revealAnswer();
            this.renderStats();
            this.renderSubmitButton();
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
                selectedColumns: this.state.selectedColumns,
                selectedProcessing: this.state.selectedProcessing,
                relevantColumns: this.state.currentSample.relevantColumns,
                correct: ok,
                gained,
            });

            this.revealAnswer();
            this.renderStats();
            this.renderSubmitButton();
            this.setFeedback(ok
                ? `정답입니다. +${gained}점. ${this.state.currentSample.explanation}`
                : `오답입니다. ${this.state.currentSample.explanation}`);

            window.setTimeout(() => this.nextRankedRound(), 1200);
        }

        isCorrectSelection() {
            const selected = [...this.state.selectedColumns].sort();
            const answer = [...this.state.currentSample.relevantColumns].sort();
            const columnsMatch = selected.length === answer.length && selected.every((value, index) => value === answer[index]);
            const correctOption = this.state.currentSample.processingOptions.find((option) => option.correct);
            const processingMatches = Boolean(correctOption) && this.state.selectedProcessing === correctOption.key;

            return columnsMatch && processingMatches;
        }

        revealAnswer() {
            const sample = this.state.currentSample;
            this.root.querySelectorAll("[data-column-key]").forEach((cell) => {
                const columnKey = cell.dataset.columnKey;
                const isRelevant = sample.relevantColumns.includes(columnKey);
                const isSelected = this.state.selectedColumns.includes(columnKey);
                cell.classList.remove("oj-extract-col-selected");
                cell.classList.toggle("oj-extract-col-answer", isRelevant);
                cell.classList.toggle("oj-extract-col-wrong", !isRelevant && isSelected);
            });
            this.root.querySelectorAll("button[data-column-key]").forEach((button) => {
                button.disabled = true;
            });
            this.renderProcessingOptions();
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
                this.fields.sampleTitle.textContent = `${this.rules.rankedRounds}라운드 데이터 추출`;
                this.fields.context.textContent = "도전 시작을 누르면 표에서 필요한 열을 고르고 활용 방법을 선택하는 문제가 나옵니다.";
                this.fields.table.innerHTML = "";
                this.fields.processingOptions.innerHTML = "";
                this.fields.selectionCount.textContent = "0개 선택";
                return;
            }

            if (this.state.status === "finished") {
                this.fields.sampleMeta.textContent = "도전 완료";
                this.fields.sampleTitle.textContent = "결과가 기록되었습니다";
                this.fields.context.textContent = `정답 ${this.state.correctCount}개, 오답 ${this.state.wrongCount}개, 최고 점수 ${this.bestScore}점`;
                this.fields.table.innerHTML = "";
                this.fields.processingOptions.innerHTML = "";
                this.fields.selectionCount.textContent = "0개 선택";
                return;
            }

            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const difficulty = this.state.playType === "ranked"
                ? (this.rules.difficulties[sample.difficulty] || this.getDifficulty())
                : this.getDifficulty();
            this.fields.sampleMeta.textContent = `${difficulty.label || ""} · ${sample.columns.length}개 열`;
            this.fields.sampleTitle.textContent = sample.title || "";
            this.fields.context.textContent = sample.context || "";
            this.renderTable(sample);
            this.renderProcessingOptions();
            this.renderColumnSelections();
        }

        renderTable(sample) {
            this.fields.table.innerHTML = `
                <table class="oj-extract-table">
                    <thead>
                        <tr>
                            ${sample.columns.map((column) => `
                                <th class="oj-extract-col-head">
                                    <button type="button" class="oj-extract-th-toggle" data-column-key="${escapeHtml(column.key)}">${escapeHtml(column.label)}</button>
                                </th>
                            `).join("")}
                        </tr>
                    </thead>
                    <tbody>
                        ${sample.rows.map((row) => `
                            <tr>
                                ${sample.columns.map((column) => `
                                    <td data-column-key="${escapeHtml(column.key)}">${escapeHtml(this.formatCell(row[column.key]))}</td>
                                `).join("")}
                            </tr>
                        `).join("")}
                    </tbody>
                </table>
            `;
        }

        renderColumnSelections() {
            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            this.fields.selectionCount.textContent = `${this.state.selectedColumns.length}개 선택`;
            this.root.querySelectorAll("[data-column-key]").forEach((cell) => {
                cell.classList.toggle("oj-extract-col-selected", this.state.selectedColumns.includes(cell.dataset.columnKey));
            });
        }

        renderProcessingOptions() {
            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const correctOption = sample.processingOptions.find((option) => option.correct);
            const correctKey = correctOption ? correctOption.key : "";

            this.fields.processingOptions.innerHTML = sample.processingOptions.map((option) => {
                const isSelected = this.state.selectedProcessing === option.key;
                const isAnswer = this.state.answered && option.key === correctKey;
                const isWrong = this.state.answered && isSelected && option.key !== correctKey;
                const classes = [
                    "oj-extract-processing-option",
                    isSelected ? "is-selected" : "",
                    isAnswer ? "is-answer" : "",
                    isWrong ? "is-wrong" : "",
                ].filter(Boolean).join(" ");
                return `<button type="button" class="${classes}" data-processing-key="${escapeHtml(option.key)}" ${this.state.answered ? "disabled" : ""}>${escapeHtml(option.label)}</button>`;
            }).join("");
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

            this.fields.submitButton.textContent = "제출";
            this.fields.submitButton.disabled = this.state.isSaving || this.state.answered;
        }

        formatCell(value) {
            if (typeof value === "number") {
                return Number.isInteger(value) ? String(value) : value.toFixed(1);
            }

            return String(value ?? "");
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
                this.fields.leaderboard.innerHTML = '<div class="oj-extract-rank-empty">아직 저장된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.fields.rankStatus.textContent = this.state.myRank ? `${this.state.myRank}위` : "전체 참여자";
            this.fields.leaderboard.innerHTML = `
                <div class="oj-extract-rank-head">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.state.leaderboard.map((row) => `
                    <div data-rank-row class="oj-extract-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
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
        document.querySelectorAll("[data-data-extraction]").forEach((root) => {
            if (!root.__DataExtraction) {
                root.__DataExtraction = new DataExtractionGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
