const escapeHtml = (value) => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

const formatScore = (value) => (value === null || value === undefined ? "-" : value);

const emptyState = (title, message = "") => `<div class="ojp-empty-state"><div><strong>${escapeHtml(title)}</strong>${message ? `<p>${escapeHtml(message)}</p>` : ""}</div></div>`;

class AiModelLabReport {
    constructor(root) {
        this.root = root;
        this.config = this.readConfig();
        this.contexts = [];
        this.fields = {
            contextSelect: root.querySelector('[data-field="context-select"]'),
            reportStatus: root.querySelector('[data-field="report-status"]'),
            reportTable: root.querySelector('[data-field="report-table"]'),
        };

        this.bindEvents();
        this.loadContexts();
    }

    readConfig() {
        try {
            return JSON.parse(this.root.dataset.config || "{}");
        } catch (error) {
            return {};
        }
    }

    headers() {
        const headers = { "Content-Type": "application/json" };
        if (this.config.nonce) {
            headers["X-WP-Nonce"] = this.config.nonce;
        }
        return headers;
    }

    async api(path) {
        const response = await window.fetch(`${this.config.restUrl}${path}`, {
            credentials: "same-origin",
            headers: this.headers(),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error((data && data.message) || "요청을 처리하지 못했습니다.");
        }
        return data;
    }

    setStatus(message, tone = "") {
        const status = this.fields.reportStatus;
        status.className = tone ? `eg-report-status ojp-notice ojp-notice--${tone}` : "eg-report-status ojp-form-status";
        status.textContent = message;
    }

    bindEvents() {
        this.fields.contextSelect.addEventListener("change", () => {
            this.loadScores();
        });
    }

    async loadContexts() {
        this.setStatus("학급 목록을 불러오는 중입니다.");

        try {
            const data = await this.api("/games/ai-model-lab/report/contexts");
            this.contexts = Array.isArray(data.items) ? data.items : [];
            this.renderContextOptions();

            if (this.contexts.length) {
                this.setStatus("");
                this.loadScores();
            } else {
                this.setStatus("");
                this.fields.reportTable.innerHTML = emptyState("담당 중인 학급·그룹이 없습니다.", "학급이나 그룹의 담당 교사로 지정되면 여기에 나타납니다.");
            }
        } catch (error) {
            this.setStatus(error.message || "학급 목록을 불러오지 못했습니다.", "danger");
        }
    }

    renderContextOptions() {
        this.fields.contextSelect.innerHTML = this.contexts.map((context) => `
            <option value="${escapeHtml(context.type)}::${escapeHtml(context.id)}::${escapeHtml(context.revision)}">${escapeHtml(context.label)}</option>
        `).join("");
    }

    async loadScores() {
        const value = this.fields.contextSelect.value;
        if (!value) {
            return;
        }

        const [type, id, revision] = value.split("::");
        this.setStatus("성적을 불러오는 중입니다.");
        this.fields.reportTable.innerHTML = "";

        try {
            const data = await this.api(`/games/ai-model-lab/report/contexts/${encodeURIComponent(type)}/${encodeURIComponent(id)}/scores?revision=${encodeURIComponent(revision)}`);
            this.renderTable(Array.isArray(data.students) ? data.students : []);
            this.setStatus("");
        } catch (error) {
            this.setStatus(error.message || "성적을 불러오지 못했습니다.", "danger");
        }
    }

    renderTable(students) {
        if (!students.length) {
            this.fields.reportTable.innerHTML = emptyState("표시할 학생이 없습니다.");
            return;
        }

        this.fields.reportTable.innerHTML = `
            <div class="ojp-table-wrap">
            <table class="ojp-table eg-score-table">
                <thead>
                    <tr>
                        <th scope="col">학생</th>
                        <th scope="col">숫자인식 1단계</th>
                        <th scope="col">숫자인식 2단계</th>
                        <th scope="col">숫자인식 총점</th>
                        <th scope="col">타이타닉 1단계</th>
                        <th scope="col">타이타닉 2단계</th>
                        <th scope="col">타이타닉 총점</th>
                    </tr>
                </thead>
                <tbody>
                    ${students.map((student) => {
                        const activities = student.activities || {};
                        const digits = activities.digit_recognition || {};
                        const titanic = activities.titanic_survival || {};
                        return `
                            <tr>
                                <th scope="row">${escapeHtml(student.displayName)}</th>
                                <td>${escapeHtml(formatScore(digits.phase1Score))}</td>
                                <td>${escapeHtml(formatScore(digits.phase2Score))}</td>
                                <td>${escapeHtml(formatScore(digits.totalScore))}</td>
                                <td>${escapeHtml(formatScore(titanic.phase1Score))}</td>
                                <td>${escapeHtml(formatScore(titanic.phase2Score))}</td>
                                <td>${escapeHtml(formatScore(titanic.totalScore))}</td>
                            </tr>
                        `;
                    }).join("")}
                </tbody>
            </table>
            </div>
        `;
    }
}

const init = () => {
    document.querySelectorAll("[data-ai-model-lab-report]").forEach((root) => {
        if (!root.__AiModelLabReport) {
            root.__AiModelLabReport = new AiModelLabReport(root);
        }
    });
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
