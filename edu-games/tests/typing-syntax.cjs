const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');const temp=path.resolve(root,'../_notes/tmp/edu-games-typing-check');fs.mkdirSync(temp,{recursive:true});
function run(exe,args,input){const r=spawnSync(exe,args,{cwd:root,input,encoding:'utf8',timeout:120000,maxBuffer:20e6});if(r.status!==0)throw Error(r.stderr||r.stdout||r.error);return r.stdout;}
const games=JSON.parse(run(process.env.PHP_BINARY||'D:/php/php.exe',['tests/bridge.php','rules']));
const samples=games['code-typing'].rules.samples;
const execute=process.env.RUN_TRUSTED_SAMPLES==='1';const outputs={};const binaries=[];
function sampleOutput(exe,args,sample){
    if(/\b(?:input|open|exec|eval|__import__|system|subprocess|fopen|scanf|gets|fork|socket)\s*\(/.test(sample.content))throw Error('Re-review external access: '+sample.id);
    const nativeWindows=process.platform==='win32'&&sample.language==='c';
    const result=spawnSync(nativeWindows?'powershell':exe,nativeWindows?['-NoProfile','-Command','& $env:EDU_TEST_EXE; exit $LASTEXITCODE']:args,{cwd:temp,encoding:'utf8',timeout:15000,maxBuffer:64000,env:{...process.env,PYTHONHASHSEED:'0',EDU_TEST_EXE:exe}});
    if(result.status!==0)throw Error(sample.id+': '+(result.stderr||result.error));
    outputs[sample.id]={sha256:require('node:crypto').createHash('sha256').update(sample.content).digest('hex'),stdin:'',stdout:result.stdout.replace(/\r\n/g,'\n')};
}
console.log(run(process.env.PYTHON_BINARY,['tests/validate-typing.py'],JSON.stringify(samples)).trim());
if(execute)for(const sample of samples.filter(x=>x.language==='python'&&x.mode!=='keyword'))sampleOutput(process.env.PYTHON_BINARY,['-I','-S','-c',sample.content],sample);
let c=0;
for(const sample of samples.filter(x=>x.language==='c'&&x.mode!=='keyword')){
    if(sample.sourceKind!=='완전 코드')continue;
    const file=path.join(temp,sample.id+'.c');fs.writeFileSync(file,sample.content);
    run(process.env.ZIG_BINARY,['cc','-std=c17','-Wall','-Wextra','-Werror=implicit-function-declaration','-c',file,'-o',path.join(temp,sample.id+'.o')]);c++;
    if(execute){const exe=path.join(temp,sample.id+'.exe');run(process.env.ZIG_BINARY,['cc','-std=c17',file,'-o',exe]);binaries.push({exe,sample});}
}
console.log(`PASS: ${c} C17 samples compiled to object files.`);
if(execute){
    for(const {exe,sample} of binaries)sampleOutput(exe,[],sample);
    fs.writeFileSync(path.join(root,'src/Games/Modules/Code_Typing/expected-output.json'),JSON.stringify(outputs,null,2)+'\n');
    console.log(`PASS: ${Object.keys(outputs).length} trusted shipped samples terminated within 15s (including process startup); output manifest generated. Not a learner-code sandbox.`);
}else console.log('No programs executed.');
