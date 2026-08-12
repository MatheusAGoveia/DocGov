<?php
// Validação manual do fluxo completo, sempre removendo os registros de teste.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';

function approvalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$adminId = (int)$pdo->query("SELECT id FROM users WHERE active = TRUE AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$subjectId = (int)$pdo->query('SELECT id FROM subjects WHERE active = TRUE ORDER BY id LIMIT 1')->fetchColumn();
approvalAssert($adminId > 0 && $subjectId > 0, 'É necessário um Super Admin e um assunto ativo.');

$workflow = new DocumentWorkflowService($pdo, new PermissionService($pdo));
$createdIds = [];
try {
    $suffix = bin2hex(random_bytes(5));
    $insert = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, content_type, status, text_content) VALUES (?, ?, ?, ?, 'text', 'draft', 'Validação temporária') RETURNING id");
    $insert->execute([$subjectId, $adminId, 'Teste aprovação ' . $suffix, 'teste-aprovacao-' . $suffix]);
    $documentId = (int)$insert->fetchColumn();
    $createdIds[] = $documentId;

    $submit = $workflow->prepareAction('submit_review', 'draft', $adminId, $documentId, 'Pronto para revisão.');
    $workflow->applyStatus($documentId, $submit['status']);
    $workflow->applyTransitionMetadata($documentId, $adminId, $submit['action'], $submit['note']);
    $workflow->record($documentId, $adminId, $submit['action'], 'draft', 'review', $submit['note']);

    try {
        $workflow->prepareAction('approve_publish', 'review', $adminId, $documentId);
        throw new RuntimeException('Aprovação sem revisão foi aceita.');
    } catch (InvalidArgumentException $expected) {
        approvalAssert(str_contains($expected->getMessage(), 'Conclua a revisão'), 'A regra de revisão prévia não retornou a mensagem esperada.');
    }

    try {
        $workflow->prepareAction('request_changes', 'review', $adminId, $documentId, '');
        throw new RuntimeException('Recusa sem motivo foi aceita.');
    } catch (InvalidArgumentException $expected) {
        approvalAssert(str_contains($expected->getMessage(), 'motivo da recusa'), 'A recusa sem motivo não foi bloqueada.');
    }

    $review = $workflow->prepareAction('review_document', 'review', $adminId, $documentId, 'Conteúdo revisado e consistente.');
    $workflow->applyStatus($documentId, $review['status']);
    $workflow->applyTransitionMetadata($documentId, $adminId, $review['action'], $review['note']);
    $workflow->record($documentId, $adminId, $review['action'], 'review', 'review', $review['note']);

    $approval = $workflow->prepareAction('approve_publish', 'review', $adminId, $documentId, 'Aprovado.');
    $workflow->applyStatus($documentId, $approval['status']);
    $workflow->applyTransitionMetadata($documentId, $adminId, $approval['action'], $approval['note']);
    $workflow->record($documentId, $adminId, $approval['action'], 'review', 'published', $approval['note']);
    $state = $pdo->query("SELECT status, reviewed_by, approved_by, approval_expires_at FROM documents WHERE id = {$documentId}")->fetch(PDO::FETCH_ASSOC);
    approvalAssert($state['status'] === 'published' && (int)$state['reviewed_by'] === $adminId && (int)$state['approved_by'] === $adminId && $state['approval_expires_at'] === null, 'Responsáveis ou prazo da aprovação não foram persistidos.');

    $insert->execute([$subjectId, $adminId, 'Teste expiração ' . $suffix, 'teste-expiracao-' . $suffix]);
    $expiredDocumentId = (int)$insert->fetchColumn();
    $createdIds[] = $expiredDocumentId;
    $pdo->exec("UPDATE documents SET approval_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = {$expiredDocumentId}");
    $workflow->expireUnapprovedDocuments();
    $expired = $pdo->query("SELECT status FROM documents WHERE id = {$expiredDocumentId}")->fetchColumn();
    $expiryHistory = (int)$pdo->query("SELECT COUNT(*) FROM document_workflow_history WHERE document_id = {$expiredDocumentId} AND action = 'expired_unapproved'")->fetchColumn();
    approvalAssert($expired === 'inactive' && $expiryHistory === 1, 'A expiração não inativou ou não registrou o documento.');
    try {
        $workflow->prepareAction('save_draft', 'inactive', $adminId, $expiredDocumentId);
        throw new RuntimeException('Documento expirado foi reativado pelo fluxo editorial.');
    } catch (InvalidArgumentException $expected) {
        approvalAssert(str_contains($expected->getMessage(), 'expirado'), 'A proteção contra reativação de expirado falhou.');
    }

    echo "[OK] Revisão obrigatória, motivo de recusa, responsáveis e expiração foram validados.\n";
} finally {
    foreach ($createdIds as $id) {
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
    }
}
