# Organização modular do painel administrativo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Facilitar encontrar as funções do painel por funcionalidade, extraindo suas ações PHP e 33 funções JavaScript sem mudar o comportamento.

**Architecture:** `admin/index.php` conserva bootstrap, autorização, preparação das telas e renderização. Um despachante estático inclui os manipuladores por funcionalidade no mesmo escopo e ordem atuais; cinco scripts clássicos consomem uma configuração de página serializada pelo PHP. Os serviços de negócio existentes continuam em `services/`.

**Tech Stack:** PHP existente, PDO/PostgreSQL, JavaScript clássico, Node.js e os testes Playwright/Chrome existentes; nenhuma dependência nova ou migração.

**Spec:** [Desenho aprovado](../specs/2026-11-13-organizacao-modulos-administrativos-design.md).

## Global Constraints

- "Manter PHP, PostgreSQL, os serviços atuais e o modelo de publicação existente."
- "Manter a entrada `admin/index.php`, suas URLs e seus contratos HTTP."
- "Não há migração de banco, mudança de framework, alteração de autenticação/permissões, redesenho de telas ou nova funcionalidade nesta etapa."
- "Os nomes globais usados por `onclick`, `onchange` e outros atributos HTML serão preservados."
- "Os scripts permanecem clássicos, com ordem explícita de carregamento e inicialização."
- "Não será introduzido bundler ou dependência npm."
- "Não será usado reset do checkout, reaplicação do schema na base em uso ou inclusão de alterações alheias em um commit de refatoração."
- Trabalhar sobre o checkout local, incluindo as alterações anteriores de AD, documentação e limpeza. Salvar seu inventário/hashes fora do repositório antes de editar; comparar o resultado com esse estado, não apenas com `HEAD`.
- Testes de banco exigem `APP_ENV=development`, host local e base temporária com prefixo `docgov_admin_architecture_test_`; nenhuma suíte pode operar sobre a base original configurada.
- Não criar commits automáticos que misturem mudanças anteriores com extrações. Quando não houver separação confiável, deixar o trabalho local revisável e registrar essa condição.

## Review Focus

- Indicadores de ação simultâneos e condições AD repetidas: preservar a primeira resposta, os efeitos e a ordem anteriores; cobrir nas tarefas 1 e 2.
- Acesso direto a módulos internos: responder 404 antes de bootstrap ou escrita; cobrir na tarefa 2.
- `__DIR__` após mover uploads/limpeza/configuração: manter a raiz física e impedir que a extração alcance outro arquivo; cobrir nas tarefas 1 e 2.
- Falha de validação seguida de renderização: preservar título, tipo, tags, hierarquia e conteúdo do formulário; cobrir nas tarefas 1 e 3.
- Configuração com aspas, acentos e `</script>`: preservar dados e funções públicas sem executar conteúdo nem duplicar inicialização; cobrir na tarefa 3.

---

## Arquivos e interfaces

### Backend

Modificar `admin/index.php` apenas nas fronteiras de despacho e carregamento de scripts/configuração. Criar `admin/dispatch.php` e os 29 manipuladores abaixo. Os números identificam ordem de execução, não nomes de arquivo:

