<?php
/**
 * Plugin Name: MY Odontologia Core
 * Description: Agenda integrada com Google Calendar, acolhimento e registros clínicos.
 * Version: 0.3.0
 * Requires PHP: 8.0
 * Text Domain: my-odontologia-core
 */
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function () {
    add_menu_page('MY Odontologia', 'MY Odontologia', 'manage_options', 'my-odontologia', 'myod_render_dashboard', 'dashicons-heart', 3);
});
function myod_render_dashboard() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Acesso não autorizado.', 'my-odontologia-core'));
    $sections = [
        ['Agenda', 'Integração com os agendamentos existentes será configurada após auditoria.'],
        ['Pacientes', 'Cadastro e campos atuais preservados; aguardando mapeamento.'],
        ['Prontuários', 'Sem alteração ou publicação automática de registros clínicos.'],
        ['Financeiro', 'Módulo previsto para etapa posterior.'],
    ];
    echo '<div class="wrap"><h1>MY Odontologia — Painel clínico</h1><p>Versão inicial de interface. Nenhum cadastro foi modificado.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1100px">';
    foreach ($sections as [$title, $description]) {
        echo '<section style="background:#fff;border:1px solid #dbe7e9;border-radius:12px;padding:20px"><h2>'.esc_html($title).'</h2><p>'.esc_html($description).'</p></section>';
    }
    echo '</div><p><strong>Próximo passo:</strong> mapear CPTs, ACF, WPForms e regras de acesso antes de conectar dados reais.</p></div>';
}

