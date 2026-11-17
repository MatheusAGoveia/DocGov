# Assuntos públicos e privados

[Documentação](../README.md)

A visibilidade é definida por **assunto**, em Administração → Assuntos ou Editar Estrutura → Configurações → Visão Geral. Na criação, o campo aparece depois da seleção de categoria e subcategoria e antes do nome. Uma categoria e uma subcategoria podem conter assuntos públicos e privados.

- **Privado:** leitura conforme as permissões existentes de usuários, equipes e ancestrais.
- **Público:** todos os usuários autenticados e ativos podem ler os conteúdos publicados desse assunto. Visitantes sem conta também podem lê-los quando a [entrada de visitantes](#entrada-sem-conta-visitantes) está ligada.

Categoria e subcategoria pai aparecem na navegação para permitir chegar ao assunto público. Isso não cria permissão herdável nos pais e não libera outros assuntos privados. Busca, favoritos, API, visualização, imagens e downloads usam o mesmo motor de autorização.

A leitura pública não concede edição ou administração. Rascunhos e conteúdos em revisão continuam restritos a quem pode editá-los. Categoria, subcategoria ou assunto inativo não aparece no portal.

Somente administradores do assunto podem mudar sua visibilidade; para criar um assunto público, é necessário administrar a subcategoria pai. Editores podem criar assuntos privados e editar metadados preservando a visibilidade. Ao voltar para Privado, a leitura pública termina imediatamente, e as concessões explícitas existentes continuam valendo. As mudanças são auditadas.

## Entrada sem conta (visitantes)

Com **Configurações → Segurança → Permitir entrada sem conta** ligado (padrão), links de documentos publicados de assuntos públicos abrem diretamente, sem exigir login ou clique em **Entrar sem conta**. A abertura inicia o modo visitante e permite continuar navegando no acervo público. O login também mantém o botão **Entrar sem conta**. O visitante:

- lê apenas documentos **publicados** de assuntos **públicos** com categoria, subcategoria e assunto ativos — inclusive busca, árvore, imagens, mídia de artigos e downloads, que usam o mesmo motor de permissões;
- não vê rascunhos, itens em revisão, assuntos privados (nem irmãos de um assunto público) e não recebe edição, administração, favoritos, notificações ou Minha Conta;
- vê o processo do assunto (objetivo, etapas, FAQ) somente na versão aprovada vigente; rascunho, revisão ou processo descontinuado aparecem vazios, e o histórico de edições não é exibido;
- vê no filtro de busca apenas as tags de documentos que pode ler;
- vê o aviso “navegando como visitante” e o botão **Entrar** para trocar para a conta corporativa.

A regra fica em `PermissionService` (visitante = `userId` 0) e o modo visitante da sessão em `GuestAccessService`. Antes de iniciar o modo visitante por um link direto, o visualizador verifica a publicação, a visibilidade pública e a hierarquia ativa no mesmo motor de permissões. Links privados ou não publicados sem sessão continuam levando ao login com o destino preservado (`login.php?next=`), validado contra uma lista fixa de páginas para evitar redirecionamento aberto. Um visitante que já está navegando recebe 403 nesses conteúdos. A entrada direta na página inicial sem sessão continua passando pelo login. Desligar a opção também exige login nos links públicos e encerra imediatamente a navegação e os downloads anônimos; contas autenticadas não são afetadas. A [API de integração](api-integracao.md) com chave pública lê o mesmo acervo, independentemente dessa opção.

Regressão: `php scratch/test_guest_access.php` (motor de permissões e destinos) e `php scratch/test_api_v1.php` (fluxo HTTP do visitante e da API).

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
