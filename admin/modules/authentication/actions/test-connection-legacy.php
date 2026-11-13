<?php
/**
 * authentication/actions/test-connection-legacy.php
 * Entradas: $canManageAuthentication, $adAuthService.
 * Estado compartilhado: resposta HTTP/efeitos da acao.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// TESTAR CONEXÃO E AUTENTICAÇÃO DO SERVIDOR LDAP VIA AJAX
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['test_ad_connection'])) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null) || !$canManageAuthentication) {
        echo json_encode(['success' => false, 'error' => 'Acesso não autorizado ou sessão expirada.']);
        exit;
    }
    $uri = trim((string)($_POST['test_uri'] ?? ''));
    $caCert = trim((string)($_POST['test_ca_cert'] ?? ''));
    $bindDn = trim((string)($_POST['test_bind_dn'] ?? ''));
    $bindPass = (string)($_POST['test_bind_pass'] ?? '');

    $result = $adAuthService->testServerConnection($uri, $caCert, $bindDn, $bindPass);
    echo json_encode($result);
    exit;
}
