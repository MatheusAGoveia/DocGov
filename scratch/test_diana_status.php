<?php
// scratch/test_diana_status.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

echo "=== TESTANDO CONECTIVIDADE E STATUS DO SERVIDOR DIANA (diana.betim.pmb) ===\n\n";

// 1. Resolução DNS
$host = 'diana.betim.pmb';
$ip = gethostbyname($host);
echo "1. Resolução de Nome (DNS):\n";
if ($ip !== $host) {
    echo "   [OK] Host '{$host}' resolvido para o IP: {$ip}\n";
} else {
    echo "   [AVISO] DNS não conseguiu resolver '{$host}'. Tentando conectar via IP diretamente se souber ou falha na resolução.\n";
}

// 2. Teste Socket TCP na porta 636 (LDAPS) e 389 (LDAP)
echo "\n2. Teste de Conexão TCP por Socket:\n";
foreach ([636 => 'LDAPS (SSL/TLS)', 389 => 'LDAP (Padrão)'] as $port => $label) {
    $start = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, 3);
    $latencyMs = (int)round((microtime(true) - $start) * 1000);
    if ($fp) {
        fclose($fp);
        echo "   [OK] Porta {$port} ({$label}) ABERTA e RESPONDENDO em {$latencyMs}ms!\n";
    } else {
        echo "   [ERRO] Porta {$port} ({$label}) INACESSÍVEL: Erro {$errno} - {$errstr}\n";
    }
}

// 3. Teste via ActiveDirectoryAuthService testServerConnection
echo "\n3. Teste Completo via Extension PHP LDAP (testServerConnection):\n";
$adService = new ActiveDirectoryAuthService($pdo);
$res636 = $adService->testServerConnection('ldaps://diana.betim.pmb:636');
echo "   -> LDAPS (636): " . json_encode($res636, JSON_UNESCAPED_UNICODE) . "\n";

$res389 = $adService->testServerConnection('ldap://diana.betim.pmb:389');
echo "   -> LDAP (389): " . json_encode($res389, JSON_UNESCAPED_UNICODE) . "\n";
