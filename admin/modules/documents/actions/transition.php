<?php
/**
 * documents/actions/transition.php
 * Entradas: $pdo, $currentAdminUserId, $permService, $workflowService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 1.1 TRANSIÇÃO EDITORIAL RÁPIDA (aprovar ou devolver para ajustes sem abrir o editor)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['workflow_quick_action'])) {
    $documentId = (int)($_POST['document_id'] ?? 0);
    $workflowAction = trim($_POST['workflow_quick_action'] ?? '');
    $workflowNote = trim($_POST['workflow_note'] ?? '');

    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif ($documentId <= 0 || !$permService->canEditDocument($currentAdminUserId, $documentId)) {
        http_response_code(403);
        $errorMessage = 'Você não possui acesso a este documento.';
    } else {
        try {
            $pdo->beginTransaction();
            $currentDocument = $workflowService->lockDocument($documentId, isset($_POST['document_version']) ? (string)$_POST['document_version'] : null);
            if (!$permService->canEditDocument($currentAdminUserId, $documentId)) throw new RuntimeException('Você não possui acesso a este documento.', 403);
            $previousStatus = (string)$currentDocument['status'];
            $transition = $workflowService->prepareAction($workflowAction, $previousStatus, $currentAdminUserId, $documentId, $workflowNote);
            $workflowService->applyStatus($documentId, $transition['status']);
            $workflowService->applyTransitionMetadata($documentId, $currentAdminUserId, $transition['action'], $transition['note']);
            $workflowService->record($documentId, $currentAdminUserId, $transition['action'], $previousStatus, $transition['status'], $transition['note']);
            $pdo->commit();
            $workflowService->notifyAfterCommit($documentId, $currentAdminUserId, $transition['action']);
            $usageAuditService->logAdminAction($currentAdminUserId, 'workflow_' . $transition['action'], 'DOCUMENT', $documentId);
            header('Location: index.php?tab=detalhes_documento&id=' . $documentId . '&msg=workflow_updated');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof DocumentConflictException) http_response_code(409);
            $errorMessage = $exception->getMessage();
        }
    }
}
