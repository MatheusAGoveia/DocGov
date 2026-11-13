<?php
/**
 * documents/actions/batch.php
 * Entradas: $pdo, $currentAdminUserId, $permService, $workflowService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 1.2 AÇÕES EM LOTE: respeitam as mesmas regras e registram cada transição.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['batch_action'])) {
    $batchAction = trim($_POST['batch_action'] ?? '');
    $selectedDocumentIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['selected_docs'] ?? [])),
        static fn (int $documentId): bool => $documentId > 0
    )));
    $workflowActions = [
        'submit_review' => 'submit_review',
        'publish' => 'approve_publish',
        'draft' => 'save_draft',
        'trash' => 'archive',
    ];

    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!isset($workflowActions[$batchAction])) {
        $errorMessage = 'Ação em lote inválida.';
    } elseif (empty($selectedDocumentIds)) {
        $errorMessage = 'Selecione pelo menos um documento.';
    } else {
        try {
            $pdo->beginTransaction();
            $processed = 0;
            $stmtCurrent = $pdo->prepare('SELECT status FROM documents WHERE id = :id FOR UPDATE');
            foreach ($selectedDocumentIds as $selectedDocumentId) {
                if (!$permService->canEditDocument($currentAdminUserId, $selectedDocumentId)) {
                    throw new RuntimeException('Um ou mais documentos selecionados estão fora da sua área autorizada.');
                }
                $stmtCurrent->execute([':id' => $selectedDocumentId]);
                $previousStatus = (string)$stmtCurrent->fetchColumn();
                if ($previousStatus === '') {
                    throw new RuntimeException('Documento selecionado não encontrado.');
                }
                if ($batchAction === 'trash') {
                    $workflowService->moveToTrash($selectedDocumentId, $currentAdminUserId, $previousStatus, 'Movido para a lixeira em ação em lote.');
                } else {
                    $transition = $workflowService->prepareAction($workflowActions[$batchAction], $previousStatus, $currentAdminUserId, $selectedDocumentId);
                    $workflowService->applyStatus($selectedDocumentId, $transition['status']);
                    $workflowService->applyTransitionMetadata($selectedDocumentId, $currentAdminUserId, $transition['action'], 'Ação em lote');
                    $workflowService->record($selectedDocumentId, $currentAdminUserId, $transition['action'], $previousStatus, $transition['status'], 'Ação em lote');
                }
                $processed++;
            }
            $pdo->commit();

            foreach ($selectedDocumentIds as $selectedDocumentId) {
                $workflowService->notifyForTransition($selectedDocumentId, $currentAdminUserId, $workflowActions[$batchAction] === 'submit_review' ? 'submitted_for_review' : ($workflowActions[$batchAction] === 'approve_publish' ? 'approved_and_published' : ''));
                $usageAuditService->logAdminAction($currentAdminUserId, 'batch_' . $workflowActions[$batchAction], 'DOCUMENT', $selectedDocumentId);
            }
            $batchMessage = $batchAction === 'trash' ? 'docs_trashed' : 'docs_workflow_updated';
            $returnFilters = array_intersect_key($_GET, array_flip(['search', 'filter_cat', 'filter_subcat', 'filter_assunto', 'filter_tipo', 'filter_status', 'page']));
            header('Location: index.php?' . http_build_query(array_merge(['tab' => 'documentos'], $returnFilters, ['msg' => $batchMessage, 'count' => $processed])));
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $exception->getMessage();
        }
    }
}
