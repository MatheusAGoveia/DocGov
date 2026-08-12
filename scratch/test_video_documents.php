<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/VideoEmbedService.php';

function videoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$constraint = (string)$pdo->query("
    SELECT pg_get_constraintdef(oid)
    FROM pg_constraint
    WHERE conrelid = 'documents'::regclass
      AND conname = 'documents_content_type_check'
")->fetchColumn();
videoAssert(str_contains($constraint, 'video'), 'A restrição de content_type não aceita vídeo.');

$youtube = VideoEmbedService::resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
videoAssert($youtube['kind'] === 'youtube', 'O link do YouTube não foi reconhecido.');
videoAssert(($youtube['embed_url'] ?? '') === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'O embed seguro do YouTube está incorreto.');

$direct = VideoEmbedService::resolve('https://cdn.example.test/videos/guia.mp4?version=2');
videoAssert($direct['kind'] === 'direct', 'Uma URL direta de vídeo não foi reconhecida.');

$unknown = VideoEmbedService::resolve('https://portal.example.test/assistir/guia');
videoAssert($unknown['kind'] === 'external', 'Links externos sem player não receberam fallback seguro.');
videoAssert(VideoEmbedService::normalizeExternalUrl('javascript:alert(1)') === null, 'Esquema inseguro foi aceito como vídeo externo.');

$subjectId = (int)$pdo->query('SELECT id FROM subjects WHERE active = TRUE ORDER BY id LIMIT 1')->fetchColumn();
$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
videoAssert($subjectId > 0 && $userId > 0, 'Fixtures mínimas para teste de vídeo não foram encontradas.');

$token = bin2hex(random_bytes(6));
$documentId = 0;
try {
    $insert = $pdo->prepare("
        INSERT INTO documents (
            subject_id, created_by, title, slug, description, content_type, status, external_url
        ) VALUES (
            :subject_id, :created_by, :title, :slug, :description, 'video', 'draft', :external_url
        ) RETURNING id
    ");
    $insert->execute([
        ':subject_id' => $subjectId,
        ':created_by' => $userId,
        ':title' => 'Vídeo de teste ' . $token,
        ':slug' => 'video-de-teste-' . $token,
        ':description' => 'Fixture temporária para validar vídeo externo.',
        ':external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]);
    $documentId = (int)$insert->fetchColumn();

    $read = $pdo->prepare('SELECT content_type, status, external_url FROM documents WHERE id = :id');
    $read->execute([':id' => $documentId]);
    $document = $read->fetch(PDO::FETCH_ASSOC) ?: [];
    videoAssert(($document['content_type'] ?? '') === 'video', 'O documento não foi salvo como vídeo.');
    videoAssert(($document['status'] ?? '') === 'draft', 'O status do vídeo não foi preservado.');
    videoAssert(($document['external_url'] ?? '') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'A URL externa do vídeo não foi preservada.');

    echo "[OK] Vídeos: migração, URL segura, YouTube, mídia direta e persistência.\n";
} finally {
    if ($documentId > 0) {
        $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $documentId]);
    }
}
