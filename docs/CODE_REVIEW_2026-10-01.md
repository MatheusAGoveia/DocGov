# Code review inicial — 01/10/2026

## Escopo e limites

Referência de histórico: `d904224` (`fix: configura e preserva conta de leitura do Active Directory`). A revisão inspecionou o checkout local: bootstrap, sessão, rotas de estrutura/documentos/permissões, gateways de arquivos, serviços de autorização, workflow, mídia, configuração, migrações, testes e documentação.

O objetivo foi iniciar uma revisão geral e estruturar documentação. Não foi uma auditoria exaustiva de todas as linhas nem uma homologação do servidor publicado. Não houve execução dos utilitários operacionais, alteração de contas, aplicação de migrações ou teste de credenciais reais.

O checkout recebeu alterações concorrentes durante o trabalho, especialmente importação de usuários, diálogos e alterações no painel/portal. Essas alterações foram preservadas e não estão aprovadas por esta revisão. Números de linha referem-se ao momento da inspeção e podem mudar com esse trabalho; use também o bloco/método identificado.

## Resumo dos achados

Prioridades: **P1** = tratar antes de ampliar/publicar o ambiente afetado; **P2** = corrigir no próximo ciclo. P0 não foi identificado no escopo inspecionado; isso não comprova sua ausência fora do escopo.

| ID | Prioridade | Achado | Evidência | Estado |
| --- | --- | --- | --- | --- |
| CR-01 | P1 | Utilitários privilegiados executáveis sem guarda HTTP | Código confirmado; exposição no servidor não verificada | Aberto |
| CR-02 | P1 | Senhas de emergência literais em arquivos versionados | Literais e rastreamento Git confirmados; uso atual não verificado | Aberto |
| CR-03 | P2 | Runner de migrações omite a migração 026 | Lista do runner e dependências do código confirmadas | Aberto |
| CR-04 | P2 | Cor escolhida no painel não é enviada à conta | Ausência da meta CSRF e retorno antecipado confirmados | Aberto, já relatado em setembro |
| CR-05 | P2 | API de documentos não vincula imagens do artigo | Divergência API/painel e política de mídia confirmadas | Aberto |
| CR-06 | P2 | API confirma documento antes dos metadados/histórico editoriais | Ordem de commit e captura da falha confirmadas | Aberto |

## CR-01 — Bloquear execução HTTP dos utilitários privilegiados

**Locais:** `scratch/set_super_admin_db.php:2`, `scratch/set_emergency_password.php:3`, `scratch/update_user_pass.php:2` e `scratch/run_migrations.php:2`.

Esses scripts incluem a conexão e executam alterações sem exigir `PHP_SAPI === 'cli'`, autorização de usuário ou CSRF. O primeiro promove/reativa contas; os dois seguintes substituem hashes de senha; o runner aplica DDL. No checkout não foi encontrada regra de servidor bloqueando `scratch/`; o `.htaccess` encontrado está em `storage/`.

**Impacto:** quando o repositório é publicado como raiz web sem bloqueio dessa pasta, uma requisição pode executar alterações administrativas com as credenciais da aplicação. A exploração no ambiente real depende da exposição HTTP e da configuração do servidor, que não foram verificadas. A política de manutenção não substitui esse controle, pois pode estar desativada.

**Correção proposta:** retirar utilitários da distribuição pública, bloquear a pasta no servidor e impor uma guarda CLI antes de qualquer bootstrap. Operações sensíveis também devem validar o ambiente/alvo e abandonar usuários fixos como parâmetro implícito.

**Aceite:** em uma implantação de teste, requisições sem sessão aos scripts retornam 403/404 e não alteram usuários/schema; execução CLI permanece disponível somente pelo procedimento autorizado. Verificar também acesso direto a `data/`, `database/`, `config/`, `.git/` e arquivos protegidos de `storage/`, especialmente em IIS.

**Validação realizada:** leitura estática e inventário das regras versionadas. Os scripts não foram executados.

## CR-02 — Remover senhas literais e avaliar rotação das credenciais aplicadas

**Locais:** `scratch/set_emergency_password.php:7` e `scratch/update_user_pass.php:6`. Ambos constam em `git ls-files`.