| Ordem | Arquivo em `admin/modules/` | Condição preservada |
| --- | --- | --- |
| 1 | `structure/actions/permanent-delete.php` | `POST structure_delete_action` |
| 2 | `structure/actions/archive.php` | `POST structure_action` |
| 3 | `subject-workspaces/actions/transition.php` | `POST subject_workspace_action` |
| 4 | `subject-workspaces/actions/save.php` | `POST save_subject_workspace` |
| 5 | `authentication/actions/test-connection.php` | Primeiro `POST test_ad_connection` |
| 6 | `users/actions/replicate.php` | Primeiro `POST replicate_ad_users` |
| 7 | `settings/actions/save.php` | `POST save_system_settings` |
| 8 | `authentication/actions/test-connection-legacy.php` | Segundo `POST test_ad_connection` |
| 9 | `authentication/actions/health.php` | `POST test_ad_health` |
| 10 | `users/actions/details.php` | `POST get_user_details` |
| 11 | `audit/actions/export.php` | Presença de `GET export_ad_audit_logs` |
| 12 | `users/actions/replicate-legacy.php` | Segundo `POST replicate_ad_users` |
| 13 | `authentication/actions/delete-domain.php` | `POST delete_ad_domain` |
| 14 | `authentication/actions/create-domain.php` | `POST create_ad_domain` |
| 15 | `authentication/actions/save-settings.php` | `POST save_ad_settings` |
| 16 | `tags/actions/manage.php` | `POST tag_admin_action` |
| 17 | `users/actions/create.php` | `POST create_user` |
| 18 | `users/actions/grant-permission.php` | `POST save_direct_user_permission` |
| 19 | `users/actions/revoke-permission.php` | `POST remove_direct_user_permission` |
| 20 | `groups/actions/manage.php` | `POST group_action` |
| 21 | `documents/actions/batch-upload.php` | `POST save_doc` e `batch_upload=1` |
| 22 | `documents/actions/save.php` | `POST save_doc` |
| 23 | `documents/actions/recover-submission.php` | Condição original de recuperação após erro, incluindo `isset($titulo)` |
| 24 | `documents/actions/transition.php` | `POST workflow_quick_action` |
| 25 | `documents/actions/trash.php` | `POST document_trash_action` |
| 26 | `documents/actions/batch.php` | `POST batch_action` |
| 27 | `structure/actions/save-category.php` | `POST save_category` |
| 28 | `structure/actions/save-subcategory.php` | `POST save_subcategory` |
| 29 | `structure/actions/save-subject.php` | `POST save_subject` |

**Contrato de inclusão:** o `if ($isLogged)` existente conserva seu local; seu primeiro trecho, até antes de `CARREGAR EDIÇÃO DE ENTIDADES DA HIERARQUIA`, passa a incluir o despachante. O carregamento GET de edição/detalhes permanece depois dele no arquivo original. Preservar o código entre blocos, comentários úteis, variáveis, condições e encerramentos de requisição; nenhuma mudança de regra ou consolidação dos dois caminhos AD.

**Contexto comum:** `$pdo: PDO`, `$loggedUser: array`, `$currentAdminUserId: int`, os booleanos de atividade/capacidades já calculados, `$message: string`, `$errorMessage: string`, `$failedDocumentSubmission: ?array`, `$editDoc: ?array`, `$adConfig: array`, `$appSettings: array` e as instâncias de serviço hoje inicializadas no topo. Variáveis intermediárias produzidas por um bloco continuam disponíveis aos blocos seguintes e à renderização.

**Fronteiras novas:** `DOCGOV_ADMIN_REQUEST === true` identifica a inclusão pela entrada administrativa; `DOCGOV_PROJECT_ROOT` é o caminho absoluto da raiz, definido pela entrada a partir de `dirname(__DIR__)`. Definir uma vez antes do despacho. O despachante e cada manipulador começam por recusar a ausência/valor inválido do marcador com 404 e `exit`, antes de qualquer outro efeito. O marcador não substitui a autenticação, as permissões ou CSRF existentes.

`admin/dispatch.php` usa apenas includes literais, na ordem da tabela, sem função/closure ao redor, mantendo o escopo do chamador. Nos manipuladores, substituir os caminhos antigos `__DIR__ . '/../...'` por `DOCGOV_PROJECT_ROOT . '/...'`; conferir cada ocorrência. Não modificar caminhos HTTP de formulário, JSON ou `Location`.

### Frontend

Criar `admin/partials/client-config.php` e os cinco scripts. O partial exige o mesmo marcador interno e emite uma única atribuição `window.DocGovAdminConfig = <JSON>;`, com `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR`. Não gerar HTML com concatenação de valores sem escape.

