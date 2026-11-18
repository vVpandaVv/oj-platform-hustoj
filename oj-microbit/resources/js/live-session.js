(function(global){
  'use strict';
  var script=global.document.currentScript, defaultHost=script&&script.src?new URL('../simulator/host.html',script.src).href:'';if(defaultHost)defaultHost+=new URL(script.src).search;
  function create(parent,options){
    var frame=global.document.createElement('iframe'),channel=new global.MessageChannel(),disposed=false,ready=false,active=false,lastPulse=Date.now(),pending=null,latestInputs=null,inputTimer=null;
    frame.className='ojmicrobit-live-host';frame.hidden=true;frame.title='상태 유지 마이크로비트 실행 공간';frame.setAttribute('sandbox','allow-scripts');frame.setAttribute('aria-hidden','true');frame.src=options.hostUrl||defaultHost;
    var timer=global.setInterval(function(){if(!disposed&&active&&Date.now()-lastPulse>3000)fail('프로그램이 응답하지 않아 실행을 중지했어요. 반복문을 확인한 뒤 다시 시작하세요.');},250);
    var readyTimer=global.setTimeout(function(){fail('시뮬레이터를 불러오지 못했어요. 사이트의 CSP·캐시 설정과 설치 파일을 확인하세요.');},10000);
    function dispose(reason){if(disposed)return;disposed=true;active=false;global.clearInterval(timer);global.clearTimeout(readyTimer);if(inputTimer!==null)global.clearTimeout(inputTimer);try{channel.port1.postMessage({type:'dispose'});}catch(ignore){}channel.port1.close();channel.port2.close();frame.remove();if(pending){pending.reject(new Error(reason||'실행 공간을 닫았습니다.'));pending=null;}}
    function fail(message){if(disposed)return;var callback=options.onError;dispose(message);callback(new Error(message));}
    function flushInputs(){if(inputTimer!==null)global.clearTimeout(inputTimer);inputTimer=null;if(ready&&latestInputs){send({type:'inputs',values:latestInputs});latestInputs=null;}}
    function send(message){if(!disposed&&ready)channel.port1.postMessage(message);}
    channel.port1.onmessage=function(event){
      if(disposed)return;var data=event.data;if(!data||typeof data.type!=='string')return;lastPulse=Date.now();
      if(data.type==='ready'){ready=true;global.clearTimeout(readyTimer);if(pending){active=true;send({type:'start',payload:pending.payload});flushInputs();}}
      else if(data.type==='started'){if(pending){pending.resolve();pending=null;}options.onState('running');}
      else if(data.type==='paused'){active=false;options.onState('paused');}
      else if(data.type==='resumed'){active=true;options.onState('running');}
      else if(data.type==='frame'){
        if(!Number.isFinite(data.atMs)||data.atMs<0||!Array.isArray(data.trace)||!Array.isArray(data.i2cTrace)||data.trace.length+data.i2cTrace.length>2000){fail('잘못된 시뮬레이터 응답입니다.');return;}
        options.onFrame(data);
      }else if(data.type==='error'){fail(typeof data.message==='string'?data.message.slice(0,1000):'시뮬레이터 실행 오류입니다.');}
    };
    channel.port1.start();frame.addEventListener('load',function(){if(!disposed&&!ready)frame.contentWindow.postMessage({type:'oj-microbit-live-connect'},'*',[channel.port2]);},{once:true});parent.append(frame);
    return {start:function(payload){return new Promise(function(resolve,reject){if(disposed){reject(new Error('실행 공간을 닫았습니다.'));return;}pending={payload:payload,resolve:resolve,reject:reject};if(ready){active=true;lastPulse=Date.now();send({type:'start',payload:payload});}});},inputs:function(values){latestInputs=values;if(inputTimer===null)inputTimer=global.setTimeout(flushInputs,16);},pause:function(){flushInputs();send({type:'pause'});},resume:function(){active=true;lastPulse=Date.now();send({type:'resume'});},dispose:dispose,frame:frame};
  }
  global.OJMicrobitLive={create:create};
})(window);
