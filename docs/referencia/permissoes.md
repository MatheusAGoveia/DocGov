# Permissões e capacidades

[Documentação](../README.md) · [Arquitetura](arquitetura.md) · [APIs](api.md)

A autorização de conteúdo usa [PermissionService](../../services/PermissionService.php). Capacidades de módulos globais usam [SystemAccessService](../../services/SystemAccessService.php). A atividade do usuário, a hierarquia e o estado editorial participam da decisão; o papel nominal de editor ou leitor não substitui a verificação do recurso.

## Acesso ao conteúdo

| Nível | Comportamento no escopo autorizado |
| --- | --- |
| `view` | Consultar e baixar conteúdo publicado |
| `edit` | Criar/editar conteúdo e enviar para revisão |
| `admin` | Administrar o ramo, gerir concessões e revisar/aprovar conforme o workflow |

A hierarquia é categoria → subcategoria → assunto → documento. As concessões são armazenadas em `permissions` para categorias, subcategorias e assuntos; o documento resolve o contexto pelo assunto pai.

A regra é `view < edit < admin`: vence a maior concessão ativa, seja direta, por equipe ou por ancestral. Não há regra explícita `DENY`. Remover uma concessão ou atribuir um nível menor não elimina outra origem de acesso maior.

Ancestrais exibidos para permitir a navegação até um recurso não concedem escrita nem acesso a irmãos privados. A publicação exige o fluxo editorial, mesmo quando o editor pode salvar o documento.

## Equipes e subgrupos

`user_groups` registra associações diretas e `group_memberships` registra equipes dentro de equipes. `GroupMembershipService` calcula os caminhos ativos e deduplica membros. Um membro do subgrupo recebe acessos de conteúdo do grupo superior; a associação não propaga acessos exclusivos do subgrupo aos membros diretos do superior.

Usuários inativos não recebem acesso. Uma equipe inativa interrompe os caminhos que passam por ela, preservando outras origens. Ciclos são recusados no serviço e no banco. Veja [grupos aninhados](grupos-aninhados.md).

## Assuntos públicos

Um assunto público oferece leitura de conteúdo publicado para contas autenticadas e ativas, dentro de uma hierarquia ativa. Essa visibilidade não concede edição, administração ou leitura de rascunhos. Ao tornar o assunto privado, a leitura pública termina e as concessões explícitas permanecem. Veja [visibilidade](assuntos-publicos-privados.md).

## Administração global e capacidades

O administrador global é identificado por `users.role = admin`, com as verificações de atividade aplicáveis. Ele administra equipes, capacidades e recursos globais.

| Capacidade | Módulo |
| --- | --- |
| `system.settings.manage` | Configurações |
| `system.authentication.manage` | Autenticação e servidores AD |
| `system.directory.manage` | Diretório de usuários e importação |
| `system.audit.view` | Auditoria |
| `system.tags.manage` | Tags |

Essas capacidades podem ser delegadas a equipes por `group_system_capabilities`. A resolução considera associação **direta** à equipe; a herança de subgrupos usada para conteúdo não se aplica aos módulos globais.

Administrar um assunto não libera configurações globais. Gerenciar membros de equipes e capacidades permanece restrito ao administrador global; a importação em lote de membros também verifica a capacidade de diretório no serviço.

## Diagnóstico e regressão

Ao investigar uma recusa, confira atividade da conta, estado do recurso, publicação, visibilidade, concessões diretas, grupos ativos e caminho de herança. Favoritar um conteúdo não conserva acesso revogado.

Os testes de permissões, hierarquia, visibilidade, capacidades e grupos estão no [guia de testes](../desenvolvimento/testes.md). A [matriz de operações](../../database/CAPABILITIES_MATRIX.md) detalha ações por nível; a [especificação de origem](../../database/PERMISSIONS_ARCHITECTURE.md) registra o contexto do modelo e contém seções históricas.
