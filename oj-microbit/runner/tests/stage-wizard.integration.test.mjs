import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { grade } from '../src/grade.mjs';
import { project } from './fixtures.mjs';
const ui=createRequire(import.meta.url)('../../resources/js/microbit.js');
const sensor={pin:0,direction:'input',signal:'analog',device:'potentiometer',label:'센서'};
const led={pin:1,direction:'output',signal:'digital',device:'led',label:'LED'};
const motor={pin:2,direction:'output',signal:'pwm',device:'motor',label:'모터'};
const def=overrides=>({mode:'stage',atMs:0,pin:0,value:800,holdMs:500,withinMs:100,tolerance:0,...overrides});
function add(model,overrides){return ui.applyStageWizard(model.scenario,model.connections,def(overrides));}
function model(){return add({scenario:ui.emptyScenario(1),connections:[]},{connection:sensor});}
function payload(model,source){return {project:project(source),exercise:{connections:model.connections,scenarios:[model.scenario]}};}
test('actual PXT accepts wizard input and parallel output conditions with independent timing',async()=>{
  let m=add(model(),{mode:'parallel',pin:1,value:1,holdMs:200,connection:led});m=add(m,{mode:'parallel',pin:2,value:800,holdMs:300,connection:motor});
  const result=await grade(payload(m,'pins.digitalWritePin(DigitalPin.P1, 1); pins.analogWritePin(AnalogPin.P2, pins.analogReadPin(AnalogPin.P0)); basic.pause(500); pins.digitalWritePin(DigitalPin.P1, 0)'));
  assert.equal(result.passed,true);assert.equal(result.score,100);assert.equal(result.scenarios[0].outputSequences.length,2);assert.deepEqual(result.scenarios[0].outputSequences.map(x=>x.steps[0].matchedAtMs),[0,0]);assert.deepEqual(result.scenarios[0].outputSequences.map(x=>x.steps[0].completedAtMs),[200,300]);
});
test('actual PXT distinguishes pause after immediate on from pause before on under wizard deadlines',async()=>{
  const m=add(model(),{mode:'parallel',pin:1,value:1,holdMs:200,connection:led});
  const immediate=await grade(payload(m,'pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(500); pins.digitalWritePin(DigitalPin.P1, 0)'));assert.equal(immediate.score,100);
  const late=await grade(payload(m,'basic.pause(500); pins.digitalWritePin(DigitalPin.P1, 1)'));assert.equal(late.score,0);
});
test('actual PXT follows wizard serial arrows from preceding completion and enforces later off deadline',async()=>{
  let m=add(model(),{mode:'parallel',pin:1,value:1,holdMs:500,connection:led});m=add(m,{mode:'serial',pin:1,value:0,holdMs:200});
  const code='pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(500); pins.digitalWritePin(DigitalPin.P1, 0)';const correct=await grade(payload(m,code));assert.equal(correct.score,100);assert.deepEqual(correct.scenarios[0].outputSequences[0].steps.map(x=>x.matchedAtMs),[0,500]);
  m=add(m,{mode:'edit',pin:1,value:1,holdMs:200,target:{kind:'outputSequences',index:0,actionIndex:0}});const wrong=await grade(payload(m,code));assert.equal(wrong.passed,false);assert.deepEqual(wrong.scenarios[0].outputSequences[0].steps.map(x=>x.passed),[true,false]);
});
