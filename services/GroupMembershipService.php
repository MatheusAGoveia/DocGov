<?php

/** Vínculos de equipes e membros efetivos para permissões de conteúdo. */
final class GroupMembershipService
{
    private ?bool $nestingAvailable = null;

    public function __construct(private PDO $pdo) {}

    public function isAvailable(): bool
    {
        if ($this->nestingAvailable === null) {
            $this->nestingAvailable = (bool)$this->pdo->query("SELECT to_regclass('group_memberships') IS NOT NULL")->fetchColumn();
        }
        return $this->nestingAvailable;
    }

    /** Pode ser prefixado a consultas existentes; só percorre equipes ativas. */
    public function effectiveMembershipsCte(?int $userId = null): string
    {
        $filter = $userId === null ? '' : ' AND ug.user_id = ' . (int)$userId;
        $recursive = $this->isAvailable() ? '
            UNION
            SELECT m.user_id, gm.parent_group_id
            FROM effective_user_groups m
            JOIN group_memberships gm ON gm.child_group_id = m.group_id
            JOIN groups parent ON parent.id = gm.parent_group_id AND parent.active = TRUE
        ' : '';
        return "WITH RECURSIVE effective_user_groups(user_id, group_id) AS (
            SELECT ug.user_id, ug.group_id FROM user_groups ug
            JOIN groups g ON g.id = ug.group_id AND g.active = TRUE
            WHERE TRUE {$filter}
            {$recursive}
        ) ";
    }

    public function getActiveGroupIds(int $userId): array
    {
        if ($userId <= 0) return [];
        return array_map('intval', $this->pdo->query($this->effectiveMembershipsCte($userId)
            . 'SELECT group_id FROM effective_user_groups ORDER BY group_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function allMembershipsCte(): string
    {
        return $this->effectiveMembershipsCte() . ', all_user_groups AS (
            SELECT user_id, group_id FROM user_groups
            UNION SELECT user_id, group_id FROM effective_user_groups
        ) ';
    }

    /** Um caminho mínimo e determinístico por equipe, sem enumerar todos os caminhos do grafo. */
    public function getUserGroups(int $userId, bool $includeInactiveDirect = false): array
    {
        if ($userId <= 0) return [];
        $stmt = $this->pdo->prepare($this->effectiveMembershipsCte($userId) . '
            SELECT g.id, g.name, g.description, g.active, ug.created_at AS member_since,
                   (ug.user_id IS NOT NULL) AS is_direct
            FROM groups g
            LEFT JOIN user_groups ug ON ug.group_id = g.id AND ug.user_id = ?
            WHERE g.id IN (SELECT group_id FROM effective_user_groups)
               OR (? AND ug.user_id IS NOT NULL)
            ORDER BY g.name, g.id');
        $stmt->execute([$userId, $includeInactiveDirect ? 'true' : 'false']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        $paths = [];
        $queue = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $byId[$id] = $row;
            if (filter_var($row['is_direct'], FILTER_VALIDATE_BOOLEAN)) {
                $paths[$id] = [$id];
                if (filter_var($row['active'], FILTER_VALIDATE_BOOLEAN)) $queue[] = $id;
            }
        }
        $parents = [];
        if ($this->isAvailable() && $rows !== []) {
            $ids = implode(',', array_keys($byId));
            foreach ($this->pdo->query("SELECT child_group_id, parent_group_id FROM group_memberships
                WHERE child_group_id IN ({$ids}) AND parent_group_id IN ({$ids}) ORDER BY parent_group_id")->fetchAll(PDO::FETCH_ASSOC) as $edge) {
                $parents[(int)$edge['child_group_id']][] = (int)$edge['parent_group_id'];
            }
        }
        for ($i = 0; $i < count($queue); $i++) {
            $child = $queue[$i];
            foreach ($parents[$child] ?? [] as $parent) {
                if (!isset($paths[$parent]) && filter_var($byId[$parent]['active'], FILTER_VALIDATE_BOOLEAN)) {
                    $paths[$parent] = [...$paths[$child], $parent];
                    $queue[] = $parent;
                }
            }
        }
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = filter_var($row['active'], FILTER_VALIDATE_BOOLEAN);
            $row['is_direct'] = filter_var($row['is_direct'], FILTER_VALIDATE_BOOLEAN);
            $row['membership_path'] = array_map(static fn(int $id): array => ['id' => $id, 'name' => $byId[$id]['name']], $paths[$row['id']] ?? []);
            $row['membership_label'] = implode(' → ', array_column($row['membership_path'], 'name'));
        }
        unset($row);
        return $rows;
    }

    public function getEffectiveMembers(int $groupId): array
    {
        $stmt = $this->pdo->prepare($this->effectiveMembershipsCte() . '
            SELECT u.id, u.name, u.username, u.email,
                   EXISTS (SELECT 1 FROM user_groups ug WHERE ug.user_id = u.id AND ug.group_id = ?) AS is_direct
            FROM effective_user_groups m JOIN users u ON u.id = m.user_id AND u.active = TRUE
            WHERE m.group_id = ? ORDER BY u.name, u.id');
        $stmt->execute([$groupId, $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRelations(int $groupId, bool $parents = false): array
    {
        if (!$this->isAvailable()) return [];
        $target = $parents ? 'parent_group_id' : 'child_group_id';
        $source = $parents ? 'child_group_id' : 'parent_group_id';
        $stmt = $this->pdo->prepare("SELECT g.id, g.name, g.description, g.active
            FROM group_memberships gm JOIN groups g ON g.id = gm.{$target}
            WHERE gm.{$source} = ? ORDER BY g.name, g.id");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getChildCounts(): array
    {
        if (!$this->isAvailable()) return [];
        return $this->pdo->query('SELECT parent_group_id, COUNT(*) FROM group_memberships GROUP BY parent_group_id')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function getAvailableChildren(int $groupId): array
    {
        if (!$this->isAvailable()) return [];
        $stmt = $this->pdo->prepare('WITH RECURSIVE ancestors(id) AS (
            SELECT CAST(? AS integer)
            UNION SELECT gm.parent_group_id FROM group_memberships gm JOIN ancestors a ON gm.child_group_id = a.id
        ) SELECT g.id, g.name, g.active FROM groups g
          WHERE g.id NOT IN (SELECT id FROM ancestors)
            AND NOT EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.parent_group_id = ? AND gm.child_group_id = g.id)
          ORDER BY g.name, g.id');
        $stmt->execute([$groupId, $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function changeChild(int $parentId, int $childId, int $actorId, bool $add): bool
    {
        if (!$this->isAvailable()) throw new RuntimeException('A opção de subgrupos ainda não está disponível.');
        if ($parentId <= 0 || $childId <= 0 || $parentId === $childId) {
            throw new InvalidArgumentException('Selecione duas equipes diferentes e existentes.');
        }
        $actor = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND active = TRUE AND role IN ('admin', 'super_admin')");
        $actor->execute([$actorId]);
        if (!$actor->fetchColumn()) throw new RuntimeException('Somente o Super Admin pode gerenciar subgrupos.');
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT group_nesting_change');
        try {
            // A validação posterior usa um snapshot novo em READ COMMITTED.
            $this->pdo->query('SELECT pg_advisory_xact_lock(20261001, 27)');
            $groups = $this->pdo->prepare('SELECT id FROM groups WHERE id IN (?, ?) ORDER BY id FOR KEY SHARE');
            $groups->execute([$parentId, $childId]);
            if (count($groups->fetchAll(PDO::FETCH_COLUMN)) !== 2) throw new InvalidArgumentException('Equipe não encontrada.');
            if ($add) {
                $cycle = $this->pdo->prepare('WITH RECURSIVE ancestors(id) AS (
                    SELECT CAST(? AS integer)
                    UNION SELECT gm.parent_group_id FROM group_memberships gm JOIN ancestors a ON gm.child_group_id = a.id
                ) SELECT 1 FROM ancestors WHERE id = ?');
                $cycle->execute([$parentId, $childId]);
                if ($cycle->fetchColumn()) throw new InvalidArgumentException('Este vínculo formaria um ciclo entre as equipes.');
                $stmt = $this->pdo->prepare('INSERT INTO group_memberships (parent_group_id, child_group_id, created_by)
                    VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
                $stmt->execute([$parentId, $childId, $actorId]);
            } else {
                $stmt = $this->pdo->prepare('DELETE FROM group_memberships WHERE parent_group_id = ? AND child_group_id = ?');
                $stmt->execute([$parentId, $childId]);
            }
            $changed = $stmt->rowCount() === 1;
            if ($changed) {
                $audit = $this->pdo->prepare("INSERT INTO usage_audit_events (user_id, event_type, resource_type, metadata)
                    VALUES (?, 'admin_action', 'ADMIN', CAST(? AS jsonb))");
                $audit->execute([$actorId, json_encode(['action' => $add ? 'team_subgroup_added' : 'team_subgroup_removed',
                    'team_id' => $parentId, 'child_team_id' => $childId], JSON_THROW_ON_ERROR)]);
            }
            if ($ownsTransaction) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT group_nesting_change');
            return $changed;
        } catch (Throwable $exception) {
            if ($ownsTransaction) $this->pdo->rollBack();
            else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT group_nesting_change');
                $this->pdo->exec('RELEASE SAVEPOINT group_nesting_change');
            }
            throw $exception;
        }
    }
}
