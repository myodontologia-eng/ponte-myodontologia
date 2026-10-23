const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('wordpress/myla-asb.php','utf8').split("<<<'JS'\n")[1].split('\nJS;')[0];
const dom=new JSDOM(`<form><section id="myod-asb"><input class="asb-date" value="2026-10-23"><textarea class="asb-note">Exemplo fictício</textarea><input type="checkbox" class="asb-consent"><button type="button" class="asb-generate"></button><button type="button" class="asb-discard"></button><p class="asb-status"></p><div class="asb-preview"></div></section><div data-key="field_myfc_evolucoes"><div class="rptx-tbody"><div id="old-record">EVOLUÇÃO ANTERIOR</div></div><button type="button" class="rptx-add-row"></button></div></form>`,{runScripts:'outside-only',url:'https://example.test'});
const w=dom.window,d=w.document;
const keys=['dente','procedimento','evolucao','orientacoes','tecnica','retorno','data'];
const body=d.querySelector('.rptx-tbody');
d.querySelector('.rptx-add-row').onclick=()=>{
 const row=d.createElement('div');row.className='new-row';
 for(const key of keys){const f=d.createElement('div');f.dataset.key='field_myfc_evolucoes_'+key;if(key==='data'){f.dataset.type='date_picker';f.innerHTML='<input type="hidden" name="date"><input type="text">';}else f.innerHTML='<textarea name="'+key+'"></textarea>';row.append(f);}
 body.append(row);
};
let requests=0;
w.fetch=async()=>{requests++;return{ok:true,json:async()=>({success:true,data:{draft:{evolucao:{dente:'36',procedimento:'Avaliação',evolucao:'Sem dor',orientacoes:'',tecnica:'',retorno:'Retorno em 15 dias'},pendencias:['Conferir data do retorno']}}})};};
w.eval('(function(cfg){'+source+'})({ajax:"/ajax",nonce:"test",ficha:220});');
async function run(){
 d.querySelector('.asb-generate').click();await new Promise(r=>setImmediate(r));
 assert.equal(requests,0,'No provider call before consent');
 d.querySelector('.asb-consent').checked=true;d.querySelector('.asb-generate').click();await new Promise(r=>setImmediate(r));
 assert.equal(requests,1);assert.equal(body.children.length,1,'Generation must not prefill or alter records');
 const button=d.querySelector('.asb-preview button');assert(button);
 d.querySelector('.asb-preview textarea').value='37';button.click();
 assert.equal(body.children.length,2);assert.equal(d.querySelector('#old-record').textContent,'EVOLUÇÃO ANTERIOR');
 assert.equal(d.querySelector('.new-row textarea').value,'37','Transfer reviewed value, not original model output');
 assert.equal(d.querySelector('.new-row input[type=hidden]').value,'20261023');
 button.click();assert.equal(body.children.length,2,'Repeated clicks must not duplicate rows');
 assert.equal(requests,1,'Prefill must not make save requests');
 d.querySelector('.asb-note').dispatchEvent(new w.Event('input'));assert.equal(d.querySelector('.asb-preview').children.length,0,'Editing input invalidates stale preview');
 console.log('Client checks passed: consent, review, preservation, date, duplicate prevention, no saves, stale draft invalidation.');
}
run().catch(e=>{console.error(e);process.exitCode=1;});
