<?php

declare(strict_types=1);

require __DIR__ . '/../config/db.php';

function formattedEditorAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{status:int,html:string,stderr:string} */
function renderFormattedEditorPage(int $userId, array $params): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_admin_page.php')
        . ' ' . escapeshellarg((string)$userId)
        . ' admin '
        . escapeshellarg(base64_encode(json_encode($params, JSON_THROW_ON_ERROR)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Não foi possível renderizar a página administrativa.');
    $html = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $match);
    return ['status' => (int)($match[1] ?? 0), 'html' => $html, 'stderr' => $stderr];
}

$userId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
formattedEditorAssert($userId > 0, 'Nenhum administrador ativo disponível para o teste.');

$editorPage = renderFormattedEditorPage($userId, ['tab' => 'novo_documento', 'section' => 'documents']);
formattedEditorAssert($editorPage['status'] === 200, 'Formulário de conteúdo não respondeu HTTP 200.');
formattedEditorAssert(str_contains($editorPage['html'], '../assets/vendor/quill/quill.js'), 'JavaScript local do Quill não foi carregado.');
formattedEditorAssert(str_contains($editorPage['html'], '../assets/article-media-layout.js'), 'Editor de posição da mídia não foi carregado.');
formattedEditorAssert(str_contains($editorPage['html'], '../assets/vendor/quill/quill.snow.css'), 'CSS local do Quill não foi carregado.');
formattedEditorAssert(str_contains($editorPage['html'], 'id="quill-editor-container"'), 'Contêiner do editor formatado não foi renderizado.');
formattedEditorAssert(str_contains($editorPage['html'], 'id="quill-insert-table"'), 'Controle de tabela não foi renderizado.');
formattedEditorAssert(!str_contains(strtolower($editorPage['html']), 'richtexteditor'), 'A dependência comercial ainda aparece no formulário.');

$overviewPage = renderFormattedEditorPage($userId, ['tab' => 'visao_geral']);
formattedEditorAssert($overviewPage['status'] === 200, 'Visão geral não respondeu HTTP 200.');
formattedEditorAssert(!str_contains($overviewPage['html'], '../assets/vendor/quill/quill.js'), 'Quill foi carregado fora da tela de criação/edição.');

formattedEditorAssert(is_file(__DIR__ . '/../assets/vendor/quill/LICENSE'), 'Licença BSD do Quill não acompanha a distribuição.');
echo "[OK] Quill local, gratuito, isolado ao formulário e com suporte a tabela validado.\n";
