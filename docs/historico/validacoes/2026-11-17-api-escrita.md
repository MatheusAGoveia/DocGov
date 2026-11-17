# Validação da integração com leitura e escrita — 17/11/2026

[Histórico](../README.md) · [Guia de integrações](../../guias/integracoes.md) · [Contrato da API](../../referencia/api-integracao.md)

Validação local da opção **Ler e escrever com conta permitida**, adicionada abaixo das opções pública e de leitura de conta. Referência inicial: commit `67ea762`, com as alterações locais desta funcionalidade ainda sem commit durante a execução.

## Ambiente e banco

- Windows, PHP CLI 8.4.22 e PostgreSQL local de desenvolvimento.
- Migrações anteriores até 029 presentes. A migração 030 foi aplicada em transação com `lock_timeout = '2s'` e reaplicada para conferir idempotência.
- A quantidade de chaves por escopo antes e depois da migração permaneceu igual; as chaves existentes não ganharam escrita.
- A regressão HTTP iniciou seu próprio servidor PHP em porta livre, com sessões e fixtures exclusivas na base local. A limpeza final confirmou zero usuários e categorias das fixtures de integração.

## Verificações executadas

| Verificação | Resultado |
| --- | --- |
| `php scratch/test_api_v1.php` | Aprovado: três escopos, autenticação, leitura, criação/edição, anexos, painel e visitantes |
| `php scratch/test_guest_access.php` | Aprovado: leitura pública, isolamento dos privados e destinos seguros |
| `php -l` nos nove arquivos PHP novos/alterados da funcionalidade | Aprovado |
| `git diff --check` | Aprovado |
| Links e âncoras dos guias de integração | 43 referências locais válidas |
| Exemplos JSON e referências internas OpenAPI | Sete exemplos JSON e 67 referências válidas |
| Parser PowerShell nos exemplos dos guias | Cinco blocos sem erros de sintaxe |

Os exemplos da documentação tiveram validação de estrutura/sintaxe; isso não equivale a executar suas chamadas contra um consumidor externo.

## Comportamento comprovado

A nova chave exige tanto `user_write` quanto edição da conta no assunto. As chaves públicas e de leitura permanecem sem escrita, inclusive quando vinculadas a uma conta editora. Vincular a chave de escrita a uma conta leitora também não concede edição.

O fluxo HTTP criou rascunhos de texto e arquivo, editou com a versão atual, recusou versão antiga com 409 e enviou conteúdo para revisão. HTML foi sanitizado; o autor não pôde ser forjado. Escrita e consulta de pendências foram bloqueadas fora do escopo, na lixeira e quando a conta perdeu edição. Categoria, subcategoria e assunto inativos impediram gravação. Conta desativada, conta promovida a administradora e chave revogada deixaram de autenticar.

A criação da chave pela terceira opção no painel foi exercitada por HTTP, seguida de uma gravação real com a chave emitida. As escritas por chave não criaram cookies nem aceitaram cookie administrativo como substituto da autenticação. A auditoria identificou a chave utilizada.

A API interna de sessão continuou criando documentos, enviando para revisão e permitindo revisão/aprovação por administrador autorizado, com o formato de resposta original. O CSRF dessa rota foi preservado. Ambas as APIs passaram a compartilhar `DocumentWriteService`.

## Limites da validação

O painel foi verificado pelo HTML e pelos formulários HTTP; não houve inspeção visual em navegador nesta execução. Não foram testados AD real, conectividade externa, proxy, HTTPS, capacidade sob carga ou uploads no tamanho máximo. A API de integração não recebeu operações de aprovação, exclusão ou alteração da estrutura do acervo.
