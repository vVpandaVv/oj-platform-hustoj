const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../resources/js/lab.js'),'utf8'),storage=new Map();let checks=0;
function check(v,label){assert(v,label);checks++;}
function open(id='network-routing'){
 const app={innerHTML:'',querySelector(){return null;}},root={dataset:{config:JSON.stringify({activity:id,activities:{[id]:{title:id,description:'test'}},data:[],storageKey:'confirmation-test',icons:'/icons.svg',hubIcons:'/hub.svg'})},querySelector(){return app;},addEventListener(){}};
 vm.runInNewContext(source,{window:{},document:{readyState:'complete',querySelectorAll(){return[root];},documentElement:{}},localStorage:{getItem(k){return storage.get(k)||null;},setItem(k,v){storage.set(k,v);}},structuredClone,MutationObserver:class{observe(){}}});
 const lab=root.__ConceptLab;return {lab,app,act(action,id){lab.click({target:{closest(){return {disabled:false,dataset:{cl:action,id}};}}});}};
}
let {lab,app,act}=open();act('route','Y');act('route','A');check(lab.s.budget===24,'draft does not spend');act('undo');check(lab.s.path.join(',')==='S,Y','draft undo allowed');act('redo');check(lab.s.path.join(',')==='S,Y,A','draft redo allowed');
act('send');check(lab.s.budget===20&&lab.s.delivered===1&&lab.s.packet===1,'confirmation spends actual route cost');check(lab.undo.length===0&&lab.redo.length===0,'history checkpoint on confirmation');const committed=JSON.stringify(lab.s);
act('undo');act('redo');check(JSON.stringify(lab.s)===committed,'confirmed transmission cannot be undone or redone');check(app.innerHTML.includes('수정·취소 불가')&&lab.message.includes('취소 불가'),'immutable receipt and feedback');
act('route','X');act('route-cancel');check(lab.s.budget===20&&lab.s.delivered===1&&lab.s.lastTransmission.number===1,'cancel next draft never refunds previous transmission');act('undo');check(lab.s.budget===20,'undo next draft keeps confirmed budget');
act('route-cancel');act('route','X');act('route','A');act('send');check(lab.s.budget===16&&lab.s.packet===2&&lab.s.delivered===1,'second confirmation is a separate committed cost');act('undo');check(lab.s.budget===16&&lab.undo.length===0,'second checkpoint cannot cross first or second transaction');
({lab,app,act}=open());check(lab.s.budget===16&&lab.s.packet===2&&lab.s.lastTransmission.number===2,'reload restores committed result without undo history');
act('reset');check(lab.s.budget===24&&lab.s.packet===0&&lab.undo.length===0,'explicit new experiment starts a fresh checkpoint');act('undo');check(lab.s.packet===0&&lab.s.budget===24,'cannot undo reset into an older experiment');
({lab,act}=open('privacy'));act('cover','phone');check(lab.s.covers.includes('phone'),'other activity changes still apply');act('undo');check(lab.s.covers.length===0,'other activity undo unchanged');
console.log('PASS: '+checks+' committed network transmission and undo-boundary checks.');
