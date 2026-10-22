<?php
if (!defined('ABSPATH')) exit;
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form','comment-form','comment-list','gallery','caption','style','script']);
    register_nav_menus(['primary' => __('Menu principal', 'my-odontologia')]);
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('my-odontologia-style', get_stylesheet_uri(), [], '0.1.3');
});

// MY: ficha clínica independente dos agendamentos.
add_action('acf/init', function () {
    if (!function_exists('acf_add_local_field_group')) return;
    $field = function ($name, $label, $type = 'text', $extra = []) {
        return array_merge(['key'=>'field_myfc_'.$name,'name'=>$name,'label'=>$label,'type'=>$type], $extra);
    };
    $tab = function ($name, $label) use ($field) { return $field('tab_'.$name, $label, 'tab', ['placement'=>'top']); };
    $repeat = function ($name, $label, $spec, $button_label = 'Adicionar registro') use ($field) {
        $children = [];
        foreach ($spec as $s) $children[] = $field($name.'_'.$s[0], $s[1], isset($s[2]) ? $s[2] : 'text', isset($s[3]) ? $s[3] : []);
        return $field($name, $label, 'repeatix_repeater', ['layout'=>'block','button_label'=>$button_label,'min'=>0,'max'=>0,'sub_fields'=>$children]);
    };
    acf_add_local_field_group([
        'key'=>'group_my_ficha_clinica_v1','title'=>'Ficha Clínica MY','active'=>true,'show_in_rest'=>0,
        'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'ficha_clinica']]],
        'fields'=>[
            $tab('dados','Dados Pessoais'),
            $field('nome_completo','Nome Completo','text',['required'=>1]),
            $field('data_nascimento','Data de nascimento','date_picker',['display_format'=>'d/m/Y','return_format'=>'Y-m-d']),
            $field('cpf','CPF'), $field('telefone','Telefone'), $field('responsavel','Nome do responsável'),
            $field('telefone_responsavel','Telefone do responsável'),

            $field('cep','CEP','text',['maxlength'=>9,'placeholder'=>'00000-000','instructions'=>'Consulte o CEP para preencher rua, bairro, cidade e UF. A consulta envia somente o CEP ao ViaCEP.']),
            $field('logradouro','Rua / Avenida'),
            $field('numero','Número'),
            $field('complemento','Complemento'),
            $field('bairro','Bairro'),
            $field('cidade','Cidade'),
            $field('uf','UF','text',['maxlength'=>2,'placeholder'=>'PR']),
            $tab('soap','Anamnese / SOAP'),
            $repeat('anamnese_soap','Atendimentos e anamnese',[
                ['data','Data','date_picker'],['profissional','Profissional / CRO'],
                ['subjetivo','Subjetivo — queixa e relato','textarea'],['objetivo','Objetivo — exame clínico','textarea'],
                ['avaliacao','Avaliação / hipótese diagnóstica','textarea'],['plano','Plano / conduta','textarea'],
                ['alergias','Alergias e reações','textarea'],['medicamentos','Medicamentos em uso','textarea'],
                ['condicoes','Doenças e condições de saúde','textarea'],['antecedentes','Antecedentes e cirurgias','textarea'],
                ['habitos','Hábitos / observações','textarea']]),
            $tab('evolucoes','Evoluções'),
            $repeat('evolucoes','Evoluções clínicas',[
                ['data','Data','date_picker'],['dente','Dente / região'],['procedimento','Procedimento realizado','textarea'],
                ['evolucao','Evolução / intercorrências','textarea'],['orientacoes','Orientações','textarea'],['profissional','Profissional / CRO']]),
            $tab('planejamento','Planejamento'),
            $repeat('planejamento','Planejamento do tratamento',[
                ['data','Data','date_picker'],['dente','Dente / região'],['procedimento','Procedimento proposto','textarea'],
                ['prioridade','Prioridade'],['status','Situação'],['valor','Valor previsto (R$)','number'],['observacoes','Observações','textarea']]),
            $tab('pagamentos','Pagamentos'),
            $repeat('pagamentos','Registros de pagamentos',[
                ['data','Data','date_picker'],['descricao','Descrição'],['valor','Valor (R$)','number'],
                ['forma','Forma de pagamento'],['situacao','Situação'],['observacoes','Observações','textarea']]),
            $tab('exames','Exames'),
            $field('exames_aviso','Arquivos de exames','message',['message'=>'Registre abaixo o exame e sua localização no armazenamento restrito da clínica. Não envie documentos clínicos à biblioteca pública de mídia.']),
            $repeat('exames','Histórico de exames',[
                ['data','Data','date_picker'],['tipo','Tipo de exame'],['descricao','Descrição / resultado','textarea'],
                ['referencia','Referência do arquivo no armazenamento restrito'],['profissional','Profissional responsável']]),

            $tab('receitas','Receitas'),
            $field('receitas_fluxo','Receitas emitidas no CFO','message',['message'=>'<p>Emita e assine a receita no CFO. Guarde o PDF original no Google Drive e vincule o arquivo abaixo.</p><p><a class="button my-btn" href="https://prescricao.cfo.org.br/prescricao-emitir?tipo_presc=5" target="_blank" rel="noopener noreferrer">Emitir receita no CFO</a> <button type="button" class="button myfc-copy-patient">Copiar nome e CPF</button></p><p>O documento abre no Drive com as permissões já definidas para o arquivo.</p>']),
            $repeat('receitas','Receitas do paciente',[
                ['data','Data de emissão','date_picker'],['descricao','Descrição / identificação da receita'],
                ['arquivo_drive','Link do PDF no Google Drive','url',['required'=>1]],
                ['codigo_cfo','Código de validação CFO (opcional)'],['observacoes','Observações','textarea']],'Vincular PDF do Drive'),
            $tab('atestados','Atestados'),
            $field('atestados_fluxo','Atestados emitidos no CFO','message',['message'=>'<p>Emita e assine o atestado no CFO. Guarde o PDF original no Google Drive e vincule o arquivo abaixo.</p><p><a class="button my-btn" href="https://prescricao.cfo.org.br/prescricao-emitir?tipo_presc=4" target="_blank" rel="noopener noreferrer">Emitir atestado no CFO</a> <button type="button" class="button myfc-copy-patient">Copiar nome e CPF</button></p>']),
            $repeat('atestados','Atestados do paciente',[
                ['data','Data de emissão','date_picker'],['descricao','Descrição do documento'],
                ['arquivo_drive','Link do PDF no Google Drive','url',['required'=>1]],
                ['codigo_cfo','Código de validação CFO (opcional)'],['observacoes','Observações','textarea']],'Vincular PDF do Drive'),
            $tab('relatorios','Relatórios'),
            $field('relatorios_fluxo','Relatórios e conclusão do tratamento','message',['message'=>'<p>Emita e assine o relatório clínico no CFO, inclusive o relatório de conclusão do tratamento. Guarde o PDF original no Google Drive e vincule o arquivo abaixo.</p><p><a class="button my-btn" href="https://prescricao.cfo.org.br/prescricao-emitir?tipo_presc=1" target="_blank" rel="noopener noreferrer">Emitir relatório no CFO</a> <button type="button" class="button myfc-copy-patient">Copiar nome e CPF</button></p>']),
            $repeat('relatorios','Relatórios do paciente',[
                ['data','Data de emissão','date_picker'],['tipo','Tipo de relatório','select',['choices'=>['clinico'=>'Relatório clínico','conclusao'=>'Conclusão do tratamento','outro'=>'Outro relatório']]],
                ['descricao','Descrição do documento'],['arquivo_drive','Link do PDF no Google Drive','url',['required'=>1]],
                ['codigo_cfo','Código de validação CFO (opcional)'],['observacoes','Observações','textarea']],'Vincular PDF do Drive'),
            $tab('agendamentos','Histórico de Agendamentos'),
            $field('agendamentos_aviso','Origem dos registros','message',['message'=>'Agendamentos vinculados pelo Acolhimento e registros manuais da ficha.']),
            $repeat('historico_agendamentos','Histórico de agendamentos',[
                ['data','Data','date_picker'],['horario','Horário'],['situacao','Situação'],
                ['referencia','ID do agendamento / evento'],['observacoes','Observações','textarea']])
        ]
    ]);
});
add_action('acf/save_post', function ($post_id) {
    if (!is_numeric($post_id) || get_post_type($post_id) !== 'ficha_clinica') return;
    if (!current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    $nome = sanitize_text_field((string)get_post_meta($post_id, 'nome_completo', true));
    if ($nome !== '' && get_the_title($post_id) !== $nome) wp_update_post(['ID'=>(int)$post_id,'post_title'=>$nome]);
}, 20);
add_action('admin_head', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'ficha_clinica') return;
    echo '<style>.post-type-ficha_clinica .rptx-block-field > .acf-label{display:none}body.post-type-ficha_clinica #wpcontent{background:#f2f6f7;color:#18343b}.post-type-ficha_clinica .wrap h1{color:#18343b}.post-type-ficha_clinica .postbox,.post-type-ficha_clinica .acf-fields,.post-type-ficha_clinica .rptx-table,.post-type-ficha_clinica .rptx-block-wrap{background:#ffffff;color:#18343b;border-color:#dbe7e9}.post-type-ficha_clinica .postbox h2,.post-type-ficha_clinica .acf-label label{color:#18343b}.post-type-ficha_clinica .acf-tab-wrap,.post-type-ficha_clinica .acf-tab-group li a{background:#eaf6f5;color:#115c66}.post-type-ficha_clinica .acf-tab-group li.active a{background:#ffffff;color:#18343b}.post-type-ficha_clinica .acf-input input,.post-type-ficha_clinica .acf-input textarea,.post-type-ficha_clinica .acf-input select{background:#ffffff;color:#18343b;border-color:#9ebdc1;min-height:44px;font-size:16px}.post-type-ficha_clinica .button-primary{background:#115c66;border-color:#115c66;color:#ffffff;min-height:44px}.post-type-ficha_clinica .acf-field{border-color:#dbe7e9}.post-type-ficha_clinica .acf-tab-group{display:flex;flex-wrap:wrap}.post-type-ficha_clinica .acf-tab-group li a{min-height:44px;box-sizing:border-box}.post-type-ficha_clinica .rptx-block-fields{display:block}.post-type-ficha_clinica .rptx-block-field{width:100%;box-sizing:border-box}.post-type-ficha_clinica .acf-input input:focus,.post-type-ficha_clinica .acf-input textarea:focus{outline:2px solid #115c66}@media(max-width:782px){.post-type-ficha_clinica .acf-field{width:100%!important;float:none!important}.post-type-ficha_clinica .acf-tab-group{gap:4px}.post-type-ficha_clinica .acf-table-wrap{overflow-x:auto}.post-type-ficha_clinica .rptx-block-field{padding:12px}.post-type-ficha_clinica #poststuff{min-width:0}}</style>';
});

