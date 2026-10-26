(() => {
    const STORAGE_KEY = "edu-games-device-matching-best";
    const SCRIPT_VERSION = "card-faces-20261020";

    const escapeHtml = (value) => String(value || "")
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

    class DeviceMatchingGame {
        constructor(root) {
            this.root = root;
            this.config = this.readConfig();
            this.rules = this.normalizeRules(this.config.rules || {});
            this.fields = {
                stage: root.querySelector('[data-field="stage"]'),
                stars: root.querySelector('[data-field="stars"], [data-field="lives"]'),
                score: root.querySelector('[data-field="score"]'),
                streak: root.querySelector('[data-field="streak"]'),
                rank: root.querySelector('[data-field="rank"]'),
                stageMeta: root.querySelector('[data-field="stage-meta"]'),
                stageTitle: root.querySelector('[data-field="stage-title"]'),
                progress: root.querySelector('[data-field="progress"]'),
                deck: root.querySelector('[data-field="deck"]'),
                feedback: root.querySelector('[data-field="feedback"]'),
                startButton: root.querySelector('[data-action="start"]'),
                nextButton: root.querySelector('[data-action="next-stage"]'),
                leaderboard: root.querySelector('[data-field="leaderboard"]'),
                rankStatus: root.querySelector('[data-field="rank-status"]'),
            };
            this.personalStorageKey = `${STORAGE_KEY}:${this.config.currentUserId || 0}`;
            this.bestScore = 0;
            try { this.bestScore = Number(window.localStorage.getItem(this.personalStorageKey)) || 0; } catch { /* Storage can be disabled (private mode, blocked site data). */ }
            try {
                const saved = JSON.parse(localStorage.getItem(`${this.personalStorageKey}:mastery-1`) || '{}');
                this.mastery = saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
            } catch { this.mastery = {}; }
            this.saveInProgress = false;
            this.sessionRefreshPromise = null;
            this.timers = [];
            this.state = this.initialState();

            this.bindEvents();
            this.render();
            if (window.ResizeObserver) {
                let width = -1, frame = 0;
                this.deckObserver = new ResizeObserver(entries => {
                    const nextWidth = entries[0].contentRect.width;
                    if (Math.abs(nextWidth - width) < 0.5) return;
                    width = nextWidth;
                    // Changing grid tracks inside this callback re-enters WebKit's resize delivery.
                    if (!frame) frame = requestAnimationFrame(() => { frame = 0; this.updateDeckColumns(); });
                });
                this.deckObserver.observe(this.fields.deck);
            } else {
                window.addEventListener("resize", () => this.updateDeckColumns());
            }
            this.loadLeaderboard();
            this.account = window.EduGamesProgress?.connect(this.config, this.root, data => {
                this.mastery = data.records || {}; this.bestScore = Number(data.bestScore) || 0; this.renderStats();
            });
        }

        readConfig() {
            try {
                return JSON.parse(this.root.dataset.config || "{}");
            } catch (error) {
                return {};
            }
        }

        normalizeRules(rules) {
            const stages = Array.isArray(rules.stages) && rules.stages.length
                ? rules.stages.map((stage, index) => ({
                    stage: Number(stage.stage) || index + 1,
                    pairs: Math.max(2, Number(stage.pairs) || 6),
                }))
                : [
                    { stage: 1, pairs: 6 },
                    { stage: 2, pairs: 8 },
                    { stage: 3, pairs: 10 },
                    { stage: 4, pairs: 12 },
                ];
            const devices = Array.isArray(rules.devices) ? rules.devices : [];

            return {
                stages,
                devices,
                maxStars: Math.max(1, Number(rules.maxStars) || 3),
                stageRating: {
                    twoStarMaxMistakes: Math.max(0, Number(rules.stageRating && rules.stageRating.twoStarMaxMistakes) || 2),
                    oneStarMaxMistakes: Math.max(0, Number(rules.stageRating && rules.stageRating.oneStarMaxMistakes) || 4),
                },
                mismatchRevealMs: Math.max(500, Number(rules.mismatchRevealMs) || 950),
                scoring: {
                    matchBase: Number(rules.scoring && rules.scoring.matchBase) || 100,
                    stageBonusPerMatch: Number(rules.scoring && rules.scoring.stageBonusPerMatch) || 15,
                    streakBonus: Number(rules.scoring && rules.scoring.streakBonus) || 10,
                    maxStreakBonus: Number(rules.scoring && rules.scoring.maxStreakBonus) || 60,
                    mismatchPenalty: Number(rules.scoring && rules.scoring.mismatchPenalty) || 20,
                    stageClearBaseBonus: Number(rules.scoring && rules.scoring.stageClearBaseBonus) || 100,
                    starBonus: Number(rules.scoring && rules.scoring.starBonus) || 60,
                    completionBonus: Number(rules.scoring && rules.scoring.completionBonus) || 300,
                },
            };
        }

        initialState() {
            return {
                status: "idle",
                stageIndex: 0,
                score: 0,
                streak: 0,
                totalMatches: 0,
                attempts: 0,
                mistakes: 0,
                stageMistakes: 0,
                totalStars: 0,
                stageResults: [],
                completedStages: 0,
                startedAt: 0,
                durationSeconds: 0,
                cards: [],
                seenDeviceIds: [],
                openedIds: [],
                locked: false,
                myRank: null,
                leaderboard: [],
                endReason: "",
            };
        }

        bindEvents() {
            this.root.addEventListener("click", (event) => {
                const actionButton = event.target.closest("[data-action]");
                if (actionButton) {
                    if (actionButton.dataset.action === "start") {
                        this.startGame();
                    }

                    if (actionButton.dataset.action === "next-stage") {
                        this.nextStage();
                    }
                    return;
                }

                const cardButton = event.target.closest("[data-card-id]");
                if (cardButton) {
                    this.flipCard(cardButton.dataset.cardId);
                }
            });
        }

        startGame() {
            this.clearTimers();
            this.state = this.initialState();
            this.state.status = "playing";
            this.state.startedAt = performance.now();
            // The token embedded in the page's initial config is single-use;
            // replaying without a page reload needs a fresh one or the
            // eventual score save is rejected no matter how high the score
            // is. Kick this off now (fire-and-forget) so it's ready by the
            // time finishGame() actually needs it.
            this.sessionRefreshPromise = this.refreshSessionToken();
            this.prepareStage();
            this.setFeedback("도전이 시작되었습니다. 사진 카드와 설명 카드를 짝지어 보세요.");
        }

        prepareStage() {
            const stage = this.currentStage();
            const seen = new Set(this.state.seenDeviceIds);
            const fresh = shuffle(this.rules.devices.filter(device => !seen.has(device.id)));
            const previous = shuffle(this.rules.devices.filter(device => seen.has(device.id)));
            const devices = [...fresh, ...previous].slice(0, Math.min(stage.pairs, this.rules.devices.length));
            this.state.seenDeviceIds = [...new Set([...seen, ...devices.map(device => device.id)])];
            if (!devices.length) {
                this.state.cards = [];
                this.state.openedIds = [];
                this.state.locked = true;
                this.render();
                this.setFeedback("카드 데이터를 불러오지 못했습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.");
                return;
            }

            // Card ids are opaque: the pair lives only in pairId/type, so the page
            // markup does not give the answer away.
            const cardId = () => `card-${this.state.stageIndex}-${Math.random().toString(36).slice(2, 10)}`;
            const cards = devices.flatMap((device) => [
                {
                    id: cardId(),
                    pairId: device.id,
                    type: "image",
                    device,
                    faceUp: false,
                    matched: false,
                },
                {
                    id: cardId(),
                    pairId: device.id,
                    type: "text",
                    device,
                    faceUp: false,
                    matched: false,
                },
            ]);

            this.state.cards = shuffle(cards);
            this.state.openedIds = [];
            this.state.locked = false;
            this.state.stageMistakes = 0;
            this.render();
        }

        currentStage() {
            return this.rules.stages[this.state.stageIndex] || this.rules.stages[0];
        }

        flipCard(cardId) {
            if (this.state.status !== "playing" || this.state.locked) {
                return;
            }

            const card = this.state.cards.find((item) => item.id === cardId);
            if (!card || card.faceUp || card.matched) {
                return;
            }

            card.faceUp = true;
            this.state.openedIds.push(card.id);
            this.renderDeck();

            if (this.state.openedIds.length === 2) {
                this.evaluateOpenCards();
            }
        }

        evaluateOpenCards() {
            const opened = this.state.openedIds
                .map((id) => this.state.cards.find((card) => card.id === id))
                .filter(Boolean);

            if (opened.length !== 2) {
                return;
            }

            this.state.locked = true;
            this.state.attempts += 1;

            if (opened[0].pairId === opened[1].pairId && opened[0].type !== opened[1].type) {
                this.handleMatch(opened);
                return;
            }

            this.handleMismatch(opened);
        }

        handleMatch(opened) {
            const gained = this.scoreForMatch();
            this.state.score += gained;
            this.state.streak += 1;
            this.state.totalMatches += 1;
            this.state.openedIds = [];
            this.renderStats();
            this.setFeedback(`${opened[0].device.name} 정답입니다. +${gained}점`);

            this.delay(() => {
                opened.forEach((card) => {
                    card.matched = true;
                });
                this.state.locked = false;
                this.render();

                if (this.stageMatchedCount() >= this.currentStage().pairs) {
                    this.completeStage();
                }
            }, 460);
        }

        handleMismatch(opened) {
            this.state.mistakes += 1;
            this.state.stageMistakes += 1;
            this.state.streak = 0;
            this.state.score = Math.max(0, this.state.score - this.rules.scoring.mismatchPenalty);
            this.renderStats();
            this.setFeedback(`오답입니다. 이번 단계 실수 ${this.state.stageMistakes}회로 기록되고, 카드는 다시 뒤집힙니다.`);

            this.delay(() => {
                opened.forEach((card) => {
                    card.faceUp = false;
                });
                this.state.openedIds = [];
                this.render();
                const closeMs = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 260;
                this.delay(() => {
                    this.state.locked = false;
                    this.render();
                }, closeMs);
            }, this.rules.mismatchRevealMs);
        }

        completeStage() {
            const stage = this.currentStage();
            const stars = this.starsForMistakes(this.state.stageMistakes);
            const bonus = this.rules.scoring.stageClearBaseBonus + (stars * this.rules.scoring.starBonus);
            this.state.score += bonus;
            this.state.totalStars += stars;
            this.state.stageResults.push({
                stage: stage.stage,
                pairs: stage.pairs,
                mistakes: this.state.stageMistakes,
                stars,
                bonus,
            });
            this.state.completedStages += 1;

            if (this.state.stageIndex >= this.rules.stages.length - 1) {
                this.state.score += this.rules.scoring.completionBonus;
                this.finishGame("completed");
                return;
            }

            this.state.status = "stage-clear";
            this.state.endReason = "";
            this.render();
            this.setFeedback(`${stage.stage}단계 완료. 실수 ${this.state.stageMistakes}회, 별 ${stars}개, 보너스 +${bonus}점입니다.`);
        }

        nextStage() {
            if (this.state.status !== "stage-clear") {
                return;
            }

            this.clearTimers();
            this.state.stageIndex += 1;
            this.state.status = "playing";
            this.prepareStage();
            this.setFeedback(`${this.currentStage().stage}단계입니다. 카드 쌍이 더 많아졌습니다.`);
        }

        async finishGame(reason) {
            this.state.status = "completed";
            this.state.endReason = reason;
            this.state.durationSeconds = this.elapsedSeconds();
            this.state.openedIds = [];
            this.state.locked = false;
            this.bestScore = Math.max(this.bestScore, this.state.score);
            try { window.localStorage.setItem(this.personalStorageKey, String(this.bestScore)); } catch { /* Storage can be disabled; the server record is unaffected. */ }
            this.mastery.stars = Math.max(Number(this.mastery.stars) || 0, this.state.totalStars);
            this.mastery.mistakes = Math.min(Number.isFinite(this.mastery.mistakes) ? this.mastery.mistakes : Infinity, this.state.mistakes);
            try { localStorage.setItem(`${this.personalStorageKey}:mastery-1`, JSON.stringify(this.mastery)); } catch { /* Local goal only; server score saving remains unchanged. */ }
            this.render();

            this.setFeedback(`4단계를 모두 완료했습니다. 최종 점수 ${this.state.score}점, 별 ${this.state.totalStars}개입니다.`);

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

        delay(callback, milliseconds) {
            const timer = window.setTimeout(() => {
                this.timers = this.timers.filter((item) => item !== timer);
                callback();
            }, milliseconds);

            this.timers.push(timer);
        }

        clearTimers() {
            this.timers.forEach((timer) => window.clearTimeout(timer));
            this.timers = [];
        }

        stageMatchedCount() {
            return this.state.cards.filter((card) => card.matched && card.type === "image").length;
        }

        scoreForMatch() {
            const scoring = this.rules.scoring;
            const stage = this.currentStage();
            const stageBonus = (stage.stage - 1) * scoring.stageBonusPerMatch;
            const streakBonus = Math.min(scoring.maxStreakBonus, this.state.streak * scoring.streakBonus);

            return scoring.matchBase + stageBonus + streakBonus;
        }

        starsForMistakes(mistakes) {
            if (mistakes === 0) {
                return this.rules.maxStars;
            }

            if (mistakes <= this.rules.stageRating.twoStarMaxMistakes) {
                return Math.max(0, this.rules.maxStars - 1);
            }

            if (mistakes <= this.rules.stageRating.oneStarMaxMistakes) {
                return Math.max(0, this.rules.maxStars - 2);
            }

            return 0;
        }

        elapsedSeconds() {
            if (!this.state.startedAt) {
                return this.state.durationSeconds;
            }

            return Math.max(1, Math.round((performance.now() - this.state.startedAt) / 1000));
        }

        render() {
            this.renderStats();
            this.renderStageHeader();
            this.renderDeck();
            this.fields.startButton.textContent = this.state.status === "idle" ? "도전 시작" : "다시 시작";
            this.fields.nextButton.hidden = this.state.status !== "stage-clear";
        }

        renderStats() {
            const goal = this.root.querySelector('[data-field="challenge-goal"]');
            if (goal) {
                const total = this.rules.stages.length * this.rules.maxStars;
                const target = (this.mastery.stars || 0) < total ? `전 단계 별 ${total}개` : this.mastery.mistakes > 0 ? `전체 오답 ${this.mastery.mistakes - 1}회 이하` : '무오답 완주와 최고점 경신';
                goal.textContent = `${this.account?.synced ? '계정' : '이 기기'} 수집 별 ${this.mastery.stars || 0} / ${total} · 다음 목표: ${target}`;
            }
            const stage = this.currentStage();
            this.setText(this.fields.stage, this.state.status === "idle" ? "-" : `${stage.stage} / ${this.rules.stages.length}`);
            this.setHtml(this.fields.stars, this.renderStars());
            this.setText(this.fields.score, String(this.state.score));
            this.setText(this.fields.streak, String(this.state.streak));
            this.setText(this.fields.rank, this.state.myRank ? `${this.state.myRank}위` : "-");
            this.setText(this.fields.progress, `${this.stageMatchedCount()} / ${this.state.status === "idle" ? 0 : stage.pairs}`);
        }

        setText(element, value) {
            if (element) {
                element.textContent = value;
            }
        }

        setHtml(element, value) {
            if (element) {
                element.innerHTML = value;
            }
        }

        renderStars() {
            if (this.state.status === "idle") {
                return "-";
            }

            const stars = this.starsForMistakes(this.state.stageMistakes);

            return Array.from({ length: this.rules.maxStars }, (_, index) => (
                `<span class="oj-device-star${index < stars ? " is-on" : ""}" aria-hidden="true">★</span>`
            )).join("");
        }

        renderStageHeader() {
            if (this.state.status === "idle") {
                this.fields.stageMeta.textContent = "대기 중";
                this.fields.stageTitle.textContent = "6쌍부터 시작해 12쌍까지 도전합니다";
                this.fields.feedback.textContent = "도전 시작을 누르면 카드가 섞입니다.";
                return;
            }

            if (this.state.status === "stage-clear") {
                this.fields.stageMeta.textContent = `${this.currentStage().stage}단계 완료`;
                this.fields.stageTitle.textContent = "다음 단계로 넘어갈 준비가 되었습니다";
                return;
            }

            if (this.state.status === "completed") {
                this.fields.stageMeta.textContent = "도전 완료";
                this.fields.stageTitle.textContent = "모든 카드 세트를 해결했습니다";
                return;
            }

            const stage = this.currentStage();
            this.fields.stageMeta.textContent = `${stage.stage}단계 · ${stage.pairs}쌍`;
            this.fields.stageTitle.textContent = "사진 카드와 설명 카드를 같은 의미로 연결하세요";
        }

        renderDeck() {
            if (this.state.status === "idle") {
                this.fields.deck.innerHTML = `
                    <div class="oj-device-empty">
                        <strong>카드 세트 준비</strong>
                        <span>실제 장치 사진과 설명 카드가 섞여 나옵니다.</span>
                    </div>
                `;
                return;
            }

            const active = document.activeElement;
            const focusedId = active && this.fields.deck.contains(active) ? active.dataset.cardId : null;
            const nodes = [...this.fields.deck.children];
            const sameDeck = nodes.length === this.state.cards.length && nodes.every((node, i) => node.dataset.cardId === this.state.cards[i].id);
            if (!sameDeck) {
                this.fields.deck.innerHTML = this.state.cards.map((card) => this.renderCard(card)).join("");
            } else {
                this.state.cards.forEach((card, i) => {
                    const node = nodes[i], front = node.querySelector('.oj-device-card-front');
                    const wasShown = node.classList.contains('is-face-up');
                    const shown = card.faceUp || card.matched;
                    if (shown && !front.firstElementChild) {
                        front.innerHTML = card.type === 'image' ? this.renderImageFace(card.device) : this.renderTextFace(card.device);
                    } else if (!shown && !wasShown) {
                        front.replaceChildren();
                    }
                    // Keep both faces mounted until the closing animation has finished.
                    if (wasShown !== shown) node.classList.add('has-turned');
                    node.classList.toggle('is-face-up', shown);
                    node.classList.toggle('is-matched', card.matched);
                    node.disabled = card.matched;
                    node.setAttribute('aria-label', shown ? `${card.device.name} ${card.type === 'image' ? '사진' : '설명'} 카드` : '뒤집힌 카드');
                    front.setAttribute('aria-hidden', String(!shown));
                    if (card.matched) { node.tabIndex = -1; node.setAttribute('aria-hidden', 'true'); }
                });
            }
            this.updateDeckColumns();
            if (focusedId) {
                // Same card again, or the next card still in play once it is matched.
                const index = this.state.cards.findIndex((card) => card.id === focusedId);
                const next = this.state.cards.slice(index).concat(this.state.cards.slice(0, index)).find((card) => !card.matched);
                const button = next ? this.fields.deck.querySelector(`[data-card-id="${CSS.escape(next.id)}"]`) : null;
                if (button) {
                    button.focus({ preventScroll: true });
                }
            }
        }

        updateDeckColumns() {
            const deck = this.fields.deck;
            const count = this.state.cards.length;
            if (!count || !deck.clientWidth) return;
            const style = window.getComputedStyle(deck);
            const gap = parseFloat(style.columnGap) || 0;
            const width = deck.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            const minimum = parseFloat(style.getPropertyValue("--device-card-min-width")) || 126;
            const fitting = Math.max(1, Math.floor((width + gap) / (minimum + gap)));
            const preferred = Math.min(6, Math.max(4, Math.ceil(count / 4)));
            let columns = Math.min(count, preferred, fitting);
            // Include matched cards so clearing a pair never moves the remaining cards.
            while (count % columns !== 0) columns -= 1;
            const value = String(columns);
            if (deck.style.getPropertyValue("--device-columns") !== value) {
                deck.style.setProperty("--device-columns", value);
            }
        }

        renderCard(card) {
            const classes = [
                "oj-device-card",
                card.faceUp || card.matched ? "is-face-up" : "",
                card.matched ? "is-matched" : "",
            ].filter(Boolean).join(" ");
            const shown = card.faceUp || card.matched;
            // Face-down cards carry no front content (it used to sit in the
            // markup, image and description included).
            const front = !shown ? "" : (card.type === "image" ? this.renderImageFace(card.device) : this.renderTextFace(card.device));

            return `
                <button type="button" class="${classes}" data-card-id="${escapeHtml(card.id)}" aria-label="${card.faceUp || card.matched ? escapeHtml(card.device.name) + ' ' + (card.type === 'image' ? '사진' : '설명') + ' 카드' : '뒤집힌 카드'}" ${card.matched ? 'disabled tabindex="-1" aria-hidden="true"' : ''}>
                    <span class="oj-device-card-inner">
                        <span class="oj-device-card-face oj-device-card-back" aria-hidden="true">
                            <span class="oj-device-card-mark">?</span>
                            <span>DEVICE</span>
                        </span>
                        <span class="oj-device-card-face oj-device-card-front" aria-hidden="${card.faceUp || card.matched ? 'false' : 'true'}">
                            ${front}
                        </span>
                    </span>
                </button>
            `;
        }

        renderImageFace(device) {
            return `
                <span class="oj-device-photo-wrap">
                    <img src="${escapeHtml(device.image)}" alt="${escapeHtml(device.imageAlt || device.name)}" loading="lazy">
                </span>
                <span class="oj-device-chip">${escapeHtml(device.categoryLabel || "")}</span>
            `;
        }

        renderTextFace(device) {
            return `
                <span class="oj-device-text-card">
                    <strong>${escapeHtml(device.name)}</strong>
                    <span>${escapeHtml(device.description)}</span>
                </span>
            `;
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
            if (!this.config.isLoggedIn || !this.config.restUrl || this.saveInProgress || this.state.score <= 0) {
                if (!this.config.isLoggedIn) {
                    this.setFeedback(`${this.fields.feedback.textContent} 로그인하면 점수와 순위가 저장됩니다.`);
                }
                return;
            }

            this.saveInProgress = true;
            const accuracy = this.state.attempts > 0
                ? Math.round((this.state.totalMatches / this.state.attempts) * 10000) / 100
                : 100;
            const payload = {
                score: this.state.score,
                roundsPlayed: this.state.attempts,
                correctCount: this.state.totalMatches,
                durationSeconds: this.state.durationSeconds,
                accuracy,
                meta: {
                    stages: this.rules.stages.map((stage) => stage.pairs),
                    completedStages: this.state.completedStages,
                    finalStage: this.currentStage().stage,
                    totalStars: this.state.totalStars,
                    stageResults: this.state.stageResults,
                    mistakes: this.state.mistakes,
                    endReason: this.state.endReason,
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

                if (data.score?.id) this.account?.save({ scoreId: Number(data.score.id) });
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
                this.saveInProgress = false;
            }
        }

        renderLeaderboard() {
            if (!this.fields.leaderboard) {
                return;
            }

            if (!this.state.leaderboard.length) {
                this.fields.rankStatus.textContent = "기록 없음";
                this.fields.leaderboard.innerHTML = '<div class="oj-device-rank-empty">아직 저장된 순위가 없습니다.</div>';
                window.EduGamesRankPager?.mount(this.fields.leaderboard, this.config.gameId);
                return;
            }

            this.fields.rankStatus.textContent = this.state.myRank ? `${this.state.myRank}위` : "전체 참여자";
            this.fields.leaderboard.innerHTML = `
                <div class="oj-device-rank-head">
                    <span>순위</span>
                    <span>플레이어</span>
                    <span>점수</span>
                </div>
                ${this.state.leaderboard.map((row) => `
                    <div data-rank-row class="oj-device-rank-row${Number(row.userId) === Number(this.config.currentUserId || 0) ? " is-me" : ""}">
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
        document.querySelectorAll("[data-device-matching]").forEach((root) => {
            let activeRoot = root;
            if (activeRoot.__DeviceMatching && activeRoot.__DeviceMatchingVersion !== SCRIPT_VERSION && activeRoot.parentNode) {
                const replacement = activeRoot.cloneNode(true);
                activeRoot.parentNode.replaceChild(replacement, activeRoot);
                activeRoot = replacement;
            }

            if (activeRoot.__DeviceMatchingVersion !== SCRIPT_VERSION) {
                activeRoot.__DeviceMatchingVersion = SCRIPT_VERSION;
                activeRoot.__DeviceMatching = new DeviceMatchingGame(activeRoot);
            }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
