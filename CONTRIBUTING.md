# Contribuindo com o DocGov

[Projeto](README.md) · [Documentação](docs/README.md)

## Preparar o ambiente

Siga o [início rápido](docs/desenvolvimento/inicio-rapido.md) e utilize uma base exclusiva de desenvolvimento. Leia a [arquitetura](docs/referencia/arquitetura.md) e as [regras de acesso](docs/referencia/permissoes.md) antes de alterar fluxos administrativos.

## Fazer uma alteração

1. Identifique a página ou endpoint de entrada, os serviços envolvidos e a persistência afetada.
2. Mantenha cada alteração concentrada em um comportamento. Preserve alterações locais que já estejam no checkout.
3. Implemente a regra no serviço responsável e aplique a autorização também no backend.
4. Execute os checks de sintaxe e a regressão pertinente em ambiente isolado, conforme o [guia de testes](docs/desenvolvimento/testes.md).
5. Atualize a documentação do contrato ou procedimento alterado.
6. Descreva a mudança para revisão, incluindo problema, comportamento resultante, verificações executadas e limitações.

## Convenções do código existente

| Área | Orientação |
| --- | --- |
| PHP | Acompanhe o estilo dos arquivos envolvidos; serviços têm classes como `PermissionService` e usam PDO |
| Regras de negócio | Reutilize `services/`; evite duplicar regras entre `admin/` e `api/` |
| Autorização | Use `PermissionService` para conteúdo e `SystemAccessService` para capacidades globais |
| Sessão e CSRF | Reutilize o bootstrap da rota e `CsrfService`; valide no servidor |
| Persistência | Use parâmetros SQL e delimite transações de operações que precisam ser atômicas |
| Conteúdo | Reutilize os sanitizadores e validadores existentes antes de persistir HTML, mídia ou conteúdo estruturado |
| Frontend | Reutilize os componentes em `assets/` e `partials/`, incluindo os diálogos compartilhados |
| Banco | Acrescente uma migração para bases existentes e mantenha o schema de base nova compatível |

O repositório não define scripts npm de build/testes nem um manifesto Composer. Utilize os comandos documentados; não suponha que `npm test` ou `composer install` configurem o projeto.

## Mudanças de banco

Crie uma migração em `database/migrations/` e documente suas dependências, o efeito sobre dados e o procedimento de verificação. Confira colisões de numeração antes de escolher um novo nome. Atualize a lista explícita do executor quando aplicável, sem executar o runner completo como substituto da identificação de pendências.

`schema.sql` contém limpeza de tabelas. Seu papel é preparar bases vazias; atualização de uma instalação existente usa somente as migrações pendentes. Consulte [banco de dados](database/README.md).

## Verificação para revisão

- Checks de sintaxe dos arquivos PHP/JavaScript alterados e `git diff --check` aprovados.
- Regressão do comportamento afetado, incluindo falha e ausência de autorização quando aplicável.
- Interface conferida em computador/celular e temas afetados quando houver mudança visual.
- Contratos, configuração e instruções de migração atualizados junto do código.
- Resultados registrados com comando, ambiente, data, resultado e limites; verificações não executadas identificadas.

Não versione senhas, tokens, cookies, hashes de autenticação, dados pessoais de teste real nem backups. Use dados fictícios e `config/local.php` ou variáveis de ambiente para a configuração do ambiente.

A [convenção de documentação](docs/desenvolvimento/documentacao.md) define onde registrar cada tipo de informação.