| Chave de `DocGovAdminConfig` | Fonte PHP e forma |
| --- | --- |
| `hierarchy.map` | `$hierarchyMap` |
| `hierarchy.documentSubcategories` | `$mapSubcatsParaDocumento` |
| `hierarchy.documentSubjects` | `$mapAssuntosParaDocumento` |
| `hierarchy.subjectSubcategories` | `$mapSubcatsParaAssunto` |
| `filters.category`, `.subcategory`, `.subject` | `$filterCat`, `$filterSubcat`, `$filterAssunto` |
| `tags.catalog`, `.inactiveNameKeys`, `.selectedIds` | `$tagCatalog`, `$inactiveTagNameKeys`, `$editDocumentTagIds` |
| `tags.newNames` | `$failedDocumentSubmission !== null ? $requestedNewTagNames : []` |
| `editor.category`, `.subcategory`, `.subject` | `$parentCatName ?? ''`, `$selAssItem['subcategoria_nome'] ?? ''`, `$selAssItem['nome'] ?? ''` |
| `editor.contents` | `$docsAssList ?? []` |
| `editor.initialType`, `.initialStructuredContent` | `$selectedContentType ?? 'text'`, `$initialStructuredContent ?? []` |
| `editor.hierarchy` | `{category: $editDoc['categoria_id'] ?? null, subcategory: $editDoc['subcategoria_id'] ?? null, subject: $editDoc['assunto_id'] ?? null}` |

| Script em `assets/admin/` | Funções públicas preservadas |
| --- | --- |
| `navigation.js` | `toggleSubmenu`, `toggleMobileSidebar`, `toggleMainSidebar` |
| `document-list.js` | `openDocumentRow`, `closeActionMenus`, `toggleActionMenu`, `toggleSelectAll`, `updateBatchToolbar`, `onFilterCategoryChange`, `onFilterSubcategoryChange` |
| `document-editor.js` | `setDocumentHierarchyHelper`, `onCategoryChange`, `onSubcategoryChange`, `toggleFormContent`, `updateCodePreview`, `toggleVideoSource`, `updateVideoFilePreview`, `getVideoEmbedUrl`, `updateVideoUrlPreview`, `updateFilePreview`, `initDocumentTags`, `initBatchFileUpload`, `setEditorMode`, `markEditorDirty`, `saveEditorLayoutState` |
| `structure.js` | `ncSwitchType`, `ncLoadSubcatsForAssunto`, `toggleTreeNode`, `expandAllTreeNodes`, `collapseAllTreeNodes`, `filterTreeNodes` |
| `groups.js` | `filterTeamMemberOptions`, `selectTeamMember` |

**Ordem:** no local do script principal atual, carregar configuração → `navigation.js` → `document-list.js` → `structure.js` → `document-editor.js`, como scripts clássicos sem `async`/`defer` novos. O `DOMContentLoaded` que inicializa editor, tags, upload, hierarquia e estrutura fica em `document-editor.js`, depois da definição de suas dependências. `groups.js` substitui o pequeno script inline na mesma condição e posição da tela de membros. Preservar as demais bibliotecas e os scripts existentes dos partials.

Preservar as declarações de estado necessárias, incluindo `EditorState`, `gridInstance`, `NC_TYPES` e os aliases de mapas. Cada alias deve apontar para a chave correspondente da configuração. Manter cada listener/efeito de inicialização uma única vez. Usar `filemtime()` nos caminhos novos de assets, pelo mesmo padrão já usado no painel.

---

### Task 1: Caracterização HTTP e infraestrutura de regressão isolada

**Files:** criar `scratch/test_admin_architecture.php` e `scratch/test_admin_architecture.js`; nenhuma alteração de produção nesta tarefa.

