<?php

/**
 * Autoriza módulos administrativos globais sem misturar essas permissões com
 * os acessos hierárquicos de categoria, subcategoria e assunto.
 *
 * Somente o Super Admin pode alterar as capacidades de uma equipe. Membros
 * ativos de equipes ativas recebem apenas as capacidades explicitamente
 * cadastradas. A gestão das próprias equipes permanece exclusiva do Super
 * Admin para impedir escalada de privilégio.
 */
final class SystemAccessService
{
    public const SETTINGS_MANAGE = 'system.settings.manage';
    public const AUTHENTICATION_MANAGE = 'system.authentication.manage';
    public const DIRECTORY_MANAGE = 'system.directory.manage';
    public const AUDIT_VIEW = 'system.audit.view';
    public const TAGS_MANAGE = 'system.tags.manage';

    private const CAPABILITIES = [
        self::SETTINGS_MANAGE => [
            'label' => 'Configurações gerais',
            'description' => 'Altera identidade visual, tema, sessão, CORS e janelas de manutenção.',
            'sensitivity' => 'critical',
        ],
        self::AUTHENTICATION_MANAGE => [
            'label' => 'Autenticação e Active Directory',
            'description' => 'Configura domínios, servidores LDAP, SSO e consulta a auditoria de autenticação.',
            'sensitivity' => 'critical',
        ],
        self::DIRECTORY_MANAGE => [
            'label' => 'Diretório de usuários',
            'description' => 'Visualiza todo o diretório e importa contas corporativas do Active Directory.',
            'sensitivity' => 'high',
        ],
        self::AUDIT_VIEW => [
            'label' => 'Auditoria global',
            'description' => 'Visualiza métricas, acessos, downloads e ações administrativas de toda a plataforma.',
            'sensitivity' => 'high',
        ],
        self::TAGS_MANAGE => [
            'label' => 'Catálogo de tags',
            'description' => 'Cria, corrige, ativa e organiza tags e sinônimos globais.',
            'sensitivity' => 'standard',
        ],
    ];

    public function __construct(
        private PDO $pdo,
        private PermissionService $permissionService
    ) {
    }

    public static function catalog(): array
    {
        return self::CAPABILITIES;
    }

    public static function isKnownCapability(string $capability): bool
    {
        return array_key_exists(strtolower(trim($capability)), self::CAPABILITIES);
    }