Os utilitários definem uma senha literal, calculam seu hash e a aplicam a uma conta nominal. Também imprimem a senha no resultado. `ActiveDirectoryAuthService::tryBreakGlassEmergencyLogin()` aceita o hash local de uma conta ativa com papel administrativo quando acionado pelo fluxo de emergência.

**Impacto:** se algum desses valores ainda corresponder a uma senha aplicada, quem possui acesso ao código ou ao resultado do script conhece uma credencial administrativa. Remover o literal do arquivo atual não revoga uma senha já usada nem remove cópias do histórico. Não foi testado se os valores são válidos atualmente.

**Correção proposta:** remover valores e saída de senha; receber credencial por um mecanismo operacional seguro, sem incluir o segredo na linha de comando/log. Identificar se esses utilitários foram usados e rotacionar as credenciais afetadas quando aplicável. Registrar o procedimento de acesso de emergência e revisar o tratamento do histórico do repositório.

**Aceite:** nenhum segredo real permanece no código/artefatos distribuídos; o procedimento de emergência não expõe senha em logs; valores anteriormente aplicados deixam de autenticar após rotação controlada.

**Validação realizada:** confirmação estática dos literais e do rastreamento Git. Os valores não são reproduzidos neste relatório.

## CR-03 — Incluir a migração de visibilidade no processo de atualização

**Locais:** `scratch/run_migrations.php:5` (lista), `database/migrations/026_subject_visibility.sql`, `api/subjects.php:56` e `services/PermissionService.php` (consultas de visibilidade).

O runner termina em `025_document_inline_media.sql`, enquanto a aplicação usa `subjects.visibility`. Uma instalação com base anterior à 026 que siga somente esse runner pode receber “Migrações concluídas” e continuar sem a coluna exigida. `schema.sql` já contém a coluna, portanto a divergência afeta principalmente atualizações.

**Impacto:** falha de consultas de assuntos/autorização após atualização incompleta. O documento de visibilidade orienta a aplicação manual da 026, mas o executor ainda não acompanha o código.

**Correção proposta:** acrescentar a 026 à sequência aplicável, reconciliar os arquivos históricos e registrar versões aplicadas. Não substituir esse ajuste pela execução do schema em uma base com dados.

**Aceite:** uma base isolada compatível com a versão anterior, sem `visibility`, é atualizada pelo procedimento oficial; a coluna e constraint passam a existir, assuntos antigos ficam privados e reaplicação prevista não quebra a base. Lista do executor e documentação devem concordar.

**Validação realizada:** comparação estática do runner, schema, migração e consumidores. Nenhuma migração foi aplicada.

## CR-04 — Disponibilizar CSRF ao seletor de tema administrativo

**Locais:** cabeçalho de `admin/index.php:3346` e `partials/theme_dropdown.php:129`.

O painel gera `$csrfToken`, mas não inclui `meta[name="csrf-token"]` no cabeçalho. `setPortalAccentTheme()` atualiza a tela e o `localStorage`, depois retorna quando essa meta não existe, antes do POST de persistência. O partial também é usado nas páginas do portal.

**Impacto:** a mudança parece salva no navegador atual, mas não atualiza a preferência da conta para outro navegador. O achado de [30/09](CODE_REVIEW_2026-09-30.md) continua válido por inspeção estática.

**Correção proposta:** incluir a meta com token escapado como nas páginas autenticadas do portal e verificar o resultado da persistência.

**Aceite:** selecionar uma cor no painel gera POST com token válido; a preferência é recuperada em outra sessão/navegador. O teste deve conferir persistência, além da mudança visual.

**Validação realizada:** busca da meta no painel e leitura do partial. O teste de navegador de setembro não foi repetido.

## CR-05 — Vincular a mídia de artigos também no salvamento pela API

**Locais:** bloco de persistência de `api/documents.php:366`, `document-media.php:36`, `services/DocumentInlineMediaService.php:70` e chamadas em `admin/index.php:1893`/`:1933`.

O upload de artigo cria `document_inline_media` sem `document_id`. O painel chama `bindReferenced()` na transação do documento, mas a API salva HTML sem chamar o serviço de vínculo/remoção. O sanitizador permite referências ao gateway de mídia, portanto esse HTML pode chegar ao salvamento.

**Impacto:** uma imagem enviada pelo autor e salva no documento pela API continua sem vínculo. Outros leitores são bloqueados por `document-media.php`, que permite mídia sem documento somente ao autor; após um dia, uma próxima execução de `pruneAbandoned()` pode removê-la. A API responde sucesso mesmo sem assegurar a mídia do artigo.

