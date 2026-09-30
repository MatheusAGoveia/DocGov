# Revisão de código — 30/09/2026

Escopo: alterações locais sobre `078f073`, incluindo temas, avatar, favicon, gestão de documentos, catálogo e testes. Não foram encontrados problemas P0 ou P1 nesse escopo.

## Achado

### [P2] A preferência de cor escolhida no painel não é enviada ao servidor

Local: `admin/index.php:3705` e `partials/theme_dropdown.php`, em `setPortalAccentTheme`.

O novo seletor do painel usa o mesmo partial do portal. O partial procura `meta[name="csrf-token"]` e retorna antes de chamar a API quando o token está ausente. O cabeçalho de `admin/index.php` não inclui essa meta, embora exista um token nos formulários. Assim, a seleção altera o visual e o armazenamento local, mas não atualiza a preferência da conta no servidor; outro navegador continuará usando a preferência anterior.

Reprodução com a API interceptada, sem modificar a preferência real:

- Painel: zero metas CSRF e zero requisições de persistência após escolher uma cor.
- Portal: uma meta CSRF e uma requisição de persistência para a mesma ação.

Correção sugerida: disponibilizar o token CSRF no cabeçalho do painel, escapado como nas páginas do portal, e acrescentar uma verificação de persistência ao teste do seletor. O achado permanece pendente neste commit.

## Validação

- Sintaxe dos 13 arquivos PHP e dos 9 arquivos JavaScript alterados ou novos: aprovada.
- `git diff --check`: aprovado.
- Sete testes de navegador: filtros e paginação, linha clicável, menu de ações, favicon, avatar, modo escuro e catálogo.
- Prévia do script de catálogo com `--dry-run`: aprovada; nenhuma publicação executada.
- Busca por tokens e chaves privadas reconhecíveis nos textos pendentes: sem ocorrências.

O teste do catálogo falhou inicialmente por rolagem horizontal em 390 px. Uma inspeção adicional mostrou largura de 390 px sem elementos excedentes, e a repetição da jornada completa passou em 390, 1440 e 2560 px. A causa da falha inicial não foi estabelecida; não foi possível reproduzi-la na repetição.
