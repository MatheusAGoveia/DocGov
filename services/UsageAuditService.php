<?php

/**
 * Registra, de forma centralizada, os eventos de uso do portal e do painel.
 * Não armazena senhas, tokens nem o texto pesquisado pelos usuários.
 */
final class UsageAuditService
{
    private const EVENT_TYPES = [
        'login',
        'portal_view',
        'search',
        'category_view',
        'subcategory_view',
        'subject_view',
        'document_view',
        'document_file_view',
        'document_download',
        'external_open',
        'admin_page_view',
        'admin_action',
    ];

    private const RESOURCE_TYPES = [
        'PORTAL',
        'CATEGORY',
        'SUBCATEGORY',
        'SUBJECT',
        'DOCUMENT',
        'ADMIN',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function log(
        string $eventType,
        ?int $userId,
        ?string $resourceType = null,
        ?int $resourceId = null,
        array $metadata = []
    ): void {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            return;
        }

        if ($resourceType !== null && !in_array($resourceType, self::RESOURCE_TYPES, true)) {
            return;
        }

        try {
            $metadata['route'] = $this->currentRoute();
            $payload = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $stmt = $this->pdo->prepare('
                INSERT INTO usage_audit_events
                    (user_id, event_type, resource_type, resource_id, metadata, ip_address, user_agent)
                VALUES
                    (:user_id, :event_type, :resource_type, :resource_id, CAST(:metadata AS jsonb), :ip_address, :user_agent)
            ');
            $stmt->execute([
                ':user_id' => $userId && $userId > 0 ? $userId : null,
                ':event_type' => $eventType,
                ':resource_type' => $resourceType,
                ':resource_id' => $resourceId && $resourceId > 0 ? $resourceId : null,
                ':metadata' => $payload,
                ':ip_address' => $this->requestIpAddress(),
                ':user_agent' => $this->requestUserAgent(),
            ]);
        } catch (Throwable $exception) {
            // A auditoria nunca pode impedir o acesso ou uma publicação válida.
            error_log('DocGov auditoria de uso: ' . $exception->getMessage());
        }
    }

    public function logAdminAction(int $userId, string $action, ?string $resourceType = null, ?int $resourceId = null): void
    {
        $this->log('admin_action', $userId, $resourceType, $resourceId, ['action' => $action]);
    }

    private function currentRoute(): string
    {
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($requestUri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? mb_substr($path, 0, 255) : '';
    }

    private function requestIpAddress(): ?string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function requestUserAgent(): ?string
    {
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null;
    }
}
