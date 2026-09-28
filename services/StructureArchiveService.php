<?php

declare(strict_types=1);

require_once __DIR__ . '/PermissionService.php';

/** Desativa ou restaura um nó da árvore sem apagar descendentes e vínculos. */
final class StructureArchiveService
{
    private const TABLES = [
        'category' => 'categories',
        'subcategory' => 'subcategories',
        'subject' => 'subjects',
    ];

    public function __construct(private PDO $pdo, private PermissionService $permissions)
    {
    }

    /** @return array{id:int,type:string,name:string,active:bool,changed:bool} */
    public function setActive(string $type, int $id, int $actorId, bool $active): array
    {
        $type = strtolower(trim($type));
        $table = self::TABLES[$type] ?? null;
        if ($table === null || $id <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('Item da estrutura inválido.');
        }
        if ($type === 'category' && !$this->permissions->isGlobalAdmin($actorId)) {
            throw new RuntimeException('Somente Super Admins podem descartar ou restaurar categorias.');
        }
        if (!$this->permissions->canAdmin($actorId, $type, $id)) {
            throw new RuntimeException('Somente um administrador deste item pode descartá-lo ou restaurá-lo.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $rowStmt = $this->pdo->prepare("SELECT id, name, active FROM {$table} WHERE id = :id FOR UPDATE");
            $rowStmt->execute([':id' => $id]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Item da estrutura não encontrado.');

            $wasActive = filter_var($row['active'], FILTER_VALIDATE_BOOLEAN);
            if ($active && !$wasActive && !$this->hasActiveParents($type, $id)) {
                throw new RuntimeException('Restaure primeiro a categoria ou subcategoria superior.');
            }

            $changed = $wasActive !== $active;
            if ($changed) {
                $update = $this->pdo->prepare("UPDATE {$table} SET active = :active WHERE id = :id");
                $update->bindValue(':active', $active, PDO::PARAM_BOOL);
                $update->bindValue(':id', $id, PDO::PARAM_INT);
                $update->execute();
            }
            if ($ownsTransaction) $this->pdo->commit();
            return [
                'id' => $id,
                'type' => $type,
                'name' => (string)$row['name'],
                'active' => $active,
                'changed' => $changed,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function hasActiveParents(string $type, int $id): bool
    {
        if ($type === 'category') return true;
        if ($type === 'subcategory') {
            $stmt = $this->pdo->prepare('SELECT 1 FROM subcategories sc JOIN categories c ON c.id = sc.category_id WHERE sc.id = :id AND c.active = TRUE');
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id JOIN categories c ON c.id = sc.category_id WHERE s.id = :id AND sc.active = TRUE AND c.active = TRUE');
        }
        $stmt->execute([':id' => $id]);
        return (bool)$stmt->fetchColumn();
    }
}
