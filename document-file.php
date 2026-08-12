<?php
// document-file.php — Endpoint protegido para visualização inline de arquivos.
ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/config/session.php';
docgovStartSession();

require_once __DIR__ . '/config/db.php';

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
    if (!in_array(($doc['content_type'] ?? ''), ['file', 'video'], true) || empty($doc['stored_filename'])) {
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
    $origName = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', basename($doc['original_filename'] ?: $fileName));
    $extension = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $mimeByExtension = [
        'pdf' => 'application/pdf',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
        'txt' => 'text/plain; charset=utf-8', 'log' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
        'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime',
    ];
    $responseMime = $mimeByExtension[$extension] ?? 'application/octet-stream';

    // Limpa completamente os buffers antes de enviar os bytes puros
    while (ob_get_level()) ob_end_clean();

    // 5. Envio dos headers adequados para visualização inline. O MIME é
    // determinado pelo arquivo/extensão permitida, nunca forçado para PDF.
    header('Content-Type: ' . $responseMime);
    header('Content-Disposition: inline; filename="' . $origName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Pragma: public');
    header('Accept-Ranges: bytes');

    $rangeStart = 0;
    $rangeEnd = $fileSize - 1;
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $rangeMatch)) {
        if ($rangeMatch[1] === '' && $rangeMatch[2] !== '') {
            $suffixLength = min((int)$rangeMatch[2], $fileSize);
            $rangeStart = max(0, $fileSize - $suffixLength);
        } else {
            $rangeStart = (int)$rangeMatch[1];
            if ($rangeMatch[2] !== '') $rangeEnd = min((int)$rangeMatch[2], $fileSize - 1);
        }

        if ($rangeStart > $rangeEnd || $rangeStart >= $fileSize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        http_response_code(206);
        header("Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$fileSize}");
    }

    $responseLength = $rangeEnd - $rangeStart + 1;
    header('Content-Length: ' . $responseLength);

    $fileHandle = fopen($realFilePath, 'rb');
    if ($fileHandle === false) {
        http_response_code(500);
        exit;
    }

    fseek($fileHandle, $rangeStart);
    $remainingBytes = $responseLength;
    while ($remainingBytes > 0 && !feof($fileHandle)) {
        $chunk = fread($fileHandle, min(8192, $remainingBytes));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remainingBytes -= strlen($chunk);
        flush();
    }
    fclose($fileHandle);

    exit;

} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro interno ao processar o arquivo.";
    exit;
}
