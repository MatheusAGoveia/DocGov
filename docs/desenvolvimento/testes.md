# Testes e verificação

[Documentação](../README.md) · [Início rápido](inicio-rapido.md) · [Histórico de validações](../historico/README.md)

Este guia descreve como escolher e executar verificações do DocGov. Resultados de execuções anteriores estão no [registro consolidado](../historico/validacoes/registro-consolidado-2026-11-11.md) e nos documentos das funcionalidades. Os comandos abaixo não representam uma execução nova.

## Organização

Os testes PHP e JavaScript ficam em `scratch/`, junto com fixtures, adaptadores e utilitários operacionais. O manifesto npm não define `test`, não há manifesto Composer/PHPUnit e não foi encontrado workflow de CI neste checkout.

Não execute todos os arquivos de `scratch/` como uma suíte: também há scripts de migração, manutenção de contas e limpeza de logs. Escolha o teste pela área afetada e leia seus pré-requisitos e sua limpeza.

## Checks de sintaxe

Execute da raiz em PowerShell. Estes comandos verificam sintaxe sem executar a lógica dos arquivos PHP/JavaScript:

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

Para uma alteração localizada, aplique `php -l <arquivo.php>` ou `node --check <arquivo.js>` aos arquivos alterados. Lint aprovado não valida autenticação, transações ou regras de acesso.

## Preparar o ambiente funcional

1. Use PostgreSQL de desenvolvimento/homologação e confira as variáveis `DB_*` herdadas pelo processo.
2. Identifique se o teste usa uma base exclusiva, cria uma base temporária ou opera na base configurada.
3. Prepare schema, fixtures e permissões exigidos pelo teste escolhido.
4. Configure subprocessos e servidor HTTP para o mesmo ambiente de teste.
5. Confira o resultado e a limpeza de fixtures, arquivos e processos ao terminar.

Alguns testes criam dados fora de transação e removem depois; mesmo testes transacionais podem avançar sequências. A constante `DOCGOV_SKIP_APP_RUNTIME` não garante isolamento de banco. Os testes de grupos aninhados e de identidade por domínio criam bases temporárias e exigem PostgreSQL local e permissão de criação de base.

## Escolher uma suíte

### Refatoração do painel administrativo

`scratch/test_admin_architecture.php` cria uma base exclusiva com prefixo `docgov_admin_architecture_test_`, prepara dados fictícios e sessões próprias e inicia um servidor HTTP local em porta livre. Exige PostgreSQL local, ambiente `development` e permissão de criar/remover a base temporária. Não reutiliza o servidor aberto nem aplica o schema à base em uso.

```powershell
# Antes de alterar o painel, registrar e conferir a referência.
php scratch/test_admin_architecture.php --record "$env:TEMP\docgov-admin-architecture-before.json"
php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json"

# Após a alteração, comparar HTTP e acrescentar interface/regressões.
php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json" --browser --regression
```

`--browser` exige Chrome e Playwright, que pode ser localizado por `DOCGOV_PLAYWRIGHT_PACKAGE`. `--regression` seleciona apenas as suítes listadas no driver e as executa sobre a base temporária herdada. Sem `--compare`, o runner também pode verificar o estado atual; uma nova captura não substitui uma referência anterior quando o objetivo é comparar mudanças.

O snapshot não contém cookies/tokens e registra o hash da entrada de origem. Dados, sessões e artefatos transitórios são removidos ao terminar. `--fail-node` provoca uma falha controlada ao iniciar o Node; `--fail-after-upload` verifica a recuperação do arquivo de fixture mesmo quando o Node falha após enviá-lo. Os testes usam AD desativado ou simulado; não comprovam login corporativo real. Veja a [validação da refatoração](../historico/validacoes/2026-11-13-modulos-administrativos.md) para os resultados datados.

### Demais áreas