**Interfaces:**
- PHP: CLI `php scratch/test_admin_architecture.php --record <arquivo.json>` registra a caracterização; `--compare <arquivo.json>` compara a mesma matriz; `--browser` acrescenta o navegador; `--regression` acrescenta as regressões existentes selecionadas. Saída 0 apenas quando todos os checks solicitados passam; falha sai com código diferente de 0.
- PHP provisiona uma base temporária, fixtures e sessões próprias, escreve um manifesto temporário e inicia o Node com `DOCGOV_ADMIN_FIXTURE_FILE=<caminho absoluto>`. O manifesto contém `database`, `sessionDirectory`, `artifactDirectory`, `users`, `csrfToken`, `categoryId`, `subcategoryId`, `subjectId`, `groupId`, `documentIds` e o modo solicitado. Tokens/cookies não entram no snapshot nem no stdout.
- Node usa `DOCGOV_PHP` ou `php`, inicia seu próprio servidor em `127.0.0.1`/porta livre, aponta o PHP para o diretório de sessão do manifesto e encerra somente os processos que criou. Navegador usa `DOCGOV_PLAYWRIGHT_PACKAGE`/`DOCGOV_BROWSER_PATH` existentes. Nenhum servidor já aberto é reutilizado.

- [x] **Step 1: Registrar a matriz de caracterização no teste Node.** Cobrir GET sem sessão (redirecionamento ao login), conta sem acesso ao painel (redirecionamento ao portal), gestor sem módulo global (403), administrador delegado, CSRF inválido, páginas de documentos/editor/equipes/estrutura, criação de rascunho, recuperação do formulário inválido e mudança de membro. Acrescentar casos com `test_ad_connection` + `replicate_ad_users`, dois indicadores administrativos não terminantes e `save_doc` + `batch_upload=1`; registrar status, `Location`, tipo de resposta, campos estáveis de JSON/formulário e efeitos persistidos relevantes.
- [x] **Step 2: Implementar o driver PHP e fixtures dessa matriz.** Usar o padrão de base exclusiva de `scratch/test_nested_groups.php`, com host local e ambiente `development`; carregar `schema.sql` somente na base nova. Criar administrador, delegado, gestor, leitor, equipe, árvore e documentos fictícios. Gerar sessões no diretório temporário compartilhado com o servidor. Limpar em `finally` conexões, base, sessões e somente arquivos de fixture criados pela suíte, mesmo após falha do Node. O snapshot normaliza apenas IDs de fixture, datas variáveis e versões de cache; não oculta erros ou dados de formulário.
- [x] **Step 3: Capturar o comportamento anterior à refatoração.** Executar `php scratch/test_admin_architecture.php --record "$env:TEMP\docgov-admin-architecture-before.json"`. Esperado: todos os cenários aprovados, snapshot sem segredos, base temporária removida e hash de `admin/index.php` registrado como identificação da origem. Estes são testes de caracterização de comportamento existente: devem passar antes da mudança.
- [x] **Step 4: Conferir a repetibilidade.** Executar `php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json"`. Esperado: zero diferenças de contrato, fixtures/processos limpos e nenhuma mudança no banco original. Corrigir o isolamento antes de extrair código.
- [x] **Step 5: Criar checkpoint revisável.** Guardar inventário e hashes do checkout anterior fora do repositório. Revisar somente os dois testes novos; registrar um commit deles apenas se não incorporar alterações anteriores. Não tratar a captura como homologação do ambiente publicado.

### Task 2: Extrair os manipuladores PHP por funcionalidade

**Files:** modificar `admin/index.php`; criar `admin/dispatch.php` e os 29 arquivos da tabela Backend; ampliar os dois testes da tarefa 1.

**Interfaces:** consumir contexto e constantes da seção Backend; produzir o mesmo estado compartilhado e os mesmos contratos HTTP. Os arquivos não expõem novas funções/classes públicas nem novas rotas de aplicação.

