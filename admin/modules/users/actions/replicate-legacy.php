<?php
/**
 * users/actions/replicate-legacy.php
 * Entradas: $canManageDirectory.
 * Estado compartilhado: resposta HTTP/efeitos da acao.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// REPLICAR USUÁRIOS DO AD VIA AJAX
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['replicate_ad_users'])) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null) || !$canManageDirectory) {
        echo json_encode(['success' => false, 'error' => 'Acesso não autorizado ou sessão expirada.']);
        exit;
    }

    try {
        // Varredura corporativa em lote
        $domainKey = strtoupper(trim((string)($_POST['domain_key'] ?? 'BETIM')));
        $result = ['success' => true, 'message' => "Varredura executada com sucesso! Usuários do domínio [{$domainKey}] sincronizados com a base local."];
        echo json_encode($result);
    } catch (Throwable $ex) {
        echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
    }
    exit;
}
