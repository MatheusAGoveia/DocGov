<?php
/**
 * users/actions/replicate.php
 * Entradas: $pdo, $currentAdminUserId, $canManageDirectory, $adAuthService, $adConfig, $usageAuditService.
 * Estado compartilhado: $adConfig.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// AJAX: REPLICAR USUÁRIOS DO AD PARA A BASE LOCAL DO DOCGOV
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['replicate_ad_users'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'error' => 'A sessão de segurança expirou. Atualize a página e tente novamente.']);
        exit;
    }
    if (!$canManageDirectory) {
        echo json_encode(['success' => false, 'error' => 'Seu usuário não possui acesso à sincronização do diretório.']);
        exit;
    }

    $domainKey = trim((string)($_POST['domain_key'] ?? ''));
    $adAuthService = new ActiveDirectoryAuthService($pdo, $adConfig);
    $result = $adAuthService->replicateAllDirectoryUsers($domainKey !== '' ? $domainKey : null);

    if (!empty($result['success'])) {
        $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
            'action' => 'ad_users_replicated',
            'domain' => $result['domain'] ?? 'ALL',
            'new_users' => $result['replicated_new'] ?? 0,
            'updated_users' => $result['updated'] ?? 0,
        ]);
    }

    echo json_encode($result);
    exit;
}
