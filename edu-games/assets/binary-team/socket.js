(() => {
    'use strict';
    class BinarySocket {
        constructor({url,ticket,state,status,channel='binary-team'}){this.url=url;this.channel=channel;this.ticket=ticket;this.state=state;this.status=status;this.pending=new Map();this.ready=false;this.code='';this.watch=0;this.attempt=0;this.generation=0;}
        connect(code,watch=0){
            if(!this.url)return;
            if(this.code===code){if(this.watch!==watch){this.watch=watch;if(this.ready)this.hello();}return;}
            this.close();this.code=code;this.watch=watch;this.open();
        }
        async hello(){
            const socket=this.ws,generation=this.generation;
            try{const ticket=await this.ticket();if(generation===this.generation&&socket?.readyState===1)socket.send(JSON.stringify({t:'hello',channel:this.channel,code:this.code,watch:this.watch,ticket}));}
            catch{if(generation===this.generation)this.fail();}
        }
        open(){
            if(!this.code||this.ws)return;
            const generation=this.generation;let socket;
            try{socket=new WebSocket(this.url);}catch{this.fail();return;}
            this.ws=socket;this.status(false);
            this.handshake=setTimeout(()=>this.fail(),7000);
            socket.onopen=()=>{if(generation===this.generation)this.hello();};
            socket.onmessage=event=>{
                if(generation!==this.generation)return;let m;try{m=JSON.parse(event.data);}catch{this.fail();return;}
                clearTimeout(this.liveness);this.liveness=setTimeout(()=>this.fail(),10000);
                if(m.t==='ready'&&m.app==='edu-games'&&m.channel===this.channel&&m.code===this.code){
                    clearTimeout(this.handshake);this.ready=true;this.attempt=0;this.status(true);clearInterval(this.refresh);this.refresh=setInterval(()=>this.hello(),60000);
                }else if(m.t==='state'&&m.room?.code===this.code)this.state(m.room);
                else if(m.t==='ack'){
                    const item=this.pending.get(m.rid);if(!item)return;clearTimeout(item.timer);this.pending.delete(m.rid);
                    if(m.ok)item.resolve(m.data);else{const e=Object.assign(new Error(m.message||'조작을 처리하지 못했습니다.'),{status:m.status,fallback:m.status>=500});item.reject(e);}
                }else if(m.t==='error')this.fail();
            };
            socket.onerror=()=>{if(generation===this.generation)this.fail();};
            socket.onclose=()=>{if(generation===this.generation)this.fail();};
        }
        request(command){
            if(!this.ready||this.ws?.readyState!==1)return Promise.reject(Object.assign(new Error('REST로 전환합니다.'),{fallback:true}));
            const rid=command.op;
            return new Promise((resolve,reject)=>{
                const timer=setTimeout(()=>this.fail(),7000);this.pending.set(rid,{resolve,reject,timer});
                try{this.ws.send(JSON.stringify({t:'command',rid,command}));}catch{this.fail();}
            });
        }
        fail(){
            if(this.reconnecting)return;this.reconnecting=true;this.disconnect();this.status(false);
            if(this.code)this.retry=setTimeout(()=>{this.reconnecting=false;this.open();},Math.min(10000,1000*2**Math.min(this.attempt++,4)));
        }
        disconnect(){
            this.generation++;this.ready=false;clearTimeout(this.handshake);clearTimeout(this.liveness);clearInterval(this.refresh);
            const socket=this.ws;this.ws=null;if(socket){socket.onopen=socket.onclose=socket.onerror=socket.onmessage=null;socket.close();}
            for(const item of this.pending.values()){clearTimeout(item.timer);item.reject(Object.assign(new Error('REST로 전환합니다.'),{fallback:true}));}this.pending.clear();
        }
        close(){clearTimeout(this.retry);this.reconnecting=false;this.code='';this.disconnect();}
    }
    window.EduBinarySocket=BinarySocket;
})();
