<?php
// scratch/test_real_ad_logic.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/PermissionService.php';

echo "=== INICIANDO BATERIA DE TESTES REAIS DE LOGICA DE SERVIDORES E DOMINIOS ===\n\n";

$systemSettings = new SystemSettingsService($pdo);
$permService = new PermissionService($pdo);
$adAuthService = new ActiveDirectoryAuthService($pdo);

$testPass = 0;
$testFail = 0;

function assertTest(bool $condition, string $description) {
    global $testPass, $testFail;
    if ($condition) {
        echo "  [OK] SUCCESS: {$description}\n";
        $testPass++;
    } else {
        echo "  [FAIL] ERROR: {$description}\n";
        $testFail++;
    }
}

// ---------------------------------------------------------------------------
// TESTE 1: LEITURA E PERSISTÊNCIA DE DOMÍNIOS COM MÚLTIPLOS SERVIDORES
// ---------------------------------------------------------------------------
echo "TESTE 1: Leitura de Domínios e Servidores no Banco de Dados...\n";
$allSettings = $systemSettings->all();
$domains = $allSettings['ad_domains'] ?? [];

assertTest(!empty($domains), "Tabela de configurações retornou domínios corporativos.");
assertTest(isset($domains['BETIM']), "Domínio BETIM está presente nas configurações.");
assertTest(isset($domains['SAUDE']), "Domínio SAUDE está presente nas configurações.");

$betimUri = $domains['BETIM']['uri'] ?? '';
echo "  -> URIs do Domínio BETIM: [{$betimUri}]\n";

// ---------------------------------------------------------------------------
// TESTE 2: TESTE DE COMUNICAÇÃO DE SERVIDOR LDAP INDIVIDUAL
// ---------------------------------------------------------------------------
echo "\nTESTE 2: Teste Real de Conexão com Servidor LDAP (ldaps://diana.betim.pmb:636)...\n";
$testRes1 = $adAuthService->testServerConnection('ldaps://diana.betim.pmb:636');
echo "  -> Resposta do Servidor 1: " . json_encode($testRes1, JSON_UNESCAPED_UNICODE) . "\n";
assertTest(isset($testRes1['success']), "O manipulador de teste de servidor retornou resposta estruturada.");

// ---------------------------------------------------------------------------
// TESTE 3: VERIFICAÇÃO DE SUPER ADMINS EM QUALQUER HIPÓTESE
// ---------------------------------------------------------------------------
echo "\nTESTE 3: Permissão de Super Admin Incondicional...\n";

// Buscar ID do matheus.damiao
$stmtMatheus = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = 'matheus.damiao' OR LOWER(email) LIKE '%matheus.damiao%' LIMIT 1");
$stmtMatheus->execute();
$matheusId = (int)$stmtMatheus->fetchColumn();

// Buscar ID do marcuss
$stmtMarcuss = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = 'marcuss' OR LOWER(email) LIKE '%marcus_aurelio%' LIMIT 1");
$stmtMarcuss->execute();
$marcussId = (int)$stmtMarcuss->fetchColumn();

if ($matheusId > 0) {
    $isMatheusAdmin = $permService->isGlobalAdmin($matheusId);
    assertTest($isMatheusAdmin, "Usuário matheus.damiao (ID: {$matheusId}) é identificado como Super Admin.");
} else {
    echo "  [WARN] matheus.damiao não encontrado na tabela users local.\n";
}

if ($marcussId > 0) {
    $isMarcussAdmin = $permService->isGlobalAdmin($marcussId);
    assertTest($isMarcussAdmin, "Usuário marcuss (ID: {$marcussId}) é identificado como Super Admin.");
} else {
    echo "  [WARN] marcuss não encontrado na tabela users local.\n";
}

// ---------------------------------------------------------------------------
// TESTE 4: TESTE DE RESOLUÇÃO DE DOMÍNIOS PARA LOGIN
// ---------------------------------------------------------------------------
echo "\nTESTE 4: Resolução de Domínios para Tela de Login público...\n";
$adConfig = require __DIR__ . '/../config/active_directory.php';
$activeDomainsMap = [];
foreach ($adConfig['domains'] ?? [] as $dKey => $dVal) {
    if (!isset($dVal['enabled']) || $dVal['enabled']) {
        $activeDomainsMap[$dKey] = !empty($dVal['name']) ? $dVal['name'] : $dKey;
    }
}
echo "  -> Domínios ativos no Login: " . json_encode($activeDomainsMap, JSON_UNESCAPED_UNICODE) . "\n";
assertTest(count($activeDomainsMap) >= 2, "A tela de login possui pelo menos 2 domínios institucionais habilitados.");

echo "\n===========================================================================\n";
echo "RESULTADO FINAL: {$testPass} Testes Aprovados, {$testFail} Falhas.\n";
echo "===========================================================================\n";
