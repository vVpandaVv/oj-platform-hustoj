'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { decode } = require('./setup');

function readEnv(file) {
    const env = {};
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
        const match = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$/);
        if (match) env[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2');
    }
    return env;
}

function installSettings(env) {
    let origin;
    try { origin = new URL(env.ALLOWED_ORIGINS); } catch { throw new Error('One WordPress origin is required.'); }
    if (!['http:', 'https:'].includes(origin.protocol) || origin.username || origin.password
        || origin.pathname !== '/' || origin.search || origin.hash || env.HOST !== '127.0.0.1'
        || !/^\d+$/.test(env.PORT) || Number(env.PORT) < 1024 || Number(env.PORT) > 65535
        || !/^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(env.WS_PATH)) {
        throw new Error('Automatic installation requires a loopback port (1024-65535), a simple path, and one site origin.');
    }
    const callback = new URL(env.WP_API);
    if (callback.origin !== origin.origin) throw new Error('WordPress callback and browser origin must match.');
    return { env, host: origin.hostname, port: origin.port || (origin.protocol === 'https:' ? '443' : '80') };
}

// Read nginx directives with their nesting and source offsets; comments and quoted braces are not syntax.
function directives(text) {
    const tokens = [...text.matchAll(/#[^\r\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\$\{[^}]*\}|[{};]|[^\s{};"'#]+/g)]
        .filter((match) => !match[0].startsWith('#'));
    let cursor = 0;
    function block(nested = false) {
        const result = [];
        while (cursor < tokens.length) {
            if (tokens[cursor][0] === '}') {
                if (!nested) throw new Error('Unexpected nginx closing brace.');
                return { items: result, close: tokens[cursor++].index };
            }
            const start = tokens[cursor].index;
            const words = [];
            while (cursor < tokens.length && !['{', '}', ';'].includes(tokens[cursor][0])) {
                words.push(tokens[cursor++][0].replace(/^(['"])(.*)\1$/, '$2'));
            }
            const separator = tokens[cursor++];
            if (!words.length || !separator || separator[0] === '}') throw new Error('Unsupported nginx syntax.');
            const child = separator[0] === '{' ? block(true) : null;
            result.push({ words, start, end: child ? child.close + 1 : separator.index + 1, close: child?.close, children: child?.items || [] });
        }
        if (nested) throw new Error('Unclosed nginx block.');
        return { items: result };
    }
    return block().items;
}

function renderSnippet(env) {
    const proxy = `    proxy_pass http://127.0.0.1:${env.PORT};\n    proxy_http_version 1.1;\n    proxy_set_header Upgrade $http_upgrade;\n    proxy_set_header Connection "upgrade";\n    proxy_set_header Host $host;\n    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n    proxy_read_timeout 120s;\n    proxy_send_timeout 120s;\n`;
    return `# OJ Code Arena - managed by game-server/install.sh\nlocation = ${env.WS_PATH} {\n${proxy}}\nlocation ^~ ${env.WS_PATH}/ {\n${proxy}}\n`;
}

function plan(env, text, snippetFile) {
    const settings = installSettings(env);
    if (!/^\/[A-Za-z0-9/_.-]+$/.test(snippetFile)) throw new Error('Invalid nginx snippet path.');
    const servers = directives(text).filter((item) => item.words[0] === 'server' && item.close !== undefined);
    const candidates = servers.filter((server) => server.children.some((item) => item.words[0] === 'listen'
        && item.words[1]?.split(':').pop() === settings.port));
    const named = candidates.filter((server) => server.children.some((item) => item.words[0] === 'server_name'
        && item.words.slice(1).map((name) => name.toLowerCase()).includes(settings.host.toLowerCase())));
    const targets = named.length ? named : candidates.filter((server) => candidates.length === 1
        && !server.children.some((item) => item.words[0] === 'server_name' && item.words.slice(1).some((name) => name !== '_' && name !== '')));
    if (targets.length !== 1) throw new Error('Cannot identify one nginx server block for this site. Specify its site file or configure the proxy manually.');
    const target = targets[0];
    const includes = target.children.filter((item) => item.words[0] === 'include'
        && path.posix.resolve('/etc/nginx', item.words[1] || '') === snippetFile);
    if (includes.length > 1) throw new Error('Duplicate Code Arena nginx includes must be resolved first.');
    if (target.children.some((item) => item.words[0] === 'location'
        && item.words.slice(1).some((word) => word === env.WS_PATH || word === env.WS_PATH + '/'))) {
        throw new Error('An inline location already uses the arena path. Remove that old location or use manual installation.');
    }
    return {
        site: includes.length ? text : text.slice(0, target.close) + `    include ${snippetFile};\n` + text.slice(target.close),
        snippet: renderSnippet(env),
    };
}

function install(envFile, siteFile, snippetFile) {
    const next = plan(readEnv(envFile), fs.readFileSync(siteFile, 'utf8'), snippetFile);
    fs.writeFileSync(snippetFile, next.snippet, 'utf8');
    fs.writeFileSync(siteFile, next.site, 'utf8');
}

if (require.main === module) {
    try {
        if (process.argv[2] === '--validate') {
            installSettings(decode(process.argv[3]));
        } else if (process.argv[2] === '--existing') {
            const old = readEnv(process.argv[3]);
            const next = decode(process.argv[4]);
            if (String(old.WP_API || '').replace(/\/+$/, '') !== next.WP_API.replace(/\/+$/, '')) {
                throw new Error('This installation belongs to a different WordPress callback. Use a separate server or migrate manually.');
            }
        } else if (process.argv[2] === '--port') {
            console.log(readEnv(process.argv[3]).PORT || '');
        } else if (process.argv[2] === '--path') {
            console.log(readEnv(process.argv[3]).WS_PATH || '');
        } else {
            install(process.argv[2], process.argv[3], process.argv[4]);
        }
    } catch (error) {
        console.error(error.message);
        process.exit(1);
    }
}

module.exports = { readEnv, installSettings, directives, renderSnippet, plan, install };
