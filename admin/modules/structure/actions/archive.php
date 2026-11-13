<?php
/**
 * structure/actions/archive.php
 * Entradas: $currentAdminUserId, $structureArchiveService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['structure_action'])) {
    $structureType = strtolower(trim((string)($_POST['structure_type'] ?? '')));
    $structureId = (int)($_POST['structure_id'] ?? 0);
    $structureAction = strtolower(trim((string)$_POST['structure_action']));
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!in_array($structureAction, ['discard', 'restore'], true)) {
        http_response_code(400);
        $errorMessage = 'Ação da estrutura inválida.';
    } else {
        try {
            $result = $structureArchiveService->setActive($structureType, $structureId, $currentAdminUserId, $structureAction === 'restore');
            if ($result['changed']) {
                $usageAuditService->logAdminAction($currentAdminUserId, $structureType . '_' . $structureAction, strtoupper($structureType), $structureId);
            }
            header('Location: index.php?tab=editar_estrutura&view=' . ($structureAction === 'restore' ? 'discarded' : 'active') . '&msg=structure_' . $structureAction . 'ed');
            exit;
        } catch (Throwable $exception) {
            http_response_code($exception instanceof InvalidArgumentException ? 400 : 403);
            $errorMessage = $exception->getMessage();
        }
    }
}