// Busca restrita: nao publica dados clinicos na REST API.
add_action('wp_ajax_myfc_buscar', function () {
    check_ajax_referer('myfc_buscar', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Acesso negado', 403);
    $term = isset($_POST['termo']) ? sanitize_text_field(wp_unslash($_POST['termo'])) : '';
    if (mb_strlen($term) < 3) wp_send_json_success([]);
    $query = new WP_Query(['post_type'=>'ficha_clinica','post_status'=>['publish','private','draft'],'s'=>$term,'posts_per_page'=>10,'orderby'=>'title','order'=>'ASC']);
    $rows = [];
    foreach ($query->posts as $p) {
        if (!current_user_can('edit_post', $p->ID)) continue;
        $rows[] = ['ver'=>add_query_arg('myfc_ficha', $p->ID, home_url('/')).'#myfc-home','editar'=>get_edit_post_link($p->ID, 'raw'),'nome'=>(string)get_post_meta($p->ID,'nome_completo',true),'nascimento'=>(string)get_post_meta($p->ID,'data_nascimento',true),'telefone'=>(string)get_post_meta($p->ID,'telefone',true),'responsavel'=>(string)get_post_meta($p->ID,'responsavel',true)];
    }
    wp_send_json_success($rows);
});
add_action('wp_footer', function () {
    if (!current_user_can('edit_posts')) return;
    ?>
    <script>
    (function(){
      const fichaUrl = <?php echo wp_json_encode(admin_url('edit.php?post_type=ficha_clinica')); ?>;
      const agendaUrl = <?php echo wp_json_encode(home_url('/my-agenda/')); ?>;
      const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      const nonce = <?php echo wp_json_encode(wp_create_nonce('myfc_buscar')); ?>;
      let fichaPanelCount=0;
      document.addEventListener('click',function(event){
        const button=event.target.closest('.btn-ficha');
        if(!button)return;
        event.preventDefault();event.stopImmediatePropagation();
        const card=button.closest('.paciente-card');if(!card)return;
        let panel=card.querySelector('.myfc-inline-panel');
        if(panel){panel.hidden=!panel.hidden;button.setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden)panel.querySelector('input').focus();return;}
        const title=card.querySelector('h4')?.textContent || '';
        const name=card.dataset.patientName||title.replace(/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}\s*[-–—]\s*/, '').trim();
        panel=document.createElement('section');panel.className='myfc-inline-panel';panel.id='myfc-inline-'+(++fichaPanelCount);panel.setAttribute('aria-label','Buscar ou cadastrar ficha clínica');
        button.setAttribute('aria-expanded','true');button.setAttribute('aria-controls',panel.id);
        const heading=document.createElement('h5');heading.textContent='Editar ou cadastrar ficha';
        const label=document.createElement('label');label.textContent='Buscar ficha pelo nome';
        const input=document.createElement('input');input.type='search';input.autocomplete='off';input.value=name;input.id=panel.id+'-busca';label.htmlFor=input.id;
        const result=document.createElement('div');result.className='myfc-suggestions';result.setAttribute('aria-live','polite');
        const create=document.createElement('a');create.className='my-btn';create.textContent='Cadastrar nova ficha';create.target='_blank';create.rel='noopener';
        const hint=document.createElement('p');hint.className='myfc-help';hint.textContent='A ficha abre em outra aba para manter o Acolhimento disponível.';
        const close=document.createElement('button');close.type='button';close.className='myfc-close';close.textContent='Fechar busca';close.addEventListener('click',function(){panel.hidden=true;button.setAttribute('aria-expanded','false');button.focus();});
        panel.append(heading,label,input,result,create,hint,close);card.appendChild(panel);
        let timer;let generation=0;
        function search(){const ticket=++generation;const term=input.value.trim();const newUrl=new URL(<?php echo wp_json_encode(admin_url('post-new.php?post_type=ficha_clinica')); ?>);if(term)newUrl.searchParams.set('myfc_nome',term);create.href=newUrl.href;result.replaceChildren();
          if(term.length<3){result.textContent='Digite pelo menos 3 letras para ver sugestões.';return;}
          result.textContent='Buscando fichas...';
          timer=setTimeout(async function(){try{
            const body=new URLSearchParams({action:'myfc_buscar',nonce:nonce,termo:term});
            const response=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:body});
            if(!response.ok)throw new Error('Busca indisponível');const data=await response.json();if(ticket!==generation)return;if(!data.success)throw new Error('Busca indisponível');result.replaceChildren();
            if(!data.data.length){result.textContent='Nenhuma ficha encontrada. Confira o nome ou cadastre uma nova ficha.';return;}
            const caption=document.createElement('p');caption.textContent='Fichas encontradas — confira o paciente antes de editar:';result.appendChild(caption);
            data.data.forEach(function(patient){const row=document.createElement('div');row.className='myfc-suggestion';const text=document.createElement('span');text.textContent=patient.nome||'Ficha sem nome';const edit=document.createElement('a');edit.className='my-btn';edit.textContent='Editar ficha';edit.href=patient.editar;edit.target='_blank';edit.rel='noopener';row.append(text,edit);result.appendChild(row);});
          }catch(error){if(ticket===generation)result.textContent='Não foi possível buscar as fichas. Tente novamente antes de cadastrar para evitar duplicidade.';}},250);
        }
        input.addEventListener('input',function(){clearTimeout(timer);search();});search();input.focus();
      },true);
      // O formulario restaurado da pagina inicial estava truncado.
      const partial=document.querySelector('.my-agenda-form');
      if(partial && !partial.querySelector('#responsavel')){
        const heading=Array.from(partial.querySelectorAll('h3')).find(el=>el.textContent.includes('Novo agendamento'));
        if(heading){let next=heading.nextElementSibling;while(next){const following=next.nextElementSibling;next.remove();next=following;}
        const details=document.createElement('details');details.className='myfc-agendar';const summary=document.createElement('summary');summary.textContent='Agendar';const frame=document.createElement('iframe');frame.title='Formulário de agendamento';frame.loading='lazy';frame.style.cssText='width:100%;height:1050px;border:0;margin-top:16px;display:block;';const embed=new URL(agendaUrl);embed.searchParams.set('myfc_embed_agenda','1');let loaded=false;details.addEventListener('toggle',function(){if(details.open&&!loaded){loaded=true;frame.src=embed.href;}});frame.addEventListener('load',function(){try{const doc=frame.contentDocument;if(!doc)return;const resize=function(){const h=Math.max(500,doc.body.scrollHeight+30);if(Math.abs(parseInt(frame.style.height,10)-h)>2)frame.style.height=h+'px';};resize();const observer=new ResizeObserver(resize);observer.observe(doc.body);}catch(error){}});details.append(summary,frame);partial.appendChild(details);}
      }
      const form=document.querySelector('#agenda-form');
      const nome=form?.querySelector('#nome'); if(!nome)return;
      const box=document.createElement('div'); box.className='my-patient-search';
      const label=document.createElement('label');label.textContent='Buscar paciente cadastrado';
      const input=document.createElement('input');input.type='search';input.placeholder='Digite pelo menos 3 letras do nome';input.autocomplete='off';input.id='myfc-busca';label.htmlFor=input.id;
      const result=document.createElement('div');result.setAttribute('aria-live','polite');
      box.append(label,input,result);form.before(box);
      let timer;let generation=0;
      input.addEventListener('input',function(){clearTimeout(timer);const ticket=++generation;const term=input.value.trim();result.replaceChildren();if(term.length<3)return;
        timer=setTimeout(async function(){result.textContent='Buscando...';try{
          const body=new URLSearchParams({action:'myfc_buscar',nonce:nonce,termo:term});
          const response=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:body});
          if(!response.ok)throw new Error('Busca indisponível');const data=await response.json();if(ticket!==generation)return;result.replaceChildren();
          if(!data.success)throw new Error('Busca indisponível');
          if(!data.data.length){result.textContent='Nenhuma ficha encontrada. Você pode preencher o novo agendamento manualmente.';return;}
          data.data.forEach(function(patient){const select=document.createElement('button');select.type='button';select.textContent=patient.nome || 'Ficha sem nome';
            select.addEventListener('click',function(){nome.value=patient.nome;const birth=patient.nascimento;let formatted=birth;if(/^\d{8}$/.test(birth))formatted=birth.slice(6,8)+'/'+birth.slice(4,6)+'/'+birth.slice(0,4);else if(/^\d{4}-\d{2}-\d{2}$/.test(birth))formatted=birth.split('-').reverse().join('/');
              [['nascimento',formatted],['telefone',patient.telefone],['responsavel',patient.responsavel]].forEach(function(pair){const el=form.querySelector('#'+pair[0]);if(el)el.value=pair[1]||'';});result.replaceChildren();result.textContent='Paciente selecionado. Confira os dados antes de agendar.';nome.focus();});result.appendChild(select);});
        }catch(error){if(ticket===generation)result.textContent='Não foi possível buscar. Tente novamente.';}},350);
      });
    })();
    </script>
    <?php
});

