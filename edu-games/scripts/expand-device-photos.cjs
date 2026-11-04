const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..'),plan=require('./device-photo-plan.json'),out=path.join(root,'assets/device-matching');
const wait=ms=>new Promise(r=>setTimeout(r,ms));
const metadata=fs.existsSync(path.join(out,'team-sources.json'))?JSON.parse(fs.readFileSync(path.join(out,'team-sources.json'),'utf8')):[];
const clean=s=>String(s||'').replace(/<[^>]*>/g,'').replace(/&amp;/g,'&').trim();
async function get(url){for(let i=0;i<4;i++){const r=await fetch(url,{headers:{'User-Agent':'EduGames/1.8 classroom open-image catalog research'},signal:AbortSignal.timeout(40000)});if(r.ok)return r;if(![429,502,503].includes(r.status))throw Error(r.status+' '+url);await wait(3000*(i+1));}throw Error('Retry limit');}
async function main(){
 const reselect=(process.env.RESELECT||'').split(',');for(let i=metadata.length-1;i>=0;i--)if(reselect.includes(metadata[i].id))metadata.splice(i,1);
 for(const [id,name,description,category,categoryLabel,query]of plan){
  if(!/^[a-z0-9-]+$/.test(id))throw Error('Invalid asset identifier: '+id);
  if(metadata.some(x=>x.id===id))continue;
  try{
   const url=new URL('https://commons.wikimedia.org/w/api.php');url.search=new URLSearchParams({action:'query',...(query.startsWith('File:')?{titles:query}:{generator:'search',gsrsearch:query+' filetype:bitmap',gsrnamespace:'6',gsrlimit:'8'}),prop:'imageinfo',iiprop:'url|extmetadata|mime',iiurlwidth:'960',format:'json'});
   const data=await(await get(url)).json(),pages=Object.values(data.query?.pages||{}).sort((a,b)=>a.index-b.index);
   const candidates=pages.filter(p=>{const i=p.imageinfo?.[0],l=i?.extmetadata?.LicenseShortName?.value||'';return i&&['image/jpeg','image/png','image/webp'].includes(i.mime)&&/^(CC BY(-SA)? [234]|CC0|Public domain)/.test(l);});
   if(!candidates.length){console.log('NO SOURCE '+id);continue;}
   const override=process.env['PHOTO_'+id.replace(/-/g,'_').toUpperCase()];
   const selected=candidates[override?Number(override):0],info=selected.imageinfo[0],asset=id+({'image/png':'.png','image/webp':'.webp'}[info.mime]||'.jpg'),download=info.thumburl||info.url;
   const buffer=Buffer.from(await(await get(download)).arrayBuffer());fs.writeFileSync(path.join(out,'images',asset),buffer);
   const row={id,name,description,category,categoryLabel,asset,sourceFile:selected.title.slice(5),sourceUrl:info.descriptionurl,license:clean(info.extmetadata.LicenseShortName.value),licenseUrl:info.extmetadata.LicenseUrl?.value||'',author:clean(info.extmetadata.Artist?.value),download,sha256:crypto.createHash('sha256').update(buffer).digest('hex'),candidates:pages.map(p=>p.title)};
   metadata.push(row);fs.writeFileSync(path.join(out,'team-sources.json'),JSON.stringify(metadata,null,2)+'\n');console.log(id+' | '+selected.title+' | '+row.license);await wait(500);
  }catch(e){console.log('FAILED '+id+': '+e.message);}
 }
 console.log('Photographs: '+metadata.length);
}
main().catch(e=>{console.error(e);process.exitCode=1;});
