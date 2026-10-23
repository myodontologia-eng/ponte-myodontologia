# Integração WordPress — MYLA ASB e conversa
Status: proposta preparada, ainda não publicada.

O backend entra pela incorporação desta PR na main e implantação do Render. Os arquivos PHP desta pasta precisam ser integrados ao plugin MY Odontologia Core; eles não são carregados automaticamente pelo serviço Python.

## Alterações no plugin
1. Fazer uma cópia privada do plugin atual no servidor, sem enviá-la para repositórios ou pastas públicas.
2. Acrescentar o conteúdo dos arquivos myla-asb.php e myla-conversation.php ao plugin (sem repetir as tags de abertura PHP).
3. Na função myod_myla_public_ajax, trocar a chamada de coleta myod_myla_collect($id,$chat_text) por myod_myla_fluent_collect($id,$chat_text).
4. O aceite do início de conversa deve registrar a versão 2026-10-23-conversa-v2 tanto na criação quanto na retomada após novo aceite. Sessões antigas sem esse aceite continuam no fluxo anterior e não enviam mensagens ao modelo.
5. No aviso inicial, informar: “Suas mensagens e um trecho desta conversa são enviados ao Google Gemini, pela conexão do sistema, para a Myla compreender e responder. Não enviamos fichas clínicas ou outras conversas.”
6. A caixa de aceite deve dizer: “Li o aviso e autorizo o processamento das minhas mensagens pelo Google Gemini para este atendimento.”
7. Atualizar o aviso do painel administrativo para refletir o processamento de mensagens aceito pelo visitante.

## Comportamento desta versão
- ASB restrita ao administrador profissional e a fichas que ele pode editar.
- Envio à IA somente da anotação digitada e da data, após aceite por geração; não lê outros campos da ficha.
- SOAP, evolução, plano, financeiro e tarefas aparecem em rascunho editável.
- Cada botão de revisão acrescenta uma linha à seção correspondente; registros anteriores não são sobrescritos.
- A transferência para os campos não salva a ficha. O profissional usa o salvamento normal após conferir.
- Tarefas de agenda ficam pendentes e não reservam horários ou enviam avisos.
- Primeira versão por texto; transcrição de áudio ainda não está incluída.
- Conversa pública contextual usa apenas o histórico da própria sessão com aceite; a confirmação final de agenda permanece no controlador WordPress.
- Telegram mantém permissões e confirmação por botões, com instruções de conversa menos repetitivas.

## Validação
Cinco testes Python passaram para consentimento, contrato, resposta incompleta e ausência de gravação. O teste de interface em DOM simulado passou para consentimento, revisão, preservação das linhas antigas, data, bloqueio de clique duplicado e invalidação de rascunho. PHP e JavaScript passaram na verificação de sintaxe.

A validação em tela, a geração real no Gemini e o teste integrado com Repeatix devem ser feitos com uma ficha fictícia após autorização para publicar. Não considerar esta versão disponível aos usuários até essa validação.
