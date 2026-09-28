<?php

declare(strict_types=1);

define('DOCGOV_SKIP_APP_RUNTIME', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/AccessService.php';
require_once __DIR__ . '/../services/DocumentSectionService.php';
require_once __DIR__ . '/../services/StructuredContentService.php';
require_once __DIR__ . '/../services/VideoEmbedService.php';

$ids = [182 => 'file', 183 => 'flow', 184 => 'orgchart', 185 => 'video', 186 => 'code', 187 => 'link'];
$stmt = $pdo->prepare('SELECT d.*, s.slug AS subject_slug, sc.slug AS subcategory_slug, c.slug AS category_slug FROM documents d JOIN subjects s ON s.id = d.subject_id JOIN subcategories sc ON sc.id = s.subcategory_id JOIN categories c ON c.id = sc.category_id WHERE d.id = ?');
$access = new AccessService($pdo);
$sections = new DocumentSectionService($pdo);

foreach ($ids as $id => $type) {
    $stmt->execute([$id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document || $document['content_type'] !== $type || $document['status'] !== 'published' || !$document['published_at']) {
        throw new RuntimeException("Documento {$id} não foi publicado com o tipo esperado.");
    }
    if (!$access->canAccessDocument(168, $id)) {
        throw new RuntimeException("Leitora real não consegue visualizar o documento {$id}.");
    }
    if (!in_array($document['section_key'], array_column($sections->publishedSectionsForSubject((int)$document['subject_id']), 'section_key'), true)) {
        throw new RuntimeException("A aba pública do documento {$id} não foi criada.");
    }
    if (!in_array($document['section_key'], array_column($sections->existingSectionsForSubject((int)$document['subject_id']), 'section_key'), true)) {
        throw new RuntimeException("A aba do editor do documento {$id} não foi criada.");
    }
    $history = $pdo->prepare('SELECT action FROM document_workflow_history WHERE document_id = ? ORDER BY id');
    $history->execute([$id]);
    if ($history->fetchAll(PDO::FETCH_COLUMN) !== ['saved_as_draft', 'submitted_for_review', 'reviewed', 'approved_and_published']) {
        throw new RuntimeException("Histórico editorial incompleto no documento {$id}.");
    }
    if (in_array($type, ['flow', 'orgchart'], true)) {
        $normalized = StructuredContentService::normalize($type, (string)$document['structured_content']);
        if (count($normalized['nodes']) < 2) throw new RuntimeException("Estrutura insuficiente no documento {$id}.");
    }
    if ($type === 'file') {
        $path = dirname(__DIR__) . '/storage/documents/' . basename((string)$document['stored_filename']);
        $header = file_get_contents($path, false, null, 0, 5);
        if ($header !== '%PDF-' || filesize($path) !== (int)$document['file_size']) throw new RuntimeException('PDF protegido inválido.');
    }
    if ($type === 'video' && VideoEmbedService::resolve((string)$document['external_url'])['kind'] !== 'youtube') {
        throw new RuntimeException('Prévia do YouTube não foi reconhecida.');
    }
    if ($type === 'link' && VideoEmbedService::normalizeExternalUrl((string)$document['external_url']) === null) {
        throw new RuntimeException('Link externo inválido.');
    }
    if ($type === 'code' && ($document['code_language'] !== 'xml' || !str_contains((string)$document['text_content'], '<figure>'))) {
        throw new RuntimeException('Código copiável ou linguagem não persistiram.');
    }
    $url = 'index.php?' . http_build_query(['cat' => $document['category_slug'], 'subcat' => $document['subcategory_slug'], 'assunto' => $document['subject_slug'], 'section' => $document['section_key']]);
    echo "[OK] {$type} {$id}: {$url}\n";
}
