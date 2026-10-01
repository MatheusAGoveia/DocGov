# Instalação e operação

Guia inicial elaborado em 01/10/2026. Os procedimentos de implantação e restauração abaixo precisam ser homologados no ambiente da infraestrutura; não foram executados nesta varredura.

## Requisitos

| Componente | Referência |
| --- | --- |
| PHP | 8.2+ como referência inicial para recursos usados, incluindo `SensitiveParameter`; sintaxe verificada localmente em PHP 8.4.22. Compatibilidade integral com 8.2 ainda não testada |
| Extensões PHP | PDO/pdo_pgsql, session, mbstring, dom/libxml, fileinfo, openssl e ldap para integração AD |
| PostgreSQL | 14+ conforme o cabeçalho de `database/schema.sql` |
| Servidor web | PHP configurado em IIS, Apache ou equivalente; HTTPS e restrições das pastas internas |
| Node.js/npm | Para dependências frontend e verificações JavaScript; sem script de build definido no manifesto atual |
| Navegador de testes | Chrome e Playwright para os testes existentes que usam essa combinação |

A presença de extensões no PHP CLI não comprova a configuração do PHP usado pelo servidor web. Confirme o arquivo `php.ini` e a identidade do processo em ambos.

## Configuração de banco

`config/db.php` lê primeiro a variável de ambiente e depois a chave correspondente em `config/local.php`. O arquivo local é ignorado pelo Git e tem um exemplo em `config/local.php.example`.

| Variável principal | Alias | Chave local | Padrão |
| --- | --- | --- | --- |
| `APP_ENV` | — | `app_environment` | `development` |
| `DB_HOST` | `DB_HOSTNAME` | `db_host` | `127.0.0.1` |
| `DB_PORT` | — | `db_port` | `5432` |
| `DB_NAME` | `DB_DATABASE` | `db_name` | `docsec` |
| `DB_USER` | `DB_USERNAME` | `db_user` | `postgres` |
| `DB_PASS` | `DB_PASSWORD` | `db_password` | Sem fallback; obrigatória |

Para desenvolvimento, copie o exemplo para `config/local.php` e preencha somente com valores do banco de desenvolvimento. Em produção, configure o ambiente do serviço, `APP_ENV=production` e uma conta de banco com os privilégios necessários à aplicação. Preserve o arquivo local fora de qualquer distribuição pública.

`APP_ENV=production` faz algumas validações, mas não bloqueia os scripts operacionais, não muda o document root e não substitui a configuração do servidor.

## Banco vazio e banco existente

**Base vazia:** crie a base e execute o schema usando uma conta apropriada à instalação. Exemplo, com credenciais fornecidas pelo mecanismo seguro da infraestrutura:

```powershell
psql -v ON_ERROR_STOP=1 -U <usuario_instalacao> -d docsec -f database/schema.sql
```

`database/seed.sql` e `database/migrate_legacy_data.php` incluem dados/contas de demonstração. Não fazem parte de uma carga automática de produção. O provisionamento inicial do administrador deve ser um procedimento controlado; não reutilize utilitários com senha fixa.

**Base existente:** faça backup e identifique as migrações realmente pendentes. Não execute o schema novamente e não decida a ordem apenas pelo nome: há numerações repetidas e arquivos históricos, como `003_resource_permissions.sql`.

O executor `scratch/run_migrations.php` contém uma lista explícita até 027, incluindo visibilidade de assuntos e grupos aninhados. Ele reaplica os scripts da lista e não possui controle de migrações já executadas; aplique somente as pendentes. Aplique a migração de visibilidade quando estiver pendente, usando o cliente PostgreSQL configurado:

```powershell
psql -v ON_ERROR_STOP=1 -U <usuario_migracao> -d docsec -f database/migrations/026_subject_visibility.sql
```

Verificação somente de leitura:

```sql
SELECT column_name, data_type, column_default
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'subjects'
  AND column_name = 'visibility';
```

Não foi identificada uma tabela de controle de execução de migrações. Mantenha registro operacional de versão, scripts aplicados e resultado até consolidar o mecanismo. Veja CR-03 no [review](CODE_REVIEW_2026-10-01.md).

Para habilitar grupos dentro de grupos em uma base existente, use `php scratch/apply_nested_groups_migration.php`, que aplica exclusivamente 027 em uma transação com limites de espera. O código mantém os vínculos diretos antes dessa expansão e não exige reinício do servidor. Consulte [implantação e verificação](grupos-aninhados.md).

## Frontend e desenvolvimento local

`package.json` registra Quill, visualizadores e outras bibliotecas; `npm ci` instala o lockfile quando disponível. Isso não copia automaticamente assets para `assets/vendor/`. Os assets locais existentes e o manifesto precisam ser conferidos ao montar a distribuição. Não há `npm run build` ou `npm test` no manifesto inspecionado.

