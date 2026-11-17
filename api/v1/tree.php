<?php
// api/v1/tree.php — categorias, subcategorias e assuntos legíveis pela chave (GET).
declare(strict_types=1);
define('DOCGOV_API_V1_ENDPOINT', true);
require __DIR__ . '/bootstrap.php';

$tree = $apiPermissions->getAccessibleResourceTree($apiViewerId);
$subjectIds = docgovApiSubjectIds($tree);
$categoryIds = array_map(static fn(array $category): int => (int)$category['id'], $tree);
$subcategoryIds = [];
foreach ($tree as $category) {
    foreach ($category['subcategories'] as $subcategory) {
        $subcategoryIds[] = (int)$subcategory['id'];
    }
}

/** @return array<int, array<string, mixed>> detalhes indexados por ID */
$loadDetails = static function (PDO $pdo, string $table, array $ids): array {
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT id, slug, description FROM {$table} WHERE id = ANY(CAST(:ids AS int[]))");
    $stmt->execute([':ids' => docgovApiIntArray($ids)]);
    $details = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details[(int)$row['id']] = $row;
    }
    return $details;
};
$categoryDetails = $loadDetails($pdo, 'categories', $categoryIds);
$subcategoryDetails = $loadDetails($pdo, 'subcategories', $subcategoryIds);
$subjectDetails = $loadDetails($pdo, 'subjects', $subjectIds);

$publishedCounts = [];
if ($subjectIds !== []) {
    $countStmt = $pdo->prepare("
        SELECT subject_id, COUNT(*) AS total
        FROM documents
        WHERE status = 'published' AND trashed_at IS NULL AND subject_id = ANY(CAST(:ids AS int[]))
        GROUP BY subject_id
    ");
    $countStmt->execute([':ids' => docgovApiIntArray($subjectIds)]);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $publishedCounts[(int)$row['subject_id']] = (int)$row['total'];
    }
}

$node = static function (array $item, array $details): array {
    $detail = $details[(int)$item['id']] ?? [];
    return [
        'id' => (int)$item['id'],
        'name' => (string)$item['name'],
        'slug' => (string)($detail['slug'] ?? ''),
        'description' => (string)($detail['description'] ?? ''),
    ];
};

$data = [];
foreach ($tree as $category) {
    $categoryNode = $node($category, $categoryDetails) + ['subcategories' => []];
    foreach ($category['subcategories'] as $subcategory) {
        $subcategoryNode = $node($subcategory, $subcategoryDetails) + ['subjects' => []];
        foreach ($subcategory['subjects'] as $subject) {
            $subcategoryNode['subjects'][] = $node($subject, $subjectDetails) + [
                'visibility' => (string)($subject['visibility'] ?? 'private'),
                'published_documents' => $publishedCounts[(int)$subject['id']] ?? 0,
            ];
        }
        $categoryNode['subcategories'][] = $subcategoryNode;
    }
    $data[] = $categoryNode;
}

docgovApiOk($data, ['subjects' => count($subjectIds)]);