// Preenche apenas o nome de uma ficha nova aberta pelo Acolhimento.
add_filter('acf/load_value/name=nome_completo', function ($value, $post_id) {
    if ($value || get_post_type($post_id) !== 'ficha_clinica' || get_post_status($post_id) !== 'auto-draft') return $value;
    if (!current_user_can('edit_posts') || !isset($_GET['myfc_nome'])) return $value;
    return sanitize_text_field(wp_unslash($_GET['myfc_nome']));
}, 10, 2);
add_action('wp_footer', function () {
    if (!current_user_can('edit_posts')) return;
    echo '<style>.myfc-inline-panel{margin-top:18px;padding:16px;background:#fff;border:1px solid #a6c9cd;border-radius:12px}.myfc-inline-panel[hidden]{display:none}.myfc-inline-panel h5{margin:0 0 12px;font-size:17px;color:#115c66}.myfc-inline-panel label{display:block;margin-bottom:6px}.myfc-inline-panel input{display:block;width:100%;margin-bottom:12px}.myfc-suggestions{font-size:14px;line-height:1.6;margin-bottom:14px}.myfc-suggestion{display:flex;gap:12px;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #dbe7e9}.myfc-suggestion span{overflow-wrap:anywhere}.myfc-suggestion .my-btn{flex-shrink:0}.myfc-inline-panel .myfc-help{font-size:12px!important;color:#53686c!important;line-height:1.5}.myfc-inline-panel .myfc-close{min-height:44px;padding:10px 14px;border-radius:8px;cursor:pointer}@media(max-width:600px){.myfc-inline-panel{padding:12px}.myfc-suggestion{flex-direction:column;align-items:stretch}.myfc-inline-panel .my-btn{text-align:center;display:block;width:100%}}</style>';
}, 30);

