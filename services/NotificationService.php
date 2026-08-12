<?php

/**
 * Centraliza as notificações internas do DocGov.
 * Falhas de notificação nunca devem impedir a operação principal do sistema.
 */
class NotificationService {
    public function __construct(private PDO $pdo) {}

    public function isAvailable(): bool {
        return (bool)$this->pdo->query("SELECT to_regclass('public.notifications') IS NOT NULL")->fetchColumn();
    }

    public function create(int $userId, string $type, string $title, string $body = '', ?int $documentId = null): void {
        if ($userId <= 0 || !$this->isAvailable()) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, document_id) VALUES (:user_id, :type, :title, :body, :document_id)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => substr(trim($type), 0, 50),
            ':title' => mb_substr(trim($title), 0, 255),
            ':body' => trim($body) !== '' ? trim($body) : null,
            ':document_id' => $documentId ?: null,
        ]);
    }

    public function createForUsers(array $userIds, string $type, string $title, string $body = '', ?int $documentId = null, int $excludeUserId = 0): void {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId > 0 && $userId !== $excludeUserId) {
                $this->create($userId, $type, $title, $body, $documentId);
            }
        }
    }

    public function unreadCount(int $userId): int {
        if ($userId <= 0 || !$this->isAvailable()) {
            return 0;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function listForUser(int $userId, int $limit = 60): array {
        if ($userId <= 0 || !$this->isAvailable()) {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT n.id, n.type, n.title, n.body, n.document_id, n.read_at, n.created_at, d.title AS document_title
            FROM notifications n
            LEFT JOIN documents d ON d.id = n.document_id
            WHERE n.user_id = :user_id
            ORDER BY n.created_at DESC, n.id DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markRead(int $notificationId, int $userId): void {
        if ($notificationId <= 0 || $userId <= 0 || !$this->isAvailable()) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    }

    public function markAllRead(int $userId): void {
        if ($userId <= 0 || !$this->isAvailable()) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND read_at IS NULL');
        $stmt->execute([':user_id' => $userId]);
    }
}
