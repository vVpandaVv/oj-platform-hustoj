(() => {
    const STORAGE_KEY = "edu-games-missing-values-best";

    const escapeHtml = (value) => String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    class MissingValuesGame {
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
                methodStatus: root.querySelector('[data-field="method-status"]'),
                context: root.querySelector('[data-field="context"]'),
                table: root.querySelector('[data-field="table"]'),
                methods: root.querySelector('[data-field="methods"]'),
                answerFields: root.querySelector('[data-field="answer-fields"]'),
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
            const methods = rules.methods && Object.keys(rules.methods).length ? rules.methods : {
                mean: "평균으로 대체",
                median: "중앙값으로 대체",
                mode: "최빈값으로 대체",
                interpolate: "앞뒤 값으로 보간",
                pattern: "규칙으로 추론",
                previous: "이전 값 유지",
            };

            return {
                rankedRounds: Math.max(4, Number(rules.rankedRounds) || 10),
                defaultDifficulty: rules.defaultDifficulty && difficulties[rules.defaultDifficulty]
                    ? rules.defaultDifficulty
                    : Object.keys(difficulties)[0],
                methods,
                difficulties,
                samples: Array.isArray(rules.samples) ? rules.samples : [],
                scoring: {
                    streakBonus: Number(rules.scoring && rules.scoring.streakBonus) || 10,
                    maxStreakBonus: Number(rules.scoring && rules.scoring.maxStreakBonus) || 70,
                    wrongPenalty: Number(rules.scoring && rules.scoring.wrongPenalty) || 25,
                    multiBlankBonus: Number(rules.scoring && rules.scoring.multiBlankBonus) || 20,
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
                selectedMethod: "",
                answers: {},
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

                const methodButton = event.target.closest("[data-method]");
                if (methodButton) {
                    this.selectMethod(methodButton.dataset.method);
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

            this.root.addEventListener("input", (event) => {
                const input = event.target.closest("[data-answer-index]");
                if (!input || this.state.answered) {
                    return;
                }

                this.state.answers[input.dataset.answerIndex] = input.value;
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
            this.state.selectedMethod = "";
            this.state.answers = {};
            this.state.answered = false;
            this.state.currentSample = this.pickSample();
            this.render();
            this.setFeedback("자료의 맥락을 보고 알맞은 결측값 처리 방법과 빈칸 값을 입력하세요. 연습에서는 틀려도 다시 시도할 수 있습니다.");
        }

        prepareRankedIdle() {
            this.state.playType = "ranked";
            this.state.status = "ranked-idle";
            this.state.score = 0;
            this.state.round = 0;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedMethod = "";
            this.state.answers = {};
            this.state.answered = false;
            this.state.startedAt = 0;
            this.state.durationSeconds = 0;
            this.state.history = [];
            this.state.currentSample = null;
            this.render();
            this.setFeedback(`도전은 ${this.rules.rankedRounds}라운드입니다. 각 문제는 한 번 제출하면 바로 기록됩니다.`);
        }

        startRanked() {
            this.state.playType = "ranked";
            this.state.status = "ranked";
            this.state.score = 0;
            this.state.round = 1;
            this.state.correctCount = 0;
            this.state.wrongCount = 0;
            this.state.streak = 0;
            this.state.selectedMethod = "";
            this.state.answers = {};
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
            this.setFeedback("도전이 시작되었습니다. 방법과 값을 모두 맞혀야 정답입니다.");
        }

        nextRankedRound() {
            if (this.state.round >= this.rules.rankedRounds) {
                this.finishRanked();
                return;
            }

            this.state.round += 1;
            this.state.selectedMethod = "";
            this.state.answers = {};
            this.state.answered = false;
            this.state.currentSample = this.pickRankedSample();
            this.render();
            this.setFeedback("다음 자료입니다. 빠진 값이 왜 빠졌는지보다, 어떤 방법으로 처리할지가 중요합니다.");
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
            const answers = {};
            Object.entries(sample && sample.answers ? sample.answers : {}).forEach(([index, value]) => {
                answers[String(index)] = value;
            });

            return {
                ...sample,
                labels: Array.isArray(sample && sample.labels) ? [...sample.labels] : [],
                values: Array.isArray(sample && sample.values) ? [...sample.values] : [],
                answers,
                missingIndexes: Object.keys(answers).map(Number).sort((left, right) => left - right),
            };
        }

        getDifficulty() {
            return this.rules.difficulties[this.state.difficulty] || Object.values(this.rules.difficulties)[0];
        }

        selectMethod(method) {
            if (!this.state.currentSample || this.state.status === "ranked-idle" || this.state.answered) {
                return;
            }

            this.state.selectedMethod = method;
            this.renderMethods();
            this.renderMethodStatus();
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

            if (!this.state.selectedMethod) {
                this.setFeedback("먼저 결측값 처리 방법을 선택하세요.");
                return;
            }

            if (!this.hasAllAnswers()) {
                this.setFeedback("빈칸에 들어갈 값을 모두 입력하세요.");
                return;
            }

            const ok = this.isCorrectAnswer();
            if (this.state.playType === "practice") {
                this.handlePracticeSubmit(ok);
                return;
            }

            this.handleRankedSubmit(ok);
        }

        hasAllAnswers() {
            return this.state.currentSample.missingIndexes.every((index) => {
                const value = this.state.answers[String(index)];
                return value !== undefined && String(value).trim() !== "";
            });
        }

        handlePracticeSubmit(ok) {
            if (!ok) {
                this.state.wrongCount += 1;
                this.renderStats();
                this.setFeedback("아직 맞지 않습니다. 자료형과 시간 순서, 극단값 여부를 다시 확인해 보세요.");
                return;
            }

            this.state.correctCount += 1;
            this.state.answered = true;
            this.render();
            this.setFeedback(`정답입니다. ${this.state.currentSample.explanation}`);
        }

        handleRankedSubmit(ok) {
            const difficulty = this.rules.difficulties[this.state.currentSample.difficulty] || this.getDifficulty();
            const missingCount = this.state.currentSample.missingIndexes.length;
            let gained = 0;
            this.state.answered = true;

            if (ok) {
                const streakBonus = Math.min(this.rules.scoring.maxStreakBonus, this.state.streak * this.rules.scoring.streakBonus);
                const multiBlankBonus = Math.max(0, missingCount - 1) * this.rules.scoring.multiBlankBonus;
                gained = Math.max(this.rules.scoring.minimumCorrectScore, Number(difficulty.baseScore || 100) + streakBonus + multiBlankBonus);
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
                method: this.state.selectedMethod,
                answer: { ...this.state.answers },
                expectedMethod: this.state.currentSample.method,
                expectedAnswer: { ...this.state.currentSample.answers },
                correct: ok,
                gained,
            });

            this.render();
            this.setFeedback(ok
                ? `정답입니다. +${gained}점 ${this.state.currentSample.explanation}`
                : `오답입니다. ${this.state.currentSample.explanation}`);

            window.setTimeout(() => this.nextRankedRound(), 1300);
        }

        isCorrectAnswer() {
            return this.state.selectedMethod === this.state.currentSample.method
                && this.state.currentSample.missingIndexes.every((index) => this.isCorrectValue(index));
        }

        isCorrectValue(index) {
            const expected = this.state.currentSample.answers[String(index)];
            const actual = this.state.answers[String(index)];

            if (this.state.currentSample.valueType === "numeric") {
                const normalized = Number(String(actual).trim().replace(",", "."));
                if (!Number.isFinite(normalized)) {
                    return false;
                }

                const tolerance = Number.isInteger(Number(expected)) ? 0.01 : 0.05;
                return Math.abs(normalized - Number(expected)) <= tolerance;
            }

            return String(actual).trim().toLowerCase() === String(expected).trim().toLowerCase();
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
                this.fields.sampleTitle.textContent = `${this.rules.rankedRounds}라운드 결측값 처리`;
                this.fields.context.textContent = "도전 시작을 누르면 각 자료의 빈칸을 한 번씩 제출하며 점수와 순위를 기록합니다.";
                this.fields.table.innerHTML = "";
                this.fields.methods.innerHTML = "";
                this.fields.answerFields.innerHTML = "";
                this.fields.methodStatus.textContent = "방법 미선택";
                return;
            }

            if (this.state.status === "finished") {
                this.fields.sampleMeta.textContent = "도전 완료";
                this.fields.sampleTitle.textContent = "결과가 기록되었습니다";
                this.fields.context.textContent = `정답 ${this.state.correctCount}개, 오답 ${this.state.wrongCount}개, 최고 점수 ${this.bestScore}점`;
                this.fields.table.innerHTML = "";
                this.fields.methods.innerHTML = "";
                this.fields.answerFields.innerHTML = "";
                this.fields.methodStatus.textContent = "완료";
                return;
            }

            const sample = this.state.currentSample;
            if (!sample) {
                return;
            }

            const difficulty = this.state.playType === "ranked"
                ? (this.rules.difficulties[sample.difficulty] || this.getDifficulty())
                : this.getDifficulty();
            this.fields.sampleMeta.textContent = `${difficulty.label || ""} · ${sample.unit || "분류형"}`;
            this.fields.sampleTitle.textContent = sample.title || "";
            this.fields.context.textContent = sample.context || "";
            this.renderTable(sample);
            this.renderMethods();
            this.renderAnswerFields(sample);
            this.renderMethodStatus();
        }

        renderTable(sample) {
            this.fields.table.innerHTML = `
                <table class="oj-missing-table">
                    <thead>
                        <tr>
                            <th>항목</th>
                            <th>값</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${sample.values.map((value, index) => {
                            const missing = sample.missingIndexes.includes(index);
                            const answer = sample.answers[String(index)];
                            const shownValue = missing && this.state.answered ? answer : value;
                            return `
                                <tr class="${missing ? "is-missing" : ""}">
                                    <th scope="row">${escapeHtml(sample.labels[index] || `${index + 1}번`)}</th>
                                    <td>
                                        <span class="${missing ? "oj-missing-cell-missing" : ""}">
                                            ${missing && !this.state.answered ? "?" : escapeHtml(this.formatValue(shownValue, sample.unit))}
                                        </span>
                                    </td>
                                </tr>
                            `;
                        }).join("")}
                    </tbody>
                </table>
            `;
        }

        renderMethods() {
            const correctMethod = this.state.currentSample ? this.state.currentSample.method : "";
            this.fields.methods.innerHTML = Object.entries(this.rules.methods).map(([method, label]) => {
                const classes = [
                    "oj-missing-method",
                    this.state.selectedMethod === method ? "is-selected" : "",
                    this.state.answered && correctMethod === method ? "is-answer" : "",
                ].filter(Boolean).join(" ");
                return `<button type="button" class="${classes}" data-method="${escapeHtml(method)}" ${this.state.answered ? "disabled" : ""}>${escapeHtml(label)}</button>`;
            }).join("");
        }

        renderAnswerFields(sample) {
            this.fields.answerFields.innerHTML = sample.missingIndexes.map((index) => {
                const expected = sample.answers[String(index)];
                const value = this.state.answered ? expected : (this.state.answers[String(index)] || "");
                return `
                    <label class="oj-missing-answer${this.state.answered ? " is-answer" : ""}">
                        <span>${escapeHtml(sample.labels[index] || `${index + 1}번`)} 값</span>
                        <input type="text" data-answer-index="${index}" value="${escapeHtml(value)}" ${this.state.answered ? "disabled" : ""} autocomplete="off" inputmode="${sample.valueType === "numeric" ? "decimal" : "text"}">
                    </label>
                `;
            }).join("");
        }

        renderMethodStatus() {
            if (!this.state.selectedMethod) {
                this.fields.methodStatus.textContent = "방법 미선택";
                return;
            }

            this.fields.methodStatus.textContent = this.rules.methods[this.state.selectedMethod] || this.state.selectedMethod;
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

            this.fields.submitButton.textContent = "처리 결과 제출";
            this.fields.submitButton.disabled = this.state.isSaving || this.state.answered;
        }

        formatValue(value, unit) {
            if (value === null || value === undefined) {
                return "?";
            }

            if (typeof value === "number") {
                const normalized = Number.isInteger(value) ? String(value) : value.toFixed(1);
                return `${normalized}${unit ? ` ${unit}` : ""}`;
            }

            return `${value}${unit ? ` ${unit}` : ""}`;
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
                this.fields.leaderboard.innerHTML = '<div class="oj-missing-rank-empty">아직 저장된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.fields.rankStatus.textContent = this.state.myRank ? `${this.state.myRank}위` : "전체 참여자";
            this.fields.leaderboard.innerHTML = `
                <div class="oj-missing-rank-head">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.state.leaderboard.map((row) => `
                    <div data-rank-row class="oj-missing-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
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
        document.querySelectorAll("[data-missing-values]").forEach((root) => {
            if (!root.__MissingValues) {
                root.__MissingValues = new MissingValuesGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
