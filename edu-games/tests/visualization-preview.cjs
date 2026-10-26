const assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const scenes=require('../assets/play/rescue-scenes.js');
const php=spawnSync(process.env.PHP_BINARY||'D:/php/php.exe',['tests/visualization-scenes.php','--fixtures'],{cwd:require('node:path').resolve(__dirname,'..'),encoding:'utf8',maxBuffer:2e6});
assert.equal(php.status,0,php.stderr||php.stdout);let checks=0;
for(const row of JSON.parse(php.stdout)){
    assert.deepEqual(scenes.choices(row.state),row.choices);checks++;
    if(row.state.scene==='histogram'){assert.deepEqual(scenes.bins(row.state),row.bins);checks++;}
}
console.log(checks+' visualization preview/server comparisons passed');