// Fichas na pagina inicial: formularios nativos do ACF, com nonce e permissoes.
add_action('template_redirect', function () {
    if (is_front_page() && current_user_can('edit_posts') && function_exists('acf_form_head')) {
        nocache_headers();
        acf_form_head();
    }
}, 1);
add_filter('the_content', function ($content) {
    if (!is_front_page() || !is_main_query() || !in_the_loop() || !current_user_can('edit_posts') || !function_exists('acf_form')) return $content;
    $selected = isset($_GET['myfc_ficha']) ? absint($_GET['myfc_ficha']) : 0;
    $valid = $selected && get_post_type($selected) === 'ficha_clinica' && current_user_can('edit_post', $selected);
    ob_start();
    ?>
    <section id="myfc-home" class="myfc-home">
      <h2>Fichas clínicas</h2>
      <p>Cadastre um paciente ou busque uma ficha existente.</p>
      <details class="myfc-home-search" open>
        <summary>Buscar e ver ficha</summary>
        <div class="myfc-home-body">
          <label for="myfc-home-query">Nome do paciente</label>
          <input type="search" id="myfc-home-query" placeholder="Digite pelo menos 3 letras do nome" autocomplete="off">
          <div id="myfc-home-results" aria-live="polite">Busque pelo nome antes de cadastrar para evitar fichas duplicadas.</div>
        </div>
      </details>
      <details class="myfc-home-form" <?php echo $valid ? 'open' : ''; ?>>
        <summary><?php echo $valid ? 'Ver / editar ficha selecionada' : 'Nova ficha clínica'; ?></summary>
        <div class="myfc-home-body">
          <?php if ($selected && !$valid) : ?><p role="alert">Ficha indisponível para este usuário.</p><?php endif; ?>
          <?php if ($valid) : ?>
            <h3><?php echo esc_html(get_the_title($selected)); ?></h3>
            <p><a class="my-btn" href="<?php echo esc_url(home_url('/?myfc_nova=1#myfc-home')); ?>">Cadastrar outra ficha</a></p>
          <?php endif; ?>
          <?php
          acf_form([
            'id'=>'myfc-home-acf',
            'html_before_fields'=>myfc_agenda_hidden(),
            'post_id'=>$valid ? $selected : 'new_post',
            'new_post'=>['post_type'=>'ficha_clinica','post_status'=>current_user_can('publish_posts') ? 'private' : 'draft','post_title'=>'Nova ficha clínica'],
            'field_groups'=>['group_my_ficha_clinica_v1'],
            'post_title'=>false,'post_content'=>false,
            'submit_value'=>$valid ? 'Salvar alterações da ficha' : 'Salvar nova ficha clínica',
            'updated_message'=>'Ficha salva com sucesso.',
            'return'=>home_url('/?myfc_ficha=%post_id%&updated=true#myfc-home'),
            'uploader'=>'basic',
          ]);
          ?>
        </div>
      </details>
    </section>
    <?php
    return ob_get_clean() . $content;
}, 20);
add_action('wp_footer', function () {
    if (!is_front_page() || !current_user_can('edit_posts')) return;
    ?>
    <style>
    #myfc-home{padding:22px;margin-bottom:28px;border:1px solid #dbe7e9;border-radius:16px;background:#fff;color:#18343b}
    #myfc-home h2{margin:0 0 8px}#myfc-home details{border:1px solid #bdd4d7;border-radius:12px;margin-top:14px;background:#f5fafa;overflow:hidden}#myfc-home summary{padding:16px;min-height:48px;cursor:pointer;font-size:17px;font-weight:700;color:#115c66}#myfc-home summary:focus-visible{outline:3px solid #27aaa5;outline-offset:-3px}#myfc-home .myfc-home-body{padding:16px;background:#fff}#myfc-home-query{display:block;width:100%;margin:8px 0 14px}#myfc-home-results{font-size:14px;line-height:1.6}#myfc-home .myfc-home-result{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #dbe7e9}#myfc-home .myfc-home-result span{overflow-wrap:anywhere}#myfc-home .myfc-home-result .my-btn{flex-shrink:0}#myfc-home .acf-fields{background:#fff}#myfc-home .acf-tab-group{display:flex;flex-wrap:wrap;gap:4px}#myfc-home .acf-tab-group li a{min-height:44px;box-sizing:border-box;color:#115c66;background:#eaf6f5}#myfc-home .acf-tab-group li.active a{background:#fff}#myfc-home .acf-field{border-color:#dbe7e9}#myfc-home .rptx-block-field>.acf-label{display:none}#myfc-home .rptx-block-fields{display:block}#myfc-home .rptx-block-field{width:100%;box-sizing:border-box}#myfc-home .acf-button,#myfc-home input[type=submit]{display:inline-block;background:#115c66!important;color:#fff!important;min-height:44px;padding:10px 16px!important;border-radius:8px;text-decoration:none;border:0!important;font-size:16px;cursor:pointer}#myfc-home .acf-form-submit{margin:20px 0 0}#myfc-home .acf-table-wrap{overflow-x:auto}#myfc-home .acf-notice{background:#eaf6f5;color:#18343b;border-color:#27aaa5}#myfc-home .acf-input input,#myfc-home .acf-input textarea,#myfc-home .acf-input select{min-height:44px;font-size:16px}#myfc-home .myfc-home-body label{display:block}#myfc-home .acf-required{color:#a32323}@media(max-width:768px){#myfc-home{padding:14px}#myfc-home .myfc-home-body{padding:10px}#myfc-home .acf-field{width:100%!important;float:none!important}#myfc-home .myfc-home-result{align-items:stretch;flex-direction:column}#myfc-home .myfc-home-result .my-btn{text-align:center}}
    </style>
    <script>
    (function(){
      const input=document.getElementById('myfc-home-query');const results=document.getElementById('myfc-home-results');if(!input||!results)return;
      const ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;const nonce=<?php echo wp_json_encode(wp_create_nonce('myfc_buscar')); ?>;
      const home=<?php echo wp_json_encode(home_url('/')); ?>;let timer;let generation=0;
      input.addEventListener('input',function(){clearTimeout(timer);const ticket=++generation;const term=input.value.trim();results.replaceChildren();if(term.length<3){results.textContent='Digite pelo menos 3 letras para buscar.';return;}results.textContent='Buscando fichas...';timer=setTimeout(async function(){try{
        const response=await fetch(ajax,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'myfc_buscar',nonce:nonce,termo:term})});if(!response.ok)throw new Error();const data=await response.json();if(ticket!==generation)return;if(!data.success)throw new Error();results.replaceChildren();
        if(!data.data.length){results.textContent='Nenhuma ficha encontrada. Confira o nome ou abra Nova ficha clínica.';return;}
        data.data.forEach(function(patient){const row=document.createElement('div');row.className='myfc-home-result';const text=document.createElement('span');text.textContent=patient.nome||'Ficha sem nome';const open=document.createElement('a');open.className='my-btn';open.textContent='Ver ficha';open.href=patient.ver;row.append(text,open);results.appendChild(row);});
      }catch(error){if(ticket===generation)results.textContent='Não foi possível buscar as fichas. Tente novamente.';}},300);});
      if(new URLSearchParams(window.location.search).has('myfc_nova'))document.querySelector('#myfc-home .myfc-home-form').open=true;
    })();
    </script>
    <?php
}, 40);

// Versao compacta do formulario existente para expandir Agendar na pagina inicial.
add_action('wp_head', function () {
    if (!isset($_GET['myfc_embed_agenda']) || !is_page('my-agenda') || !current_user_can('edit_posts')) return;
    echo '<style>html{margin-top:0!important;min-height:0!important}body{margin:0!important;min-height:0!important;background:#fff!important}#wpadminbar,.my-top,.my-foot,.my-titulo,.my-agenda>iframe,.my-agenda>h2,.my-agenda>p:first-of-type,.my-agenda>hr{display:none!important}.my-wrap{padding:0!important;max-width:none!important}.my-agenda{margin:0!important;padding:12px!important;border:0!important;box-shadow:none!important}.my-pagina-conteudo .my-agenda #agenda-form{margin:0}.my-agenda h3{margin-top:0!important}</style>';
}, 100);
add_action('wp_footer', function () {
    if (!is_front_page() || !current_user_can('edit_posts')) return;
    echo '<style>.myfc-agendar{border:1px solid #bdd4d7;border-radius:12px;background:#f5fafa;margin-top:12px;overflow:hidden}.myfc-agendar summary{cursor:pointer;min-height:48px;padding:16px;font-size:17px;font-weight:700;color:#115c66}.myfc-agendar summary:focus-visible{outline:3px solid #27aaa5;outline-offset:-3px}.myfc-agendar iframe{background:#fff;filter:none!important}</style>';
}, 50);

