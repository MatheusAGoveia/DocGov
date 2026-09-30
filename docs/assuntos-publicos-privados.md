# Assuntos públicos e privados

A visibilidade é definida por **assunto**, em Administração → Assuntos ou Editar Estrutura → Configurações → Visão Geral. Na criação, o campo aparece depois da seleção de categoria e subcategoria e antes do nome. Uma categoria e uma subcategoria podem conter assuntos públicos e privados.

- **Privado:** leitura conforme as permissões existentes de usuários, equipes e ancestrais.
- **Público:** todos os usuários autenticados e ativos podem ler os conteúdos publicados desse assunto. Visitantes sem login não recebem esse acesso.

Categoria e subcategoria pai aparecem na navegação para permitir chegar ao assunto público. Isso não cria permissão herdável nos pais e não libera outros assuntos privados. Busca, favoritos, API, visualização, imagens e downloads usam o mesmo motor de autorização.

A leitura pública não concede edição ou administração. Rascunhos e conteúdos em revisão continuam restritos a quem pode editá-los. Categoria, subcategoria ou assunto inativo não aparece no portal.

Somente administradores do assunto podem mudar sua visibilidade; para criar um assunto público, é necessário administrar a subcategoria pai. Editores podem criar assuntos privados e editar metadados preservando a visibilidade. Ao voltar para Privado, a leitura pública termina imediatamente, e as concessões explícitas existentes continuam valendo. As mudanças são auditadas.

## Atualização do banco

Aplicar somente `database/migrations/026_subject_visibility.sql` na base existente antes de publicar o código. A migração é idempotente e mantém todos os assuntos existentes como privados. Não executar `database/schema.sql` em uma base com dados.

Na API de criação de assuntos, o campo opcional `visibility` aceita `private` ou `public`; quando omitido, vale `private`. As respostas de assuntos e a árvore de navegação incluem essa visibilidade.

## Validação

Revisão focada em autorização, herança, isolamento dos assuntos privados, alteração de visibilidade e preservação das permissões existentes, sem achados pendentes nesta implementação.

Testes locais aprovados:

- `php scratch/test_subject_visibility.php`: leitores, visitantes, usuários inativos, assuntos privados irmãos, conteúdos pendentes, escrita, diagnóstico, revogação e ancestrais inativos.
- `node scratch/test_subject_visibility_browser.js`: portal, busca, favoritos, imagens, downloads, APIs, CSRF, administradores locais, formulários, ordem dos campos e layout em 390/1440 px. Exige Playwright e Chrome; o pacote pode ser indicado por `DOCGOV_PLAYWRIGHT_PACKAGE`.
- Regressões: `test_permissions.php`, `test_permission_api.php`, `test_hierarchy_resolution.php` e `test_subject_workspace.php`.
- Sintaxe PHP/JavaScript, `git diff --check`, reaplicação idempotente da migração e limpeza das fixtures.

A suíte antiga `scratch/test_scoped_admin.php` não iniciou por depender de usuários que não existem nesta base local. O isolamento administrativo foi verificado pelos testes de API e navegador com usuários temporários.
