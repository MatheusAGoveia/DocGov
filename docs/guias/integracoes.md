# Integrações com outros sistemas

[Documentação](../README.md) · [Referência da API v1](../referencia/api-integracao.md) · [OpenAPI](../../api/v1/openapi.json)

Guia para o administrador que libera uma integração e para a equipe que conecta outro sistema ao DocGov. O fluxo descrito corresponde à implementação disponível em 17/11/2026.

A integração permite consultar a estrutura do acervo, pesquisar publicações, obter seu conteúdo e baixar arquivos. A opção **Ler e escrever com conta permitida** também permite criar e editar publicações nos assuntos que a conta pode editar, salvando rascunhos ou enviando para revisão. A API não cadastra usuários, não altera a estrutura, não aprova publicações e não exclui documentos. A conexão com o sistema consumidor deve ser implementada pela equipe responsável por ele.

## Antes de começar

- O DocGov deve estar instalado e acessível ao servidor do sistema consumidor. Em produção, utilize HTTPS.
- Uma base existente precisa ter as migrações [029](../../database/migrations/029_api_clients.sql) e [030](../../database/migrations/030_api_write_scope.sql) aplicadas, além das anteriores. A 030 habilita o novo escopo sem mudar as chaves existentes. A preparação do ambiente está em [instalação e operação](../operacao/instalacao-e-operacao.md).
- Um **Super Admin** precisa emitir a chave. Administração de assunto ou permissão de edição não permitem gerenciar integrações.
- Defina quem mantém o consumidor, qual conteúdo ele precisa consultar e onde o segredo será armazenado.

A URL-base da API é a URL da instalação acrescida de `api/v1/`:

| Instalação | URL-base da API |
| --- | --- |
| Desenvolvimento local | `http://127.0.0.1:8000/api/v1/` |
| Publicação na raiz do domínio | `https://docgov.exemplo/api/v1/` |
| Publicação em subdiretório | `https://portal.exemplo/docgov/api/v1/` |

Os domínios com `.exemplo` e os IDs usados neste guia são ilustrativos. Na máquina do consumidor, `127.0.0.1` aponta para o próprio consumidor; use o endereço do servidor do DocGov para uma conexão entre máquinas.

## 1. Escolher o acesso da integração

| Opção no painel | Conteúdo liberado | Uso típico |
| --- | --- | --- |
| **Somente documentos públicos** | Documentos publicados de assuntos públicos e hierarquia ativa | Site institucional, catálogo público e pesquisa de publicações |
| **Leitura de uma conta** | Documentos publicados que a conta vinculada pode ler, incluindo privados autorizados | Intranet, pesquisa interna e sincronização de um acervo restrito |
| **Ler e escrever com conta permitida** | Leitura da conta e criação/edição de publicações nos assuntos em que ela tem edição | Envio e atualização de conteúdo por outro sistema |

Nas opções vinculadas a conta, utilize uma conta de serviço já cadastrada, ativa e com as permissões necessárias. Informe o login exato. Se houver mais de uma conta ativa com esse login, a criação é recusada; o painel não oferece seleção de domínio para resolver essa ambiguidade.

Contas de administrador global não podem ser vinculadas. A chave também deixa de autenticar se a conta for desativada ou promovida a administrador global. Mudanças nas permissões da conta afetam as consultas seguintes, sem exigir uma nova chave.

As duas opções de somente leitura continuam limitadas aos publicados. O escopo de escrita permite consultar também os detalhes de rascunhos, itens em revisão e inativos que a conta pode editar; a lista continua trazendo publicados por padrão, com `status=all` para incluir pendências autorizadas. Nenhum escopo permite ler a lixeira ou conteúdo com hierarquia inativa. Uma chave de escrita não concede edição à conta: ela precisa ter essa permissão previamente no DocGov.

## 2. Gerar a chave

1. Entre no portal com uma conta de **Super Admin** e abra a Administração.
2. No menu **Sistema**, selecione **Integrações (API)**. A rota da tela é `admin/index.php?tab=integracoes`.
3. Preencha o formulário **Gerar acesso para um sistema**:

