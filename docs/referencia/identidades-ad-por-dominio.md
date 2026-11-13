# Usuários AD com o mesmo login em domínios diferentes

[Documentação](../README.md)

O Documentos permite que `BETIM\marcuss` e `SAUDE\marcuss` tenham cadastros independentes, inclusive quando os nomes completos são diferentes. Cada cadastro conserva seu ID, equipes e permissões. Ter o mesmo nome completo também não reúne automaticamente as contas.

O acesso continua dependendo da autenticação no domínio selecionado. O nome completo é lido dos atributos `displayName` ou `cn` do AD e atualizado no cadastro da identidade correspondente. Uma alteração de nome para o mesmo GUID mantém a conta existente. Se a consulta não retornar esses atributos, o nome e o GUID já registrados são preservados.

O banco mantém o login único dentro de cada domínio, sem distinguir maiúsculas e minúsculas. Contas locais têm login único entre si. E-mail e GUID continuam protegidos contra conflitos; um GUID diferente para um login já registrado no mesmo domínio exige revisão administrativa, em vez de transferir as permissões da pessoa anterior.

Importação, replicação, login integrado, fallback de emergência e histórico de acesso da ficha do usuário respeitam o domínio.

## Atualização de uma instalação existente

Publique o código desta correção e aplique somente `database/migrations/028_ad_domain_identity.sql`, em uma transação PostgreSQL. A migração valida os novos índices antes de remover `users_username_key`; não renomeia usuários nem modifica dados de cadastro. `database/schema.sql` é destinado a bases novas e contém limpeza de tabelas: não o execute para atualizar uma instalação existente.

Não é necessário apagar nem renomear a conta BETIM para permitir o cadastro de uma pessoa de SAUDE com o mesmo login. No próximo acesso validado pelo AD, o cadastro do domínio correto é criado ou atualizado.

## Verificação reproduzível

```powershell
node scratch/test_batch_directory_ldap.js --domain-login
node scratch/test_batch_directory_ldap.js
```

O primeiro comando cria uma base PostgreSQL temporária, usa a extensão PHP LDAP contra um servidor simulado local e remove a base ao terminar. Cobre pessoas de nomes diferentes, homônimos, alteração de nome, preservação de permissões, conflitos de GUID/e-mail, importação, login de emergência e migração. Também executa as regressões existentes de login integrado, importação em lote e conta técnica nessa mesma base temporária.

O segundo comando verifica a consulta LDAP por nome completo/login, homônimos, caracteres especiais e falhas de consulta. Os testes não utilizam senhas corporativas reais. A confirmação de acesso no AD de SAUDE precisa de uma nova tentativa normal de login pelo usuário.

O status de BETIM no painel depende também da conta técnica configurada. A correção dos cadastros por domínio não altera essa credencial.

## Seleção de domínio e primeiro envio de senha

O formulário passa a chave selecionada separadamente do login: BETIM autentica em BETIM; SAUDE autentica em SAUDE. Cada envio faz um único bind com o domínio NetBIOS configurado. Uma rejeição não repete a senha como UPN ou login sem domínio. Um login qualificado é aceito somente quando corresponde à seleção; um domínio divergente ou desconhecido é recusado.

Ao concluir o login, o horário da sessão é renovado e o marcador de expiração é removido antes do redirecionamento. Isso corrige o caso reproduzido em que uma sessão sem usuário, mas com horário antigo, invalidava um login recém-aceito e devolvia a pessoa à tela de entrada. O formulário identifica corretamente usuário e senha atual para o navegador e preserva o domínio e o login após um erro.

Nos registros de marcuss analisados em 11/11/2026, o primeiro envio retornou `52e` do AD e o seguinte teve sucesso, em ambos os domínios. O usuário confirmou que a primeira tela mostrava senha inválida. Isso demonstra uma rejeição do AD; o defeito de sessão foi reproduzido separadamente e não é apresentado como a causa comprovada desse primeiro envio rejeitado. Não foram solicitadas nem capturadas senhas pessoais para investigar o caso.

## Monitoramento e configuração de SAUDE

A atualização automática do painel testa rede e protocolo sem repetir autenticações da conta técnica. O botão Atualizar Saúde Agora verifica também a credencial e a Base DN. Um servidor que respondeu ao LDAP continua acessível mesmo que rejeite a conta técnica; o painel mostra essa rejeição e erros de Base DN separadamente.

O servidor `hermes.saude.betim` informou `DC=saude,DC=betim` como contexto padrão. Essa Base DN foi confirmada novamente e aplicada apenas à configuração de SAUDE no banco local, preservando as demais configurações e credenciais. A conta técnica atualizada de BETIM passou na autenticação e na leitura real da Base DN.

Os testes por HTTP cobrem entrada no primeiro envio com sessão nova/antiga nos dois domínios, senha de outro domínio, recuperação após erro, identidades qualificadas e seleção inválida. A verificação opcional de navegador pode ser habilitada por `DOCGOV_PLAYWRIGHT_PACKAGE`; usa Chrome e verifica os estados do painel e o KPI de conectividade. Todas essas fixtures usam base e servidor de teste temporários.