// Consulta de CEP restrita aos usuarios do sistema. Somente o CEP e enviado ao ViaCEP.
add_action('wp_ajax_myfc_cep', function () {
    check_ajax_referer('myfc_cep', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message'=>'Acesso negado.'], 403);
    $cep = isset($_POST['cep']) ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['cep'])) : '';
    if (strlen($cep) !== 8) wp_send_json_error(['message'=>'Informe um CEP com 8 números.'], 400);
    $cached = get_transient('myfc_cep_'.$cep);
    if (is_array($cached)) wp_send_json_success($cached);
    $response = wp_remote_get('https://viacep.com.br/ws/'.$cep.'/json/', ['timeout'=>8,'redirection'=>0,'limit_response_size'=>12000]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) wp_send_json_error(['message'=>'Consulta indisponível. Preencha o endereço manualmente ou tente novamente.'], 502);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) wp_send_json_error(['message'=>'Resposta inválida. Preencha o endereço manualmente.'], 502);
    if (!empty($data['erro'])) wp_send_json_error(['message'=>'CEP não encontrado. Confira os números ou preencha o endereço manualmente.'], 404);
    $result = [];
    foreach (['cep','logradouro','bairro','localidade','uf'] as $key) $result[$key] = isset($data[$key]) ? sanitize_text_field($data[$key]) : '';
    if (!$result['localidade'] || strlen($result['uf']) !== 2) wp_send_json_error(['message'=>'Não foi possível confirmar o endereço. Preencha manualmente.'], 502);
    set_transient('myfc_cep_'.$cep, $result, 7 * DAY_IN_SECONDS);
    wp_send_json_success($result);
});
function myfc_render_cep_helper() {
    if (!current_user_can('edit_posts')) return;
    ?>
    <script>
    (function(){
      const cep=document.getElementById('acf-field_myfc_cep');if(!cep||cep.dataset.myfcCepReady)return;cep.dataset.myfcCepReady='1';cep.inputMode='numeric';cep.autocomplete='postal-code';
      const button=document.createElement('button');button.type='button';button.className='button myfc-cep-button';button.textContent='Consultar CEP';button.style.cssText='display:inline-block;min-height:44px;padding:10px 16px;margin-top:10px;border-radius:8px;background:#115c66;color:white;border:0;cursor:pointer;';
      const status=document.createElement('p');status.setAttribute('aria-live','polite');status.style.cssText='font-size:13px;line-height:1.5;margin:8px 0 0;';
      cep.after(button,status);let generation=0;
      const find=function(name){return document.getElementById('acf-field_myfc_'+name);};
      [['logradouro','address-line1'],['complemento','address-line2'],['bairro','address-level3'],['cidade','address-level2'],['uf','address-level1']].forEach(function(pair){const el=find(pair[0]);if(el)el.autocomplete=pair[1];});
      const uf=find('uf');if(uf)uf.addEventListener('input',function(){uf.value=uf.value.toUpperCase().replace(/[^A-Z]/g,'').slice(0,2);});
      cep.addEventListener('input',function(){generation++;const digits=cep.value.replace(/\D/g,'').slice(0,8);cep.value=digits.length>5?digits.slice(0,5)+'-'+digits.slice(5):digits;status.textContent='';});
      button.addEventListener('click',async function(){const value=cep.value.replace(/\D/g,'');if(value.length!==8){status.textContent='Informe os 8 números do CEP.';cep.focus();return;}const ticket=++generation;button.disabled=true;status.textContent='Consultando CEP...';
        try{const response=await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'myfc_cep',nonce:<?php echo wp_json_encode(wp_create_nonce('myfc_cep')); ?>,cep:value})});const result=await response.json();if(ticket!==generation)return;if(!response.ok||!result.success)throw new Error(result.data?.message||'Consulta indisponível. Preencha manualmente.');
          const data=result.data;[['logradouro',data.logradouro],['bairro',data.bairro],['cidade',data.localidade],['uf',data.uf]].forEach(function(pair){const el=find(pair[0]);if(el&&pair[1]){el.value=pair[1];el.dispatchEvent(new Event('change',{bubbles:true}));}});
          status.textContent=data.logradouro?'Endereço preenchido. Confira os dados e informe número e complemento.':'CEP geral da localidade: cidade e UF preenchidas. Complete rua, bairro, número e complemento.';
          find(data.logradouro?'numero':'logradouro')?.focus();
        }catch(error){if(ticket===generation)status.textContent=error.message||'Consulta indisponível. Preencha o endereço manualmente.';}finally{button.disabled=false;}
      });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'myfc_render_cep_helper', 60);
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'ficha_clinica') myfc_render_cep_helper();
}, 60);

add_action('wp_ajax_myfc_cep_endereco', function () {
    check_ajax_referer('myfc_cep', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message'=>'Acesso negado.'], 403);
    $uf = isset($_POST['uf']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['uf']))) : '';
    $cidade = isset($_POST['cidade']) ? sanitize_text_field(wp_unslash($_POST['cidade'])) : '';
    $rua = isset($_POST['rua']) ? sanitize_text_field(wp_unslash($_POST['rua'])) : '';
    $estados = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    if (!in_array($uf,$estados,true) || mb_strlen($cidade)<3 || mb_strlen($rua)<3 || mb_strlen($cidade)>100 || mb_strlen($rua)>160) wp_send_json_error(['message'=>'Preencha UF, cidade e rua. Cidade e rua precisam de pelo menos 3 letras.'],400);
    $url='https://viacep.com.br/ws/'.$uf.'/'.rawurlencode($cidade).'/'.rawurlencode($rua).'/json/';
    $response=wp_remote_get($url,['timeout'=>8,'redirection'=>0,'limit_response_size'=>100000]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) wp_send_json_error(['message'=>'Busca indisponível. Tente novamente ou preencha manualmente.'],502);
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if (!is_array($data) || !empty($data['erro'])) wp_send_json_error(['message'=>'Não foi possível encontrar esse endereço. Confira os dados.'],404);
    $results=[];
    foreach (array_slice($data,0,20) as $item) {
        if (!is_array($item) || empty($item['cep'])) continue;
        $row=[];foreach (['cep','logradouro','bairro','localidade','uf'] as $key) $row[$key]=isset($item[$key])?sanitize_text_field($item[$key]):'';
        $results[]=$row;
    }
    wp_send_json_success(['enderecos'=>$results,'refinar'=>count($data)>20]);
});
function myfc_render_reverse_cep_helper() {
    if (!current_user_can('edit_posts')) return;
    ?>
    <script>
    (function(){
      const cep=document.getElementById('acf-field_myfc_cep');if(!cep||cep.dataset.myfcReverseReady)return;cep.dataset.myfcReverseReady='1';
      const field=function(name){return document.getElementById('acf-field_myfc_'+name);};
      const wrapper=document.createElement('div');wrapper.className='myfc-reverse-cep';wrapper.style.marginTop='10px';
      const button=document.createElement('button');button.type='button';button.textContent='Não sei o CEP — buscar pelo endereço';button.className='button';button.style.cssText='min-height:44px;padding:10px 14px;border-radius:8px;background:#eaf6f5;color:#115c66;border:1px solid #a6c9cd;cursor:pointer;white-space:normal;';
      const help=document.createElement('p');help.textContent='Preencha UF, cidade e rua abaixo e clique para buscar. A consulta envia somente esses dados ao ViaCEP.';help.style.cssText='font-size:13px;line-height:1.5;margin:8px 0;';
      const results=document.createElement('div');results.setAttribute('aria-live','polite');results.style.cssText='font-size:14px;line-height:1.6;';wrapper.append(button,help,results);cep.closest('.acf-input').appendChild(wrapper);
      let generation=0;['uf','cidade','logradouro'].forEach(function(name){field(name)?.addEventListener('input',function(){generation++;results.replaceChildren();});});
      button.addEventListener('click',async function(){const uf=field('uf')?.value.trim().toUpperCase()||'';const city=field('cidade')?.value.trim()||'';const street=field('logradouro')?.value.trim()||'';
        if(uf.length!==2||city.length<3||street.length<3){results.textContent='Preencha UF, cidade e rua nos campos de endereço. Depois clique novamente para buscar o CEP.';field(!street?'logradouro':!city?'cidade':'uf')?.focus();return;}
        const ticket=++generation;button.disabled=true;results.textContent='Buscando CEPs...';
        try{const response=await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'myfc_cep_endereco',nonce:<?php echo wp_json_encode(wp_create_nonce('myfc_cep')); ?>,uf:uf,cidade:city,rua:street})});const data=await response.json();if(ticket!==generation)return;if(!response.ok||!data.success)throw new Error(data.data?.message||'Busca indisponível.');results.replaceChildren();
          if(!data.data.enderecos.length){results.textContent='Nenhum CEP encontrado. Confira UF, cidade e nome da rua, ou preencha manualmente.';return;}
          const heading=document.createElement('p');heading.textContent=data.data.refinar?'Exibindo os primeiros 20 resultados. Refine o nome da rua para localizar o trecho correto.':'Confira a rua, o bairro e o trecho antes de selecionar:';results.appendChild(heading);
          data.data.enderecos.forEach(function(address){const row=document.createElement('div');row.style.cssText='padding:12px 0;border-top:1px solid #dbe7e9;';const description=document.createElement('p');description.textContent=address.logradouro+' — '+address.bairro+' — '+address.localidade+'/'+address.uf+' — CEP '+address.cep;description.style.margin='0 0 8px';const choose=document.createElement('button');choose.type='button';choose.textContent='Usar este endereço';choose.className='button';choose.style.cssText='min-height:44px;padding:10px 14px;cursor:pointer;';choose.addEventListener('click',function(){[['cep',address.cep],['logradouro',address.logradouro],['bairro',address.bairro],['cidade',address.localidade],['uf',address.uf]].forEach(function(pair){const el=field(pair[0]);if(el){el.value=pair[1]||'';el.dispatchEvent(new Event('change',{bubbles:true}));}});results.replaceChildren();results.textContent='Endereço selecionado. Confira e complete número e complemento.';field('numero')?.focus();});row.append(description,choose);results.appendChild(row);});
        }catch(error){if(ticket===generation)results.textContent=error.message||'Não foi possível consultar. Preencha manualmente.';}finally{button.disabled=false;}
      });
    })();
    </script>
    <?php
}
add_action('wp_footer','myfc_render_reverse_cep_helper',61);
add_action('admin_footer',function(){ $screen=get_current_screen();if($screen&&$screen->post_type==='ficha_clinica')myfc_render_reverse_cep_helper(); },61);

