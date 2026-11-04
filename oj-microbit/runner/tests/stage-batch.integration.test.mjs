import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { grade } from '../src/grade.mjs';
import { project, request } from './fixtures.mjs';
const ui=createRequire(import.meta.url)('../../resources/js/microbit.js');
const sensor={pin:0,direction:'input',signal:'analog',device:'potentiometer',label:'센서'};
const led={pin:1,direction:'output',signal:'digital',device:'led',label:'LED'};
const motor={pin:2,direction:'output',signal:'pwm',device:'motor',label:'모터'};
const action=(expected,holdMs=200)=>({expected,holdMs,withinMs:100,tolerance:0});
const channels=()=>[{pin:0,newConnection:sensor,value:800},{pin:1,newConnection:led,steps:[action(1,500),action(0)]},{pin:2,newConnection:motor,steps:[action(800)]}];
const model=()=>ui.applyStageBatch(ui.emptyScenario(1),[],{mode:'new',atMs:0,intervalMs:1000,channels:channels()});
const payload=(m,source)=>({project:project(source),exercise:{connections:m.connections,scenarios:[m.scenario]}});
const code='let value = pins.analogReadPin(AnalogPin.P0); pins.digitalWritePin(DigitalPin.P1, 1); pins.analogWritePin(AnalogPin.P2, value); basic.pause(500); pins.digitalWritePin(DigitalPin.P1, 0)';
test('actual PXT grades a batch of sensor, serial LED and parallel PWM as configured together',async()=>{
  const result=await grade(payload(model(),code));assert.equal(result.score,100);assert.equal(result.passed,true);
  assert.deepEqual(result.scenarios[0].outputSequences.find(x=>x.pin===1).steps.map(x=>x.matchedAtMs),[0,500]);
  assert.equal(result.scenarios[0].outputSequences.find(x=>x.pin===2).steps[0].matchedAtMs,0);
});
test('a wrong parallel pin does not make its correctly completed serial neighbor fail',async()=>{
  const result=await grade(payload(model(),code.replace('AnalogPin.P2, value','AnalogPin.P2, 100')));assert.equal(result.passed,false);
  assert.equal(result.scenarios[0].outputSequences.find(x=>x.pin===1).passed,true);assert.equal(result.scenarios[0].outputSequences.find(x=>x.pin===2).passed,false);
});
test('the next batch stage applies all its input and output reference times together',async()=>{
  let m=model();m=ui.applyStageBatch(m.scenario,m.connections,{mode:'new',atMs:1000,intervalMs:1000,channels:[{pin:0,value:200},{pin:1,steps:[action(0)]},{pin:2,steps:[action(200)]}]});
  const result=await grade(payload(m,code+'; basic.pause(500); let next = pins.analogReadPin(AnalogPin.P0); pins.digitalWritePin(DigitalPin.P1, 0); pins.analogWritePin(AnalogPin.P2, next)'));
  assert.equal(result.score,100);assert.deepEqual(result.trace.filter(x=>x.kind==='write'&&x.pin===2).map(x=>x.value),[800,200]);
});
test('batch servo angle and pulse outputs remain different actual PXT signals',async()=>{
  const m=ui.applyStageBatch(ui.emptyScenario(1),[],{mode:'new',atMs:0,intervalMs:1000,channels:[{pin:1,newConnection:{...led,device:'servo',signal:'servo'},steps:[action(90)]},{pin:2,newConnection:{...motor,device:'servo',signal:'servo_pulse'},steps:[{...action(1500),tolerance:50}]}]});
  const result=await grade(payload(m,'pins.servoWritePin(AnalogPin.P1, 90); pins.servoSetPulse(AnalogPin.P2, 1500)'));assert.equal(result.score,100);assert.deepEqual(result.scenarios[0].outputSequences.map(x=>x.signal),['servo','servo_pulse']);
});
test('batch editing existing fixed-time assertions preserves their original grader behavior',async()=>{
  const input=request(),before=structuredClone(input.exercise.scenarios[0]);const chosen=ui.stageBatchChannels(before,input.exercise.connections,0).map(x=>({pin:x.pin,value:x.value,steps:x.steps}));
  const edited=ui.applyStageBatch(before,input.exercise.connections,{mode:'edit',atMs:0,intervalMs:300,channels:chosen});input.exercise.scenarios=[edited.scenario];
  assert.equal(edited.scenario.assertions.length,before.assertions.length);assert.equal(edited.scenario.outputSequences.length,0);assert.equal((await grade(input)).score,100);
});