    public function hasCapability(?int $userId, string $capability): bool
    {
        $userId = (int)($userId ?? 0);
        $capability = strtolower(trim($capability));
        if ($userId <= 0 || !self::isKnownCapability($capability) || !$this->isActiveUser($userId)) {
            return false;
        }

        if ($this->permissionService->isGlobalAdmin($userId)) {
            return true;
        }

        $stmt = $this->pdo->prepare('
            SELECT 1
            FROM group_system_capabilities gsc
            JOIN groups g ON g.id = gsc.group_id AND g.active = TRUE
            JOIN user_groups ug ON ug.group_id = g.id
            WHERE ug.user_id = :user_id
              AND gsc.capability = :capability
            LIMIT 1
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':capability' => $capability,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function hasAnyCapability(?int $userId): bool
    {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || !$this->isActiveUser($userId)) {
            return false;
        }
        if ($this->permissionService->isGlobalAdmin($userId)) {
            return true;
        }

        $stmt = $this->pdo->prepare('
            SELECT 1
            FROM group_system_capabilities gsc
            JOIN groups g ON g.id = gsc.group_id AND g.active = TRUE
            JOIN user_groups ug ON ug.group_id = g.id
            WHERE ug.user_id = :user_id
            LIMIT 1
        ');
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function getUserCapabilities(?int $userId): array
    {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || !$this->isActiveUser($userId)) {
            return [];
        }
        if ($this->permissionService->isGlobalAdmin($userId)) {
            return array_keys(self::CAPABILITIES);
        }

        $stmt = $this->pdo->prepare('
            SELECT DISTINCT gsc.capability
            FROM group_system_capabilities gsc
            JOIN groups g ON g.id = gsc.group_id AND g.active = TRUE
            JOIN user_groups ug ON ug.group_id = g.id
            WHERE ug.user_id = :user_id
            ORDER BY gsc.capability
        ');
        $stmt->execute([':user_id' => $userId]);
        return array_values(array_filter(
            array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            [self::class, 'isKnownCapability']
        ));
    }

    public function getGroupCapabilities(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }
        $stmt = $this->pdo->prepare('
            SELECT capability
            FROM group_system_capabilities
            WHERE group_id = :group_id
            ORDER BY capability
        ');
        $stmt->execute([':group_id' => $groupId]);
        return array_values(array_filter(
            array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            [self::class, 'isKnownCapability']
        ));
    }

    public function syncGroupCapabilities(int $groupId, array $requestedCapabilities, int $actorUserId): array
    {
        if (!$this->permissionService->isGlobalAdmin($actorUserId) || !$this->isActiveUser($actorUserId)) {
            throw new RuntimeException('Somente o Super Admin pode alterar acessos administrativos do sistema.');
        }
        if ($groupId <= 0) {
            throw new InvalidArgumentException('Equipe inválida.');
        }

        $requested = array_values(array_unique(array_map(
            static fn (mixed $value): string => strtolower(trim((string)$value)),
            $requestedCapabilities
        )));
        foreach ($requested as $capability) {
            if (!self::isKnownCapability($capability)) {
                throw new InvalidArgumentException('Capacidade administrativa inválida.');
            }
        }
        sort($requested);

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $groupStmt = $this->pdo->prepare('SELECT name FROM groups WHERE id = :id FOR UPDATE');
            $groupStmt->execute([':id' => $groupId]);
            $groupName = $groupStmt->fetchColumn();
            if ($groupName === false) {
                throw new InvalidArgumentException('Equipe não encontrada.');
            }

            $current = $this->getGroupCapabilities($groupId);
            $granted = array_values(array_diff($requested, $current));
            $revoked = array_values(array_diff($current, $requested));

            if ($granted !== []) {
                $insert = $this->pdo->prepare('
                    INSERT INTO group_system_capabilities (group_id, capability, granted_by)
                    VALUES (:group_id, :capability, :granted_by)
                    ON CONFLICT (group_id, capability) DO NOTHING
                ');
                foreach ($granted as $capability) {
                    $insert->execute([
                        ':group_id' => $groupId,
                        ':capability' => $capability,
                        ':granted_by' => $actorUserId,
                    ]);
                    $this->writeAudit($actorUserId, $groupId, (string)$groupName, $capability, 'GRANTED');
                }
            }

            if ($revoked !== []) {
                $delete = $this->pdo->prepare('
                    DELETE FROM group_system_capabilities
                    WHERE group_id = :group_id AND capability = :capability
                ');
                foreach ($revoked as $capability) {
                    $delete->execute([
                        ':group_id' => $groupId,
                        ':capability' => $capability,
                    ]);
                    $this->writeAudit($actorUserId, $groupId, (string)$groupName, $capability, 'REVOKED');
                }
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return ['granted' => $granted, 'revoked' => $revoked, 'current' => $requested];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function isActiveUser(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id AND active = TRUE');
        $stmt->execute([':id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function writeAudit(
        int $actorUserId,
        int $groupId,
        string $groupName,
        string $capability,
        string $action
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO system_capability_audit
                (actor_id, group_id, group_name, capability, action, ip_address)
            VALUES
                (:actor_id, :group_id, :group_name, :capability, :action, :ip_address)
        ');
        $stmt->execute([
            ':actor_id' => $actorUserId,
            ':group_id' => $groupId,
            ':group_name' => $groupName,
            ':capability' => $capability,
            ':action' => $action,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
