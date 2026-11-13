<?php
/**
 * structure/actions/save-category.php
 * Entradas: $pdo, $loggedUser, $isGlobalAdminCurrent, $permService, $categoryImageService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 2. GESTÃO DE CATEGORIAS (CRIAR/EDITAR/EXCLUIR)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_category'])) {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $statusVal = trim($_POST['status'] ?? 'ativo') === 'ativo';
    $catId = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $redirectTab = trim($_POST['redirect_tab'] ?? 'categorias');
    $categoryImageFile = isset($_FILES['category_image']) && is_array($_FILES['category_image'])
        ? $_FILES['category_image']
        : null;
    $removeCategoryImage = $catId !== null && isset($_POST['remove_category_image']);

    $userId = (int)($loggedUser['id'] ?? 0);
    $canSaveCategory = $catId
        ? ($permService->canAdminCategory($userId, $catId) || $permService->isGlobalAdmin($userId))
        : $permService->canCreateCategory($userId);
    $currentCategoryActive = null;
    if ($catId) {
        $statusStmt = $pdo->prepare('SELECT active FROM categories WHERE id = :id');
        $statusStmt->execute([':id' => $catId]);
        $storedStatus = $statusStmt->fetchColumn();
        if ($storedStatus !== false) $currentCategoryActive = docgovDatabaseBoolean($storedStatus);
    }

    if ($catId && $currentCategoryActive === null) {
        $errorMessage = 'Categoria não encontrada.';
    } elseif ($catId && $currentCategoryActive !== $statusVal && !$isGlobalAdminCurrent) {
        http_response_code(403);
        $errorMessage = 'Somente Super Admins podem descartar ou restaurar categorias.';
    } elseif (!$canSaveCategory) {
        http_response_code(403);
        $errorMessage = $catId
            ? 'Acesso negado. É necessário privilégio Admin nesta Categoria (ou ser Administrador Global) para alterá-la.'
            : 'Acesso negado. Apenas o Administrador Global pode criar novas Categorias no nível raiz.';
    } elseif (empty($nome)) {
        $errorMessage = 'Informe o nome da categoria.';
    } elseif (($imageError = $categoryImageService->validate($categoryImageFile)) !== null) {
        $errorMessage = $imageError;
    } else {
        $oldImagePath = null;
        $newImagePath = null;
        $savedCategoryId = (int)($catId ?? 0);

        try {
            $pdo->beginTransaction();

            if ($catId) {
                $oldImageStmt = $pdo->prepare('SELECT image_path FROM categories WHERE id = :id FOR UPDATE');
                $oldImageStmt->execute([':id' => $catId]);
                $oldImagePath = $oldImageStmt->fetchColumn();
                if ($oldImagePath === false) {
                    throw new RuntimeException('Categoria não encontrada.');
                }

                $slug = slugify($nome);
                $stmt = $pdo->prepare('UPDATE categories SET name = :name, slug = :slug, description = :desc, active = :active WHERE id = :id');
                $stmt->execute([':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':id' => $catId]);
            } else {
                $slug = slugify($nome);
                $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description, active) VALUES (:name, :slug, :desc, :active) RETURNING id');
                $stmt->execute([':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false']);
                $savedCategoryId = (int)$stmt->fetchColumn();
            }

            $hasNewImage = $categoryImageFile !== null
                && (int)($categoryImageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasNewImage) {
                $newImagePath = $categoryImageService->store($categoryImageFile, $savedCategoryId);
                $imageStmt = $pdo->prepare('UPDATE categories SET image_path = :image_path WHERE id = :id');
                $imageStmt->execute([':image_path' => $newImagePath, ':id' => $savedCategoryId]);
            } elseif ($removeCategoryImage) {
                $imageStmt = $pdo->prepare('UPDATE categories SET image_path = NULL WHERE id = :id');
                $imageStmt->execute([':id' => $savedCategoryId]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newImagePath !== null) {
                $categoryImageService->remove($newImagePath);
            }
            $errorMessage = $exception->getMessage();
        }

        if ($errorMessage === '') {
            if (($newImagePath !== null || $removeCategoryImage) && is_string($oldImagePath)) {
                $categoryImageService->remove($oldImagePath);
            }
            $usageAuditService->logAdminAction($userId, $catId ? 'category_updated' : 'category_created', 'CATEGORY', $savedCategoryId);
            $redirectParams = ['tab' => $redirectTab, 'msg' => 'category_saved'];
            if ($redirectTab === 'novo_documento' && !$catId && $statusVal) {
                $redirectParams += ['setup' => 'subcategory', 'cat_id' => $savedCategoryId];
            } elseif ($redirectTab === 'novo_documento' && !$statusVal) {
                $redirectParams['tab'] = 'categorias';
            }
            header('Location: index.php?' . http_build_query($redirectParams));
            exit;
        }
    }
}
