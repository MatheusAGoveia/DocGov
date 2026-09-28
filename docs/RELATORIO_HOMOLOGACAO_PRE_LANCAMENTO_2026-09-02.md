# Relatório de homologação pré-lançamento — GovDoc

Data: 02/09/2026  
Ambiente avaliado: estação local de desenvolvimento  
Decisão atual: **NÃO LIBERAR PARA PRODUÇÃO**

## Resumo executivo

A base técnica, as migrações e os principais recursos documentais estão estáveis nos testes executados. A homologação, porém, encontrou uma falha de segurança na autenticação integrada antes de alcançar a jornada completa no navegador: uma identidade com domínio explícito não configurado, como `OUTRO\usuario`, é redirecionada internamente para o domínio padrão BETIM em vez de ser recusada.

Os dois administradores existentes, `matheus.damiao` e `marcuss`, foram confirmados como Super Admins autorizados. A base ainda contém três contas leitoras que precisam fazer parte da conferência nominal antes do lançamento.

Pelo critério de interrupção na primeira fronteira quebrada, os testes restantes, a homologação no navegador e a carga concorrente devem ser retomados somente depois da correção desses bloqueios.

## História verificada

Um usuário autenticado cria um conteúdo em uma seção, envia para revisão, um administrador aprova, o PostgreSQL persiste a publicação e o portal apresenta automaticamente a seção correspondente.

## Ambiente encontrado

| Componente | Versão/estado |
|---|---|
| PHP CLI | 8.4.22 |
| PostgreSQL | 18.4 |
| Node.js | 24.18.0 |
| Editor formatado | Quill 2.0.2, BSD-3-Clause e hospedado localmente |
| Seções dinâmicas | 13 ativas |
| Base atual | 5 usuários, 3 categorias, 3 assuntos e 9 documentos |

O PHP do servidor definitivo da prefeitura precisa ser comparado com o ambiente acima. A aplicação ainda não foi validada executando diretamente na versão mais antiga mencionada para o ambiente municipal.

## Resultado dos testes

| Verificação | Resultado | Evidência |
|---|---:|---|
| Sintaxe PHP | Aprovado | 119 de 119 arquivos sem erro |
| JavaScript próprio | Aprovado | Verificação de sintaxe concluída |
| Dependências NPM | Aprovado | 0 vulnerabilidades conhecidas |
| Editor formatado gratuito | Aprovado | Quill local, toolbar, texto e tabela 3×3 validados no Chrome sem erro de console ou rede |
| Migrações PostgreSQL | Aprovado | Migrações 003 a 024 executadas com sucesso |
| Integridade de acesso | Aprovado | Super Admins e restrições estruturais validados |
| Imagens de categoria | Aprovado | Persistência e reversão validadas |
| Documentos de código | Aprovado | Rascunho, publicação, linguagem e conteúdo validados |
| Painel da conta | Aprovado | Métricas pessoais renderizadas |
| Aprovação documental | Aprovado | Revisão, recusa, responsáveis e expiração validados |
| API de seções | Aprovado | CSRF, criação, edição e JSON estruturado validados |
| Tags no formulário | Aprovado | Criação e sugestões validadas |
| Títulos repetidos | Aprovado | Slugs únicos e edição estável |
| Seções dinâmicas | Aprovado | CRUD, sanitização, Fluxo e Organograma validados |
| Equipes e herança | Aprovado | Membros, acesso, remoção e auditoria validados |
| Hierarquia | Aprovado | IDs, nomes repetidos, escopo e inatividade validados |
| Login integrado Windows | **Reprovado** | Domínio explícito desconhecido foi aceito pelo fallback |

Foram concluídos 11 cenários funcionais antes da falha de autenticação. Após a substituição do editor, outros nove testes de regressão foram aprovados. As fixtures temporárias foram removidas; não ficaram usuários com os prefixos utilizados pelos testes.

## Bloqueios de lançamento

### P0 — Domínio desconhecido aceito como BETIM

Arquivo: `services/ActiveDirectoryAuthService.php`, método `resolveIdentity()`.

Depois de não encontrar o `domainHint` entre os aliases configurados, o código sempre seleciona o domínio padrão. O fallback deve existir somente quando a identidade recebida não contém domínio. Se houver prefixo NetBIOS ou sufixo UPN explícito e desconhecido, a função deve retornar `null`.

Critérios para liberar:

- `BETIM\usuario` deve resolver BETIM;
- `usuario@saude.pmb` deve resolver SAUDE quando habilitado;
- usuário sem domínio pode usar o domínio selecionado/controlado;
- `OUTRO\usuario` e `usuario@dominio-nao-configurado` devem ser recusados;
- nenhuma conta deve ser criada quando o domínio for recusado.

