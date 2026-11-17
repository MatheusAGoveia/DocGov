# APIs e rotas

[Documentação](../README.md)

Referência das rotas internas do portal, originalmente revisada em 13/11/2026 por leitura do backend. Não foram feitas chamadas HTTP naquela reorganização. A API externa v1 tem [referência própria](api-integracao.md), [guia de configuração](../guias/integracoes.md) e [especificação OpenAPI](../../api/v1/openapi.json); os contratos abaixo descrevem as rotas internas.

## Navegação

- [Convenções](#convenções)
- [Estrutura](#estrutura)
- [Documentos](#documentos)
- [Permissões e pesquisa de contas/equipes](#permissões-e-pesquisa-de-contasequipes)
- [Grupos aninhados](#grupos-aninhados)
- [Importação de usuários em lote](#importação-de-usuários-em-lote)
- [Conta, favoritos e mídia](#conta-favoritos-e-mídia)

## Convenções

As APIs desta página usam **sessão PHP por cookie** e atendem o próprio portal. Para integrar outros sistemas, use a [API de integração v1](api-integracao.md) em `api/v1/`, somente leitura e autenticada por chave (`Authorization: Bearer`). CORS e autenticação são controles separados.

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
| 409 | Conflito de versão/estado em operações documentais que usam esse controle |
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
| `document_version` | Versão recebida no último salvamento; usada na edição para detectar conteúdo desatualizado |
| `text_content`, `code_language` | Texto formatado ou código, conforme o editor |
| `structured_content` | Payload normalizado para fluxo/organograma |
| `external_url` | Link externo |
| `video_source`, `video_url` | `upload` ou `url` para vídeo |
| `file`, `video_file` | Arquivo em multipart |

As ações editoriais são `save_draft`, `submit_review`, `review_document`, `approve_publish`, `request_changes` e `archive`. Publicação exige documento existente em revisão, administrador autorizado e revisão concluída. O campo legado `status=published` é convertido em submissão para revisão quando `workflow_action` está ausente.

Os limites observados nessa rota incluem 25 MB para arquivos, 50 MB para vídeo, 1 MB para código e 2 MB para HTML formatado. Limites menores do PHP/servidor podem recusar a requisição antes da aplicação.

Exemplo conceitual de criação, usando uma sessão e token previamente obtidos:

```text
POST api/documents.php
Content-Type: application/x-www-form-urlencoded
X-CSRF-Token: <token_da_sessao>

subject_id=<id>&title=Procedimento&section_key=documents&workflow_action=save_draft&text_content=<p>Conteúdo</p>
```

A resposta de sucesso contém `success`, `id`, `title`, `slug`, `status` e `document_version`. O backend reserva o slug; não dependa de um slug calculado pelo cliente. Guarde a versão retornada e envie-a ao editar. Um conflito detectado retorna 409; recarregue o documento e reveja as alterações antes de reenviar.

No código atual, a API grava o documento, os metadados da transição e o histórico na mesma transação; notificações ocorrem após o commit. Edições usam bloqueio do documento e comparação de versão. A publicação verifica que o conteúdo revisado não foi alterado. Fonte: [api/documents.php](../../api/documents.php) e [DocumentWorkflowService](../../services/DocumentWorkflowService.php). O vínculo de mídia de artigo é um fluxo separado; não presuma paridade com o painel sem verificar esse caminho.

## Permissões e pesquisa de contas/equipes

`api/permissions.php` exige sessão e administração sobre o recurso informado por `resource_type` (`category`, `subcategory`, `subject`) e `resource_id`.

- **GET:** retorna contexto, regras e indicação do administrador global.
- **POST:** usa `principal_type` (`user`, `group`; `team` é alias), `principal_id` e `permission_level` (`view`, `edit`, `admin`).
- **DELETE:** usa `permission_id` no contexto do recurso. Também aceita POST com `_method=DELETE`.

POST/DELETE exigem CSRF. O serviço verifica o escopo da regra, protege herança e registra auditoria. JSON malformado pode retornar 400.

`api/search_principals.php` lê `q`, `type`, `resource_type` e `resource_id`. A pesquisa exige administração do recurso e retorna resultados limitados; não é um diretório público. O endpoint não possui gate explícito que restrinja o método a GET.

## Grupos aninhados

`POST admin/index.php?tab=editar_grupo&id=<grupo>&group_tab=groups` recebe `group_action=add_subgroup|remove_subgroup`, `group_id` (grupo superior), `child_group_id` e `csrf_token`. Somente administrador global pode executar. Sucesso redireciona com HTTP 302; validação de ciclo, vínculo duplicado ou inexistente retorna 422; CSRF inválido retorna 419; falta de autorização retorna 403. Erros operacionais retornam 503 sem expor detalhes de banco.

Pesquisa de equipes em `api/search_principals.php` e o painel de permissões contam membros ativos diretos e indiretos sem duplicar usuários. Diagnósticos de usuário incluem o caminho de equipes que originou o acesso. Capacidades administrativas globais permanecem limitadas aos vínculos diretos. [Regras e implantação](grupos-aninhados.md).

## Importação de usuários em lote

**POST `admin/import-users.php`** processa uma etapa da lista. Exige sessão PHP e token em **`X-CSRF-Token`**; esse endpoint não lê o token de um campo de formulário. O corpo utiliza campos de formulário, sem interpretar um corpo JSON inteiro.

| Campo | Obrigatoriedade | Contrato |
| --- | --- | --- |
| `mode` | Sim | `directory` ou `group` |
| `domain` | Sim | Chave de domínio configurado |
| `entries` | Sim | String JSON contendo uma lista de 1 a 20 nomes/logins |
| `group_id` | No modo `group` | Inteiro positivo de uma equipe existente |

O campo `entries` aceita até 30.000 bytes no endpoint; cada identificador é validado no serviço e limitado a 255 caracteres. O limite de 1.000 entradas pertence à lista da interface: o cliente divide a lista em etapas de até 20.

Exemplo de campos de formulário, com sessão e token previamente obtidos:

```text
mode=directory
domain=BETIM
entries=["ana.silva","João Souza"]
```

Resposta ilustrativa de uma etapa:

```json
{
  "success": true,
  "results": [
    {
      "input": "ana.silva",
      "status": "imported",
      "message": "Importado do AD com sucesso.",
      "created": true,
      "name": "Ana Silva",
      "username": "ana.silva"
    }
  ]
}
```

HTTP 200 e `success=true` indicam que a etapa foi processada; confira cada item de `results`. Entre os estados estão `imported`, `existing`, `added`, `already_member`, `duplicate`, `not_found`, `ambiguous`, `inactive`, `invalid`, `unavailable`, `conflict` e `error`. Nome/login aparecem quando disponíveis; itens ambíguos podem incluir `candidates`.

O modo `directory` exige `system.directory.manage`; o modo `group` também exige administrador global ativo. O backend resolve a autorização a partir da sessão. Cada pessoa é persistida em uma transação própria; cadastro e vínculo de equipe são atômicos para aquela entrada. A sessão é liberada antes das consultas LDAP.

| HTTP | Condição |
| --- | --- |
| 401 | Sessão ausente |
| 405 | Método diferente de POST |
| 419 | CSRF ausente/inválido |
| 422 | Campos inválidos, JSON malformado ou etapa fora do contrato |
| 403 | Sem autorização |
| 500 | Falha interna da etapa |

Fonte: [endpoint](../../admin/import-users.php) e [BatchUserImportService](../../services/BatchUserImportService.php). O [guia de importação](../guias/importacao-usuarios-em-lote.md) descreve a experiência de uso, os relatórios e a retomada de pendências.

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

As imagens de artigos aceitam JPEG, PNG, GIF e WebP, até 10 MB e 8000 px por lado. O upload gera mídia inicialmente sem vínculo. A entrega da mídia vinculada depende da autorização do documento; sem vínculo, fica restrita ao autor. Verifique a associação ao documento no fluxo de salvamento utilizado.

## Próxima versão do contrato

As rotas internas desta página ainda não têm schemas OpenAPI, paginação uniforme ou um formato único de erros. A especificação OpenAPI existente cobre somente `api/v1/`. Algumas rotas internas de leitura não restringem explicitamente o método HTTP. Ao alterar um contrato, registre os campos, erros e comportamento de autorização e atualize a regressão correspondente; essas limitações não devem ser interpretadas como funcionalidades já implementadas.
