<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/CsrfService.php';

if ($appEnvironment !== 'development' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Este teste exige o banco local de desenvolvimento.');
}

function visibilityAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function visibilityFixture(PDO $pdo): array {
    $token = bin2hex(random_bytes(12));
    $actor = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
    visibilityAssert($actor > 0, 'Administrador de teste indisponível.');
    $users = ['global' => $actor];
    $insertUser = $pdo->prepare("INSERT INTO users (name, username, email, role, active) VALUES (:name, :username, :email, 'reader', :active) RETURNING id");
    foreach (['reader', 'editor', 'manager', 'outsider', 'inactive'] as $role) {
        $username = 'visibility-' . $role . '-' . $token;
        $insertUser->execute([':name' => 'Visibilidade teste ' . $role, ':username' => $username, ':email' => $username . '@example.invalid', ':active' => $role === 'inactive' ? 'false' : 'true']);
        $users[$role] = (int)$insertUser->fetchColumn();
    }
    $categoryStmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug) RETURNING id');
    $categoryStmt->execute([':name' => 'Visibilidade teste ' . $token, ':slug' => 'visibility-test-' . $token]);
    $category = (int)$categoryStmt->fetchColumn();
    $subcategories = []; $subjects = [];
    $subStmt = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug) VALUES (?, ?, ?) RETURNING id');
    $subjectStmt = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug) VALUES (?, ?, ?) RETURNING id');
    foreach (['public', 'private'] as $type) {
        $subStmt->execute([$category, ($type === 'public' ? 'Orientações gerais teste ' : 'Privada teste ') . $token, $type . '-' . $token]);
        $subcategories[$type] = (int)$subStmt->fetchColumn();
        $subjectStmt->execute([$subcategories[$type], 'Assunto ' . $type . ' teste ' . $token, 'subject-' . $type . '-' . $token]);
        $subjects[$type] = (int)$subjectStmt->fetchColumn();
    }
    $subjectStmt->execute([$subcategories['public'], 'Assunto sibling teste ' . $token, 'subject-sibling-' . $token]);
    $subjects['sibling'] = (int)$subjectStmt->fetchColumn();
    $pdo->prepare("UPDATE subjects SET visibility = 'public' WHERE id = ?")->execute([$subjects['public']]);
    $ruleStmt = $pdo->prepare('INSERT INTO permissions (user_id, category_id, permission_level, created_by) VALUES (?, ?, ?, ?)');
    $ruleStmt->execute([$users['editor'], $category, 'edit', $actor]);
    $ruleStmt->execute([$users['manager'], $category, 'admin', $actor]);
    $categoryStmt->execute([':name' => 'Categoria externa teste ' . $token, ':slug' => 'visibility-other-' . $token]);
    $otherCategory = (int)$categoryStmt->fetchColumn();
    $ruleStmt->execute([$users['outsider'], $otherCategory, 'admin', $actor]);
    $documents = [];
    $docStmt = $pdo->prepare('INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, status, text_content) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
    foreach (['public' => ['public', 'published'], 'private' => ['private', 'published'], 'sibling' => ['sibling', 'published'], 'draft' => ['public', 'draft'], 'review' => ['public', 'review'], 'file' => ['public', 'published'], 'private_file' => ['private', 'published']] as $key => [$branch, $status]) {
        $file = str_contains($key, 'file');
        $docStmt->execute([$subjects[$branch], $actor, 'Documento ' . $key . ' teste ' . $token, 'document-' . $key . '-' . $token, $file ? 'file' : 'text', $file ? 'attachments' : 'documents', $status, $file ? null : '<p>Orientação de teste ' . $token . '</p>']);
        $documents[$key] = (int)$docStmt->fetchColumn();
    }
    return compact('token', 'users', 'category', 'otherCategory', 'subcategories', 'subjects', 'documents');
}

