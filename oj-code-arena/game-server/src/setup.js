'use strict';

/*
 * Writes game-server/.env from the setup code shown in the OJ console
 * (교사 메뉴 › 코드 아레나 › 설정 → 방법 1):
 *
 *   node src/setup.js
 *
 * The code is "oca1." + base64url(JSON {v:1, env:{...}}), made by
 * Support\Server_Env::setup_code() in the plugin. An existing .env is kept
 * as .env.bak.
 */
const fs = require('node:fs');
const path = require('node:path');

const KEYS = ['ARENA_SECRET', 'WP_API', 'ALLOWED_ORIGINS', 'PORT', 'HOST', 'WS_PATH'];

function decode(code) {
    const raw = String(code || '').trim();
    if (!raw.startsWith('oca1.')) throw new Error('설정 코드는 oca1. 로 시작해야 해요. WordPress 화면에서 다시 복사해 주세요.');
    let data;
    try {
        data = JSON.parse(Buffer.from(raw.slice(5).replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8'));
    } catch {
        throw new Error('설정 코드를 읽지 못했어요. 끝까지 빠짐없이 복사했는지 확인해 주세요.');
    }
    if (!data || data.v !== 1 || !data.env || typeof data.env !== 'object') throw new Error('알 수 없는 설정 코드예요.');
    const env = {};
    for (const key of KEYS) {
        const value = String(data.env[key] == null ? '' : data.env[key]);
        if (/[\r\n]/.test(value)) throw new Error(key + ' 값이 올바르지 않아요.');
        env[key] = value;
    }
    if (env.ARENA_SECRET.length < 32) throw new Error('비밀키가 너무 짧아요.');
    if (!/^https?:\/\//.test(env.WP_API)) throw new Error('WP_API 주소가 올바르지 않아요.');
    if (!/^\d{1,5}$/.test(env.PORT) || Number(env.PORT) < 1 || Number(env.PORT) > 65535) throw new Error('PORT 값이 올바르지 않아요.');
    return env;
}

function render(env) {
    return '# OJ Code Arena game server — written by src/setup.js\n' + KEYS.map((k) => `${k}=${env[k]}`).join('\n') + '\n';
}

function write(env, dir) {
    const file = path.join(dir, '.env');
    let backup = '';
    let extra = '';
    if (fs.existsSync(file)) {
        extra = fs.readFileSync(file, 'utf8').split(/\r?\n/).filter((line) => {
            const key = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=/)?.[1];
            return key && !KEYS.includes(key);
        }).join('\n');
        backup = file + '.bak';
        fs.copyFileSync(file, backup);
        fs.chmodSync(backup, 0o600);
    }
    fs.writeFileSync(file, render(env) + (extra ? extra + '\n' : ''), { mode: 0o600 });
    fs.chmodSync(file, 0o600);
    return { file, backup };
}

if (require.main === module) {
    try {
        if (process.argv.length > 2) {
            throw new Error('설정 코드는 명령 인수가 아니라 표준 입력으로 전달해 주세요.');
        }
        const env = decode(fs.readFileSync(0, 'utf8'));
        const { file, backup } = write(env, path.join(__dirname, '..'));
        console.log('설정 파일을 만들었어요: ' + file);
        if (backup) console.log('예전 파일은 ' + backup + ' 로 남겼어요.');
        console.log(`게임 서버가 ${env.HOST}:${env.PORT}${env.WS_PATH} 에서 열려요. 이제 실행하세요: node src/index.js`);
    } catch (err) {
        console.error(err.message);
        process.exit(1);
    }
}

module.exports = { decode, render, write, KEYS };
