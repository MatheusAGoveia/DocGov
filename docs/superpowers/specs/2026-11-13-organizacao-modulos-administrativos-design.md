# Organização do painel administrativo por funcionalidade

**Data:** 13/11/2026  
**Estado:** proposta para aprovação; implementação não iniciada  
**Revisão:** leitura independente concluída, sem perguntas importantes pendentes  
**Objetivo informado:** facilitar encontrar as funções do sistema.

## Intenção

Uma pessoa deve conseguir partir de um comportamento — salvar documento, incluir membro de equipe, testar AD — e localizar o código responsável sem percorrer o painel inteiro. O primeiro recorte é o painel administrativo, principal concentração de responsabilidades identificada no checkout.

A inspeção encontrou `admin/index.php` com 8.245 linhas, uma função PHP nomeada e 33 funções JavaScript nomeadas. O arquivo reúne bootstrap, autorização, processamento de requisições, consultas, HTML, CSS e JavaScript. A lógica de várias operações também usa serviços existentes em `services/`.

Os números descrevem o checkout inspecionado; as alterações locais de documentação, limpeza e identidade AD já existentes serão preservadas. Esta proposta não pressupõe que o checkout esteja igual ao último commit.

## Decisões propostas

Estas são escolhas técnicas propostas para atender ao objetivo, ainda não aprovadas pelo usuário:

- Manter PHP, PostgreSQL, os serviços atuais e o modelo de publicação existente.
- Organizar os manipuladores administrativos por funcionalidade, com nomes explícitos para a ação de cada arquivo.
- Separar as funções JavaScript do painel em arquivos por área, preservando nomes públicos usados no HTML.
- Manter a entrada `admin/index.php`, suas URLs e seus contratos HTTP.
- Acrescentar um mapa curto de funcionalidades, pontos de entrada, manipuladores, serviços e scripts.

## Escopo da primeira etapa

Esta etapa entrega a separação das ações administrativas e das funções JavaScript hoje incorporadas em `admin/index.php`, com a documentação e a regressão correspondentes. Consultas de preparação de telas, HTML e CSS permanecem no ponto atual, salvo os pequenos trechos necessários para carregar a configuração e os scripts extraídos.

O resultado é um primeiro passo verificável da refatoração arquitetural. A separação posterior das consultas e templates e a decomposição de serviços grandes serão projetos seguintes, usando o padrão validado nesta etapa.

Não há migração de banco, mudança de framework, alteração de autenticação/permissões, redesenho de telas ou nova funcionalidade nesta etapa. Não serão removidas rotas apenas por falta de links internos.

## Organização proposta

```text
admin/
├── index.php
├── dispatch.php
├── README.md
├── modules/
│   ├── documents/actions/
│   ├── structure/actions/
│   ├── subject-workspaces/actions/
│   ├── users/actions/
│   ├── groups/actions/
│   ├── authentication/actions/
│   ├── settings/actions/
│   ├── tags/actions/
│   └── audit/actions/
└── partials/                         componentes de tela existentes

assets/admin/
├── navigation.js
├── document-list.js
├── document-editor.js
├── structure.js
└── groups.js

services/                            serviços de negócio existentes
```

Os diretórios e arquivos acima constituem o padrão inicial. Cada manipulador terá nome que expresse a operação; por exemplo, `documents/actions/save.php`, `groups/actions/manage.php` e `authentication/actions/create-domain.php`. O plano detalhará a relação completa de arquivos e variáveis antes da implementação. Se um bloco indivisível exigir um arquivo auxiliar, sua responsabilidade deverá estar explícita no mapa.

## Entrada e despacho de requisições

1. `admin/index.php` mantém a sequência atual de sessão, conexão/runtime, serviços, autorização e inicialização do contexto.
2. No ponto hoje ocupado pelos manipuladores administrativos, inclui `admin/dispatch.php`.
3. O despachante inclui uma lista estática de manipuladores na mesma ordem em que os blocos são avaliados hoje.
4. Cada manipulador preserva a condição de entrada, a validação, os controles de acesso, a persistência e a resposta do bloco original.
5. A execução retorna para a preparação de dados e renderização da página, quando o comportamento original não encerra a requisição.

A lista não será construída a partir de um caminho enviado pelo usuário nem de descoberta automática de arquivos. Não haverá `eval`, `extract($_POST)` ou roteamento por nome de arquivo recebido na requisição.

A primeira extração usa o escopo compartilhado de inclusão do PHP para preservar os contratos atuais de variáveis, como `$message`, `$errorMessage`, `$failedDocumentSubmission` e `$editDoc`. Não será criada uma classe de contexto genérica para conter todas as variáveis. Cada arquivo documentará os serviços/variáveis de que depende; a redução desse acoplamento pertence a uma etapa posterior.

Os caminhos de arquivos físicos e de includes serão recalculados a partir da raiz validada do projeto; mover um bloco não poderá mudar o destino de upload, limpeza ou configuração. URLs HTTP continuam relativas à entrada original, e não à pasta do manipulador.

Os novos arquivos PHP internos terão proteção antes de qualquer processamento para recusar acesso direto. A entrada administrativa estabelece o contexto de execução; conhecer a URL de um manipulador não pode substituir autenticação e CSRF.

## Mapa de responsabilidades

