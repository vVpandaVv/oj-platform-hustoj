'use strict';
const crypto=require('node:crypto');

function readTicket(ticket,secret,now=Date.now(),channel='binary-team') {
    if(typeof ticket!=='string'||ticket.length>2048)return null;
    const parts=/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/.exec(ticket);if(!parts)return null;
    const expected=crypto.createHmac('sha256',secret).update(parts[1]).digest();
    if(!crypto.timingSafeEqual(expected,Buffer.from(parts[2],'hex')))return null;
    let p;try{p=JSON.parse(Buffer.from(parts[1],'base64url').toString());}catch{return null;}
    if(!['binary-team','device-team'].includes(channel)||p.purpose!==`edu-games-${channel}`||!Number.isSafeInteger(p.id)||p.id<1||!Number.isInteger(p.iat)||!Number.isInteger(p.exp)
        ||p.exp<=now/1000||p.iat>now/1000+5||p.exp-p.iat!==90||typeof p.name!=='string'||typeof p.manage!=='boolean'||typeof p.admin!=='boolean')return null;
    return p;
}

function createAdapter({app,rooms,fetch:fetcher=globalThis.fetch,clock=()=>Date.now()}) {
    if(app.id!=='edu-games'||!/^https?:\/\//.test(app.env.WP_API||''))throw Error('Invalid Edu Games registration');
    const channels=new Map(['binary-team','device-team'].map(name=>[name,rooms.channel(app.id,name)])),connections=new Set();
    const send=(c,body)=>{if(c?.socket.readyState===1)c.socket.send(JSON.stringify(body));};
    const error=e=>({status:e.status||503,message:e.message||'실시간 서버에 연결하지 못했습니다.'});
    function remove(c){
        if(!c.code)return;const channel=channels.get(c.channel),room=channel.get(c.code);room?.clients.delete(c.id);if(room&&!room.clients.size)channel.delete(c.code);c.code='';
    }
    async function sync(room,action,requester,rid) {
        if(!room.clients.size)return;
        const sessions=Object.fromEntries([...room.clients].map(([id,c])=>[id,{ticket:c.ticket,watch:c.watch}]));
        const raw=JSON.stringify({sync:true,app:app.id,channel:room.channel,code:room.code,sessions,action:action?{client:requester.id,command:action}:null});
        const time=String(Math.floor(Date.now()/1000));
        const signature=crypto.createHmac('sha256',app.secret).update(`POST\n/binary-team/sync\n${time}\n${raw}`).digest('hex');
        try{
            const r=await fetcher(app.env.WP_API,{method:'POST',redirect:'error',signal:AbortSignal.timeout(6000),
                headers:{'Content-Type':'application/json','X-OJ-Realtime-Time':time,'X-OJ-Realtime-Signature':signature},body:raw});
            const data=await r.json();if(!r.ok||!data.views||typeof data.views!=='object')throw Object.assign(Error(data.message||'경기 서버에 연결하지 못했습니다.'),{status:r.status});
            if(action&&requester?.code===room.code)send(requester,{t:'ack',rid,...data.reply});
            for(const [id,c]of room.clients){
                const view=data.views[id],failure=data.errors?.[id]||view?.error;
                if(failure){send(c,{t:'error',...failure});c.socket.close(1008,'access');continue;}
                if(!view?.data)continue;
                if(!c.ready){c.ready=true;send(c,{t:'ready',app:app.id,channel:room.channel,code:room.code});}
                send(c,{t:'state',room:view.data});
            }
        }catch(e){
            if(action&&requester)send(requester,{t:'ack',rid,ok:false,...error(e)});
            for(const c of room.clients.values()){send(c,{t:'error',...error(e)});c.socket.close(1011,'backend-unavailable');}
        }finally{room.lastSync=Date.now();}
    }
    function enqueue(room,action=null,c=null,rid=null){
        if(room.queued>=128){send(c,{t:'ack',rid,ok:false,status:429,message:'조작 요청이 많습니다. 잠시 뒤 다시 시도하세요.'});return;}
        room.queued++;room.tail=room.tail.then(()=>sync(room,action,c,rid)).finally(()=>room.queued--);
    }
    return {
        tickMs:250,maxPayload:8192,
        connect(socket){const c={id:crypto.randomBytes(12).toString('hex'),socket,ready:false,code:'',rateAt:Date.now(),rate:0};connections.add(c);return c;},
        disconnect(c){remove(c);connections.delete(c);},
        message(c,raw){
            let m;try{m=JSON.parse(raw);}catch{c.socket.close(1008,'invalid-json');return;}
            if(Date.now()-c.rateAt>=1000){c.rateAt=Date.now();c.rate=0;}if(++c.rate>40){c.socket.close(1008,'rate-limit');return;}
            if(m?.t==='hello'){
                const name=m.channel||'binary-team',p=readTicket(m.ticket,app.secret,clock(),name);
                if(!p||!/^([A-Z2-9]{6})$/.test(m.code||'')||!Number.isInteger(m.watch)||m.watch<0||m.watch>6||(c.uid&&c.uid!==p.id)){
                    send(c,{t:'error',status:401,message:'실시간 입장권을 확인하세요.'});c.socket.close(1008,'bad-ticket');return;
                }
                if(c.code!==m.code||c.channel!==name){remove(c);c.ready=false;}c.uid=p.id;c.ticket=m.ticket;c.watch=m.watch;c.code=m.code;c.channel=name;
                const channel=channels.get(name);
                if(!channel.has(c.code))channel.set(c.code,{code:c.code,channel:name,clients:new Map(),tail:Promise.resolve(),queued:0,lastSync:0});
                const room=channel.get(c.code);if(room.clients.size>=80&&!room.clients.has(c.id)){c.socket.close(1013,'room-full');return;}
                room.clients.set(c.id,c);enqueue(room);return;
            }
            if(!c.ready||!readTicket(c.ticket,app.secret,clock(),c.channel)){send(c,{t:'error',status:401,message:'입장권을 갱신하세요.'});c.socket.close(1008,'expired-ticket');return;}
            if(m?.t==='command'&&typeof m.rid==='string'&&m.rid.length<=64&&m.command&&typeof m.command==='object'&&!Array.isArray(m.command)){
                enqueue(channels.get(c.channel).get(c.code),m.command,c,m.rid);
            }
        },
        tick(){for(const channel of channels.values())for(const room of channel.values())if(!room.queued&&Date.now()-room.lastSync>=700)enqueue(room);},
        status(){return {supportedChannels:[...channels.keys()],clients:connections.size};},
        close(){for(const c of connections)c.socket.close(1001,'server-close');connections.clear();for(const channel of channels.values())channel.clear();},
    };
}
module.exports={createAdapter,readTicket};