| Área | Comando existente | Condição principal |
| --- | --- | --- |
| Permissões de conteúdo | `php scratch/test_permissions.php` | Base de teste com fixtures transacionais |
| API de permissões | `php scratch/test_permission_api.php` | Subprocessos configurados para a base de teste |
| Hierarquia | `php scratch/test_hierarchy_resolution.php` | Casos com nomes repetidos em ramos diferentes |
| Visibilidade | `php scratch/test_subject_visibility.php` | Conferir modos de preparação/limpeza e guardas locais |
| Seções dinâmicas | `php scratch/test_dynamic_document_sections.php` | Schema atual e usuário ativo de teste |
| Workflow documental | `php scratch/test_document_approval_workflow.php` | Fixtures editoriais em base exclusiva |
| Processo do assunto | `php scratch/test_subject_workspace.php` | Fixtures criadas e removidas pelo teste |
| Capacidades globais | `php scratch/test_system_access_groups.php` | Base de teste com estrutura de equipes/capacidades |
| Grupos aninhados | `php scratch/test_nested_groups.php` | Cria e remove base PostgreSQL temporária |
| Importação em lote | `php scratch/test_batch_user_import.php` | PostgreSQL local e diretório simulado |
| Protocolo de consulta LDAP | `node scratch/test_batch_directory_ldap.js` | PHP com LDAP e servidor LDAP simulado local |
| Identidade e login por domínio | `node scratch/test_batch_directory_ldap.js --domain-login` | Base temporária, LDAP simulado e adaptadores HTTP |
| Concorrência de importação | `node scratch/test_batch_user_import_concurrency.js` | PostgreSQL local e fixtures de concorrência |
| Conta técnica AD | `php scratch/test_ad_service_account.php` | Conferir o modo escolhido; alguns modos criam fixtures |

Confira as instruções específicas em [importação em lote](../guias/importacao-usuarios-em-lote.md), [grupos aninhados](../referencia/grupos-aninhados.md), [visibilidade](../referencia/assuntos-publicos-privados.md) e [identidades AD](../referencia/identidades-ad-por-dominio.md).

Os testes com LDAP simulado não requerem senhas corporativas. Scripts de integração real podem acessar a rede e dependem de contas e ambiente próprios; não os trate como parte automática de uma verificação documental ou de sintaxe.

## Testes de navegador

As suítes existentes usam Playwright e Chrome. O pacote Playwright não está declarado no manifesto do projeto. Disponibilize-o no ambiente de teste e consulte as variáveis aceitas pelo script escolhido:

| Variável | Uso observado |
| --- | --- |
| `DOCGOV_PLAYWRIGHT_PACKAGE` | Pacote/caminho do Playwright; algumas suítes usam `playwright` por padrão |
| `DOCGOV_PHP` | Executável PHP, nas suítes que iniciam subprocessos |
| `DOCGOV_TEST_BASE_URL` | Endereço do servidor de teste; frequentemente `http://127.0.0.1:8000` |
| `DOCGOV_BROWSER_PATH` | Executável do Chrome |

Nem todas as suítes usam as mesmas variáveis. Algumas iniciam seu próprio servidor; outras esperam um servidor já disponível. Confira o modo antes de executar.

Exemplos de comandos existentes:

```powershell
node scratch/test_batch_user_import_browser.js
node scratch/test_subject_visibility_browser.js
node scratch/test_admin_documents_filters_browser.js
node scratch/test_profile_avatar_browser.js
node scratch/test_dark_mode_browser.js
```

Para grupos aninhados com navegador, use `php scratch/test_nested_groups.php --browser`, com o pacote Playwright configurado.

## Critérios de verificação

A regressão deve demonstrar o comportamento alterado e os caminhos de falha relevantes. Para mudanças de acesso, verifique também uma conta sem autorização. Para persistência composta, confira que a falha não deixa gravação parcial. Para interface, cubra os tamanhos e temas afetados.

A jornada funcional de uma publicação deve cobrir login, consulta permitida e negada, criação, revisão, aprovação, mídia, download e logout. Os detalhes da implantação estão em [operação](../operacao/instalacao-e-operacao.md).

## Registrar uma execução

Registre data, referência do código quando conhecida, ambiente, banco/schema, comandos, resultado, artefatos e limitações em `docs/historico/`. Identifique explicitamente verificações não executadas e diferencie teste simulado de autenticação corporativa real.

O [registro consolidado até 11/11/2026](../historico/validacoes/registro-consolidado-2026-11-11.md) preserva os números e resultados anteriormente registrados. Sua conservação não representa reexecução ou homologação de outro ambiente.
