# Início rápido para desenvolvimento

[Documentação](../README.md) · [Arquitetura](../referencia/arquitetura.md) · [Testes](testes.md)

Este guia prepara uma cópia local do DocGov. Execute os comandos PowerShell a partir da raiz do repositório e use uma base exclusiva de desenvolvimento.

## 1. Conferir os requisitos

| Componente | Requisito do projeto |
| --- | --- |
| PHP | Referência inicial 8.2+; a documentação registra sintaxe validada em 8.4.22, sem uma matriz de compatibilidade integral |
| Extensões | PDO/pdo_pgsql, session, mbstring, dom/libxml, fileinfo, openssl; ldap para AD |
| PostgreSQL | 14+, conforme `database/schema.sql` |
| Node.js/npm | Instalação das bibliotecas e verificação JavaScript |
| Chrome e Playwright | Necessários para as suítes de navegador que os utilizam |

```powershell
php -v
php -m
psql --version
node --version
npm --version
```

Confira as extensões no PHP CLI e, se usar outro servidor, também no PHP desse servidor. Playwright não é uma dependência declarada no manifesto atual.

## 2. Configurar a conexão

```powershell
Copy-Item -LiteralPath config/local.php.example -Destination config/local.php
```

Execute a cópia somente se `config/local.php` ainda não existir. Ajuste `db_host`, `db_port`, `db_name`, `db_user` e `db_password` para o banco local, mantendo `app_environment` como `development`. O arquivo é ignorado pelo Git.

Variáveis de ambiente de banco prevalecem sobre o arquivo local. Confira se o terminal não está herdando configuração de outro ambiente. Consulte a [referência de configuração](../referencia/configuracao.md).

## 3. Preparar o banco

Crie uma base nova e vazia pelo cliente PostgreSQL e aplique o schema. Por exemplo, para uma base local chamada `docgov_dev`:

```powershell
createdb -h 127.0.0.1 -U postgres docgov_dev
psql -h 127.0.0.1 -U postgres -d docgov_dev -v ON_ERROR_STOP=1 -f database/schema.sql
```

Configure `db_name=docgov_dev` no arquivo local e utilize uma conta com os privilégios necessários para a aplicação. Não coloque a senha na linha de comando. Se usar um usuário de instalação diferente, ajuste os comandos e os privilégios de acesso da conta da aplicação.

O schema contém `DROP TABLE`: confirme que a base de destino é nova e descartável. Em uma base existente, siga o procedimento de [migração](../../database/README.md#atualizar-uma-base-existente).

O seed é opcional para demonstração local. Leia `database/seed.sql` antes de utilizá-lo e considere os dados e contas que ele cria. Não use essas contas como provisionamento de produção.

## 4. Preparar dependências

```powershell
npm ci
```

O comando instala as versões do lockfile. Não copia bibliotecas para `assets/vendor/` nem gera um bundle. As páginas usam assets já presentes no repositório e também dependências externas; não há `npm run build` configurado.

## 5. Iniciar o portal

```powershell
php -d upload_max_filesize=50M -d post_max_size=512M -d memory_limit=768M -d max_input_time=300 -d display_errors=Off -d display_startup_errors=Off -d log_errors=On -S 127.0.0.1:8000 -t .
```

Abra `http://127.0.0.1:8000/login.php`. Mantenha o terminal aberto enquanto usa o portal; encerre com `Ctrl+C`.

O servidor embutido não aplica as regras `.htaccess` ou `web.config`. Use somente o endereço local e siga o [guia de operação](../operacao/instalacao-e-operacao.md) para publicação.

## 6. Fazer o primeiro acesso

Selecione um domínio habilitado e utilize uma conta autorizada no ambiente de desenvolvimento. A autenticação explícita consulta o AD e resolve a identidade por domínio. Uma linha em `users` ou o seed, isoladamente, não comprovam que o login será aceito pela configuração ativa.

Sem acesso ao AD, consulte os testes com diretório simulado no [guia de testes](testes.md). O fluxo de emergência tem regras próprias e não substitui o provisionamento inicial de uma conta autorizada.

Confirme a entrada no portal, a leitura de um conteúdo permitido e a recusa de um recurso fora do escopo. Os comandos de teste e os critérios para cada alteração estão em [testes e verificação](testes.md).
