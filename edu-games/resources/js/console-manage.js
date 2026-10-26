const escapeHtml = (value) => String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

// Platform sheet and toast (OJ Platform 2.1+); fall back to the browser dialogs.
const notify = (message, tone) => {
    if (window.OJP && typeof window.OJP.toast === "function") {
        window.OJP.toast(message, tone);
    } else if ("danger" === tone) {
        window.alert(message);
    }
};

const confirmAction = (options) => {
    if (window.OJP && typeof window.OJP.confirm === "function") {
        return Promise.resolve(window.OJP.confirm(options));
    }
    return Promise.resolve(window.confirm(`${options.title}\n${options.message}`));
};

const emptyState = (title, message = "") => `
    <div class="ojp-empty-state"><div><strong>${escapeHtml(title)}</strong>${message ? `<p>${escapeHtml(message)}</p>` : ""}</div></div>
`;

class EduGamesConsole {
    constructor(root) {
        this.root = root;
        this.config = this.readConfig();
        this.fields = {
            gamesTable: root.querySelector('[data-field="games-table"]'),
            saveStatus: root.querySelector('[data-field="save-status"]'),
            saveButton: root.querySelector('[data-click-action="save-visibility"]'),
            teamList: root.querySelector('[data-field="team-list"]'),
        };

        this.bindEvents();
        this.loadGames();
        this.loadTeams();
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

    bindEvents() {
        this.root.addEventListener("submit", (event) => {
            const form = event.target.closest("[data-action]");
            if (!form) {
                return;
            }
            event.preventDefault();
            this.handleForm(form);
        });

        this.root.addEventListener("click", (event) => {
            const button = event.target.closest("[data-click-action]");
            if (button) {
                this.handleClick(button);
            }
        });
    }

    async loadGames() {
        this.fields.gamesTable.innerHTML = '<div class="ojp-loading-state" role="status">게임 목록을 불러오는 중입니다.</div>';
        try {
            this.games = await this.api("/admin/games");
            this.renderGames();
        } catch (error) {
            this.fields.gamesTable.innerHTML = `<div class="ojp-notice ojp-notice--danger" role="alert"><p>${escapeHtml(error.message)}</p></div>`;
        }
    }

    categoryLabel(category) {
        const labels = this.config.categoryLabels || {};
        return labels[category] || category;
    }

    renderGames() {
        if (!this.games.length) {
            this.fields.gamesTable.innerHTML = emptyState("등록된 게임이 없습니다.");
            this.fields.saveButton.hidden = true;
            return;
        }

        this.fields.gamesTable.innerHTML = `
            <div class="ojp-table-wrap">
                <table class="ojp-table">
                    <thead>
                        <tr><th scope="col">공개</th><th scope="col">게임</th><th scope="col">분류</th><th scope="col">상태</th></tr>
                    </thead>
                    <tbody>
                        ${this.games.map((game) => `
                            <tr>
                                <td>
                                    <label class="ojp-toggle-row">
                                        <input type="checkbox" data-game-toggle value="${escapeHtml(game.id)}" ${game.isOpen ? "checked" : ""} />
                                        <span class="ojp-visually-hidden">${escapeHtml(game.title)} 공개</span>
                                    </label>
                                </td>
                                <td><strong>${escapeHtml(game.title)}</strong><span class="ojp-table-meta">${escapeHtml(game.id)}</span></td>
                                <td>${escapeHtml(this.categoryLabel(game.category))}</td>
                                <td><span class="ojp-status-badge ojp-status-badge--${"playable" === game.status ? "success" : "neutral"}">${escapeHtml(game.statusLabel || game.status)}</span></td>
                            </tr>
                        `).join("")}
                    </tbody>
                </table>
            </div>
        `;
        this.fields.saveButton.hidden = false;
    }

    async handleClick(button) {
        const action = button.dataset.clickAction;
        try {
            if ("save-visibility" === action) {
                const openIds = Array.from(this.root.querySelectorAll("[data-game-toggle]:checked")).map((input) => input.value);
                this.fields.saveStatus.textContent = "";
                button.disabled = true;
                try {
                    await this.api("/admin/games/visibility", { method: "POST", body: JSON.stringify({ openIds }) });
                } finally {
                    button.disabled = false;
                }
                this.fields.saveStatus.textContent = "저장했습니다.";
                notify("공개 설정을 저장했습니다");
            } else if ("reset-invite-code" === action) {
                await this.api(`/teams/${button.dataset.teamId}/reset-invite-code`, { method: "POST", body: "{}" });
                notify("초대 코드를 새로 발급했습니다");
                this.loadTeams();
            } else if ("delete-team" === action) {
                const name = button.dataset.teamName || "";
                const ok = await confirmAction({
                    title: name ? `"${name}" 팀을 삭제할까요?` : "이 팀을 삭제할까요?",
                    message: "팀원 소속도 함께 사라지며 되돌릴 수 없습니다.",
                    confirmLabel: "삭제",
                    destructive: true,
                });
                if (!ok) {
                    return;
                }
                await this.api(`/teams/${button.dataset.teamId}`, { method: "DELETE" });
                notify("팀을 삭제했습니다");
                this.loadTeams();
            }
        } catch (error) {
            notify(error.message, "danger");
        }
    }

    async handleForm(form) {
        const action = form.dataset.action;
        try {
            if ("create-team" === action) {
                const name = form.querySelector('[name="name"]').value.trim();
                await this.api("/teams", { method: "POST", body: JSON.stringify({ name }) });
                form.reset();
                notify("팀을 추가했습니다");
                this.loadTeams();
            } else if ("rename-team" === action) {
                const name = form.querySelector('[name="name"]').value.trim();
                await this.api(`/teams/${form.dataset.teamId}/rename`, { method: "POST", body: JSON.stringify({ name }) });
                notify("팀 이름을 저장했습니다");
                this.loadTeams();
            }
        } catch (error) {
            notify(error.message, "danger");
        }
    }

    async loadTeams() {
        try {
            const teams = await this.api("/teams");
            this.renderTeams(Array.isArray(teams) ? teams : []);
        } catch (error) {
            this.fields.teamList.innerHTML = `<div class="ojp-notice ojp-notice--danger" role="alert"><p>${escapeHtml(error.message)}</p></div>`;
        }
    }

    renderTeams(teams) {
        const rows = teams.map((team) => `
            <tr>
                <td>
                    <form class="eg-console-inline" data-action="rename-team" data-team-id="${team.id}">
                        <label class="ojp-visually-hidden" for="eg-team-name-${team.id}">팀 이름</label>
                        <input class="ojp-input" id="eg-team-name-${team.id}" type="text" name="name" value="${escapeHtml(team.name)}" required />
                        <button type="submit" class="ojp-button ojp-button--small">이름 저장</button>
                    </form>
                </td>
                <td><code class="eg-console-code">${escapeHtml(team.inviteCode || "")}</code></td>
                <td>${Number(team.memberCount) || 0}명</td>
                <td>
                    <div class="eg-console-row-actions">
                        <button type="button" class="ojp-button ojp-button--small ojp-button--plain" data-click-action="reset-invite-code" data-team-id="${team.id}">코드 재발급</button>
                        <button type="button" class="ojp-button ojp-button--small ojp-button--danger" data-click-action="delete-team" data-team-id="${team.id}" data-team-name="${escapeHtml(team.name)}">삭제</button>
                    </div>
                </td>
            </tr>
        `).join("");

        this.fields.teamList.innerHTML = `
            <form data-action="create-team" class="eg-console-inline eg-console-team-form">
                <div class="ojp-field">
                    <label for="eg-new-team-name">새 팀 이름</label>
                    <input class="ojp-input" id="eg-new-team-name" type="text" name="name" required />
                </div>
                <button type="submit" class="ojp-button">팀 추가</button>
            </form>
            ${rows ? `
                <div class="ojp-table-wrap">
                    <table class="ojp-table">
                        <thead>
                            <tr><th scope="col">팀 이름</th><th scope="col">초대 코드</th><th scope="col">인원</th><th scope="col"><span class="ojp-visually-hidden">작업</span></th></tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            ` : emptyState("아직 만든 팀이 없습니다.", "팀을 추가하면 초대 코드가 만들어집니다.")}
        `;
    }
}

const init = () => {
    document.querySelectorAll("[data-edu-games-console]").forEach((root) => {
        if (!root.__EduGamesConsole) {
            root.__EduGamesConsole = new EduGamesConsole(root);
        }
    });
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
