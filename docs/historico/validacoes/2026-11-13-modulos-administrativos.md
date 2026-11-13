# Validação da organização modular do painel — 13/11/2026

[Documentação](../../README.md) · [Mapa do painel](../../../admin/README.md) · [Plano executado](../../superpowers/plans/2026-11-13-organizacao-modulos-administrativos.md)

## Escopo

Refatoração do painel para facilitar encontrar as funções por funcionalidade. Foram extraídos 29 manipuladores PHP e 33 funções JavaScript. A entrada administrativa passou de 8.245 para 4.915 linhas. Consultas de tela e a maior parte do HTML/CSS continuam nela.

A referência de comparação foi o checkout local antes da extração, incluindo suas alterações anteriores. SHA-256 de `admin/index.php` na captura: `c8f78864276c78adcd04c00a4cf3ad74f8ab953fbb6ca2f8ae9e12909ac2acbf`. A captura e o inventário de arquivos foram guardados fora do repositório; não contêm credenciais corporativas.

## Execuções e resultados

| Verificação | Resultado |
| --- | --- |
| Caracterização HTTP anterior e repetição antes da extração | 51 verificações de respostas e efeitos persistidos aprovadas |
| Comparação depois da extração | Mesmas 51 verificações, sem diferenças de contrato |
| Acesso direto aos includes internos | GET e POST recusados com 404, sem mudança do estado persistido |
| Upload em lote de arquivo fictício | HTTP 201, destino físico e conteúdo conferidos, arquivo removido |
| Interface | 1440×900/claro e 390×844/escuro; listagem, menus, filtros, seleção, editor, tags, configuração escapada, hierarquia por ID com nomes repetidos, preview, árvore e membros aprovados |
| Regressões existentes | 12 suítes aprovadas na base temporária |
| Identidades AD por domínio | 33 verificações agregadas e cenários HTTP/saúde/navegador aprovados, com LDAP simulado |
| Consulta LDAP em lote | Protocolo, reutilização de conexão, nomes, Unicode e falhas aprovados com servidor simulado |
| Falha provocada após upload | Falha esperada detectada; driver removeu arquivo, base, sessões e artefatos |
| Sintaxe | 33 arquivos PHP e 6 JavaScript aprovados |
| Diff | `git diff --check` aprovado |
| Documentação | 33 arquivos Markdown e 333 links/âncoras conferidos, sem erros |
| Integridade dos arquivos fora do recorte | Nenhuma alteração inesperada no inventário; nenhum arquivo novo remanescente em `storage/` ou `uploads/` |
| Bases temporárias do runner ao final | Zero |

As 12 regressões foram: `test_permissions.php`, `test_permission_api.php`, `test_hierarchy_resolution.php`, `test_document_approval_workflow.php`, `test_duplicate_document_creation.php`, `test_subject_workspace.php`, `test_group_access_admin.php`, `test_system_access_groups.php`, `test_system_settings_admin.php`, `test_structure_permanent_deletion.php`, `test_tags.php` e `test_access_integrity.php`.

## Comandos

Executados a partir da raiz, em PowerShell. A captura foi feita antes da alteração do código:

```powershell
php scratch/test_admin_architecture.php --record "$env:TEMP\docgov-admin-architecture-before.json"
php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json"
php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json" --browser --regression
php scratch/test_admin_architecture.php --compare "$env:TEMP\docgov-admin-architecture-before.json" --fail-after-upload
node scratch/test_batch_directory_ldap.js --domain-login
node scratch/test_batch_directory_ldap.js
git diff --check
```

O comando com `--fail-after-upload` retorna 1 propositalmente; foi conferida a mensagem esperada e a limpeza posterior. O Playwright foi localizado na instalação já disponível do runtime pela variável `DOCGOV_PLAYWRIGHT_PACKAGE`; nenhuma dependência foi adicionada ao manifesto npm.

## Revisão independente

Uma leitura independente comparou os blocos e as funções com o snapshot de origem, incluindo ordem, escopo, caminhos, headers, encerramento de requisição, guardas e inicialização dos scripts. Não encontrou regressões acionáveis no código de produção.

A revisão identificou dois problemas no runner durante sua ampliação: consulta de arquivo antes da conexão e limpeza insuficiente do upload quando o Node falhava. Ambos foram corrigidos e revistos; a falha controlada após upload confirmou a limpeza pelo driver PHP.

## Limites

Os testes utilizaram bases locais temporárias, usuários fictícios, sessões exclusivas e servidores de teste. Não houve migração da base em uso, autenticação corporativa real ou homologação do servidor publicado. As alterações anteriores do checkout foram preservadas; a refatoração permanece local e não foi incluída em um commit que misture trabalho anterior.

O estado compartilhado entre a entrada e os manipuladores continua explícito e documentado. A separação de consultas, templates e serviços grandes não faz parte desta primeira etapa.
