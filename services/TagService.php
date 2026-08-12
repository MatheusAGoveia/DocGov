<?php

/** Catálogo de tags e vínculos transversais entre documentos. */
final class TagService
{
    public const TYPES = [
        'topic' => 'Tema',
        'technology' => 'Tecnologia',
        'asset' => 'Ativo',
        'process' => 'Processo',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int, array{id:int,name:string,type:string,type_label:string,aliases:array<int,string>}> */
    public function allActive(): array
    {
        $stmt = $this->pdo->query("\n            SELECT t.id, t.name, t.tag_type,\n                   COALESCE(array_agg(ta.alias ORDER BY ta.alias) FILTER (WHERE ta.id IS NOT NULL), '{}') AS aliases\n            FROM tags t\n            LEFT JOIN tag_aliases ta ON ta.tag_id = t.id\n            WHERE t.active = TRUE\n            GROUP BY t.id, t.name, t.tag_type\n            ORDER BY t.name ASC\n        ");
        return array_map(fn(array $tag): array => $this->formatTag($tag), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<int, array{id:int,name:string,type:string,type_label:string,aliases:array<int,string>,active:bool,document_count:int,created_by_name:?string}> */
    public function allWithDetails(): array
    {
        $stmt = $this->pdo->query("\n            SELECT t.id, t.name, t.tag_type, t.active, u.name AS created_by_name,\n                   COUNT(DISTINCT dt.document_id) AS document_count,\n                   COALESCE(array_agg(DISTINCT ta.alias ORDER BY ta.alias) FILTER (WHERE ta.id IS NOT NULL), '{}') AS aliases\n            FROM tags t\n            LEFT JOIN users u ON u.id = t.created_by\n            LEFT JOIN document_tags dt ON dt.tag_id = t.id\n            LEFT JOIN tag_aliases ta ON ta.tag_id = t.id\n            GROUP BY t.id, t.name, t.tag_type, t.active, u.name\n            ORDER BY t.active DESC, t.name ASC\n        ");
        return array_map(fn(array $tag): array => $this->formatTag($tag), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<int, int> */
    public function getDocumentTagIds(int $documentId): array
    {
        $stmt = $this->pdo->prepare('SELECT tag_id FROM document_tags WHERE document_id = :document_id ORDER BY tag_id');
        $stmt->execute([':document_id' => $documentId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<int, array{id:int,name:string,type:string,type_label:string}> */
    public function getDocumentTags(int $documentId): array
    {
        $stmt = $this->pdo->prepare("\n            SELECT t.id, t.name, t.tag_type\n            FROM document_tags dt\n            JOIN tags t ON t.id = dt.tag_id\n            WHERE dt.document_id = :document_id\n            ORDER BY t.name ASC\n        ");
        $stmt->execute([':document_id' => $documentId]);
        return array_map(fn(array $tag): array => $this->formatTag($tag), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<int, int> $documentIds @return array<int, array<int, array{id:int,name:string,type:string,type_label:string}>> */
    public function mapDocumentTags(array $documentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("\n            SELECT dt.document_id, t.id, t.name, t.tag_type\n            FROM document_tags dt\n            JOIN tags t ON t.id = dt.tag_id AND t.active = TRUE\n            WHERE dt.document_id IN ($placeholders)\n            ORDER BY t.name ASC\n        ");
        $stmt->execute($ids);
        $mapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $mapped[(int)$row['document_id']][] = $this->formatTag($row);
        }
        return $mapped;
    }

    /** @param array<int, int> $tagIds @return array<int, int> */
    public function assertActiveIds(array $tagIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn(int $id): bool => $id > 0)));
        if (count($ids) > 12) throw new InvalidArgumentException('Use no máximo 12 tags por conteúdo.');
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM tags WHERE active = TRUE AND id IN ($placeholders)");
        $stmt->execute($ids);
        $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($valid); $expected = $ids; sort($expected);
        if ($valid !== $expected) throw new InvalidArgumentException('Uma ou mais tags não estão disponíveis. Atualize a página e tente novamente.');
        return $ids;
    }

    /** @param array<int, int> $tagIds @param array<int, string> $newNames @return array<int, int> */
    public function resolveForDocument(array $tagIds, array $newNames, int $actorId): array
    {
        $ids = $this->assertActiveIds($tagIds);
        $names = $this->assertNewNames($newNames, count($ids));
        foreach ($names as $name) $ids[] = $this->findOrCreate($name, $actorId);
        return $this->assertActiveIds($ids);
    }

    /** @param array<int, string> $newNames @return array<int, string> */
    public function assertNewNames(array $newNames, int $existingCount = 0): array
    {
        $names = array_values(array_unique(array_filter(array_map(static fn($name): string => trim((string)$name), $newNames))));
        if (count($names) > 6) throw new InvalidArgumentException('Crie no máximo 6 novas tags por conteúdo.');
        if ($existingCount + count($names) > 12) throw new InvalidArgumentException('Use no máximo 12 tags por conteúdo.');
        foreach ($names as $name) {
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) throw new InvalidArgumentException('Cada nova tag deve ter entre 2 e 80 caracteres.');
        }
        return $names;
    }

    /** @param array<int, int> $tagIds */
    public function syncDocumentTags(int $documentId, array $tagIds): void
    {
        if ($documentId <= 0) throw new InvalidArgumentException('Documento inválido para vincular tags.');
        $ids = $this->assertActiveIds($tagIds);
        $started = false;
        if (!$this->pdo->inTransaction()) { $this->pdo->beginTransaction(); $started = true; }
        try {
            $delete = $this->pdo->prepare('DELETE FROM document_tags WHERE document_id = :document_id');
            $delete->execute([':document_id' => $documentId]);
            if ($ids !== []) {
                $insert = $this->pdo->prepare('INSERT INTO document_tags (document_id, tag_id) VALUES (:document_id, :tag_id)');
                foreach ($ids as $tagId) $insert->execute([':document_id' => $documentId, ':tag_id' => $tagId]);
            }
            if ($started) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function create(string $name, string $type, int $actorId): int
    {
        $normalized = $this->normalise($name);
        if ($normalized === '') throw new InvalidArgumentException('Informe uma tag válida.');
        if (mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 80) throw new InvalidArgumentException('A tag deve ter entre 2 e 80 caracteres.');
        if (!isset(self::TYPES[$type])) $type = 'topic';
        $existing = $this->resolve($normalized, false);
        if ($existing !== null) return (int)$existing['id'];
        $stmt = $this->pdo->prepare('INSERT INTO tags (name, normalized_name, tag_type, created_by) VALUES (:name, :normalized_name, :tag_type, :created_by) RETURNING id');
        try {
            $stmt->execute([':name' => trim($name), ':normalized_name' => $normalized, ':tag_type' => $type, ':created_by' => $actorId ?: null]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $exception) {
            $existing = $this->resolve($normalized, false);
            if ($existing !== null) return (int)$existing['id'];
            throw $exception;
        }
    }

    public function update(int $tagId, string $name, string $type): void
    {
        if ($tagId <= 0 || !isset(self::TYPES[$type])) throw new InvalidArgumentException('Tag inválida.');
        $normalized = $this->normalise($name);
        if ($normalized === '' || mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 80) throw new InvalidArgumentException('A tag deve ter entre 2 e 80 caracteres.');
        $stmt = $this->pdo->prepare('UPDATE tags SET name = :name, normalized_name = :normalized_name, tag_type = :tag_type WHERE id = :id');
        $stmt->execute([':name' => trim($name), ':normalized_name' => $normalized, ':tag_type' => $type, ':id' => $tagId]);
        if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Tag não encontrada ou sem alteração.');
    }

    public function setActive(int $tagId, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE tags SET active = :active WHERE id = :id');
        $stmt->bindValue(':active', $active, PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $tagId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Tag não encontrada ou sem alteração.');
    }

    public function addAlias(int $tagId, string $alias): void
    {
        $alias = trim($alias); $normalized = $this->normalise($alias);
        if ($tagId <= 0 || $normalized === '' || mb_strlen($alias) < 2 || mb_strlen($alias) > 80) throw new InvalidArgumentException('Sinônimo inválido.');
        $tag = $this->pdo->prepare('SELECT id FROM tags WHERE id = :id'); $tag->execute([':id' => $tagId]);
        if (!$tag->fetchColumn()) throw new InvalidArgumentException('Tag não encontrada.');
        if ($this->resolve($normalized, false) !== null) throw new InvalidArgumentException('Esse nome ou sinônimo já está em uso.');
        $stmt = $this->pdo->prepare('INSERT INTO tag_aliases (tag_id, alias, normalized_alias) VALUES (:tag_id, :alias, :normalized_alias)');
        $stmt->execute([':tag_id' => $tagId, ':alias' => $alias, ':normalized_alias' => $normalized]);
    }

    public function removeAlias(int $aliasId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tag_aliases WHERE id = :id'); $stmt->execute([':id' => $aliasId]);
        if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Sinônimo não encontrado.');
    }

    /** @return array{id:int,name:string,type:string,type_label:string}|null */
    public function resolveName(string $value): ?array
    {
        $row = $this->resolve($this->normalise($value), true);
        return $row === null ? null : $this->formatTag($row);
    }

    private function findOrCreate(string $name, int $actorId): int
    {
        $resolved = $this->resolve($this->normalise($name), true);
        return $resolved !== null ? (int)$resolved['id'] : $this->create($name, 'topic', $actorId);
    }

    /** @return array<string,mixed>|null */
    private function resolve(string $normalized, bool $activeOnly): ?array
    {
        if ($normalized === '') return null;
        $stmt = $this->pdo->prepare("\n            SELECT t.id, t.name, t.tag_type, t.active\n            FROM tags t\n            LEFT JOIN tag_aliases ta ON ta.tag_id = t.id\n            WHERE (t.normalized_name = :normalized OR ta.normalized_alias = :normalized)\n              " . ($activeOnly ? 'AND t.active = TRUE' : '') . "\n            LIMIT 1\n        ");
        $stmt->execute([':normalized' => $normalized]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string,mixed> $tag @return array<string,mixed> */
    private function formatTag(array $tag): array
    {
        $tag['id'] = (int)$tag['id'];
        $tag['type'] = (string)($tag['tag_type'] ?? $tag['type'] ?? 'topic');
        unset($tag['tag_type']);
        $tag['type_label'] = self::TYPES[$tag['type']] ?? self::TYPES['topic'];
        if (array_key_exists('active', $tag)) {
            $tag['active'] = in_array($tag['active'], [true, 1, '1', 't', 'true'], true);
        }
        if (isset($tag['aliases'])) {
            $aliases = $tag['aliases'];
            if (is_string($aliases) && str_starts_with($aliases, '{')) $aliases = trim($aliases, '{}') === '' ? [] : str_getcsv(trim($aliases, '{}'), ',', '"', '\\');
            $tag['aliases'] = is_array($aliases) ? array_values(array_filter(array_map('strval', $aliases))) : [];
        }
        return $tag;
    }

    private function normalise(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) $value = $transliterated;
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
