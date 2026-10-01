# Grupos dentro de grupos

Implementação de 01/10/2026. A gestão permanece exclusiva do administrador global.

## Usar no painel

Abra **Equipes → Editar equipe → Subgrupos**, selecione a equipe a incluir e clique em **Incluir subgrupo**. A mesma tela mostra os subgrupos, as equipes superiores e os membros ativos recebidos indiretamente. **Remover vínculo** preserva a equipe, os usuários e as permissões próprias; remove somente aquela origem de herança.

A lista de equipes distingue membros diretos e subgrupos. A tela de equipes do usuário e o diagnóstico de acesso explicam a origem com um caminho, por exemplo **Contas a Pagar → Financeiro**.

## Regras de acesso

- Membros de Contas a Pagar recebem os acessos ao conteúdo de Financeiro quando Contas a Pagar está incluída em Financeiro. Membros diretos de Financeiro não recebem os acessos exclusivos de Contas a Pagar.
- A herança funciona em vários níveis e permite que uma equipe pertença a mais de uma equipe superior. Membros são contados uma única vez mesmo quando existem caminhos repetidos.
- Todas as equipes do caminho devem estar ativas. Desativar uma equipe interrompe os caminhos que passam por ela, preservando vínculos e outras origens de acesso. Usuários inativos não recebem acesso.
- A regra existente continua: `view < edit < admin`, maior concessão vence. Uma regra menor no subgrupo não reduz uma concessão maior do grupo superior.
- Capacidades administrativas globais de `SystemAccessService` continuam sendo concedidas exclusivamente por vínculos diretos. Incluir uma equipe não concede automaticamente acesso a configurações, autenticação ou diretório.
- Autoinclusão, vínculos duplicados e ciclos indiretos são rejeitados. Inclusão e remoção exigem CSRF e são auditadas.

## Implantação sem reiniciar o sistema

A migração `database/migrations/027_nested_groups.sql` cria uma tabela de vínculos inicialmente vazia, seu índice e a proteção contra ciclos. Não modifica os grupos, membros ou permissões existentes. O código mantém a resolução por vínculos diretos enquanto a tabela não existir e oculta a nova aba nesse período.

Publique `GroupMembershipService.php` junto dos arquivos de aplicação modificados. Depois aplique exclusivamente a migração nova pelo adaptador CLI, usando a configuração do ambiente de destino:

```powershell
php scratch/apply_nested_groups_migration.php
```

O adaptador usa uma transação, espera no máximo dois segundos por locks e limita a execução a quinze segundos. Em caso de disputa ou erro, reverte a migração e sai com erro para permitir uma nova tentativa. Não ativa manutenção nem reinicia o servidor. A presença de locks breves do PostgreSQL ainda depende da carga do ambiente.

O arquivo `schema.sql` inclui a mesma estrutura para bases novas. **Não execute o schema em uma base existente**: ele contém a reinicialização de tabelas. O runner histórico agora lista 026 e 027, mas não deve substituir a aplicação exclusiva da migração pendente.

Escritas na hierarquia usam o isolamento padrão `READ COMMITTED` e uma trava transacional compartilhada entre serviço e trigger. O banco rejeita escritas de hierarquia com isolamento que possa manter um snapshot antigo; consultas de leitura não têm essa restrição. Remover uma equipe elimina seus vínculos por chave estrangeira, preservando os subgrupos.

## Verificar

```powershell
php scratch/test_nested_groups.php

# Para incluir navegador, aponte para o pacote Playwright instalado no ambiente:
$env:DOCGOV_PLAYWRIGHT_PACKAGE = '<caminho-do-pacote-playwright>'
php scratch/test_nested_groups.php --browser
```

A suíte exige PostgreSQL local de desenvolvimento com permissão para criar uma base temporária. Cria uma base exclusiva, testa compatibilidade antes da migração, herança, autorização, CSRF, auditoria, concorrência e regressões, e remove a base ao terminar. O teste de navegador inicia e encerra seu próprio servidor em uma porta livre; não usa nem encerra o servidor do sistema.
