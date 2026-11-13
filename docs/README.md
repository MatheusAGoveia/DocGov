# Documentação do DocGov

[Repositório](../README.md) · [Contribuição](../CONTRIBUTING.md)

Documentação do código e dos procedimentos do DocGov. Escolha um caminho conforme a tarefa: usar o sistema, desenvolver, consultar uma regra ou operar o ambiente.

## Começar

| Perfil | Percurso recomendado |
| --- | --- |
| Desenvolvimento | [Início rápido](desenvolvimento/inicio-rapido.md) → [Arquitetura](referencia/arquitetura.md) → [Testes](desenvolvimento/testes.md) |
| Infraestrutura | [Configuração](referencia/configuracao.md) → [Instalação e operação](operacao/instalacao-e-operacao.md) → [Banco de dados](../database/README.md) |
| Edição e administração | [Guia de uso](guias/uso-do-sistema.md) → [Permissões](referencia/permissoes.md) → [Importação de usuários](guias/importacao-usuarios-em-lote.md) |
| Integração | [APIs e rotas](referencia/api.md) → [Identidades AD](referencia/identidades-ad-por-dominio.md) |

## Guias de uso

| Documento | Conteúdo |
| --- | --- |
| [Uso do sistema](guias/uso-do-sistema.md) | Consulta, favoritos, conteúdo, revisão e administração |
| [Importação de usuários e membros em lote](guias/importacao-usuarios-em-lote.md) | Lista de entrada, domínio, resultados e reprocessamento |

## Referência técnica

| Documento | Conteúdo |
| --- | --- |
| [Arquitetura](referencia/arquitetura.md) | Bootstrap, componentes, serviços, dados e fluxos |
| [Mapa do painel administrativo](../admin/README.md) | Localização de ações, serviços e funções JavaScript por funcionalidade |
| [APIs e rotas](referencia/api.md) | Sessão, CSRF, métodos, campos, respostas e autorização |
| [Configuração](referencia/configuracao.md) | Variáveis, precedência, sessão, limites e segredos |
| [Banco de dados](../database/README.md) | Modelo atual, instalação e evolução do schema |
| [Permissões e capacidades](referencia/permissoes.md) | Acesso efetivo, equipes, visibilidade e administração global |
| [Assuntos públicos e privados](referencia/assuntos-publicos-privados.md) | Regras de leitura e migração 026 |
| [Grupos aninhados](referencia/grupos-aninhados.md) | Herança de equipes e migração 027 |
| [Identidades AD por domínio](referencia/identidades-ad-por-dominio.md) | Login, identidade, sessão, diagnóstico e migração 028 |
| [Avisos e confirmações](referencia/dialogos-do-sistema.md) | Contrato do componente de diálogos e acessibilidade |
| [Matriz de capacidades de conteúdo](../database/CAPABILITIES_MATRIX.md) | Operações por nível de permissão |
| [Especificação de origem das permissões](../database/PERMISSIONS_ARCHITECTURE.md) | Algoritmos e contexto do modelo; inclui seções históricas |

## Desenvolvimento

| Documento | Conteúdo |
| --- | --- |
| [Início rápido](desenvolvimento/inicio-rapido.md) | Requisitos, configuração local e primeiro acesso |
| [Testes e verificação](desenvolvimento/testes.md) | Checks de sintaxe, seleção de suítes e isolamento |
| [Contribuição](../CONTRIBUTING.md) | Fluxo de alteração, convenções e validação |
| [Manutenção da documentação](desenvolvimento/documentacao.md) | Organização, formato, fontes e atualização |

## Operação

| Documento | Conteúdo |
| --- | --- |
| [Instalação e operação](operacao/instalacao-e-operacao.md) | Banco, migrações, uploads, publicação, backup e diagnóstico |
| [Conta de leitura AD](operacao/conta-leitura-ad.md) | Credenciais técnicas, preservação e teste por domínio |
| [Login integrado Windows](operacao/windows-integrated-auth.md) | Pré-requisitos de infraestrutura e limites da implementação atual |

## Histórico e material do manual

O [histórico](historico/README.md) reúne revisões, homologações e resultados já registrados. O estado dos achados se refere à data de cada relatório; consulte o código e execute a regressão pertinente antes de decidir pelo encerramento de um item.

O [catálogo do manual](docgov_catalog.php) é a fonte PHP de conteúdo destinada ao acervo TI → DocGov. Ele é separado dos guias do repositório: editar um Markdown não publica nem sincroniza documentos no banco.

```text
docs/
├── README.md
├── guias/                uso e procedimentos do usuário
├── referencia/           arquitetura, contratos e regras
├── desenvolvimento/     ambiente, testes e documentação
├── operacao/             instalação e sustentação
├── historico/            revisões, homologações e validações
├── superpowers/plans/    planos de implementação existentes
└── docgov_catalog.php    fonte do manual publicado no acervo
```

Os arquivos substituídos foram removidos. Novos conteúdos devem seguir a [convenção documental](desenvolvimento/documentacao.md).
