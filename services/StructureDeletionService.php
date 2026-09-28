<?php

declare(strict_types=1);

require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/DocumentInlineMediaService.php';

/** Exclusão definitiva de ramos descartados, respeitando os vínculos da árvore. */
final class StructureDeletionService
{
    private const TABLES = [
        'category' => 'categories',
        'subcategory' => 'subcategories',
        'subject' => 'subjects',
    ];

    public function __construct(private PDO $pdo, private PermissionService $permissions)
    {
    }

    /** @return array{name:string,type:string,id:int,subcategories:int,subjects:int,documents:int,published_documents:int} */
    public function preview(string $type, int $id, int $actorId): array
    {
        $this->assertAllowed($type, $id, $actorId);
        $branch = $this->collect($type, $id, false);
        return $this->summary($type, $id, $branch);
    }

    /**
     * O banco é removido em uma transação. Caminhos devolvidos só devem ser
     * apagados do disco depois do commit, com validação do diretório protegido.
     *
     * @return array{summary:array<string,mixed>,document_files:array<int,string>,image_files:array<int,string>,inline_media_files:array<int,string>}
     */
    public function deletePermanently(string $type, int $id, int $actorId, string $confirmedName): array
    {
        $this->assertAllowed($type, $id, $actorId);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT structure_permanent_delete');
        }
        try {
            $branch = $this->collect($type, $id, true);
            $name = (string)$branch['target']['name'];
            if (!hash_equals($name, trim($confirmedName))) {
                throw new InvalidArgumentException('Digite exatamente o nome do item para confirmar a exclusão definitiva.');
            }

            $summary = $this->summary($type, $id, $branch);
            $documentIds = array_map('intval', array_column($branch['documents'], 'id'));
            $inlineMediaFiles = (new DocumentInlineMediaService($this->pdo, dirname(__DIR__)))->pathsForDocuments($documentIds);
            $subjectIds = array_map('intval', array_column($branch['subjects'], 'id'));
            $subcategoryIds = array_map('intval', array_column($branch['subcategories'], 'id'));
            $this->deleteIds('documents', $documentIds);
            $this->deleteIds('subjects', $subjectIds);
            $this->deleteIds('subcategories', $subcategoryIds);
            if ($type === 'category') $this->deleteIds('categories', [$id]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT structure_permanent_delete');
            }
            $imageFiles = array_column($branch['subcategories'], 'image_path');
            if ($type === 'category') $imageFiles[] = $branch['target']['image_path'] ?? null;
            return [
                'summary' => $summary,
                'document_files' => $this->nonEmptyPaths(array_column($branch['documents'], 'file_path')),
                'image_files' => $this->nonEmptyPaths($imageFiles),
                'inline_media_files' => $inlineMediaFiles,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT structure_permanent_delete');
                $this->pdo->exec('RELEASE SAVEPOINT structure_permanent_delete');
            }
            throw $exception;
        }
    }

    private function assertAllowed(string $type, int $id, int $actorId): void
    {
        if (!isset(self::TABLES[$type]) || $id <= 0) {
            throw new InvalidArgumentException('Item da estrutura inválido.');
        }
        if (!$this->permissions->isGlobalAdmin($actorId)) {
            throw new RuntimeException('Somente Super Admins podem excluir itens da estrutura permanentemente.');
        }
    }

    /** @return array{target:array<string,mixed>,subcategories:array<int,array<string,mixed>>,subjects:array<int,array<string,mixed>>,documents:array<int,array<string,mixed>>} */
    private function collect(string $type, int $id, bool $lock): array
    {
        $table = self::TABLES[$type];
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $targetStmt = $this->pdo->prepare("SELECT id, name, active" . ($type === 'category' || $type === 'subcategory' ? ', image_path' : '') . " FROM {$table} WHERE id = :id{$lockSql}");
        $targetStmt->execute([':id' => $id]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) throw new RuntimeException('Item descartado não encontrado.');
        if (filter_var($target['active'], FILTER_VALIDATE_BOOLEAN)) {
            throw new InvalidArgumentException('Descarte o item antes de excluí-lo permanentemente.');
        }

        if ($type === 'category') {
            $subcategoryStmt = $this->pdo->prepare("SELECT id, image_path FROM subcategories WHERE category_id = :id ORDER BY id{$lockSql}");
            $subcategoryStmt->execute([':id' => $id]);
            $subcategories = $subcategoryStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($type === 'subcategory') {
            $subcategories = [['id' => $id, 'image_path' => $target['image_path'] ?? null]];
        } else {
            $subcategories = [];
        }

        $subcategoryIds = array_map('intval', array_column($subcategories, 'id'));
        if ($type === 'subject') {
            $subjects = [['id' => $id]];
        } elseif ($subcategoryIds !== []) {
            $subjects = $this->selectDescendants('subjects', 'subcategory_id', $subcategoryIds, 'id', $lock);
        } else {
            $subjects = [];
        }

        $subjectIds = array_map('intval', array_column($subjects, 'id'));
        $documents = $subjectIds !== []
            ? $this->selectDescendants('documents', 'subject_id', $subjectIds, 'id, file_path, status', $lock)
            : [];
        return compact('target', 'subcategories', 'subjects', 'documents');
    }

    /** @param array<int,int> $ids @return array<int,array<string,mixed>> */
    private function selectDescendants(string $table, string $foreignKey, array $ids, string $columns, bool $lock): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT {$columns} FROM {$table} WHERE {$foreignKey} IN ({$placeholders}) ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int,int> $ids */
    private function deleteIds(string $table, array $ids): void
    {
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        if ($stmt->rowCount() !== count($ids)) {
            throw new RuntimeException('A estrutura mudou durante a exclusão. Nenhum item foi removido.');
        }
    }

    /** @param array<string,mixed> $branch @return array{name:string,type:string,id:int,subcategories:int,subjects:int,documents:int,published_documents:int} */
    private function summary(string $type, int $id, array $branch): array
    {
        return [
            'name' => (string)$branch['target']['name'],
            'type' => $type,
            'id' => $id,
            'subcategories' => $type === 'subject' ? 0 : count($branch['subcategories']) - ($type === 'subcategory' ? 1 : 0),
            'subjects' => $type === 'subject' ? 0 : count($branch['subjects']),
            'documents' => count($branch['documents']),
            'published_documents' => count(array_filter($branch['documents'], static fn(array $doc): bool => ($doc['status'] ?? '') === 'published')),
        ];
    }

    /** @param array<int,mixed> $paths @return array<int,string> */
    private function nonEmptyPaths(array $paths): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($path): string => trim((string)$path),
            $paths
        ), static fn(string $path): bool => $path !== '')));
    }
}