function visibilityCleanup(PDO $pdo, string $token): void {
    visibilityAssert((bool)preg_match('/^[a-f0-9]{24}$/', $token), 'Token de fixture inválido.');
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE slug IN (?, ?)');
    $stmt->execute(['visibility-test-' . $token, 'visibility-other-' . $token]);
    $categories = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $pdo->beginTransaction();
    try {
        foreach ($categories as $categoryId) {
            $pdo->prepare('DELETE FROM documents WHERE subject_id IN (SELECT s.id FROM subjects s JOIN subcategories sc ON sc.id = s.subcategory_id WHERE sc.category_id = ?)')->execute([$categoryId]);
            $pdo->prepare('DELETE FROM subjects WHERE subcategory_id IN (SELECT id FROM subcategories WHERE category_id = ?)')->execute([$categoryId]);
            $pdo->prepare('DELETE FROM subcategories WHERE category_id = ?')->execute([$categoryId]);
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
        }
        foreach (['reader', 'editor', 'manager', 'outsider', 'inactive'] as $role) {
            $pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['visibility-' . $role . '-' . $token]);
        }
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
    // Só arquivos com o token exclusivo deste teste, dentro dos diretórios fixos.
    foreach (['documents', 'categories', 'subcategories'] as $directory) {
        foreach (glob(__DIR__ . '/../storage/' . $directory . '/*_' . $token . '.png') ?: [] as $file) unlink($file);
    }
}

