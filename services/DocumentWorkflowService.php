<?php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/PermissionService.php';

/** Mantém as transições editoriais, a auditoria e os avisos aos responsáveis. */
class DocumentWorkflowService {
    public function __construct(private PDO $pdo, private PermissionService $permissionService) {}

    public static function label(string $status): string {
        return match (strtolower($status)) {
            '' => 'Início',
            'draft' => 'Rascunho',
            'review' => 'Em revisão',
            'published' => 'Publicado',
            'inactive' => 'Inativo',
            default => 'Desconhecido',
        };
    }

    public static function actionLabel(string $action): string {
        return match (strtolower($action)) {
            'saved_as_draft' => 'salvou como rascunho',
            'submitted_for_review' => 'enviou para revisão',
            'reviewed' => 'concluiu a revisão',
            'approved_and_published' => 'aprovou e publicou',
            'changes_requested' => 'recusou e devolveu para ajustes',
            'expired_unapproved' => 'teve a aprovação expirada',
            'archived' => 'arquivou',
            'restored_from_trash' => 'restaurou da lixeira',
            default => 'atualizou o fluxo',
        };
    }

    public function canApprove(int $userId, int $documentId): bool {
        return $this->permissionService->isGlobalAdmin($userId)
            || $this->permissionService->canAdminDocument($userId, $documentId);
    }

    /** Revisão e aprovação são atribuições administrativas; editores apenas submetem conteúdo. */
    public function canReview(int $userId, int $documentId): bool {
        return $this->canApprove($userId, $documentId);
    }

    /** @return array{status:string, action:string, note:string} */
    public function prepareAction(string $requestedAction, ?string $previousStatus, int $userId, ?int $documentId, string $note = ''): array {
        $action = strtolower(trim($requestedAction));
        $previousStatus = strtolower(trim((string)$previousStatus));
        $note = mb_substr(trim($note), 0, 2000);

        return match ($action) {
            'save_draft' => $this->prepareDraft($previousStatus, $note),
            'submit_review' => $this->prepareReview($previousStatus, $note),
            'review_document' => $this->prepareReviewConclusion($previousStatus, $userId, $documentId, $note),
            'approve_publish' => $this->prepareApproval($previousStatus, $userId, $documentId, $note),
            'request_changes' => $this->prepareChanges($previousStatus, $userId, $documentId, $note),
            'archive' => ['status' => 'inactive', 'action' => 'archived', 'note' => $note],
            default => throw new InvalidArgumentException('Ação editorial inválida.'),
        };
    }

    private function prepareReview(string $previousStatus, string $note): array {
        if ($previousStatus === 'review') {
            throw new InvalidArgumentException('Este documento já está em revisão.');
        }
        if (!in_array($previousStatus, ['', 'draft', 'published'], true)) {
            throw new InvalidArgumentException('Este documento não pode ser enviado para revisão no estado atual.');
        }
        return ['status' => 'review', 'action' => 'submitted_for_review', 'note' => $note];
    }

    private function prepareDraft(string $previousStatus, string $note): array {
        if ($previousStatus === 'inactive') {
            throw new InvalidArgumentException('Este documento está arquivado ou expirado e não pode voltar ao fluxo editorial.');
        }
        return ['status' => 'draft', 'action' => 'saved_as_draft', 'note' => $note];
    }

    private function prepareApproval(string $previousStatus, int $userId, ?int $documentId, string $note): array {
        if (!$documentId || $previousStatus !== 'review') {
            throw new InvalidArgumentException('Somente documentos em revisão podem ser publicados.');
        }
        if (!$this->canApprove($userId, $documentId)) {
            throw new RuntimeException('Apenas o Administrador da categoria pode aprovar esta publicação.');
        }
        if (!$this->hasCompletedReview($documentId)) {
            throw new InvalidArgumentException('Conclua a revisão antes de aprovar a publicação.');
        }
        return ['status' => 'published', 'action' => 'approved_and_published', 'note' => $note];
    }

    private function prepareReviewConclusion(string $previousStatus, int $userId, ?int $documentId, string $note): array {
        if (!$documentId || $previousStatus !== 'review') {
            throw new InvalidArgumentException('Somente documentos em revisão podem ser revisados.');
        }
        if (!$this->canReview($userId, $documentId)) {
            throw new RuntimeException('Apenas o Administrador da categoria pode revisar este documento.');
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new InvalidArgumentException('Informe um parecer de revisão com ao menos 3 caracteres.');
        }
        return ['status' => 'review', 'action' => 'reviewed', 'note' => $note];
    }

    private function prepareChanges(string $previousStatus, int $userId, ?int $documentId, string $note): array {
        if (!$documentId || $previousStatus !== 'review') {
            throw new InvalidArgumentException('Somente documentos em revisão podem retornar para ajustes.');
        }
        if (!$this->canApprove($userId, $documentId)) {
            throw new RuntimeException('Apenas o Administrador da categoria pode solicitar ajustes.');
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new InvalidArgumentException('Informe o motivo da recusa com ao menos 3 caracteres.');
        }
        return ['status' => 'draft', 'action' => 'changes_requested', 'note' => $note];
    }

