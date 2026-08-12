<?php
// Teste transacional de tags: nenhum dado de teste permanece no banco.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/TagService.php';

function tagTestAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function tagTestId(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$suffix = bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $actorId = tagTestId($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'editor', TRUE) RETURNING id", ['Editor de Tags', "tag.editor.$suffix", "tag.editor.$suffix@example.invalid"]);
    $categoryId = tagTestId($pdo, "INSERT INTO categories (name, slug, description) VALUES (?, ?, '') RETURNING id", ['Infraestrutura Tags', "infra-tags-$suffix"]);
    $subcategoryId = tagTestId($pdo, "INSERT INTO subcategories (category_id, name, slug, description) VALUES (?, ?, ?, '') RETURNING id", [$categoryId, 'Plataformas', "plataformas-$suffix"]);
    $subjectId = tagTestId($pdo, "INSERT INTO subjects (subcategory_id, name, slug, description) VALUES (?, ?, ?, '') RETURNING id", [$subcategoryId, 'Virtualização', "virtualizacao-$suffix"]);
    $documentId = tagTestId($pdo, "INSERT INTO documents (subject_id, created_by, title, slug, content_type, status) VALUES (?, ?, ?, ?, 'text', 'draft') RETURNING id", [$subjectId, $actorId, 'Guia de teste', "guia-$suffix"]);

    $service = new TagService($pdo);
    $tagId = $service->create("Nutanix $suffix", 'technology', $actorId);
    $service->addAlias($tagId, "HCI $suffix");
    $resolved = $service->resolveName("HCI $suffix");
    tagTestAssert($resolved !== null && (int)$resolved['id'] === $tagId, 'Sinônimo não resolveu para a tag canônica.');
    tagTestAssert($service->create("Nutanix $suffix", 'topic', $actorId) === $tagId, 'Nome canônico duplicou a tag.');

    $service->syncDocumentTags($documentId, [$tagId]);
    $documentTags = $service->getDocumentTags($documentId);
    tagTestAssert(count($documentTags) === 1 && (int)$documentTags[0]['id'] === $tagId, 'Vínculo documento-tag não foi persistido.');
    tagTestAssert(isset($service->mapDocumentTags([$documentId])[$documentId]), 'Mapeamento de tags por documento falhou.');

    $service->setActive($tagId, false);
    $inactiveDetails = array_values(array_filter($service->allWithDetails(), static fn(array $tag): bool => (int)$tag['id'] === $tagId));
    tagTestAssert(count($inactiveDetails) === 1 && $inactiveDetails[0]['active'] === false, 'Catálogo não marcou a tag inativa corretamente.');
    try {
        $service->assertActiveIds([$tagId]);
        throw new RuntimeException('Tag inativa continuou disponível para seleção.');
    } catch (InvalidArgumentException) {
        // Esperado.
    }
    $service->setActive($tagId, true);
    $details = array_values(array_filter($service->allWithDetails(), static fn(array $tag): bool => (int)$tag['id'] === $tagId));
    tagTestAssert(count($details) === 1 && (int)$details[0]['document_count'] === 1, 'Catálogo não apresentou a contagem de documentos.');

    echo "[OK] Tags: criação, sinônimo, vínculo, escopo ativo e catálogo validados.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
