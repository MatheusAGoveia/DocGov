<?php

require_once __DIR__ . '/../config/db.php';

$hasColumn = $pdo->query("
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'categories'
      AND column_name = 'image_path'
")->fetchColumn();

if (!$hasColumn) {
    fwrite(STDERR, "[FAIL] Coluna image_path ausente.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $slug = 'category-image-test-' . bin2hex(random_bytes(5));
    $imagePath = 'storage/categories/category_999_aaaaaaaaaaaaaaaaaaaaaaaa.jpg';
    $stmt = $pdo->prepare("
        INSERT INTO categories (name, slug, description, image_path, active)
        VALUES (:name, :slug, '', :image_path, TRUE)
        RETURNING id
    ");
    $stmt->execute([
        ':name' => 'Teste de imagem',
        ':slug' => $slug,
        ':image_path' => $imagePath,
    ]);
    $categoryId = (int)$stmt->fetchColumn();

    $savedImageStmt = $pdo->prepare('SELECT image_path FROM categories WHERE id = :id');
    $savedImageStmt->execute([':id' => $categoryId]);
    if ($savedImageStmt->fetchColumn() !== $imagePath) {
        throw new RuntimeException('Persistência da imagem falhou.');
    }

    $pdo->rollBack();
    echo "[OK] Imagem opcional da categoria: migração e persistência validadas.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . "\n");
    exit(1);
}
