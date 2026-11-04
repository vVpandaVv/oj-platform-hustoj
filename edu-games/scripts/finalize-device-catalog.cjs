const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'..'),out=path.join(root,'assets/device-matching'),plan=require('./device-photo-plan.json');
const ids=new Set(plan.map(row=>row[0]));
const all=JSON.parse(fs.readFileSync(path.join(out,'team-sources.json'),'utf8')),rows=all.filter(row=>ids.has(row.id));
if(rows.length!==32||new Set(rows.map(row=>row.id)).size!==32)throw Error('Expected 32 reviewed additions');
for(const row of rows){if(!/^[a-z0-9-]+$/.test(row.id)||!fs.existsSync(path.join(out,'images',row.asset)))throw Error('Invalid reviewed asset');}
fs.writeFileSync(path.join(out,'team-sources.json'),JSON.stringify(rows,null,2)+'\n');
let credits=fs.readFileSync(path.join(out,'ATTRIBUTION.md'),'utf8').split('## Team Catalog Expansion')[0].trimEnd();
credits+='\n\n## Team Catalog Expansion (2026-11-03)\n\n32 additional device photographs, bringing the local catalog to 70 devices. Commons-generated thumbnails are distributed without additional editing. Diagrams, unrelated scenes and ambiguous component closeups were excluded. Source URLs, authors, licenses, download URLs and SHA-256 hashes are preserved in `team-sources.json`. These photographs illustrate device categories and are not a claim of newest-model availability.\n\n| Asset | Source | Author | License |\n| --- | --- | --- | --- |\n';
for(const r of rows)credits+=`| ${r.asset} | [${r.sourceFile.replace(/\|/g,' ')}](${r.sourceUrl}) | ${r.author.replace(/\s+/g,' ').replace(/\|/g,' ')} | [${r.license}](${r.licenseUrl||r.sourceUrl}) |\n`;
fs.writeFileSync(path.join(out,'ATTRIBUTION.md'),credits);
console.log('Finalized '+rows.length+' reviewed photographs; catalog total 70.');
