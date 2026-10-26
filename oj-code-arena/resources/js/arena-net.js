/*
 * Code Arena browser connection: fetches a signed ticket from WordPress,
 * opens the WebSocket to the game server, does request/ack and reconnects.
 */
(function (window) {
    'use strict';

    function rest(cfg, method, route, body) {
        return fetch(cfg.restUrl + route, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: body === undefined ? undefined : JSON.stringify(body),
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) {
                    var err = new Error((data && data.message) || ('HTTP ' + res.status));
                    err.code = data && data.code;
                    err.status = res.status;
                    throw err;
                }
                return data;
            });
        });
    }

    /**
     * opts: { cfg, ticket: () => Promise<{url, ticket}>, onMessage(msg), onStatus(state, detail), onHello(ack) }
     * state: connecting | open | retry | failed | closed
     */
    function Connection(opts) {
        this.opts = opts;
        this.ws = null;
        this.rid = 0;
        this.pending = {};
        this.queue = [];
        this.stopped = false;
        this.tries = 0;
        this.ready = false;
    }

    // force: ask WordPress to push the class game to the game server again
    // (used once after the server answered "no-session", e.g. after a restart).
    Connection.prototype.start = function (force) {
        var self = this;
        if (self.stopped) return;
        self.ready = false;
        self.opts.onStatus && self.opts.onStatus('connecting');
        self.opts.ticket(!!force).then(function (t) {
            if (self.stopped) return;
            if (t.mode === 'poll') return self.startPoll(t);
            var ws = new WebSocket(t.url);
            self.ws = ws;
            ws.onopen = function () {
                self.send('hello', { ticket: t.ticket }).then(function (ack) {
                    if (!ack.ok && ack.why === 'no-session' && !self.forced) {
                        self.forced = true;
                        ws.onclose = null;
                        ws.close();
                        self.start(true);
                        return;
                    }
                    if (!ack.ok) {
                        self.stopped = true;
                        self.opts.onStatus && self.opts.onStatus('failed', ack.why);
                        ws.close();
                        return;
                    }
                    self.tries = 0;
                    self.ready = true;
                    self.opts.onStatus && self.opts.onStatus('open');
                    self.opts.onHello && self.opts.onHello(ack);
                    var q = self.queue;
                    self.queue = [];
                    q.forEach(function (raw) { ws.send(raw); });
                });
            };
            ws.onmessage = function (ev) {
                var msg;
                try { msg = JSON.parse(ev.data); } catch (e) { return; }
                if (msg.t === 'ack') {
                    var cb = self.pending[msg.rid];
                    if (cb) { delete self.pending[msg.rid]; cb(msg); }
                    return;
                }
                if (msg.t === 'closed') {
                    self.stopped = true;
                    self.opts.onStatus && self.opts.onStatus('closed', msg.reason);
                }
                self.opts.onMessage(msg);
            };
            ws.onclose = function () {
                self.ready = false;
                Object.keys(self.pending).forEach(function (k) { self.pending[k]({ ok: false, why: 'disconnected' }); });
                self.pending = {};
                if (!self.stopped) self.retry();
            };
            ws.onerror = function () { /* onclose follows */ };
        }).catch(function (err) {
            if (err && (err.status === 403 || err.status === 404)) {
                self.stopped = true;
                self.opts.onStatus && self.opts.onStatus('failed', err.message);
                return;
            }
            self.retry();
        });
    };

    // ── built-in mode: HTTP polling through relay.php (REST fallback) ──
    // Same messages as the WebSocket server; commands ride along with polls.

    var CLOSE_REASONS = { ended: 1, kicked: 1, idle: 1, timeout: 1, empty: 1, closed: 1 };
    var URGENT = { move: 0, poke: 0 };

    function ticketClaims(ticket) {
        try {
            var body = String(ticket).split('.')[0].replace(/-/g, '+').replace(/_/g, '/');
            var json = decodeURIComponent(Array.prototype.map.call(atob(body), function (c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            return JSON.parse(json);
        } catch (e) {
            return {};
        }
    }

    function restOnly() {
        try { return window.localStorage.getItem('oca.relay') === 'rest'; } catch (e) { return false; }
    }

    Connection.prototype.startPoll = function (t) {
        var self = this;
        var p = self.poll = {
            ticket: t.ticket,
            url: restOnly() ? t.rest : t.url,
            rest: t.rest,
            claims: ticketClaims(t.ticket),
            cursor: 0,
            arena: (self.poll && self.poll.arena) || null,
            cmds: [],
            busy: false,
            timer: 0,
            again: false,
            lastActive: 0,
            rooms: '',
            fails: 0,
        };
        self.pollNow(true);
        return p;
    };

    Connection.prototype.pollLater = function (ms) {
        var self = this;
        var p = self.poll;
        if (!p || self.stopped) return;
        clearTimeout(p.timer);
        p.timer = setTimeout(function () { self.pollNow(false); }, ms);
    };

    Connection.prototype.pollNow = function (hello) {
        var self = this;
        var p = self.poll;
        if (!p || self.stopped) return;
        if (p.busy) { p.again = true; return; }
        clearTimeout(p.timer);
        p.busy = true;
        var cmds = p.cmds;
        p.cmds = [];
        var sent = performance.now();
        fetch(p.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket: p.ticket, arena: p.arena, cursor: p.cursor, cmds: cmds }),
        }).then(function (res) {
            if (!res.ok && res.status !== 200) throw { http: res.status };
            return res.json();
        }).then(function (data) {
            p.busy = false;
            p.fails = 0;
            self.pollResult(data, hello, (performance.now() - sent) / 2);
        }).catch(function (err) {
            p.busy = false;
            p.cmds = cmds.concat(p.cmds);
            // relay.php blocked or not runnable here → use the REST route from now on.
            if (p.url !== p.rest && p.rest) {
                p.url = p.rest;
                try { window.localStorage.setItem('oca.relay', 'rest'); } catch (e) { /* storage off */ }
                return self.pollNow(hello);
            }
            p.fails++;
            if (p.fails > 12) {
                self.stopped = true;
                self.opts.onStatus && self.opts.onStatus('failed', 'unreachable');
                return;
            }
            self.opts.onStatus && self.opts.onStatus('retry', p.fails);
            self.pollLater(Math.min(8000, 500 * p.fails));
        });
    };

    Connection.prototype.pollResult = function (data, hello, lag) {
        var self = this;
        var p = self.poll;
        if (!data || !data.ok) {
            var why = data && data.why;
            if (why === 'no-session' && !self.forced) {
                self.forced = true;
                self.poll = null;
                return self.start(true);
            }
            if (why === 'bad-ticket') {
                self.poll = null;
                return self.start(false);
            }
            self.stopped = true;
            self.ready = false;
            if (CLOSE_REASONS[why] && !hello) {
                self.opts.onStatus && self.opts.onStatus('closed', why);
            } else {
                self.opts.onStatus && self.opts.onStatus('failed', why);
            }
            return;
        }
        self.renewTicket();
        p.cursor = data.cursor;
        p.arena = data.arena;
        if (!self.ready) {
            self.ready = true;
            self.tries = 0;
            self.opts.onStatus && self.opts.onStatus('open');
            var c = p.claims;
            self.opts.onHello && self.opts.onHello({ ok: true, me: { uid: c.uid, name: c.name, role: c.role, look: { avatar: c.uid % 8, color: (c.uid * 3) % 8 } } });
        }
        if (data.rooms) {
            var key = JSON.stringify(data.rooms);
            if (key !== p.rooms) {
                p.rooms = key;
                self.opts.onMessage({ t: 'rooms', rooms: data.rooms });
            }
        }
        (data.acks || []).forEach(function (ack) {
            if (ack.lobby) { p.arena = 'lobby'; p.cursor = 0; }
            var cb = self.pending[ack.rid];
            if (cb) { delete self.pending[ack.rid]; cb(Object.assign({ t: 'ack' }, ack)); }
        });
        var events = data.events || [];
        events.forEach(function (msg) {
            if (self.stopped) return;
            self.fixTimers(msg, lag);
            if (msg.t === 'arena') self.fillYou(msg);
            if (msg.t === 'closed') {
                self.stopped = true;
                self.opts.onStatus && self.opts.onStatus('closed', msg.reason);
            }
            self.opts.onMessage(msg);
        });
        if (self.stopped) return;
        if (events.length || p.cmds.length) p.lastActive = performance.now();
        if (p.again || p.cmds.length) {
            p.again = false;
            return self.pollNow(false);
        }
        var active = performance.now() - p.lastActive < 6000;
        self.pollLater(p.arena === 'lobby' ? 1500 : (active ? 350 : 800));
    };

    // Tickets live 5 minutes. Fetch a new one from WordPress (which checks
    // the learner's rights again) about 90 s before it runs out.
    Connection.prototype.renewTicket = function () {
        var self = this;
        var p = self.poll;
        if (!p || p.renewing || !p.claims.exp || p.claims.exp * 1000 - Date.now() > 90000) return;
        p.renewing = true;
        self.opts.ticket(false).then(function (t) {
            if (self.poll === p && t && t.ticket) {
                p.ticket = t.ticket;
                p.claims = ticketClaims(t.ticket);
            }
            p.renewing = false;
        }).catch(function (err) {
            p.renewing = false;
            if (err && (err.status === 403 || err.status === 404)) {
                self.stopped = true;
                self.opts.onStatus && self.opts.onStatus('failed', err.message);
            }
        });
    };

    // Timers in messages are "ms from now" on the server; take off the
    // network delay so countdowns stay honest.
    Connection.prototype.fixTimers = function (msg, lag) {
        var cut = function (o, k) { if (o && typeof o[k] === 'number' && o[k] > 0) o[k] = Math.max(0, o[k] - lag); };
        ['ms', 'endMs', 'startMs', 'remainMs', 'hintMs'].forEach(function (k) { cut(msg, k); });
        if (msg.t === 'state') {
            (msg.occ || []).forEach(function (o) { cut(o, 'ms'); });
            (msg.incoming || []).forEach(function (o) { cut(o, 'ms'); });
            cut(msg.boss, 'ms');
            cut(msg.question, 'ms');
            if (msg.fx) Object.keys(msg.fx).forEach(function (k) { cut(msg.fx, k); });
        }
        if (msg.round) { cut(msg.round, 'remainMs'); cut(msg.round, 'startMs'); }
    };

    // The relay sends one arena detail to everybody; add this viewer's part.
    Connection.prototype.fillYou = function (msg) {
        var c = this.poll.claims;
        msg.role = c.role;
        msg.you = null;
        if (c.role !== 'learner') return;
        var found = null;
        (msg.teams || []).forEach(function (t) {
            t.members.forEach(function (m) { if (m.uid === c.uid) found = t.no; });
        });
        (msg.waiting || []).forEach(function (m) { if (m.uid === c.uid) found = 0; });
        if (found !== null) msg.you = { uid: c.uid, team: found, host: msg.host === c.uid };
    };

    Connection.prototype.pollSend = function (msg) {
        var p = this.poll;
        if (msg.t === 'move' || msg.t === 'poke') {
            // Coalesce moves into the next poll (at most ~120 ms away).
            p.cmds.push(msg);
            if (!p.busy) this.pollLater(120);
            return;
        }
        p.cmds.push(msg);
        p.lastActive = performance.now();
        this.pollNow(false);
    };

    Connection.prototype.retry = function () {
        var self = this;
        self.tries++;
        if (self.tries > 12) {
            self.stopped = true;
            self.opts.onStatus && self.opts.onStatus('failed', 'unreachable');
            return;
        }
        self.opts.onStatus && self.opts.onStatus('retry', self.tries);
        setTimeout(function () { self.start(); }, Math.min(8000, 600 * self.tries));
    };

    // Sends { t, ...data }. Returns a promise for the ack when wantAck.
    Connection.prototype.send = function (t, data, noAck) {
        var self = this;
        var msg = Object.assign({ t: t }, data || {});
        if (!noAck) msg.rid = ++self.rid;
        if (self.poll) {
            self.pollSend(msg);
            if (noAck) return null;
            return new Promise(function (resolve) {
                self.pending[msg.rid] = resolve;
                setTimeout(function () {
                    if (self.pending[msg.rid]) { delete self.pending[msg.rid]; resolve({ ok: false, why: 'timeout' }); }
                }, 10000);
            });
        }
        var raw = JSON.stringify(msg);
        var open = self.ws && self.ws.readyState === 1 && (self.ready || t === 'hello');
        if (open) self.ws.send(raw);
        else if (t !== 'move' && t !== 'poke') self.queue.push(raw);
        if (noAck) return null;
        return new Promise(function (resolve) {
            self.pending[msg.rid] = resolve;
            setTimeout(function () {
                if (self.pending[msg.rid]) { delete self.pending[msg.rid]; resolve({ ok: false, why: 'timeout' }); }
            }, 8000);
        });
    };

    Connection.prototype.fire = function (t, data) {
        return this.send(t, data, true);
    };

    Connection.prototype.stop = function () {
        this.stopped = true;
        if (this.ws) this.ws.close();
        if (this.poll) clearTimeout(this.poll.timer);
    };

    var WHY = {
        'bad-ticket': '입장권이 만료되었어요. 페이지를 새로 고쳐 주세요.',
        'no-session': '게임 서버에 수업이 아직 열리지 않았어요.',
        'not-in-roster': '이 수업 명단에 없어서 들어갈 수 없어요.',
        'kicked': '선생님이 이 수업에서 내보냈어요.',
        'full': '자리가 다 찼어요.',
        'started': '이미 경기가 시작된 방이에요.',
        'closed': '닫힌 방이에요.',
        'free-off': '자유 대전이 꺼져 있어요.',
        'dup': '다른 창에서 같은 계정으로 접속해서 이 창은 연결을 끊었어요.',
        'ended': '수업이 끝났어요.',
        'idle': '한동안 입력이 없어서 방을 닫았어요.',
        'timeout': '오래 시작하지 않아서 방을 닫았어요.',
        'empty': '방에 아무도 없어서 닫았어요.',
        'unreachable': '게임 서버에 연결할 수 없어요. 선생님께 알려 주세요.',
        'too-soon': '방은 30초에 하나만 만들 수 있어요.',
        'too-many-rooms': '방이 너무 많아요. 다른 방에 들어가 보세요.',
        'no-room': '방이 없어졌어요.',
        'not-host': '방장만 할 수 있어요.',
        'no-players': '참가자가 없어요.',
        'no-problems': '선택한 태그에 문제가 없어요.',
        'playing': '경기 중에는 바꿀 수 없어요.',
        'teacher-picks': '팀은 선생님이 정해요.',
        'attacks-off': '지금은 공격 아이템을 쓸 수 없어요.',
        'no-target': '공격할 팀이 없어요.',
        'frozen': '얼어서 움직일 수 없어요!',
        'empty-slot': '빈 칸이에요.',
    };

    window.ArenaNet = {
        rest: rest,
        Connection: Connection,
        why: function (code) { return WHY[code] || code || '알 수 없는 오류'; },
    };
})(window);
