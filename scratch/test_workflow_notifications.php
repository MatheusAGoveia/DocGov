<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';
require_once __DIR__ . '/../services/NotificationService.php';

$adminId = (int)$pdo->query("SELECT id FROM users WHERE active = TRUE AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$subjectId = (int)$pdo->query('SELECT id FROM subjects ORDER BY id LIMIT 1')->fetchColumn();
if ($adminId <= 0 || $subjectId <= 0) {
    throw new RuntimeException('É necessário um Super Admin e um assunto para testar o fluxo.');
}

$permissionService = new PermissionService($pdo);
$workflow = new DocumentWorkflowService($pdo, $permissionService);
$notifications = new NotificationService($pdo);
if (!$notifications->isAvailable()) {
    throw new RuntimeException('Tabela de notificações não foi criada.');
}

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $stmt = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, description, content_type, status, text_content) VALUES (?, ?, ?, ?, '', 'text', 'draft', 'Teste transitório') RETURNING id");
    $stmt->execute([$subjectId, $adminId, 'Teste de fluxo ' . $suffix, 'teste-fluxo-' . $suffix]);
    $documentId = (int)$stmt->fetchColumn();
    $notifications->create($adminId, 'test', 'Teste de notificação', 'Registro transitório de validação.', $documentId);

    $review = $workflow->prepareAction('submit_review', 'draft', $adminId, $documentId, 'Pronto para revisão');
    $workflow->applyStatus($documentId, $review['status']);
    $workflow->applyTransitionMetadata($documentId, $adminId, $review['action'], $review['note']);
    $workflow->record($documentId, $adminId, $review['action'], 'draft', $review['status'], $review['note']);
    $workflow->notifyForTransition($documentId, $adminId, $review['action']);

    $reviewConclusion = $workflow->prepareAction('review_document', 'review', $adminId, $documentId, 'Conteúdo conferido e pronto para decisão.');
    $workflow->applyStatus($documentId, $reviewConclusion['status']);
    $workflow->applyTransitionMetadata($documentId, $adminId, $reviewConclusion['action'], $reviewConclusion['note']);
    $workflow->record($documentId, $adminId, $reviewConclusion['action'], 'review', $reviewConclusion['status'], $reviewConclusion['note']);
    $workflow->notifyForTransition($documentId, $adminId, $reviewConclusion['action']);

    $approval = $workflow->prepareAction('approve_publish', 'review', $adminId, $documentId, 'Aprovado no teste');
    $workflow->applyStatus($documentId, $approval['status']);
    $workflow->applyTransitionMetadata($documentId, $adminId, $approval['action'], $approval['note']);
    $workflow->record($documentId, $adminId, $approval['action'], 'review', $approval['status'], $approval['note']);
    $workflow->notifyForTransition($documentId, $adminId, $approval['action']);

    $historyCount = (int)$pdo->query("SELECT COUNT(*) FROM document_workflow_history WHERE document_id = $documentId")->fetchColumn();
    $finalStatus = (string)$pdo->query("SELECT status FROM documents WHERE id = $documentId")->fetchColumn();
    $notificationCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE document_id = $documentId")->fetchColumn();
    if ($historyCount !== 3 || $finalStatus !== 'published' || $notificationCount < 1) {
        throw new RuntimeException('Transições não foram persistidas corretamente no teste.');
    }

    $pdo->rollBack();
    echo "[OK] Fluxo draft -> review -> revisão concluída -> published, histórico e notificações validado em transação revertida.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