// CPF: formato visual e verificacao dos dois digitos, sem consulta cadastral.
function myfc_validar_cpf($value) {
    $raw=trim((string)$value);
    if ($raw==='') return true;
    if (preg_match('/[^0-9.\-\s]/',$raw)) return false;
    $cpf=preg_replace('/[^0-9]/','',$raw);
    if (strlen($cpf)!==11 || preg_match('/^(\d)\1{10}$/',$cpf)) return false;
    for ($length=9;$length<=10;$length++) {
        $sum=0;
        for ($i=0;$i<$length;$i++) $sum+=(int)$cpf[$i]*($length+1-$i);
        $digit=($sum*10)%11;
        if ($digit===10) $digit=0;
        if ((int)$cpf[$length]!==$digit) return false;
    }
    return true;
}
add_filter('acf/validate_value/key=field_myfc_cpf',function($valid,$value,$field,$input){
    if ($valid!==true) return $valid;
    return myfc_validar_cpf($value) ? true : 'CPF inválido. Confira os 11 números e os dígitos verificadores.';
},10,4);
add_filter('acf/update_value/key=field_myfc_cpf',function($value){
    $digits=preg_replace('/[^0-9]/','',(string)$value);
    if (strlen($digits)!==11) return $value;
    return substr($digits,0,3).'.'.substr($digits,3,3).'.'.substr($digits,6,3).'-'.substr($digits,9,2);
},10,1);
function myfc_render_cpf_helper(){
    if (!current_user_can('edit_posts')) return;
    ?>
    <script>
    (function(){
      const input=document.getElementById('acf-field_myfc_cpf');if(!input||input.dataset.myfcCpfReady)return;input.dataset.myfcCpfReady='1';input.inputMode='numeric';input.maxLength=14;input.placeholder='000.000.000-00';
      const status=document.createElement('p');status.id='myfc-cpf-feedback';status.setAttribute('aria-live','polite');status.style.cssText='font-size:13px;line-height:1.5;margin:8px 0 0;';input.after(status);const described=input.getAttribute('aria-describedby');input.setAttribute('aria-describedby',(described?described+' ':'')+status.id);
      function validCPF(digits){if(!/^\d{11}$/.test(digits)||/^(\d)\1{10}$/.test(digits))return false;for(let length=9;length<=10;length++){let sum=0;for(let i=0;i<length;i++)sum+=Number(digits[i])*(length+1-i);let digit=(sum*10)%11;if(digit===10)digit=0;if(Number(digits[length])!==digit)return false;}return true;}
      function format(value){const d=value.replace(/\D/g,'').slice(0,11);return d.replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/(\d{3})\.(\d{3})\.(\d{3})(\d)/,'$1.$2.$3-$4');}
      function check(showIncomplete){const digits=input.value.replace(/\D/g,'');const valid=!digits||validCPF(digits);input.setCustomValidity(valid?'':'CPF inválido. Confira os 11 números.');if(!digits){status.textContent='';input.removeAttribute('aria-invalid');return;}if(digits.length<11&&!showIncomplete){status.textContent='';input.removeAttribute('aria-invalid');return;}input.setAttribute('aria-invalid',String(!valid));status.style.color=valid?'#236b42':'#a32323';status.textContent=valid?'Dígitos verificadores conferidos.':'CPF inválido. Confira os 11 números e os dígitos verificadores.';}
      input.value=format(input.value);check(false);
      input.addEventListener('input',function(){const before=input.value;const caret=input.selectionStart;const digitsBefore=before.slice(0,caret===null?before.length:caret).replace(/\D/g,'').length;input.value=format(before);let next=0;let seen=0;while(next<input.value.length&&seen<digitsBefore){if(/\d/.test(input.value[next]))seen++;next++;}if(document.activeElement===input)input.setSelectionRange(next,next);check(false);});
      input.addEventListener('blur',function(){check(true);});
    })();
    </script>
    <?php
}
add_action('wp_footer','myfc_render_cpf_helper',62);
add_action('admin_footer',function(){ $screen=get_current_screen();if($screen&&$screen->post_type==='ficha_clinica')myfc_render_cpf_helper(); },62);