- [x] **Step 1: Acrescentar testes das novas fronteiras.** Para cada arquivo interno planejado, exercitar GET/POST direto com corpo de ação válido e exigir 404, sem mudança de usuários, configurações, documentos ou arquivo sentinela. No caso de upload em lote com `id` preenchido e CSRF válido, exigir 422/JSON e zero documentos criados; CSRF inválido exige 419/JSON. Conferir os mesmos resultados na caracterização dos indicadores simultâneos e da recuperação de submissão. Incluir fixture própria de arquivo para verificar que limpeza e upload continuam limitados aos caminhos originais.
- [x] **Step 2: Extrair os 29 blocos sem mudar suas regras.** Criar constantes de contexto, guarda dos arquivos, lista estática de includes e corrigir somente caminhos físicos relativos à raiz. Manter o `if ($isLogged)`, as cargas GET seguintes, os dois blocos repetidos de cada ação AD, as variáveis compartilhadas e o corpo dos tratamentos de erro. Documentar serviços/variáveis de entrada e estado produzido no cabeçalho de cada arquivo. Não mover consultas de tela nem unir operações semelhantes.
- [x] **Step 3: Executar sintaxe e comparação HTTP.** Aplicar `php -l` em `admin/index.php`, `admin/dispatch.php` e cada manipulador; executar `php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json"`. Esperado: sintaxe aprovada, zero diferenças na matriz anterior, arquivos internos recusando acesso e nenhum efeito fora das fixtures.
- [x] **Step 4: Revisar o diff da extração.** Comparar cada bloco extraído com o snapshot local da tarefa 1, aceitando apenas guarda, cabeçalho, indentação e adaptação explícita de caminho. Conferir particularmente `documents/actions/recover-submission.php`, `batch-upload.php`, o arquivo de CSV e todos os caminhos de certificados/storage. Criar checkpoint; não incluir no commit mudanças de AD anteriores ao recorte.

### Task 3: Extrair as funções JavaScript e sua configuração de página

**Files:** modificar `admin/index.php`; criar `admin/partials/client-config.php` e os cinco scripts da tabela Frontend; ampliar `scratch/test_admin_architecture.js` e o manifesto de fixtures do driver PHP.

**Interfaces:** consumir `window.DocGovAdminConfig` exatamente com as chaves da seção Frontend; produzir as 33 funções globais existentes. Nenhum script novo interpreta nomes de arquivo/trechos de JavaScript vindos do usuário.

- [x] **Step 1: Escrever a regressão de configuração e carregamento.** Em uma página de editor com fixture contendo aspas, acentos e `</script><script>window.unexpectedCode=true</script>`, exigir dados recuperados literalmente e `window.unexpectedCode !== true`. Exigir uma única configuração, cada script externo esperado carregado uma vez, as funções públicas da página disponíveis e zero erros de console/rede local. Essa expectativa dos scripts novos deve falhar antes da extração.
- [x] **Step 2: Extrair os scripts conforme a tabela de funções.** Gerar o partial de configuração com escape e guarda; trocar as interpolações pelos aliases correspondentes. Carregar os scripts na ordem definida e manter o script de membros na condição original. Conservar listeners, constantes e inicialização do editor; não mover scripts dos demais partials ou a configuração do Tailwind.
- [x] **Step 3: Executar sintaxe e regressão de navegador.** Aplicar `node --check` aos cinco arquivos e `php -l` ao partial/entrada; executar `php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json" --browser`. Esperado: comparação HTTP aprovada e todos os cenários visuais funcionais abaixo aprovados, sem inicialização duplicada.
- [x] **Step 4: Conferir interação em computador e celular.** Na suíte de navegador, usar 1440×900 e 390×844; cobrir tema claro/escuro nas telas afetadas. Exercitar filtros e menus da listagem, seleção em lote, hierarquia por ID com nomes repetidos, tipos de conteúdo, tags, preview de arquivo/vídeo, estado do editor, árvore e pesquisa/seleção de membros. Verificar um único efeito por clique, recuperação do formulário e a sequência `setup=subject`/`setup=document`. Capturas ficam apenas no diretório temporário da suíte.
- [x] **Step 5: Criar checkpoint revisável.** Conferir o destino das 33 funções contra o inventário anterior e revisar as mudanças do arquivo PHP como carregamento/configuração. Manter as alterações preexistentes identificadas no snapshot e fora de commits inseparáveis.

### Task 4: Publicar o mapa de funções e validar o recorte completo

**Files:** criar `admin/README.md`; modificar `README.md`, `docs/README.md`, `docs/referencia/arquitetura.md`, `docs/desenvolvimento/testes.md`, o desenho e este plano. Se houver execução concluída, criar `docs/historico/validacoes/2026-11-13-modulos-administrativos.md` e vinculá-la em `docs/historico/README.md`.

