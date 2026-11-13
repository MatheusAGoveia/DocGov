# Arquitetura do DocGov

[Documentação](../README.md)

Referência da organização do código, revisada em 13/11/2026. Descreve o checkout local; a topologia e a capacidade do ambiente publicado dependem de homologação própria.

## Navegação

- [Componentes e requisições](#componentes-e-requisições)
- [Mapa dos serviços](#mapa-dos-serviços)
- [Dados](#dados)
- [Autorização](#autorização)
- [Dois fluxos de aprovação](#dois-fluxos-de-aprovação)
- [Arquivos e conteúdo](#arquivos-e-conteúdo)
- [Fronteiras a consolidar](#fronteiras-a-consolidar)

## Componentes e requisições

O sistema é um monólito PHP com múltiplos pontos de entrada. As páginas montam HTML, chamam serviços e consultam o PostgreSQL. As APIs retornam JSON e compartilham os mesmos serviços de autorização. O frontend utiliza JavaScript convencional e bibliotecas de edição/visualização.

No painel, `admin/index.php` conserva bootstrap, autorização, consultas de tela e renderização. `admin/dispatch.php` inclui 29 manipuladores em `admin/modules/<área>/actions/`, na ordem original e no mesmo escopo. Os arquivos internos recusam acesso HTTP direto. As 33 funções JavaScript antes incorporadas à entrada estão em cinco arquivos de `assets/admin/`, com dados serializados por `admin/partials/client-config.php`. O [mapa do painel](../../admin/README.md) indica o arquivo de cada comportamento.

```mermaid
flowchart LR
    U[Navegador] --> W[Servidor web e PHP]
    W --> P[Páginas do portal e painel]
    W --> A[APIs e gateways de mídia]
    P --> S[Serviços de negócio]
    A --> S
    S --> DB[(PostgreSQL)]
    S --> F[Armazenamento de arquivos]
    S --> AD[Active Directory por LDAP]
```

O bootstrap usual carrega `config/session.php`, inicia a sessão e inclui `config/db.php`. A conexão carrega `config/app_runtime.php`, salvo quando `DOCGOV_SKIP_APP_RUNTIME` é definido como `true` por um executor. O runtime obtém configurações, aplica políticas de sessão, headers, manutenção e expiração editorial.

Isso torna a inclusão da conexão uma operação com efeitos adicionais: no contexto web, pode atualizar documentos vencidos e encerrar/redirecionar a requisição por manutenção. Um script que apenas inclui `db.php` não deve ser tratado automaticamente como consulta sem escrita.

## Mapa dos serviços

| Área | Fonte principal | Responsabilidade |
| --- | --- | --- |
| Autorização de conteúdo | `PermissionService.php` | Acesso efetivo, herança, árvore e escopo administrativo |
| Compatibilidade de acesso | `AccessService.php` | Encaminha chamadas ao motor de permissões |
| Módulos administrativos | `SystemAccessService.php` | Capacidades globais delegadas por equipes |
| Identidade corporativa | `ActiveDirectoryAuthService.php` | Autenticação, consulta ao AD, contas e auditoria de autenticação |
| Importação de usuários | `BatchUserImportService.php`, `DirectoryImportGateway.php` | Processamento em etapas, consulta ao diretório e transação por pessoa |
| Equipes aninhadas | `GroupMembershipService.php` | Membros ativos diretos/indiretos, caminhos de herança e proteção contra ciclos |
| Configuração | `SystemSettingsService.php` | Configurações JSONB e status de manutenção |
| Fluxo editorial | `DocumentWorkflowService.php` | Transições, metadados, histórico, expiração e avisos |
| Conteúdo e seções | `DocumentSectionService.php`, `RichTextSanitizer.php`, `StructuredContentService.php` | Seleção de editor e validação do conteúdo |
| Upload e identidade do documento | `BatchDocumentUploadService.php`, `DocumentSlugService.php` | Lote de arquivos e reserva de slug |
| Mídia | `DocumentInlineMediaService.php`, `CategoryImageService.php`, `VideoEmbedService.php` | Imagens internas, imagens da estrutura e vídeo externo |
| Processo do assunto | `SubjectWorkspaceService.php` | Seções estruturadas, aprovação e snapshots |
| Estrutura | `HierarchyService.php`, `StructureArchiveService.php`, `StructureDeletionService.php` | Resolução da hierarquia, arquivamento e exclusão permanente |
| Organização e rastreio | `TagService.php`, `NotificationService.php`, `UsageAuditService.php` | Tags, notificações e eventos de uso |

Os caminhos da tabela são relativos a [services/](../../services/). A importação usa [admin/import-users.php](../../admin/import-users.php), processa até 20 entradas por requisição e separa autorização de diretório da gestão de equipes. Consulte o [guia de importação](../guias/importacao-usuarios-em-lote.md) e o [contrato HTTP](api.md#importação-de-usuários-em-lote).

A identidade AD é resolvida por domínio, login e GUID, preservando ID, equipes e concessões da conta correspondente. Logins iguais em domínios distintos não unem cadastros. Veja [identidades AD por domínio](identidades-ad-por-dominio.md).

## Dados

`database/schema.sql` representa a estrutura para instalação em uma base vazia. As migrações versionadas representam a evolução de bases existentes.

```mermaid
flowchart TD
    C[categories] --> SC[subcategories]
    SC --> SU[subjects]
    SU --> D[documents]
    DS[document_sections] --> D
    SU --> SW[subject_workspaces]
    SW --> SH[subject_workspace_history]
    D --> M[document_inline_media]
    D --> WH[document_workflow_history]
    D --> DT[document_tags]
    T[tags e tag_aliases] --> DT
    US[users] --> UG[user_groups]
    G[groups] --> UG
    G --> GM[group_memberships]
    US --> PE[permissions]
    G --> PE
    G --> CAP[group_system_capabilities]
```

Outras tabelas incluem `favorites`, `notifications`, `system_settings`, `permission_audit`, `system_capability_audit` e `usage_audit_events`. Consulte as migrações para a evolução da auditoria AD. Os arquivos físicos não são armazenados como bytes no banco; o banco guarda identificação, vínculo e metadados.

## Autorização

Os níveis hierárquicos são `view < edit < admin`. O maior nível recebido diretamente, por equipe ativa ou por ancestral vence. Não há regra explícita de negação; remover uma concessão não elimina outra origem de acesso. Documentos herdam o escopo do assunto.

Equipes também podem conter subgrupos através de `group_memberships`. `GroupMembershipService` resolve membros diretos e indiretos, sem duplicações e somente por caminhos de equipes ativas. A resolução alcança permissões de conteúdo, diagnóstico, escopo administrativo e contagens de membros. Capacidades administrativas globais continuam usando apenas vínculos diretos. Consulte [grupos aninhados](grupos-aninhados.md).

O administrador global é identificado pelo papel armazenado no banco. O runtime e os serviços verificam a atividade do usuário e o contexto do recurso. Ancestrais exibidos na navegação não representam concessão herdável de escrita.

Assuntos públicos acrescentam leitura a usuários autenticados e ativos, limitada ao conteúdo publicado e à hierarquia ativa. Essa leitura não libera edição, aprovação ou administração. Veja [visibilidade](assuntos-publicos-privados.md).

As capacidades `system.settings.manage`, `system.authentication.manage`, `system.directory.manage`, `system.audit.view` e `system.tags.manage` são separadas das permissões de conteúdo. Apenas o administrador global modifica as capacidades e a gestão de equipes.

## Dois fluxos de aprovação

| Entidade | Estados | Particularidades |
| --- | --- | --- |
| Documento | `draft`, `review`, `published`, `inactive` | Revisão concluída é um metadado; aprovação exige documento em revisão e revisor registrado |
| Workspace do assunto | `draft`, `review`, `approved`, `deprecated` | Valida prontidão, guarda histórico e permite recuperar snapshot aprovado |

Documentos pendentes podem expirar após o prazo registrado em `approval_expires_at`. `app_runtime.php` chama a expiração em requisições web; não foi identificado um agendador versionado. Sem tráfego, a execução depende de outra integração operacional.

## Arquivos e conteúdo

`download.php`, `document-file.php` e `document-media.php` resolvem o recurso e validam acesso antes de entregar o arquivo. Mídia de artigo é enviada inicialmente sem documento, fica disponível ao autor e é vinculada ao salvar pelo painel. Uploads abandonados são removidos após um dia quando ocorre nova chamada de upload.

A pasta `storage/` possui `.htaccess` para Apache e um `index.php` que responde 403. Esses arquivos não comprovam bloqueio de caminhos individuais em IIS ou no servidor embutido do PHP. O servidor deve impedir acesso direto aos arquivos protegidos.

HTML formatado é filtrado por uma lista de tags, atributos e URLs. Código usa texto e seleção de linguagem; fluxos e organogramas passam por normalização estruturada. O frontend inclui bibliotecas locais e carrega Tailwind por CDN em páginas inspecionadas; não existe comando de build em `package.json`.

## Fronteiras a consolidar

`admin/index.php` ainda concentra consultas e composição da interface. As ações administrativas estão separadas por funcionalidade, mas continuam compartilhando o estado da entrada por includes PHP. A API de documentos e o painel mantêm caminhos de persistência próprios. Ambos usam serviços compartilhados, mas a paridade de comportamento deve ser verificada ao alterar um fluxo.

No código atual de `api/documents.php`, salvamento, metadados de transição e histórico editorial ocorrem antes do commit; notificações são disparadas depois. A edição também usa bloqueio e versão de documento. A [API](api.md) descreve o contrato correspondente. Essas observações de código não representam uma nova execução de regressão.

As [revisões históricas](../historico/README.md) registram problemas e propostas no estado examinado à época. Sua lista de achados não deve ser usada como inventário atualizado sem conferir o código e as evidências de correção.