// Valida os vinculos sem tornar arquivos publicos nem guardar o PDF no WordPress.
foreach (['receitas','atestados','relatorios'] as $myfc_doc_type) {
    add_filter('acf/validate_value/key=field_myfc_'.$myfc_doc_type,function($valid,$value,$field,$input)use($myfc_doc_type){
        if ($valid!==true || !is_array($value)) return $valid;
        foreach ($value as $row) {
            if (!is_array($row)) continue;
            $key='field_myfc_'.$myfc_doc_type.'_arquivo_drive';
            $name=$myfc_doc_type.'_arquivo_drive';
            $link=isset($row[$key])?trim((string)$row[$key]):(isset($row[$name])?trim((string)$row[$name]):'');
            $url=wp_parse_url($link);
            if (!is_array($url) || empty($url['host']) || !in_array(strtolower($url['host']),['drive.google.com','docs.google.com'],true) || ($url['scheme']??'')!=='https') return 'Informe o link HTTPS do PDF no Google Drive em cada documento.';
        }
        return true;
    },10,4);
}
function myfc_render_document_helpers(){
    if (!current_user_can('edit_posts')) return;
    ?>
    <script>
    (function(){
      const form=document.getElementById('myfc-home-acf')||document.querySelector('body.post-type-ficha_clinica #post');if(!form)return;
      document.querySelectorAll('.myfc-copy-patient').forEach(function(button){button.addEventListener('click',async function(){const nome=document.getElementById('acf-field_myfc_nome_completo')?.value.trim()||'';const cpf=document.getElementById('acf-field_myfc_cpf')?.value.trim()||'';if(!nome){alert('Preencha ou selecione a ficha do paciente primeiro.');return;}try{await navigator.clipboard.writeText('Nome: '+nome+(cpf?'\nCPF: '+cpf:''));button.textContent='Dados copiados';setTimeout(function(){button.textContent='Copiar nome e CPF';},2500);}catch(error){alert('Não foi possível copiar automaticamente. Selecione nome e CPF nos Dados Pessoais.');}});});
      function links(){form.querySelectorAll('input[type=url]').forEach(function(input){if(!input.name.includes('_arquivo_drive'))return;let open=input.parentElement.querySelector('.myfc-open-drive');if(!open){open=document.createElement('a');open.className='button my-btn myfc-open-drive';open.target='_blank';open.rel='noopener noreferrer';open.textContent='Abrir PDF no Drive';open.style.cssText='display:inline-block;margin-top:8px;min-height:44px;padding:10px 14px;border-radius:8px;';input.after(open);input.addEventListener('input',links);}
        try{const url=new URL(input.value);const good=url.protocol==='https:'&&['drive.google.com','docs.google.com'].includes(url.hostname);if(!good)throw new Error();open.href=url.href;open.hidden=false;}catch(error){open.removeAttribute('href');open.hidden=true;}
      });}
      links();const observer=new MutationObserver(links);observer.observe(form,{childList:true,subtree:true});
    })();
    </script>
    <style>.myfc-copy-patient{min-height:44px;padding:10px 14px;border-radius:8px;cursor:pointer}.myfc-open-drive[hidden]{display:none!important}.acf-field-message .my-btn{margin:4px 6px 4px 0}</style>
    <?php
}
add_action('wp_footer','myfc_render_document_helpers',63);
add_action('admin_footer',function(){ $screen=get_current_screen();if($screen&&$screen->post_type==='ficha_clinica')myfc_render_document_helpers(); },63);