**Interfaces:** mapa de tarefa → entrada/indicador HTTP → manipulador → serviço → script; cada link aponta para arquivo existente. Não duplicar implementação nem usar números de linha como índice estável.

- [x] **Step 1: Escrever o mapa do painel.** Incluir salvar/revisar/publicar documento, upload/lixeira/lote, criar/arquivar estrutura, processo do assunto, usuários/permissão direta, membros/subgrupos/capacidades, conta técnica/saúde/domínios AD, configurações, tags, exportação CSV e localização das funções de interface. Explicar o fluxo de entrada e a dependência transitória do escopo compartilhado.
- [x] **Step 2: Atualizar os pontos de navegação e a orientação de testes.** Vincular `admin/README.md` nos índices e na arquitetura, documentar o novo runner e separar instruções de execução de resultados datados. Remover a afirmação de que as ações e funções JavaScript permanecem no arquivo monolítico; conservar a descrição das consultas/templates que continuam nele.
- [x] **Step 3: Executar a validação final isolada.** Executar `php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json" --browser --regression`. O modo de regressão executa, com `DB_NAME` temporário herdado, somente `test_permissions.php`, `test_permission_api.php`, `test_hierarchy_resolution.php`, `test_document_approval_workflow.php`, `test_duplicate_document_creation.php`, `test_subject_workspace.php`, `test_group_access_admin.php`, `test_system_access_groups.php`, `test_system_settings_admin.php`, `test_structure_permanent_deletion.php`, `test_tags.php` e `test_access_integrity.php`, preparando suas fixtures necessárias na base exclusiva. Execute separadamente `node scratch/test_batch_directory_ldap.js --domain-login` e `node scratch/test_batch_directory_ldap.js`, que usam LDAP simulado e suas próprias guardas. Não execute sem adaptação as suítes antigas que dependem de IDs fixos da base local, como `test_admin_documents_filters_browser.js` (subcategoria 107); os cenários equivalentes pertencem ao runner novo.
- [x] **Step 4: Conferir integridade e documentação.** Executar `git diff --check`, verificar links/âncoras e todos os caminhos dos assets/handlers novos. Comparar hashes com o inventário inicial para comprovar que arquivos fora do recorte e documentos/arquivos reais foram preservados. Verificar que bases, sessões e processos temporários foram removidos mesmo em um teste de falha provocado dentro da suíte.
- [x] **Step 5: Registrar apenas evidência obtida.** Atualizar os estados do desenho/plano conforme o trabalho realmente concluído; registrar comandos, ambiente, resultados, limites e ausência de autenticação corporativa real. Revisar o diff inteiro antes de relatar conclusão; qualquer suíte não executada deve permanecer identificada, e não ser marcada como aprovada.

## Auto-revisão do plano

- Escopo: as ações e as 33 funções possuem destino; consultas/templates e serviços grandes continuam explicitamente fora do recorte.
- Contratos: constantes, contexto compartilhado, chaves de configuração, ordem dos 29 manipuladores e ordem dos scripts estão definidos uma vez e consumidos pelas tarefas.
- Riscos: os cinco pontos de Review Focus têm verificações nas tarefas indicadas; nenhuma correção de regra foi misturada à extração.
- Isolamento: baseline, regressões, HTTP e navegador apontam para base/sessões/processos temporários; scripts operacionais e AD real ficam fora da execução.
- Execução: quatro tarefas sequenciais. Recomenda-se execução nesta conversa, porque todas dependem do mesmo `admin/index.php` e de um estado de checkout com mudanças anteriores; uma revisão independente ao final confere o recorte inteiro.

## Estado

Plano aprovado e executado nesta conversa, com revisão independente final. Primeira etapa concluída em 13/11/2026; [evidências e limites](../../historico/validacoes/2026-11-13-modulos-administrativos.md). A refatoração permanece no checkout local, preservando o trabalho anterior.
