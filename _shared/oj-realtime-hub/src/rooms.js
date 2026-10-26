'use strict';

class Rooms {
    constructor() { this.channels = new Map(); }

    channel(app, channel) {
        if (![app, channel].every((value) => /^[a-z0-9][a-z0-9-]{0,47}$/.test(value))) throw new Error('Invalid room namespace');
        const key = `${app}/${channel}`;
        if (!this.channels.has(key)) this.channels.set(key, new Map());
        return this.channels.get(key);
    }

    status(app) {
        const channels = {};
        for (const [key, rooms] of this.channels) {
            if (key.startsWith(app + '/')) channels[key.slice(app.length + 1)] = rooms.size;
        }
        return { channels, rooms: Object.values(channels).reduce((sum, count) => sum + count, 0) };
    }
}

module.exports = { Rooms };