| Campo | Como preencher |
| --- | --- |
| Nome da integração | Entre 3 e 120 caracteres; identifique consumidor e ambiente, por exemplo `Portal institucional — homologação` |
| Finalidade | Opcional, até 500 caracteres; informe objetivo e equipe responsável |
| O que a chave pode fazer | Escolha um dos três escopos da seção anterior |
| Login da conta vinculada | Obrigatório em **Leitura de uma conta** e **Ler e escrever com conta permitida** |
| Expira em | Opcional; escolha uma data futura. Vazio significa sem expiração. A validade termina às 23:59:59 da data escolhida, no fuso configurado no DocGov |
| Limite/minuto | De 10 a 1000 requisições por minuto; padrão de 120, compartilhado por todos os processos que usam a mesma chave |

4. Clique em **Gerar chave**.
5. Copie o token exibido e guarde-o no gerenciador de segredos ou na configuração protegida do servidor consumidor.

O token aparece **uma única vez**, logo após a criação. O DocGov guarda apenas seu prefixo de identificação e o hash do token completo. Atualizar a página não recupera o segredo. Se o perder, gere outra chave e revogue a anterior.

Use chaves diferentes para cada consumidor e ambiente. Não grave o token no Git, em URLs, em logs ou em JavaScript entregue ao navegador.

## 3. Testar a conexão

Configure no ambiente do processo consumidor:

| Variável usada nos exemplos | Valor |
| --- | --- |
| `DOCGOV_BASE_URL` | URL-base completa da API, terminando em `/api/v1/` |
| `DOCGOV_API_KEY` | Token emitido no painel, obtido da configuração protegida |

Esses nomes são uma convenção dos exemplos; não são novas variáveis obrigatórias da instalação do DocGov. Não é necessário abrir sessão no portal nem enviar usuário, senha corporativa, cookies ou CSRF para consultar a API.

Exemplo em PowerShell, com a chave já definida no ambiente:

```powershell
$env:DOCGOV_BASE_URL = 'http://127.0.0.1:8000/api/v1/'
if ([string]::IsNullOrWhiteSpace($env:DOCGOV_API_KEY)) {
    throw 'Defina DOCGOV_API_KEY pela configuração protegida do ambiente.'
}
$apiBase = $env:DOCGOV_BASE_URL.TrimEnd('/')
$headers = @{ Authorization = "Bearer $env:DOCGOV_API_KEY" }
$status = Invoke-RestMethod -Uri "$apiBase/status.php" -Headers $headers -TimeoutSec 30
$status.data
```

O resultado esperado é HTTP **200**, `success=true`, `api_version="1.0"` e a identificação da integração criada. Um HTTP **401** indica que a chave não foi aceita. O teste de status também consome uma requisição do limite por minuto.

Se a infraestrutura não repassar `Authorization` ao PHP, use a alternativa:

```powershell
$headers = @{ 'X-API-Key' = $env:DOCGOV_API_KEY }
$status = Invoke-RestMethod -Uri "$apiBase/status.php" -Headers $headers -TimeoutSec 30
```

