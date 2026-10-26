const escapeHtml = (value) => String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

class EduPlayerStats {
    constructor(root) {
        this.root = root;
        this.config = this.readConfig();
        this.fields = {
            status: root.querySelector('[data-field="status"]'),
            summary: root.querySelector('[data-field="summary"]'),
            games: root.querySelector('[data-field="games"]'),
            recent: root.querySelector('[data-field="recent"]'),
        };

        this.load();
    }

    readConfig() {
        try {
            return JSON.parse(this.root.dataset.config || "{}");
        } catch (error) {
            return {};
        }
    }

    async load() {
        if (!this.config.isLoggedIn) {
            this.fields.status.textContent = "로그인이 필요합니다.";
            this.fields.summary.innerHTML = '<div class="eg-empty">로그인하면 실전 점수, 정확도, 최근 기록을 확인할 수 있습니다.</div>';
            return;
        }

        try {
            const headers = {};
            if (this.config.nonce) {
                headers["X-WP-Nonce"] = this.config.nonce;
            }

            const response = await window.fetch(`${this.config.restUrl}/players/me/stats`, {
                credentials: "same-origin",
                headers,
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data && data.message ? data.message : "통계를 불러오지 못했습니다.");
            }

            this.render(data);
        } catch (error) {
            this.fields.status.textContent = "오류";
            this.fields.summary.innerHTML = `<div class="eg-empty">${escapeHtml(error.message || "통계를 불러오지 못했습니다.")}</div>`;
        }
    }

    render(data) {
        const summary = data.summary || {};
        this.fields.status.textContent = `${escapeHtml(this.config.playerName || "플레이어")}님의 실전 기록`;
        this.fields.summary.innerHTML = `
            <div class="eg-stat-card">
                <span>최고 점수</span>
                <strong>${Number(summary.bestScore) || 0}</strong>
            </div>
            <div class="eg-stat-card">
                <span>평균 점수</span>
                <strong>${Number(summary.averageScore || 0).toFixed(1)}</strong>
            </div>
            <div class="eg-stat-card">
                <span>정확도</span>
                <strong>${Number(summary.averageAccuracy || 0).toFixed(1)}%</strong>
            </div>
            <div class="eg-stat-card">
                <span>해결 문제</span>
                <strong>${Number(summary.correctCount) || 0}</strong>
            </div>
        `;
        this.renderGames(Array.isArray(data.games) ? data.games : []);
        this.renderRecent(Array.isArray(data.recent) ? data.recent : []);
    }

    renderGames(games) {
        if (!games.length) {
            this.fields.games.innerHTML = '<div class="eg-empty">아직 게임 기록이 없습니다.</div>';
            return;
        }

        this.fields.games.innerHTML = games.map((game) => `
            <div class="eg-stats-row">
                <strong>${escapeHtml(game.gameSlug)}</strong>
                <span>최고 ${Number(game.bestScore) || 0}점</span>
                <span>평균 ${Number(game.averageScore || 0).toFixed(1)}점</span>
                <span>정확도 ${Number(game.averageAccuracy || 0).toFixed(1)}%</span>
            </div>
        `).join("");
    }

    renderRecent(rows) {
        if (!rows.length) {
            this.fields.recent.innerHTML = '<div class="eg-empty">최근 실전 기록이 없습니다.</div>';
            return;
        }

        this.fields.recent.innerHTML = rows.map((row) => `
            <div class="eg-stats-row">
                <strong>${Number(row.score) || 0}점</strong>
                <span>${escapeHtml(row.gameSlug)}</span>
                <span>${Number(row.correctCount) || 0}문제</span>
                <span>${Number(row.accuracy || 0).toFixed(1)}%</span>
            </div>
        `).join("");
    }
}

document.querySelectorAll("[data-player-stats]").forEach((root) => {
    if (!root.__PlayerStats) {
        root.__PlayerStats = new EduPlayerStats(root);
    }
});
