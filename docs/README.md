# Documentação do DocGov

Esta é a entrada central para a documentação do repositório. A primeira organização foi feita em **01/10/2026**, tomando `d904224` como referência de histórico e inspecionando também o checkout local. Há alterações concorrentes na importação de usuários; a revisão dessas alterações deve ocorrer quando estiverem estabilizadas.

## Guias atuais

| Documento | Público | Cobertura |
| --- | --- | --- |
| [Uso do sistema](USO_DO_SISTEMA.md) | Leitores, editores e administradores | Navegação, conteúdo, revisão e acesso |
| [Arquitetura](ARQUITETURA.md) | Desenvolvimento e infraestrutura | Componentes, dados, autorização e fluxos |
| [Instalação e operação](INSTALACAO_OPERACAO.md) | Desenvolvimento e infraestrutura | Configuração, banco, implantação, backup e diagnóstico |
| [APIs e rotas](API.md) | Desenvolvimento | Contratos principais, sessão, CSRF e limitações |
| [Testes](TESTES.md) | Desenvolvimento e homologação | Verificações, suítes existentes e lacunas |
| [Code review de 01/10/2026](CODE_REVIEW_2026-10-01.md) | Desenvolvimento e responsáveis pelo sistema | Achados, prioridades e critérios de aceite |

## Referências existentes

- [Assuntos públicos e privados](assuntos-publicos-privados.md): visibilidade, autorização e migração 026.
- [Conta de leitura AD](conta-leitura-ad.md): preservação e teste das credenciais por domínio.
- [Login integrado Windows](windows-integrated-auth.md): referência de infraestrutura; veja a nota sobre o login explícito atual.
- [Banco de dados](../database/README.md), [arquitetura de permissões](../database/PERMISSIONS_ARCHITECTURE.md) e [matriz de capacidades](../database/CAPABILITIES_MATRIX.md).
- [Revisão de 30/09/2026](CODE_REVIEW_2026-09-30.md) e [homologação de 02/09/2026](RELATORIO_HOMOLOGACAO_PRE_LANCAMENTO_2026-09-02.md): evidências históricas, com escopos e datas próprios.
- [Catálogo do manual](docgov_catalog.php): fonte PHP do conteúdo destinado ao acervo em TI → DocGov. Sua existência não comprova que esteja publicado ou atualizado no banco.

## Próximos incrementos

| Prioridade | Entrega | Critério de conclusão |
| --- | --- | --- |
| 1 | Tratar os achados CR-01 a CR-03 | Correções e verificações descritas no code review aprovadas |
| 2 | Homologar configuração de IIS/Apache e recuperação | Evidência de bloqueio das pastas internas e restauração em ambiente isolado |
| 3 | Consolidar testes e automação de checks | Comando único para suíte isolada, com saída, falha e limpeza previsíveis |
| 4 | Ampliar os contratos de API | Payloads/respostas completos, erros uniformes e testes por endpoint |
| 5 | Documentar importação de usuários após estabilização | Fluxo, limites, autorização, resultados parciais e teste publicados |
| 6 | Sincronizar o manual no acervo | Conteúdo revisado e publicação controlada com validação de leitura |

## Manutenção dos documentos

Mudanças em autorização devem atualizar arquitetura, guia de uso e matriz de capacidades. Mudanças em endpoints devem atualizar API e testes. Mudanças em banco/configuração devem atualizar instalação e operação. Registre o commit e os comandos de validação nas futuras revisões.

Não copie segredos, cookies de sessão, hashes de senha ou dumps de usuários para os guias. Os achados de credenciais apontam os arquivos e a ação necessária sem reproduzir os valores.
