# Conta de leitura para importação do Active Directory

Em **Administração → Servidores AD → domínio**, configure a seção **Conta de leitura para importar usuários**. Informe o login e a senha diretamente no painel e salve as configurações do domínio. O login aceita a identidade reconhecida pelo AD, como `DOMINIO\usuario`, UPN ou DN completo.

Cada domínio mantém sua própria conta; as réplicas daquele domínio compartilham essa configuração. Salvar um domínio preserva os demais, o domínio principal e as configurações globais de autenticação. A senha salva não é enviada no HTML. Deixar o campo de senha vazio conserva a senha existente quando o login da conta permanece igual. Trocar o login exige a nova senha.

**Autenticar & Testar Este Servidor** valida a conexão, o bind e uma leitura da Base DN. O teste não importa usuários nem altera o AD. Ao reutilizar a senha salva, o endereço do servidor precisa estar cadastrado no domínio. Autorização e CSRF são verificados no backend. Um teste de rede sem credenciais é identificado separadamente de uma autenticação com conta de leitura.

A importação continua exigindo conta e senha válidas. Nenhuma senha de usuário importado é solicitada. Credenciais operacionais ficam na configuração local do sistema; não são incluídas no Git.

## Validação

- `php scratch/test_ad_service_account.php`: preservação, troca e validação das credenciais, caracteres especiais e validação da URI.
- `node scratch/test_ad_service_account_browser.js`: salvamento pelo formulário, senha ausente do HTML, senha preservada ao salvar novamente, isolamento entre domínios e configurações globais, autorização, CSRF, teste com conta salva e layout em celular/desktop. Exige Playwright e Chrome; `DOCGOV_PLAYWRIGHT_PACKAGE` pode indicar o pacote instalado.

Os testes usam um domínio temporário com endpoint local e removem suas fixtures. A validação operacional utiliza um bind e uma leitura na Base DN, sem importação ou mudanças no diretório.
