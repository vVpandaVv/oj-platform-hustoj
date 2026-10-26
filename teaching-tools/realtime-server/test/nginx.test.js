'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { matchingBrace, serverBlocks } = require('../src/install-nginx');

test('finds the complete HTTPS server block with nested locations', () => {
    const text = `
server {
    listen 80;
}
server {
    listen 443 ssl;
    location / {
        try_files $uri /index.php;
    }
}
`;
    const blocks = serverBlocks(text);
    assert.equal(blocks.length, 2);
    assert.match(blocks[1].text, /listen 443 ssl/);
    assert.equal(text[matchingBrace(text, blocks[1].open)], '}');
});
