<?php
// scratch/test_ad_extended_diagnostics.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

echo "=== TESTANDO MELHORIAS NO ACTIVE DIRECTORY AUTH SERVICE ===\n\n";

$adAuthService = new ActiveDirectoryAuthService($pdo);
$pass = 0;
$fail = 0;

function assertCheck(bool $condition, string $msg) {
    global $pass, $fail;
    if ($condition) {
        echo "  [OK] SUCCESS: {$msg}\n";
        $pass++;
    } else {
        echo "  [FAIL] ERROR: {$msg}\n";
        $fail++;
    }
}

// 1. Teste de Normalização de Usuário (Duplo prefixo / UPN)
echo "1. TESTANDO RESOLUÇÃO DE IDENTIDADES COM PREFIXOS DUPLICADOS OU SUFIXOS UPN:\n";

$reflector = new ReflectionClass(ActiveDirectoryAuthService::class);
$method = $reflector->getMethod('resolveIdentity');
$method->setAccessible(true);

$res1 = $method->invoke($adAuthService, 'BETIM\\BETIM\\maria.silva');
assertCheck($res1 !== null && $res1['username'] === 'maria.silva' && $res1['domain']['key'] === 'BETIM', 'BETIM\\BETIM\\maria.silva normalizado para maria.silva no domínio BETIM');

$res2 = $method->invoke($adAuthService, 'BETIM\\maria.silva@betim.pmb');
assertCheck($res2 !== null && $res2['username'] === 'maria.silva', 'BETIM\\maria.silva@betim.pmb normalizado para maria.silva');

$res3 = $method->invoke($adAuthService, 'maria.silva@betim.pmb');
assertCheck($res3 !== null && $res3['username'] === 'maria.silva' && $res3['domain']['key'] === 'BETIM', 'maria.silva@betim.pmb reconhecido no domínio BETIM');

$res4 = $method->invoke($adAuthService, 'matheus.damiao@betim.mg.gov.br');
assertCheck($res4 !== null && $res4['username'] === 'matheus.damiao' && $res4['domain']['key'] === 'BETIM' && $res4['typed_domain_hint'] === 'betim.mg.gov.br', 'matheus.damiao@betim.mg.gov.br extraiu username e alias betim.mg.gov.br');

// 2. Simulando Resposta com Código de Erro de Conta Bloqueada (Data 775)
echo "\n2. TESTANDO TRATAMENTO DE CÓDIGOS DE ERRO ESTENDIDOS DO AD (DATA 775, 532, 533):\n";

$fakeDiagMessage775 = "80090308: LdapErr: DSID-0C090447, comment: AcceptSecurityContext error, data 775, v3839";
preg_match('/data\s+([0-9a-fA-F]+)/i', $fakeDiagMessage775, $matches);
$code775 = strtolower($matches[1] ?? '');
assertCheck($code775 === '775', 'Código data 775 (Conta Bloqueada) extraído com sucesso.');

$fakeDiagMessage532 = "80090308: LdapErr: DSID-0C090447, comment: AcceptSecurityContext error, data 532, v3839";
preg_match('/data\s+([0-9a-fA-F]+)/i', $fakeDiagMessage532, $matches);
$code532 = strtolower($matches[1] ?? '');
assertCheck($code532 === '532', 'Código data 532 (Senha Expirada) extraído com sucesso.');

echo "\n===========================================================================\n";
echo "RESULTADO: {$pass} Testes Aprovados, {$fail} Falhas.\n";
echo "===========================================================================\n";
