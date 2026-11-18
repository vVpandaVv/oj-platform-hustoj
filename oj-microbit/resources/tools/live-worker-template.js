// This function is assembled with the pinned PXT sources by build-live.mjs.
function liveWorker() {
  'use strict';
  var wallTimer = globalThis.setTimeout.bind(globalThis), wallClear = globalThis.clearTimeout.bind(globalThis), wallNow = globalThis.performance.now.bind(globalThis.performance), send = globalThis.postMessage.bind(globalThis);
  var runtime = null, scheduled = null, paused = false, lastWall = 0, lastHeartbeat = 0;
  function fail(error) { if(scheduled!==null)wallClear(scheduled);scheduled=null;paused=true;send({type:'error',message:String(error&&error.message||error).slice(0,1000)}); }
  function flush() { var value=runtime.drain();if(value.trace.length||value.i2cTrace.length||wallNow()-lastHeartbeat>=100){lastHeartbeat=wallNow();send({type:'frame',...value});} }
  function pump() {
    scheduled=null;if(!runtime||paused)return;
    try { var now=wallNow(),delta=Math.max(0,Math.min(100,now-lastWall));lastWall=now;runtime.advance(delta);flush();scheduled=wallTimer(pump,10); }catch(e){fail(e);}
  }
  globalThis.onmessage=function(event){
    var message=event.data;
    try{
      if(message.type==='start'){
        if(runtime)throw Error('A runtime can only start once.');runtime=createRuntime(message.payload);lastWall=wallNow();send({type:'started'});pump();
      }else if(message.type==='inputs'&&runtime){
        if(!paused){runtime.advance(Math.max(0,Math.min(100,wallNow()-lastWall)));lastWall=wallNow();}
        runtime.inputs(message.values);flush();
      }else if(message.type==='pause'&&runtime){if(scheduled!==null)wallClear(scheduled);scheduled=null;paused=true;send({type:'paused',atMs:runtime.time()});
      }else if(message.type==='resume'&&runtime&&paused){paused=false;lastWall=wallNow();send({type:'resumed',atMs:runtime.time()});pump();}
    }catch(e){fail(e);}
  };
  function createRuntime(payload){
    /*__RUNTIME__*/
    var externalI2C=new Map((payload.i2cInputs||[]).map(e=>[e.address+':'+e.register,e.value]));
    for(var original of payload.i2cDevices||[])for(var register of original.registers){var key=original.address+':'+register.register;if(!externalI2C.has(key))externalI2C.set(key,register.value);}
    function advance(delta){
      var target=clock.now+delta,operations=0;
      while(!rt.dead){
        var timer=null;for(var candidate of clock.timers.values())if(candidate.at<=target&&(!timer||candidate.at<timer.at||candidate.at===timer.at&&candidate.id<timer.id))timer=candidate;
        if(!timer)break;
        if(++operations>2000)throw Error('Simulation operation limit exceeded.');
        clock.now=timer.at;clock.timers.delete(timer.id);timer.fn(...timer.args);
      }
      clock.now=target;if(failure)throw Error(failure);
    }
    function update(values){
      for(var e of values.inputs||[]){var c=connections.get(e.pin);if(!c||c.direction!=='input'||c.signal!==e.signal||!Number.isInteger(e.value)||e.value<0||e.value>(c.signal==='digital'?1:1023))throw Error('Invalid live GPIO input.');}
      for(var e of values.i2cInputs||[]){var device=i2cDevice(e.address);i2cRegister(device,e.register);if(!Number.isInteger(e.value)||e.value<0||e.value>255)throw Error('Invalid live I2C input.');}
      for(var e of values.inputs||[])inputs.set(e.pin,e.value);
      for(var e of values.i2cInputs||[]){var key=e.address+':'+e.register;if(externalI2C.get(key)!==e.value){i2cDevices.get(e.address).registers.set(e.register,e.value);externalI2C.set(key,e.value);}}
    }
    return {advance,inputs:update,time:()=>clock.now,drain:()=>{if(trace.length+i2cTrace.length>2000)throw Error('Live output rate limit exceeded.');var value={atMs:clock.now,trace:trace.splice(0),i2cTrace:i2cTrace.splice(0)};return value;}};
  }
}
