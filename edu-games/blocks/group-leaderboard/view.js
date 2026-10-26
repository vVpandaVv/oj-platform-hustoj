const escapeHtml = (value) => String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

class EduGroupLeaderboard {
    constructor(root) {
        this.root = root;
        this.config = this.readConfig();
        this.games = Array.isArray(this.config.games) ? this.config.games.filter((game) => game && game.id) : [];
        this.currentGame = this.config.defaultGame || (this.games[0] && this.games[0].id) || "";
        this.scope = "overall";
        this.team = null;
        this.fields = {
            teamPanel: root.querySelector('[data-field="team-panel"]'),
            scopeTabs: root.querySelector('[data-field="scope-tabs"]'),
            tabs: root.querySelector('[data-field="game-tabs"]'),
            status: root.querySelector('[data-field="status"]'),
            leaderboard: root.querySelector('[data-field="leaderboard"]'),
            teamAdminList: root.querySelector('[data-field="team-admin-list"]'),
        };

        if (this.config.isLoggedIn) {
            this.bindEvents();
            this.renderScopeTabs();
            this.renderTabs();
            this.loadMyTeam();
        }
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
            this.handleFormAction(form);
        });

        this.root.addEventListener("click", (event) => {
            const scopeButton = event.target.closest("[data-scope]");
            if (scopeButton) {
                this.scope = scopeButton.dataset.scope;
                this.renderScopeTabs();
                this.loadLeaderboard();
                return;
            }

            const gameButton = event.target.closest("[data-game-id]");
            if (gameButton) {
                this.currentGame = gameButton.dataset.gameId;
                this.renderTabs();
                this.loadLeaderboard();
                return;
            }

            const actionButton = event.target.closest("[data-click-action]");
            if (actionButton) {
                this.handleClickAction(actionButton);
            }
        });
    }

    renderScopeTabs() {
        if (!this.fields.scopeTabs) {
            return;
        }

        const scopes = [
            { key: "overall", label: "전체 순위" },
            { key: "teams", label: "팀 순위" },
            { key: "team", label: "팀 내 순위" },
        ];

        this.fields.scopeTabs.innerHTML = scopes.map((scope) => `
            <button type="button" class="${scope.key === this.scope ? "is-active" : ""}" data-scope="${scope.key}">
                ${escapeHtml(scope.label)}
            </button>
        `).join("");
    }

    loadLeaderboard() {
        this.rankRequest = (this.rankRequest || 0) + 1;
        if ("team" === this.scope) {
            if (!this.team) {
                this.fields.status.textContent = "";
                this.fields.leaderboard.innerHTML = '<div class="eg-empty">팀에 참여하면 팀 내 순위를 볼 수 있습니다.</div>';
                return;
            }
            this.loadTeamLeaderboard();
            return;
        }

        if ("teams" === this.scope) {
            this.loadTeamStandings();
            return;
        }

        this.loadOverallLeaderboard();
    }

    async handleFormAction(form) {
        const action = form.dataset.action;
        try {
            if ("join-team" === action) {
                const code = form.querySelector('[name="inviteCode"]').value.trim();
                const result = await this.api("/teams/join", { method: "POST", body: JSON.stringify({ inviteCode: code }) });
                this.team = result.team;
                this.afterTeamChange();
            } else if ("create-team" === action) {
                const name = form.querySelector('[name="name"]').value.trim();
                await this.api("/teams", { method: "POST", body: JSON.stringify({ name }) });
                form.reset();
                this.loadTeamAdminList();
            } else if ("rename-team" === action) {
                const teamId = form.dataset.teamId;
                const name = form.querySelector('[name="name"]').value.trim();
                await this.api(`/teams/${teamId}/rename`, { method: "POST", body: JSON.stringify({ name }) });
                this.loadTeamAdminList();
            }
        } catch (error) {
            window.alert(error.message);
        }
    }

    async handleClickAction(button) {
        const action = button.dataset.clickAction;
        try {
            if ("leave-team" === action) {
                const result = await this.api("/teams/leave", { method: "POST", body: "{}" });
                this.team = result.team;
                this.afterTeamChange();
            } else if ("reset-invite-code" === action) {
                await this.api(`/teams/${button.dataset.teamId}/reset-invite-code`, { method: "POST", body: "{}" });
                this.loadTeamAdminList();
                if (this.team && Number(this.team.id) === Number(button.dataset.teamId)) {
                    this.loadMyTeam();
                }
            } else if ("delete-team" === action) {
                if (!window.confirm("이 팀을 삭제할까요? 팀원 소속도 함께 사라집니다.")) {
                    return;
                }
                await this.api(`/teams/${button.dataset.teamId}`, { method: "DELETE" });
                this.loadTeamAdminList();
            }
        } catch (error) {
            window.alert(error.message);
        }
    }

    afterTeamChange() {
        this.renderTeamPanel();
        this.loadLeaderboard();
    }

    async loadMyTeam() {
        try {
            const result = await this.api("/teams/mine");
            this.team = result.team;
        } catch (error) {
            this.team = null;
        }
        this.afterTeamChange();
        if (this.config.canManage) {
            this.loadTeamAdminList();
        }
    }

    renderTeamPanel() {
        if (!this.fields.teamPanel) {
            return;
        }

        if (!this.team) {
            this.fields.teamPanel.innerHTML = `
                <form data-action="join-team" class="eg-team-form">
                    <input type="text" name="inviteCode" placeholder="초대 코드 입력" required maxlength="6" />
                    <button type="submit">팀 참여</button>
                </form>
            `;
            return;
        }

        this.fields.teamPanel.innerHTML = `
            <div class="eg-team-current">
                <strong>${escapeHtml(this.team.name)}</strong>
                <span>초대 코드: ${escapeHtml(this.team.inviteCode || "")}</span>
                <span>${Number(this.team.memberCount) || 0}명</span>
                <button type="button" data-click-action="leave-team">팀 나가기</button>
            </div>
        `;
    }

    renderTabs() {
        if (!this.fields.tabs) {
            return;
        }

        const games = this.games.length ? this.games : [{ id: this.currentGame, title: this.currentGame }];
        this.fields.tabs.innerHTML = games.map((game) => `
            <button type="button" class="${game.id === this.currentGame ? "is-active" : ""}" data-game-id="${escapeHtml(game.id)}">
                ${escapeHtml(game.title || game.id)}
            </button>
        `).join("");
    }

    async loadOverallLeaderboard() {
        const request = this.rankRequest;
        if (!this.currentGame) {
            return;
        }

        this.fields.status.textContent = "순위표를 불러오는 중입니다.";

        const key = `${this.currentGame}:overall`;
        const pager = window.EduGamesRankPager.request(this.fields.leaderboard, key, () => this.loadLeaderboard());
        try {
            const data = await this.api(`/leaderboards/game/${encodeURIComponent(this.currentGame)}?page=${pager.page}&perPage=${pager.perPage}`);
            if (!window.EduGamesRankPager.accept(this.fields.leaderboard, key, data, pager.ticket)) return;
            this.rankTotal = data.pagination?.total;
            if (request !== this.rankRequest) return;
            this.renderOverallLeaderboard(Array.isArray(data.leaderboard) ? data.leaderboard : [], data.myRank);
        } catch (error) {
            window.EduGamesRankPager.fail(this.fields.leaderboard, pager.ticket);
        }
    }

    renderOverallLeaderboard(rows, myRank) {
        this.fields.status.textContent = `전체 참여자 ${this.rankTotal ?? rows.length}명${myRank ? ` · 내 순위 ${myRank}위` : ''}`;

        if (!rows.length) {
            this.fields.leaderboard.innerHTML = '<div class="eg-empty">아직 기록된 순위가 없습니다.</div>';
            window.EduGamesRankPager?.mount(this.fields.leaderboard, `${this.currentGame}:overall`);
            return;
        }

        this.fields.leaderboard.innerHTML = `
            <div class="eg-board-row eg-board-head">
                <span>순위</span>
                <span>플레이어</span>
                <span>점수</span>
                <span>정확도</span>
            </div>
            ${rows.map((row) => `
                <div data-rank-row class="eg-board-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
                    <strong>${Number(row.rank) || "-"}</strong>
                    <span>${escapeHtml(row.playerName || "플레이어")}</span>
                    <b>${Number(row.score) || 0}</b>
                    <span>${Number(row.accuracy || 0).toFixed(1)}%</span>
                </div>
            `).join("")}
        `;
        window.EduGamesRankPager?.mount(this.fields.leaderboard, `${this.currentGame}:overall`);
    }

    async loadTeamStandings() {
        const request = this.rankRequest;
        if (!this.currentGame) {
            return;
        }

        this.fields.status.textContent = "팀 순위를 불러오는 중입니다.";

        const key = `${this.currentGame}:teams`;
        const pager = window.EduGamesRankPager.request(this.fields.leaderboard, key, () => this.loadLeaderboard());
        try {
            const data = await this.api(`/leaderboards/teams?game=${encodeURIComponent(this.currentGame)}&page=${pager.page}&perPage=${pager.perPage}`);
            if (!window.EduGamesRankPager.accept(this.fields.leaderboard, key, data, pager.ticket)) return;
            this.rankTotal = data.pagination?.total;
            if (request !== this.rankRequest) return;
            this.renderTeamStandings(Array.isArray(data.standings) ? data.standings : []);
        } catch (error) {
            window.EduGamesRankPager.fail(this.fields.leaderboard, pager.ticket);
        }
    }

    renderTeamStandings(rows) {
        this.fields.status.textContent = `전체 ${this.rankTotal ?? rows.length}팀 · 평균 점수 기준 순위`;

        if (!rows.length) {
            this.fields.leaderboard.innerHTML = '<div class="eg-empty">아직 기록된 팀 순위가 없습니다.</div>';
            window.EduGamesRankPager?.mount(this.fields.leaderboard, `${this.currentGame}:teams`, '[data-rank-row]', '팀');
            return;
        }

        this.fields.leaderboard.innerHTML = `
            <div class="eg-board-row eg-board-head">
                <span>순위</span>
                <span>팀</span>
                <span>인원</span>
                <span>평균 점수</span>
            </div>
            ${rows.map((row) => `
                <div data-rank-row class="eg-board-row${this.team && Number(row.teamId) === Number(this.team.id) ? " is-me" : ""}">
                    <strong>${Number(row.rank) || "-"}</strong>
                    <span>${escapeHtml(row.teamName || "")}</span>
                    <span>${Number(row.memberCount) || 0}명</span>
                    <b>${Number(row.averageScore) || 0}</b>
                </div>
            `).join("")}
        `;
        window.EduGamesRankPager?.mount(this.fields.leaderboard, `${this.currentGame}:teams`, '[data-rank-row]', '팀');
    }

    async loadTeamLeaderboard() {
        const request = this.rankRequest;
        if (!this.team || !this.currentGame) {
            return;
        }

        this.fields.status.textContent = "순위표를 불러오는 중입니다.";

        const key = `${this.currentGame}:team:${this.team.id}`;
        const pager = window.EduGamesRankPager.request(this.fields.leaderboard, key, () => this.loadLeaderboard());
        try {
            const data = await this.api(`/leaderboards/team/${this.team.id}?game=${encodeURIComponent(this.currentGame)}&page=${pager.page}&perPage=${pager.perPage}`);
            if (!window.EduGamesRankPager.accept(this.fields.leaderboard, key, data, pager.ticket)) return;
            this.rankTotal = data.pagination?.total;
            if (request !== this.rankRequest) return;
            this.renderTeamMemberLeaderboard(Array.isArray(data.leaderboard) ? data.leaderboard : []);
        } catch (error) {
            window.EduGamesRankPager.fail(this.fields.leaderboard, pager.ticket);
        }
    }

    renderTeamMemberLeaderboard(rows) {
        this.fields.status.textContent = `${this.team.name} 팀 · 참여자 ${this.rankTotal ?? rows.length}명`;

        if (!rows.length) {
            this.fields.leaderboard.innerHTML = '<div class="eg-empty">아직 기록된 순위가 없습니다.</div>';
            window.EduGamesRankPager?.mount(this.fields.leaderboard, `${this.currentGame}:team:${this.team.id}`);
            return;
        }

        this.fields.leaderboard.innerHTML = `
            <div class="eg-board-row eg-board-head">
                <span>순위</span>
                <span>플레이어</span>
                <span>점수</span>
                <span>정확도</span>
            </div>
            ${rows.map((row) => `
                <div data-rank-row class="eg-board-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
                    <strong>${Number(row.rank) || "-"}</strong>
                    <span>${escapeHtml(row.playerName || "플레이어")}</span>
                    <b>${Number(row.score) || 0}</b>
                    <span>${Number(row.accuracy || 0).toFixed(1)}%</span>
                </div>
            `).join("")}
        `;
        window.EduGamesRankPager?.mount(this.fields.leaderboard, `${this.currentGame}:team:${this.team.id}`);
    }

    async loadTeamAdminList() {
        if (!this.fields.teamAdminList) {
            return;
        }

        try {
            const teams = await this.api("/teams");
            this.renderTeamAdminList(Array.isArray(teams) ? teams : []);
        } catch (error) {
            this.fields.teamAdminList.innerHTML = `<div class="eg-empty">${escapeHtml(error.message)}</div>`;
        }
    }

    renderTeamAdminList(teams) {
        const rows = teams.map((team) => `
            <div class="eg-team-admin-row">
                <form data-action="rename-team" data-team-id="${team.id}">
                    <input type="text" name="name" value="${escapeHtml(team.name)}" />
                    <button type="submit">이름 변경</button>
                </form>
                <span>코드: ${escapeHtml(team.inviteCode || "")}</span>
                <span>${Number(team.memberCount) || 0}명</span>
                <button type="button" data-click-action="reset-invite-code" data-team-id="${team.id}">코드 재발급</button>
                <button type="button" data-click-action="delete-team" data-team-id="${team.id}">삭제</button>
            </div>
        `).join("");

        this.fields.teamAdminList.innerHTML = `
            <form data-action="create-team" class="eg-team-form">
                <input type="text" name="name" placeholder="새 팀 이름" required />
                <button type="submit">팀 만들기</button>
            </form>
            ${rows || '<div class="eg-empty">아직 만든 팀이 없습니다.</div>'}
        `;
    }
}

document.querySelectorAll("[data-group-leaderboard]").forEach((root) => {
    if (!root.__GroupLeaderboard) {
        root.__GroupLeaderboard = new EduGroupLeaderboard(root);
    }
});
