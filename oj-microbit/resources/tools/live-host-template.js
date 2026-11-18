(function(){
  'use strict';
  // Refuse a same-origin or unsandboxed embedding before accepting any executable payload.
  if(globalThis.parent===globalThis||globalThis.origin!=='null')return;
  var connected=false;
  addEventListener('message',function(event){
    if(connected||event.source!==parent||event.data?.type!=='oj-microbit-live-connect'||event.ports.length!==1)return;
    connected=true;var port=event.ports[0],worker=null,url=null;
    function error(message){port.postMessage({type:'error',message:String(message).slice(0,1000)});}
    port.onmessage=function(e){
      var data=e.data;
      if(!data||!['start','inputs','pause','resume','dispose'].includes(data.type))return;
      try{
        if(data.type==='dispose'){if(worker)worker.terminate();if(url)URL.revokeObjectURL(url);port.close();return;}
        if(data.type==='start'){
          if(worker)throw Error('Already started.');url=URL.createObjectURL(new Blob([/*__WORKER_SOURCE__*/],{type:'text/javascript'}));worker=new Worker(url);URL.revokeObjectURL(url);url=null;
          worker.onmessage=function(message){port.postMessage(message.data);};worker.onerror=function(){worker.terminate();error('시뮬레이터 실행 오류가 발생했어요. 코드를 확인하거나 다시 시작하세요.');};
        }
        if(worker)worker.postMessage(data);
      }catch(err){if(worker)worker.terminate();error(err.message);}
    };
    port.start();port.postMessage({type:'ready'});
  });
})();
