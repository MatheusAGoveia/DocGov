# Mapa do painel administrativo

[Documentação](../docs/README.md) · [Arquitetura](../docs/referencia/arquitetura.md) · [Testes](../docs/desenvolvimento/testes.md)

Use este mapa para localizar o código de um comportamento. As ações ficam em `modules/<área>/actions/`, as regras reutilizáveis em `../services/` e as funções de interface em `../assets/admin/`.

## Fluxo de uma requisição

1. [index.php](index.php) inicia sessão/conexão, configura os serviços e verifica o acesso ao painel.
2. [dispatch.php](dispatch.php) inclui os manipuladores numa lista estática, preservando a ordem das ações.
3. O manipulador valida entrada, CSRF e autorização, chama os serviços e produz a resposta ou o estado do formulário.
4. `index.php` prepara os dados das telas e renderiza o HTML.
5. [client-config.php](partials/client-config.php) serializa os dados da página para os scripts, com escape para o contexto HTML.

Os manipuladores são includes internos, sem acesso HTTP direto. Compartilham o escopo da entrada; cada cabeçalho indica as variáveis e serviços consumidos. `DOCGOV_PROJECT_ROOT` mantém o destino dos caminhos físicos após a extração.

## Documentos

| Quero alterar… | Manipulador | Serviços relacionados |
| --- | --- | --- |
| Criar ou editar um documento | [documents/actions/save.php](modules/documents/actions/save.php) | [Workflow](../services/DocumentWorkflowService.php), [seções](../services/DocumentSectionService.php), [slug](../services/DocumentSlugService.php), [tags](../services/TagService.php) |
| Enviar arquivos em lote | [batch-upload.php](modules/documents/actions/batch-upload.php) | [BatchDocumentUploadService](../services/BatchDocumentUploadService.php) |
| Recuperar dados do formulário após erro | [recover-submission.php](modules/documents/actions/recover-submission.php) | Estado compartilhado com o salvamento e a renderização |
| Revisar, aprovar ou solicitar ajustes pela listagem | [transition.php](modules/documents/actions/transition.php) | [DocumentWorkflowService](../services/DocumentWorkflowService.php) |
| Enviar à lixeira, restaurar ou excluir | [trash.php](modules/documents/actions/trash.php) | Workflow, [mídia](../services/DocumentInlineMediaService.php) e auditoria |
| Processar ações editoriais em lote | [batch.php](modules/documents/actions/batch.php) | Workflow e [UsageAuditService](../services/UsageAuditService.php) |

O upload em lote é avaliado antes do salvamento individual. A recuperação de submissão continua depois do salvamento; mover sua posição altera o estado apresentado no formulário.

## Estrutura e processo do assunto

| Quero alterar… | Manipulador | Serviços relacionados |
| --- | --- | --- |
| Criar/editar categoria | [save-category.php](modules/structure/actions/save-category.php) | [PermissionService](../services/PermissionService.php), [CategoryImageService](../services/CategoryImageService.php) |
| Criar/editar subcategoria | [save-subcategory.php](modules/structure/actions/save-subcategory.php) | Permissões, imagens e [HierarchyService](../services/HierarchyService.php) |
| Criar/editar assunto e sua visibilidade | [save-subject.php](modules/structure/actions/save-subject.php) | Permissões e hierarquia |
| Descartar ou restaurar um ramo | [archive.php](modules/structure/actions/archive.php) | [StructureArchiveService](../services/StructureArchiveService.php) |
| Excluir um ramo permanentemente | [permanent-delete.php](modules/structure/actions/permanent-delete.php) | [StructureDeletionService](../services/StructureDeletionService.php) |
| Salvar seções do processo | [subject-workspaces/actions/save.php](modules/subject-workspaces/actions/save.php) | [SubjectWorkspaceService](../services/SubjectWorkspaceService.php) |
| Revisar/aprovar o processo do assunto | [subject-workspaces/actions/transition.php](modules/subject-workspaces/actions/transition.php) | SubjectWorkspaceService e notificações |

## Usuários, equipes e acesso

