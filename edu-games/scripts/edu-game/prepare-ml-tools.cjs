'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');

async function main() {
    const [python, directory] = process.argv.slice(2);
    if (!python || !directory) throw new Error('Usage: node prepare-ml-tools.cjs PYTHON TOOL_DIRECTORY');
    const root = path.resolve(directory);
    fs.mkdirSync(root, { recursive: true });
    const files = [];
    for (const [name, version] of [['numpy', '2.3.5'], ['scipy', '1.16.3'], ['scikit-learn', '1.8.0'], ['joblib', '1.5.2'], ['threadpoolctl', '3.6.0']]) {
        const metadata = await fetch(`https://pypi.org/pypi/${name}/${version}/json`).then((response) => { if (!response.ok) throw new Error(response.status); return response.json(); });
        const wheel = metadata.urls.find((item) => item.filename.endsWith('cp312-cp312-win_amd64.whl') || item.filename.endsWith('py3-none-any.whl'));
        if (!wheel) throw new Error(`No Python 3.12 Windows wheel: ${name}`);
        const bytes = Buffer.from(await fetch(wheel.url).then((response) => { if (!response.ok) throw new Error(response.status); return response.arrayBuffer(); }));
        if (crypto.createHash('sha256').update(bytes).digest('hex') !== wheel.digests.sha256) throw new Error(`Digest mismatch: ${name}`);
        const file = path.join(root, wheel.filename); fs.writeFileSync(file, bytes); files.push(file);
        console.log(`Verified ${wheel.filename}`);
    }
    const result = spawnSync(python, ['-m', 'pip', '--isolated', 'install', '--no-index', '--no-deps', '--disable-pip-version-check', '--target', path.join(root, 'python'), ...files], { stdio: 'inherit', windowsHide: true, timeout: 180000 });
    if (result.status !== 0) throw new Error(`Offline install failed: ${result.error || result.status}`);
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