### P0 — Conferência dos usuários leitores

A base contém estas contas ativas:

- `matheus.damiao` — admin, BETIM;
- `marcuss` — admin, BETIM;
- `prof.marcelo` — reader, EDUCacao;
- `thamyres.ferreira` — reader, BETIM;
- `thavyne.ferreira` — reader, BETIM.

`matheus.damiao` e `marcuss` estão aprovados como Super Admins. Falta confirmar se `prof.marcelo` e `thamyres.ferreira` devem permanecer ativos junto com `thavyne.ferreira`, conforme a lista final esperada para o lançamento.

### P0 — Paridade do ambiente de produção

Os testes foram executados no PHP 8.4.22. É obrigatório executar a mesma suíte no PHP e PostgreSQL reais da prefeitura, incluindo extensões `pdo_pgsql`, `ldap`, `mbstring`, `dom`, `fileinfo` e configuração TLS/CA do AD.

### Resolvido — Editor formatado sem custo de licença

O RichTextEditor comercial foi removido e substituído pelo Quill 2.0.2, distribuído sob BSD-3-Clause. Os assets são locais, a licença acompanha a distribuição e a auditoria NPM não aponta vulnerabilidades conhecidas nessa versão.

## Melhorias prioritárias de desempenho

### P1 — Compilar o Tailwind localmente

Login, portal, administração e visualização de documentos carregam `cdn.tailwindcss.com`, que compila estilos no navegador e cria dependência externa. Para produção, gerar um CSS minificado local contendo somente as classes utilizadas.

Impacto esperado: carregamento inicial menor, menos JavaScript, renderização mais rápida e funcionamento mesmo quando a rede municipal bloquear CDNs.

### Resolvido parcialmente — Editor mais leve e carregamento condicionado

O editor anterior carregava aproximadamente 1,53 MiB não comprimidos de núcleo, plugins e CSS em todas as páginas administrativas. O Quill local usa aproximadamente 229 KiB de JavaScript e CSS e agora é carregado somente em `novo_documento`, uma redução aproximada de 85% nesses assets. GridStack e Highlight.js ainda são globais.

Recomendação:

- carregar GridStack somente no editor visual;
- carregar Highlight.js somente em documento de código;

### P1 — Eliminar dependências críticas de CDN

Tailwind, GridStack, Highlight.js, JSZip, docx-preview e mapas de caracteres do PDF ainda possuem referências externas. Hospedar versões fixadas localmente, com hash de integridade no processo de build e cache longo.

### P1 — Definir orçamento de desempenho e executar carga

Após corrigir a autenticação, medir em ambiente semelhante ao definitivo:

- portal e documento: resposta de servidor p95 menor que 500 ms;
- APIs administrativas: p95 menor que 400 ms;
- login AD: p95 menor que 2 s, separando tempo LDAP do tempo da aplicação;
- LCP do navegador menor que 2,5 s;
- testes com 25, 50 e 100 usuários simultâneos;
- erro HTTP inferior a 1% durante carga.

### P2 — Ativar otimizações do servidor

- OPcache em produção;
- compressão Brotli ou gzip;
- HTTP/2 ou HTTP/3 quando disponível;
- `Cache-Control: immutable` para assets versionados;
- pool de processos PHP dimensionado por memória e concorrência;
- log de consultas lentas do PostgreSQL e métricas p50/p95/p99.

### P2 — Versionar migrações executadas

O executor atual percorre novamente todas as migrações 003–024. Criar uma tabela `schema_migrations`, checksum dos arquivos e lock de execução reduz tempo de deploy e evita reaplicações concorrentes.

## Testes pendentes após a correção

- permissões por usuário/equipe e concorrência;
- isolamento da visão administrativa por categoria;
- workspace, revisão e histórico do assunto;
- grupos de acesso às configurações;
- configurações, CORS e manutenção;
- tags, vídeo, auditoria e notificações;
- login real BETIM e SAUDE com TLS;
- jornada completa no navegador;
- console, rede, acessibilidade básica e responsividade;
- carga concorrente e medições p50/p95/p99;
- restauração a partir de backup e rollback do deploy.

## Critério recomendado para lançamento

Liberar somente quando todos os P0 estiverem resolvidos, a suíte interrompida for retomada sem falhas, o fluxo completo for aprovado no navegador e os limites de desempenho forem medidos no ambiente equivalente ao da prefeitura.
