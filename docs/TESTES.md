# Testes e verificação

Inventário inicial em 01/10/2026. Há testes PHP e de navegador em `scratch/`, junto com scripts operacionais. Não existe comando único de suíte em `package.json`, manifesto PHPUnit/Composer ou workflow de CI encontrado em `.github/` nesta varredura.

## Resultado desta etapa

| Verificação executada | Resultado |
| --- | --- |
| `php -l` nos 137 arquivos PHP encontrados no início da validação | Aprovada |
| `node --check` em 19 scripts próprios de `assets/` e `scratch/` | Aprovada |
| `git diff --check` no checkout naquele momento | Aprovada; aviso de normalização LF/CRLF em arquivo alterado concorrentemente |

Runtimes observados: PHP 8.4.22 e Node.js 24.18.0. A lista PHP inclui scripts de operação, mas **lint não executa esses scripts**. As bibliotecas de terceiros, diretórios de dados/artefatos e assets PDF.js foram excluídos conforme o tipo de verificação.

Não foram executados testes funcionais de banco, autenticação AD ou navegador nesta etapa. Os números acima representam o conjunto encontrado na execução; novos arquivos surgiram no checkout durante a varredura. A aprovação de sintaxe não é homologação funcional nem validação da importação em andamento.

## Verificação rápida reproduzível

Execute a partir da raiz, em PowerShell:

```powershell
$phpFiles = rg --files -g '*.php' -g '!node_modules/**' -g '!storage/**' -g '!uploads/**' -g '!tmp/**' -g '!output/**'
foreach ($file in $phpFiles) {
    php -l $file
    if ($LASTEXITCODE -ne 0) { throw "Sintaxe PHP inválida: $file" }
}

$jsFiles = rg --files assets scratch -g '*.js' -g '!assets/pdfjs/**' -g '!assets/vendor/**'
foreach ($file in $jsFiles) {
    node --check $file
    if ($LASTEXITCODE -ne 0) { throw "Sintaxe JS inválida: $file" }
}

git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Falha em git diff --check' }
```

## Preparação da suíte funcional

Use uma base **exclusiva de desenvolvimento/homologação**, atualizada e com fixtures apropriadas. Configure também os subprocessos e o servidor de teste para essa mesma base. Alguns testes criam registros fora de transação e depois fazem limpeza; outros dependem de usuários previamente existentes. Mesmo testes transacionais podem alterar sequências do PostgreSQL.

Inspecione o modo e a limpeza de cada script antes de executá-lo. Não faça execução automática de todos os arquivos em `scratch/`: o diretório contém utilitários que promovem contas, trocam senhas e apagam logs. `DOCGOV_SKIP_APP_RUNTIME` aparece em alguns testes, mas não é uma proteção geral contra uso de banco real.

## Suítes candidatas para regressão

Os comandos desta tabela são um plano de execução em ambiente isolado, não resultados novos desta revisão.

| Área | Comando existente | Observação |
| --- | --- | --- |
| Motor de permissões | `php scratch/test_permissions.php` | Fixtures transacionais e rollback |
| API de permissões | `php scratch/test_permission_api.php` | Adaptadores/subprocessos e controle de acesso |
| Resolução de hierarquia | `php scratch/test_hierarchy_resolution.php` | Nomes repetidos em ramos; fixtures transacionais |
| Assuntos públicos/privados | `php scratch/test_subject_visibility.php` | Guardas de CLI/banco local; possui modos de preparação e limpeza |
| Seções e conteúdo estruturado | `php scratch/test_dynamic_document_sections.php` | Exige estrutura atual e usuário ativo |
| Fluxo de documentos | `php scratch/test_document_approval_workflow.php` | Revisão, aprovação e estados; conferir fixtures |
| Workspace do assunto | `php scratch/test_subject_workspace.php` | Cria fixtures e faz limpeza |
| Conta técnica AD | `php scratch/test_ad_service_account.php` | Modo padrão testa configuração; modos adicionais alteram fixtures |

Existem ainda testes de uploads, tags, manutenção, auditoria e interface administrativa. Testes de AD real fazem chamadas de rede; precisam de um escopo operacional separado e dados de teste controlados.

## Navegador

`scratch/test_subject_visibility_browser.js` utiliza Playwright, Chrome e um servidor PHP acessível. Variáveis observadas nessa suíte:

| Variável | Finalidade |
| --- | --- |
| `DOCGOV_PLAYWRIGHT_PACKAGE` | Caminho/pacote do Playwright; padrão `playwright` |
| `DOCGOV_PHP` | Executável PHP; padrão `php` |
| `DOCGOV_TEST_BASE_URL` | Base do servidor de teste; padrão `http://127.0.0.1:8000` |
| `DOCGOV_BROWSER_PATH` | Executável Chrome; padrão de instalação Windows |

O manifesto atual não declara Playwright. Prepare a dependência no ambiente de testes e consulte cada script: nem todas as suítes compartilham as mesmas variáveis.

Exemplos de suítes existentes: `test_subject_visibility_browser.js`, `test_ad_service_account_browser.js`, `test_admin_documents_filters_browser.js`, `test_profile_avatar_browser.js` e `test_dark_mode_browser.js`. Em ambiente isolado, execute a suíte escolhida com `node scratch/<arquivo>.js` e confira o encerramento/limpeza.

## Critérios para o próximo ciclo

1. Separar utilitários operacionais da suíte e impor guardas de CLI e ambiente de teste.
2. Criar um runner que pare em falhas, identifique o banco de teste e finalize a limpeza.
3. Cobrir CR-01 a CR-06 com verificações que demonstrem a correção, incluindo persistência, autorização e rollback.
4. Manter uma jornada mínima: login → consulta permitida/negada → criação → revisão → aprovação → mídia → download → logout.
5. Registrar commit, banco/schema, comandos, resultado, arquivos gerados e limitações em cada homologação.

Os resultados antigos de setembro permanecem nos relatórios e documentos de funcionalidade correspondentes. Não devem ser apresentados como testes executados novamente em outubro.

## Grupos aninhados — validação de 01/10/2026

A implementação de subgrupos foi validada com `php scratch/test_nested_groups.php --browser`, em uma base PostgreSQL temporária exclusiva, removida ao final. O pacote Playwright foi fornecido pela variável `DOCGOV_PLAYWRIGHT_PACKAGE`.

- Compatibilidade antes da migração, herança em vários níveis, múltiplos grupos superiores, deduplicação, desativação/reativação e preservação de outras origens de acesso: aprovadas.
- CSRF, autorização de administrador global, bloqueio de ciclos no serviço e no banco, gravações concorrentes e reversão do vínculo quando a auditoria falha: aprovados.
- Navegador: inclusão, pesquisa de permissões com contagem indireta, caminho de herança no usuário, confirmação e remoção; computador e celular no tema claro, celular no tema escuro: aprovados, sem erros de execução.
- Regressões `test_permissions.php`, `test_group_access_admin.php`, `test_system_access_groups.php`, `test_permission_api.php`, `test_hierarchy_resolution.php` e `test_access_integrity.php`: aprovadas na base isolada.
- Sintaxe dos dez arquivos PHP da mudança e do script JavaScript de navegador: aprovada.

A migração exclusiva 027 foi aplicada ao banco local configurado. Login retornou HTTP 200 e a aba Subgrupos foi verificada no servidor já existente, sem alterar vínculos. O processo do servidor foi preservado; nenhuma base temporária de teste permaneceu. Isso comprova a ativação local, não a publicação em outro ambiente. Veja [uso e implantação](grupos-aninhados.md).