Envie um dos cabeçalhos por requisição; não coloque o token na query string. Em outras linguagens, o mesmo contrato HTTP é descrito na [referência técnica](../referencia/api-integracao.md#exemplos-com-curl).

## 4. Consultar o acervo

Os comandos abaixo reutilizam `$apiBase` e `$headers` definidos no teste anterior.

```powershell
# Hierarquia de categorias, subcategorias e assuntos legíveis pela chave.
$tree = Invoke-RestMethod -Uri "$apiBase/tree.php" -Headers $headers -TimeoutSec 30
$tree.data

# Primeira página de documentos publicados.
$list = Invoke-RestMethod -Uri "$apiBase/documents.php?page=1&per_page=20" -Headers $headers -TimeoutSec 30
$list.data | Select-Object id, title, content_type, updated_at
$list.meta

# Substitua 123 por um ID retornado pela lista.
$documentId = 123
$detail = Invoke-RestMethod -Uri "$apiBase/documents.php?id=$documentId" -Headers $headers -TimeoutSec 30
$detail.data.content

# Execute quando detail.data.links.file não for nulo.
if ($detail.data.links.file) {
    Invoke-WebRequest -Uri "$apiBase/$($detail.data.links.file)" -Headers $headers -OutFile './documento.bin' -TimeoutSec 30
}
```

Os links `links.self` e `links.file` são relativos à **URL-base da API**. Reenvie a chave também nas chamadas de detalhe e download. O campo `file.name` informa o nome original do anexo; no exemplo, o arquivo é salvo com o nome local fixo `documento.bin`.

A lista traz metadados; o detalhe acrescenta `content`, cujo formato varia entre HTML, código, dados estruturados, URL e arquivo. Todos os filtros e campos estão na [referência da API](../referencia/api-integracao.md#endpoints).

### Paginação e sincronização

Consulte todas as páginas usando `meta.total_pages`; uma resposta com `data=[]` pode representar um acervo vazio ou uma página sem itens. `per_page` aceita de 1 a 100. Exemplo para leitura completa:

```powershell
$page = 1
do {
    $result = Invoke-RestMethod -Uri "$apiBase/documents.php?page=$page&per_page=50" -Headers $headers -TimeoutSec 30
    foreach ($document in $result.data) {
        # Substitua a exibição pela gravação no destino, identificada por document.id.
        $document | Select-Object id, title, updated_at
    }
    $page++
} while ($page -le $result.meta.total_pages)
```

Para sincronizações seguintes, use `updated_since` e salve o horário de início da execução somente depois que todas as páginas e gravações tiverem sido concluídas. Codifique o parâmetro de data na URL, inclusive o sinal `+` de um fuso positivo. A [referência](../referencia/api-integracao.md#sincronização-incremental) descreve retomada, reconciliação e limites da paginação.

### Link público do portal e consulta pela API

| Acesso | Autenticação |
| --- | --- |
| `ver_conteudo.php?id=123`, na raiz da instalação | Publicação pública abre diretamente como visitante quando o acesso sem conta está ligado; conteúdo privado exige conta e permissão |
| `api/v1/documents.php?id=123` | Sempre exige chave, inclusive para documento público |
| Chave com escopo público | Continua funcionando mesmo que a entrada de visitantes no portal esteja desligada |

Para compartilhar uma publicação pública com uma pessoa, utilize o link do portal sem incluir a chave. Para copiar seu conteúdo para outro sistema, utilize a API.

## 5. Criar e editar publicações

Gere uma chave na terceira opção e vincule uma conta que tenha edição no assunto. Criação e edição usam `POST api/v1/documents.php`, com JSON, formulário ou multipart para anexos. As duas opções antigas de chave retornam 403 em tentativas de escrita, mesmo quando a conta associada é editora.

Exemplo PowerShell, reutilizando `$apiBase` e `$headers` com uma chave de escrita. Substitua 12 pelo assunto permitido:

```powershell
$payload = @{
    subject_id = 12
    title = 'Procedimento recebido pela integração'
    section_key = 'documents'
    text_content = '<p>Conteúdo para revisão.</p>'
    workflow_action = 'save_draft'
}
$created = Invoke-RestMethod -Method Post -Uri "$apiBase/documents.php" -Headers $headers -ContentType 'application/json; charset=utf-8' -Body ([System.Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json))) -TimeoutSec 30

# Reenvie os campos do conteúdo e a versão exata recebida para editar.
$payload.id = $created.data.id
$payload.document_version = $created.data.document_version
$payload.title = 'Procedimento atualizado pela integração'
$updated = Invoke-RestMethod -Method Post -Uri "$apiBase/documents.php" -Headers $headers -ContentType 'application/json; charset=utf-8' -Body ([System.Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json))) -TimeoutSec 30
```

A criação responde 201 e a edição responde 200. Ambas retornam `data.id`, `title`, `slug`, `status` e `document_version`. Guarde a versão sem converter seu formato; uma versão desatualizada retorna 409 e não salva nada. Consulte o detalhe atual e reveja as alterações antes de reenviar.

As ações aceitas são `save_draft` (padrão) e `submit_review`. Aprovação e publicação continuam pelo portal. Ao editar um documento publicado e salvá-lo como rascunho, ele deixa de ser publicado até concluir novamente o fluxo editorial. A leitura de rascunhos e seus anexos exige a chave de escrita e edição no respectivo assunto.

Para anexos, envie `multipart/form-data`, a seção e o arquivo em `file` (ou `video_file` para vídeo). Os limites são 25 MB para arquivos, 50 MB para vídeo, 1 MB para código e 2 MB para HTML; limites menores do PHP/servidor continuam prevalecendo. Os campos, formatos e exemplos cURL estão na [referência de escrita](../referencia/api-integracao.md#criação-e-edição-de-publicações).

## 6. Acompanhar e substituir chaves

Na lista **Chaves emitidas**, confira nome, finalidade, prefixo, escopo, situação, último uso, expiração e limite. O último uso é atualizado no máximo uma vez por minuto; não é um contador de chamadas nem uma confirmação de que uma sincronização terminou.

| Situação | Significado e ação |
| --- | --- |
| Ativa | A chave pode autenticar; consulte os endpoints para verificar o conteúdo acessível |
| Revogada | O acesso foi encerrado; gere outra chave se a integração precisar voltar |
| Expirada | A validade terminou; emita uma substituta |
| Conta inativa | Reative a conta vinculada somente se isso fizer parte da política de acesso desejada |
| Bloqueada: conta administradora | A conta vinculada se tornou administradora global; crie uma chave para uma conta de serviço com permissões mínimas |
| Inativa | A chave está desabilitada e não autentica |

Para trocar o token ou alterar escopo, conta, validade ou limite, gere uma nova chave: a tela atual oferece criação e revogação, sem edição de chaves existentes.

1. Gere a nova chave com a configuração desejada.
2. Atualize o segredo no consumidor.
3. Valide `status.php` e uma consulta do acervo esperado.
4. Na chave anterior, clique em **Revogar** e confirme. As próximas chamadas com ela receberão 401.

Se houver exposição do segredo, revogue a chave comprometida imediatamente e configure uma substituta. A revogação não apaga dados ou arquivos que o consumidor já tenha copiado; esses dados devem seguir as regras de retenção e acesso do sistema de destino.

## Diagnóstico

| Sintoma | O que verificar |
| --- | --- |
| Aba de integrações ausente | Confirme que a conta é Super Admin |
| Erro ao gerar chave vinculada | Confira login único, conta ativa e ausência de papel de administrador global |
| 401 | Cabeçalho enviado, token completo, expiração, revogação e situação da conta; o endpoint não diferencia esses motivos |
| 200 com lista vazia | Publicação, visibilidade, permissões, hierarquia ativa e filtros enviados |
| 404 no documento | ID, escopo da chave e estado publicado; a API não revela se o recurso existe fora do escopo |
| 404 `no_file` | O documento não tem anexo; consulte `content` no detalhe |
| 404 `file_missing` | O arquivo físico não está disponível; encaminhe à operação do DocGov |
| 422 | Tipo e tamanho dos parâmetros, data e limites de paginação |
| 429 | Respeite os segundos de `Retry-After` antes de tentar novamente; reduza chamadas simultâneas |
| 503 | Verifique a manutenção da área de APIs e aguarde `Retry-After`, quando informado |
| 500 | Encaminhe horário, endpoint e código ao responsável pelos logs do servidor, sem enviar o token |
| Funciona no servidor, mas não no navegador | Confira CORS; mantenha o token no backend do consumidor |

## Limites e referências

Não há exclusão pela API, webhooks ou eventos de remoção. A escrita fica restrita à terceira opção e às permissões da conta. Faça reconciliação periódica para retirar do destino conteúdo removido, despublicado ou fora do escopo. Imagens inseridas em artigos usam gateways do portal: a API não fornece um endpoint autenticado por chave para imagens privadas embutidas.

Conexões entre servidores não exigem CORS. Para uma interface web, mantenha a chave no backend da aplicação consumidora e entregue ao navegador somente o que esse backend autoriza. Habilitar CORS não concede permissões nem protege uma chave exposta.

- [Contrato, exemplos com cURL e implantação](../referencia/api-integracao.md).
- [Especificação OpenAPI](../../api/v1/openapi.json): ao importar em uma ferramenta, ajuste o servidor para a URL real, incluindo eventual subdiretório.
- [Visibilidade pública e privada](../referencia/assuntos-publicos-privados.md).
- [Testes e verificação](../desenvolvimento/testes.md): `scratch/test_api_v1.php` cobre autenticação, escopos, consulta, download, limites e painel com dados temporários em desenvolvimento local.

Fontes: [tela administrativa](../../admin/partials/api-clients.php), [ações de criação e revogação](../../admin/modules/integrations/actions/manage.php), [ApiClientService](../../services/ApiClientService.php) e [endpoints v1](../../api/v1/).
