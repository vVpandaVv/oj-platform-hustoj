const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');const r=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',['tests/bridge.php','rules'],{cwd:root,encoding:'utf8',maxBuffer:20e6});assert.equal(r.status,0,r.stderr);const games=JSON.parse(r.stdout);let checks=0;
const images=games['image-data'].rules.samples;assert.equal(images.filter(x=>x.size===8).length,12);assert.equal(images.filter(x=>x.size===16).length,33);
const ids=new Set();
for(const sample of images){assert(!ids.has(sample.id));ids.add(sample.id);assert.equal(sample.data.length,sample.size);assert(sample.data.every(row=>row.length===sample.size&&/^[0-9A-F]+$/.test(row)));assert(/[1-9A-F]/.test(sample.data.join('')));checks++;}
const character=games['character-code'].rules;assert.equal(character.symbols.length,37);assert.equal(new Set(character.symbols).size,37);
for(const level of Object.values(character.difficulties)){for(const text of level.phrases){const meta=character.phraseMetadata[text];assert.equal(meta.length,text.length);assert.equal(meta.uniqueSymbols,new Set(text).size);assert(meta.tags.length);assert([...text].every(c=>character.symbols.includes(c)));checks++;}}
assert.equal(Object.keys(character.phraseMetadata).length,86);
for(const sample of games['code-typing'].rules.samples){assert(['Python 3.12','C17'].includes(sample.languageVersion));assert(['완전 코드','비실행 코드 조각','단어 목록'].includes(sample.sourceKind));checks++;}
const outputs=JSON.parse(fs.readFileSync(path.join(root,'src/Games/Modules/Code_Typing/expected-output.json'),'utf8'));assert.equal(Object.keys(outputs).length,70);
for(const sample of games['code-typing'].rules.samples.filter(s=>s.mode!=='keyword')){assert.equal(outputs[sample.id].sha256,crypto.createHash('sha256').update(sample.content).digest('hex'));assert.equal(sample.expectedInput,'');assert.equal(sample.expectedOutput,outputs[sample.id].stdout);checks++;}
for(const manifest of ['reviewed-sources.json','expanded-sources.json'])for(const entry of JSON.parse(fs.readFileSync(path.join(root,'assets/device-matching',manifest),'utf8'))){const bytes=fs.readFileSync(path.join(root,'assets/device-matching/images',entry.file));assert.equal(crypto.createHash('sha256').update(bytes).digest('hex'),entry.sha256);assert(entry.metadata.LicenseShortName.value);assert(entry.source.startsWith('https://commons.wikimedia.org/'));checks++;}
console.log(`PASS: ${checks} pixel, phrase, typing metadata and photo integrity checks.`);