    private function hasCompletedReview(int $documentId): bool {
        $stmt = $this->pdo->prepare('SELECT reviewed_by IS NOT NULL FROM documents WHERE id = :id');
        $stmt->execute([':id' => $documentId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Persiste o estado sem provocar inferência ambígua text/varchar no PostgreSQL. */
    public function applyStatus(int $documentId, string $status): void {
        $status = strtolower(trim($status));
        if (!in_array($status, ['draft', 'review', 'published', 'inactive'], true)) {
            throw new InvalidArgumentException('Estado editorial inválido.');
        }

        $publishedAtSql = match ($status) {
            'published' => 'COALESCE(published_at, CURRENT_TIMESTAMP)',
            'draft', 'review' => 'NULL',
            default => 'published_at',
        };

        $stmt = $this->pdo->prepare("
            UPDATE documents
            SET status = CAST(:new_status AS VARCHAR(20)),
                published_at = {$publishedAtSql},
                approval_expires_at = CASE
                    WHEN CAST(:is_published AS BOOLEAN) THEN NULL
                    ELSE COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL '1 month')
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :document_id
        ");
        $stmt->execute([
            ':new_status' => $status,
            ':is_published' => $status === 'published' ? 1 : 0,
            ':document_id' => $documentId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Documento selecionado não encontrado.');
        }
    }

    /** Envia um documento à lixeira e preserva o estado para uma restauração fiel. */
    public function moveToTrash(int $documentId, int $actorId, ?string $previousStatus = null, string $note = ''): void {
        $previousStatus = strtolower(trim((string)$previousStatus));
        if (!in_array($previousStatus, ['draft', 'review', 'published'], true)) {
            throw new InvalidArgumentException('Somente documentos ativos no fluxo editorial podem ser enviados à lixeira.');
        }

        $stmt = $this->pdo->prepare("\n            UPDATE documents\n            SET status = 'inactive',\n                trashed_at = CURRENT_TIMESTAMP,\n                trashed_by = :actor_id,\n                trashed_from_status = CAST(:previous_status AS VARCHAR(20)),\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = :document_id\n        ");
        $stmt->execute([
            ':actor_id' => $actorId,
            ':previous_status' => $previousStatus,
            ':document_id' => $documentId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Documento selecionado não encontrado.');
        }
        $this->record($documentId, $actorId, 'archived', $previousStatus, 'inactive', $note);
    }

    /** Restaura apenas itens que foram efetivamente enviados à lixeira. */
    public function restoreFromTrash(int $documentId, int $actorId): string {
        $document = $this->pdo->prepare('SELECT status, trashed_at, trashed_from_status FROM documents WHERE id = :id FOR UPDATE');
        $document->execute([':id' => $documentId]);
        $row = $document->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Documento não encontrado.');
        }
        if ((string)$row['status'] !== 'inactive' || empty($row['trashed_at'])) {
            throw new InvalidArgumentException('Este item não está na lixeira ou foi inativado por outro motivo.');
        }

        $restoreStatus = strtolower((string)$row['trashed_from_status']);
        if (!in_array($restoreStatus, ['draft', 'review', 'published'], true)) {
            $restoreStatus = 'draft';
        }
        $stmt = $this->pdo->prepare("\n            UPDATE documents\n            SET status = CAST(:restore_status AS VARCHAR(20)),\n                trashed_at = NULL,\n                trashed_by = NULL,\n                trashed_from_status = NULL,\n                approval_expires_at = CASE\n                    WHEN CAST(:is_published AS BOOLEAN) THEN NULL\n                    ELSE CURRENT_TIMESTAMP + INTERVAL '1 month'\n                END,\n                updated_at = CURRENT_TIMESTAMP\n            WHERE id = :id\n        ");
        $stmt->execute([
            ':restore_status' => $restoreStatus,
            ':is_published' => $restoreStatus === 'published' ? 1 : 0,
            ':id' => $documentId,
        ]);
        $this->record($documentId, $actorId, 'restored_from_trash', 'inactive', $restoreStatus, 'Restaurado da lixeira.');
        return $restoreStatus;
    }

    /** Atualiza os responsáveis do fluxo depois de uma transição já validada. */
    public function applyTransitionMetadata(int $documentId, int $actorId, string $action, string $note = ''): void {
        $action = strtolower(trim($action));
        $actorId = $actorId > 0 ? $actorId : null;

        $sql = match ($action) {
            'submitted_for_review' => '
                UPDATE documents
                SET reviewed_by = NULL, reviewed_at = NULL,
                    approved_by = NULL, approved_at = NULL,
                    rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL,
                    approval_expires_at = COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL \'1 month\')
                WHERE id = :id',
            'reviewed' => '
                UPDATE documents
                SET reviewed_by = :actor_id, reviewed_at = CURRENT_TIMESTAMP
                WHERE id = :id',
            'approved_and_published' => '
                UPDATE documents
                SET approved_by = :actor_id, approved_at = CURRENT_TIMESTAMP,
                    rejection_reason = NULL, rejected_by = NULL, rejected_at = NULL,
                    approval_expires_at = NULL
                WHERE id = :id',
            'changes_requested' => '
                UPDATE documents
                SET rejected_by = :actor_id, rejected_at = CURRENT_TIMESTAMP,
                    rejection_reason = :note
                WHERE id = :id',
            default => null,
        };

        if ($sql === null) {
            return;
        }
        $stmt = $this->pdo->prepare($sql);
        $params = [':id' => $documentId];
        if (str_contains($sql, ':actor_id')) {
            $params[':actor_id'] = $actorId;
        }
        if (str_contains($sql, ':note')) {
            $params[':note'] = trim($note);
        }
        $stmt->execute($params);
    }

    /**
     * Expiração preguiçosa e idempotente: é executada em cada requisição da aplicação
     * e pode também ser chamada pelo agendador do servidor. Nunca expira publicações.
     */
    public function expireUnapprovedDocuments(): int {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query("
                WITH expiring AS (
                    SELECT id, status
                    FROM documents
                    WHERE status IN ('draft', 'review')
                      AND approval_expires_at IS NOT NULL
                      AND approval_expires_at <= CURRENT_TIMESTAMP
                    FOR UPDATE
                ), expired AS (
                    UPDATE documents d
                    SET status = 'inactive', published_at = NULL, updated_at = CURRENT_TIMESTAMP
                    FROM expiring e
                    WHERE d.id = e.id
                    RETURNING d.id
                )
                SELECT e.id, e.status
                FROM expiring e
                JOIN expired x ON x.id = e.id
            ");
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($documents) {
                $history = $this->pdo->prepare('
                    INSERT INTO document_workflow_history (document_id, actor_id, action, previous_status, new_status, note)
                    VALUES (:document_id, NULL, :action, :previous_status, :new_status, :note)
                ');
                foreach ($documents as $document) {
                    $history->execute([
                        ':document_id' => (int)$document['id'],
                        ':action' => 'expired_unapproved',
                        ':previous_status' => $document['status'],
                        ':new_status' => 'inactive',
                        ':note' => 'Prazo de 1 mês para aprovação encerrado automaticamente.',
                    ]);
                }
            }
            $this->pdo->commit();
            return count($documents);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function record(int $documentId, int $actorId, string $action, ?string $previousStatus, string $newStatus, string $note = ''): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO document_workflow_history (document_id, actor_id, action, previous_status, new_status, note)
            VALUES (:document_id, :actor_id, :action, :previous_status, :new_status, :note)
        ');
        $stmt->execute([
            ':document_id' => $documentId,
            ':actor_id' => $actorId > 0 ? $actorId : null,
            ':action' => $action,
            ':previous_status' => $previousStatus ?: null,
            ':new_status' => $newStatus,
            ':note' => trim($note) !== '' ? trim($note) : null,
        ]);
    }

    public function notifyForTransition(int $documentId, int $actorId, string $action): void {
        $notificationService = new NotificationService($this->pdo);
        if (!$notificationService->isAvailable()) {
            return;
        }

        $stmt = $this->pdo->prepare('
            SELECT d.id, d.title, d.created_by
            FROM documents d
            WHERE d.id = :id
        ');
        $stmt->execute([':id' => $documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            return;
        }

        if ($action === 'submitted_for_review') {
            $reviewers = [];
            $candidateIds = $this->pdo->query('SELECT id FROM users WHERE active = TRUE')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($candidateIds as $candidateId) {
                if ($this->canApprove((int)$candidateId, $documentId)) {
                    $reviewers[] = (int)$candidateId;
                }
            }
            $notificationService->createForUsers(
                $reviewers,
                'document_review_requested',
                'Documento aguardando revisão',
                '“' . $document['title'] . '” foi enviado para sua aprovação.',
                $documentId,
                $actorId
            );
            return;
        }

        $authorId = (int)($document['created_by'] ?? 0);
        if ($action === 'approved_and_published') {
            $notificationService->createForUsers(
                [$authorId],
                'document_published',
                'Publicação aprovada',
                '“' . $document['title'] . '” foi aprovada e já está disponível no portal.',
                $documentId,
                $actorId
            );
        } elseif ($action === 'reviewed') {
            $notificationService->createForUsers(
                [$authorId],
                'document_reviewed',
                'Revisão concluída',
                '“' . $document['title'] . '” foi revisado e aguarda a aprovação final.',
                $documentId,
                $actorId
            );
        } elseif ($action === 'changes_requested') {
            $notificationService->createForUsers(
                [$authorId],
                'document_changes_requested',
                'Ajustes solicitados',
                '“' . $document['title'] . '” retornou para rascunho para receber ajustes.',
                $documentId,
                $actorId
            );
        }
    }

    public function history(int $documentId): array {
        $stmt = $this->pdo->prepare('
            SELECT h.id, h.action, h.previous_status, h.new_status, h.note, h.created_at,
                   u.name AS actor_name, u.username AS actor_username
            FROM document_workflow_history h
            LEFT JOIN users u ON u.id = h.actor_id
            WHERE h.document_id = :document_id
            ORDER BY h.created_at DESC, h.id DESC
        ');
        $stmt->execute([':document_id' => $documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
