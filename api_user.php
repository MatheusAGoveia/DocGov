<?php
// api_user.php - API de Operações de Usuários e Favoritos (PostgreSQL)
require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Content-Type-Options: nosniff');

$loggedUser = $_SESSION['user'] ?? null;
if (!$loggedUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Usuário não autenticado.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
if (!CsrfService::isValid(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Sessão de segurança expirada.']);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$userId = (int)$loggedUser['id'];

if ($action === 'set_theme' || $action === 'update_theme') {
    $theme = trim((string)($_POST['theme'] ?? 'light'));
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
    $portalTheme = SystemSettingsService::normalizePortalTheme($_POST['theme'] ?? 'emerald');
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
    $targetType = trim((string)($_POST['target_type'] ?? $_POST['type'] ?? 'document'));
    if (!in_array($targetType, ['document', 'subcategory', 'subject'])) {
        $targetType = 'document';
    }

    $targetId = (int)($_POST['target_id'] ?? $_POST['doc_id'] ?? $_POST['subcat_id'] ?? $_POST['subject_id'] ?? 0);
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

if ($action === 'record_view') {
    $documentId = (int)($_POST['doc_id'] ?? 0);
    if ($documentId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Documento inválido.']);
        exit;
    }

    require_once __DIR__ . '/services/AccessService.php';
    $accessService = new AccessService($pdo);
    if (!$accessService->canAccessDocument($userId, $documentId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sem permissão de acesso a este documento.']);
        exit;
    }

    require_once __DIR__ . '/services/UsageAuditService.php';
    (new UsageAuditService($pdo))->log('document_view', $userId, 'DOCUMENT', $documentId, ['source' => 'portal_card']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'upload_avatar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['avatar_file']) || $_FILES['avatar_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: minha_conta.php?msg=err_upload');
        exit;
    }

    $file = $_FILES['avatar_file'];
    if (!isset($file['tmp_name'], $file['size']) || !is_uploaded_file((string)$file['tmp_name'])) {
        header('Location: minha_conta.php?msg=err_upload');
        exit;
    }

    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = $fileInfo ? (finfo_file($fileInfo, (string)$file['tmp_name']) ?: '') : '';
    if ($fileInfo) finfo_close($fileInfo);
    if (!isset($allowedMimes[$detectedMime]) || @getimagesize((string)$file['tmp_name']) === false) {
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

    $newFileName = 'user_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $allowedMimes[$detectedMime];
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

if ($action === 'remove_avatar') {
    $avatarStmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $avatarStmt->execute([':id' => $userId]);
    $avatarPath = str_replace('\\', '/', (string)$avatarStmt->fetchColumn());

    $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = :id')->execute([':id' => $userId]);
    $_SESSION['user']['avatar'] = null;

    if (preg_match('#^uploads/avatars/user_' . preg_quote((string)$userId, '#') . '_[a-f0-9]{24}\.(jpg|png|webp)$#', $avatarPath) === 1) {
        $avatarRoot = realpath(__DIR__ . '/uploads/avatars');
        $candidate = realpath(__DIR__ . '/' . $avatarPath);
        if ($avatarRoot && $candidate && str_starts_with($candidate, $avatarRoot . DIRECTORY_SEPARATOR) && is_file($candidate)) {
            unlink($candidate);
        }
    }

    header('Location: minha_conta.php?msg=avatar_removed');
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.']);
