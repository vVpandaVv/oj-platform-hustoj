(() => {
    const escapeHtml = (value) => String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    const percent = (value) => `${(Number(value) * 100).toFixed(1)}%`;

    const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    class AIModelLabGame {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.activities = this.normalizeActivities(this.config.rules || {});
            this.fields = {
                stageLabel: root.querySelector('[data-field="stage-label"]'),
                feedback: root.querySelector('[data-field="feedback"]'),
                activityPanel: root.querySelector('[data-field="activity-panel"]'),
                activityList: root.querySelector('[data-field="activity-list"]'),
                setupPanel: root.querySelector('[data-field="setup-panel"]'),
                activityMeta: root.querySelector('[data-field="activity-meta"]'),
                activityTitle: root.querySelector('[data-field="activity-title"]'),
                activityDescription: root.querySelector('[data-field="activity-description"]'),
                modelCards: root.querySelector('[data-field="model-cards"]'),
                labelingProgress: root.querySelector('[data-field="labeling-progress"]'),
                labelingGrid: root.querySelector('[data-field="labeling-grid"]'),
                phase1Panel: root.querySelector('[data-field="phase1-panel"]'),
                phase1Grid: root.querySelector('[data-field="phase1-grid"]'),
                phase1Score: root.querySelector('[data-field="phase1-score"]'),
                phase2Panel: root.querySelector('[data-field="phase2-panel"]'),
                phase2Metrics: root.querySelector('[data-field="phase2-metrics"]'),
                phase2Confusion: root.querySelector('[data-field="phase2-confusion"]'),
                phase2Score: root.querySelector('[data-field="phase2-score"]'),
                totalScore: root.querySelector('[data-field="total-score"]'),
                submitPhase1Button: root.querySelector('[data-action="submit-phase1"]'),
                submitPhase2Button: root.querySelector('[data-action="submit-phase2"]'),
            };

            this.state = this.initialState();

            this.bindEvents();
            this.renderActivityList();
        }

        readConfig() {
            try {
                return JSON.parse(this.root.dataset.config || "{}");
            } catch (error) {
                return {};
            }
        }

        normalizeActivities(rules) {
            return Array.isArray(rules.activities) ? rules.activities : [];
        }

        initialState() {
            return {
                attemptId: null,
                activity: null,
                selectedModel: "",
                labelChoices: {},
                phase1Result: null,
                isBusy: false,
            };
        }

        bindEvents() {
            this.root.addEventListener("click", (event) => {
                const activityCard = event.target.closest("[data-activity-key]");
                if (activityCard) {
                    this.selectActivity(activityCard.dataset.activityKey);
                    return;
                }

                const modelCard = event.target.closest("[data-model-key]");
                if (modelCard) {
                    this.selectModel(modelCard.dataset.modelKey);
                    return;
                }

                const labelButton = event.target.closest("[data-sample-id][data-label-key]");
                if (labelButton) {
                    this.assignLabel(Number(labelButton.dataset.sampleId), labelButton.dataset.labelKey);
                    return;
                }

                const actionButton = event.target.closest("[data-action]");
                if (!actionButton) {
                    return;
                }

                if (actionButton.dataset.action === "submit-phase1") {
                    this.submitPhase1();
                }

                if (actionButton.dataset.action === "submit-phase2") {
                    this.submitPhase2();
                }

                if (actionButton.dataset.action === "restart") {
                    this.restart();
                }
            });
        }

        setFeedback(message, isError = false) {
            this.fields.feedback.textContent = message || "";
            this.fields.feedback.classList.toggle("is-error", Boolean(isError));
        }

        setStage(label) {
            if (this.fields.stageLabel) {
                this.fields.stageLabel.textContent = label;
            }
        }

        headers() {
            const headers = { "Content-Type": "application/json" };
            if (this.config.nonce) {
                headers["X-WP-Nonce"] = this.config.nonce;
            }
            return headers;
        }

        async api(path, options = {}) {
            const response = await window.fetch(`${this.config.restUrl}${path}`, {
                credentials: "same-origin",
                headers: this.headers(),
                ...options,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error((data && data.message) || "요청을 처리하지 못했습니다.");
            }
            return data;
        }

        renderActivityList() {
            if (!this.activities.length) {
                this.fields.activityList.innerHTML = '<p>등록된 활동이 없습니다.</p>';
                return;
            }

            this.fields.activityList.innerHTML = this.activities.map((activity) => `
                <button type="button" class="oj-aiml-activity-card" data-activity-key="${escapeHtml(activity.key)}">
                    <span class="oj-aiml-badge${"hard" === activity.difficulty ? " is-hard" : ""}">${escapeHtml("hard" === activity.difficulty ? "심화" : "기초")}</span>
                    <strong>${escapeHtml(activity.title)}</strong>
                    <span>${escapeHtml(activity.datasetLabel || "")}</span>
                    <span>${escapeHtml(activity.description || "")}</span>
                </button>
            `).join("");
        }

        async selectActivity(activityKey) {
            if (this.state.isBusy) {
                return;
            }

            this.state.isBusy = true;
            this.setFeedback("활동을 불러오는 중입니다...");

            try {
                const data = await this.api(`/games/ai-model-lab/activities/${encodeURIComponent(activityKey)}/start`, {
                    method: "POST",
                    body: "{}",
                });

                this.state.attemptId = data.attemptId;
                this.state.activity = data.activity;
                this.state.selectedModel = (data.resume && data.resume.modelKey) || "";
                this.state.labelChoices = {};
                this.state.phase1Result = null;

                this.fields.phase1Panel.classList.add("is-hidden");
                this.fields.phase2Panel.classList.add("is-hidden");
                this.fields.setupPanel.classList.remove("is-hidden");
                this.fields.activityPanel.classList.add("is-hidden");
                this.setStage("직접 분류하기");

                this.renderSetup();

                if (data.resume && data.resume.totalScore !== null && data.resume.totalScore !== undefined) {
                    this.setFeedback(`이전 기록이 있습니다. 이전 총점: ${data.resume.totalScore}점. 다시 도전할 수 있습니다.`);
                } else {
                    this.setFeedback("모델을 고르고, 샘플을 하나씩 직접 분류해 보세요.");
                }
            } catch (error) {
                this.setFeedback(error.message || "활동을 불러오지 못했습니다.", true);
            } finally {
                this.state.isBusy = false;
            }
        }

        renderSetup() {
            const activity = this.state.activity;

            this.fields.activityMeta.textContent = activity.datasetLabel || "";
            this.fields.activityTitle.textContent = activity.title || "";
            this.fields.activityDescription.textContent = activity.description || "";

            this.renderModelCards();
            this.renderLabelingGrid();
            this.updateProgress();
        }

        renderModelCards() {
            const activity = this.state.activity;

            this.fields.modelCards.innerHTML = (activity.models || []).map((model) => `
                <button type="button" class="oj-aiml-model-card${this.state.selectedModel === model.key ? " is-selected" : ""}" data-model-key="${escapeHtml(model.key)}">
                    <strong>${escapeHtml(model.label)}</strong>
                    <span>${escapeHtml(model.description || "")}</span>
                </button>
            `).join("");
        }

        featureListMarkup(features) {
            const activity = this.state.activity;

            if (activity.displayType === "pixel_grid_8x8") {
                return this.pixelGridMarkup(features);
            }

            const featureLabels = activity.featureLabels || {};

            return Object.keys(features).map((featureKey) => `
                <div class="oj-aiml-feature-row">
                    <span>${escapeHtml(featureLabels[featureKey] || featureKey)}</span>
                    <strong>${escapeHtml(features[featureKey])}</strong>
                </div>
            `).join("");
        }

        // Renders an 8x8 grid of shaded cells from features keyed "r{row}c{col}"
        // (pixel intensity 0-16, scikit-learn's load_digits scale) so a
        // handwritten digit is shown as an actual recognizable shape instead
        // of a wall of numbers.
        pixelGridMarkup(features) {
            let cells = "";
            for (let r = 0; r < 8; r++) {
                for (let c = 0; c < 8; c++) {
                    const value = Number(features[`r${r}c${c}`] || 0);
                    const shade = Math.max(0, Math.min(1, value / 16));
                    cells += `<span class="oj-aiml-pixel" style="background-color: rgba(30,30,40,${shade.toFixed(3)});"></span>`;
                }
            }
            return `<div class="oj-aiml-pixel-grid" role="img" aria-label="손글씨 숫자 이미지">${cells}</div>`;
        }

        renderLabelingGrid() {
            const activity = this.state.activity;
            const labels = activity.labels || [];
            const labelLabels = activity.labelLabels || {};

            this.fields.labelingGrid.innerHTML = (activity.labelingBatch || []).map((sample) => {
                const chosen = this.state.labelChoices[sample.id];

                return `
                    <div class="oj-aiml-sample-card${chosen ? " is-labeled" : ""}" data-sample-id="${sample.id}">
                        <div class="oj-aiml-sample-features">${this.featureListMarkup(sample.features)}</div>
                        <div class="oj-aiml-sample-labels">
                            ${labels.map((labelKey) => `
                                <button type="button" class="oj-aiml-label-button${chosen === labelKey ? " is-selected" : ""}" data-sample-id="${sample.id}" data-label-key="${escapeHtml(labelKey)}">${escapeHtml(labelLabels[labelKey] || labelKey)}</button>
                            `).join("")}
                        </div>
                    </div>
                `;
            }).join("");
        }

        selectModel(modelKey) {
            this.state.selectedModel = modelKey;
            this.renderModelCards();
            this.updateProgress();
        }

        assignLabel(sampleId, labelKey) {
            this.state.labelChoices = { ...this.state.labelChoices, [sampleId]: labelKey };
            this.renderLabelingGrid();
            this.updateProgress();
        }

        updateProgress() {
            const activity = this.state.activity;
            const total = (activity.labelingBatch || []).length;
            const labeled = Object.keys(this.state.labelChoices).length;

            this.fields.labelingProgress.textContent = `${labeled} / ${total}개 분류 완료`;
            this.fields.submitPhase1Button.disabled = !this.state.selectedModel || labeled < total;
        }

        async submitPhase1() {
            if (this.state.isBusy) {
                return;
            }

            const activity = this.state.activity;

            if (!this.state.selectedModel) {
                this.setFeedback("모델을 선택하세요.", true);
                return;
            }

            const total = (activity.labelingBatch || []).length;
            if (Object.keys(this.state.labelChoices).length < total) {
                this.setFeedback("모든 샘플을 분류하세요.", true);
                return;
            }

            this.state.isBusy = true;
            this.setFeedback("분류 결과를 확인하는 중입니다...");
            this.fields.submitPhase1Button.disabled = true;

            try {
                const data = await this.api(`/games/ai-model-lab/attempts/${this.state.attemptId}/phase1`, {
                    method: "POST",
                    body: JSON.stringify({
                        modelKey: this.state.selectedModel,
                        labels: this.state.labelChoices,
                    }),
                });

                this.state.phase1Result = data;

                this.fields.phase2Panel.classList.add("is-hidden");
                this.fields.setupPanel.classList.add("is-hidden");
                this.fields.phase1Panel.classList.remove("is-hidden");
                this.setStage("분류 결과 확인");
                this.renderPhase1(data);
                this.setFeedback(`총 ${data.totalCount}개 중 ${data.correctCount}개를 정확히 분류했습니다.`);
            } catch (error) {
                this.setFeedback(error.message || "분류 결과를 확인하지 못했습니다.", true);
            } finally {
                this.state.isBusy = false;
                this.fields.submitPhase1Button.disabled = false;
            }
        }

        renderPhase1(result) {
            const activity = this.state.activity;
            const labelLabels = activity.labelLabels || {};
            const perSampleCorrect = result.perSampleCorrect || {};

            this.fields.phase1Grid.innerHTML = (activity.labelingBatch || []).map((sample) => {
                const chosen = this.state.labelChoices[sample.id];
                const isCorrect = Boolean(perSampleCorrect[sample.id]);

                return `
                    <div class="oj-aiml-sample-card is-readonly ${isCorrect ? "is-correct" : "is-incorrect"}" data-sample-id="${sample.id}">
                        <div class="oj-aiml-sample-features">${this.featureListMarkup(sample.features)}</div>
                        <div class="oj-aiml-sample-verdict">
                            <span>${escapeHtml(labelLabels[chosen] || chosen || "-")}</span>
                            <strong>${isCorrect ? "✓" : "✕"}</strong>
                        </div>
                    </div>
                `;
            }).join("");

            this.fields.phase1Score.textContent = String(result.score);
        }

        async submitPhase2() {
            if (this.state.isBusy) {
                return;
            }

            this.state.isBusy = true;
            this.fields.submitPhase2Button.disabled = true;
            this.setFeedback("모델을 학습시키는 중입니다...");

            try {
                const [data] = await Promise.all([
                    this.api(`/games/ai-model-lab/attempts/${this.state.attemptId}/phase2`, {
                        method: "POST",
                        body: "{}",
                    }),
                    wait(900),
                ]);

                this.fields.phase1Panel.classList.add("is-hidden");
                this.fields.phase2Panel.classList.remove("is-hidden");
                this.setStage("학습 결과 확인");
                this.renderPhase2(data);
                this.setFeedback("모든 단계를 완료했습니다. 결과가 저장되었습니다.");
            } catch (error) {
                this.setFeedback(error.message || "학습 결과를 확인하지 못했습니다.", true);
            } finally {
                this.state.isBusy = false;
                this.fields.submitPhase2Button.disabled = false;
            }
        }

        metricsMarkup(result) {
            const rows = [
                ["정확도", result.accuracy],
                ["정밀도", result.precision],
                ["재현율", result.recall],
                ["F1 점수", result.f1],
            ];

            return rows.map(([label, value]) => `
                <div class="oj-aiml-metric-row">
                    <span>${escapeHtml(label)}</span>
                    <strong>${escapeHtml(percent(value))}</strong>
                </div>
            `).join("");
        }

        confusionMarkup(matrix) {
            if (!Array.isArray(matrix) || !matrix.length) {
                return "";
            }

            const activity = this.state.activity;
            const labelKeys = activity.labels || [];
            const labelLabels = activity.labelLabels || {};
            const safeLabels = labelKeys.length === matrix.length
                ? labelKeys.map((labelKey) => labelLabels[labelKey] || labelKey)
                : matrix.map((_, index) => `클래스 ${index + 1}`);

            return `
                <table>
                    <caption>혼동 행렬 (행: 실제, 열: 예측)</caption>
                    <thead>
                        <tr>
                            <th></th>
                            ${safeLabels.map((label) => `<th>${escapeHtml(label)}</th>`).join("")}
                        </tr>
                    </thead>
                    <tbody>
                        ${matrix.map((row, rowIndex) => `
                            <tr>
                                <th>${escapeHtml(safeLabels[rowIndex])}</th>
                                ${row.map((value, columnIndex) => `<td class="${rowIndex === columnIndex ? "is-diagonal" : ""}">${escapeHtml(value)}</td>`).join("")}
                            </tr>
                        `).join("")}
                    </tbody>
                </table>
            `;
        }

        renderPhase2(result) {
            this.fields.phase2Metrics.innerHTML = this.metricsMarkup(result);
            this.fields.phase2Confusion.innerHTML = this.confusionMarkup(result.confusionMatrix);
            this.fields.phase2Score.textContent = String(result.score);
            this.fields.totalScore.textContent = String(result.totalScore);
        }

        restart() {
            this.state = this.initialState();
            this.fields.setupPanel.classList.add("is-hidden");
            this.fields.phase1Panel.classList.add("is-hidden");
            this.fields.phase2Panel.classList.add("is-hidden");
            this.fields.activityPanel.classList.remove("is-hidden");
            this.setStage("활동 선택");
            this.setFeedback("");
        }
    }

    const init = () => {
        document.querySelectorAll("[data-ai-model-lab]").forEach((root) => {
            if (!root.__AIModelLab) {
                root.__AIModelLab = new AIModelLabGame(root);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
