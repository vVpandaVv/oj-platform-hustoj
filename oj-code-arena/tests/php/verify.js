'use strict';

// Helper for tests/php/run.php: checks PHP-made tickets/signatures with the
// game server's own sign.js, and returns a Node-made signature for PHP.
const path = require('node:path');
const sign = require(path.join(__dirname, '..', '..', 'game-server', 'src', 'sign.js'));

const [secret, ticket, time, signature, bodyB64] = process.argv.slice(2);
const body = Buffer.from(bodyB64, 'base64').toString('utf8');
process.stdout.write(JSON.stringify({
    ticket: sign.readTicket(secret, ticket),
    request: sign.verifyRequest(secret, 'POST', '/server/results', time, signature, body),
    nodeSig: sign.signRequest(secret, 'POST', '/server/results', body),
}));
