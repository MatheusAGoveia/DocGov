# Login integrado do Windows (BETIM e SAÚDE)

[Documentação](../README.md)

> **Situação atual — 01/10/2026:** `login.php` exige usuário e senha em cada nova sessão e não chama `ActiveDirectoryAuthService::authenticateIntegrated()` automaticamente. O método e a opção de configuração existem, mas habilitar a variável abaixo, isoladamente, não ativa SSO na entrada atual. Este documento descreve a infraestrutura necessária para uma futura ativação revisada. Consulte [arquitetura](../referencia/arquitetura.md) e [instalação e operação](instalacao-e-operacao.md) para o comportamento vigente.

O DocGov não lê nem recebe a senha usada no Windows. O IIS ou Apache autentica o navegador com Kerberos/NTLM e encaminha somente a identidade no campo `REMOTE_USER`, como `BETIM\\matheus.damiao` ou `SAUDE\\nome.sobrenome`.

## Variáveis de ambiente

Os valores abaixo são placeholders de infraestrutura; substitua-os pelos dados de cada domínio no ambiente de destino. A configuração local confirmada em novembro está registrada em [identidades por domínio](../referencia/identidades-ad-por-dominio.md#monitoramento-e-configuração-de-saude). Habilitar estas opções não altera o fluxo explícito de login atual.

```text
AD_INTEGRATED_WINDOWS_ENABLED=true
AD_DEFAULT_DOMAIN=BETIM

# Domínio BETIM: substituir pelos valores do ambiente.
AD_LDAP_URI=ldaps://<controlador-betim>:636
AD_BASE_DN=<base-dn-betim>
AD_NETBIOS_DOMAIN=BETIM

# Domínio SAÚDE: preencher com dados fornecidos pela TI.
AD_SAUDE_LDAP_URI=ldaps://<controlador-saude>:636
AD_SAUDE_BASE_DN=<base-dn-saude>
AD_SAUDE_DNS_DOMAIN=<dominio-dns-saude>
AD_SAUDE_NETBIOS_DOMAIN=SAUDE
AD_SAUDE_CA_CERTIFICATE=C:\\caminho\\certificado-saude.pem
AD_SAUDE_SERVICE_BIND_DN=<identidade-da-conta-tecnica>
AD_SAUDE_SERVICE_BIND_PASSWORD=<segredo no ambiente>

# Um Super Admin de cada domínio pode ser declarado de forma inequívoca.
AD_SUPER_ADMIN_USERS=BETIM\\administrador,SAUDE\\administrador.saude
```

A conta técnica é usada para pesquisar/importar contas e sincronizar os dados cadastrais após o login integrado. Sem ela, apenas usuários já importados podem entrar automaticamente.

## IIS

No site/aplicação do DocGov, habilite **Windows Authentication**, desabilite **Anonymous Authentication** nas rotas administrativas e use HTTPS. A identidade deve chegar ao PHP em `REMOTE_USER`. Configure os navegadores internos como zona Intranet para a negociação automática.

## Apache

Proteja as rotas com Kerberos/NTLM (por exemplo, `mod_auth_gssapi`) e confirme que o módulo encaminha o usuário autenticado como `REMOTE_USER`. Não aceite cabeçalhos `Remote-User` enviados pelo cliente; somente o servidor web pode definir essa variável.

## Validação antes da ativação

1. Deixe `AD_INTEGRATED_WINDOWS_ENABLED=false` até o IIS/Apache estar protegido.
2. Acesse `login.php` com uma conta BETIM e uma SAÚDE e confirme a identidade exibida nos registros de auditoria.
3. Confirme que uma conta não importada é bloqueada e que uma conta desativada também não entra.