$mode = $argv[1] ?? '--test';
if ($mode === '--cleanup') { visibilityCleanup($pdo, (string)($argv[2] ?? '')); echo "Fixture removida.\n"; exit; }
if ($mode === '--prepare') {
    $fixture = null;
    try {
        $pdo->beginTransaction(); $fixture = visibilityFixture($pdo); $pdo->commit();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aL1cAAAAASUVORK5CYII=', true);
        $paths = ['document' => 'storage/documents/visibility_' . $fixture['token'] . '.png', 'category' => 'storage/categories/category_' . $fixture['category'] . '_' . $fixture['token'] . '.png'];
        foreach ($fixture['subcategories'] as $type => $id) $paths[$type] = 'storage/subcategories/subcategory_' . $id . '_' . $fixture['token'] . '.png';
        foreach ($paths as $path) visibilityAssert(file_put_contents(__DIR__ . '/../' . $path, $png) !== false, 'Falha ao criar arquivo de teste.');
        $pdo->prepare('UPDATE categories SET image_path = ? WHERE id = ?')->execute([$paths['category'], $fixture['category']]);
        foreach ($fixture['subcategories'] as $type => $id) $pdo->prepare('UPDATE subcategories SET image_path = ? WHERE id = ?')->execute([$paths[$type], $id]);
        foreach (['file', 'private_file'] as $key) $pdo->prepare('UPDATE documents SET file_path = ?, stored_filename = ?, original_filename = ?, mime_type = ?, file_size = ? WHERE id = ?')->execute([$paths['document'], basename($paths['document']), 'teste.png', 'image/png', strlen($png), $fixture['documents'][$key]]);
        $fixture['sessions'] = [];
        foreach (['global', 'reader', 'editor', 'manager', 'outsider'] as $role) {
            $stmt = $pdo->prepare('SELECT id, name, username, email, role FROM users WHERE id = ?'); $stmt->execute([$fixture['users'][$role]]); $user = $stmt->fetch();
            session_id(bin2hex(random_bytes(16))); session_start();
            $_SESSION = ['user' => ['id' => (int)$user['id'], 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => 'T'], 'admin_logged' => in_array($role, ['global', 'manager', 'editor', 'outsider'], true)];
            $fixture['sessions'][$role] = ['id' => session_id(), 'csrf' => CsrfService::token()]; session_write_close();
        }
        echo json_encode($fixture, JSON_THROW_ON_ERROR); exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($fixture !== null) visibilityCleanup($pdo, $fixture['token']);
        throw $error;
    }
}
visibilityAssert($mode === '--test', 'Modo de teste inválido.');
$pdo->beginTransaction();
try {
    $f = visibilityFixture($pdo); $permissions = new PermissionService($pdo); $reader = $f['users']['reader'];
    visibilityAssert($permissions->canViewCategory($reader, $f['category']), 'Categoria com ramo público deve permitir navegação.');
    visibilityAssert(!$permissions->canView($reader, 'category', $f['category']), 'O pai não deve conceder permissão herdável aos irmãos.');
    visibilityAssert($permissions->canViewSubcategory($reader, $f['subcategories']['public']), 'Ramo público não abriu.');
    visibilityAssert(!$permissions->canViewSubcategory($reader, $f['subcategories']['private']), 'Ramo privado vazou.');
    visibilityAssert(!$permissions->canView($reader, 'subcategory', $f['subcategories']['public']), 'Subcategoria pai criou permissão herdável.');
    visibilityAssert($permissions->canViewSubject($reader, $f['subjects']['public']), 'Assunto público não abriu.');
    visibilityAssert(!$permissions->canViewSubject($reader, $f['subjects']['sibling']), 'Assunto privado irmão vazou.');
    visibilityAssert($permissions->canViewDocument($reader, $f['documents']['public']), 'Documento publicado não abriu.');
    foreach (['private', 'sibling', 'draft', 'review', 'private_file'] as $key) visibilityAssert(!$permissions->canViewDocument($reader, $f['documents'][$key]), 'Leitor acessou ' . $key);
    foreach ([0, $f['users']['inactive'], 2147483647] as $user) visibilityAssert(!$permissions->canViewDocument($user, $f['documents']['public']), 'Visitante, usuário inativo ou inexistente recebeu acesso.');
    visibilityAssert(!$permissions->canCreateSubject($reader, $f['subcategories']['public']) && !$permissions->canEditDocument($reader, $f['documents']['public']) && !$permissions->canAdminSubcategory($reader, $f['subcategories']['public']) && !$permissions->canAccessAdminPanel($reader), 'Publicidade concedeu escrita ou administração.');
    visibilityAssert($permissions->canEditDocument($f['users']['editor'], $f['documents']['public']), 'Permissão de edição existente foi perdida.');
    visibilityAssert($permissions->canViewDocument($f['users']['global'], $f['documents']['draft']), 'Administração perdeu acesso a rascunhos.');
    $allowed = $permissions->getAllowedSubcategoryIds($reader);
    visibilityAssert(in_array($f['subcategories']['public'], $allowed, true) && !in_array($f['subcategories']['private'], $allowed, true), 'Árvore liberou irmão privado.');
    $effective = $permissions->getEffectiveSubjectPermission($reader, $f['subjects']['public']);
    visibilityAssert($effective['effective_level'] === 'view' && $effective['sources'][0]['principal_type'] === 'public', 'Fonte pública não foi explicada.');
    $diagnosis = $permissions->getUserEffectiveAccessDiagnosis($reader);
    visibilityAssert(count(array_filter($diagnosis['resources'], fn($r) => $r['resource_type'] === 'subject' && $r['resource_id'] === $f['subjects']['public'])) === 1, 'Diagnóstico omitiu o acesso público.');
    $pdo->prepare("UPDATE subjects SET visibility = 'private' WHERE id = ?")->execute([$f['subjects']['public']]);
    visibilityAssert(!$permissions->canViewDocument($reader, $f['documents']['public']), 'Revogação não teve efeito imediato.');
    $pdo->prepare('INSERT INTO permissions (user_id, subject_id, permission_level, created_by) VALUES (?, ?, ?, ?)')->execute([$reader, $f['subjects']['public'], 'view', $f['users']['global']]);
    visibilityAssert($permissions->canViewDocument($reader, $f['documents']['public']), 'Permissão explícita não foi preservada em ramo privado.');
    $pdo->prepare('DELETE FROM permissions WHERE user_id = ?')->execute([$reader]);
    $pdo->prepare("UPDATE subjects SET visibility = 'public' WHERE id = ?")->execute([$f['subjects']['public']]);
    foreach (['categories' => $f['category'], 'subcategories' => $f['subcategories']['public'], 'subjects' => $f['subjects']['public']] as $table => $id) {
        $pdo->prepare("UPDATE {$table} SET active = FALSE WHERE id = ?")->execute([$id]);
        visibilityAssert(!$permissions->canViewDocument($reader, $f['documents']['public']), 'Ancestral inativo liberou leitura.');
        $pdo->prepare("UPDATE {$table} SET active = TRUE WHERE id = ?")->execute([$id]);
    }
    echo "PASS visibilidade: isolamento de irmãos, herança de leitura, status, edição, visitantes, usuários inativos, diagnóstico e revogação.\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
