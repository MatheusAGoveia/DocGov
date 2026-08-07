<?php
// document-file.php — Endpoint Protegido para Stream Inline de PDF
ob_start();
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';

$loggedUser = $_SESSION['user'] ?? null;
$docId = (int)($_GET['id'] ?? 0);

if ($docId <= 0) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ID do documento inválido.";
    exit;
}

try {
    // 1. Busca do Documento Real no PostgreSQL
    $stmt = $pdo->prepare("
        SELECT id, title, content_type, file_path, stored_filename, original_filename, mime_type, file_size, status, subject_id
        FROM documents
        WHERE id = :id
    ");
    $stmt->execute([':id' => $docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Documento não encontrado no banco de dados.";
        exit;
    }

    // 2. Validação de Permissão de Acesso via AccessService
    require_once __DIR__ . '/services/AccessService.php';
    $accessService = new AccessService($pdo);
    $userId = $loggedUser ? (int)$loggedUser['id'] : 0;

    if (!$accessService->canAccessDocument($userId, $docId)) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Acesso negado. Sua conta não possui permissão para visualizar este arquivo.";
        exit;
    }

    // 3. Validação do Tipo de Conteúdo
    if (($doc['content_type'] ?? '') !== 'file' || empty($doc['stored_filename'])) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Este documento não possui um arquivo anexo.";
        exit;
    }

    // 4. Resolução Segura de Caminho (Storage Permitido)
    $storageDir = realpath(__DIR__ . '/storage/documents');
    if (!$storageDir) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Diretório de armazenamento não configurado.";
        exit;
    }

    $fileName = basename($doc['stored_filename']);
    $fullPath = $storageDir . DIRECTORY_SEPARATOR . $fileName;
    $realFilePath = realpath($fullPath);

    // Validação estrita de Traversal Path
    if (!$realFilePath || !file_exists($realFilePath) || strpos($realFilePath, $storageDir) !== 0) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "O arquivo físico deste documento não foi encontrado no servidor.";
        exit;
    }

    if (!is_readable($realFilePath)) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Permissão de leitura negada no arquivo do servidor.";
        exit;
    }

    $fileSize = filesize($realFilePath);
    $origName = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $doc['original_filename'] ?: 'documento.pdf');
    if (!str_ends_with(strtolower($origName), '.pdf')) {
        $origName .= '.pdf';
    }

    // Limpa completamente os buffers antes de enviar os bytes puros
    while (ob_get_level()) ob_end_clean();

    // 5. Envio dos Headers Adequados para Stream Inline
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $origName . '"');
    header('Content-Length: ' . $fileSize);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Pragma: public');

    readfile($realFilePath);
    exit;

} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro interno ao processar o arquivo.";
    exit;
}
