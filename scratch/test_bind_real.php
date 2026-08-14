<?php
// scratch/test_bind_real.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

$adAuthService = new ActiveDirectoryAuthService($pdo);

echo "=== TESTANDO CONEXÃO REAL COM SERVIDORES VALIDANDO BIND E RESPOSTA REDE ===\n\n";

// Teste A: IP Fictício ou Inacessível (Deve retornar ERRO REAL de rede/timeout)
echo "TESTE A: Servidor Inexistente (ldaps://192.168.254.254:636)...\n";
$resA = $adAuthService->testServerConnection('ldaps://192.168.254.254:636');
echo "  -> Resultado: " . json_encode($resA, JSON_UNESCAPED_UNICODE) . "\n";

// Teste B: Servidor com Conta Técnica Inválida (Deve retornar ERRO REAL de credenciais)
echo "\nTESTE B: Servidor Betim com Conta Técnica Inválida...\n";
$resB = $adAuthService->testServerConnection('ldaps://diana.betim.pmb:636', '', 'CN=UsuarioInexistente,DC=betim,DC=pmb', 'senha_errada_123');
echo "  -> Resultado: " . json_encode($resB, JSON_UNESCAPED_UNICODE) . "\n";

// Teste C: Servidor Betim com Comunicação de Rede Real
echo "\nTESTE C: Servidor Betim Real (ldaps://diana.betim.pmb:636)...\n";
$resC = $adAuthService->testServerConnection('ldaps://diana.betim.pmb:636');
echo "  -> Resultado: " . json_encode($resC, JSON_UNESCAPED_UNICODE) . "\n";
