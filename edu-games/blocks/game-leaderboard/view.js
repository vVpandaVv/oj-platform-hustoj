const escapeHtml = (value) => String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

class EduGameLeaderboard {
    constructor(root) {
        this.root = root;
        this.config = this.readConfig();
        this.games = Array.isArray(this.config.games) ? this.config.games.filter((game) => game && game.id) : [];
        this.currentGame = this.config.defaultGame || (this.games[0] && this.games[0].id) || "character-code";
        this.fields = {
            tabs: root.querySelector('[data-field="game-tabs"]'),
            status: root.querySelector('[data-field="status"]'),
            leaderboard: root.querySelector('[data-field="leaderboard"]'),
        };

        this.bindEvents();
        this.renderTabs();
        this.load();
    }

    readConfig() {
        try {
            return JSON.parse(this.root.dataset.config || "{}");
        } catch (error) {
            return {};
        }
    }

    bindEvents() {
        this.root.addEventListener("click", (event) => {
            const button = event.target.closest("[data-game-id]");
            if (!button) {
                return;
            }

            this.currentGame = button.dataset.gameId;
            this.renderTabs();
            this.load();
        });
    }

    renderTabs() {
        if (!this.fields.tabs) {
            return;
        }

        const games = this.games.length ? this.games : [{ id: "character-code", title: "문자 코드 암호 게임" }];
        this.fields.tabs.innerHTML = games.map((game) => `
            <button type="button" class="${game.id === this.currentGame ? "is-active" : ""}" data-game-id="${escapeHtml(game.id)}">
                ${escapeHtml(game.title || game.id)}
            </button>
        `).join("");
    }

    async load() {
        const request = this.rankRequest = (this.rankRequest || 0) + 1;
        if (!this.config.restUrl) {
            this.showError("REST API 주소가 없습니다.");
            return;
        }

        this.fields.status.textContent = "순위표를 불러오는 중입니다.";

        const key = this.currentGame;
        const pager = window.EduGamesRankPager.request(this.fields.leaderboard, key, () => this.load());
        try {
            const response = await window.fetch(`${this.config.restUrl}/leaderboards/game/${this.currentGame}?page=${pager.page}&perPage=${pager.perPage}`, {
                credentials: "same-origin",
                headers: this.config.nonce ? { "X-WP-Nonce": this.config.nonce } : {},
            });
            const data = await response.json();
            if (!window.EduGamesRankPager.accept(this.fields.leaderboard, key, data, pager.ticket)) return;
            this.rankTotal = data.pagination?.total;
            if (request !== this.rankRequest) return;

            if (!response.ok) {
                throw new Error(data && data.message ? data.message : "순위표를 불러오지 못했습니다.");
            }

            this.renderLeaderboard(Array.isArray(data.leaderboard) ? data.leaderboard : [], data.myRank || null);
        } catch (error) {
            window.EduGamesRankPager.fail(this.fields.leaderboard, pager.ticket);
        }
    }

    renderLeaderboard(rows, myRank) {
        this.fields.status.textContent = `전체 참여자 ${this.rankTotal ?? rows.length}명${myRank ? ` · 내 순위: ${myRank}위` : ''}`;

        if (!rows.length) {
            this.fields.leaderboard.innerHTML = '<div class="eg-empty">아직 기록된 순위가 없습니다.</div>';
            window.EduGamesRankPager?.mount(this.fields.leaderboard, this.currentGame);
            return;
        }

        this.fields.leaderboard.innerHTML = `
            <div class="eg-board-row eg-board-head">
                <span>순위</span>
                <span>플레이어</span>
                <span>점수</span>
                <span>정확도</span>
                <span>해결</span>
                <span>시간</span>
            </div>
            ${rows.map((row) => `
                <div data-rank-row class="eg-board-row">
                    <strong>${Number(row.rank) || "-"}</strong>
                    <span>${escapeHtml(row.playerName || "플레이어")}</span>
                    <b>${Number(row.score) || 0}</b>
                    <span>${Number(row.accuracy || 0).toFixed(1)}%</span>
                    <span>${Number(row.correctCount) || 0}</span>
                    <span>${Number(row.durationSeconds) || 0}초</span>
                </div>
            `).join("")}
        `;
        window.EduGamesRankPager?.mount(this.fields.leaderboard, this.currentGame);
    }

    showError(message) {
        this.fields.status.textContent = "오류";
        this.fields.leaderboard.innerHTML = `<div class="eg-empty">${escapeHtml(message)}</div>`;
    }
}

document.querySelectorAll("[data-game-leaderboard]").forEach((root) => {
    if (!root.__GameLeaderboard) {
        root.__GameLeaderboard = new EduGameLeaderboard(root);
    }
});
