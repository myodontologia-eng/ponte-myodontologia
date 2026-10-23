<?php
// One conversation's consented text only; never clinical records or other sessions.
function myod_myla_fluent_collect($id,$text){
    $consent=(array)get_post_meta($id,'_myod_privacy_consent',true);
    if(($consent['version']??'')!=='2026-10-23-conversa-v2')return myod_myla_collect($id,$text);
    $topic=myod_myla_topic($text);
    if(in_array($topic,['alerta','humano'],true))return null;
    $plain=trim(mb_strtolower(remove_accents(trim($text)))," .!?");
    if(in_array($plain,['parar','cancelar solicitacao','recomecar'],true))return myod_myla_collect($id,$text);
    $flow=(array)get_post_meta($id,'_myod_chat_booking',true);$step=(string)($flow['step']??'');
    // A language model must never manufacture consent for a calendar write.
    if($step==='confirm')return myod_myla_collect($id,$text);
    $history=[];
    foreach(array_slice((array)get_post_meta($id,'_myod_messages',true),-8) as $message){
        if(is_array($message)&&in_array($message['role']??'',['user','assistant'],true))$history[]=['role'=>$message['role'],'content'=>mb_substr((string)($message['content']??''),0,2000)];
    }
    $r=myod_myla_bridge('POST','conversation',['message'=>$text,'history'=>$history,'step'=>$step,'consent'=>true]);
    if(is_wp_error($r)||!is_string($r['intent']??null))return myod_myla_collect($id,$text);
    if(in_array($r['intent'],['attention','urgent'],true)){
        update_post_meta($id,'_myod_attention','pending');
        return ['answer'=>$r['intent']==='urgent'?'Esta situação precisa de avaliação presencial imediata. Se houver dificuldade para respirar ou engolir, sangramento intenso ou risco imediato, procure um serviço de emergência. Este chat não garante atendimento em tempo real.':'Vou deixar esta conversa sinalizada para atenção da equipe. Não há resposta humana em tempo real garantida por aqui.','attention'=>true];
    }
    if($r['intent']==='chat'&&is_string($r['answer']??null)&&trim($r['answer'])!=='')return ['answer'=>sanitize_textarea_field($r['answer']),'provider'=>'gemini_conversa'];
    if($r['intent']==='booking'){
        $normalized=sanitize_textarea_field((string)($r['normalized']??''));
        if(!$normalized)$normalized=$text;
        if(!$step&&myod_myla_topic($normalized)!=='agendar')$normalized='Quero agendar uma consulta. '.$normalized;
        return myod_myla_collect($id,$normalized);
    }
    return myod_myla_collect($id,$text);
}
