# Referência de configuração

[Documentação](../README.md) · [Instalação e operação](../operacao/instalacao-e-operacao.md)

As fontes desta referência são [db.php](../../config/db.php), [active_directory.php](../../config/active_directory.php), [session.php](../../config/session.php), [app_runtime.php](../../config/app_runtime.php), [SystemSettingsService](../../services/SystemSettingsService.php) e [.user.ini](../../.user.ini).

## Banco e ambiente

A precedência é: **variável de ambiente não vazia → chave de `config/local.php` → padrão do código**. Quando há aliases, o primeiro nome não vazio da tabela é utilizado.

| Variável | Alias | Chave local | Padrão |
| --- | --- | --- | --- |
| `APP_ENV` | — | `app_environment` | `development` |
| `DB_HOST` | `DB_HOSTNAME` | `db_host` | `127.0.0.1` |
| `DB_PORT` | — | `db_port` | `5432` |
| `DB_NAME` | `DB_DATABASE` | `db_name` | `docsec` |
| `DB_USER` | `DB_USERNAME` | `db_user` | `postgres` |
| `DB_PASS` | `DB_PASSWORD` | `db_password` | Sem padrão; configuração obrigatória |

Use [local.php.example](../../config/local.php.example) como modelo de desenvolvimento. Em produção, configure o ambiente do serviço PHP. O ambiente do terminal CLI pode ser diferente do ambiente do IIS, Apache ou PHP-FPM.

`APP_ENV=production` valida valores de conexão; não configura a publicação nem impede, por si só, a execução de utilitários operacionais.

## Active Directory

O arquivo `active_directory.php` prepara valores a partir do ambiente. Quando `SystemSettingsService` está disponível, aplica as configurações retornadas pelo serviço, incluindo padrões e domínios salvos no banco. Portanto, o painel/banco pode sobrepor valores do ambiente; a precedência do banco de dados da seção anterior não se aplica ao AD.

| Variável | Finalidade |
| --- | --- |
| `AD_AUTH_ENABLED` | Habilitação da autenticação AD; fallback inicial `true` |
| `AD_DEFAULT_DOMAIN` | Chave do domínio padrão; fallback inicial `BETIM` |
| `AD_SUPER_ADMIN_USERS` | Identidades com tratamento de administrador; prefira identidades qualificadas por domínio |
| `AD_INTEGRATED_WINDOWS_ENABLED` | Opção do serviço de login integrado; fallback inicial `false` |
| `AD_NETWORK_TIMEOUT` | Timeout LDAP; padrão 5 segundos, mínimo 2 |
| `AD_LDAP_URI` | Endereço LDAP/LDAPS do domínio BETIM |
| `AD_BASE_DN` | Base de consulta |
| `AD_DNS_DOMAIN`, `AD_NETBIOS_DOMAIN` | Identificação DNS e NetBIOS |
| `AD_CA_CERTIFICATE` | Caminho do certificado de confiança |
| `AD_SERVICE_BIND_DN`, `AD_SERVICE_BIND_PASSWORD` | Conta técnica de leitura |
| `AD_SAUDE_*` | Configuração equivalente de URI, Base DN, DNS, NetBIOS, CA e conta técnica para SAUDE |

Os valores efetivos dos domínios precisam corresponder ao ambiente. Os endereços e Base DN de exemplos de infraestrutura não devem ser copiados sem conferência. A configuração local de SAUDE confirmada em novembro está registrada em [identidades por domínio](identidades-ad-por-dominio.md).

O login atual exige domínio, usuário e senha; habilitar a opção integrada isoladamente não ativa SSO em `login.php`. Consulte [login integrado Windows](../operacao/windows-integrated-auth.md) e [conta técnica](../operacao/conta-leitura-ad.md).

## Configurações persistidas

`system_settings` armazena pares `setting_key`/`setting_value`, com valor JSONB. `SystemSettingsService` concentra padrões, validação e persistência. Entre as famílias de configuração estão identidade do portal, tema, timezone, timeout de sessão, CORS, manutenção e domínios AD.

Altere essas opções pelos fluxos administrativos autorizados. Credenciais técnicas salvas pelo painel ficam na configuração do sistema no banco; controle o acesso e a proteção dos backups.

## Sessão

| Propriedade | Comportamento |
| --- | --- |
| Transporte | Cookie de sessão PHP; a [API de integração](api-integracao.md) usa chave própria, sem sessão |
| Visitantes | Configuração `guest_access_enabled`, padrão ligado: botão “Entrar sem conta” e leitura somente de assuntos públicos |
| Cookie | `HttpOnly`, `SameSite=Lax`, vida útil de sessão do navegador |
| `Secure` | Ativado quando PHP identifica HTTPS ou porta 443 |
| Inatividade | Configuração `session_timeout_minutes`, padrão 120 minutos, limitada pelo runtime a 15–480 minutos |
| CSRF | Validado pelas rotas de escrita; consulte os contratos de cada endpoint |

Se houver proxy reverso, valide se PHP identifica HTTPS corretamente na implantação. `DOCGOV_SKIP_APP_RUNTIME=true` é uma constante usada por alguns executores para não carregar o runtime; não é uma variável de ambiente geral nem uma garantia de isolamento de testes.

## Uploads

| Diretiva versionada | Valor |
| --- | --- |
| `upload_max_filesize` | `50M` |
| `post_max_size` | `512M` |
| `memory_limit` | `768M` |
| `max_input_time` | `300` |
| `display_errors`, `display_startup_errors` | `Off` |
| `log_errors` | `On` |

`.user.ini` atende CGI/FastCGI. O servidor embutido requer `php.ini` ou opções `-d`. O proxy/servidor web também precisa comportar o corpo enviado. Os limites funcionais por tipo e lote estão em [operação](../operacao/instalacao-e-operacao.md#limites-de-envio).

## Dados que não devem ser versionados

Mantenha senhas, cookies, tokens e backups fora da documentação. `config/local.php` é ignorado pelo Git, mas precisa também ficar protegido contra acesso HTTP na implantação. Exemplos devem usar placeholders e dados fictícios.