| Quero alterar… | Manipulador/entrada | Serviços relacionados |
| --- | --- | --- |
| Importar uma conta do AD | [users/actions/create.php](modules/users/actions/create.php) | [ActiveDirectoryAuthService](../services/ActiveDirectoryAuthService.php) |
| Replicar contas do diretório | [replicate.php](modules/users/actions/replicate.php) | ActiveDirectoryAuthService |
| Obter ficha e diagnóstico do usuário | [details.php](modules/users/actions/details.php) | [PermissionService](../services/PermissionService.php), grupos e AD |
| Conceder permissão individual | [grant-permission.php](modules/users/actions/grant-permission.php) | PermissionService |
| Remover permissão individual | [revoke-permission.php](modules/users/actions/revoke-permission.php) | PermissionService |
| Criar/editar equipe ou gerenciar membros | [groups/actions/manage.php](modules/groups/actions/manage.php) | Permissões, notificações e auditoria |
| Gerenciar subgrupos | [groups/actions/manage.php](modules/groups/actions/manage.php) | [GroupMembershipService](../services/GroupMembershipService.php) |
| Delegar capacidades administrativas | [groups/actions/manage.php](modules/groups/actions/manage.php) | [SystemAccessService](../services/SystemAccessService.php) |
| Importar uma lista de pessoas/membros | [import-users.php](import-users.php) | [BatchUserImportService](../services/BatchUserImportService.php) |
| Alterar o cálculo de acesso ao conteúdo | [PermissionService](../services/PermissionService.php) | [Referência de permissões](../docs/referencia/permissoes.md) |

## Autenticação, configurações e auditoria

| Quero alterar… | Manipulador | Serviço relacionado |
| --- | --- | --- |
| Testar conexão/conta de leitura | [test-connection.php](modules/authentication/actions/test-connection.php) | [ActiveDirectoryAuthService](../services/ActiveDirectoryAuthService.php) |
| Monitorar saúde dos servidores | [health.php](modules/authentication/actions/health.php) | ActiveDirectoryAuthService |
| Criar um domínio | [create-domain.php](modules/authentication/actions/create-domain.php) | [SystemSettingsService](../services/SystemSettingsService.php) |
| Excluir um domínio | [delete-domain.php](modules/authentication/actions/delete-domain.php) | SystemSettingsService |
| Salvar servidores e autenticação | [save-settings.php](modules/authentication/actions/save-settings.php) | SystemSettingsService |
| Salvar configurações gerais/manutenção | [settings/actions/save.php](modules/settings/actions/save.php) | SystemSettingsService |
| Gerenciar tags | [tags/actions/manage.php](modules/tags/actions/manage.php) | [TagService](../services/TagService.php) |
| Exportar auditoria AD em CSV | [audit/actions/export.php](modules/audit/actions/export.php) | Consulta de auditoria e autorização existentes |

Os blocos históricos [test-connection-legacy.php](modules/authentication/actions/test-connection-legacy.php) e [replicate-legacy.php](modules/users/actions/replicate-legacy.php) conservam a posição e as condições anteriores. O despacho não os funde; os caminhos iniciais correspondentes podem encerrar a requisição antes deles.

## Funções JavaScript

| Arquivo | Responsabilidade e funções para procurar |
| --- | --- |
| [navigation.js](../assets/admin/navigation.js) | Sidebar e submenus: `toggleSubmenu`, `toggleMobileSidebar`, `toggleMainSidebar` |
| [document-list.js](../assets/admin/document-list.js) | Linhas, menus, seleção em lote e filtros: `openDocumentRow`, `toggleActionMenu`, `updateBatchToolbar`, `onFilterCategoryChange` |
| [document-editor.js](../assets/admin/document-editor.js) | Hierarquia, tipos, tags, previews e upload: `onCategoryChange`, `toggleFormContent`, `getVideoEmbedUrl`, `initDocumentTags`, `initBatchFileUpload`, `setEditorMode` |
| [structure.js](../assets/admin/structure.js) | Formulários da estrutura e árvore: `ncSwitchType`, `ncLoadSubcatsForAssunto`, `toggleTreeNode`, `filterTreeNodes` |
| [groups.js](../assets/admin/groups.js) | Membros: `filterTeamMemberOptions`, `selectTeamMember` |

As 33 funções públicas conservam os nomes usados no HTML. Os scripts são clássicos; `structure.js` carrega antes de `document-editor.js`, que contém a inicialização no `DOMContentLoaded`. A configuração da página vem de `window.DocGovAdminConfig`; ela não contém credenciais de AD.

## Onde ficam telas e consultas

As consultas que preparam listagens, dashboards e formulários e a maior parte do HTML/CSS continuam em [index.php](index.php). Componentes já separados ficam em [partials/](partials/). Esta etapa separa ações e funções de interface; a decomposição de consultas e templates é uma evolução posterior.

Para buscar uma função pelo nome a partir da raiz:

```powershell
rg -n 'function nomeDaFuncao' admin assets/admin services
```

## Validação

O runner [test_admin_architecture.php](../scratch/test_admin_architecture.php) cria PostgreSQL temporário, sessões e servidor HTTP exclusivos, compara contratos e pode executar navegador/regressões. Consulte [testes](../docs/desenvolvimento/testes.md) para os comandos e requisitos.
