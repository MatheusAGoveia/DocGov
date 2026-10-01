# DocGov

Portal interno de gestão documental e conhecimento. Organiza o acervo em **categoria → subcategoria → assunto → documento**, com acesso por usuário/equipe, revisão editorial e autenticação corporativa pelo Active Directory.

## Comece aqui

| Necessidade | Documento |
| --- | --- |
| Consultar, editar e revisar conteúdos | [Guia de uso](docs/USO_DO_SISTEMA.md) |
| Entender componentes, dados e permissões | [Arquitetura](docs/ARQUITETURA.md) |
| Preparar ambiente, atualizar e recuperar o sistema | [Instalação e operação](docs/INSTALACAO_OPERACAO.md) |
| Integrar ou manter os endpoints | [APIs e rotas](docs/API.md) |
| Escolher e executar verificações | [Testes](docs/TESTES.md) |
| Priorizar problemas encontrados na varredura | [Code review de 01/10/2026](docs/CODE_REVIEW_2026-10-01.md) |
| Consultar toda a documentação e suas pendências | [Índice da documentação](docs/README.md) |

## Visão técnica

- Backend PHP, páginas renderizadas no servidor e serviços em `services/`, sem framework ou manifesto Composer no checkout inspecionado.
- PostgreSQL via PDO; o banco de execução é definido em `config/db.php`. O arquivo SQLite em `data/` é legado, não a conexão atual.
- Frontend com JavaScript/CSS, Quill e visualizadores de arquivos. Há assets locais e dependências externas carregadas pelas páginas.
- Conteúdos de texto, arquivo, link, código, vídeo, fluxo e organograma; seções configuráveis no banco.
- Permissões hierárquicas em `PermissionService`; capacidades administrativas globais em `SystemAccessService`.

## Execução e alterações

Siga o [guia de instalação](docs/INSTALACAO_OPERACAO.md) antes de preparar o ambiente. Em uma base existente, aplique apenas as migrações pendentes. `schema.sql` é destinado a uma base vazia e `seed.sql` contém contas de teste.

A varredura inicial identificou scripts operacionais sem bloqueio HTTP, credenciais literais em utilitários e lacunas no executor de migrações. Os detalhes e critérios de correção estão no [relatório de revisão](docs/CODE_REVIEW_2026-10-01.md). A configuração do servidor publicado não foi inspecionada.

## Organização

```text
admin/       painel e partials administrativos
api/         endpoints JSON de estrutura, documentos e permissões
assets/      scripts, estilos e bibliotecas locais
config/      conexão, sessão, runtime e configuração AD
database/    schema, migrações e especificações de acesso
docs/        guias, revisões e catálogo de conteúdo do manual
partials/    componentes compartilhados das páginas
services/    regras de negócio e persistência
storage/     documentos, imagens da estrutura, logo e mídia de artigos
uploads/     avatars
scratch/     testes, adaptadores e utilitários operacionais
```

Documentação estruturada a partir do código em 01/10/2026. Os resultados desta etapa são de revisão estática e sintaxe; não representam homologação do ambiente publicado.
