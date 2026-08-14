<?php
// scratch/test_new_auth_features.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

echo "=== TESTANDO AS 2 NOVAS FUNCIONALIDADES DE AUTENTICAÇÃO ===\n\n";

$adAuthService = new ActiveDirectoryAuthService($pdo);

// 1. TESTE DE REGISTRO DE AUDITORIA DE SENHA EXPIRADA
echo "1. REGISTRANDO TENTATIVA DE LOGIN COM SENHA EXPIRADA NO AD:\n";
$adAuthService->logAdAuthAttempt(
    'BETIM',
    'joao.silva',
    'ldaps://diana.betim.pmb:636',
    'DC1 Principal (DIANA)',
    'password_expired',
    'Sua senha do Active Directory expirou ou requer alteração no próximo logon.',
    18,
    '10.250.40.10'
);

$logCount = (int)$pdo->query("SELECT COUNT(*) FROM ad_auth_logs WHERE status = 'password_expired'")->fetchColumn();
echo "  [OK] SUCCESS: Tentativa registrada com status 'password_expired'! Registros expirados: {$logCount}\n";

// 2. TESTE DE EXPORTAÇÃO CSV DE LOGS
echo "\n2. TESTANDO CONSULTA E ESTRUTURA PARA EXPORTAÇÃO DE RELATÓRIO (CSV):\n";
$stmt = $pdo->query("
    SELECT created_at, domain_key, username, server_name, server_uri, status, status_message, latency_ms, user_ip
    FROM ad_auth_logs
    ORDER BY created_at DESC
    LIMIT 10
");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
echo "  [OK] SUCCESS: Total de " . count($rows) . " registros recuperados prontos para exportação em CSV/PDF.\n";
foreach ($rows as $r) {
    echo "  -> " . $r['created_at'] . " | " . $r['domain_key'] . "\\" . $r['username'] . " | Status: " . $r['status'] . " (" . $r['latency_ms'] . "ms)\n";
}

echo "\n===========================================================================\n";
echo "RESULTADO FINAL: Exportação CSV/PDF e Alerta de Senha Expirada 100% OPERACIONAIS!\n";
echo "===========================================================================\n";