**Correção proposta:** compartilhar o salvamento de conteúdo entre painel e API, vinculando referências na mesma transação e removendo arquivos descartados após o commit. Validar proprietário, existência e documento de origem da mídia.

**Aceite:** upload seguido de salvamento via API cria o vínculo; um leitor autorizado vê a imagem; outro sem acesso é bloqueado; limpeza de abandonados preserva a mídia vinculada. Referências de terceiros são recusadas e uma falha de vínculo desfaz o salvamento.

**Validação realizada:** comparação estática dos caminhos de persistência. Não foi feita criação de documentos ou upload para reprodução.

## CR-06 — Tornar persistência editorial atômica na API

**Local:** `api/documents.php:371` e bloco seguinte de `applyTransitionMetadata()`/`record()`/`notifyForTransition()`.

A API faz commit do documento e só depois atualiza metadados editoriais e histórico. Se essas etapas falharem, a exceção é apenas registrada, e a resposta ainda informa sucesso. O painel coloca essas operações antes do commit, mas também captura exceções nesse bloco; seu comportamento de falha deve ser incluído na correção e nos testes, sem assumir atomicidade apenas pela posição das chamadas.

**Impacto:** um documento pode mudar de estado sem revisor/aprovador/histórico correspondente. Por exemplo, concluir a revisão pode responder sucesso com `reviewed_by` ainda vazio, impedindo aprovação posterior; publicar pode persistir sem os dados de aprovação. A ocorrência concreta depende de falha nessas operações, mas o caminho de sucesso parcial está explícito no código.

**Correção proposta:** confirmar documento, metadados e histórico na mesma transação. Definir a estratégia de notificação separadamente, com registro de falha e possibilidade de reenvio, sem tratar falha editorial como sucesso integral.

**Aceite:** falha controlada na gravação de metadados/histórico retorna erro e mantém o documento no estado anterior. Transição bem-sucedida registra estado, ator e histórico coerentes. Falha de notificação tem comportamento definido e observável.

**Validação realizada:** leitura da ordem de transação e tratamento de exceção. Não houve injeção de falha no banco.

## Observações de arquitetura e documentação

- `admin/index.php` tem mais de oito mil linhas e combina ações, persistência e HTML. Planejar extração gradual por fluxo, com regressão, em vez de uma reescrita ampla.
- O manual Windows descrevia login automático, mas `login.php` exige credenciais explícitas e não chama o método integrado. A documentação recebeu uma nota sobre o comportamento atual.
- Documentos antigos de capacidades associavam edição à publicação direta; o workflow atual exige revisão e aprovação administrativa. Essa orientação foi atualizada.
- `database/README.md` descreve a modelagem inicial. Foi acrescentada uma entrada para os guias atuais e ressalva sobre schema/seed.
- Há bibliotecas locais e Tailwind carregado por CDN. Não há build automatizado definido em `package.json`; disponibilidade externa e montagem dos assets precisam entrar na homologação da distribuição.
- Alguns testes têm guardas locais e rollback; outros criam fixtures persistentes ou dependem de usuários existentes. A suíte ainda precisa de isolamento e runner.

## Verificações e ordem de execução

Executado: sintaxe de **137 arquivos PHP**, sintaxe de **19 JavaScript próprios** e `git diff --check`. Sem erros nesses checks; houve aviso de normalização de fim de linha em alteração concorrente. Veja o [guia de testes](TESTES.md) para o escopo e reprodução.

Não executado: suíte funcional de PostgreSQL, navegador, AD real, teste da exposição HTTP, migrações ou validação da importação em andamento.

Ordem sugerida:

1. Tratar CR-01/CR-02 e confirmar exposição/configuração do ambiente afetado.
2. Corrigir CR-03 e homologar atualização de base existente.
3. Consolidar o salvamento compartilhado para CR-05/CR-06, com testes de autorização e falha.
4. Corrigir CR-04 e validar persistência entre sessões.
5. Consolidar runner/CI, revisão da importação e manutenção contínua da documentação.

Para cada achado, registrar responsável, commit da correção, comandos/resultados e evidência de aceite. Todos permanecem abertos nesta etapa de documentação; não houve alteração no comportamento da aplicação por este trabalho.
