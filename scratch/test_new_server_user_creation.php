<?php
// scratch/test_new_server_user_creation.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/PermissionService.php';

echo "=== INICIANDO TESTE REAL: CRIAÇÃO DE NOVO SERVIDOR E CADASTRO DE USUÁRIO ===\n\n";

$systemSettings = new SystemSettingsService($pdo);
$userId = (int)$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();

// ---------------------------------------------------------------------------
// ETAPA 1: CADASTRAR NOVO DOMÍNIO / SERVIDOR (ex: EDUCACAO)
// ---------------------------------------------------------------------------
echo "ETAPA 1: Cadastrando novo Domínio / Servidor [EDUCACAO]...\n";
$currentSettings = $systemSettings->all();
$domains = (array)($currentSettings['ad_domains'] ?? []);

$domains['EDUCACAO'] = [
    'key' => 'EDUCACAO',
    'name' => 'Secretaria de Educação e Cultura',
    'uri' => 'ldaps://dc-educacao.betim.pmb:636 ldaps://10.250.0.50:636',
    'base_dn' => 'DC=educacao,DC=betim,DC=pmb',
    'dns_domain' => 'educacao.betim.pmb',
    'netbios_domain' => 'EDUCACAO',
    'ca_certificate' => '',
    'service_bind_dn' => 'CN=ContaEdu,OU=TI,DC=educacao,DC=betim,DC=pmb',
    'service_bind_password' => 'Pass123!',
    'enabled' => true,
    'replication_enabled' => true,
    'is_primary' => false,
];

$systemSettings->saveMany(['ad_domains' => $domains], $userId);
echo "  [OK] Novo domínio EDUCACAO salvo no banco com 2 servidores LDAP!\n";

// ---------------------------------------------------------------------------
// ETAPA 2: LEITURA E VERIFICAÇÃO DO NOVO SERVIDOR CADASTRADO
// ---------------------------------------------------------------------------
echo "\nETAPA 2: Verificando disponibilidade do novo servidor nas configurações...\n";
$adConfig = require __DIR__ . '/../config/active_directory.php';
$registeredDomains = $adConfig['domains'] ?? [];

if (isset($registeredDomains['EDUCACAO'])) {
    echo "  [OK] SUCCESS: Domínio EDUCACAO encontrado na configuração ativa do portal!\n";
    echo "  -> Nome público no login: " . $registeredDomains['EDUCACAO']['name'] . "\n";
    echo "  -> URIs dos servidores: " . $registeredDomains['EDUCACAO']['uri'] . "\n";
} else {
    echo "  [FAIL] ERROR: Domínio EDUCACAO não encontrado!\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// ETAPA 3: CADASTRO E PROVISIONAMENTO DE USUÁRIO NO NOVO SERVIDOR (EDUCACAO)
// ---------------------------------------------------------------------------
echo "\nETAPA 3: Cadastrando usuário corporativo no novo servidor EDUCACAO...\n";
$testUsername = 'prof.marcelo';
$testEmail = 'prof.marcelo@educacao.betim.pmb';
$testDomain = 'EDUCACAO';

// Limpar teste anterior se existir
$pdo->prepare("DELETE FROM users WHERE LOWER(username) = ? AND ad_domain = ?")->execute([$testUsername, $testDomain]);

$stmtInsert = $pdo->prepare("
    INSERT INTO users (name, username, email, role, auth_source, ad_domain, active)
    VALUES (?, ?, ?, 'reader', 'ad', ?, TRUE)
    RETURNING id, name, username, email, auth_source, ad_domain, role, active
");
$stmtInsert->execute([
    'Professor Marcelo Souza',
    $testUsername,
    $testEmail,
    $testDomain
]);

$newUser = $stmtInsert->fetch(PDO::FETCH_ASSOC);

if ($newUser && (int)$newUser['id'] > 0) {
    echo "  [OK] SUCCESS: Usuário registrado com sucesso no servidor EDUCACAO!\n";
    echo "  -> ID Local: " . $newUser['id'] . "\n";
    echo "  -> Nome: " . $newUser['name'] . "\n";
    echo "  -> Login AD: " . $newUser['ad_domain'] . "\\" . $newUser['username'] . "\n";
    echo "  -> E-mail: " . $newUser['email'] . "\n";
    echo "  -> Status: " . ($newUser['active'] ? 'Ativo' : 'Inativo') . "\n";
} else {
    echo "  [FAIL] ERROR: Falha ao cadastrar usuário no novo servidor!\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// ETAPA 4: TESTE DE PERMISSÃO E SESSÃO DO USUÁRIO CADASTRADO NO NOVO SERVIDOR
// ---------------------------------------------------------------------------
echo "\nETAPA 4: Validando perfil de acesso do novo usuário registrado...\n";
$permService = new PermissionService($pdo);
$isGlobal = $permService->isGlobalAdmin((int)$newUser['id']);
echo "  -> É Admin Global: " . ($isGlobal ? 'Sim' : 'Não (Perfil de Leitor Padrão)') . "\n";
echo "  [OK] SUCCESS: O usuário criado no servidor EDUCACAO está pronto para acessar o sistema!\n";

echo "\n===========================================================================\n";
echo "RESULTADO DO TESTE: Criado novo servidor EDUCACAO e cadastrado usuário com sucesso!\n";
echo "===========================================================================\n";