Para desenvolvimento isolado, com uma base de teste preparada:

```powershell
php -S 127.0.0.1:8000 -t .
```

Esse servidor ignora `.htaccess` e pode expor pastas internas. Restrinja-o à máquina local e não o use como configuração de publicação. Para validar o bloqueio das pastas, use a configuração real do servidor ou um roteador de desenvolvimento revisado.

## Active Directory

`config/active_directory.php` possui valores de ambiente e, quando o runtime está carregado, sobreposição por configurações de `SystemSettingsService`, incluindo os padrões retornados por ele. Confira o resultado da configuração do domínio no painel; não assuma que uma variável de ambiente substitui um valor armazenado no banco.

As principais famílias de configuração são `AD_AUTH_ENABLED`, `AD_DEFAULT_DOMAIN`, `AD_SUPER_ADMIN_USERS`, `AD_LDAP_URI`, `AD_BASE_DN`, `AD_CA_CERTIFICATE`, `AD_SERVICE_BIND_DN` e `AD_SERVICE_BIND_PASSWORD`; o domínio adicional utiliza as chaves `AD_SAUDE_*`. `AD_NETWORK_TIMEOUT` define o timeout de conexão.

Use a [conta de leitura por domínio](conta-leitura-ad.md) para consultas/importação. As credenciais salvas pelo painel ficam dentro da configuração JSONB do sistema: backup e acesso ao banco também precisam protegê-las.

O login atual em `login.php` exige credenciais explícitas; não chama `authenticateIntegrated()` automaticamente. A opção e o método de autenticação integrada existem, mas a ativação operacional depende de implementação/revisão adicional. Consulte a nota em [login Windows](windows-integrated-auth.md).

## Publicação e manutenção

1. Registre commit, versão do banco e plano de retorno.
2. Confirme backup restaurável do banco e dos arquivos físicos.
3. Prepare uma distribuição revisada. Exclua `scratch/`, `data/`, `database/`, `tmp/`, `output/`, `.git/` e dependências de desenvolvimento da exposição HTTP. Preserve internamente os recursos necessários à operação.
4. Bloqueie acesso HTTP direto a `config/`, `services/` e aos arquivos protegidos de `storage/`; preserve a leitura interna pelo PHP. Sirva esses arquivos pelos gateways autorizados. Verifique as regras específicas de IIS/Apache.
5. Prepare manutenção pelo painel, aplique somente as migrações pendentes e publique o código compatível.
6. Valide login, leitura permitida, leitura negada, aprovação, upload, download e ausência de exposição das pastas internas.
7. Encerre a manutenção e registre resultados.

O runtime tem modos `full` e `read_only`, por escopo de portal, administração, APIs e arquivos. Administradores globais ativos possuem bypass. No modo somente leitura, o bloqueio usa o método HTTP; isso não garante ausência total de escrita, pois o runtime pode expirar documentos e páginas podem registrar auditoria. `maintenance-control.php` possui um fluxo separado de autenticação para encerrar a manutenção.

## Backup e recuperação

Inclua PostgreSQL, `storage/`, `uploads/`, configuração operacional protegida e referência do código. Banco e arquivos devem representar um estado compatível. Não coloque senhas ou backups em diretórios servidos por HTTP.

Homologue a restauração em uma base e pasta separadas: restaure o banco, reponha os arquivos, configure os segredos, confirme permissões do processo e execute a jornada funcional. Voltar apenas o código pode ser insuficiente depois de uma migração; não há runner geral de rollback identificado.

Responsáveis, frequência, retenção, RPO e RTO ainda precisam ser definidos pela operação. Esses valores não foram inferidos do código.

## Diagnóstico

| Sintoma | Primeiro ponto a conferir |
| --- | --- |
| Falha de conexão | Ambiente do serviço PHP, extensões, PostgreSQL e conectividade; erro detalhado no log do servidor |
| Sessão/419 | Sessão ativa, token CSRF do formulário/header e timeout |
| Acesso negado | Atividade da conta, equipe, permissões herdadas, visibilidade e estado do conteúdo |
| Upload recusado | `upload_max_filesize`, `post_max_size`, limite do servidor web, formato e permissão de escrita |
| Arquivo ausente | Vínculo no banco, nome armazenado e arquivo físico correspondente |
| AD indisponível | Domínio/URI efetivos, LDAP/TLS, CA, conta técnica e logs de autenticação |
| Tema não persiste no painel | Achado CR-04: meta CSRF ausente no cabeçalho |

Monitore os logs de erros PHP, especialmente os prefixos `DocGov` de workflow, configurações e auditoria. Não há stack de monitoramento ou política de retenção automatizada identificada nesta etapa.
