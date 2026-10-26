import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const script=await readFile(new URL('../resources/js/worksheet-workflow.js',import.meta.url),'utf8');
const stages=['criteria','author','review','assignment','assessment','report','release'];
const server=createServer((request,response)=>{
  response.setHeader('Content-Type','text/html; charset=utf-8');
  response.end(`<section id="oj-ai-projects" data-project-stage="author"><nav data-oj-ai-workflow data-current-step="author" data-workflow-base="/projects/"><select data-workflow-select>${stages.map(stage=>`<option value="${stage}">${stage}</option>`).join('')}</select>${stages.map(stage=>stage==='release'?`<span data-workflow-step="release" aria-disabled="true">release</span>`:`<a data-workflow-step="${stage}">${stage}</a>`).join('')}</nav></section><script>${script}</script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const {chromium}=await loadPlaywright();
const browser=await chromium.launch(browserLaunchOptions());
try {
  const page=await browser.newPage();
  const id=n=>String(n).padStart(26,'0');
  const original={projectId:id(1),projectTitle:'Evaluation A',worksheetId:id(2),title:'Worksheet A',assignmentId:id(3),assignmentTitle:'Group A assignment',publicationId:id(4),publicationHash:'a'.repeat(64),context:{type:'group',id:'group:1',revision:'r1',label:'Group A'}};
  await page.goto(`http://127.0.0.1:${server.address().port}/?stage=author`);
  const select=value=>page.evaluate(value=>{window.OJAIWorkflow.setSelection(value);return {selection:window.OJAIWorkflow.read(),url:location.href};},value);
  const clearDeployment=result=>{
    for(const key of ['assignmentId','assignmentTitle','publicationId','publicationHash'])assert.equal(result.selection[key],'',key);
    for(const key of ['assignment_id','publication_id','publication_hash'])assert.equal(new URL(result.url).searchParams.has(key),false,key);
  };
  await select(original);
  let result=await select({worksheetId:id(5)});clearDeployment(result);
  assert.equal(result.selection.context.id,'group:1');assert.equal(result.selection.title,'');
  await select(original);
  result=await select({context:{...original.context,id:'group:2'}});clearDeployment(result);
  assert.equal(result.selection.worksheetId,original.worksheetId);
  await select(original);
  result=await select({context:{...original.context,revision:'r2'}});clearDeployment(result);
  await select(original);
  result=await select({projectId:id(6)});clearDeployment(result);
  assert.equal(result.selection.worksheetId,'');assert.equal(result.selection.projectTitle,'');assert.equal(result.selection.context,null);
  result=await select({...original,worksheetId:id(5),assignmentId:id(7),publicationId:id(8),publicationHash:'b'.repeat(64)});
  assert.equal(result.selection.assignmentId,id(7));assert.equal(result.selection.publicationId,id(8));assert.equal(result.selection.publicationHash,'b'.repeat(64));
  result=await select({assignmentId:id(9)});assert.equal(result.selection.publicationId,'');assert.equal(result.selection.publicationHash,'');
  await select(original);
  result=await select({publicationId:id(10)});assert.equal(result.selection.publicationHash,'');
  const permission=await page.evaluate(()=>{window.OJAIWorkflow.setStage('release');window.OJAIWorkflow.setStage('invalid]');return {stage:document.querySelector('nav').dataset.currentStep,url:location.href};});
  assert.equal(permission.stage,'author');assert.equal(new URL(permission.url).searchParams.get('stage'),'author');
  console.log('PASS workflow selection: project, worksheet, context revision, deployment identity and denied stage.');
} finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
