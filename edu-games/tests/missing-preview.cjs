const assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const preview=require('../assets/play/missing.js');
const run=input=>{const p=spawnSync('D:/php/php.exe',['tests/hands-fixture.php'],{cwd:require('node:path').resolve(__dirname,'..'),input:JSON.stringify({game:'missing-values',...input}),encoding:'utf8'});assert.equal(p.status,0,p.stderr);return JSON.parse(p.stdout);};
let checks=0;
for(const family of ['linear','mean','median','mode','previous','next','seasonal','nearest','unknown']){
    const {state,view}=run({action:'start',level:'example-'+family});
    const ids=state.rows.map((r,i)=>i).filter(i=>state.rows[i].value!==null&&(state.kind!=='cohort'||state.rows[i].group===state.group));
    for(const method of view.methods){
        const client=preview.predictions(view,method,ids),server=run({action:'update',state,move:{type:'restore',method,ids}}).view.feedback.predictions;
        assert.deepEqual(client,server,family+' / '+method+' preview equals server');checks++;
    }
    const local={};preview.prepare(view,local);assert.equal(local.method,null);assert(!preview.ready(view,local));checks++;
}
console.log(checks+' missing preview/server parity checks passed');
