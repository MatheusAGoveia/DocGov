# Code review profundo antes do lançamento — 01/10/2026

## Parecer e escopo

**A meta é 20.000 usuários simultâneos. A revisão encontrou bloqueadores de segurança, integridade editorial e escala. Ainda não há evidência para aprovar o lançamento nessa capacidade.** O dimensionamento inicial e a homologação estão no [plano de capacidade](PLANO_CAPACIDADE_20000_USUARIOS.md).

Esta entrega contém análise, documentação e medições isoladas. **Não aplica correções ao sistema, configurações, contas, conteúdo ou schema. Cada correção depende da decisão do responsável**, conforme solicitado. As propostas preservam as regras atuais; acesso de emergência, cache privado, conflitos, paginação e entrega eventual de avisos precisam de decisão explícita.

Referência: `e51f80c7a06baaa375a2b5022846060c791ee573`, com o checkout local inspecionado. Alterações concorrentes na importação em lote, testes e artefatos de interface foram preservadas. O servidor publicado não foi acessado. Linhas indicadas correspondem ao checkout desta inspeção.

Áreas examinadas: bootstrap/sessão; PostgreSQL/migrações; portal/busca; painel; permissões/capacidades; AD/importação; documentos/aprovação; workspace; mídia/uploads; entrega de arquivos; notificações/auditoria; frontend PDF/CSS; distribuição/testes. A análise dos caminhos críticos, sintaxe e sondas de serviços não equivale à execução completa de todas as jornadas.

## Evidências obtidas

Resultados brutos sem usuários, senhas, tokens ou conteúdo: [evidencias.json](review/2026-10-01/evidencias.json).

| Verificação | Resultado | Limite |
| --- | --- | --- |
| Sintaxe PHP | 143 arquivos, sem erros | `php -l`, sem executar bootstrap/testes |
| Sintaxe JavaScript próprio | 25 arquivos, sem erros | `node --check`; exclui bundles de terceiros e JS embutido em PHP |
| PostgreSQL local | 18.4; transação confirmada `READ ONLY` | Sem alteração; não descreve produção |
| Base local | 4 categorias, 10 subcategorias, 22 assuntos, 35 documentos, 11 usuários | Insuficiente para benchmark de capacidade |
| Autorização de portal, usuário ativo não global | **285 consultas; aproximadamente 150 ms** | Uma execução dos métodos usados pela página, não HTTP completo |
| Autorização de portal, administrador global | 49 consultas; aproximadamente 16 ms | Perfil/desenho da árvore mudam o custo |
| Leitor sintético, 1.000 assuntos públicos | **20.057 consultas** | SQLite em memória com PermissionService real; quantidade/complexidade, não throughput PostgreSQL |
| Validação editorial com estado antigo | Aceitou `published` com estado atual `draft` e revisão registrada | Fixture em memória; persistência/interleaving reais analisados estaticamente |
| Sanitizador | Removeu script; preservou referência ao gateway de mídia | Sonda pontual, não auditoria completa de XSS |
| Busca representativa | `Seq Scan` em documentos; sem índice de trigramas nas tabelas inspecionadas | EXPLAIN sem ANALYZE, apenas título/descrição, base pequena |
| PDF.js entregue | **3.11.174**, avaliação habilitada por padrão | Asset efetivamente referenciado, não só manifesto npm |

A sonda PostgreSQL abriu conexão própria ao banco local, com leitura obrigatória, timeout de consulta de 5 s e de lock de 500 ms. Não incluiu `config/db.php`, que pode escrever pela expiração editorial. Os timeouts no JSON são controles da sonda, não configuração global aprovada do servidor. A ferramenta ficou fora da distribuição, na pasta local de artefatos da revisão.

Não executados: utilitários privilegiados, migrações, uploads/exclusões, AD real, suítes que escrevem fixtures, exploração de vulnerabilidades, teste HTTP de exposição interna ou carga de 20 mil usuários.

## Prioridades ligadas à arquitetura

**P1:** resolver antes de lançar o ambiente afetado ou declarar capacidade de 20 mil. **P2:** corrigir antes da ampliação relevante ou registrar aceite formal de risco/prazo. Infraestrutura desconhecida é uma pendência de homologação, não um defeito comprovado em produção.

