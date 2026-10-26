'use strict';

// Builds data/default-bank.json from data/default-bank.txt.
// Usage: node tools/build-bank.js
const fs = require('node:fs');
const path = require('node:path');
const Bank = require('../shared/arena-bank');

const root = path.join(__dirname, '..');
const text = fs.readFileSync(path.join(root, 'data', 'default-bank.txt'), 'utf8');
const { items, errors } = Bank.parse(text);
if (errors.length) {
    for (const e of errors) console.error(`line ${e.line}: ${e.text}`);
    process.exit(1);
}
const seen = new Set();
const normal = [];
const boss = [];
for (const p of items) {
    const key = p.kind + '|' + p.code.replace(/\s+/g, '') + (p.kind === 'boss' ? '|' + p.title : '');
    if (seen.has(key)) continue;
    seen.add(key);
    if (p.kind === 'normal') normal.push({ code: p.code, tags: p.tags });
    else boss.push({ title: p.title, code: p.code, input: p.input, answers: p.answers, blank: p.blank, tags: p.tags });
}
const tags = [...new Set(items.flatMap((p) => p.tags))];
fs.writeFileSync(path.join(root, 'data', 'default-bank.json'), JSON.stringify({ normal, boss, tags }, null, 1) + '\n');
console.log(`normal ${normal.length}, boss ${boss.length}, tags ${tags.join(', ')}`);
