# API de integração (v1)

[Documentação](../README.md) · [Guia de configuração e uso](../guias/integracoes.md) · [APIs e rotas do portal](api.md) · [Especificação OpenAPI](../../api/v1/openapi.json)

API **somente leitura** para que outros sistemas consultem o acervo do DocGov: site institucional, intranet, busca corporativa, robôs de sincronização. Ela é separada das rotas internas em `api/*.php`, que usam sessão por cookie e CSRF.

Referência conferida com o código disponível em **17/11/2026**. Para emitir uma chave e testar a primeira conexão, comece pelo [guia de integrações](../guias/integracoes.md).

## Navegação

- [Visão geral](#visão-geral)
- [Chaves de acesso](#chaves-de-acesso)
- [Autenticação](#autenticação)
- [Endpoints](#endpoints)
- [Exemplos com cURL](#exemplos-com-curl)
- [Formato das respostas](#formato-das-respostas)
- [Sincronização incremental](#sincronização-incremental)
- [Implantação](#implantação)
- [Limitações conhecidas](#limitações-conhecidas)

## Visão geral

| Item | Contrato |
| --- | --- |
| Base | `<instalação>/api/v1/` |
| Métodos | `GET` (e `HEAD`); qualquer outro responde 405 |
| Autenticação | Chave por requisição, sem sessão e sem cookies |
| Conteúdo exposto | Somente documentos **publicados**, de assuntos e hierarquia **ativos** |
| Autorização | Mesmo motor do portal (`PermissionService`) |
| Formato | JSON UTF-8; arquivos em `document-file.php` |
| Limite | Por chave, por minuto (padrão 120) |

A URL-base inclui o caminho completo da instalação, por exemplo `https://portal.exemplo/docgov/api/v1/`. A especificação OpenAPI usa `/api/v1` como servidor padrão na raiz do domínio; ajuste esse valor no cliente quando houver subdiretório.

O acesso público do portal é independente da autenticação da API: um link `ver_conteudo.php?id=` pode abrir sem login quando a entrada de visitantes está ligada, mas uma chamada à API sempre exige chave, mesmo para o mesmo documento público.

## Chaves de acesso

O **Super Admin** emite e revoga chaves em **Administração → Sistema → Integrações (API)**. Ao criar, escolha:

| Escopo | O que a chave lê |
| --- | --- |
| Somente documentos públicos | O mesmo acervo de um visitante sem conta. Funciona mesmo que a entrada de visitantes no portal esteja desligada. |
| Leitura de uma conta | Os documentos publicados que a conta informada pode ler. Use uma conta de serviço com permissões mínimas. Contas de administrador global são recusadas; se a conta for desativada ou promovida a administrador global, a chave para de funcionar. |

Também é possível definir validade (data) e limite por minuto (10–1000). O token aparece **uma única vez** após a criação; o banco guarda apenas o prefixo de identificação e o hash SHA-256. Se a chave for perdida ou exposta, revogue-a e gere outra: a revogação vale na requisição seguinte.

A conta vinculada precisa existir, estar ativa e ser identificada por um login único entre as contas ativas. Não há autenticação LDAP a cada consulta: a chave é validada pelo DocGov, que aplica as permissões atuais da conta. A operação de criação/rotação e as situações exibidas no painel estão no [guia administrativo](../guias/integracoes.md#2-gerar-a-chave).

## Autenticação

Envie a chave em um destes cabeçalhos:

```text
Authorization: Bearer dgv_<prefixo>_<segredo>
X-API-Key: dgv_<prefixo>_<segredo>
```

A chave **nunca** é aceita na URL. Chave ausente, inválida, expirada, revogada ou vinculada a conta inativa responde `401` com `WWW-Authenticate: Bearer`, sem distinguir o motivo.

## Endpoints

| Endpoint | Finalidade |
| --- | --- |
| `status.php` | Valida a chave; retorna versão, horário do servidor e identificação da chave |
| `tree.php` | Categorias → subcategorias → assuntos legíveis, com `slug`, `description`, `visibility` e `published_documents` |
| `documents.php` | Lista paginada de documentos publicados |
| `documents.php?id=<id>` | Detalhe de um documento, com o campo `content` |
| `document-file.php?id=<id>` | Download do arquivo anexo (`Content-Disposition: attachment`) |

Filtros de `documents.php`:

| Parâmetro | Regra |
| --- | --- |
| `id` | Inteiro positivo para obter um detalhe; nesse modo os filtros de listagem não são aplicados |
| `subject_id`, `subcategory_id`, `category_id` | IDs inteiros; assunto fora do escopo responde 404. Categoria/subcategoria sem resultados legíveis retorna lista vazia |
| `q` | Texto no título ou na descrição (até 120 caracteres; `%` e `_` são literais) |
| `content_type` | `file`, `text`, `link`, `code`, `video`, `flow`, `orgchart` |
| `tag` | Nome ou apelido de uma tag ativa, até 80 caracteres; tag não encontrada retorna lista vazia |
| `updated_since` | Data ISO 8601, por exemplo `2026-11-01T00:00:00-03:00`, até 40 caracteres; comparação inclusiva (`updated_at >= updated_since`) |
| `page`, `per_page` | Página de 1 a 100000 (padrão 1); `per_page` de 1 a 100 (padrão 20) |

A lista é ordenada por `updated_at` decrescente e depois por `id`. Rascunhos, itens em revisão, inativos ou na lixeira nunca aparecem, nem para chaves vinculadas a editores.

IDs aceitos têm valor máximo de 2147483647. Os filtros de listagem são combinados entre si. A busca em `q` considera título e descrição, sem diferenciar maiúsculas de minúsculas; ela não pesquisa o corpo do documento.

O campo `content` aparece somente no detalhe e depende do tipo:

| `content.format` | Campos adicionais | Uso |
| --- | --- | --- |
| `html` | `html` | Texto formatado, sanitizado pelo mesmo filtro do portal |
| `code` | `language`, `source` | Linguagem e código-fonte |
| `structured` | `data` | Dados do fluxo ou organograma |
| `url` | `url` | Endereço externo de link ou vídeo |
| `file` | Nenhum | Anexo disponível em `links.file` |
| `none` | Nenhum | Sem representação adicional de conteúdo |

A lista e o detalhe trazem `id`, `title`, `slug`, `description`, `content_type`, `section`, `category`, `subcategory`, `subject`, `tags`, `published_at`, `updated_at`, `file`, `external_url` e `links`. As datas são serializadas com fuso; `published_at`, `file` e `external_url` podem ser nulos. `file.size_bytes` também pode ser nulo. `tags` contém nomes das tags ativas.

Os links em `links` são relativos à URL-base completa de `api/v1/`. Quando não existe arquivo local, `file` e `links.file` são nulos; links e vídeos externos usam `external_url` e `content.url`. Não concatene esses caminhos à raiz do domínio quando o DocGov estiver em subdiretório.

## Exemplos com cURL

Os comandos abaixo usam um shell Bash e pressupõem `DOCGOV_BASE_URL` e `DOCGOV_API_KEY` no ambiente do consumidor. A URL-base deve terminar em `/api/v1/`. O guia contém [exemplos equivalentes em PowerShell](../guias/integracoes.md#3-testar-a-conexão).

```bash
# Validar a chave e conferir o limite configurado.
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $DOCGOV_API_KEY" \
  "${DOCGOV_BASE_URL%/}/status.php"

# Descobrir os IDs de categorias, subcategorias e assuntos legíveis.
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $DOCGOV_API_KEY" \
  "${DOCGOV_BASE_URL%/}/tree.php"

# Filtrar a lista; IDs, termo e data são exemplos.
curl --fail-with-body --silent --show-error --get \
  -H "Authorization: Bearer $DOCGOV_API_KEY" \
  --data-urlencode 'subject_id=12' \
  --data-urlencode 'q=procedimento' \
  --data-urlencode 'updated_since=2026-11-01T00:00:00-03:00' \
  --data-urlencode 'page=1' --data-urlencode 'per_page=50' \
  "${DOCGOV_BASE_URL%/}/documents.php"

# Consultar um detalhe: substitua 123 por um ID da lista.
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $DOCGOV_API_KEY" \
  "${DOCGOV_BASE_URL%/}/documents.php?id=123"

# Baixar o anexo; execute quando links.file não for nulo.
curl --fail --silent --show-error \
  -H "Authorization: Bearer $DOCGOV_API_KEY" \
  --output documento.bin \
  "${DOCGOV_BASE_URL%/}/document-file.php?id=123"
```

`--fail-with-body` exige cURL 7.76 ou superior. Em versões anteriores, use `--fail` e confira o status HTTP; o corpo de erro pode não ser exibido. O download entrega bytes, não um envelope JSON. Os outros endpoints retornam JSON. A chave deve acompanhar **todas** as requisições, inclusive downloads.

## Formato das respostas

### Verificação da chave

Exemplo ilustrativo de HTTP 200 em `status.php`:

```json
{
  "success": true,
  "data": {
    "api_version": "1.0",
    "server_time": "2026-11-17T09:00:00-03:00",
    "client": {
      "id": 1,
      "name": "Portal institucional",
      "access_scope": "public",
      "rate_limit_per_minute": 120
    }
  }
}
```

### Lista e detalhe

Na lista, `data` é um array e `meta` descreve a paginação. Uma lista vazia é uma resposta válida:

```json
{"success": true, "data": [], "meta": {"page": 1, "per_page": 20, "total": 0, "total_pages": 0}}
```

Em `tree.php`, `data` também é um array e `meta.subjects` informa o número de assuntos legíveis. Cada categoria contém `subcategories`, e cada subcategoria contém `subjects`; os assuntos incluem `visibility` e `published_documents`. Um assunto legível pode ter zero documentos publicados.

No detalhe, `data` é um objeto. Exemplo de um documento de texto com dados fictícios:

```json
{
  "success": true,
  "data": {
    "id": 123,
    "title": "Procedimento de atendimento",
    "slug": "procedimento-de-atendimento",
    "description": "Orientações para abertura de uma solicitação.",
    "content_type": "text",
    "section": {"key": "documents", "label": "Documentos"},
    "category": {"id": 1, "name": "Atendimento", "slug": "atendimento"},
    "subcategory": {"id": 2, "name": "Serviços", "slug": "servicos"},
    "subject": {"id": 12, "name": "Solicitações", "slug": "solicitacoes", "visibility": "public"},
    "tags": ["Orientação"],
    "published_at": "2026-11-16T10:00:00-03:00",
    "updated_at": "2026-11-17T08:00:00-03:00",
    "file": null,
    "external_url": null,
    "links": {"self": "documents.php?id=123", "file": null},
    "content": {"format": "html", "html": "<p>Abra a solicitação no portal de atendimento.</p>"}
  }
}
```

Na lista, cada item tem os mesmos metadados, sem `content`.

### Erros e limites

```json
{"success": false, "error": "Mensagem legível", "code": "invalid_parameter"}
```

| HTTP | `code` | Situação |
| --- | --- | --- |
| 401 | `unauthorized` | Chave ausente ou não aceita |
| 404 | `not_found`, `no_file`, `file_missing` | Recurso inexistente, fora do escopo ou sem arquivo |
| 405 | `method_not_allowed` | Método diferente de GET/HEAD |
| 422 | `invalid_parameter` | Parâmetro fora do contrato |
| 429 | `rate_limited` | Limite excedido; respeite `Retry-After` |
| 503 | Sem `code`; `error = maintenance` | Manutenção com a área API afetada, tratada pelo runtime antes do bootstrap da API |
| 500 | `internal_error` | Falha interna (detalhes apenas no log do servidor) |

Após a autenticação e a contabilização da requisição, a resposta traz:

| Cabeçalho | Significado |
| --- | --- |
| `X-DocGov-API-Version` | Versão `1.0` nas respostas produzidas pela API |
| `X-RateLimit-Limit` | Chamadas permitidas por minuto para a chave |
| `X-RateLimit-Remaining` | Saldo da janela corrente |
| `X-RateLimit-Reset` | **Segundos** até a próxima janela; não é um timestamp Unix |
| `Retry-After` | Segundos de espera em 429; também pode aparecer durante manutenção |

O limite usa uma janela fixa de um minuto. Todas as chamadas com chave válida contam, inclusive `status.php`, `HEAD` e consultas que depois retornam erro de parâmetro ou recurso. A janela é compartilhada entre todos os processos que usam essa chave. Use timeout nas requisições e tentativas limitadas com espera para 429 e falhas transitórias; 401, 404 e 422 exigem revisão da chave, do acesso ou da chamada.

A manutenção tem envelope próprio, produzido antes da autenticação; não dependa exclusivamente de `code` para tratar HTTP 503:

```json
{"success": false, "error": "maintenance", "message": "Mensagem de manutenção configurada no portal."}
```

## Sincronização incremental

1. Na primeira execução, leia todas as páginas sem `updated_since`. Guarde o horário do **início** da execução, preferencialmente obtido de `status.php`, para reduzir diferenças de relógio entre servidores.
2. Nas execuções seguintes, chame `documents.php?updated_since=<início da última execução concluída>` e percorra `page` até `meta.total_pages`. Codifique o parâmetro da data na URL.
3. Identifique os itens por `id` e faça inclusão ou atualização no destino, tolerando repetições. A comparação de data é inclusiva. Consulte o detalhe e o arquivo quando necessário.
4. Só avance o horário persistido depois de concluir todas as páginas e gravações. Em falha parcial, mantenha o horário anterior para permitir reprocessamento.
5. Periodicamente, execute uma leitura completa com a mesma chave e filtros, comparando IDs. Retire ou restrinja no destino os itens que deixaram de estar acessíveis **somente após concluir essa leitura com sucesso**.

Não há snapshot entre páginas: alterações concorrentes podem deslocar itens na paginação por número de página. Considere sobreposição de datas, reprocessamento e reconciliação completa conforme a consistência exigida pelo consumidor.

Despublicação, exclusão, mudança de visibilidade, alteração de permissões ou desativação da hierarquia não produzem eventos de remoção. Mudanças apenas no acesso também podem não alterar `documents.updated_at`, portanto o filtro incremental sozinho não descobre todos os documentos recém-liberados ou removidos do escopo. Dados já copiados não são apagados pela revogação de uma chave.

## Implantação

1. Aplique `database/migrations/029_api_clients.sql` na base existente (idempotente; cria `api_clients` e `api_client_rate_windows`).
2. Publique o código e confirme que `api/v1/bootstrap.php` responde 404 quando acessado diretamente.
3. Em Apache com PHP-FPM/CGI, o cabeçalho `Authorization` pode não chegar ao PHP; habilite `CGIPassAuth On` ou oriente os clientes a usar `X-API-Key`. No IIS, confira o repasse do cabeçalho.
4. Para chamadas a partir de navegadores de outra origem, habilite o CORS em Configurações; os cabeçalhos `Authorization` e `X-API-Key` já são aceitos no preflight. Integrações servidor-a-servidor não precisam de CORS.
5. Publique somente sob HTTPS: a chave trafega em cabeçalho.
6. A chave é um segredo de servidor: não a embuta em JavaScript servido ao navegador nem em aplicativos distribuídos. Mesmo com CORS liberado, quem tiver a chave lê tudo o que ela alcança.
7. O limite por minuto só conta requisições com chave válida. Chamadas com chaves inválidas custam uma consulta indexada cada; para conter abuso em volume, configure limite por IP no servidor web, proxy reverso ou WAF.

As chamadas de detalhe e download são registradas na auditoria de uso (`document_view`, `document_download`) com `channel = api` e o ID da chave; a criação e a revogação de chaves também são auditadas.

## Limitações conhecidas

- A API é somente leitura; criação e edição continuam pelo portal e pelo painel.
- Imagens inseridas no corpo de artigos apontam para `document-media.php`, um gateway do portal fora da API v1. As imagens de documentos públicos são acessíveis anonimamente quando a entrada de visitantes está ligada; uma chave de API vinculada não autentica a entrega de imagens privadas por esse gateway.
- Não há webhooks; use a sincronização incremental.
- O limite por minuto é por chave, em janela fixa.

## Fontes e verificação

O contrato vem de [bootstrap.php](../../api/v1/bootstrap.php), [status.php](../../api/v1/status.php), [tree.php](../../api/v1/tree.php), [documents.php](../../api/v1/documents.php), [document-file.php](../../api/v1/document-file.php), [ApiClientService](../../services/ApiClientService.php) e [runtime de manutenção/CORS](../../config/app_runtime.php).

As regressões `php scratch/test_api_v1.php` e `php scratch/test_guest_access.php` cobrem os fluxos locais de integração e visitantes. Consulte os pré-requisitos e a limpeza no [guia de testes](../desenvolvimento/testes.md); não execute sobre produção. Esses testes locais não comprovam conectividade de um consumidor externo, configuração de proxy, HTTPS ou CORS da implantação.
