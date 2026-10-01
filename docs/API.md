# APIs e rotas

## Grupos aninhados

`POST admin/index.php?tab=editar_grupo&id=<grupo>&group_tab=groups` recebe `group_action=add_subgroup|remove_subgroup`, `group_id` (grupo superior), `child_group_id` e `csrf_token`. Somente administrador global pode executar. Sucesso redireciona com HTTP 302; validação de ciclo, vínculo duplicado ou inexistente retorna 422; CSRF inválido retorna 419; falta de autorização retorna 403. Erros operacionais retornam 503 sem expor detalhes de banco.

Pesquisa de equipes em `api/search_principals.php` e o painel de permissões contam membros ativos diretos e indiretos sem duplicar usuários. Diagnósticos de usuário incluem o caminho de equipes que originou o acesso. Capacidades administrativas globais permanecem limitadas aos vínculos diretos. [Regras e implantação](grupos-aninhados.md).

Inventário inicial do código em 01/10/2026. Os contratos abaixo foram lidos no backend; não houve chamada HTTP nesta etapa. Não existe uma especificação OpenAPI identificada no checkout.

## Convenções

As APIs usam **sessão PHP por cookie**. Não foi identificado um mecanismo de token de API. CORS e autenticação são controles separados.

Nas APIs administrativas, envie o token CSRF da sessão em `X-CSRF-Token` ou `csrf_token`. As rotas de categorias, subcategorias, assuntos e documentos leem campos de formulário; use `application/x-www-form-urlencoded` ou `multipart/form-data` quando houver upload. `api/permissions.php` também aceita JSON, mesclado com os parâmetros da URL/formulário.

Respostas usuais:

```json
{"success": true, "data": []}
```

```json
{"success": false, "error": "Descrição do erro"}
```

As rotas de mídia de artigo usam `ok` em vez de `success`. Os códigos HTTP e formatos não são inteiramente uniformes; alguns erros de validação ainda retornam HTTP 200. Verifique o payload além do status.

| Código | Uso observado |
| --- | --- |
| 401 | Sessão ausente |
| 403 | Operação fora do escopo autorizado |
| 404 | Recurso/arquivo inexistente em rotas que usam esse código |
| 405 | Método recusado em rotas que o implementam |
| 413 | POST excedeu o limite, no upload de mídia de artigo |
| 419 | Token CSRF inválido/ausente |
| 422 | Algumas validações de seleção, conteúdo e transição |
| 500 | Falha interna |
| 503 | Bloqueio por manutenção nas áreas afetadas |

## Estrutura

| Endpoint | Métodos implementados | Entradas principais | Regra |
| --- | --- | --- | --- |
| `api/categories.php` | GET, POST | POST: `name`, `description` | Lista categorias permitidas; criar exige administrador global |
| `api/subcategories.php` | GET, POST | GET: `category_id` ou `category_slug`; POST: `category_id`, `name`, `description` | Lista no escopo; criar exige edição/administração na categoria pai |
| `api/subjects.php` | GET, POST | GET: `subcategory_id` ou `subcategory_slug`; POST: `subcategory_id`, `name`, `description`, `visibility` | Criar exige edição/administração; `public` exige administração da subcategoria |
| `api/tree.php` | Leitura, sem gate explícito de método | Sessão | Árvore filtrada por `getAccessibleResourceTree()` |

`visibility` aceita `private` ou `public` e o padrão de criação é privado. IDs são mais seguros como contexto de integração do que apenas nomes/slugs repetidos em ramos diferentes. Algumas rotas aceitam aliases de campos em português; prefira os nomes principais da tabela.

## Documentos

**GET `api/documents.php`** aceita `subject_id` (alias `assunto_id`) e `status`: `published` por padrão, `draft`, `review`, `inactive` ou `all`. A consulta usa o **escopo administrativo de edição/administração**; não é uma API de leitura geral para leitores do portal. Sem assunto explícito, a consulta limita o resultado a 50 documentos. Não há contrato de paginação nessa rota.

**POST `api/documents.php`** cria ou atualiza um documento com token CSRF e permissão no documento/assunto de destino.

