'use strict';

const fs = require('node:fs');
const path = require('node:path');

function readEnv(file) {
    const values = {};
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
        const match = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$/);
        if (match) values[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2');
    }
    return values;
}

function matchingBrace(text, open) {
    let depth = 0;
    let quote = '';
    let comment = false;
    for (let index = open; index < text.length; index += 1) {
        const char = text[index];
        const previous = index > 0 ? text[index - 1] : '';
        if (comment) {
            if (char === '\n') comment = false;
            continue;
        }
        if (!quote && char === '#') {
            comment = true;
            continue;
        }
        if ((char === '"' || char === "'") && previous !== '\\') {
            quote = quote === char ? '' : (quote || char);
            continue;
        }
        if (quote) continue;
        if (char === '{') depth += 1;
        if (char === '}') {
            depth -= 1;
            if (depth === 0) return index;
        }
    }
    return -1;
}

function serverBlocks(text) {
    const blocks = [];
    const pattern = /(^|\n)([ \t]*)server\s*\{/g;
    let match;
    while ((match = pattern.exec(text))) {
        const open = text.indexOf('{', match.index);
        const close = matchingBrace(text, open);
        if (close < 0) break;
        blocks.push({ open, close, text: text.slice(open + 1, close) });
        pattern.lastIndex = close + 1;
    }
    return blocks;
}

function install(envFile, siteFile, snippetFile) {
    const env = readEnv(envFile);
    const port = Number(env.PORT || 3210);
    const wsPath = String(env.WS_PATH || '/classroom-live');
    if (!(port > 0 && port <= 65535) || !/^\/[A-Za-z0-9/_-]+$/.test(wsPath)) {
        throw new Error('The realtime server path or port is invalid.');
    }

    const snippet = `# OJ Realtime Hub - managed by install.sh\nlocation ${wsPath} {\n    proxy_pass http://127.0.0.1:${port};\n    proxy_http_version 1.1;\n    proxy_set_header Upgrade $http_upgrade;\n    proxy_set_header Connection "upgrade";\n    proxy_set_header Host $host;\n    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n    proxy_read_timeout 3600s;\n    proxy_send_timeout 3600s;\n}\n`;
    fs.mkdirSync(path.dirname(snippetFile), { recursive: true });
    fs.writeFileSync(snippetFile, snippet, 'utf8');

    let site = fs.readFileSync(siteFile, 'utf8');
    const includeLine = `include ${snippetFile};`;
    if (site.includes(includeLine)) return '';

    const blocks = serverBlocks(site);
    const target = blocks.find((block) => /\blisten\s+[^;]*\b443\b[^;]*;/.test(block.text) || /\bssl\b/.test(block.text));
    if (!target) throw new Error(`No HTTPS server block was found in ${siteFile}.`);

    const stamp = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
    const backup = `${siteFile}.oj-realtime-${stamp}.bak`;
    fs.copyFileSync(siteFile, backup);
    site = site.slice(0, target.close) + `    ${includeLine}\n` + site.slice(target.close);
    fs.writeFileSync(siteFile, site, 'utf8');
    return backup;
}

if (require.main === module) {
    try {
        const backup = install(process.argv[2], process.argv[3], process.argv[4]);
        console.log(backup || 'UNCHANGED');
    } catch (error) {
        console.error(error.message);
        process.exit(1);
    }
}

module.exports = { install, matchingBrace, serverBlocks };