| Área | Blocos atuais que pertencem ao módulo |
| --- | --- |
| Estrutura | Exclusão definitiva, descarte/restauração, salvar categoria, subcategoria e assunto |
| Processo do assunto | `subject_workspace_action` e `save_subject_workspace` |
| Documentos | Upload em lote, salvar/editar, preservar formulário inválido, transição rápida, lixeira e ações em lote |
| Usuários | `create_user`, `get_user_details`, conceder/remover permissão individual e replicação de usuários |
| Equipes | `group_action`, incluindo membros, subgrupos e capacidades |
| Autenticação | Testes de conexão/saúde, criação/exclusão de domínio e configuração de servidores AD |
| Configurações | `save_system_settings`, incluindo os campos de AD que já fazem parte desse formulário |
| Tags | `tag_admin_action` |
| Auditoria | Exportação de logs AD em CSV |

Carregamentos GET de edição e de detalhes, a partir da seção atual de preparação de entidades, continuam no `index.php` nesta etapa. A exportação de auditoria continua com a condição e o método aceitos pelo código original.

Há condições repetidas para `test_ad_connection` e `replicate_ad_users`. Elas devem ser inventariadas e preservadas em sua ordem e condição de alcance; esta refatoração não deve fundi-las silenciosamente. O despacho também preserva o comportamento de requisições contendo mais de um indicador de ação. Upload em lote continua sendo avaliado antes do salvamento individual.

## JavaScript por área

| Arquivo | Responsabilidade |
| --- | --- |
| `navigation.js` | Sidebar, navegação móvel, submenus e menus compartilhados |
| `document-list.js` | Abertura de linhas, filtros, seleção e barra de ações em lote |
| `document-editor.js` | Hierarquia do formulário, tipos de conteúdo, tags, previews, envio em lote e estado do editor |
| `structure.js` | Seleção de tipo de nova estrutura e operações da árvore |
| `groups.js` | Pesquisa e seleção de membros da equipe |

Os nomes globais usados por `onclick`, `onchange` e outros atributos HTML serão preservados. Os scripts permanecem clássicos, com ordem explícita de carregamento e inicialização. Não será introduzido bundler ou dependência npm.

O PHP permanece responsável por produzir os dados que hoje são interpolados no script: mapas de hierarquia, filtros selecionados, catálogo e seleção de tags, nomes do ramo, assuntos, tipo de conteúdo e estado do editor. Esses valores serão serializados uma vez em um objeto de configuração dedicado à página, antes dos scripts consumidores, com escape seguro para HTML. Não se transportam senhas ou configurações completas do AD para esse objeto.

O plano deverá registrar as dependências entre funções, variáveis e efeitos de inicialização antes de escolher os limites de extração. A separação não pode antecipar o uso de um dado, duplicar listeners ou perder a recuperação do formulário após validação.

## Como encontrar uma função depois da mudança

`admin/README.md` terá uma tabela de tarefas com o arquivo exato de entrada e o serviço responsável. Exemplos:

- Salvar documento → manipulador de documentos → serviços de workflow, seções, mídia e tags.
- Incluir membro → manipulador de equipes → verificação de acesso, persistência e notificações existentes.
- Testar conta técnica → manipulador de autenticação → `ActiveDirectoryAuthService`.
- Alterar regra de acesso ao conteúdo → `services/PermissionService.php`.
- Ajustar preview de vídeo → `assets/admin/document-editor.js` e `VideoEmbedService.php`.

O índice central e a referência de arquitetura apontarão para esse mapa. O mapa explica onde alterar cada comportamento; não duplica a implementação dos serviços nem contém números de linha que envelheçam a cada edição.

## Verificação e critérios de aceite

- Inventariar todos os blocos administrativos e as 33 funções JavaScript antes da extração; cada item deve ter um destino ou uma permanência justificada.
- Usar caracterização anterior à mudança para comparar rotas, status, redirecionamentos, respostas e recuperação de formulário nos fluxos movidos.
- Verificar preservação da ordem de despacho, inclusive indicadores simultâneos, condições repetidas de AD, upload em lote e preparação do formulário após erro.
- Verificar sessão ausente, usuário sem escopo, administrador delegado, CSRF inválido e acesso direto aos novos arquivos internos.
- Executar regressões relevantes de documentos, estrutura, processo do assunto, equipes, capacidades, configurações e AD simulado em uma base temporária exclusiva; conferir rollback e limpeza das fixtures.
- Conferir em navegador os scripts extraídos: listagem, filtros/menus, editor, tags, previews, árvore e seleção de membros, incluindo celular e tema escuro nas telas afetadas.
- Conferir carregamento/ordem dos scripts, ausência de funções globais ausentes, listeners duplicados e erros de console; usar dados com aspas, acentos e fechamento de tag no objeto de configuração.
- Aprovar `php -l`, `node --check`, `git diff --check` e os links/documentos atualizados.

Não serão executados scripts operacionais em lote, migrações no banco configurado, autenticações corporativas reais ou operações sobre documentos reais para validar a reorganização. Testes que criam bases temporárias utilizam a conexão local de desenvolvimento apenas para provisioná-las, respeitando suas guardas existentes.

## Limites e riscos conhecidos

A extração mantém parte do estado compartilhado do painel. Seu objetivo imediato é localização e separação por funcionalidade, e não eliminar todo acoplamento em uma única mudança. As consultas, templates e os serviços de permissões/AD continuam com oportunidades próprias de decomposição.

Movimentos mecânicos podem alterar `__DIR__`, escopo de variáveis, ordem de execução, headers e disponibilidade de funções JavaScript. Esses são critérios de regressão, não efeitos aceitáveis da refatoração.

O trabalho deve ser feito sobre o estado local existente e produzir um diff separado e revisável. Não será usado reset do checkout, reaplicação do schema na base em uso ou inclusão de alterações alheias em um commit de refatoração.

## Próximo passo

Após a aprovação deste desenho, produzir o plano de implementação com caminhos, fronteiras, dependências e comandos de regressão. A implementação começa somente depois dessa revisão, conforme a skill de planejamento aplicável.
