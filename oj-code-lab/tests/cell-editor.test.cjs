const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function setup(config={}, withAce=true) {
  const instances=[],scripts=[];let document;
  class Element {
    constructor(){this.children=[];this.events={};this.hidden=false;this.value='';this.attributes={};}
    insertBefore(child,ref){child.parentNode=this;this.children.splice(this.children.indexOf(ref),0,child);}
    appendChild(child){child.parentNode=this;this.children.push(child);if(this===document.head)scripts.push(child);}
    remove(){if(this.parentNode)this.parentNode.children=this.parentNode.children.filter(x=>x!==this);}
    addEventListener(type,fn){this.events[type]=fn;}
    removeEventListener(type){delete this.events[type];}
    setAttribute(name,value){this.attributes[name]=value;}
    focus(){document.activeElement=this;this.events.focus?.();}
  }
  document={head:new Element(),createElement:()=>new Element()};
  const ace={config:{set(){}},require:()=>true,edit:host=>{
    const input=new Element();let value='';
    const editor={host,input,events:{},cursorRow:0,session:{doc:{indexToPosition:index=>({row:0,column:index})},setUseWorker(value){this.worker=value;},setMode(value){this.mode=value;},on(type,fn){this.change=fn;},setBreakpoints(rows){this.breakpoints=[...rows];}},
      commands:{byName:{},addCommand(cmd){this.byName[cmd.name]=cmd;this.command=this.command||cmd;}},selection:{setSelectionRange(value){this.range=value;}},
      textInput:{getElement:()=>input},setValue(text){value=text;},getValue:()=>value,setTheme(value){this.theme=value;},
      setOptions(value){this.options=value;},resize(){},focus(){input.focus();},destroy(){this.destroyed=true;},
      on(type,fn){this.events[type]=fn;},getCursorPosition(){return {row:this.cursorRow,column:0};},
      type(text,delta={action:'insert',start:{row:0,column:0},end:{row:0,column:text.length}}){value=text;this.session.change(delta);},
      clickGutter(row,className='ace_gutter-cell'){let stopped=false;this.events.guttermousedown({domEvent:{target:{className}},getDocumentPosition:()=>({row,column:0}),stop(){stopped=true;}});return stopped;}};
    instances.push(editor);return editor;
  }};
  const window={location:{href:'http://127.0.0.1/test',origin:'http://127.0.0.1'},setTimeout,clearTimeout};
  if(withAce)window.ace=ace;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../resources/js/cell-editor.js'),'utf8'),{window,document,URL,Map,Promise});
  const manager=new window.OclCellEditors(Object.assign({available:true,theme:'monokai',fontSize:18,coreUrl:'http://127.0.0.1/ace/ace.js',baseUrl:'http://127.0.0.1/ace/'},config));
  function attach(value='print(1)') {const wrap=new Element(),textarea=new Element();textarea.value=value;wrap.appendChild(textarea);let changes=0,runs=0;
    const control=manager.attach(textarea,()=>changes++,()=>runs++);return {wrap,textarea,control,get changes(){return changes;},get runs(){return runs;}};}
  return {manager,attach,instances,scripts,window,document,ace};
}
test('Ace applies account theme/font, Python mode and changes without marking initial content dirty',async()=>{
  const a=setup(),cell=a.attach();await flush();const editor=a.instances[0];
  assert.equal(editor.theme,'ace/theme/monokai');assert.equal(editor.options.fontSize,'18px');
  assert.equal(editor.options.maxLines,Infinity);assert.equal(editor.session.mode,'ace/mode/python');assert.equal(editor.session.worker,false);
  assert.equal(cell.changes,0);assert.equal(cell.textarea.hidden,true);
  editor.type('print("한글")');assert.equal(cell.textarea.value,'print("한글")');assert.equal(cell.changes,1);
  editor.commands.command.exec();assert.equal(cell.runs,1);a.manager.destroy();assert.equal(editor.destroyed,true);
});
test('late enhancement takes the latest text and restores the selection/focus',async()=>{
  const a=setup(),cell=a.attach();cell.textarea.focus();cell.textarea.value='typed while loading';
  cell.textarea.selectionStart=6;cell.textarea.selectionEnd=11;await flush();
  assert.equal(a.instances[0].getValue(),'typed while loading');assert.equal(a.document.activeElement,a.instances[0].input);
  assert.equal(a.instances[0].selection.range.start.column,6);assert.equal(a.instances[0].selection.range.end.column,11);a.manager.destroy();
});
test('enhancement waits for Korean composition to finish',async()=>{
  const a=setup(),cell=a.attach();cell.textarea.events.compositionstart();await flush();assert.equal(a.instances.length,0);
  cell.textarea.value='한글 조합 완료';cell.textarea.events.compositionend();await new Promise(resolve=>setTimeout(resolve,5));
  assert.equal(a.instances[0].getValue(),'한글 조합 완료');a.manager.destroy();
});
test('failed core loading leaves textarea usable and does not duplicate script requests',async()=>{
  const a=setup({},false),one=a.attach(),two=a.attach();await flush();assert.equal(a.scripts.length,1);
  a.scripts[0].onerror();await flush();assert.equal(one.textarea.hidden,false);assert.equal(two.textarea.hidden,false);
  assert.equal(a.instances.length,0);a.manager.destroy();
});
test('removed cells cannot be mounted after asynchronous assets arrive',async()=>{
  const a=setup({},false),cell=a.attach();await flush();cell.control.destroy();
  a.window.ace=a.ace;a.scripts[0].onload();await flush();assert.equal(a.instances.length,0);a.manager.destroy();
});
test('unavailable and foreign editor asset URLs preserve the safe basic editor',async()=>{
  for(const config of [{available:false},{baseUrl:'https://foreign.example/ace/',coreUrl:'https://foreign.example/ace/ace.js'}]){
    const a=setup(config),cell=a.attach();await flush();assert.equal(a.scripts.length,0);assert.equal(a.instances.length,0);assert.equal(cell.textarea.hidden,false);a.manager.destroy();
  }
});
test('gutter clicks and F9 toggle breakpoints that follow inserted and removed lines',async()=>{
  const a=setup(),cell=a.attach('a = 1\nb = 2\nc = 3\nd = 4');await flush();const editor=a.instances[0];
  assert.deepEqual([...cell.control.getBreakpoints()],[]);
  assert.equal(editor.clickGutter(2),true);assert.equal(editor.clickGutter(0,'ace_fold-widget'),false,'fold widgets are not breakpoints');
  editor.cursorRow=3;editor.commands.byName.oclToggleBreakpoint.exec();
  assert.deepEqual([...cell.control.getBreakpoints()],[3,4]);assert.deepEqual(editor.session.breakpoints,[2,3]);
  editor.type('x',{action:'insert',start:{row:0,column:5},end:{row:2,column:0}});
  assert.deepEqual([...cell.control.getBreakpoints()],[5,6],'two inserted lines push breakpoints down');
  editor.type('x',{action:'insert',start:{row:4,column:0},end:{row:5,column:0}});
  assert.deepEqual([...cell.control.getBreakpoints()],[6,7],'inserting at a line start moves that line');
  editor.type('x',{action:'remove',start:{row:4,column:0},end:{row:5,column:0}});
  assert.deepEqual([...cell.control.getBreakpoints()],[5,6]);
  editor.type('x',{action:'remove',start:{row:3,column:5},end:{row:4,column:5}});
  assert.deepEqual([...cell.control.getBreakpoints()],[5],'a breakpoint on a deleted line is dropped');
  editor.clickGutter(4);assert.deepEqual([...cell.control.getBreakpoints()],[]);assert.equal(cell.changes,4);a.manager.destroy();
});
test('the basic editor reports no breakpoints',async()=>{
  const a=setup({available:false}),cell=a.attach();await flush();assert.deepEqual([...cell.control.getBreakpoints()],[]);a.manager.destroy();
});
