# Identidades AD por domínio — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir a entrada de pessoas com o mesmo login em BETIM e SAUDE, inclusive com nomes completos diferentes, preservando a identidade e as permissões de cada cadastro.

**Architecture:** A identidade corporativa usa domínio e login, com confirmação do objectGUID quando disponível. O nome completo vem do AD e é atualizado no cadastro correspondente; nomes iguais ou diferentes não transferem vínculos entre contas. Uma migração substitui a unicidade global do login por unicidade dentro do domínio.

**Tech Stack:** PHP 8.4, PDO e PostgreSQL 14+.

**Spec:** Solicitação do usuário neste chat em 11/11/2026 e diagnóstico confirmado: `SAUDE\\marcuss` falha em `users_username_key`, pois já existe `BETIM\\marcuss`.

## Global Constraints

- Preservar IDs, equipes, permissões e contas existentes.
- Nomes completos diferentes devem permitir cadastros separados em domínios diferentes.
- A senha continua validada pelo AD; nome completo não autoriza acesso por si só.
- Manter a unicidade do e-mail e do GUID; conflitos de identidade exigem revisão.
- Executar testes em banco local temporário, sem autenticar contas reais nem modificar suas senhas.
- Aplicar somente a migração nova no banco local configurado após os testes.

## Review Focus

- Mesmo login e nomes diferentes entre domínios: IDs distintos, sem herança de equipes/permissões.
- Mesmo nome completo entre domínios e alteração de nome no mesmo GUID: associação estável e sem fusão de contas.
- GUID diferente para um login já cadastrado no mesmo domínio: rejeitar transferência de identidade.
- Dados de diretório ausentes: preservar nome completo e GUID existentes.
- Login de emergência, importação e auditoria: respeitar o domínio também quando o login existe em outra conta.

### Task 1: Persistência e sincronização das identidades

**Files:**
- Create: `database/migrations/028_ad_domain_identity.sql`
- Modify: `database/schema.sql`, `database/seed.sql`, `database/migrate_legacy_data.php`, `scratch/run_migrations.php`, `services/ActiveDirectoryAuthService.php`, `admin/index.php`
- Test: `scratch/test_ad_domain_identity.php`, `scratch/test_batch_directory_ldap.js`

**Interfaces:**
- Consumes: `authenticateIntegrated(string): array`, `provisionDirectoryUser(array, string): array`, `synchronizeUser(...)`, `tryBreakGlassEmergencyLogin(string, string): ?array`.
- Produces: login único por domínio no banco e resolução consistente dessas interfaces; nenhuma alteração nos IDs existentes.

- [x] Escrever e executar regressão em base temporária: mesmo login em BETIM e SAUDE, nomes diferentes, nomes iguais, mudança de nome, GUID conflitante, manutenção de permissões e fallback de emergência.
- [x] Criar índices únicos de login normalizado por domínio AD e de login local; remover `users_username_key` somente após validar os novos índices.
- [x] Ajustar sincronização e importação para consultar o domínio correto, preservar metadados ausentes e rejeitar GUID conflitante.
- [x] Restringir fallback de emergência e logs da ficha do usuário ao domínio correspondente.
- [x] Executar a regressão nova, as regressões pertinentes de login/importação e lint dos arquivos alterados.

### Task 2: Ativação local e registro da validação

**Files:**
- Modify: `docs/TESTES.md`
- Create: `docs/identidades-ad-por-dominio.md`

**Interfaces:**
- Consumes: migração `028_ad_domain_identity.sql`, executada em transação.
- Produces: banco local configurado com login único dentro de cada domínio, sem criar usuários reais de teste.

- [x] Validar idempotência da migração e rejeição de duplicatas dentro do mesmo domínio.
- [x] Aplicar somente a migração 028 no banco local, com timeout de bloqueio e rollback em falha.
- [x] Conferir índices e preservação dos cadastros existentes; registrar resultados e limite da validação sem senha real de SAUDE.

Resultado: 31 verificações aprovadas, incluindo seis autenticações com a extensão LDAP contra servidor simulado e três regressões existentes em base temporária. Migração aplicada ao banco local `docsec`, preservando os 15 usuários, 12 vínculos de equipe e 25 permissões; dados cadastrais antes/depois idênticos. Não houve autenticação de conta real de SAUDE nem publicação em outro servidor.

### Task 3: Um envio de senha e validação somente no domínio selecionado

**Spec adicional:** O usuário confirmou que atualizou a credencial técnica e pediu: selecionar BETIM consulta BETIM; selecionar SAUDE consulta SAUDE; senha válida deve concluir a entrada no primeiro envio. Preservar as correções anteriores e concluir a configuração de SAUDE.

**Files:** `login.php`, `config/active_directory.php`, `services/ActiveDirectoryAuthService.php`, `scratch/test_batch_directory_ldap.js`, `scratch/test_ad_domain_identity.php`, `scratch/test_login_domain_flow.js`, `scratch/login_domain_flow_fixture.php`.

**Interfaces:** `authenticate(string $login, string $password, ?string $selectedDomainKey = null): array` aceita a seleção explícita e rejeita identidade de outro domínio; chamadas existentes com dois argumentos continuam válidas. Login bem-sucedido renova o horário da sessão e descarta o marcador de expiração.

- [x] Reproduzir por HTTP real, em base temporária, o retorno ao login ao autenticar com uma sessão sem usuário e horário antigo.
- [x] Validar a seleção por chave exata, normalizar login qualificado somente se corresponder à seleção e fazer um bind canônico nesse domínio.
- [x] Renovar `last_activity_at` no login e configurar autocomplete para usuário/senha atuais.
- [x] Verificar entrada no primeiro POST em BETIM/SAUDE, sessão nova/antiga, senha errada, preservação da seleção e ausência de troca de domínio.
- [x] Confirmar BETIM com a credencial atualizada e corrigir somente a Base DN de SAUDE com o valor anunciado pelo servidor, preservando credenciais e demais configurações.
- [x] Executar regressões, lint e registrar resultados; manter o sistema disponível se os ajustes não exigirem manutenção.

Monitoramento concluído: `testServerConnection()` informa conectividade, autenticação e leitura separadamente; o painel mantém o servidor acessível quando a conta é rejeitada e somente testa credenciais na atualização manual. Testes por HTTP e Chrome aprovados, incluindo o KPI 2/2 para um servidor com credencial válida e outro com credencial rejeitada. As 33 verificações agregadas e regressões existentes passaram. Nenhuma manutenção foi necessária.