// MY clinical extension v0.2: structured records and private examinations.
function myod_clinical_id() {
    $id=absint($_GET['myfc_ficha']??$_GET['post']??0);
    return $id && get_post_type($id)==='ficha_clinica' && current_user_can('edit_post',$id) ? $id : 0;
}
function myod_extra_field($name,$label,$type='text',$extra=[]) {
    return array_merge(['key'=>'field_myfc_'.$name,'name'=>$name,'label'=>$label,'type'=>$type],$extra);
}
add_filter('acf/load_field/key=field_myfc_evolucoes',function($f){
    $f['instructions']='Uma linha por atendimento ou complemento. Preserve os registros anteriores. Salve a ficha antes de gerar o relatório.';
    $new=[
        myod_extra_field('evolucoes_horario','Horário','time_picker',['display_format'=>'H:i','return_format'=>'H:i']),
        myod_extra_field('evolucoes_tipo','Tipo de registro','select',['choices'=>['clinica'=>'Evolução clínica','tecnica'=>'Registro técnico','complemento'=>'Complemento de registro anterior','administrativo'=>'Ocorrência de agendamento'],'default_value'=>'clinica']),
        myod_extra_field('evolucoes_abrangencia','Abrangência','select',['choices'=>['geral'=>'Geral','dente'=>'Dente(s)','arcada'=>'Arcada','sextante'=>'Sextante'],'default_value'=>'geral']),
        myod_extra_field('evolucoes_faces','Faces / localização'),
        myod_extra_field('evolucoes_agendamento','ID do evento / agendamento'),
        myod_extra_field('evolucoes_situacao','Situação do atendimento','select',['choices'=>[''=>'Não informado','atendido'=>'Atendido','agendado'=>'Agendado','faltou'=>'Faltou','remarcado'=>'Remarcado','desmarcou'=>'Desmarcou','atendimento_atrasado'=>'Atendimento atrasado'],'allow_null'=>1]),
        myod_extra_field('evolucoes_tecnica','Descrição técnica — anestesia, materiais e técnica empregada','textarea',['rows'=>4]),
        myod_extra_field('evolucoes_retorno','Plano / retorno','textarea',['rows'=>3]),
        myod_extra_field('evolucoes_referencia','Referência do registro complementado (data / horário)')
    ];
    $keys=array_column($f['sub_fields']??[],'key');foreach($new as $n)if(!in_array($n['key'],$keys,true))$f['sub_fields'][]=$n;
    return $f;
});
add_filter('acf/load_field/key=field_myfc_exames',function($f){
    $f['label']='Descrição e interpretação dos exames';
    $keys=array_column($f['sub_fields']??[],'key');
    foreach([myod_extra_field('exames_dente','Dente / região'),myod_extra_field('exames_arquivo_drive','Link do exame no Google Drive (opcional)','url',['instructions'=>'Use arquivo restrito da clínica. Não habilite acesso público.'])] as $n)if(!in_array($n['key'],$keys,true))$f['sub_fields'][]=$n;
    return $f;
});
// Files are stored in a sibling of the WordPress web root, never in uploads.
function myod_private_dir(){
    $root=realpath(ABSPATH);if(!$root) return new WP_Error('storage','Raiz do sistema indisponível.');
    $dir=dirname($root).DIRECTORY_SEPARATOR.'my-clinical-private';
    if(!is_dir($dir)&&!wp_mkdir_p($dir)) return new WP_Error('storage','Não foi possível criar o armazenamento privado.');
    $real=realpath($dir);$doc=realpath($_SERVER['DOCUMENT_ROOT']??ABSPATH);
    foreach(array_filter([$root,$doc]) as $public)if($real===$public||strpos($real,$public.DIRECTORY_SEPARATOR)===0)return new WP_Error('storage','Armazenamento recusado: pasta dentro da raiz pública.');
    if(!$real||!is_writable($real))return new WP_Error('storage','Armazenamento privado indisponível.');
    chmod($real,0700);return $real;
}
function myod_exam_url($id,$file){return wp_nonce_url(add_query_arg(['action'=>'myod_exam_get','ficha'=>$id,'arquivo'=>$file],admin_url('admin-post.php')),'myod_exam_get_'.$id.'_'.$file);}
add_filter('acf/load_field/key=field_myfc_exames_aviso',function($f){
    $id=myod_clinical_id();
    $html='<p>Anexe imagens e PDFs diretamente à ficha. Os arquivos ficam fora da pasta pública do site e sua abertura exige login e permissão para editar esta ficha.</p><p>Formatos: JPG, PNG, WebP e PDF. Até 10 arquivos por envio; limite de 10 MB por arquivo, respeitando o limite do servidor. Tomografias DICOM podem ser vinculadas pelo Drive restrito.</p>';
    if(!$id){$f['message']=$html.'<p><strong>Salve primeiro a ficha para habilitar os anexos.</strong></p>';return $f;}
    $dir=myod_private_dir();
    if(is_wp_error($dir)){$f['message']=$html.'<p>'.esc_html($dir->get_error_message()).'</p>';return $f;}
    $url=wp_nonce_url(add_query_arg(['action'=>'myod_exam_upload_form','ficha'=>$id],admin_url('admin-post.php')),'myod_exam_upload_form_'.$id);
    $html.='<p><a class="button my-btn" href="'.esc_url($url).'" target="_blank" rel="noopener">Anexar imagens / PDF</a></p><h4>Anexos desta ficha</h4>';
    $files=get_post_meta($id,'_myod_private_exam');
    if(!$files)$html.='<p>Nenhum arquivo anexado.</p>';
    foreach($files as $file){if(!is_array($file)||empty($file['id']))continue;
        $html.='<div style="border:1px solid #dbe7e9;padding:12px;margin:8px 0"><strong>'.esc_html($file['name']).'</strong><br>'.esc_html(($file['date']??'').' · '.($file['type']??'').' · '.($file['region']??'')).'<br>'.esc_html($file['description']??'').'<br><small>Registrado em '.esc_html($file['created']??'').' por '.esc_html($file['author_name']??'').'</small><br><a class="button" href="'.esc_url(myod_exam_url($id,$file['id'])).'" target="_blank" rel="noopener">Abrir / baixar anexo</a></div>';
    }
    $f['message']=$html.'<p>Após anexar, recarregue a ficha para atualizar a lista. Salve alterações pendentes antes de recarregar.</p>';return $f;
});
function myod_exam_access($action){
    $id=absint($_REQUEST['ficha']??0);
    if(!$id||get_post_type($id)!=='ficha_clinica'||!current_user_can('edit_post',$id))wp_die('Acesso não autorizado.','Acesso',['response'=>403]);
    check_admin_referer($action.'_'.$id);nocache_headers();header('X-Robots-Tag: noindex, nofollow');return $id;
}
add_action('admin_post_myod_exam_upload_form',function(){
    $id=myod_exam_access('myod_exam_upload_form');$dir=myod_private_dir();if(is_wp_error($dir))wp_die(esc_html($dir->get_error_message()));
    header('Content-Type: text/html; charset=UTF-8');header('X-Frame-Options: SAMEORIGIN');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Anexar exame</title><style>body{font:16px system-ui;max-width:720px;margin:32px auto;padding:20px;color:#18343b}label{display:block;margin:18px 0 6px}input,textarea,button{font:inherit;padding:10px;box-sizing:border-box;max-width:100%}input:not([type=file]),textarea{width:100%}button{background:#115c66;color:white;border:0;border-radius:8px;margin-top:20px}small{display:block}</style><h1>Anexar exames</h1><p>Ficha #'.(int)$id.' — '.esc_html(get_the_title($id)).'</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="myod_exam_upload"><input type="hidden" name="ficha" value="'.(int)$id.'">';
    wp_nonce_field('myod_exam_upload_'.$id);
    echo '<label for="date">Data do exame</label><input id="date" type="date" name="exam_date" required value="'.esc_attr(current_time('Y-m-d')).'"><label for="type">Tipo de exame</label><input id="type" name="exam_type" required maxlength="150" placeholder="Ex.: radiografia periapical"><label for="region">Dente / região</label><input id="region" name="exam_region" maxlength="150"><label for="description">Descrição / identificação</label><textarea id="description" name="exam_description" rows="3" maxlength="3000"></textarea><label for="files">Imagens / PDF</label><input id="files" type="file" name="exam_files[]" multiple required accept="image/jpeg,image/png,image/webp,application/pdf"><small>Até 10 arquivos, 10 MB cada. Os originais serão preservados.</small><button type="submit">Salvar anexos nesta ficha</button></form></html>';exit;
});
add_action('admin_post_myod_exam_upload',function(){
    $id=myod_exam_access('myod_exam_upload');$dir=myod_private_dir();if(is_wp_error($dir))wp_die(esc_html($dir->get_error_message()));
    $files=$_FILES['exam_files']??[];$count=count((array)($files['name']??[]));
    if(!$count||$count>10)wp_die('Selecione de 1 a 10 arquivos.');
    $date=sanitize_text_field(wp_unslash($_POST['exam_date']??''));$d=DateTime::createFromFormat('!Y-m-d',$date);
    $type=sanitize_text_field(wp_unslash($_POST['exam_type']??''));if(!$d||$d->format('Y-m-d')!==$date||!$type)wp_die('Informe data válida e tipo do exame.');
    $allowed=['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];$batch=[];
    for($i=0;$i<$count;$i++){
        $tmp=$files['tmp_name'][$i]??'';$name=sanitize_file_name($files['name'][$i]??'');
        if(($files['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)||filesize($tmp)>min(10*MB_IN_BYTES,wp_max_upload_size()))wp_die('Envio recusado. Confira o tamanho e tente novamente; nenhum arquivo foi salvo.');
        $check=wp_check_filetype_and_ext($tmp,$name,$allowed);$actual=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if(empty($check['ext'])||empty($check['type'])||!in_array($actual,array_values($allowed),true)||$actual!==$check['type'])wp_die('Formato inválido. Use JPG, PNG, WebP ou PDF; nenhum arquivo foi salvo.');
        $batch[]=['tmp'=>$tmp,'name'=>$name,'mime'=>$actual,'ext'=>$check['ext']];
    }
    $stored=[];foreach($batch as $f){$token=bin2hex(random_bytes(24));$path=$dir.DIRECTORY_SEPARATOR.$token.'.'.$f['ext'];
        if(!move_uploaded_file($f['tmp'],$path)){foreach($stored as $s)@unlink($s['path']);wp_die('Falha no armazenamento. Nenhum anexo foi registrado.');}
        chmod($path,0600);$stored[]=['path'=>$path,'record'=>['id'=>$token,'disk'=>basename($path),'name'=>$f['name'],'mime'=>$f['mime'],'size'=>filesize($path),'sha256'=>hash_file('sha256',$path),'date'=>$date,'type'=>$type,'region'=>sanitize_text_field(wp_unslash($_POST['exam_region']??'')),'description'=>sanitize_textarea_field(wp_unslash($_POST['exam_description']??'')),'author'=>get_current_user_id(),'author_name'=>wp_get_current_user()->display_name,'created'=>current_time('mysql')]];
    }
    $saved=[];foreach($stored as $s){$meta=add_post_meta($id,'_myod_private_exam',$s['record']);if(!$meta){foreach($saved as $mid)delete_metadata_by_mid('post',$mid);foreach($stored as $p)@unlink($p['path']);wp_die('Não foi possível registrar os anexos. Tente novamente.');}$saved[]=$meta;}
    wp_die('<p>'.count($saved).' anexo(s) salvo(s) na ficha #'.(int)$id.'.</p><p>Feche esta aba e recarregue a ficha depois de salvar alterações pendentes.</p>','Anexos salvos',['response'=>200]);
});
add_action('admin_post_myod_exam_get',function(){
    $id=absint($_GET['ficha']??0);$token=sanitize_key($_GET['arquivo']??'');
    if(!$id||get_post_type($id)!=='ficha_clinica'||!current_user_can('edit_post',$id))wp_die('Acesso negado.','Acesso',['response'=>403]);
    check_admin_referer('myod_exam_get_'.$id.'_'.$token);$record=null;
    foreach(get_post_meta($id,'_myod_private_exam') as $f)if(is_array($f)&&($f['id']??'')===$token){$record=$f;break;}
    if(!$record)wp_die('Arquivo não encontrado.','Arquivo',['response'=>404]);
    $dir=myod_private_dir();if(is_wp_error($dir))wp_die('Armazenamento indisponível.');$path=$dir.DIRECTORY_SEPARATOR.basename($record['disk']);
    if(!is_file($path)||!hash_equals($record['sha256'],hash_file('sha256',$path)))wp_die('Arquivo indisponível ou integridade divergente.');
    nocache_headers();header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: '.$record['mime']);header('Content-Length: '.filesize($path));header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($record['name']));readfile($path);exit;
});
add_filter('acf/load_field/key=field_myfc_relatorios_fluxo',function($f){
    $id=myod_clinical_id();$f['message'].='<p>Gere o relatório consolidado dos registros salvos. Revise o conteúdo e, depois, realize a assinatura no serviço do CFO.</p>';
    if($id){$url=wp_nonce_url(add_query_arg(['action'=>'myod_clinical_report','ficha'=>$id],admin_url('admin-post.php')),'myod_clinical_report_'.$id);$f['message'].='<p><a class="button my-btn" href="'.esc_url($url).'" target="_blank" rel="noopener">Gerar relatório de evolução / imprimir PDF</a></p>';}
    return $f;
});
function myod_row_value($row,$prefix,$name){return $row[$prefix.'_'.$name]??$row['field_myfc_'.$prefix.'_'.$name]??$row[$name]??'';}
add_action('admin_post_myod_clinical_report',function(){
    $id=myod_exam_access('myod_clinical_report');header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: private, no-store');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Relatório de evolução — ficha '.(int)$id.'</title><style>body{font:14px system-ui;max-width:900px;margin:30px auto;padding:20px;color:#18343b}section{border-bottom:1px solid #bbc;padding:12px 0;break-inside:avoid}p{white-space:pre-wrap;line-height:1.5}button{padding:12px} @media print{button,.screen{display:none}body{margin:0;max-width:none}}</style><button onclick="window.print()">Imprimir / salvar PDF</button><h1>MY Odontologia — Relatório de evolução</h1><p>Paciente: '.esc_html(get_the_title($id)).' · Ficha #'.(int)$id.'</p><p>Emitido em '.esc_html(current_time('d/m/Y H:i')).'.</p><p class="screen">Documento para revisão profissional. Não assinado digitalmente. A assinatura no CFO é realizada separadamente. O relatório reúne os registros salvos, inclusive em tratamentos em andamento ou interrompidos.</p>';
    $rows=function_exists('get_field')?get_field('evolucoes',$id):[];
    if(!is_array($rows)||!$rows)echo '<p>Nenhuma evolução clínica registrada.</p>';
    $labels=['data'=>'Data','horario'=>'Horário','tipo'=>'Tipo de registro','abrangencia'=>'Abrangência','dente'=>'Dente / região','faces'=>'Faces / localização','procedimento'=>'Procedimento realizado','evolucao'=>'Evolução / intercorrências','tecnica'=>'Descrição técnica','orientacoes'=>'Orientações','retorno'=>'Plano / retorno','profissional'=>'Profissional / CRO','situacao'=>'Situação','agendamento'=>'ID do evento / agendamento','referencia'=>'Referência do complemento'];
    foreach((array)$rows as $i=>$row){if(!is_array($row))continue;echo '<section><h2>Registro '.((int)$i+1).'</h2>';foreach($labels as $key=>$label){$value=myod_row_value($row,'evolucoes',$key);if(is_scalar($value)&&trim((string)$value)!=='')echo '<p><strong>'.esc_html($label).':</strong> '.esc_html((string)(['clinica'=>'Evolução clínica','tecnica'=>'Registro técnico','complemento'=>'Complemento','administrativo'=>'Ocorrência de agendamento','geral'=>'Geral','dente'=>'Dente(s)','arcada'=>'Arcada','sextante'=>'Sextante','atendido'=>'Atendido','agendado'=>'Agendado','faltou'=>'Faltou','remarcado'=>'Remarcado','desmarcou'=>'Desmarcou','atendimento_atrasado'=>'Atendimento atrasado'][$value]??$value)).'</p>';}echo '</section>';}
    echo '<h2>Histórico de agendamentos e ocorrências</h2>';
    $appointments=get_posts(['post_type'=>'prontuario','post_status'=>['publish','private','draft'],'numberposts'=>-1,'meta_key'=>'_myfc_ficha_id','meta_value'=>$id,'orderby'=>'ID','order'=>'ASC']);
    foreach($appointments as $appointment){
        if(!current_user_can('edit_post',$appointment->ID))continue;
        echo '<section><h3>'.esc_html($appointment->post_title).'</h3><p>Agendamento #'.(int)$appointment->ID.'</p>';
        $status_labels=['agendado'=>'Agendado','aguardando'=>'Aguardando','atendido'=>'Atendido','remarcado'=>'Remarcado','desmarcou'=>'Desmarcou','faltou'=>'Faltou','atendimento_atrasado'=>'Atendimento atrasado'];
        $current=function_exists('myfc_agenda_status')?myfc_agenda_status($appointment->ID):get_post_meta($appointment->ID,'status',true);
        echo '<p>Situação atual: '.esc_html($status_labels[$current]??$current).'</p>';
        foreach(get_post_meta($appointment->ID,'_myfc_presenca_auditoria') as $entry){
            if(!is_array($entry))continue;
            $from=$entry['anterior']??'';$to=$entry['situacao']??'';
            echo '<p>'.esc_html(($entry['data']??'').' — '.($status_labels[$from]??($from?:'Sem registro anterior')).' → '.($status_labels[$to]??$to).' — Usuário #'.(int)($entry['usuario']??0)).'</p>';
        }
        echo '</section>';
    }
    echo '<h2>Anexos de exames</h2>';foreach(get_post_meta($id,'_myod_private_exam') as $f){if(!is_array($f))continue;echo '<p>'.esc_html(($f['date']??'').' — '.($f['type']??'').' — '.($f['region']??'').' — '.($f['name']??'')).'</p>';}
    echo '<p>Responsável pela revisão: ____________________________________<br>CRO: ____________________</p></html>';exit;
});

function myod_evolution_snapshot($id){
    if(!is_numeric($id)||get_post_type($id)!=='ficha_clinica'||!current_user_can('edit_post',$id)||wp_is_post_revision($id)||wp_is_post_autosave($id))return;
    $rows=function_exists('get_field')?get_field('evolucoes',$id,false):[];
    $rows=is_array($rows)?$rows:[];
    $hash=hash('sha256',wp_json_encode($rows));
    if(get_post_meta($id,'_myod_evolution_last_hash',true)===$hash)return;
    if(!$rows&&!get_post_meta($id,'_myod_evolution_last_hash',true))return;
    $previous=(string)get_post_meta($id,'_myod_evolution_last_hash',true);
    $ok=add_post_meta($id,'_myod_evolution_audit',['recorded_utc'=>gmdate('c'),'author'=>get_current_user_id(),'previous_hash'=>$previous,'hash'=>$hash,'rows'=>$rows]);
    if($ok)update_post_meta($id,'_myod_evolution_last_hash',$hash);
}
add_action('acf/save_post','myod_evolution_snapshot',5);
add_action('acf/save_post','myod_evolution_snapshot',40);



// Loaded by MY Odontologia Core. Credentials remain server-side.
if (!defined('ABSPATH')) exit;
function myod_cal_statuses(){return ['agendado'=>'Agendado','atendido'=>'Atendido','faltou'=>'Faltou','atendimento_atrasado'=>'Atendimento atrasado','remarcado'=>'Remarcado','desmarcou'=>'Desmarcou'];}
function myod_cal_options(){return (array)get_option('myod_calendar_bridge',[]);}
function myod_cal_request($method,$path,$body=null,$key=''){
    $o=myod_cal_options();
    if(empty($o['token']))return new WP_Error('config','Configure a conexão com a agenda no painel MY Odontologia.');
    $args=['method'=>$method,'timeout'=>65,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$o['token'],'Accept'=>'application/json']];
    if(empty($o['calendar_id']))return new WP_Error('calendar','Informe a agenda esperada na configuração.');
    $args['headers']['X-Calendar-ID']=$o['calendar_id'];
    if($body!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body);}
    if($key)$args['headers']['Idempotency-Key']=$key;
    $r=wp_remote_request('https://ponte-myodontologia.onrender.com/api/calendar/events'.$path,$args);
    if(is_wp_error($r))return new WP_Error('connection','Não foi possível consultar a ponte. Tente atualizar em alguns instantes.');
    $code=wp_remote_retrieve_response_code($r);$data=json_decode(wp_remote_retrieve_body($r),true);
    if($code<200||$code>=300)return new WP_Error('bridge',is_string($data['detail']??null)?$data['detail']:'A ponte recusou a operação. Confira a conexão e tente novamente.',['status'=>$code]);
    if(!is_array($data))return new WP_Error('response','Resposta inválida da ponte.');
    return $data;
}
add_action('admin_menu',function(){add_submenu_page('my-odontologia','Agenda integrada','Agenda integrada','manage_options','myod-calendar','myod_cal_admin');});
add_action('admin_post_myod_cal_config',function(){
    if(!current_user_can('manage_options'))wp_die('Acesso negado.');check_admin_referer('myod_cal_config');
    $old=myod_cal_options();$token=trim(wp_unslash($_POST['token']??''));
    if($token){if(preg_match('/[\r\n]/',$token)||strlen($token)>1000)wp_die('Token inválido.');$old['token']=$token;}
    $old['calendar_id']=sanitize_text_field(wp_unslash($_POST['calendar_id']??''));
    $old['label']=sanitize_text_field(wp_unslash($_POST['label']??'MY Odontologia — Testes API'));
    update_option('myod_calendar_bridge',$old,false);
    wp_safe_redirect(admin_url('admin.php?page=myod-calendar&saved=1'));exit;
});
function myod_cal_admin(){
    if(!current_user_can('manage_options'))return;$o=myod_cal_options();
    echo '<div class="wrap"><h1>Agenda integrada</h1><p>Conexão direta WordPress ↔ ponte Render ↔ Google Calendar.</p>';
    if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Configuração salva.</p></div>';
    echo '<details><summary>Configuração da conexão</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="myod_cal_config">';wp_nonce_field('myod_cal_config');
    echo '<p><label>Identificação da agenda<br><input name="label" class="regular-text" value="'.esc_attr($o['label']??'MY Odontologia — Testes API').'"></label></p><p><label>ID da agenda esperada<br><input name="calendar_id" required class="large-text" value="'.esc_attr($o['calendar_id']??'9265d36c8424439345bd483ddee2be869fb9e3b2b17f7bf1ee9d52bf81dc1f26@group.calendar.google.com').'"></label></p><p><label>Token interno da ponte<br><input type="password" name="token" autocomplete="new-password" class="regular-text" value="" placeholder="'.esc_attr(empty($o['token'])?'Informe o token':'Configurado — deixe vazio para manter').'"></label></p><p>Credencial Google permanece somente no Render. O token interno é usado no servidor e não é enviado ao navegador.</p><button class="button button-primary">Salvar conexão</button></form></details>';
    echo myod_cal_render().'</div>';
}
function myod_cal_date($value){
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('America/Sao_Paulo'));
    return $d&&$d->format('Y-m-d')===$value?$d:false;
}
function myod_cal_status($event){
    $s=$event['extendedProperties']['private']['status']??'';
    foreach(myod_cal_statuses() as $key=>$label)if($s===$label)return $key;
    if(preg_match('/(?:^|\R)\s*Status:\s*([^\r\n]+)/iu',strip_tags($event['description']??''),$m))foreach(myod_cal_statuses() as $key=>$label)if(mb_strtolower(trim($m[1]))===mb_strtolower($label))return $key;
    return 'agendado';
}
function myod_cal_legacy_event_id($post){
    if(!$post)return '';
    $text=wp_strip_all_tags(str_replace(['<br>','<br/>','<br />'],"\n",$post->post_content));
    return preg_match('/(?:Event ID|Google Event ID|ID do evento)\s*:\s*([a-zA-Z0-9_-]+)/iu',$text,$m)?$m[1]:'';
}
function myod_cal_ensure_event($id){
    if(get_post_meta($id,'_myod_calendar_event',true))return true;
    if(!current_user_can('edit_post',$id))return new WP_Error('access','Acesso negado.');
    $post=get_post($id);$eid=myod_cal_legacy_event_id($post);
    if(!$eid)return false;
    if((myod_cal_options()['calendar_id']??'')!=='myodontologia@gmail.com')return new WP_Error('calendar','Confira a agenda de origem do registro anterior.');
    if(!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/',$post->post_title,$date))return new WP_Error('date','Confira a data do agendamento antigo.');
    $from=myod_cal_date($date[3].'-'.$date[2].'-'.$date[1]);if(!$from)return new WP_Error('date','Data inválida no registro antigo.');
    $r=myod_cal_request('GET','?'.http_build_query(['time_min'=>$from->format(DATE_ATOM),'time_max'=>$from->modify('+1 day')->format(DATE_ATOM)]));
    if(is_wp_error($r))return $r;
    foreach($r['events']??[] as $event)if(($event['id']??'')===$eid){$stored=myod_cal_store($event,'google');return is_wp_error($stored)?$stored:true;}
    return new WP_Error('missing','Evento antigo não localizado na data original. Localize a nova data na agenda antes de alterar o status.');
}
function myod_cal_store($event,$source){
    $o=myod_cal_options();$cid=$event['calendar_id']??'';$eid=$event['id']??'';
    if(!$cid||!$eid||$cid!==($o['calendar_id']??''))return new WP_Error('calendar','A ponte respondeu com outra agenda. Nenhum registro foi importado.');
    $uid=hash('sha256',$cid.'|'.$eid);
    $lock='myod_cal_lock_'.$uid;
    // Release a lock left by a terminated request; atomic comparison avoids deleting a newer lock.
    $locked=(int)get_option($lock,0);
    if($locked&&$locked<time()-120){global $wpdb;$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$lock,(string)$locked));wp_cache_delete($lock,'options');}
    if(!add_option($lock,time(),'','no'))return new WP_Error('busy','Agendamento em sincronização. Aguarde alguns instantes e atualize.');
    try{
    $rows=get_posts(['post_type'=>'prontuario','post_status'=>['private','publish','draft'],'numberposts'=>1,'meta_key'=>'_myod_calendar_uid','meta_value'=>$uid]);
    $id=$rows?(int)$rows[0]->ID:0;
    $adopted=false;
    if(!$id&&$cid==='myodontologia@gmail.com'){
        $matches=[];
        foreach(get_posts(['post_type'=>'prontuario','post_status'=>['private','publish','draft'],'numberposts'=>-1,'s'=>$eid]) as $candidate){
            if(myod_cal_legacy_event_id($candidate)===$eid&&!get_post_meta($candidate->ID,'_myod_calendar_uid',true))$matches[]=$candidate;
        }
        if(count($matches)>1)return new WP_Error('ambiguous','Há mais de um registro antigo para o mesmo evento. Confira o vínculo antes de importar.');
        if(count($matches)===1){
            $id=(int)$matches[0]->ID;
            if(!current_user_can('edit_post',$id))return new WP_Error('access','Sem permissão para este agendamento.');
            if(!add_post_meta($id,'_myod_calendar_legacy_snapshot',['recorded_utc'=>gmdate('c'),'title'=>$matches[0]->post_title,'content'=>$matches[0]->post_content,'status'=>get_post_meta($id,'_myfc_presenca',true),'ficha'=>get_post_meta($id,'_myfc_ficha_id',true)]))return new WP_Error('audit','Falha ao preservar o registro anterior.');
            update_post_meta($id,'_myod_calendar_uid',$uid);$adopted=true;
        }
    }
    if($id&&!current_user_can('edit_post',$id))return new WP_Error('access','Sem permissão para este agendamento.');
    $raw_start=$event['start']['dateTime']??$event['start']['date']??'';if(!$raw_start)return new WP_Error('date','Evento sem horário de início.');
    try{$start=new DateTimeImmutable($raw_start,new DateTimeZone('America/Sao_Paulo'));$start=$start->setTimezone(new DateTimeZone('America/Sao_Paulo'));}catch(Exception $e){return new WP_Error('date','Horário inválido na agenda.');}
    $title=$start->format('d/m/Y H:i').' - '.sanitize_text_field($event['summary']??'Agendamento');
    $previous=$id?get_post_meta($id,'_myod_calendar_event',true):null;
    if(!$id){$id=wp_insert_post(['post_type'=>'prontuario','post_status'=>'private','post_title'=>$title,'post_content'=>sanitize_textarea_field($event['description']??''),'meta_input'=>['_myod_calendar_uid'=>$uid]],true);if(is_wp_error($id))return $id;}
    elseif(!$adopted&&get_the_title($id)!==$title)wp_update_post(['ID'=>$id,'post_title'=>$title]);
    $before=$id?(string)get_post_meta($id,'_myfc_presenca',true):'';$status=myod_cal_status($event);
    if($adopted&&$before&&$before!=='aguardando'&&$before!==$status)update_post_meta($id,'_myod_calendar_pending_status',$before);
    $pending=(string)get_post_meta($id,'_myod_calendar_pending_status',true);
    if($source==='wordpress'||$pending===$status){delete_post_meta($id,'_myod_calendar_pending_status');$pending='';}
    if($pending&&isset(myod_cal_statuses()[$pending]))$status=$pending;
    if(!$previous||($previous['etag']??'')!==($event['etag']??'')){
        if(!add_post_meta($id,'_myod_calendar_audit',['recorded_utc'=>gmdate('c'),'user'=>get_current_user_id(),'source'=>$source,'previous'=>$previous,'event'=>$event]))return new WP_Error('audit','Falha ao registrar histórico. Atualize antes de continuar.');
    }
    update_post_meta($id,'_myod_calendar_event',$event);update_post_meta($id,'_myod_calendar_id',$cid);update_post_meta($id,'google_event_id',$eid);
    update_post_meta($id,'_myfc_presenca',$status);update_post_meta($id,'status',$status);
    if($before!==$status)add_post_meta($id,'_myfc_presenca_auditoria',['anterior'=>$before,'situacao'=>$status,'usuario'=>get_current_user_id(),'data'=>current_time('mysql'),'origem'=>$source,'calendar_id'=>$cid,'event_id'=>$eid]);
    $event['local_id']=$id;$event['local_status']=$status;$event['ficha_id']=(int)get_post_meta($id,'_myfc_ficha_id',true);
    $event['status_conflict']=$pending?'Situação anterior preservada no sistema. Revise e salve para sincronizar com o Google.':'';
    $event['history']=[];
    foreach(array_slice(get_post_meta($id,'_myod_calendar_audit'),-20) as $entry){$e=$entry['event']??[];$event['history'][]=['at'=>$entry['recorded_utc']??'','user'=>(int)($entry['user']??0),'source'=>$entry['source']??'','status'=>myod_cal_status($e),'start'=>$e['start']['dateTime']??$e['start']['date']??'','end'=>$e['end']['dateTime']??$e['end']['date']??''];}
    if($event['ficha_id']&&current_user_can('edit_post',$event['ficha_id']))$event['ficha_url']=add_query_arg('myfc_ficha',$event['ficha_id'],home_url('/')).'#myfc-home';
    return $event;
    }finally{delete_option($lock);}
}
function myod_cal_update($id,$status,$start=null,$end=null,$etag=null){
    if(get_post_type($id)!=='prontuario'||!current_user_can('edit_post',$id))return new WP_Error('access','Acesso negado.');
    $old=get_post_meta($id,'_myod_calendar_event',true);$o=myod_cal_options();
    if(!$old||($old['calendar_id']??'')!==($o['calendar_id']??''))return new WP_Error('calendar','Agendamento pertence a outra agenda.');
    $labels=myod_cal_statuses();if(!isset($labels[$status]))return new WP_Error('status','Situação inválida.');
    if($status==='remarcado'&&(!$start||!$end))return new WP_Error('period','Use a agenda integrada para informar os novos horários da remarcação.');
    $body=['status'=>$labels[$status],'user'=>'wp:'.get_current_user_id(),'etag'=>$etag?:($old['etag']??'')];
    if($start||$end){if(!$start||!$end)return new WP_Error('period','Informe início e término.');$body['start']=$start;$body['end']=$end;}
    $operation=wp_generate_uuid4();
    if(!add_post_meta($id,'_myod_calendar_operations',['id'=>$operation,'state'=>'requested','at'=>gmdate('c'),'user'=>get_current_user_id(),'previous'=>$old,'request'=>$body]))return new WP_Error('audit','Não foi possível registrar a solicitação.');
    $r=myod_cal_request('PATCH','/'.rawurlencode($old['id']),$body);
    add_post_meta($id,'_myod_calendar_operations',['id'=>$operation,'state'=>is_wp_error($r)?'failed':'confirmed','at'=>gmdate('c'),'error'=>is_wp_error($r)?$r->get_error_code():'']);
    return is_wp_error($r)?$r:myod_cal_store($r,'wordpress');
}
// Called before the existing human-confirmed ficha link changes a status.
function myod_calendar_link_status($id,$status){
    $ready=myod_cal_ensure_event($id);if(is_wp_error($ready))return $ready;
    $event=get_post_meta($id,'_myod_calendar_event',true);
    if(!$event||myod_cal_status($event)===$status)return true;
    return myod_cal_update($id,$status);
}
add_action('acf/validate_save_post',function(){
    if(empty($_POST['myfc_agenda'])||!function_exists('myfc_agenda_context'))return;$c=myfc_agenda_context();if(!$c)return;
    if($c['status']==='remarcado'&&get_post_meta($c['id'],'_myod_calendar_event',true)&&myfc_agenda_status($c['id'])!=='remarcado')acf_add_validation_error('','Vincule a ficha com a situação atual. Para remarcar, informe os novos horários na agenda integrada.');
},30);
add_action('acf/save_post',function($ficha){
    if(empty($_POST['myfc_agenda'])||get_post_type($ficha)!=='ficha_clinica'||!function_exists('myfc_agenda_context'))return;
    $c=myfc_agenda_context();if(!$c)return;
    $r=myod_calendar_link_status($c['id'],$c['status']);
    if(is_wp_error($r))wp_die(esc_html('Não foi possível sincronizar o agendamento. '.$r->get_error_message()).'<p>Volte à ficha e tente novamente. Os campos clínicos deste envio não foram salvos.</p>','Agenda não sincronizada',['response'=>409,'back_link'=>true]);
},1);
add_action('wp_ajax_myfc_presenca',function(){
    $id=absint($_POST['id']??0);
    if(!get_post_meta($id,'_myod_calendar_event',true)&&!myod_cal_legacy_event_id(get_post($id)))return;
    check_ajax_referer('myfc_presenca','nonce');
    if(!get_post_meta($id,'_myfc_ficha_id',true))wp_send_json_error(['message'=>'Selecione ou cadastre a ficha primeiro.'],400);
    $ready=myod_cal_ensure_event($id);if(is_wp_error($ready))wp_send_json_error(['message'=>$ready->get_error_message()],400);
    $r=myod_cal_update($id,sanitize_key($_POST['situacao']??''));
    if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()],400);wp_send_json_success();
},0);
add_action('wp_ajax_myod_calendar',function(){
    check_ajax_referer('myod_calendar','nonce');nocache_headers();
    if(!current_user_can('edit_posts'))wp_send_json_error(['message'=>'Acesso negado.'],403);
    $op=sanitize_key($_POST['operation']??'list');
    if($op==='list'){
        $from=myod_cal_date(sanitize_text_field(wp_unslash($_POST['from']??'')));$to=myod_cal_date(sanitize_text_field(wp_unslash($_POST['to']??'')));
        if(!$from||!$to||$to<=$from||$from->diff($to)->days>62)wp_send_json_error(['message'=>'Período inválido.'],400);
        $r=myod_cal_request('GET','?'.http_build_query(['time_min'=>$from->format(DATE_ATOM),'time_max'=>$to->format(DATE_ATOM)]));
        if(!is_wp_error($r)){
            if(($r['calendar_id']??'')!==(myod_cal_options()['calendar_id']??''))wp_send_json_error(['message'=>'A ponte respondeu com outra agenda. Confira a configuração.'],409);
            $events=[];foreach($r['events']??[] as $event){$e=myod_cal_store($event,'google');if(is_wp_error($e)){$r=$e;break;}$events[]=$e;}
            if(!is_wp_error($r))$r=['events'=>$events,'label'=>myod_cal_options()['label']??'Agenda integrada'];
        }
    }elseif($op==='update'){
        $r=myod_cal_update(absint($_POST['id']??0),sanitize_key($_POST['status']??''),sanitize_text_field(wp_unslash($_POST['start']??''))?:null,sanitize_text_field(wp_unslash($_POST['end']??''))?:null,sanitize_text_field(wp_unslash($_POST['etag']??''))?:null);
    }elseif($op==='create'){
        $summary=sanitize_text_field(wp_unslash($_POST['summary']??''));$key=sanitize_text_field(wp_unslash($_POST['request_id']??''));
        if(!$summary||strlen($summary)>300||!preg_match('/^[a-zA-Z0-9-]{16,128}$/',$key))wp_send_json_error(['message'=>'Confira a identificação do agendamento.'],400);
        $body=['summary'=>$summary,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'start'=>sanitize_text_field(wp_unslash($_POST['start']??'')),'end'=>sanitize_text_field(wp_unslash($_POST['end']??'')),'user'=>'wp:'.get_current_user_id()];
        $r=myod_cal_request('POST','',$body,'wp-'.get_current_user_id().'-'.$key);if(!is_wp_error($r))$r=myod_cal_store($r,'wordpress');
    }else $r=new WP_Error('operation','Operação inválida.');
    if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()],400);
    wp_send_json_success($r);
});
add_shortcode('myod_agenda','myod_cal_render');
function myod_cal_render(){
    if(!current_user_can('edit_posts'))return '';
    ob_start();$uid=wp_unique_id('myodcal-');
    ?>
    <section class="myod-calendar" id="<?php echo esc_attr($uid); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('myod_calendar')); ?>">
    <div class="myod-cal-head"><div><small>MY ODONTOLOGIA</small><h3>Agenda integrada</h3><p class="myod-cal-label">Carregando conexão…</p></div><button type="button" data-action="new">Novo agendamento</button></div>
    <div class="myod-cal-toolbar"><button type="button" data-action="prev" aria-label="Período anterior">←</button><button type="button" data-action="today">Hoje</button><button type="button" data-action="next" aria-label="Próximo período">→</button><label>Data <input class="myod-cal-date" type="date"></label><label>Visualização <select class="myod-cal-view"><option value="week">Semana</option><option value="day">Dia</option><option value="month">Mês</option></select></label><button type="button" data-action="refresh">Atualizar</button></div>
    <p class="myod-cal-feedback" role="status" aria-live="polite"></p><div class="myod-cal-grid"></div>
    <dialog class="myod-cal-dialog"><form class="myod-cal-form"><h3 class="myod-cal-form-title">Novo agendamento</h3><p>Horários de Brasília — São Paulo</p><p class="myod-cal-identity" style="font-size:12px;overflow-wrap:anywhere"></p><p class="myod-cal-linked"></p><label>Paciente / identificação<input name="summary" required maxlength="300"></label><div class="myod-cal-patient-fields"><label>Telefone<input name="phone" type="tel" maxlength="40"></label><label>Data de nascimento<input name="birth" type="date"></label><label>Responsável<input name="responsible" maxlength="200"></label><label>Motivo<input name="reason" maxlength="200"></label></div><label>Observações administrativas<textarea name="description" rows="3" maxlength="8000" placeholder="Nome, telefone e responsável, quando necessário"></textarea></label><label>Início<input name="start" type="datetime-local" required></label><label>Término<input name="end" type="datetime-local" required></label><label>Situação<select name="status"><?php foreach(myod_cal_statuses() as $key=>$label)echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>'; ?></select></label><p class="myod-cal-form-feedback" role="status"></p><details class="myod-cal-history"><summary>Histórico deste agendamento</summary><ol></ol></details><p><button type="submit">Salvar agendamento</button> <button type="button" data-action="close">Fechar</button></p></form></dialog>
    </section>
    <style>
    .myod-calendar{padding:24px;background:#fff;border:1px solid #dbe7e9;border-radius:16px;margin:24px 0;color:#18343b}.myod-cal-head,.myod-cal-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between}.myod-cal-head h3{font-size:26px;margin:6px 0}.myod-cal-label{margin:4px 0 18px;color:#53686c}.myod-calendar button{background:#115c66;color:#fff;border:0;border-radius:8px;padding:11px 15px;cursor:pointer;min-height:44px;font:inherit}.myod-calendar button:disabled{opacity:.5;cursor:wait}.myod-calendar input,.myod-calendar select,.myod-calendar textarea{font:inherit;padding:10px;border:1px solid #9ebdc1;border-radius:8px;max-width:100%;box-sizing:border-box;background:#fff;color:#18343b}.myod-cal-toolbar{justify-content:flex-start;margin:18px 0}.myod-cal-toolbar label{display:flex;align-items:center;gap:8px}.myod-cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}.myod-cal-day{border:1px solid #dbe7e9;border-radius:10px;min-height:140px;padding:10px;min-width:0;background:#f9fcfc}.myod-cal-day h4{font-size:14px;margin:0 0 12px;color:#115c66}.myod-cal-event{display:block;width:100%;text-align:left;margin:7px 0;padding:10px!important;background:#e8f4f3!important;color:#18343b!important;overflow-wrap:anywhere;border-left:4px solid #27aaa5!important;font-size:13px!important}.myod-cal-event strong,.myod-cal-event span{display:block;margin:2px 0}.myod-cal-event[data-status=desmarcou],.myod-cal-event[data-status=faltou]{border-left-color:#b53f3f!important}.myod-cal-event[data-status=atendido]{border-left-color:#287443!important}.myod-cal-feedback{min-height:24px}.myod-cal-dialog{max-width:560px;width:calc(100% - 32px);border:1px solid #dbe7e9;border-radius:16px;padding:24px;color:#18343b}.myod-cal-dialog::backdrop{background:#163b4270}.myod-cal-form label{display:block;margin:14px 0}.myod-cal-form input,.myod-cal-form textarea,.myod-cal-form select{display:block;width:100%;margin-top:6px}.myod-cal-grid[data-view=day]{grid-template-columns:1fr}.myod-cal-grid[data-view=month] .myod-cal-day{min-height:110px}.myod-calendar :focus-visible{outline:3px solid #27aaa5;outline-offset:3px}@media(max-width:850px){.myod-cal-grid{grid-template-columns:1fr}.myod-calendar{padding:16px}.myod-cal-day{min-height:80px}.myod-cal-head{align-items:flex-start}.myod-cal-toolbar label{width:100%;justify-content:space-between}}
    </style>
    <script>
    (()=>{
    const root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root)return;
    const $=s=>root.querySelector(s),date=$('.myod-cal-date'),view=$('.myod-cal-view'),grid=$('.myod-cal-grid'),feedback=$('.myod-cal-feedback'),dialog=$('dialog'),form=$('form'),fields=form.elements;
    const labels=<?php echo wp_json_encode(myod_cal_statuses()); ?>,tz='America/Sao_Paulo';let events=[],editing=null,requestId='',busy=false,generation=0,lastStamp='';
    const key=()=>crypto.randomUUID();
    const iso=d=>new Intl.DateTimeFormat('en-CA',{timeZone:tz,year:'numeric',month:'2-digit',day:'2-digit'}).format(d);
    const day=s=>new Date(s+'T12:00:00-03:00');const add=(s,n)=>{const d=day(s);d.setUTCDate(d.getUTCDate()+n);return iso(d);};
    const local=s=>{if(!s)return '';const d=new Date(s);const p=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:tz,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(d).map(p=>[p.type,p.value]));return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;};
    date.value=iso(new Date());
    async function api(operation,data={}){const r=await fetch(root.dataset.ajax,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'myod_calendar',nonce:root.dataset.nonce,operation,...data})});const j=await r.json();if(!r.ok||!j.success)throw new Error(j.data?.message||'Não foi possível concluir a operação.');return j.data;}
    function period(){let from=date.value,to=add(from,1);if(view.value==='week'){from=add(from,-day(from).getUTCDay());to=add(from,7);}if(view.value==='month'){from=from.slice(0,7)+'-01';const d=day(from);d.setUTCMonth(d.getUTCMonth()+1);to=iso(d);}return {from,to};}
    function render(){grid.replaceChildren();grid.dataset.view=view.value;const {from,to}=period();let current=from;while(current<to){const section=document.createElement('section');section.className='myod-cal-day';const heading=document.createElement('h4');heading.textContent=new Intl.DateTimeFormat('pt-BR',{timeZone:tz,weekday:'short',day:'2-digit',month:'2-digit'}).format(day(current));section.append(heading);const matches=events.filter(e=>{const a=e.start?.dateTime||e.start?.date;const b=e.end?.dateTime||e.end?.date;if(!a)return false;if(e.start?.date)return a<=current&&b>current;return new Date(a)<new Date(add(current,1)+'T00:00:00-03:00')&&new Date(b)>new Date(current+'T00:00:00-03:00');});for(const event of matches){const button=document.createElement('button');button.type='button';button.className='myod-cal-event';button.dataset.status=event.local_status;const time=document.createElement('strong');time.textContent=event.start?.dateTime?new Intl.DateTimeFormat('pt-BR',{timeZone:tz,hour:'2-digit',minute:'2-digit'}).format(new Date(event.start.dateTime)):'Dia inteiro';const title=document.createElement('span');title.textContent=event.summary||'Agendamento';const status=document.createElement('span');status.textContent=labels[event.local_status]||'Agendado';button.append(time,title,status);button.addEventListener('click',()=>open(event));section.append(button);}if(!matches.length){const p=document.createElement('small');p.textContent='Sem agendamentos';section.append(p);}grid.append(section);current=add(current,1);}}
    async function load(){const ticket=++generation;feedback.textContent='Consultando agenda…';try{const result=await api('list',period());if(ticket!==generation)return;events=result.events;$('.myod-cal-label').textContent=result.label;render();feedback.textContent='Agenda atualizada. '+events.length+' agendamento(s) no período.';const stamp=events.map(e=>[e.id,e.etag,e.local_status,e.ficha_id].join(':')).join('|');if(stamp!==lastStamp){lastStamp=stamp;const filter=document.getElementById('filtro');if(filter)filter.dispatchEvent(new Event('change'));}}catch(e){if(ticket===generation){grid.replaceChildren();feedback.textContent=e.message;}}}
    function open(event=null){editing=event;requestId=key();form.reset();$('.myod-cal-form-feedback').textContent=event?.status_conflict||'';$('.myod-cal-form-title').textContent=event?'Atualizar agendamento':'Novo agendamento';$('.myod-cal-identity').textContent=event?'Registro #'+event.local_id+' · ID Google: '+event.id:'';$('.myod-cal-linked').replaceChildren();if(event?.ficha_url){const a=document.createElement('a');a.href=event.ficha_url;a.textContent='Ver ficha vinculada';$('.myod-cal-linked').append(a);}const history=$('.myod-cal-history');history.hidden=!event;const ol=history.querySelector('ol');ol.replaceChildren();for(const h of event?.history||[]){const li=document.createElement('li');li.style.cssText='font-size:13px;margin:10px 0';li.textContent=new Intl.DateTimeFormat('pt-BR',{timeZone:tz,dateStyle:'short',timeStyle:'short'}).format(new Date(h.at))+' — '+(labels[h.status]||h.status)+' — '+(h.start?local(h.start).replace('T',' '):'')+' — '+(h.source==='wordpress'?'Sistema':'Google')+' · usuário #'+h.user;ol.append(li);}fields.summary.value=event?.summary||'';fields.description.value=event?.description||'';fields.start.value=event?.start?.dateTime?local(event.start.dateTime):date.value+'T08:00';fields.end.value=event?.end?.dateTime?local(event.end.dateTime):date.value+'T08:30';fields.status.value=event?.local_status||'agendado';fields.status.disabled=!event;$('.myod-cal-patient-fields').hidden=!!event;fields.summary.readOnly=!!event;fields.description.readOnly=!!event;fields.start.disabled=!!event&&!event.start?.dateTime;fields.end.disabled=!!event&&!event.end?.dateTime;dialog.showModal();}
    root.addEventListener('click',e=>{const action=e.target.closest('[data-action]')?.dataset.action;if(!action||busy)return;if(action==='new')open();else if(action==='close')dialog.close();else if(action==='today'){date.value=iso(new Date());load();}else if(action==='refresh')load();else if(action==='prev'||action==='next'){const sign=action==='prev'?-1:1;if(view.value==='month'){const d=day(date.value.slice(0,7)+'-01');d.setUTCMonth(d.getUTCMonth()+sign);date.value=iso(d);}else date.value=add(date.value,sign*(view.value==='week'?7:1));load();}});
    form.addEventListener('submit',async e=>{e.preventDefault();if(busy)return;const msg=$('.myod-cal-form-feedback');let start=fields.start.value,end=fields.end.value;const timed=!editing||!!editing.start?.dateTime;if(timed&&(!start||!end||end<=start)){msg.textContent='Informe término posterior ao início.';return;}const changed=editing&&timed&&(start!==local(editing.start.dateTime)||end!==local(editing.end.dateTime));if(changed&&fields.status.value!=='remarcado'){msg.textContent='Para mudar os horários, selecione Remarcado.';return;}if(editing&&fields.status.value==='remarcado'&&!changed){msg.textContent='Informe os novos horários da remarcação.';return;}busy=true;const submit=form.querySelector('[type=submit]');submit.disabled=true;msg.textContent='Salvando…';try{if(editing){const data={id:editing.local_id,status:fields.status.value,etag:editing.etag||''};if(changed){data.start=start+':00-03:00';data.end=end+':00-03:00';}await api('update',data);}else{await api('create',{summary:(fields.reason.value?fields.reason.value+' - ':'')+fields.summary.value,description:['Nome: '+fields.summary.value,'Telefone: '+fields.phone.value,'Nascimento: '+fields.birth.value,'Responsável: '+fields.responsible.value,'Motivo: '+fields.reason.value,fields.description.value].join('\n'),start:start+':00-03:00',end:end+':00-03:00',request_id:requestId});}dialog.close();await load();}catch(error){msg.textContent=error.message;}finally{busy=false;submit.disabled=false;}});
    date.addEventListener('change',()=>{if(date.value)load();});view.addEventListener('change',load);setInterval(()=>{if(!document.hidden&&!dialog.open&&!busy&&!document.querySelector('.myfc-inline-panel:not([hidden])'))load();},60000);load();
    })();
    </script>
    <?php return ob_get_clean();
}
