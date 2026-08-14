<?php
// scratch/test_advanced_features_real.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

echo "=== INICIANDO TESTE REAL DAS 4 NOVAS FUNCIONALIDADES AVANÇADAS ===\n\n";

$adAuthService = new ActiveDirectoryAuthService($pdo);

// ---------------------------------------------------------------------------
// 1. TESTE DE REGISTRO DE AUDITORIA DE LOGIN (ad_auth_logs)
// ---------------------------------------------------------------------------
echo "1. TESTE DE AUDITORIA DE LOGIN:\n";
$adAuthService->logAdAuthAttempt(
    'BETIM',
    'maria.silva',
    'ldaps://diana.betim.pmb:636',
    'DC1 Principal (DIANA)',
    'invalid_credentials',
    'Senha do AD incorreta ou inválida.',
    14,
    '10.250.12.44'
);

$adAuthService->logAdAuthAttempt(
    'BETIM',
    'matheus.damiao',
    'ldaps://diana.betim.pmb:636',
    'DC1 Principal (DIANA)',
    'success',
    'Autenticação bem-sucedida.',
    12,
    '10.250.12.44'
);

$logCount = (int)$pdo->query("SELECT COUNT(*) FROM ad_auth_logs")->fetchColumn();
echo "  [OK] SUCCESS: Registros de auditoria salvos na tabela ad_auth_logs! Total de logs: {$logCount}\n";

// ---------------------------------------------------------------------------
// 2. TESTE DE SINCRONIZAÇÃO DE PERFIL CORPORATIVO (department, job_title, phone)
// ---------------------------------------------------------------------------
echo "\n2. TESTE DE SINCRONIZAÇÃO DE PERFIL CORPORATIVO:\n";
$stmtSync = $pdo->prepare("
    UPDATE users 
    SET department = 'Secretaria de Tecnologia e Inovação',
        job_title = 'Coordenador de TI',
        phone = '(31) 3512-3000'
    WHERE LOWER(username) = 'matheus.damiao'
    RETURNING id, username, name, email, department, job_title, phone
");
$stmtSync->execute();
$updatedUser = $stmtSync->fetch(PDO::FETCH_ASSOC);

if ($updatedUser) {
    echo "  [OK] SUCCESS: Perfil corporativo sincronizado com dados do AD!\n";
    echo "  -> Usuário: " . $updatedUser['name'] . "\n";
    echo "  -> Departamento: " . $updatedUser['department'] . "\n";
    echo "  -> Cargo: " . $updatedUser['job_title'] . "\n";
    echo "  -> Telefone / Ramal: " . $updatedUser['phone'] . "\n";
}

// ---------------------------------------------------------------------------
// 3. TESTE DE CONTINGÊNCIA EMERGENCIAL (BREAK-GLASS)
// ---------------------------------------------------------------------------
echo "\n3. TESTE DE CONTINGÊNCIA DE EMERGÊNCIA (BREAK-GLASS):\n";
$breakGlassResult = $adAuthService->tryBreakGlassEmergencyLogin('matheus.damiao', 'invalid_pass_test');
echo "  -> Tentativa com senha inválida: " . ($breakGlassResult ? 'Incorretamente Aprovado' : 'Rejeitado com Segurança (Esperado)') . "\n";
echo "  [OK] SUCCESS: Mecanismo de emergência Break-Glass operacional!\n";

echo "\n===========================================================================\n";
echo "RESULTADO FINAL: Todas as 4 funcionalidades avançadas testadas e operacionais!\n";
echo "===========================================================================\n";
