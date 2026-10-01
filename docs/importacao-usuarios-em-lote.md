# Importação de usuários e membros em lote

A importação aceita uma lista de até 1.000 nomes completos ou logins, com uma pessoa por linha. É possível colar uma coluna do Excel ou carregar um arquivo `.txt` ou `.csv` em UTF-8. O CSV deve conter uma única coluna, com cabeçalho opcional `nome`, `nome completo`, `login`, `username` ou `usuário`.

## Importar contas do AD

1. Acesse **Gestão de Acesso → Usuários**.
2. Abra **Importar usuários em lote do AD**.
3. Selecione o domínio e cole ou carregue a lista.
4. Clique em **Importar lista do AD**.

Contas novas, ativas e identificadas com segurança no AD são cadastradas como leitoras. Cadastros existentes mantêm os papéis, vínculos e bloqueios atuais. A operação não registra um login, não pede a senha das pessoas e não modifica o AD.

## Adicionar pessoas a uma equipe

1. Acesse **Gestão de Acesso → Equipes**, selecione a equipe e abra **Membros**.
2. Abra **Adicionar membros em lote**.
3. Selecione o domínio, cole ou carregue a lista e clique em **Importar e adicionar à equipe**.

O sistema utiliza os cadastros existentes. Quando a pessoa ainda não está cadastrada, consulta o AD, cria o cadastro e adiciona o vínculo com a equipe na mesma transação. Se o vínculo falhar, o novo cadastro daquela entrada também é desfeito. As demais pessoas continuam sendo processadas.

Equipes inativas podem receber membros, seguindo o comportamento já existente do sistema; seus acessos entram em vigor quando forem ativadas. Cadastros inativos não são reativados nem adicionados pela importação.

## Nomes, logins e resultados

Exemplo de lista para o domínio BETIM:

```text
Ana Maria Silva
joao.souza
BETIM\maria.santos
pedro.lima@betim.pmb
```

A busca no AD usa correspondência exata em `displayName`, `cn` ou `sAMAccountName`. Logins com domínio precisam pertencer ao domínio selecionado. Use o nome como aparece no AD; nomes parciais não são usados para escolher automaticamente uma pessoa. Nos cadastros locais, o login exato tem prioridade, seguido pelo nome completo exato.

| Resultado | Significado / ação |
| --- | --- |
| Importado / Adicionado à equipe | Operação concluída. O relatório informa quando houve cadastro automático. |
| Já cadastrado / Já é membro | Registro existente preservado; repetir a lista não duplica registros ou vínculos. |
| Repetido | A mesma entrada aparece mais de uma vez na lista. |
| Não encontrado no AD | A consulta terminou sem correspondência no domínio selecionado; conferir nome ou informar login. |
| Nome ambíguo | Mais de uma pessoa corresponde ao nome; informar o login. Quando a ambiguidade é do AD, são exibidas até três sugestões. |
| Conta inativa | Solicitar revisão ao administrador; a importação preserva bloqueios. |
| AD indisponível | A entrada ainda não foi verificada. Conferir conexão/conta técnica e reprocessar. |
| Conflito de cadastro | Login, identidade ou e-mail já associado a outro cadastro; solicitar revisão, sem sobrescrever a conta. |
| Falha nesta entrada | Não foi possível concluir ou confirmar a operação; reprocessar é seguro. |

Ao final, use os filtros, **Copiar não encontrados** ou **Baixar relatório CSV**. O relatório contém todas as entradas e protege valores que poderiam ser interpretados como fórmulas por planilhas. Os resultados ficam nesta página: baixe o relatório antes de atualizar ou sair.

**Parar após esta etapa** conclui a etapa em andamento e preserva o restante como pendente. **Processar pendências** retoma somente entradas pendentes, falhas e consultas indisponíveis. Para corrigir nomes ausentes ou ambíguos, edite a lista e importe novamente; repetir contas já concluídas é seguro.

## Permissões e operação

- A importação para o diretório exige `system.directory.manage`, inclusive para administradores delegados.
- Gerenciar membros em lote exige administrador global ativo, seguindo a restrição existente para equipes.
- Cada etapa da API exige sessão autenticada, POST e token CSRF. Papéis informados pelo navegador não são usados para autorizar.
- Cada etapa processa até 20 entradas e reutiliza uma conexão LDAP. Consultas demoradas ou respostas parciais não são tratadas como nomes ausentes.
- Criação e vínculo são atômicos por pessoa. Restrições de unicidade, locks transacionais e `ON CONFLICT` protegem repetições e importações concorrentes.
- Cadastros novos e vínculos novos são registrados na auditoria existente. Notificações internas de ingresso são criadas somente para vínculos novos.
- É necessária uma [conta técnica de leitura configurada](conta-leitura-ad.md) no domínio consultado. Cadastros existentes podem ser vinculados à equipe sem consulta ao AD.
- Não há migração de banco necessária; são utilizadas as tabelas de usuários, equipes, auditoria e notificações existentes.

Implementação: `services/BatchUserImportService.php`, `services/DirectoryImportGateway.php`, métodos de lote em `services/ActiveDirectoryAuthService.php`, `admin/import-users.php`, `admin/partials/batch-user-import.php` e `assets/batch-user-import.{js,css}`.

## Verificação executada

Em 01/10/2026, foram aprovados:

- Testes com PostgreSQL local e diretório simulado: 500 cadastros + vínculos, repetição sem duplicação, auditoria, notificações, rollback por entrada, conflitos, homônimos, contas inativas, autorização e delegação.
- Protocolo LDAP com a extensão PHP real contra servidor local simulado: nomes, logins, Unicode, escape do filtro, conexão reutilizada, contas desativadas, homônimos, resultados parciais e falhas de busca/bind.
- Duas importações simultâneas da mesma identidade: um cadastro criado e um vínculo de equipe.
- Navegador: inclusão e repetição pela API real com cadastro de teste; autenticação, método, CSRF e autorização; lista de 500 com respostas de AD simuladas; upload CSV, resumo, filtros, relatório, escape HTML, interrupção, retomada, falha de rede, layout de 390 px e tema escuro.
- Regressões dos fluxos existentes de login integrado e administração de equipes.

Esses testes não importaram pessoas do AD corporativo. A homologação com lista real depende da conexão e da conta técnica do domínio no ambiente de teste.

Comandos reproduzíveis a partir da raiz do projeto:

```powershell
php scratch/test_batch_user_import.php
node scratch/test_batch_directory_ldap.js
node scratch/test_batch_user_import_concurrency.js
node scratch/test_batch_user_import_browser.js
php scratch/test_integrated_windows_auth.php
php scratch/test_group_access_admin.php
```

Os testes de banco exigem ambiente `development` com PostgreSQL local e removem suas fixtures ao final. Para o teste de navegador, o servidor deve estar em `http://127.0.0.1:8000` ou em `DOCGOV_TEST_BASE_URL`, com Playwright disponível. É possível informar o caminho do pacote por `DOCGOV_PLAYWRIGHT_PACKAGE` e o executável do Chrome por `DOCGOV_BROWSER_PATH`.

Referências da integração: [ldap_search](https://www.php.net/manual/en/function.ldap-search.php), [ldap_parse_result](https://www.php.net/manual/en/function.ldap-parse-result.php) e [ldap_escape](https://www.php.net/manual/en/function.ldap-escape.php).
