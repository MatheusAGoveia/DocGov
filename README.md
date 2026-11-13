# DocGov

Portal interno de gestão documental e conhecimento, com autenticação corporativa, controle de acesso por usuário/equipe e revisão editorial.

O acervo segue a hierarquia **categoria → subcategoria → assunto → documento**. O portal reúne artigos, arquivos, links, código, vídeos, fluxos e organogramas, além da documentação de processos por assunto.

## Documentação

| Quero… | Consulte |
| --- | --- |
| Executar o projeto para desenvolvimento | [Início rápido](docs/desenvolvimento/inicio-rapido.md) |
| Entender a aplicação e seus serviços | [Arquitetura](docs/referencia/arquitetura.md) |
| Encontrar ações e funções do painel | [Mapa do painel administrativo](admin/README.md) |
| Consultar endpoints e autenticação | [APIs e rotas](docs/referencia/api.md) |
| Configurar banco, sessão e AD | [Configuração](docs/referencia/configuracao.md) |
| Instalar, atualizar ou recuperar o ambiente | [Instalação e operação](docs/operacao/instalacao-e-operacao.md) |
| Usar o portal e administrar conteúdos | [Guia de uso](docs/guias/uso-do-sistema.md) |
| Executar verificações | [Testes](docs/desenvolvimento/testes.md) |
| Contribuir com o projeto | [Guia de contribuição](CONTRIBUTING.md) |

O [índice completo](docs/README.md) inclui permissões, importação de usuários, integração AD e registros históricos.

## Tecnologias

| Camada | Implementação |
| --- | --- |
| Aplicação | PHP, páginas renderizadas no servidor e serviços sem framework |
| Persistência | PostgreSQL via PDO; arquivos em armazenamento local |
| Interface | JavaScript e CSS, Quill e visualizadores de documentos |
| Identidade | Active Directory por LDAP/LDAPS e sessão PHP |
| Dependências frontend | npm, com versões resolvidas em `package-lock.json` |

O manifesto npm gerencia bibliotecas e não define comandos de build ou de testes. A conexão de execução usa PostgreSQL.

## Desenvolvimento local

1. Confira os [requisitos e a preparação do banco](docs/desenvolvimento/inicio-rapido.md).
2. Copie `config/local.php.example` para `config/local.php` e configure uma base exclusiva de desenvolvimento.
3. Com essa base preparada, inicie o servidor a partir da raiz:

```powershell
php -d upload_max_filesize=50M -d post_max_size=512M -d memory_limit=768M -d max_input_time=300 -d display_errors=Off -d display_startup_errors=Off -d log_errors=On -S 127.0.0.1:8000 -t .
```

Abra Sua URL com uma conta autorizada no ambiente. A preparação inicial de contas está descrita no guia de instalação.

**Banco existente:** aplique somente migrações pendentes. `database/schema.sql` contém comandos de remoção de tabelas e deve ser usado apenas em uma base vazia e descartável. O seed contém dados de demonstração.

O servidor embutido atende somente ao desenvolvimento local. A publicação exige HTTPS e bloqueio de acesso direto às pastas internas, conforme o [guia de operação](docs/operacao/instalacao-e-operacao.md).

## Estrutura do repositório

```text
admin/       painel administrativo e importação de usuários
api/         endpoints JSON
assets/      JavaScript, CSS e bibliotecas servidas localmente
config/      conexão, sessão, runtime e integração AD
database/    schema, migrações, seed e especificações de dados
docs/        documentação organizada por finalidade
partials/    componentes PHP compartilhados
services/    regras de negócio, autorização e persistência
scratch/     testes, fixtures e utilitários operacionais
storage/     documentos, imagens e mídia de artigos
uploads/     avatares
```

## Escopo da documentação

Os guias descrevem o código do checkout revisado em **13/11/2026**. Resultados anteriores permanecem no [histórico](docs/historico/README.md), com seus próprios limites. A reorganização documental não representa homologação funcional, validação de capacidade ou aprovação de uma implantação.
