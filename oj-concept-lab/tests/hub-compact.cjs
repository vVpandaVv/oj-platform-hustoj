const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),{spawnSync}=require('node:child_process'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const result=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',['-r',`define('OJ_CONCEPT_LAB_PATH',getcwd().'/'); require 'src/Core/Autoloader.php'; OjConceptLab\\Core\\Autoloader::register(); function get_option($k,$v=false){return $v;} function home_url($p=''){return $p;} echo json_encode(OjConceptLab\\Activities\\Catalog::all(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);`],{cwd:root,encoding:'utf8'});
assert.equal(result.status,0,result.stderr);const activities=JSON.parse(result.stdout),sprite=fs.readFileSync(path.join(root,'resources/hub/icons.svg'),'utf8');let checks=0;
function check(value,label){assert(value,label);checks++;}
check(Object.keys(activities).length===11,'eleven activities retained');
for(const a of Object.values(activities)){check(a.title&&a.hubTitle&&a.hubDescription&&a.url,'full concept and compact presentation '+a.id);check(sprite.includes('id="'+a.hubIcon+'"'),'distinct local icon '+a.id);check(['blue','mint','coral','amber'].includes(a.tone),'theme color '+a.id);}
check(new Set(Object.values(activities).map(a=>a.hubIcon)).size===11,'no repeated experience icons');
function render(items){const app={innerHTML:''},element={dataset:{config:JSON.stringify({activity:'',data:[],activities:items,icons:'/resources/icons.svg',hubIcons:'/resources/hub/icons.svg'})},querySelector(){return app;},addEventListener(){}};const context={window:{},document:{readyState:'complete',querySelectorAll(){return[element];}},MutationObserver:class{observe(){}}};vm.runInNewContext(fs.readFileSync(path.join(root,'resources/js/lab.js'),'utf8'),context);return app.innerHTML;}
let html=render(activities);check((html.match(/class="cl-activity"/g)||[]).length===11,'all cards rendered');check(html.includes('11개'),'visible activity count');check(!html.includes('체험 열기 →'),'repeated command text removed');
for(const a of Object.values(activities)){check(html.includes('aria-label="'+a.title+'"'),'full accessible concept '+a.id);check(html.includes('href="'+a.url+'"'),'original entry route '+a.id);}
const visible={...activities};delete visible['missing-values'];html=render(visible);check(html.includes('10개')&&!html.includes('href="'+activities['missing-values'].url+'"'),'closed activity stays absent');
html=render({x:{...activities.privacy,title:'<script>bad</script>',hubTitle:'<b>bad</b>',hubDescription:'<img src=x>',url:'https://school.test/?x="bad'}});check(!html.includes('<script>')&&!html.includes('<img ')&&!html.includes('<b>'),'escape all presentation and accessibility text');
check(render({}).includes('0개'),'empty catalog remains valid');
console.log('PASS: '+checks+' compact concept hub rendering, visibility and icon checks.');
