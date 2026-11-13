<?php
/**
 * authentication/actions/health.php
 * Entradas: $canManageAuthentication, $adAuthService, $adConfig.
 * Estado compartilhado: $adConfig.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// HEALTH CHECK VIVO DOS SERVIDORES AD VIA AJAX
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['test_ad_health'])) {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        if (!CsrfService::isValid($_POST['csrf_token'] ?? null) || !$canManageAuthentication) {
            echo json_encode(['success' => false, 'error' => 'Sessão expirada ou sem permissão de administrador global.']);
            exit;
        }

        $adConfig = require DOCGOV_PROJECT_ROOT . '/config/active_directory.php';
        $allDomains = (array)($currentSystemSettings['ad_domains'] ?? []);
        if (empty($allDomains)) {
            $allDomains = (array)($adConfig['domains'] ?? []);
        }

        $healthResults = [];
        // Atualização automática verifica rede/protocolo. Credenciais são
        // verificadas somente quando o administrador solicita manualmente.
        $validateAccounts = !empty($_POST['validate_ad_accounts']);
        session_write_close();

        foreach ($allDomains as $dKey => $dData) {
            $uriRaw = trim((string)($dData['uri'] ?? ''));
            $servers = preg_split('/\s+/', $uriRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (empty($servers)) {
                continue;
            }
            foreach ($servers as $idx => $sUri) {
                $start = microtime(true);
                $testRes = $adAuthService->testServerConnection(
                    $sUri,
                    (string)($dData['ca_certificate'] ?? ''),
                    $validateAccounts ? (string)($dData['service_bind_dn'] ?? '') : '',
                    $validateAccounts ? (string)($dData['service_bind_password'] ?? '') : '',
                    $validateAccounts ? (string)($dData['base_dn'] ?? '') : ''
                );
                $latMs = (int)round((microtime(true) - $start) * 1000);
                $healthResults[] = [
                    'domain' => $dKey,
                    'name' => ($idx === 0) ? "DC1 Principal ({$dKey})" : "DC" . ($idx + 1) . " Réplica ({$dKey})",
                    'uri' => $sUri,
                    'online' => !empty($testRes['ldap_available']),
                    'status' => $testRes['status'] ?? 'configuration_error',
                    'authenticated' => $testRes['authenticated'] ?? null,
                    'directory_readable' => $testRes['directory_readable'] ?? null,
                    'account_configured' => !empty($dData['service_bind_dn']) && (string)($dData['service_bind_password'] ?? '') !== '',
                    'latency_ms' => $latMs,
                    'message' => $testRes['message'] ?? ($testRes['error'] ?? 'Indisponível')
                ];
            }
        }

        echo json_encode(['success' => true, 'health' => $healthResults]);
        exit;
    } catch (Throwable $ex) {
        echo json_encode(['success' => false, 'error' => 'Falha ao consultar saúde: ' . $ex->getMessage()]);
        exit;
    }
}
