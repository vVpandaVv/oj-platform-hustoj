(function (window) {
    'use strict';

    function Connection(options) {
        this.options = options;
        this.socket = null;
        this.ready = false;
        this.stopped = false;
        this.retryTimer = null;
        this.tries = 0;
        this.queue = [];
        this.deadlineTimer = null;
        this.heartbeatTimer = null;
    }

    Connection.prototype.start = function () {
        var self = this;
        if (self.stopped) {
            return;
        }

        self.options.onStatus && self.options.onStatus('connecting');
        var ticketTimer;
        var deadline = new Promise(function (_, reject) {
            ticketTimer = window.setTimeout(function () { reject(new Error('ticket-timeout')); }, 8000);
        });
        Promise.race([Promise.resolve().then(function () { return self.options.ticket(); }), deadline]).then(function (info) {
            if (self.stopped) {
                return;
            }
            if (!info || info.mode !== 'websocket' || !info.url || !info.ticket) {
                self.options.onStatus && self.options.onStatus('fallback');
                self.retryLater(15000);
                return;
            }
            self.open(info);
        }).catch(function () {
            if (self.stopped) { return; }
            self.options.onStatus && self.options.onStatus('fallback');
            self.retryLater();
        }).finally(function () { window.clearTimeout(ticketTimer); });
    };

    Connection.prototype.open = function (info) {
        var self = this;
        var socket;
        try {
            socket = new WebSocket(info.url);
        } catch (error) {
            self.options.onStatus && self.options.onStatus('fallback');
            self.retryLater();
            return;
        }

        self.socket = socket;
        self.deadlineTimer = window.setTimeout(function () { socket.close(); }, 8000);
        var lastMessageAt = Date.now();
        socket.onopen = function () {
            socket.send(JSON.stringify({ t: 'hello', ticket: info.ticket }));
        };
        socket.onmessage = function (event) {
            if (self.stopped || self.socket !== socket) { return; }
            lastMessageAt = Date.now();
            var message;
            try {
                message = JSON.parse(event.data);
            } catch (error) {
                return;
            }

            if (message.t === 'ready') {
                window.clearTimeout(self.deadlineTimer);
                self.deadlineTimer = null;
                self.ready = true;
                self.tries = 0;
                self.flush();
                self.options.onStatus && self.options.onStatus('open', message);
                if (message.heartbeat && self.heartbeatTimer === null) {
                    self.heartbeatTimer = window.setInterval(function () {
                        if (Date.now() - lastMessageAt > 20000) { socket.close(); return; }
                        self.send({ t: 'ping' });
                    }, 5000);
                }
            } else if (message.t === 'error') {
                socket.close();
                return;
            }

            self.options.onMessage && self.options.onMessage(message);
        };
        socket.onerror = function () {
            // onclose owns reconnect and fallback handling.
        };
        socket.onclose = function () {
            if (self.socket !== socket) { return; }
            self.clearTimers();
            if (self.socket === socket) {
                self.socket = null;
            }
            self.ready = false;
            if (!self.stopped) {
                self.options.onStatus && self.options.onStatus('retry');
                self.retryLater();
            }
        };
    };

    Connection.prototype.retryLater = function (delay) {
        var self = this;
        if (self.stopped || self.retryTimer !== null) {
            return;
        }
        self.tries += 1;
        var wait = typeof delay === 'number' ? delay : Math.min(15000, 500 * Math.pow(2, Math.min(5, self.tries - 1)));
        self.retryTimer = window.setTimeout(function () {
            self.retryTimer = null;
            self.start();
        }, wait);
    };

    Connection.prototype.send = function (message) {
        if (this.ready && this.socket && this.socket.readyState === WebSocket.OPEN) {
            try {
                this.socket.send(JSON.stringify(message));
                return true;
            } catch (error) { this.socket.close(); }
        }

        // Classroom updates are persisted through REST (or mirrored by a
        // snapshot) first. Replaying an old event after reconnect could
        // resurrect a signal or activity already cleared, so transient
        // notifications are never queued.
        if (message.t === 'participant-event' || message.t === 'event' || message.t === 'ping' || /^preview(?:-|$)/.test(message.t)) {
            return false;
        }

        if (message.t === 'snapshot') {
            this.queue = this.queue.filter(function (queued) { return queued.t !== 'snapshot' && queued.t !== 'preview'; });
        }
        this.queue.push(message);
        this.queue = this.queue.slice(-4);
        return false;
    };

    Connection.prototype.flush = function () {
        var queued = this.queue;
        this.queue = [];
        for (var i = 0; i < queued.length; i += 1) {
            this.send(queued[i]);
        }
    };

    Connection.prototype.clearTimers = function () {
        window.clearTimeout(this.deadlineTimer);
        window.clearInterval(this.heartbeatTimer);
        this.deadlineTimer = null;
        this.heartbeatTimer = null;
    };

    Connection.prototype.stop = function () {
        this.clearTimers();
        this.stopped = true;
        this.ready = false;
        if (this.retryTimer !== null) {
            window.clearTimeout(this.retryTimer);
            this.retryTimer = null;
        }
        if (this.socket) {
            this.socket.onclose = null;
            this.socket.close();
            this.socket = null;
        }
        this.queue = [];
        this.options.onStatus && this.options.onStatus('closed');
    };

    window.HttRealtime = {
        connect: function (options) {
            var connection = new Connection(options);
            connection.start();
            return connection;
        },
    };
})(window);
