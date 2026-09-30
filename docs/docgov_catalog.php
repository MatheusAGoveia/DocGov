<?php

/**
 * Conteúdo-fonte da documentação publicada em TI > DocGov.
 * Não incluir senhas, tokens, nomes de contas técnicas nem parâmetros secretos.
 */
return [
    [
        'name' => 'Comece aqui', 'slug' => 'comece-aqui',
        'description' => 'Mapa do sistema, público-alvo e caminho de leitura.',
        'docs' => [[
            'title' => 'Guia de entrada do DocGov', 'slug' => 'guia-de-entrada', 'section' => 'documents',
            'description' => 'O que é o DocGov, onde cada recurso fica e qual guia consultar primeiro.',
            'tags' => ['DocGov', 'Início'],
            'html' => <<<'HTML'
<h2>O que é o DocGov</h2>
<p>O DocGov é o portal interno de documentação da Prefeitura. Ele organiza conhecimento em <strong>categoria → subcategoria → assunto → conteúdo</strong>, controla quem pode consultar ou editar cada ramo e mantém um fluxo de revisão antes da publicação. O conteúdo pode ser texto formatado, arquivo, link, código, vídeo, fluxo ou organograma. Uma mesma página de assunto reúne as seções correspondentes.</p>
<h2>Para quem é este espaço</h2>
<ul><li><strong>Leitores:</strong> pesquisam, consultam, baixam documentos permitidos e marcam favoritos.</li><li><strong>Editores:</strong> criam e atualizam conteúdos nos assuntos em que possuem permissão de edição.</li><li><strong>Administradores de recurso:</strong> revisam, aprovam, organizam a estrutura e gerenciam acessos dentro do seu escopo.</li><li><strong>Administradores globais:</strong> gerenciam configurações, autenticação e operação do portal.</li></ul>
<h2>Roteiro recomendado</h2>
<ol><li>Leia <strong>Arquitetura e funcionamento</strong> para entender os componentes e a estrutura.</li><li>Para tarefas diárias, siga <strong>Uso do portal</strong> e <strong>Editor e tipos de conteúdo</strong>.</li><li>Antes de publicar, consulte <strong>Fluxo editorial</strong> e <strong>Permissões e equipes</strong>.</li><li>Para operação, comece em <strong>Configurações</strong>, <strong>LDAP e autenticação</strong> e <strong>Janela de manutenção</strong>.</li><li>Para mudanças técnicas, use <strong>Implantação e alterações</strong>, <strong>Backup e recuperação</strong> e <strong>Diagnóstico e segurança</strong>.</li></ol>
<p><strong>Regra de segurança:</strong> este catálogo explica procedimentos, mas não guarda credenciais, senhas LDAP, chaves, endereços internos sensíveis nem cópias de arquivos de configuração locais. Consulte o cofre de segredos e os responsáveis de infraestrutura para valores de produção.</p>
<p><strong>Atualização:</strong> após qualquer mudança de interface, banco ou política de acesso, revise o assunto correspondente, submeta a nova versão para revisão e valide a página publicada em desktop e celular.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Arquitetura e funcionamento', 'slug' => 'arquitetura-e-funcionamento',
        'description' => 'Componentes, dados, arquivos e ciclo de uma requisição.',
        'docs' => [[
            'title' => 'Arquitetura técnica e mapa de componentes', 'slug' => 'arquitetura-tecnica', 'section' => 'manuals',
            'description' => 'Como PHP, PostgreSQL, serviços, API e arquivos se relacionam.',
            'tags' => ['DocGov', 'Arquitetura'],
            'html' => <<<'HTML'
<h2>Visão geral</h2>
<p>A aplicação é implementada principalmente em PHP, com PostgreSQL como fonte persistente de usuários, hierarquia, conteúdos, permissões, configurações e auditoria. A interface usa JavaScript e CSS em <code>assets/</code>; o editor formatado utiliza Quill. Arquivos enviados ficam no armazenamento local protegido e são servidos por controladores PHP que verificam acesso.</p>
<table><thead><tr><th>Camada</th><th>Onde encontrar</th><th>Responsabilidade</th></tr></thead><tbody><tr><td>Portal</td><td><code>index.php</code>, <code>ver_conteudo.php</code></td><td>Navegação, busca e leitura autorizada.</td></tr><tr><td>Administração</td><td><code>admin/index.php</code></td><td>Estrutura, documentos, usuários, permissões e configurações.</td></tr><tr><td>API</td><td><code>api/</code></td><td>Operações usadas pela interface; exige sessão e autorização, com CSRF em alterações.</td></tr><tr><td>Regras de negócio</td><td><code>services/</code></td><td>Fluxo editorial, acesso, LDAP, sanitização, tags e estrutura.</td></tr><tr><td>Persistência</td><td><code>config/db.php</code>, <code>database/</code></td><td>Conexão PDO, esquema e migrações PostgreSQL.</td></tr><tr><td>Arquivos</td><td><code>storage/</code></td><td>Documentos e imagens gerenciados pelo sistema; backup separado do banco.</td></tr></tbody></table>
<h2>Fluxo de leitura</h2>
<p>O navegador solicita a página; a sessão identifica o usuário; <code>PermissionService</code> valida a hierarquia e o nível efetivo; o banco fornece metadados e conteúdos publicados; a página monta a seção do assunto. Uma URL direta não dispensa a checagem de permissão. Arquivos não devem ser expostos por acesso direto ao diretório de armazenamento.</p>
<h2>Fluxo de edição</h2>
<p>O editor envia dados ao painel ou à API; o backend valida CSRF, permissão, tipo, tamanho e formato; HTML é sanitizado por <code>RichTextSanitizer</code> e estruturas gráficas por <code>StructuredContentService</code>; a transição editorial é registrada em histórico; somente a versão publicada aparece ao leitor comum.</p>
<h2>Modelo de dados</h2>
<p>As tabelas centrais são <code>categories</code>, <code>subcategories</code>, <code>subjects</code> e <code>documents</code>. <code>document_sections</code> define as seções; <code>tags</code> e <code>document_tags</code> apoiam a busca; <code>permissions</code> e <code>groups</code> definem acesso; <code>system_settings</code> guarda parâmetros; <code>document_workflow_history</code> e tabelas de auditoria registram ações. Veja <code>database/schema.sql</code> antes de alterar o modelo.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Uso do portal', 'slug' => 'uso-do-portal',
        'description' => 'Navegação, pesquisa, favoritos, perfil e leitura.',
        'docs' => [[
            'title' => 'Como consultar e encontrar documentos', 'slug' => 'como-consultar', 'section' => 'tutorials',
            'description' => 'Passo a passo do usuário leitor no portal.', 'tags' => ['DocGov', 'Tutorial'],
            'html' => <<<'HTML'
<h2>Entrar e localizar</h2>
<ol><li>Acesse o endereço institucional do DocGov e autentique-se com a conta disponibilizada pela TI. O método pode ser login LDAP ou, quando configurado com segurança, autenticação integrada do Windows.</li><li>Na tela inicial, abra uma categoria, depois uma subcategoria e um assunto. A trilha no topo mostra o caminho atual e permite voltar.</li><li>Use a busca para encontrar títulos, descrições e tags. Filtre quando necessário e abra o resultado permitido para sua conta.</li><li>Na página do assunto, escolha a seção: Documentos, Tutoriais, Manuais, Arquivos, Códigos, Vídeos, Fluxo, Organograma, FAQ ou outras seções ativas.</li></ol>
<h2>Ler, baixar e acompanhar</h2>
<p>Abra o conteúdo para ler a descrição, as tags e o material principal. Arquivos disponíveis podem ser baixados pelo comando exibido na página. Marque os documentos mais usados como favoritos para acessá-los pela navegação principal; o favorito é pessoal e não altera a permissão do documento. Consulte o sino para notificações do fluxo e o menu do perfil para sua conta e saída.</p>
<h2>Quando algo não aparece</h2>
<p>Primeiro limpe filtros e confirme que está no assunto correto. Se um item publicado continuar ausente, peça ao responsável do conteúdo para verificar status, atividade de todos os níveis da hierarquia e permissão efetiva da sua conta/equipe. Não tente usar uma URL direta como substituto da autorização.</p>
<h2>Boas práticas</h2>
<ul><li>Confira data, responsável e versão antes de seguir uma orientação operacional.</li><li>Relate links quebrados, conteúdo desatualizado e dados sensíveis exibidos indevidamente ao responsável pelo assunto.</li><li>Ao terminar em computador compartilhado, use a opção de sair do sistema.</li></ul>
HTML,
        ]],
    ],
    [
        'name' => 'Editor e tipos de conteúdo', 'slug' => 'editor-e-tipos-de-conteudo',
        'description' => 'Criação de assuntos, seções, imagens e materiais.',
        'docs' => [[
            'title' => 'Criar e atualizar conteúdos', 'slug' => 'criar-e-atualizar', 'section' => 'manuals',
            'description' => 'Uso do editor e escolha da seção apropriada.', 'tags' => ['DocGov', 'Editor'],
            'html' => <<<'HTML'
<h2>Antes de criar</h2>
<p>Verifique se já existe documento equivalente. Escolha um assunto específico, um título descritivo e um resumo que responda “para que serve?”. A permissão <strong>Editar</strong> no assunto (ou herdada de nível superior) é necessária para criar conteúdo; administrar a estrutura exige escopo apropriado.</p>
<h2>Seções e formatos</h2>
<table><thead><tr><th>Necessidade</th><th>Seção recomendada</th></tr></thead><tbody><tr><td>Orientação contínua</td><td>Documentos ou Manuais — editor formatado.</td></tr><tr><td>Instruções executáveis</td><td>Tutoriais — passos numerados e resultado esperado.</td></tr><tr><td>Perguntas recorrentes</td><td>Perguntas Frequentes — pergunta como subtítulo, resposta logo abaixo.</td></tr><tr><td>Anexo para baixar</td><td>Arquivos — arquivo com descrição, formato e versão.</td></tr><tr><td>Trecho técnico copiável</td><td>Códigos — selecione linguagem e explique pré-requisitos.</td></tr><tr><td>Etapas ou estrutura</td><td>Fluxo do Processo ou Organograma — nós estruturados.</td></tr><tr><td>Referência externa</td><td>Links — URL válida com contexto.</td></tr></tbody></table>
<h2>Texto e imagens</h2>
<p>Use títulos hierárquicos, parágrafos curtos, listas para procedimentos, tabelas para comparações e links com texto significativo. No editor visual, carregue a imagem pelo recurso próprio; dê texto alternativo e confirme o alinhamento em tela estreita. O layout de mídia permite mover a imagem pela área da própria imagem e ajustar seu tamanho. Evite colar conteúdo vindo de fontes não confiáveis e nunca adicione senhas, tokens ou dados pessoais desnecessários.</p>
<h2>Salvar e publicar</h2>
<p>Salve como rascunho, revise ortografia e URLs, envie para revisão, aguarde parecer e aprovação. Uma edição de conteúdo publicado pode voltar ao fluxo de revisão; confirme o estado mostrado no painel antes de avisar os leitores. Associe tags específicas, sem exagerar, para facilitar a busca transversal.</p>
<h2>Limites e validação</h2>
<p>O backend sanitiza o HTML e valida tipos de mídia e tamanho. Arquivos comuns têm limite de 25 MB; vídeos enviados, 250 MB; código, 1 MB. Se o envio falhar, valide formato, tamanho, conexão e permissão de escrita em <code>storage/documents/</code>.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Fluxo editorial e publicação', 'slug' => 'fluxo-editorial-e-publicacao',
        'description' => 'Rascunho, revisão, aprovação, histórico e arquivamento.',
        'docs' => [[
            'title' => 'Revisar, aprovar e manter publicações', 'slug' => 'revisar-e-publicar', 'section' => 'manuals',
            'description' => 'Estados do conteúdo e responsabilidades de cada papel.', 'tags' => ['DocGov', 'Publicação'],
            'html' => <<<'HTML'
<h2>Estados</h2>
<table><thead><tr><th>Estado</th><th>Significado</th><th>Ação seguinte</th></tr></thead><tbody><tr><td>Rascunho</td><td>Trabalho em preparação, não visível ao leitor comum.</td><td>Editar e enviar para revisão.</td></tr><tr><td>Em revisão</td><td>Aguardando parecer do administrador do recurso.</td><td>Revisar; aprovar ou solicitar ajustes.</td></tr><tr><td>Publicado</td><td>Disponível para quem pode ver o assunto.</td><td>Monitorar validade e atualizar quando necessário.</td></tr><tr><td>Inativo/lixeira</td><td>Retirado da consulta corrente.</td><td>Restaurar ou administrar conforme política.</td></tr></tbody></table>
<h2>Procedimento de publicação</h2>
<ol><li>O editor conclui título, resumo, corpo, links, anexos e tags; salva como rascunho.</li><li>O editor escolhe <strong>Enviar para revisão</strong> e informa contexto da mudança.</li><li>O administrador do recurso verifica conteúdo, permissão, acessibilidade, precisão técnica e eventual informação restrita; registra parecer de revisão.</li><li>Se estiver correto, aprova e publica. Caso contrário, devolve com motivo claro. O histórico guarda autor, ação, estado anterior, estado novo e nota.</li><li>Após publicar, confira o resultado no portal com uma conta leitora autorizada, inclusive em celular.</li></ol>
<p>Rascunhos e itens em revisão têm prazo de aprovação registrado; o serviço pode expirar conteúdos não aprovados. Publicações não expiram por esse mecanismo. Não trate o status “salvo” como sinônimo de “publicado”.</p>
<h2>Alteração e retirada</h2>
<p>Ao mudar uma orientação existente, preserve o contexto e explique o motivo no envio à revisão. Para retirar material obsoleto, use a ação administrativa de arquivar/lixeira em vez de apagar diretamente no banco. Antes de eliminar permanentemente, verifique links recebidos, anexos e exigências de retenção documental.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Permissões e equipes', 'slug' => 'permissoes-e-equipes',
        'description' => 'Acesso por hierarquia, usuários, grupos e auditoria.',
        'docs' => [[
            'title' => 'Conceder acesso com menor privilégio', 'slug' => 'conceder-acesso', 'section' => 'manuals',
            'description' => 'Como planejar, aplicar e verificar permissões.', 'tags' => ['DocGov', 'Segurança'],
            'html' => <<<'HTML'
<h2>Princípio</h2>
<p>Autenticação identifica a pessoa; autorização decide o que ela pode fazer. Estar no Active Directory não concede, por si só, acesso editorial. A permissão pode ser atribuída a um usuário ou equipe na categoria, subcategoria ou assunto e é herdada pelos níveis abaixo. O acesso efetivo considera as regras aplicáveis; uma regra mais fraca no filho não revoga automaticamente acesso mais forte herdado do pai.</p>
<table><thead><tr><th>Nível</th><th>Uso típico</th></tr></thead><tbody><tr><td>Visualizar</td><td>Consultar conteúdos publicados.</td></tr><tr><td>Editar</td><td>Criar e alterar conteúdos nos assuntos autorizados.</td></tr><tr><td>Administrar</td><td>Gerir regras do recurso e atuar no fluxo de aprovação do escopo.</td></tr></tbody></table>
<h2>Concessão segura</h2>
<ol><li>Defina a necessidade e o prazo; prefira uma equipe quando várias pessoas exercem a mesma função.</li><li>Escolha o menor ramo que cubra o trabalho. Conceder na categoria inteira amplia a herança para todas as subcategorias e assuntos.</li><li>No painel de permissões, selecione pessoa/equipe, recurso e nível; salve.</li><li>Use o diagnóstico de acesso efetivo e teste com a conta destinatária. Confirme também a capacidade de ver o pai, a subcategoria e o assunto.</li><li>Registre a solicitação e revise acessos após mudança de função, desligamento ou reorganização.</li></ol>
<p>Administradores globais recebem acesso amplo pelo papel da conta e não precisam de regras individuais. A administração de usuários e capacidades do sistema é separada da permissão sobre documentos. Alterações de permissão são auditadas; evite editar diretamente a tabela <code>permissions</code>.</p>
<h2>Investigação de “acesso negado”</h2>
<p>Confirme que usuário e equipe estão ativos; que o conteúdo está publicado; que categoria, subcategoria e assunto estão ativos; e que a regra foi aplicada ao ID correto. Consulte o diagnóstico de acesso efetivo para identificar a regra de origem, especialmente quando há herança.</p>
HTML,
        ]],
    ],
    [
        'name' => 'LDAP e autenticação', 'slug' => 'ldap-e-autenticacao',
        'description' => 'Active Directory, LDAPS, domínios, importação e login integrado.',
        'docs' => [[
            'title' => 'Configurar e diagnosticar Active Directory', 'slug' => 'configurar-active-directory', 'section' => 'manuals',
            'description' => 'Parâmetros, testes e cuidados com credenciais e certificados.', 'tags' => ['DocGov', 'LDAP', 'Segurança'],
            'html' => <<<'HTML'
<h2>Como funciona</h2>
<p>O login corporativo usa <code>ActiveDirectoryAuthService</code>. A aplicação resolve domínio e usuário, conecta ao LDAP/LDAPS, autentica com a senha recebida e sincroniza dados básicos da conta no DocGov. A senha corporativa não deve ser persistida na base ou em logs. No primeiro acesso válido, a conta pode ser provisionada como leitora; direitos sobre recursos são concedidos separadamente no painel.</p>
<h2>Pré-requisitos</h2>
<ul><li>Extensão PHP <code>ldap</code> habilitada, conectividade ao servidor de diretório e DNS funcional.</li><li>LDAPS com certificado confiável, cadeia de CA válida e relógio do servidor sincronizado.</li><li>URI, DN base, domínio DNS, domínio NetBIOS e certificado informados pela equipe de infraestrutura.</li><li>Conta técnica de leitura com privilégios mínimos apenas quando for necessária importação, consulta ou replicação de usuários.</li></ul>
<h2>Configurar pelo painel</h2>
<ol><li>Abra <strong>Administração → Configurações → Active Directory</strong> com uma conta autorizada.</li><li>Cadastre ou revise cada domínio: nome, URI LDAPS, DN base, domínio DNS/NetBIOS, caminho do certificado, estado ativo e domínio principal.</li><li>Se for importar/replicar contas, configure a identidade da conta técnica e o segredo por mecanismo protegido do ambiente. Nunca coloque o segredo neste documento ou em Git.</li><li>Use o teste de conexão/autenticação do painel; valide um usuário de teste de cada domínio e os cenários de erro.</li><li>Confirme que uma identidade de domínio explícito desconhecido é recusada e que conta desativada, senha expirada ou bloqueio retornam orientação apropriada.</li></ol>
<p><strong>Variáveis de ambiente:</strong> <code>AD_AUTH_ENABLED</code>, <code>AD_DEFAULT_DOMAIN</code>, <code>AD_LDAP_URI</code>, <code>AD_BASE_DN</code>, <code>AD_DNS_DOMAIN</code>, <code>AD_NETBIOS_DOMAIN</code>, <code>AD_CA_CERTIFICATE</code>, <code>AD_SERVICE_BIND_DN</code>, <code>AD_SERVICE_BIND_PASSWORD</code> e <code>AD_NETWORK_TIMEOUT</code>. O domínio adicional configurado em código usa prefixo <code>AD_SAUDE_</code>. As configurações salvas no painel podem sobrepor os padrões de ambiente; confira qual fonte está ativa antes de diagnosticar.</p>
<h2>Login integrado do Windows</h2>
<p>É uma opção separada, controlada por <code>AD_INTEGRATED_WINDOWS_ENABLED</code> e pela configuração do painel. Só habilite após proteger o site no IIS/Apache com Kerberos/NTLM e validar que <code>REMOTE_USER</code> vem exclusivamente do servidor web, nunca de cabeçalho fornecido pelo cliente. Use HTTPS e teste com contas autorizadas e não autorizadas. A orientação detalhada está em <code>docs/windows-integrated-auth.md</code>.</p>
<h2>Falhas comuns</h2>
<p>“Servidor indisponível”: teste DNS, porta, certificado/CA e firewall. “Importação não configurada”: valide conta técnica e permissões de leitura. “Credenciais inválidas”: confirme domínio escolhido, formato da identidade e estado da conta, sem registrar a senha. Preserve acesso administrativo de contingência conforme política interna e teste-o antes de uma mudança de LDAP.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Configurações do sistema', 'slug' => 'configuracoes-do-sistema',
        'description' => 'Identidade visual, sessão, CORS, suporte e parâmetros persistidos.',
        'docs' => [[
            'title' => 'Administrar as configurações com segurança', 'slug' => 'administrar-configuracoes', 'section' => 'manuals',
            'description' => 'O que o painel controla e como validar mudanças.', 'tags' => ['DocGov', 'Configuração'],
            'html' => <<<'HTML'
<h2>Fontes de configuração</h2>
<p><code>config/db.php</code> usa variáveis de ambiente do serviço e, em desenvolvimento, <code>config/local.php</code> ignorado pelo Git. <code>SystemSettingsService</code> fornece padrões e carrega ajustes persistidos em <code>system_settings</code>. Os ajustes de AD salvos pela interface podem prevalecer sobre os valores iniciais do ambiente. Nunca publique <code>config/local.php</code>, exportações de configurações com segredos ou o conteúdo de <code>system_settings</code> sem filtragem.</p>
<h2>Campos administráveis</h2>
<table><thead><tr><th>Grupo</th><th>Exemplos</th><th>Validação após salvar</th></tr></thead><tbody><tr><td>Identidade</td><td>Nome do portal, organização, descrição, logo e tema padrão.</td><td>Confira login, cabeçalho, telas estreitas e modo escuro.</td></tr><tr><td>Atendimento</td><td>E-mail de suporte e fuso horário.</td><td>Abra o link de suporte e confira horários exibidos.</td></tr><tr><td>Sessão</td><td>Expiração por inatividade (15–480 minutos).</td><td>Teste entrada, permanência e expiração.</td></tr><tr><td>CORS</td><td>Origens, métodos e uso de credenciais.</td><td>Permita somente origens necessárias; não use <code>*</code> com credenciais.</td></tr><tr><td>Operação</td><td>Janela de manutenção, área, modo, mensagem e progresso.</td><td>Verifique período e retorno do serviço.</td></tr><tr><td>Autenticação</td><td>Domínios AD e login integrado.</td><td>Teste conexão e contas de cenários diferentes.</td></tr></tbody></table>
<h2>Procedimento de alteração</h2>
<ol><li>Registre estado atual, motivo e responsável. Para mudança de alto risco, agende uma janela.</li><li>Altere um grupo de parâmetros por vez e salve pelo painel, que valida entradas e audita a operação.</li><li>Abra uma sessão de teste e confira portal, administração, documento, API afetada e telas móveis.</li><li>Em caso de regressão, restaure os valores anteriores pelo painel ou execute o plano de recuperação aprovado.</li></ol>
<p>O usuário pode escolher tema de destaque e modo claro/escuro na interface, conforme recursos habilitados. O tema padrão do portal é definido pela administração; a preferência de visualização individual não altera permissões nem conteúdo.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Janela de manutenção', 'slug' => 'janela-de-manutencao',
        'description' => 'Planejamento, bloqueio, comunicação, contingência e encerramento.',
        'docs' => [[
            'title' => 'Planejar e executar manutenção', 'slug' => 'executar-manutencao', 'section' => 'tutorials',
            'description' => 'Runbook da janela de manutenção e sua recuperação.', 'tags' => ['DocGov', 'Operação'],
            'html' => <<<'HTML'
<h2>Planejamento</h2>
<ol><li>Defina mudança, risco, responsável, referência de chamado, início e fim em horário local e estratégia de reversão.</li><li>Faça backup consistente do PostgreSQL e dos arquivos, verifique espaço livre e confirme acesso administrativo/contingência.</li><li>No painel de Configurações, habilite a manutenção, informe motivo, título e mensagem para os usuários, prazo, antecedência do aviso e áreas atingidas: portal, administração, API e/ou arquivos.</li><li>Escolha <strong>bloqueio total</strong> para indisponibilidade ou <strong>somente leitura</strong> quando consultas puderem continuar. Teste o comportamento antes da intervenção.</li></ol>
<h2>Durante a janela</h2>
<p>O bloqueio é aplicado apenas quando a opção está habilitada e o relógio está entre início e fim. Em somente leitura, requisições GET/HEAD/OPTIONS podem continuar; operações de escrita são bloqueadas nas áreas selecionadas. O servidor retorna HTTP 503 em bloqueios; APIs recebem JSON, e o navegador é direcionado à página de manutenção. Administradores globais ativos possuem passagem operacional para acompanhar a intervenção. Atualize o progresso e a comunicação no painel.</p>
<h2>Encerrar e validar</h2>
<ol><li>Execute testes de login, busca, visualização, download, criação controlada e API relevante.</li><li>Desative a manutenção no painel e confirme com uma conta não administrativa que o acesso voltou.</li><li>Registre horário real, resultado, eventuais incidentes e necessidade de atualização da documentação.</li></ol>
<p>O fim do horário programado torna o bloqueio inativo, mas não substitui a conferência operacional. Se o painel não estiver acessível durante uma manutenção ativa, existe <code>maintenance-control.php</code>, protegido por autenticação própria de emergência e restrito a encerrar a manutenção. Use-o apenas conforme procedimento institucional; nunca compartilhe sua identidade/credencial em artigos.</p>
<h2>Reversão</h2>
<p>Se um teste essencial falhar, interrompa a implantação, mantenha comunicação de indisponibilidade, restaure código e/ou dados conforme o plano aprovado e repita os testes. Não restaure apenas o banco se os arquivos enviados estiverem em versão incompatível.</p>
HTML,
        ], [
            'title' => 'Checklist de janela de manutenção', 'slug' => 'checklist-de-manutencao', 'section' => 'templates',
            'description' => 'Modelo copiável para abertura, execução e encerramento.', 'tags' => ['DocGov', 'Operação'],
            'html' => <<<'HTML'
<h2>Antes</h2><ul><li>☐ Mudança, chamado, responsável e aprovador identificados.</li><li>☐ Início/fim e fuso revisados; aviso enviado.</li><li>☐ Backup do banco e de <code>storage/</code> concluído e verificável.</li><li>☐ Critérios de sucesso, teste de regressão e reversão definidos.</li><li>☐ Conta administrativa/contingência testada.</li></ul>
<h2>Durante</h2><ul><li>☐ Áreas e modo de manutenção corretos.</li><li>☐ Horário de início e versão implantada registrados.</li><li>☐ Migrações e logs acompanhados; progresso comunicado.</li><li>☐ Nenhuma credencial incluída em chamado ou captura de tela.</li></ul>
<h2>Depois</h2><ul><li>☐ Login LDAP, leitura, pesquisa, arquivo, edição e API testados.</li><li>☐ Manutenção desativada e acesso validado por usuário comum.</li><li>☐ Resultado, pendências e documentação atualizados.</li></ul>
HTML,
        ]],
    ],
    [
        'name' => 'Implantação e alterações', 'slug' => 'implantacao-e-alteracoes',
        'description' => 'Ambiente, migrações, mudanças de código e validação.',
        'docs' => [[
            'title' => 'Implantar, atualizar e alterar o DocGov', 'slug' => 'implantar-e-alterar', 'section' => 'manuals',
            'description' => 'Procedimento técnico do repositório até a verificação pós-implantação.', 'tags' => ['DocGov', 'Desenvolvimento'],
            'html' => <<<'HTML'
<h2>Pré-requisitos do ambiente</h2>
<p>Tenha servidor web com PHP e extensões <code>pdo_pgsql</code>, <code>ldap</code> (para AD), <code>mbstring</code>, <code>dom</code> e <code>fileinfo</code>; PostgreSQL; certificado TLS; diretório de armazenamento gravável pelo processo web; e Node.js/NPM para dependências de frontend. Confirme versões efetivas na homologação, pois a presença no desenvolvimento não prova paridade com produção.</p>
<h2>Configuração inicial</h2>
<ol><li>Obtenha o código do repositório por processo aprovado; configure o servidor para servir a aplicação sem expor <code>config/</code>, <code>database/</code>, <code>scratch/</code> ou arquivos de segredo.</li><li>Defina <code>APP_ENV</code>, <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code> e <code>DB_PASS</code> no ambiente do serviço. Para desenvolvimento local, use <code>config/local.php</code> baseado em <code>config/local.php.example</code>; não versione o arquivo real.</li><li>Prepare o banco conforme o procedimento de implantação. <code>database/schema.sql</code> é de criação inicial e contém comandos destrutivos; <strong>não o execute em uma base com dados</strong>. Em atualização, aplique somente as migrações aprovadas e ainda pendentes, após backup e teste em cópia da base.</li><li>Instale dependências do frontend a partir do lockfile com <code>npm ci</code> quando necessário e valide a disponibilidade dos assets no ambiente de destino.</li></ol>
<h2>Como fazer uma alteração de código</h2>
<ol><li>Crie uma ramificação de trabalho e descreva comportamento esperado, permissões e impacto em dados.</li><li>Localize a entrada de interface, a regra em <code>services/</code>, a API/página e as tabelas relacionadas antes de editar. Não coloque autorização apenas no JavaScript.</li><li>Para alteração de esquema, escreva uma nova migração incremental em <code>database/migrations/</code>; não reescreva migrações já aplicadas. Planeje compatibilidade e reversão.</li><li>Atualize testes e esta documentação; execute sintaxe PHP/JS, testes relevantes e jornada real no navegador.</li><li>Revise diferença, segredos, migrações e acessibilidade; faça implantação em janela aprovada quando houver risco operacional.</li></ol>
<p><strong>Atenção ao executor atual:</strong> <code>scratch/run_migrations.php</code> enumera arquivos de migração e percorre todos; confirme idempotência e estado da base antes de usá-lo. Não presuma que ele faz controle de versão por tabela de migrações. Em produção, prefira um plano registrado de scripts incrementais aplicados uma vez.</p>
<h2>Critérios mínimos de liberação</h2>
<p>Login e permissão de uma conta comum, edição/revisão/publicação, pesquisa, leitura/arquivo, tema claro/escuro, telas estreitas e grandes, manutenção e regressão LDAP nos domínios configurados. Registre versão, data, operador e resultado.</p>
HTML,
        ], [
            'title' => 'Exemplo seguro de configuração local', 'slug' => 'exemplo-configuracao-local', 'section' => 'source-code',
            'description' => 'Modelo sem valores reais de banco ou LDAP.', 'tags' => ['DocGov', 'Desenvolvimento'],
            'language' => 'php',
            'code' => <<<'CODE'
<?php
// Somente para desenvolvimento local. Nunca versione config/local.php real.
return [
    'app_environment' => 'development',
    'db_host' => '127.0.0.1',
    'db_port' => '5432',
    'db_name' => 'docgov_dev',
    'db_user' => 'usuario_de_desenvolvimento',
    'db_password' => 'OBTENHA_NO_COFRE_DE_SEGREDOS',
];
CODE,
        ]],
    ],
    [
        'name' => 'Backup e recuperação', 'slug' => 'backup-e-recuperacao',
        'description' => 'PostgreSQL, arquivos, restauração e ensaio de desastre.',
        'docs' => [[
            'title' => 'Proteger e restaurar dados do DocGov', 'slug' => 'proteger-e-restaurar', 'section' => 'manuals',
            'description' => 'Escopo do backup e sequência segura de recuperação.', 'tags' => ['DocGov', 'Backup'],
            'html' => <<<'HTML'
<h2>O que precisa ser protegido</h2>
<p>O banco PostgreSQL contém usuários, estrutura, documentos textuais, permissões, configuração, histórico e auditoria. O diretório <code>storage/</code> contém arquivos e imagens referenciados pelo banco. Um backup de apenas um desses lados pode resultar em links quebrados ou arquivos órfãos. Preserve também a versão implantada do código e uma cópia controlada dos parâmetros de ambiente sem divulgar segredos.</p>
<h2>Rotina de backup</h2>
<ol><li>Defina frequência, retenção, criptografia, local externo e responsáveis conforme a política da Prefeitura.</li><li>Gere um dump consistente do PostgreSQL com ferramenta homologada (<code>pg_dump</code> em formato customizado é uma opção) e capture <code>storage/</code> em um ponto compatível. Para restauração exata, reduza/congele escritas durante a captura ou use procedimento de snapshot consistente.</li><li>Registre data, versão do esquema, tamanho e resultado; verifique integridade do arquivo e mantenha ao menos uma cópia fora do servidor principal.</li><li>Teste periodicamente a restauração em ambiente isolado, incluindo abertura de documentos e download de anexos. Um backup não testado não é evidência de recuperação.</li></ol>
<h2>Restauração</h2>
<ol><li>Declare incidente, comunique indisponibilidade e suspenda novas escritas.</li><li>Escolha o ponto de recuperação e confirme compatibilidade de banco, <code>storage/</code> e código. Preserve evidências do ambiente com falha.</li><li>Restaure em ambiente isolado, aplique permissões do filesystem, conecte com credenciais protegidas e execute verificações de integridade/contagem.</li><li>Teste login, hierarquia, documento publicado, anexos, busca e edição controlada.</li><li>Somente então troque o tráfego para o ambiente recuperado e registre perda de dados estimada e ações preventivas.</li></ol>
<p><strong>Não execute</strong> <code>database/schema.sql</code> na base de produção para “reparar” uma falha: o arquivo remove tabelas antes de recriá-las. Use o plano de restauração e migrações revisadas.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Diagnóstico e segurança', 'slug' => 'diagnostico-e-seguranca',
        'description' => 'Triagem de incidentes, logs, auditoria e testes.',
        'docs' => [[
            'title' => 'Diagnosticar falhas e operar com segurança', 'slug' => 'diagnosticar-falhas', 'section' => 'manuals',
            'description' => 'Mapa de sintomas, evidências e controles essenciais.', 'tags' => ['DocGov', 'Segurança', 'Operação'],
            'html' => <<<'HTML'
<h2>Primeiros cinco minutos</h2>
<ol><li>Registre horário, URL, usuário afetado, ambiente e mensagem exata sem copiar senhas ou dados pessoais.</li><li>Determine se o problema afeta todos ou apenas uma pessoa, um assunto ou uma operação.</li><li>Confira status de PHP/servidor web, PostgreSQL, LDAP, espaço em disco, certificado TLS e estado da manutenção.</li><li>Compare com a última mudança de código, configuração, permissão ou esquema.</li><li>Se houver risco de perda ou vazamento, interrompa escrita e acione o fluxo institucional de incidente.</li></ol>
<table><thead><tr><th>Sintoma</th><th>Verificações iniciais</th></tr></thead><tbody><tr><td>Erro 500 ou tela vazia</td><td>Logs PHP, conexão PDO, extensão faltante, migração pendente.</td></tr><tr><td>Erro 503</td><td>Janela de manutenção ativa, área selecionada e modo.</td></tr><tr><td>Login falha</td><td>DNS/LDAPS, CA, domínio, estado da conta e registros de tentativa AD.</td></tr><tr><td>Documento não aparece</td><td>Status publicado, ramo ativo, permissão efetiva e seção ativa.</td></tr><tr><td>Arquivo não abre</td><td>Registro no banco, existência em <code>storage/</code>, permissão de leitura e controlador de download.</td></tr><tr><td>Editor não salva</td><td>Sessão, CSRF, permissão de edição, validação de HTML/arquivo e logs.</td></tr></tbody></table>
<h2>Segurança operacional</h2>
<ul><li>Conceda acesso pelo menor escopo e revise equipes periodicamente.</li><li>Mantenha TLS no portal e LDAPS com CA validada; não aceite identidade integrada de cabeçalho não confiável.</li><li>Não exponha <code>config/local.php</code>, dumps, logs, <code>storage/</code> ou scripts de manutenção diretamente pela web.</li><li>Não registre senhas, tokens ou dados sensíveis em documentos, capturas de tela, histórico Git ou chamados.</li><li>Faça alterações administrativas pelo painel/API autorizado para preservar validação e auditoria.</li></ul>
<h2>Verificação após correção</h2>
<p>Repita o cenário que falhou, um cenário de sucesso e um de acesso negado; confira logs e histórico, documente causa-raiz e ação preventiva. Quando houver alteração de permissão, teste tanto o usuário autorizado quanto outro sem acesso.</p>
HTML,
        ], [
            'title' => 'Perguntas frequentes de suporte', 'slug' => 'perguntas-frequentes-suporte', 'section' => 'faq',
            'description' => 'Respostas rápidas para dúvidas de leitores, editores e operadores.', 'tags' => ['DocGov', 'Suporte'],
            'html' => <<<'HTML'
<h3>Por que consigo entrar, mas não vejo um assunto?</h3><p>Login e permissão são processos diferentes. Peça ao administrador do recurso para conferir o diagnóstico de acesso efetivo, a atividade do ramo e seu vínculo com a equipe correta.</p>
<h3>Salvei o conteúdo; por que ninguém o vê?</h3><p>Um rascunho não é público. Envie para revisão, aguarde parecer e aprovação e confirme que o status ficou Publicado.</p>
<h3>Posso compartilhar o link direto de um arquivo?</h3><p>Somente com alguém que também possua acesso. O link não substitui autorização; prefira compartilhar a página do documento para manter contexto e versão.</p>
<h3>Qual a diferença entre manutenção total e somente leitura?</h3><p>Total bloqueia solicitações nas áreas escolhidas. Somente leitura permite consultas seguras, mas bloqueia alterações, mantendo resposta 503 para escritas nas áreas afetadas.</p>
<h3>Onde informo a senha do LDAP?</h3><p>Use o mecanismo protegido definido pela infraestrutura e a interface administrativa autorizada; não escreva a senha em documentação ou repositório. A senha pessoal do usuário é digitada apenas no fluxo de autenticação.</p>
<h3>Qual é o primeiro passo antes de atualizar o sistema?</h3><p>Definir impacto e reversão, realizar backup consistente de banco e arquivos, testar em homologação e agendar janela se a mudança puder afetar usuários.</p>
HTML,
        ]],
    ],
    [
        'name' => 'Integrações e API', 'slug' => 'integracoes-e-api',
        'description' => 'Endpoints internos, sessão, CSRF e limites de automação.',
        'docs' => [[
            'title' => 'Entender as APIs internas do DocGov', 'slug' => 'apis-internas', 'section' => 'manuals',
            'description' => 'Contratos de acesso e cuidados antes de automatizar.', 'tags' => ['DocGov', 'API'],
            'html' => <<<'HTML'
<h2>Escopo</h2>
<p>O diretório <code>api/</code> contém endpoints usados pela própria interface, incluindo categorias, subcategorias, assuntos, documentos, permissões e árvore. Eles dependem da sessão do usuário e aplicam verificações de autorização. Operações de alteração exigem token CSRF. Não trate essas rotas como uma API pública estável sem um contrato formal e testes de compatibilidade.</p>
<h2>Regras para integração</h2>
<ol><li>Identifique a rota e o método HTTP no código atual; verifique o JSON retornado e os códigos de erro.</li><li>Use uma identidade autorizada com o menor escopo necessário. Nunca reutilize a sessão ou a conta pessoal de um administrador em automação de produção.</li><li>Respeite CSRF, tipos de conteúdo e limites de upload. Revalide permissão no servidor para cada recurso recebido.</li><li>Trate 401/403/419/503 separadamente: sessão expirada, acesso negado, CSRF e manutenção não são o mesmo erro.</li><li>Para integração permanente, proponha um contrato versionado, autenticação de serviço, limites de taxa, auditoria e testes antes de expor novas rotas.</li></ol>
<p>O CORS do portal é configurável, mas habilitá-lo não concede permissão nem cria um mecanismo de autenticação. Restrinja origens e métodos ao necessário, especialmente quando credenciais forem usadas.</p>
HTML,
        ]],
    ],
];
