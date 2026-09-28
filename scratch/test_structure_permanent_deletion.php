<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/StructureDeletionService.php';

$permissions = new PermissionService($pdo);
$service = new StructureDeletionService($pdo, $permissions);
$superAdmins = array_map('intval', $pdo->query("SELECT id FROM users WHERE active = TRUE AND role IN ('admin', 'super_admin') ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
$readerId = (int)$pdo->query("SELECT id FROM users WHERE active = TRUE AND role NOT IN ('admin', 'super_admin') ORDER BY id LIMIT 1")->fetchColumn();
if (count($superAdmins) < 2 || $readerId <= 0) throw new RuntimeException('O teste precisa de dois Super Admins e um leitor ativos.');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expectFailure = static function (callable $action, string $message) use ($assert): void {
    try {
        $action();
    } catch (RuntimeException | InvalidArgumentException $exception) {
        return;
    }
    $assert(false, $message);
};

$makeBranch = static function (bool $categoryActive, bool $subcategoryActive, bool $subjectActive) use ($pdo, $superAdmins): array {
    $suffix = bin2hex(random_bytes(8));
    $name = 'Teste de exclusão ' . $suffix;
    $category = $pdo->prepare('INSERT INTO categories (name, slug, active) VALUES (:name, :slug, :active) RETURNING id');
    $category->execute([':name' => $name, ':slug' => 'test-structure-delete-' . $suffix, ':active' => $categoryActive ? 'true' : 'false']);
    $categoryId = (int)$category->fetchColumn();
    $subcategory = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug, active) VALUES (:category_id, :name, :slug, :active) RETURNING id');
    $subcategory->execute([':category_id' => $categoryId, ':name' => $name . ' Sub', ':slug' => 'sub-' . $suffix, ':active' => $subcategoryActive ? 'true' : 'false']);
    $subcategoryId = (int)$subcategory->fetchColumn();
    $subject = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug, active) VALUES (:subcategory_id, :name, :slug, :active) RETURNING id');
    $subject->execute([':subcategory_id' => $subcategoryId, ':name' => $name . ' Assunto', ':slug' => 'subject-' . $suffix, ':active' => $subjectActive ? 'true' : 'false']);
    $subjectId = (int)$subject->fetchColumn();
    $document = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, status, text_content) VALUES (:subject_id, :created_by, :title, :slug, 'text', 'documents', 'published', '<p>Teste transacional.</p>') RETURNING id");
    $document->execute([':subject_id' => $subjectId, ':created_by' => $superAdmins[0], ':title' => $name . ' Doc', ':slug' => 'doc-' . $suffix]);
    $documentId = (int)$document->fetchColumn();
    $history = $pdo->prepare("INSERT INTO document_workflow_history (document_id, actor_id, action, previous_status, new_status) VALUES (:document_id, :actor_id, 'approved_and_published', 'review', 'published')");
    $history->execute([':document_id' => $documentId, ':actor_id' => $superAdmins[0]]);
    return compact('name', 'categoryId', 'subcategoryId', 'subjectId', 'documentId');
};

$pdo->beginTransaction();
try {
    $categoryBranch = $makeBranch(false, true, true);
    $permissions->saveResourcePermission('category', $categoryBranch['categoryId'], $readerId, null, 'view', $superAdmins[0]);
    $preview = $service->preview('category', $categoryBranch['categoryId'], $superAdmins[1]);
    $assert($preview['subcategories'] === 1 && $preview['subjects'] === 1 && $preview['documents'] === 1 && $preview['published_documents'] === 1, 'Prévia da categoria incorreta.');
    $expectFailure(static fn() => $service->preview('category', $categoryBranch['categoryId'], $readerId), 'Leitor visualizou exclusão definitiva.');
    $expectFailure(static fn() => $service->deletePermanently('category', $categoryBranch['categoryId'], $readerId, $categoryBranch['name']), 'Leitor excluiu categoria.');
    $expectFailure(static fn() => $service->deletePermanently('category', $categoryBranch['categoryId'], $superAdmins[1], 'nome incorreto'), 'Confirmação incorreta foi aceita.');
    $deleted = $service->deletePermanently('category', $categoryBranch['categoryId'], $superAdmins[1], $categoryBranch['name']);
    $assert($deleted['summary']['documents'] === 1, 'Resumo da exclusão incorreto.');
    foreach (['categories' => $categoryBranch['categoryId'], 'subcategories' => $categoryBranch['subcategoryId'], 'subjects' => $categoryBranch['subjectId'], 'documents' => $categoryBranch['documentId']] as $table => $id) {
        $query = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
        $query->execute([':id' => $id]);
        $assert(!$query->fetchColumn(), "{$table} não foi removida.");
    }
    $permissionCheck = $pdo->prepare('SELECT 1 FROM permissions WHERE category_id = :id');
    $permissionCheck->execute([':id' => $categoryBranch['categoryId']]);
    $assert(!$permissionCheck->fetchColumn(), 'Permissão órfã após exclusão.');
    $historyCheck = $pdo->prepare('SELECT 1 FROM document_workflow_history WHERE document_id = :id');
    $historyCheck->execute([':id' => $categoryBranch['documentId']]);
    $assert(!$historyCheck->fetchColumn(), 'Histórico órfão após exclusão.');

    $subcategoryBranch = $makeBranch(true, false, true);
    $service->deletePermanently('subcategory', $subcategoryBranch['subcategoryId'], $superAdmins[0], $subcategoryBranch['name'] . ' Sub');
    $categoryCheck = $pdo->prepare('SELECT 1 FROM categories WHERE id = :id');
    $categoryCheck->execute([':id' => $subcategoryBranch['categoryId']]);
    $assert((bool)$categoryCheck->fetchColumn(), 'Categoria pai removida ao excluir subcategoria.');
    $subjectCheck = $pdo->prepare('SELECT 1 FROM subjects WHERE id = :id');
    $subjectCheck->execute([':id' => $subcategoryBranch['subjectId']]);
    $assert(!$subjectCheck->fetchColumn(), 'Assunto filho não removido com subcategoria.');

    $subjectBranch = $makeBranch(true, true, false);
    $service->deletePermanently('subject', $subjectBranch['subjectId'], $superAdmins[0], $subjectBranch['name'] . ' Assunto');
    $categoryCheck->execute([':id' => $subjectBranch['categoryId']]);
    $assert((bool)$categoryCheck->fetchColumn(), 'Categoria pai removida ao excluir assunto.');
    $subcategoryCheck = $pdo->prepare('SELECT 1 FROM subcategories WHERE id = :id');
    $subcategoryCheck->execute([':id' => $subjectBranch['subcategoryId']]);
    $assert((bool)$subcategoryCheck->fetchColumn(), 'Subcategoria pai removida ao excluir assunto.');

    $activeBranch = $makeBranch(true, true, true);
    $expectFailure(static fn() => $service->deletePermanently('category', $activeBranch['categoryId'], $superAdmins[0], $activeBranch['name']), 'Categoria ativa foi excluída.');
    $pdo->rollBack();
    echo "OK: exclusão de categoria, subcategoria e assunto; confirmação; permissão; dependências; transação revertida.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
