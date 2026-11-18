const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const context={window:{},document:{readyState:'loading',addEventListener(){}},URL,location:{href:'https://school.test/oj-addons/edu-games/games'}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/typing/entry.js'),'utf8'),context);
const render=context.window.EduTypingEntry.render,options={hub:true,gameUrl:'/oj-addons/edu-games/games/code-typing',iconsUrl:'/icons.svg'};
const evaluation={id:3,title:'1반 코드 검정',language:'python',contextLabels:['1반'],durationSeconds:60,minAccuracy:95,maxAttempts:2,status:'open',version:'typing-verified-v3',attempts:[]};
let html=render([evaluation],options),checks=0;
function check(v,label){assert(v,label);checks++;}
check(html.includes('ete-hub-card'),'compact hub presentation');check(html.includes('응시하기'),'participation stays visible');check(html.includes('evaluation=3'),'same assessment deep link');check(html.includes('미응시'),'absent status');check(!html.includes('기록 대기'),'no large empty metrics');check(html.includes('ete-student-count">1'),'assignment count stays prominent');
html=render([{...evaluation,attempts:[{status:'running',token:'test',attemptNo:1}]}],options);check(html.includes('이어서 응시')&&html.includes('응시 중'),'resume and running status');
const result={status:'finished',effectiveCpm:145.6,inputAccuracy:97.25,qualified:true,validCharacters:200};
html=render([{...evaluation,result,attempts:[{...result,attemptNo:1}]}],options);check(html.includes('145.6')&&html.includes('97.25%'),'correct saved speed and accuracy');check(html.includes('기준 충족'),'qualification preserved');check(html.includes('다시 응시'),'remaining attempts preserved');
html=render([{...evaluation,result:{...result,qualified:false,inputAccuracy:81},maxAttempts:1,attempts:[result]}],options);check(html.includes('정확도 미달'),'unqualified record stays visible');check(html.includes('결과 보기')&&!html.includes('응시하기'),'no attempt after maximum');
html=render([{...evaluation,title:'<script>bad</script>'}],options);check(!html.includes('<script>'),'escape teacher-provided title');
html=render([evaluation],{...options,hub:false});check(html.includes('ete-student-metrics')&&html.includes('data-typing-evaluation="3"'),'full assessment page and controls unchanged');
const sprite=fs.readFileSync(path.join(__dirname,'../assets/hub/icons.svg'),'utf8');for(const icon of ['binary','image','bot','code-xml','copy','cloud-rain','vault','music-2'])check(sprite.includes('id="'+icon+'"'),'game icon '+icon);
console.log('PASS: '+checks+' compact hub assessment and icon checks.');
