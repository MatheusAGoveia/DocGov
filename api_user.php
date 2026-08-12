<?php
// api_user.php - API de Operações de Usuários e Favoritos (PostgreSQL)
require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

$loggedUser = $_SESSION['user'] ?? null;
if (!$loggedUser) {
    echo json_encode(['success' => false, 'error' => 'Usuário não autenticado.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$userId = (int)$loggedUser['id'];

if ($action === 'set_theme' || $action === 'update_theme') {
    $theme = trim($_REQUEST['theme'] ?? 'light');
    if (!in_array($theme, ['light', 'dark', 'system'], true)) {
        $theme = 'light';
    }

    $stmt = $pdo->prepare("UPDATE users SET theme_preference = :theme WHERE id = :id");
    $stmt->execute([':theme' => $theme, ':id' => $userId]);

    $_SESSION['user']['tema_preferido'] = $theme;
    $_SESSION['user']['theme_preference'] = $theme;
    echo json_encode(['success' => true, 'theme' => $theme]);
    exit;
}

if ($action === 'update_portal_theme') {
    require_once __DIR__ . '/services/SystemSettingsService.php';
    $portalTheme = SystemSettingsService::normalizePortalTheme($_REQUEST['theme'] ?? 'emerald');
    try {
        $stmt = $pdo->prepare("UPDATE users SET portal_theme = :theme WHERE id = :id");
        $stmt->execute([':theme' => $portalTheme, ':id' => $userId]);
    } catch (Throwable $e) {
        // Fallback silencioso se a coluna não existir
    }
    $_SESSION['user']['portal_theme'] = $portalTheme;
    echo json_encode(['success' => true, 'portal_theme' => $portalTheme]);
    exit;
}

if ($action === 'toggle_favorito') {
    $targetType = trim($_REQUEST['target_type'] ?? $_REQUEST['type'] ?? 'document');
    if (!in_array($targetType, ['document', 'subcategory', 'subject'])) {
        $targetType = 'document';
    }

    $targetId = (int)($_REQUEST['target_id'] ?? $_REQUEST['doc_id'] ?? $_REQUEST['subcat_id'] ?? $_REQUEST['subject_id'] ?? 0);
    if ($targetId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID de elemento inválido.']);
        exit;
    }

    $columnMap = [
        'document' => 'document_id',
        'subcategory' => 'subcategory_id',
        'subject' => 'subject_id'
    ];
    $column = $columnMap[$targetType];

    // Validação Anti-IDOR: Garantir que o usuário possui acesso ao grupo da entidade antes de favoritar
    require_once __DIR__ . '/services/AccessService.php';
    $accessService = new AccessService($pdo);

    if ($targetType === 'document' && !$accessService->canAccessDocument($userId, $targetId)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão de acesso a este documento.']);
        exit;
    }
    if ($targetType === 'subcategory' && !$accessService->canAccessSubcategory($userId, $targetId)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão de acesso a esta subcategoria.']);
        exit;
    }
    if ($targetType === 'subject' && !$accessService->canAccessSubject($userId, $targetId)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão de acesso a este assunto.']);
        exit;
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM favorites WHERE user_id = :user_id AND {$column} = :target_id");
    $stmtCheck->execute([':user_id' => $userId, ':target_id' => $targetId]);
    $fav = $stmtCheck->fetch();

    if ($fav) {
        $stmtDel = $pdo->prepare("DELETE FROM favorites WHERE user_id = :user_id AND {$column} = :target_id");
        $stmtDel->execute([':user_id' => $userId, ':target_id' => $targetId]);
        echo json_encode(['success' => true, 'is_favorite' => false, 'type' => $targetType, 'id' => $targetId]);
    } else {
        $stmtIns = $pdo->prepare("INSERT INTO favorites (user_id, {$column}) VALUES (:user_id, :target_id)");
        $stmtIns->execute([':user_id' => $userId, ':target_id' => $targetId]);
        echo json_encode(['success' => true, 'is_favorite' => true, 'type' => $targetType, 'id' => $targetId]);
    }
    exit;
}

if ($action === 'upload_avatar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['avatar_file']) || $_FILES['avatar_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: minha_conta.php?msg=err_upload');
        exit;
    }

    $file = $_FILES['avatar_file'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($fileExt, $allowedExts)) {
        header('Location: minha_conta.php?msg=err_ext');
        exit;
    }

    if ($file['size'] > 3 * 1024 * 1024) { // 3MB limit
        header('Location: minha_conta.php?msg=err_size');
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/avatars';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFileName = 'user_' . $userId . '_' . time() . '.' . $fileExt;
    $targetPath = $uploadDir . '/' . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $relativePath = 'uploads/avatars/' . $newFileName;
        $stmt = $pdo->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
        $stmt->execute([':avatar' => $relativePath, ':id' => $userId]);
        $_SESSION['user']['avatar'] = $relativePath;

        header('Location: minha_conta.php?msg=ok_avatar');
    } else {
        header('Location: minha_conta.php?msg=err_save');
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.']);