Os seis CR mantêm IDs e detalhes no [review inicial](CODE_REVIEW_2026-10-01.md). Os DR acrescentam a revisão profunda. **Todos permanecem abertos.**

| ID | Prioridade | Achado/risco | Arquitetura |
| --- | --- | --- | --- |
| CR-01 | P1, exposição condicional | Scripts privilegiados sem guarda HTTP | [Entradas](ARQUITETURA.md#componentes-e-requisições) |
| CR-02 | P1, validade não testada | Senhas administrativas literais versionadas | [Identidade](ARQUITETURA.md#mapa-dos-serviços) |
| DR-01 | P1 | Exclusão definitiva pelo visualizador sem validar CSRF | [Arquivos](ARQUITETURA.md#arquivos-e-conteúdo) |
| DR-02 | P1 | PDF.js servido afetado pela CVE-2024-4367 | [Frontend](ARQUITETURA.md#arquivos-e-conteúdo) |
| DR-03 | P1 | Aprovação/salvamento com estado desatualizado | [Aprovação](ARQUITETURA.md#dois-fluxos-de-aprovação) |
| CR-06 | P1 nesta revisão | Persistência editorial sem atomicidade uniforme | [Persistência](ARQUITETURA.md#fronteiras-a-consolidar) |
| DR-04 | P1 para 20 mil | Autorização repete árvore/consultas por recurso | [Autorização](ARQUITETURA.md#autorização) |
| DR-05 | P1 para 20 mil | Expiração com escrita em toda requisição web | [Bootstrap](ARQUITETURA.md#componentes-e-requisições) |
| DR-06 | P1 para 20 mil | Sessão/worker ocupados durante downloads | [Entrega](ARQUITETURA.md#arquivos-e-conteúdo) |
| DR-12 | P1 para múltiplos nós | Sessões, arquivos e conexões compartilhados sem contrato consolidado | [Componentes](ARQUITETURA.md#componentes-e-requisições) |
| CR-03 | P2; bloqueia atualização incompleta | Runner omite migração 026 | [Dados](ARQUITETURA.md#dados) |
| CR-04 | P2 | Tema administrativo não persiste na conta | [Frontend](ARQUITETURA.md#arquivos-e-conteúdo) |
| CR-05 | P2 | API não vincula mídia do artigo | [Mídia](ARQUITETURA.md#arquivos-e-conteúdo) |
| DR-07 | P2 | Busca/listas sem limite e substring sem índice apropriado | [Dados](ARQUITETURA.md#dados) |
| DR-08 | P2 | Painel calcula outras abas e materializa listas/árvores completas | [Painel](ARQUITETURA.md#fronteiras-a-consolidar) |
| DR-09 | P2 | Aviso editorial verifica todos os usuários sincronamente | [Serviços](ARQUITETURA.md#mapa-dos-serviços) |
| DR-10 | P2; decisão de privacidade | Arquivo protegido fresco no cache por uma hora | [Entrega](ARQUITETURA.md#arquivos-e-conteúdo) |
| DR-11 | P2 | Substituição não remove binário anterior após commit | [Storage](ARQUITETURA.md#arquivos-e-conteúdo) |
| DR-13 | P2 e decisão de segurança | Rajada de login e política de emergência sem limites definidos | [Identidade](ARQUITETURA.md#mapa-dos-serviços) |
| DR-14 | P2 | Assets, auditoria e observabilidade sem contrato operacional completo | [Operação](ARQUITETURA.md#arquivos-e-conteúdo) |

## Segurança e integridade antes do lançamento

### DR-01 — Exclusão definitiva por rota antiga

**Fonte:** [ver_conteudo.php](../ver_conteudo.php), linhas 88–97. Compare com a lixeira de [admin/index.php](../admin/index.php), a partir da linha 2008.

O visualizador aceita POST `delete_doc` de administrador global, remove o arquivo e depois executa DELETE. Não valida CSRF, não exige passagem pela lixeira/confirmação nominal e não envolve a operação em transação. Gerar token no início da página não valida essa ação.

**Impacto:** uma requisição forjada que receba a sessão administrativa pode apagar um documento fora dos controles atuais. Falha no DELETE deixa registro apontando para arquivo já removido. `SameSite=Lax` reduz alguns vetores, mas não corrige a validação ausente. Nenhuma exclusão foi executada.

**Proposta para aprovação:** serviço/contrato protegido de exclusão; decidir se a rota deve mover à lixeira ou continuar como exclusão permanente. Essa decisão muda seu comportamento. Validar token, autorização/estado, registrar auditoria e remover binários somente após commit.

**Aceite:** token ausente/inválido não muda banco/arquivo; leitor/editor não exclui; falha de banco preserva o binário; fluxo aprovado mantém confirmação/lixeira/histórico coerentes.

### DR-02 — Vulnerabilidade no PDF efetivamente usado

**Fonte:** [ver_conteudo.php](../ver_conteudo.php), linhas 215 e 394–419; [asset servido](../assets/pdfjs/pdf.min.js); [manifesto](../package.json).

O manifesto declara PDF.js 4.4.168, mas essa página carrega **3.11.174**, com worker local e CMaps dessa versão. `getDocument()` não desativa `isEvalSupported`; o bundle confirma o padrão habilitado. Atualizar somente o lockfile não atualiza esse caminho.

O advisory oficial informa execução de JavaScript no domínio da aplicação ao abrir PDF malicioso nas versões até 4.1.392 com avaliação habilitada; a correção foi publicada em 4.2.67. A versão entregue está afetada. Nenhum PDF de exploração foi aberto. [Advisory Mozilla](https://github.com/mozilla/pdf.js/security/advisories/GHSA-wgrm-67xf-hhpq).

**Proposta para aprovação:** mitigação documentada desativando avaliação e atualização conjunta de biblioteca, worker e CMaps para versão corrigida/homologada. Preservar zoom, páginas, download e autorização; tratar compatibilidade de API/ES modules, sem simples substituição cega de bundle.

**Aceite:** versões servidas correspondem à release homologada; biblioteca/worker/CMaps compatíveis; PDFs de referência, fontes/acentos e arquivos grandes funcionam; segurança validada isoladamente. O CSP do gateway de bytes não corrige JavaScript executado pelo visualizador na página principal.

### DR-03 — Estado e conteúdo aprovados desatualizados

**Fontes:** [DocumentWorkflowService](../services/DocumentWorkflowService.php), linhas 46–89, 118–146; [API](../api/documents.php), leitura antes da transação e UPDATE nas 337–346; [painel](../admin/index.php), linhas 1755, 1837–1870 e 1988–1995.

`prepareAction()` recebe estado anterior do chamador; aprovação consulta presença de revisor, mas não valida versão do conteúdo dentro de um lock uniforme. API/formulário atualizam por ID sem condição de versão. A ação editorial individual do painel lê estado e grava em autocommit. Requisições concorrentes podem validar o mesmo estado, depois sobrescrever conteúdo/estado ou publicar após outra alteração. O lock de slug não revalida a decisão editorial tomada antes dele.

A fixture em memória mostrou aprovação aceita com estado recebido `review`, estado atual `draft` e revisão registrada. Demonstra a confiança no estado recebido; não reproduz todos os interleavings no PostgreSQL.

Há também uma decisão de processo: o payload de salvamento pode aprovar e enviar conteúdo alterado; o parecer não está associado à versão do conteúdo. Se a aprovação deve cobrir exatamente a versão revisada, formalizar esse vínculo antes de implementar.

**Proposta para aprovação:** salvamento/transição compartilhados; ler e bloquear registro dentro da transação e revalidar estado/revisão; versão otimista para detectar formulário antigo. Lock cobre concorrência após leitura; versão cobre edição iniciada antes de outra gravação. Definir resposta de conflito preservando o texto do usuário. Manter URLs, papéis e sequência editorial aprovada.

**Aceite:** salvamentos concorrentes não perdem alteração silenciosamente; aprovação concorrente com retorno/expiração não publica estado inválido; conflito previsível; revisão corresponde ao conteúdo publicado. API, individual e lote cumprem o mesmo contrato. O lote já usa `FOR UPDATE` na linha 2110: conservar essa proteção.

### CR-06 — Ampliação da atomicidade editorial

Elevado de P2 para **P1** pela integridade necessária no lançamento. A API confirma documento antes de metadados/histórico, linhas 371–379. A ação individual do painel, 1988–1996, também grava sem transação comum. No formulário, 1898–1906, há transação, mas falhas editoriais são capturadas e o fluxo continua tentando commit/sucesso.

**Proposta para aprovação:** documento, tags, vínculo de mídia, metadados e histórico confirmam juntos. Notificação deve ter estratégia explícita de falha/reenvio após persistência, evitando fan-out na transação. Erro PostgreSQL pode abortar uma transação; capturar e prosseguir não prova sucesso.

**Aceite adicional:** falhas controladas em metadados/histórico, em cada entrada e banco descartável, retornam erro e não deixam transição parcial. Nenhuma falha foi injetada na base atual. Veja também CR-05 no [review inicial](CODE_REVIEW_2026-10-01.md).

## Performance preservando a autorização

### DR-04 — Principal gargalo medido: autorização e árvore

**Fontes:** [PermissionService](../services/PermissionService.php), árvore na linha 1103, árvore acessível na 1417, listas na 1466–1489, escopo administrativo na 1562; [portal](../index.php), linhas 27 e 42–44; [AccessService](../services/AccessService.php).

Cada lista de IDs reconstrói a árvore acessível. A estrutura consulta categorias, subcategorias por categoria e assuntos por subcategoria: **1 + C + SC**, antes da autorização. Para não globais, cada recurso pode consultar papel, atividade, cadeia, grupos e regras. A verificação de acesso ao painel acrescenta uma caminhada administrativa. Concessão herdada em ancestral reduz parte do custo; muitos assuntos públicos sem concessão ancestral expõem crescimento por nó.

| Fixture: uma categoria/subcategoria, assuntos públicos, leitor sem regras privadas | Três listas de leitura | Padrão do portal: painel + três listas |
| --- | ---: | ---: |
| 10 assuntos | 192 SQL | 257 SQL |
| 100 assuntos | 1.542 SQL | 2.057 SQL |
| 1.000 assuntos | 15.042 SQL | 20.057 SQL |

Na árvore local, foram 285 SQL para o não global selecionado e 49 para global. Latências são de execução local do serviço; número de consultas/crescimento estrutural são a evidência principal. Não são benchmark HTTP nem comprovação de throughput.

**Proposta para aprovação:** contexto de autorização compartilhado por requisição; árvore reutilizada para as três listas; hierarquia em lote; usuário/grupos/regras carregados em lote e cálculo em memória; invalidar/recomputar após mutações na mesma requisição. Ancestral de navegação não pode virar concessão de escrita.

Cache entre requisições só depois, com versão/invalidação por concessão, equipe, usuário, atividade, visibilidade e hierarquia. Não usar IDs na sessão como autorização duradoura ou compartilhar cache HTML privado apenas por URL.

**Aceite:** matriz antiga/nova equivalente para herança, público/privado, equipes inativas, usuário desativado, hierarquia inativa e leitor/editor/admin delegado/global; revogação funciona no prazo definido. Orçamento inicial proposto: autorização do portal em até 12 consultas, sem crescimento por recurso. Ainda não atingido.

### DR-05 — Expiração no bootstrap de toda requisição

**Fontes:** [runtime](../config/app_runtime.php), linhas 16–24; [workflow](../services/DocumentWorkflowService.php), linhas 253–291.

Toda entrada web que inclui a conexão tenta expirar pendências: transação, vencidos com `FOR UPDATE`, atualização e histórico por documento. Inclui gateways de mídia e entradas sem autenticação. O índice parcial existente é positivo; não elimina transação por requisição nem limita um lote grande. Não há LIMIT/SKIP LOCKED. Sem tráfego, a expiração não é garantida pelo repositório.

**Impacto:** rajadas repetem trabalho; muitos vencidos podem gerar espera de leitores e disputa com editores. A duração sob concorrência não foi medida.

**Proposta para aprovação:** worker/agendador com lotes limitados, coordenação/idempotência e histórico consistente. Preservar prazo nas leituras/aprovações, recusando pendência vencida mesmo se o worker atrasar. Retirar a escrita do bootstrap sem esse controle mudaria a regra atual.

**Aceite:** navegação não executa lote editorial; vencido recusado no instante devido; workers não duplicam histórico; lote grande não bloqueia leitura; atraso/reinício monitorados.

### DR-06 — Sessão bloqueada e PHP transmitindo bytes

**Fontes:** [sessão](../config/session.php), [download](../download.php), linha 104; [stream](../document-file.php), linhas 149–166. [Importação](../admin/import-users.php) fecha sessão antes de LDAP, um padrão positivo.

As rotas de entrega mantêm sessão aberta. O handler CLI local é `files`; confirmar web/produção. Com handler que bloqueia sessão, download lento serializa outras chamadas da mesma conta. PHP transmite bytes, mantendo worker e PDO durante a transferência. Chunks de 8 KB com flush repetido aumentam trabalho; o problema principal é duração/bloqueio. [Manual PHP](https://www.php.net/manual/en/function.session-write-close.php).

**Proposta para aprovação:** persistir alterações necessárias e fechar sessão antes da transmissão; conferir que não há escrita posterior dependente dela. Liberar banco quando dispensável. Delegar bytes ao servidor web por localização interna, mantendo autorização PHP e URLs; arquivo público não substitui o gateway.

**Aceite:** download lento e navegação/favoritos na mesma sessão sem espera; validar sessões distintas, acesso negado, Range, 206/416, nomes, MIME, sandbox e vídeo. Quando delegado, worker PHP não permanece ocupado até o fim dos bytes.

### DR-07 — Busca/listas sem orçamento de linhas

**Fontes:** [portal](../index.php), linhas 166–217; [favoritos](../favoritos.php); [API](../api/documents.php), linhas 64–105; [schema](../database/schema.sql).

Busca usa LOWER/LIKE com substring em vários campos/tags/aliases e fetchAll sem paginação. API por assunto também retorna lista completa/conteúdo estruturado; só o outro caminho limita 50. B-tree de título não fornece acesso apropriado geral à substring em LOWER(title). Plano reduzido local usa Seq Scan; em 35 documentos isso é normal e não prova lentidão atual.

**Proposta para aprovação:** índices de expressão/trigramas compatíveis com a busca atual, avaliando seletividade, escrita e todos os campos do OR. Preservar substring, acentos, filtros, ordem e tags. `pg_trgm` oferece GIN/GiST para LIKE/ILIKE; termos sem trigramas úteis podem continuar caros. [PostgreSQL](https://www.postgresql.org/docs/current/pgtrgm.html).

Paginação/cursor muda apresentação ou resposta e exige decisão separada. Manter acesso a todos os resultados e contagens; não introduzir corte silencioso ou trocar por full-text sem equivalência funcional.

**Aceite:** corpus representativo de 100 mil documentos ou volume real previsto; buscas comuns/raras/curtas, tags/aliases e filtros equivalentes; planos/latências medidos; resposta/memória limitadas pela navegação aprovada.

### DR-08 — Painel trabalha em dados não exibidos

**Fontes:** [painel](../admin/index.php), dashboard 2715–3022, árvore 3257–3275; [permissões](../services/PermissionService.php), usuários 1624–1655 e equipes 1712–1730.

Dashboard global é calculado por capacidade de auditoria sem exigir aba `visao_geral`. Árvore administrativa carrega todos os documentos do escopo, mesmo com listagem visível paginada. Na aba de usuários, a lista autorizada é completa; gestor não global recalcula equipes/escopo por usuário. Custo pode combinar usuários e hierarquia.

**Proposta para aprovação:** consultas por aba/necessidade, escopo reutilizado, contagens de equipes em lote e árvore sob demanda; preservar filtros, números e escopo delegado. Agregados podem ter cache/materialização com atualização definida. Paginação nova de usuários exige aprovação da experiência.

**Aceite:** abas que não usam dashboard não fazem essas consultas; usuários/contagens equivalentes; árvore grande navegável; SQL/payload/latência por aba registrados.
