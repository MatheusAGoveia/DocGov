# Manutenção da documentação

[Documentação](../README.md) · [Contribuição](../../CONTRIBUTING.md)

A documentação deve permitir que outra pessoa execute uma tarefa ou mantenha o código sem depender do histórico de conversas do projeto.

## Onde escrever

| Local | Finalidade |
| --- | --- |
| `README.md` | Apresentação, tecnologias, entrada para desenvolvimento e navegação |
| `CONTRIBUTING.md` | Fluxo de contribuição e critérios de revisão |
| `docs/guias/` | Procedimentos de quem usa o sistema |
| `docs/referencia/` | Arquitetura, contratos, configuração e regras |
| `docs/desenvolvimento/` | Ambiente local, testes e convenções |
| `docs/operacao/` | Instalação, atualização e sustentação |
| `docs/historico/` | Evidências de revisões, homologações e execuções datadas |
| `database/README.md` | Schema, migrações e modelo de dados |

Mantenha os planos existentes em `docs/superpowers/plans/`; planos descrevem trabalho e não substituem a referência da implementação concluída. O catálogo PHP do manual possui um ciclo de publicação separado.

## Formato de um documento

Use nomes em português, minúsculas e hífens, como `inicio-rapido.md`. Documentos históricos incluem a data `AAAA-MM-DD` quando ela identifica a execução. Use um título principal e um link de retorno ao índice. Estruture os demais títulos em ordem, sem saltar níveis.

Comece com a finalidade e o escopo. Um procedimento deve indicar pré-requisitos, passos, resultado esperado e limitações. Uma referência deve indicar a fonte no código, os nomes exatos dos campos e os comportamentos observáveis.

Exemplo de estrutura:

```markdown
# Título

[Documentação](../README.md)

Finalidade e escopo.

## Pré-requisitos

## Procedimento

## Verificação

## Referências relacionadas
```

Ajuste as seções ao conteúdo; não crie títulos vazios apenas para preencher o modelo. Tabelas servem a comparações e contratos; exemplos executáveis usam blocos de código com a linguagem indicada.

## Fonte e atualização

| Mudança | Documentos a revisar |
| --- | --- |
| Endpoint, payload ou resposta | API e regressões correspondentes |
| Permissão, equipe ou visibilidade | Permissões, arquitetura, guia de uso e matriz de capacidades |
| Banco ou migração | Banco de dados, instalação e referência da funcionalidade |
| Variável ou configuração | Configuração e operação |
| Workflow editorial | Arquitetura, guia de uso e API |
| Componente compartilhado | Referência do componente e testes pertinentes |

Use caminhos relativos para navegar no repositório. Ao mover um guia existente, atualize os links internos para o novo caminho e remova a versão substituída depois de conferir que o conteúdo foi preservado. Acrescente novos guias ao índice central.

## Evidências e histórico

Diferencie comportamento lido no código, teste executado e procedimento ainda não homologado. Relatórios registram data, referência do código quando conhecida, ambiente, comandos, resultado, artefatos e limites. Não apresente uma aprovação antiga como execução nova.

Achados de uma revisão pertencem ao seu escopo temporal. Registre a correção com evidência própria; preserve o relatório original. Artefatos citados que não estejam no checkout devem ser identificados como ausentes, sem links quebrados ou resultados inventados.

Não inclua senhas, tokens, cookies, hashes de autenticação, dumps de pessoas reais ou detalhes de credenciais nos exemplos. Prefira dados fictícios e placeholders.

## Revisão antes de concluir

1. Confira nomes de arquivos, campos, serviços e comandos no código.
2. Verifique os links relativos e as âncoras dos títulos.
3. Leia os passos como alguém configurando o projeto pela primeira vez.
4. Execute `git diff --check` e registre somente as verificações realmente realizadas.
