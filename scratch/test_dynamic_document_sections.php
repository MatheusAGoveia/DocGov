<?php

declare(strict_types=1);

define('DOCGOV_SKIP_APP_RUNTIME', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/DocumentSectionService.php';
require_once __DIR__ . '/../services/RichTextSanitizer.php';
require_once __DIR__ . '/../services/StructuredContentService.php';

function dynamicSectionAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$actorId = (int)$pdo->query("SELECT id FROM users WHERE active = TRUE ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id LIMIT 1")->fetchColumn();
if ($actorId <= 0) throw new RuntimeException('Nenhum usuário ativo para executar o teste.');

$suffix = bin2hex(random_bytes(5));
$service = new DocumentSectionService($pdo);
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO categories (name, slug, active) VALUES (?, ?, TRUE) RETURNING id');
    $stmt->execute(["Categoria dinâmica {$suffix}", "categoria-dinamica-{$suffix}"]);
    $categoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug, active) VALUES (?, ?, ?, TRUE) RETURNING id');
    $stmt->execute([$categoryId, "Subcategoria dinâmica {$suffix}", "subcategoria-dinamica-{$suffix}"]);
    $subcategoryId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug, active) VALUES (?, ?, ?, TRUE) RETURNING id');
    $stmt->execute([$subcategoryId, "Assunto dinâmico {$suffix}", "assunto-dinamico-{$suffix}"]);
    $subjectId = (int)$stmt->fetchColumn();

    dynamicSectionAssert($service->publishedSectionsForSubject($subjectId) === [], 'Um assunto vazio exibiu seções públicas.');
    dynamicSectionAssert($service->existingSectionsForSubject($subjectId) === [], 'Um assunto vazio exibiu abas no editor.');

    $flow = StructuredContentService::normalize('flow', [
        'nodes' => [
            ['id' => 'inicio', 'type' => 'start', 'title' => 'Receber solicitação'],
            ['id' => 'validar', 'type' => 'decision', 'title' => 'Validar dados', 'owner' => 'Analista'],
            ['id' => 'fim', 'type' => 'end', 'title' => 'Concluir'],
        ],
    ]);
    $stmt = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, structured_content, status) VALUES (?, ?, ?, ?, 'flow', 'process-flow', CAST(? AS JSONB), 'draft') RETURNING id");
    $stmt->execute([$subjectId, $actorId, 'Fluxo de teste', "fluxo-{$suffix}", json_encode($flow)]);
    $flowId = (int)$stmt->fetchColumn();
    dynamicSectionAssert($service->publishedSectionsForSubject($subjectId) === [], 'Um rascunho criou uma seção pública.');
    $editorSections = $service->existingSectionsForSubject($subjectId);
    dynamicSectionAssert(array_column($editorSections, 'section_key') === ['process-flow'], 'O rascunho não criou a aba do seu tipo no editor.');
    dynamicSectionAssert((int)$editorSections[0]['published_count'] === 0, 'O rascunho foi contado como publicado.');

    $pdo->prepare("UPDATE documents SET status = 'published', published_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$flowId]);
    $sections = $service->publishedSectionsForSubject($subjectId);
    dynamicSectionAssert(array_column($sections, 'section_key') === ['process-flow'], 'Publicar o fluxo não criou sua seção automaticamente.');
    dynamicSectionAssert((int)$service->existingSectionsForSubject($subjectId)[0]['published_count'] === 1, 'A aba do editor não refletiu a publicação.');

    $orgChart = StructuredContentService::normalize('orgchart', [
        'nodes' => [
            ['id' => 'diretoria', 'name' => 'Diretoria', 'role' => 'Direção'],
            ['id' => 'equipe', 'parent_id' => 'diretoria', 'name' => 'Equipe Técnica', 'role' => 'Execução'],
        ],
    ]);
    dynamicSectionAssert($orgChart['nodes'][1]['parent_id'] === 'diretoria', 'A hierarquia do organograma não foi preservada.');
    $orgChart['nodes'][1]['role'] = 'Operação atualizada';
    $stmt = $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, structured_content, status, published_at) VALUES (?, ?, ?, ?, 'orgchart', 'organization-chart', CAST(? AS JSONB), 'published', CURRENT_TIMESTAMP) RETURNING id");
    $stmt->execute([$subjectId, $actorId, 'Organograma de teste', "organograma-{$suffix}", json_encode($orgChart)]);
    $orgChartId = (int)$stmt->fetchColumn();
    $sections = $service->publishedSectionsForSubject($subjectId);
    dynamicSectionAssert(array_column($sections, 'section_key') === ['process-flow', 'organization-chart'], 'O organograma publicado não criou a seção na ordem do catálogo.');

    $futureKey = "future-{$suffix}";
    $pdo->prepare("INSERT INTO document_sections (section_key, label, description, editor_kind, sort_order) VALUES (?, 'Seção futura', 'Teste genérico', 'richtext', 25)")->execute([$futureKey]);
    $pdo->prepare("INSERT INTO documents (subject_id, created_by, title, slug, content_type, section_key, text_content, status, published_at) VALUES (?, ?, 'Conteúdo futuro', ?, 'text', ?, '<p>Conteúdo</p>', 'published', CURRENT_TIMESTAMP)")->execute([$subjectId, $actorId, "futuro-{$suffix}", $futureKey]);
    $sections = $service->publishedSectionsForSubject($subjectId);
    dynamicSectionAssert(in_array($futureKey, array_column($sections, 'section_key'), true), 'Uma seção futura exigiu regra fixa no código.');
    dynamicSectionAssert(in_array($futureKey, array_column($service->existingSectionsForSubject($subjectId), 'section_key'), true), 'Uma seção futura não criou a aba no editor.');

    $sanitized = RichTextSanitizer::sanitize('<h2>Guia</h2><script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(2)"><p><strong>Seguro</strong></p><table><tr><td>Dado</td></tr></table>');
    dynamicSectionAssert(!str_contains($sanitized, '<script') && !str_contains($sanitized, 'onerror') && !str_contains($sanitized, 'javascript:'), 'O HTML rico manteve conteúdo executável.');
    dynamicSectionAssert(str_contains($sanitized, '<strong>Seguro</strong>') && str_contains($sanitized, '<table>'), 'O sanitizador removeu formatação rica permitida.');

    $quillSanitized = RichTextSanitizer::sanitize('<p class="ql-align-center classe-indevida" onclick="alert(1)">Centralizado</p><ol><li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>Item</li></ol>');
    dynamicSectionAssert(str_contains($quillSanitized, 'class="ql-align-center"'), 'O sanitizador removeu uma classe visual segura do Quill.');
    dynamicSectionAssert(str_contains($quillSanitized, 'data-list="bullet"'), 'O sanitizador removeu o tipo de lista do Quill.');
    dynamicSectionAssert(!str_contains($quillSanitized, 'classe-indevida') && !str_contains($quillSanitized, 'onclick'), 'O sanitizador manteve atributos ou classes não permitidos.');

    $pdo->prepare("UPDATE documents SET status = 'inactive', published_at = NULL WHERE id = ?")->execute([$flowId]);
    dynamicSectionAssert(!in_array('process-flow', array_column($service->publishedSectionsForSubject($subjectId), 'section_key'), true), 'A seção permaneceu após inativar seu último documento publicado.');
    dynamicSectionAssert(!in_array('process-flow', array_column($service->existingSectionsForSubject($subjectId), 'section_key'), true), 'A aba permaneceu após inativar o último documento do tipo.');
    $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$orgChartId]);
    dynamicSectionAssert(!in_array('organization-chart', array_column($service->publishedSectionsForSubject($subjectId), 'section_key'), true), 'A seção permaneceu após excluir seu último organograma.');
    dynamicSectionAssert(!in_array('organization-chart', array_column($service->existingSectionsForSubject($subjectId), 'section_key'), true), 'A aba permaneceu após excluir o último organograma.');

    echo "OK: CRUD estruturado, sanitização rica e seções automáticas/futuras validados.\n";
    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
