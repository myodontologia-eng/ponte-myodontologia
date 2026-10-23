<?php
// MYLA ASB: typed note -> editable preview -> explicit per-section prefill.
if (!defined('ABSPATH')) exit;
add_filter('acf/load_fields', function($fields) {
    if (!is_array($fields)) return $fields;
    $out=[];
    foreach($fields as $field){
        $out[]=$field;
        if(($field['key']??'')==='field_myfc_evolucoes'){
            $subs=[];
            foreach(['descricao'=>'Tarefa / retorno','prazo'=>'Prazo informado','situacao'=>'Situação'] as $name=>$label)
                $subs[]=['key'=>'field_myfc_asb_tarefas_'.$name,'name'=>'asb_tarefas_'.$name,'label'=>$label,'type'=>$name==='descricao'?'textarea':'text','rows'=>3];
            $out[]=['key'=>'field_myfc_asb_tarefas','name'=>'asb_tarefas','label'=>'Tarefas e retornos pendentes','type'=>'repeatix_repeater','layout'=>'block','button_label'=>'Adicionar tarefa','sub_fields'=>$subs,'instructions'=>'Registro de pendências. Uma tarefa não reserva horário nem envia notificações. Agende pela agenda integrada após conferir a disponibilidade.'];
        }
    }
    return $out;
});
add_action('wp_ajax_myod_asb_draft',function(){
    check_ajax_referer('myod_asb','nonce');
    $id=absint($_POST['ficha']??0);
    if(!current_user_can('manage_options')||get_post_type($id)!=='ficha_clinica'||!current_user_can('edit_post',$id))wp_send_json_error(['message'=>'Acesso restrito ao profissional.'],403);
    if(($_POST['consent']??'')!=='1')wp_send_json_error(['message'=>'Confirme o envio da anotação ao Gemini.'],422);
    $note=sanitize_textarea_field(wp_unslash($_POST['note']??''));
    $date=sanitize_text_field(wp_unslash($_POST['reference_date']??''));
    if(mb_strlen($note)<3||mb_strlen($note)>8000||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))wp_send_json_error(['message'=>'Confira a anotação e a data do atendimento.'],422);
    $lock='myod_asb_lock_'.get_current_user_id();
    $old=(int)get_option($lock,0);if($old&&$old<time()-100)delete_option($lock);
    if(!add_option($lock,time(),'','no'))wp_send_json_error(['message'=>'Aguarde a geração que já está em andamento.'],429);
    try{$r=myod_myla_bridge('POST','clinical/draft',['note'=>$note,'reference_date'=>$date,'consent'=>true]);}
    finally{delete_option($lock);}
    if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()],502);
    if(empty($r['draft'])||!is_array($r['draft'])||($r['saved']??true)!==false)wp_send_json_error(['message'=>'Resposta inválida. Nenhum campo foi preenchido.'],502);
    nocache_headers();wp_send_json_success($r);
});
add_action('acf/render_field/key=field_myfc_evolucoes',function(){
    static $done=false;$id=myod_clinical_id();
    if($done||!$id||!current_user_can('manage_options'))return;$done=true;
    $cfg=['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('myod_asb'),'ficha'=>$id,'today'=>wp_date('Y-m-d',null,new DateTimeZone('America/Sao_Paulo'))];
    echo '<section id="myod-asb" style="background:#f5f6f7;border:1px solid #c8cdd0;border-radius:12px;padding:20px;margin:18px 0"><h3 style="margin-top:0">MYLA ASB — Assistente do atendimento</h3><p>Descreva o atendimento. Revise o rascunho e preencha cada seção desejada. Os registros anteriores são preservados.</p><label>Data do atendimento <input type="date" class="asb-date" value="'.esc_attr($cfg['today']).'"></label><label style="display:block;margin-top:12px">Anotação do atendimento<textarea class="asb-note" rows="5" maxlength="8000" style="width:100%;margin-top:6px" placeholder="Ex.: retorno do implante 36, sem dor, cicatrização satisfatória; solicitei radiografia e retorno em 15 dias..."></textarea></label><p><label><input type="checkbox" class="asb-consent"> Autorizo enviar somente esta anotação e a data ao Google Gemini, pela ponte do sistema, para preparar o rascunho. Evitarei nome, CPF, telefone e outros identificadores.</label></p><button type="button" class="button button-primary asb-generate">Gerar rascunho para revisão</button> <button type="button" class="button asb-discard">Limpar rascunho</button><p class="asb-status" role="status" aria-live="polite"></p><div class="asb-preview"></div><p><strong>Preencher não salva a ficha.</strong> Confira os campos e use o botão de salvar da ficha. Retornos ficam como tarefas pendentes até você agendar.</p><a href="'.esc_url(admin_url('admin.php?page=myod-calendar')).'" target="_blank" rel="noopener">Abrir agenda integrada para confirmar um horário</a></section>';
    echo '<script>(function(cfg){';
    echo <<<'JS'
const root=document.getElementById('myod-asb');if(!root||root.dataset.ready)return;root.dataset.ready='1';
const q=s=>root.querySelector(s),status=q('.asb-status'),preview=q('.asb-preview'),note=q('.asb-note'),date=q('.asb-date'),generate=q('.asb-generate');
let revision=0,busy=false,dirty=false;
const labels={subjetivo:'S — Relato do paciente',objetivo:'O — Achados observados',avaliacao:'A — Avaliação informada',plano:'P — Conduta',alergias:'Alergias informadas',dente:'Dente / região',procedimento:'Procedimento',evolucao:'Evolução / intercorrências',orientacoes:'Orientações',tecnica:'Técnica / materiais informados',retorno:'Plano / retorno',observacoes:'Observações',descricao:'Descrição',valor:'Valor (R$)',forma:'Forma de pagamento',situacao:'Situação',prazo:'Prazo informado'};
const schemas={soap:['subjetivo','objetivo','avaliacao','plano','alergias'],evolucao:['dente','procedimento','evolucao','orientacoes','tecnica','retorno'],planejamento:['dente','procedimento','observacoes'],pagamentos:['descricao','valor','forma','situacao','observacoes'],tarefas:['descricao','prazo']};
const groups={soap:['SOAP','anamnese_soap'],evolucao:['Evolução clínica','evolucoes'],planejamento:['Plano de tratamento','planejamento'],pagamentos:['Financeiro','pagamentos'],tarefas:['Tarefas de agenda','asb_tarefas']};
function invalidate(){revision++;preview.replaceChildren();status.textContent='Anotação alterada. Gere um novo rascunho para continuar.';}
note.addEventListener('input',()=>{dirty=!!note.value;invalidate();});date.addEventListener('change',invalidate);
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
root.closest('form')?.addEventListener('submit',()=>{dirty=false;});
q('.asb-discard').addEventListener('click',()=>{revision++;preview.replaceChildren();note.value='';q('.asb-consent').checked=false;dirty=false;status.textContent='Rascunho descartado. Campos que você já preencheu na ficha continuam disponíveis para revisão.';});
function appendRow(group,values){
 const field=document.querySelector('[data-key="field_myfc_'+group+'"]');
 const add=field?.querySelector('.rptx-add-row'),body=field?.querySelector('.rptx-tbody');
 if(!add||!body)throw Error('Seção indisponível nesta ficha. Nenhum campo anterior foi alterado.');
 const before=new Set(body.children);add.click();
 const row=Array.from(body.children).find(e=>!before.has(e));
 if(!row)throw Error('Não foi possível abrir um novo registro. Confira a seção na ficha.');
 const targets=Object.entries(values).map(([key,value])=>{
  const f=row.querySelector('[data-key="field_myfc_'+group+'_'+key+'"]');
  const input=f?.querySelector('textarea,select,input[name]');
  if(!input)throw Error('Campo '+key+' indisponível. Revise o novo registro antes de salvar.');
  return {f,input,value};
 });
 targets.forEach(({f,input,value})=>{
  if(f.dataset.type==='date_picker'){
   const parts=value.split('-');input.value=parts.join('');const visible=f.querySelector('input[type=text]');if(visible)visible.value=parts.reverse().join('/');
  }else input.value=value;
  input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));
 });
 dirty=true;
}
function render(draft){
 preview.replaceChildren();
 for(const [group,[title,target]] of Object.entries(groups)){
  const items=Array.isArray(draft[group])?draft[group]:[draft[group]];
  items.filter(v=>v&&typeof v==='object'&&Object.values(v).some(Boolean)).forEach((item,index)=>{
   const box=document.createElement('section');box.style.cssText='background:white;border:1px solid #ccd1d4;border-radius:8px;padding:16px;margin-top:14px';
   const heading=document.createElement('h4');heading.textContent=title+(items.length>1?' '+(index+1):'');box.append(heading);
   const controls={};
   for(const key of schemas[group]){
    const label=document.createElement('label');label.style.cssText='display:block;margin:10px 0';label.textContent=labels[key];
    const input=document.createElement(key==='situacao'&&group==='pagamentos'?'select':key==='valor'?'input':'textarea');
    if(input.tagName==='SELECT')for(const state of ['A confirmar','Pendente','Pago']){const option=document.createElement('option');option.value=state;option.textContent=state;input.append(option);}
    if(key==='valor'){input.type='number';input.min='0';input.step='0.01';}else input.rows=2;
    input.value=typeof item[key]==='string'?item[key]:'';input.style.cssText='display:block;width:100%;margin-top:5px';controls[key]=input;label.append(input);box.append(label);
   }
   const button=document.createElement('button');button.type='button';button.className='button';button.textContent='Revisei: preencher '+title.toLowerCase();
   button.addEventListener('click',()=>{
    try{
     const values=Object.fromEntries(Object.entries(controls).map(([key,input])=>[key,input.value.trim()]));
     if(group==='pagamentos'&&(!values.valor||!/^\d{1,8}(\.\d{1,2})?$/.test(values.valor)))throw Error('Confira o valor do pagamento antes de preencher.');
     if(!Object.values(values).some(Boolean))throw Error('Preencha o conteúdo que deseja transferir.');
     if(group!=='tarefas')values.data=date.value;else values.situacao='Pendente — não agendado';
     appendRow(target,values);button.disabled=true;button.textContent='Preenchido na ficha — falta salvar';Object.values(controls).forEach(input=>input.disabled=true);
     status.textContent=title+' preenchido em um novo registro. Revise na seção correspondente e salve a ficha quando concluir.';
    }catch(error){status.textContent=error.message;}
   });box.append(button);preview.append(box);
  });
 }
 if(Array.isArray(draft.pendencias)&&draft.pendencias.length){const p=document.createElement('p');p.textContent='Confira também: '+draft.pendencias.filter(v=>typeof v==='string').join(' • ');preview.prepend(p);}
}
generate.addEventListener('click',async()=>{
 if(busy)return;if(!note.value.trim()||!date.value){status.textContent='Informe a data e descreva o atendimento.';return;}
 if(!q('.asb-consent').checked){status.textContent='Confirme o envio da anotação ao Gemini.';return;}
 const version=++revision;busy=true;generate.disabled=true;preview.replaceChildren();status.textContent='Preparando o rascunho… Nenhuma alteração foi salva.';
 const body=new URLSearchParams({action:'myod_asb_draft',nonce:cfg.nonce,ficha:String(cfg.ficha),note:note.value,reference_date:date.value,consent:'1'});
 try{const response=await fetch(cfg.ajax,{method:'POST',credentials:'same-origin',body});const data=await response.json();if(version!==revision)return;if(!response.ok||!data.success)throw Error(data.data?.message||'Não foi possível gerar o rascunho.');render(data.data.draft);q('.asb-consent').checked=false;status.textContent='Rascunho pronto. Revise e preencha somente as seções desejadas. Nada foi salvo.';}
 catch(error){if(version===revision)status.textContent=error.message||'Falha na conexão. Tente novamente.';}
 finally{busy=false;generate.disabled=false;}
});
JS;
    echo '})('.wp_json_encode($cfg).');</script>';
});