| Campo | Papel |
| --- | --- |
| `id` ou `document_id` | Ausente/zero para criar; positivo para editar |
| `subject_id` | Assunto de destino; na edição pode ser recuperado do documento atual |
| `title`, `description` | Identificação e resumo |
| `section_key` | Seção ativa que determina o editor e o tipo de conteúdo |
| `content_type` | Compatibilidade: usado para escolher a seção padrão quando não informada |
| `workflow_action`, `workflow_note` | Ação editorial e parecer/justificativa |
| `text_content`, `code_language` | Texto formatado ou código, conforme o editor |
| `structured_content` | Payload normalizado para fluxo/organograma |
| `external_url` | Link externo |
| `video_source`, `video_url` | `upload` ou `url` para vídeo |
| `file`, `video_file` | Arquivo em multipart |

As ações editoriais são `save_draft`, `submit_review`, `review_document`, `approve_publish`, `request_changes` e `archive`. Publicação exige documento existente em revisão, administrador autorizado e revisão concluída. O campo legado `status=published` é convertido em submissão para revisão quando `workflow_action` está ausente.

Os limites observados nessa rota incluem 25 MB para arquivos, 250 MB para vídeo, 1 MB para código e 2 MB para HTML formatado. Limites menores do PHP/servidor podem recusar a requisição antes da aplicação.

Exemplo conceitual de criação, usando uma sessão e token previamente obtidos:

```text
POST api/documents.php
Content-Type: application/x-www-form-urlencoded
X-CSRF-Token: <token_da_sessao>

subject_id=<id>&title=Procedimento&section_key=documents&workflow_action=save_draft&text_content=<p>Conteúdo</p>
```

A resposta de sucesso contém `id`, `title`, `slug` e `status`. O backend reserva o slug; não dependa de um slug calculado pelo cliente.

Há divergências conhecidas entre API e painel no vínculo de mídia e na transação editorial: CR-05 e CR-06 no [review](CODE_REVIEW_2026-10-01.md).

## Permissões e pesquisa de contas/equipes

`api/permissions.php` exige sessão e administração sobre o recurso informado por `resource_type` (`category`, `subcategory`, `subject`) e `resource_id`.

- **GET:** retorna contexto, regras e indicação do administrador global.
- **POST:** usa `principal_type` (`user`, `group`; `team` é alias), `principal_id` e `permission_level` (`view`, `edit`, `admin`).
- **DELETE:** usa `permission_id` no contexto do recurso. Também aceita POST com `_method=DELETE`.

POST/DELETE exigem CSRF. O serviço verifica o escopo da regra, protege herança e registra auditoria. JSON malformado pode retornar 400.

`api/search_principals.php` lê `q`, `type`, `resource_type` e `resource_id`. A pesquisa exige administração do recurso e retorna resultados limitados; não é um diretório público. O endpoint não possui gate explícito que restrinja o método a GET.

## Conta, favoritos e mídia

`api_user.php` aceita POST e CSRF. As ações observadas são `set_theme`/`update_theme`, `update_portal_theme`, `toggle_favorito`, `record_view`, `upload_avatar` e `remove_avatar`. Favoritos usam `target_type` (`document`, `subcategory`, `subject`) e `target_id`, com verificação de acesso. Algumas ações de avatar respondem com redirecionamento.

| Rota | Função | Controle |
| --- | --- | --- |
| `document-media-upload.php` | POST de `image`, `subject_id` ou `document_id`, `csrf_token` | Exige edição/criação no contexto; retorna `ok`, `id` e `url` |
| `document-media.php?id=...` | Imagem de artigo | Documento vinculado exige leitura; upload sem vínculo fica restrito ao autor |
| `document-file.php?id=...` | Visualização inline de arquivo/vídeo local | Autorização, caminho resolvido e suporte a Range |
| `download.php?id=...` | Download; opção `inline` | Autorização antes da entrega |
| `category_image.php`, `subcategory_image.php` | Imagens da estrutura | Gateways de imagem com contexto de acesso |
| `app_logo.php` | Identidade visual do portal | Rota permitida durante manutenção |

As imagens de artigos aceitam JPEG, PNG, GIF e WebP, até 10 MB e 8000 px por lado. O upload gera uma mídia inicialmente sem vínculo; para salvar via API de documentos, veja a lacuna CR-05.

## Próxima versão do contrato

Formalizar schemas por endpoint, campos obrigatórios, paginação e erros. Padronizar os métodos de leitura e o uso de status HTTP. A importação de usuários em alteração local deve ser adicionada depois da revisão do fluxo. Cada mudança de contrato deve acompanhar teste de autorização e atualização deste documento.
