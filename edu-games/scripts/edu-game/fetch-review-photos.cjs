/* Downloads only explicitly named Commons files, retaining rights metadata. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
async function download(url) {
    for (let attempt = 0; attempt < 4; attempt++) {
        const response = await fetch(url, { signal: AbortSignal.timeout(45000) });
        if (response.ok) return response;
        if (![429, 503].includes(response.status) || attempt === 3) throw Error(`${url}: ${response.status}`);
        await new Promise(resolve => setTimeout(resolve, Math.max(20000, Number(response.headers.get('retry-after')) * 1000 || 0)));
    }
}
async function main() {
    const [out, ...names] = process.argv.slice(2);
    if (!out || !names.length) throw Error('Usage: output-directory File:name ...');
    fs.mkdirSync(out, { recursive: true });
    const url = new URL('https://commons.wikimedia.org/w/api.php');
    Object.entries({ action: 'query', titles: names.join('|'), prop: 'imageinfo', iiprop: 'url|extmetadata', iiurlwidth: '800', format: 'json' }).forEach(([k,v]) => url.searchParams.set(k,v));
    const response = await download(url);
    const metadata = await response.json(); const manifest = [];
    for (const page of Object.values(metadata.query.pages)) {
        const info = page.imageinfo?.[0]; if (!info) throw Error(page.title);
        const res = await download(info.thumburl || info.url);
        const bytes = Buffer.from(await res.arrayBuffer()); const name = `${page.pageid}.jpg`;
        if (bytes[0] !== 0xff || bytes[1] !== 0xd8) throw Error(`${page.title}: expected a JPEG photograph`);
        fs.writeFileSync(path.join(out,name),bytes);
        manifest.push({ file:name, title:page.title, source:info.descriptionurl, original:info.url, download:info.thumburl || info.url, sha256:crypto.createHash('sha256').update(bytes).digest('hex'), metadata:info.extmetadata });
        await new Promise(resolve => setTimeout(resolve, 1500));
    }
    fs.writeFileSync(path.join(out,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');
    console.log(JSON.stringify(manifest.map(x=>({file:x.file,title:x.title,license:x.metadata.LicenseShortName?.value,author:x.metadata.Artist?.value})),null,2));
}
main().catch(e=>{console.error(e);process.exitCode=1;});
