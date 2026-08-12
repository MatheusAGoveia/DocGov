# Login integrado do Windows (BETIM e SAÚDE)

O DocGov não lê nem recebe a senha usada no Windows. O IIS ou Apache autentica o navegador com Kerberos/NTLM e encaminha somente a identidade no campo `REMOTE_USER`, como `BETIM\\matheus.damiao` ou `SAUDE\\nome.sobrenome`.

## Variáveis de ambiente

```text
AD_INTEGRATED_WINDOWS_ENABLED=true
AD_DEFAULT_DOMAIN=BETIM

# Domínio BETIM (já configurado)
AD_LDAP_URI=ldaps://diana.betim.pmb:636
AD_BASE_DN=DC=betim,DC=pmb
AD_NETBIOS_DOMAIN=BETIM

# Domínio SAÚDE: preencher com dados fornecidos pela TI.
AD_SAUDE_LDAP_URI=ldaps://servidor-saude:636
AD_SAUDE_BASE_DN=DC=saude,DC=pmb
AD_SAUDE_DNS_DOMAIN=saude.pmb
AD_SAUDE_NETBIOS_DOMAIN=SAUDE
AD_SAUDE_CA_CERTIFICATE=C:\\caminho\\certificado-saude.pem
AD_SAUDE_SERVICE_BIND_DN=CN=docgov-reader,OU=Service Accounts,DC=saude,DC=pmb
AD_SAUDE_SERVICE_BIND_PASSWORD=<segredo no ambiente>

# Um Super Admin de cada domínio pode ser declarado de forma inequívoca.
AD_SUPER_ADMIN_USERS=BETIM\\matheus.damiao,SAUDE\\administrador.saude
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