// Presenca vinculada: dados privados, um vinculo por agendamento.
function myfc_agenda_context() {
    $id=absint($_REQUEST['myfc_agenda']??0);
    $nonce=sanitize_text_field(wp_unslash($_REQUEST['myfc_agenda_nonce']??''));
    $status=sanitize_key($_REQUEST['myfc_situacao']??'aguardando');
    if (!$id || get_post_type($id)!=='prontuario' || !current_user_can('edit_post',$id) || !wp_verify_nonce($nonce,'myfc_agenda_'.$id) || !in_array($status,['agendado','aguardando','atendido','remarcado','desmarcou','faltou','atendimento_atrasado'],true)) return [];
    return ['id'=>$id,'status'=>$status,'nonce'=>$nonce];
}
function myfc_agenda_hidden() {
    $c=myfc_agenda_context(); if (!$c) return '';
    return '<div class="acf-notice"><p>Ao salvar, este agendamento será vinculado à ficha: '.esc_html(get_the_title($c['id'])).' — '.esc_html(ucfirst($c['status'])).'.</p></div><input type="hidden" name="myfc_agenda" value="'.esc_attr($c['id']).'"><input type="hidden" name="myfc_situacao" value="'.esc_attr($c['status']).'"><input type="hidden" name="myfc_agenda_nonce" value="'.esc_attr($c['nonce']).'">';
}
function myfc_agenda_gravar($agenda,$ficha,$status) {
    if (get_post_type($agenda)!=='prontuario'||get_post_type($ficha)!=='ficha_clinica'||!current_user_can('edit_post',$agenda)||!current_user_can('edit_post',$ficha)||!in_array($status,['agendado','aguardando','atendido','remarcado','desmarcou','faltou','atendimento_atrasado'],true)) return new WP_Error('acesso','Não foi possível registrar.');
    $old=(int)get_post_meta($agenda,'_myfc_ficha_id',true);
    if (!$old) { add_post_meta($agenda,'_myfc_ficha_id',$ficha,true); $old=(int)get_post_meta($agenda,'_myfc_ficha_id',true); }
    if ($old!==$ficha) return new WP_Error('vinculo','Este agendamento já está vinculado a outra ficha. Recarregue o Acolhimento.');
    $before=(string)get_post_meta($agenda,'_myfc_presenca',true);
    if ($before!==$status) {
        update_post_meta($agenda,'_myfc_presenca',$status);
        update_post_meta($agenda,'status',$status);
        add_post_meta($agenda,'_myfc_presenca_auditoria',['anterior'=>$before,'situacao'=>$status,'usuario'=>get_current_user_id(),'data'=>current_time('mysql')]);
    }
    return true;
}
add_action('acf/validate_save_post',function(){
    if (empty($_POST['myfc_agenda'])) return;
    $c=myfc_agenda_context();
    if (!$c) { acf_add_validation_error('','O vínculo com o agendamento expirou. Reabra pelo Acolhimento.'); return; }
    $linked=(int)get_post_meta($c['id'],'_myfc_ficha_id',true);
    $target=absint($_POST['_acf_post_id']??$_POST['post_ID']??0);
    if ($linked && $linked!==$target) acf_add_validation_error('','Este agendamento já está vinculado a outra ficha. Reabra pelo Acolhimento.');
});
add_action('acf/save_post',function($ficha){
    if (get_post_type($ficha)!=='ficha_clinica'||empty($_POST['myfc_agenda'])) return;
    $c=myfc_agenda_context(); if ($c) myfc_agenda_gravar($c['id'],(int)$ficha,$c['status']);
},30);
add_action('edit_form_top',function($post){if($post->post_type==='ficha_clinica')echo myfc_agenda_hidden();});
add_filter('acf/load_field/key=field_myfc_agendamentos_aviso',function($field){
    $id=absint($_GET['myfc_ficha']??$_GET['post']??0);
    $field['message']='Os agendamentos vinculados pelo Acolhimento aparecem abaixo. Os registros manuais são mantidos separadamente.';
    if (!$id||get_post_type($id)!=='ficha_clinica'||!current_user_can('edit_post',$id)) return $field;
    $q=new WP_Query(['post_type'=>'prontuario','post_status'=>['publish','private','draft'],'posts_per_page'=>-1,'meta_key'=>'_myfc_ficha_id','meta_value'=>$id,'orderby'=>'ID','order'=>'DESC']);
    $html='<div class="myfc-presenca-historico">';
    if (!$q->posts) $html.='<p>Nenhum agendamento vinculado.</p>';
    foreach($q->posts as $p){if(!current_user_can('edit_post',$p->ID))continue;$s=myfc_agenda_status($p->ID);$html.='<p><strong>'.esc_html($p->post_title).'</strong><br>Situação: '.esc_html(ucfirst($s)).' · Agendamento #'.(int)$p->ID.'</p>';}
    $field['message'].=$html.'</div>'; return $field;
});
add_action('wp_ajax_myfc_agenda_listar',function(){
    check_ajax_referer('myfc_presenca','nonce'); if(!current_user_can('edit_posts'))wp_send_json_error(['message'=>'Acesso negado.'],403);
    $date=sanitize_text_field(wp_unslash($_POST['data']??''));
    $d=DateTime::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)wp_send_json_error(['message'=>'Data inválida.'],400);
    $q=new WP_Query(['post_type'=>'prontuario','post_status'=>['publish','private','draft'],'s'=>$d->format('d/m/Y'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);$rows=[];
    foreach($q->posts as $p){if(!current_user_can('edit_post',$p->ID))continue;$f=(int)get_post_meta($p->ID,'_myfc_ficha_id',true);$identity=function_exists('myod_cal_patient_for_record')?myod_cal_patient_for_record($p->ID):['name'=>'','reason'=>''];$rows[]=['id'=>$p->ID,'titulo'=>$p->post_title,'patient_name'=>$identity['name'],'reason'=>$identity['reason'],'ficha'=>$f,'url'=>$f?add_query_arg('myfc_ficha',$f,home_url('/')).'#myfc-home':'','status'=>myfc_agenda_status($p->ID),'nonce'=>wp_create_nonce('myfc_agenda_'.$p->ID)];}
    wp_send_json_success($rows);
});
add_action('wp_ajax_myfc_presenca',function(){
    check_ajax_referer('myfc_presenca','nonce');$id=absint($_POST['id']??0);$f=(int)get_post_meta($id,'_myfc_ficha_id',true);
    if(!$f)wp_send_json_error(['message'=>'Selecione ou cadastre a ficha primeiro.'],400);
    $r=myfc_agenda_gravar($id,$f,sanitize_key($_POST['situacao']??''));if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()],400);wp_send_json_success();
});
add_action('wp_footer',function(){
 if(!current_user_can('edit_posts'))return;
 ?>
 <script>
 (function(){
 const list=document.getElementById('lista'),filter=document.getElementById('filtro');if(!list||!filter)return;
 const ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce=<?php echo wp_json_encode(wp_create_nonce('myfc_presenca')); ?>;
 let generation=0,writing=false;const records=new Map();
 function context(link,card){const r=records.get(card?.dataset.agendaId);if(!r)return;const u=new URL(link.href);u.searchParams.set('myfc_agenda',r.id);u.searchParams.set('myfc_agenda_nonce',r.nonce);u.searchParams.set('myfc_situacao',card.dataset.pending||r.status);link.href=u.href;}
 document.addEventListener('click',function(e){const link=e.target.closest('.myfc-inline-panel a');if(link)context(link,link.closest('.paciente-card'));},true);
 function note(card,text){let p=card.querySelector('.myfc-presenca-feedback');if(!p){p=document.createElement('p');p.className='myfc-presenca-feedback';p.setAttribute('aria-live','polite');card.append(p);}p.textContent=text;}
 const statusLabels={agendado:'Agendado',atendido:'Atendido',remarcado:'Remarcado',desmarcou:'Desmarcou',faltou:'Faltou',atendimento_atrasado:'Atendimento atrasado',aguardando:'Aguardando'};
 function show(card,status){const b=card.querySelector('b');if(b){b.textContent=statusLabels[status]||status;b.style.color=status==='atendido'?'#287443':status==='faltou'?'#b53f3f':'#805500';}}
 let timer;function schedule(){if(writing)return;clearTimeout(timer);timer=setTimeout(load,100);}
 async function load(){const ticket=++generation;try{const response=await fetch(ajax,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'myfc_agenda_listar',nonce,data:filter.value})});const result=await response.json();if(ticket!==generation)return;if(!response.ok||!result.success)throw new Error(result.data?.message||'Falha ao carregar presença.');
 writing=true;observer.disconnect();records.clear();list.replaceChildren();
 result.data.forEach(r=>{records.set(String(r.id),r);const card=document.createElement('div');card.className='paciente-card';card.dataset.agendaId=r.id;card.dataset.patientName=r.patient_name||'';const h=document.createElement('h4');h.textContent=r.patient_name?r.titulo.replace(/^(\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}).*$/, '$1 — '+r.patient_name):r.titulo;const p=document.createElement('p');p.append('Status: ',document.createElement('b'));const buttons=document.createElement('div');
 const fichaButton=document.createElement('button');fichaButton.type='button';fichaButton.className='btn-status btn-ficha';fichaButton.textContent='Ficha / Verificar';buttons.append(fichaButton);
 const label=document.createElement('label');label.htmlFor='myfc-status-'+r.id;label.textContent='Alterar status';label.style.cssText='display:block;margin:12px 0 6px';const select=document.createElement('select');select.id=label.htmlFor;select.className='myfc-status-select';select.style.cssText='display:block;width:100%;min-height:44px';
 ['agendado','atendido','remarcado','desmarcou','faltou','atendimento_atrasado'].forEach(value=>{const option=document.createElement('option');option.value=value;option.textContent=statusLabels[value];select.append(option);});if(r.status==='aguardando'){const legacy=document.createElement('option');legacy.value='aguardando';legacy.textContent='Aguardando (registro anterior)';legacy.disabled=true;select.prepend(legacy);}select.value=r.status;buttons.append(label,select);card.append(h);if(r.reason){const reason=document.createElement('p');reason.textContent='Motivo: '+r.reason;card.append(reason);}card.append(p,buttons);show(card,r.status);
 if(r.ficha){const a=document.createElement('a');a.className='my-btn';a.href=r.url;a.textContent='Ver ficha vinculada';card.append(a);}list.append(card);});
 if(!result.data.length){const p=document.createElement('p');p.textContent='Sem atendimentos para esta data.';list.append(p);}observer.observe(list,{childList:true});writing=false;
 }catch(error){writing=false;observer.disconnect();list.textContent=error.message;observer.observe(list,{childList:true});}}
 // Salva a selecao com o mesmo vinculo e auditoria da presenca.
 document.addEventListener('change',async function(e){const b=e.target.closest('.myfc-status-select');if(!b)return;e.stopImmediatePropagation();const card=b.closest('.paciente-card'),r=records.get(card?.dataset.agendaId);if(!r)return;const status=b.value;
 if(!r.ficha){card.dataset.pending=status;const panel=card.querySelector('.myfc-inline-panel');if(!panel||panel.hidden)card.querySelector('.btn-ficha').click();note(card,'Status ainda não salvo. Para registrar '+statusLabels[status]+', escolha a ficha correta ou cadastre uma nova. O vínculo e o status serão gravados ao salvar a ficha.');return;}
 b.disabled=true;try{const response=await fetch(ajax,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'myfc_presenca',nonce,id:r.id,situacao:status})});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.data?.message||'Falha ao salvar.');r.status=status;delete card.dataset.pending;show(card,status);note(card,'Status salvo na ficha vinculada.');}catch(error){b.value=r.status;note(card,error.message);}finally{b.disabled=false;}
 },true);
 const observer=new MutationObserver(function(m){if(m.some(x=>x.target===list))schedule();});observer.observe(list,{childList:true});filter.addEventListener('change',schedule);window.addEventListener('pageshow',schedule);load();
 })();
 </script>
 <?php
},70);

function myfc_agenda_status($id) {
    $saved = get_post_meta($id, '_myfc_presenca', true);
    if ($saved) return $saved;
    $status = sanitize_key(get_post_meta($id, 'status', true));
    if (in_array($status, ['agendado','aguardando','atendido','remarcado','desmarcou','faltou','atendimento_atrasado'], true)) return $status;
    $content = wp_strip_all_tags(str_replace(['<br>','<br/>','<br />'], "\n", (string)get_post_field('post_content', $id)));
    return preg_match('/(?:^|\R)\s*Status:\s*Agendado\b/iu', $content) ? 'agendado' : 'aguardando';
}
add_action('save_post_prontuario', function($id) {
    if (wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
    if (!get_post_meta($id, '_myfc_presenca', true) && myfc_agenda_status($id) === 'agendado') {
        update_post_meta($id, '_myfc_presenca', 'agendado');
        update_post_meta($id, 'status', 'agendado');
    }
}, 30);

