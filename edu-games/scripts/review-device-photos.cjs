const fs=require('node:fs'),path=require('node:path'),sharp=require(process.env.SHARP_PATH||'sharp');
const root=path.resolve(__dirname,'..'),rows=require('../assets/device-matching/team-sources.json'),out=path.resolve(root,'../_notes/tmp/device-team-review');fs.mkdirSync(out,{recursive:true});
(async()=>{for(let start=0;start<rows.length;start+=20){const entries=[];for(const [i,row]of rows.slice(start,start+20).entries()){
 const picture=await sharp(path.join(root,'assets/device-matching/images',row.asset)).resize(232,160,{fit:'contain',background:'#ffffff'}).flatten({background:'#ffffff'}).toBuffer();
 const label=Buffer.from(`<svg width="232" height="32"><rect width="232" height="32" fill="white"/><text x="5" y="21" font-family="sans-serif" font-size="13">${start+i+1}. ${row.id.replace(/&/g,'&amp;')}</text></svg>`);
 entries.push({input:picture,left:(i%4)*240,top:Math.floor(i/4)*200},{input:label,left:(i%4)*240,top:Math.floor(i/4)*200+160});
 }const file=path.join(out,`sheet-${start/20+1}.png`);await sharp({create:{width:960,height:Math.ceil(Math.min(20,rows.length-start)/4)*200,channels:3,background:'#eeeeee'}}).composite(entries).png().toFile(file);console.log(file);}})().catch(e=>{console.error(e);process.exitCode=1;});
