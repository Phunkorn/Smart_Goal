import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {boardMarkup} from './helpers/workspace-board.js';
import {initAutosave} from '../../resources/js/pages/workspace/autosave.js';
import {VersionConflictError,createClient} from '../../resources/js/pages/workspace/client.js';
import {clone,mergeDocuments,applyHistoryDelta,withoutConflicts} from '../../resources/js/pages/workspace/synchronization.js';
import {createDraftStore} from '../../resources/js/pages/workspace/draft.js';

const element=(id='a',extra={})=>({id,type:'text',z:1,x:10,y:10,w:100,h:50,text:'old',fontSize:20,color:'#1f2937',...extra});
const document=(...elements)=>({schema:1,elements});
const initial=document(element());
const server=()=>{
    let data=clone(initial),version=1;const receipts=new Map();const calls=[];
    return {calls,get:()=>({version,document:clone(data)}),client:{
        async load(){calls.push('load');return {ok:true,version,document:clone(data)};},
        async save(batch){calls.push(clone(batch));if(receipts.has(batch.requestId))return {ok:true,request_id:batch.requestId,...thisState()};if(batch.baseVersion!==version)throw new VersionConflictError({version,document:clone(data)});
            const map=new Map(data.elements.map(e=>[e.id,e]));batch.operations.forEach(op=>op.type==='delete'?map.delete(op.id):map.set(op.id,op.element));data=document(...map.values());version++;receipts.set(batch.requestId,true);return {ok:true,request_id:batch.requestId,...thisState()};},
    }};
    function thisState(){return {version,document:clone(data)};}
};
const mount=(transport,{readOnly=false,data=initial}={})=>{
    const env=mountDom(boardMarkup());let local=clone(data);const timers=new Map();let serial=0;
    const sync=initAutosave({root:env.document.querySelector('[data-workspace-board]'),doc:env.document,design:{saveStates:{saved:{label:'บันทึกแล้ว'},dirty:{label:'งานค้าง'}}},initialVersion:1,initialDocument:data,
        capabilities:{canEdit:!readOnly},client:transport,getDocument:()=>local,onReplaceDocument:d=>{local=clone(d);},setTimeoutImpl:(fn,ms)=>{timers.set(++serial,{fn,ms});return serial;},clearTimeoutImpl:id=>timers.delete(id)});
    return {...env,sync,timers,get:()=>local,edit:elements=>{local=document(...elements);sync.markDirty();},cleanup:()=>{sync.destroy();env.cleanup();}};
};
test('H2: same text conflict preserves local work, prevents unload, and never opens a blocking dialog',async()=>{
    const remote=server(),a=mount(remote.client),b=mount(remote.client);
    try{a.edit([element('a',{text:'A'})]);b.edit([element('a',{text:'B'})]);await a.sync.saveNow();await b.sync.saveNow();
        assert.equal(remote.get().document.elements[0].text,'A');assert.equal(b.get().elements[0].text,'B');assert.equal(b.sync.isDirty(),true);assert.equal(b.sync.conflicts().length,1);
        const leave=new b.window.Event('beforeunload',{cancelable:true});b.window.dispatchEvent(leave);assert.equal(leave.defaultPrevented,true);
        await b.sync.refresh();assert.equal(b.get().elements[0].text,'B');b.sync.resolve('local');await b.sync.saveNow();assert.equal(remote.get().document.elements[0].text,'B');
    }finally{a.cleanup();b.cleanup();}
});
for(const clients of [2,5,10])test(`${clients} clients merge distinct objects without dropping edits`,async()=>{
    const remote=server(),envs=Array.from({length:clients},()=>mount(remote.client));
    try{envs.forEach((env,i)=>env.edit([element(),element(`new-${i}`,{z:i+2,text:`user-${i}`})]));
        for(let round=0;round<clients;round++){await Promise.all(envs.map(e=>e.sync.saveNow()));await Promise.all(envs.map(e=>e.sync.refresh()));}
        assert.equal(remote.get().document.elements.length,clients+1);for(const env of envs){assert.equal(env.get().elements.length,clients+1);assert.equal(env.sync.conflicts().length,0);}
    }finally{envs.forEach(e=>e.cleanup());}
});
test('different properties merge, geometric fields conflict as one group',()=>{
    const base=initial,mine=document(element('a',{text:'mine'})),remote=document(element('a',{color:'#dc2626'}));
    const merged=mergeDocuments(base,mine,remote);assert.equal(merged.conflicts.length,0);assert.equal(merged.document.elements[0].text,'mine');assert.equal(merged.document.elements[0].color,'#dc2626');
    assert.equal(mergeDocuments(base,document(element('a',{x:30})),document(element('a',{w:150}))).conflicts[0].group,'geometry');
});
test('delete versus edit never resurrects the deleted ID on automatic save',async()=>{
    const remote=server(),a=mount(remote.client),b=mount(remote.client);
    try{a.edit([]);b.edit([element('a',{text:'recover me'})]);await a.sync.saveNow();await b.sync.saveNow();assert.equal(remote.get().document.elements.length,0);assert.equal(b.get().elements[0].text,'recover me');assert.equal(b.sync.conflicts().length,1);
        b.sync.resolve('local');await b.sync.saveNow();assert.notEqual(remote.get().document.elements[0].id,'a');
    }finally{a.cleanup();b.cleanup();}
});
test('H3: refresh waits for save; edits during flight survive acknowledgement',async()=>{
    const remote=server();let release;const real=remote.client.save.bind(remote.client);
    const env=mount({...remote.client,save:batch=>new Promise(resolve=>{release=async()=>resolve(await real(batch));})});
    try{env.edit([element('a',{text:'first'})]);const pending=env.sync.saveNow();const refresh=env.sync.refresh();env.edit([element('a',{text:'second'})]);await release();await pending;await refresh;
        assert.equal(env.get().elements[0].text,'second');assert.equal(env.sync.currentVersion(),2);assert.equal(env.sync.isDirty(),true);
    }finally{env.cleanup();}
});
test('stale response and response after destroy cannot replace the current board',async()=>{
    let resolve;const env=mount({save:async()=>{},load:()=>new Promise(r=>{resolve=r;})});
    const pending=env.sync.refresh();env.sync.destroy();resolve({version:99,document:document(element('alien'))});await pending;assert.equal(env.get().elements[0].id,'a');env.cleanup();
});
test('read-only clients receive automatic updates and never send saves',async()=>{
    const remote=server(),a=mount(remote.client),viewer=mount(remote.client,{readOnly:true});
    try{a.edit([element('a',{text:'shared'})]);await a.sync.saveNow();await [...viewer.timers.values()][0].fn();await viewer.sync.refresh();assert.equal(viewer.get().elements[0].text,'shared');assert.equal(remote.calls.filter(c=>typeof c==='object').length,1);}finally{a.cleanup();viewer.cleanup();}
});
test('clean hidden tab does not save or schedule polling; visible tab synchronizes',async()=>{
    const remote=server(),env=mount(remote.client);
    try{Object.defineProperty(env.document,'hidden',{value:true,configurable:true});env.document.dispatchEvent(new env.window.Event('visibilitychange'));assert.equal(remote.calls.length,0);assert.equal(env.timers.size,0);
        Object.defineProperty(env.document,'hidden',{value:false});env.document.dispatchEvent(new env.window.Event('visibilitychange'));await env.sync.refresh();assert.ok(remote.calls.includes('load'));}finally{env.cleanup();}
});
test('lost response retry uses the identical operation ID without double application',async()=>{
    const remote=server();let lose=true;const actual=remote.client.save.bind(remote.client);const env=mount({...remote.client,save:async batch=>{const result=await actual(batch);if(lose){lose=false;throw new Error('network');}return result;}});
    try{env.edit([element(),element('new')]);await env.sync.saveNow();assert.equal(env.sync.isDirty(),true);await env.sync.saveNow();assert.equal(remote.get().version,2);assert.equal(remote.calls[0].requestId,remote.calls[1].requestId);assert.equal(env.sync.isDirty(),false);}finally{env.cleanup();}
});
test('undo preserves remote creates and independent property changes',()=>{
    const before=initial,after=document(element('a',{x:30}));const current=document(element('a',{x:30,color:'#ff0000'}),element('remote'));
    const undone=applyHistoryDelta(current,after,before);assert.equal(undone.elements.length,2);assert.equal(undone.elements[0].x,10);assert.equal(undone.elements[0].color,'#ff0000');
    assert.equal(applyHistoryDelta(document(),after,before).elements.length,0);
});
test('durable draft expires and is never restored by a different account or board',()=>{
    const env=mountDom();try{const store=createDraftStore({storage:env.window.localStorage,accountId:1,boardId:7,now:()=>1});store.save({base:initial,local:initial,version:1,conflicts:[]});assert.equal(store.load().accountId,1);
        assert.equal(createDraftStore({storage:env.window.localStorage,accountId:1,boardId:8}).load(),null);
        assert.equal(createDraftStore({storage:env.window.localStorage,accountId:2,boardId:7}).load(),null);assert.equal(store.load(),null);
        store.save({base:initial,local:initial,version:1});assert.equal(createDraftStore({storage:env.window.localStorage,accountId:1,boardId:7,now:()=>8*86400000}).load(),null);
    }finally{env.cleanup();}
});
for(const status of [429,500,403,419])test(`HTTP ${status} classifies retry without clearing pending work`,async()=>{
    const env=mountDom();try{const client=createClient({routes:{save:'/save'},doc:env.document,fetchImpl:async()=>({ok:false,status,json:async()=>({message:'failed'})})});await assert.rejects(client.save({document:initial,baseVersion:1}),error=>error.status===status && error.retryable===(status===429||status>=500));}finally{env.cleanup();}
});
test('HTTP 200 invalid JSON cannot masquerade as saved',async()=>{
    const env=mountDom();try{const client=createClient({routes:{save:'/save'},doc:env.document,fetchImpl:async()=>({ok:true,status:200,json:async()=>{throw new Error('html');}})});await assert.rejects(client.save({document:initial,baseVersion:1}),/ข้อมูลตอบกลับ/);}finally{env.cleanup();}
});
test('timeout rejects a hanging transport and aborts it',async()=>{
    const env=mountDom();let signal;try{const client=createClient({routes:{save:'/save'},doc:env.document,timeoutMs:5,fetchImpl:async(url,options)=>{signal=options.signal;return new Promise(()=>{});}});await assert.rejects(client.save({document:initial,baseVersion:1}),/ใช้เวลานาน/);assert.equal(signal.aborted,true);}finally{env.cleanup();}
});

test('canonical defaults and numeric normalization do not create a self-conflict during typing',()=>{
    const base=document(element('a',{text:'sent',lineAligns:{},x:1.234}));
    const local=document(element('a',{text:'next',lineAligns:{},x:1.234}));
    const remote=document(element('a',{text:'sent',x:1.23}));
    const merged=mergeDocuments(base,local,remote);assert.equal(merged.conflicts.length,0);assert.equal(merged.document.elements[0].text,'next');
});

test('permanent validation failure retains draft and does not retry merely because polling succeeds',async()=>{
    let calls=0;const env=mount({load:async()=>({version:1,unchanged:true}),save:async()=>{calls++;throw Object.assign(new Error('invalid document'),{retryable:false,status:422});}});
    try{env.edit([element('a',{text:'pending'})]);await env.sync.saveNow();await env.sync.refresh();await env.sync.saveNow();
        // Manual save is explicit; automatic refresh must not enqueue another request.
        assert.equal(calls,2);assert.equal(env.sync.isDirty(),true);
        assert.equal(env.document.querySelector('[data-workspace-save-state]').dataset.state,'blocked');
    }finally{env.cleanup();}
});
