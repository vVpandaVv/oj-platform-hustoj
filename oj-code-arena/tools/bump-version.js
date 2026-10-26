'use strict';

/*
 * Sets the version everywhere it is written:
 *   node tools/bump-version.js 1.0.0
 */
const fs = require('node:fs');
const path = require('node:path');

const version = process.argv[2];
if (!/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/.test(version || '')) {
    console.error('usage: node tools/bump-version.js X.Y.Z[-pre]');
    process.exit(1);
}
const root = path.join(__dirname, '..');

function edit(rel, pairs) {
    const file = path.join(root, rel);
    let text = fs.readFileSync(file, 'utf8');
    for (const [re, to] of pairs) {
        if (!re.test(text)) throw new Error(`${rel}: pattern ${re} not found`);
        text = text.replace(re, to);
    }
    fs.writeFileSync(file, text);
    console.log('updated ' + rel);
}

edit('oj-code-arena.php', [
    [/( \* Version: )\S+/, `$1${version}`],
    [/(define\('OJ_CODE_ARENA_VERSION', ')[^']+('\);)/, `$1${version}$2`],
]);
edit('tests/php/manifest.php', [[/(define\('OJ_CODE_ARENA_VERSION', ')[^']+('\);)/, `$1${version}$2`]]);
edit('game-server/package.json', [[/("version": ")[^"]+(")/, `$1${version}$2`]]);
edit('game-server/package-lock.json', [
    [/("name": "oj-code-arena-server",\s+"version": ")[^"]+(")/, `$1${version}$2`],
    [/(""\s*:\s*\{\s+"name": "oj-code-arena-server",\s+"version": ")[^"]+(")/, `$1${version}$2`],
]);
edit('README.md', [[/(- 버전: )\S+/, `$1${version}`]]);
console.log('version ' + version + ' — add a CHANGELOG.md entry, then: node tools/test-all.js');
