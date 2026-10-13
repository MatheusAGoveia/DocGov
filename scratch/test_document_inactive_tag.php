<?php
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../services/TagService.php';
require_once __DIR__ . '/../services/CsrfService.php';

function inactiveTagAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function inactiveTagInsert(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$suffix = bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $actorId = inactiveTagInsert($pdo, "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'admin', TRUE) RETURNING id", ['Teste de tags', "inactive.$suffix", "inactive.$suffix@example.invalid"]);
    $categoryId = inactiveTagInsert($pdo, 'INSERT INTO categories (name, slug) VALUES (?, ?) RETURNING id', ["Categoria $suffix", "categoria-$suffix"]);
    $subcategoryId = inactiveTagInsert($pdo, 'INSERT INTO subcategories (category_id, name, slug) VALUES (?, ?, ?) RETURNING id', [$categoryId, "Subcategoria $suffix", "subcategoria-$suffix"]);
    $subjectId = inactiveTagInsert($pdo, 'INSERT INTO subjects (subcategory_id, name, slug) VALUES (?, ?, ?) RETURNING id', [$subcategoryId, "Assunto $suffix", "assunto-$suffix"]);
    $tagName = "Tag desativada $suffix";
    $tags = new TagService($pdo);
    $tagId = $tags->create($tagName, 'topic', $actorId);
    $tags->setActive($tagId, false);
    docgovStartSession();
    $_SESSION['user'] = ['id' => $actorId, 'nome' => 'Teste', 'role' => 'admin', 'active' => true];
    $_SESSION['admin_logged'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = ['tab' => 'novo_documento'];
    $_POST = ['save_doc' => '1', 'csrf_token' => CsrfService::token(), 'titulo' => "Video preservado $suffix", 'descricao' => 'Descrição preservada',
        'categoria' => (string)$categoryId, 'subcategoria' => (string)$subcategoryId, 'assunto' => (string)$subjectId,
        'tipo_conteudo' => 'video', 'section_key' => 'videos', 'video_source' => 'url', 'video_url' => 'https://example.invalid/video.mp4',
        'workflow_action' => 'save_draft', 'new_tags' => [$tagName]];
    $_FILES = [];
    $inactiveTagFixtureSubjectId = $subjectId;
    ob_start();
    require __DIR__ . '/../admin/index.php';
    $html = ob_get_clean();
    inactiveTagAssert(str_contains($html, 'está desativada'), 'A tela não explicou a tag desativada.');
    inactiveTagAssert(!str_contains($html, 'Nenhuma alteração parcial foi mantida'), 'A tela exibiu o erro genérico.');
    inactiveTagAssert(str_contains($html, 'value="Video preservado ' . $suffix . '"'), 'Título não foi preservado.');
    inactiveTagAssert(str_contains($html, 'Descrição preservada'), 'Descrição não foi preservada.');
    inactiveTagAssert(str_contains($html, 'value="https://example.invalid/video.mp4"'), 'Vídeo não foi preservado.');
    inactiveTagAssert(str_contains($html, 'const initialNewDocumentTagNames = ["' . $tagName . '"]'), 'Tags digitadas não foram preservadas.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE subject_id = ?');
    $stmt->execute([$inactiveTagFixtureSubjectId]);
    inactiveTagAssert((int)$stmt->fetchColumn() === 0, 'Validação gravou um documento parcial.');
    inactiveTagAssert($tags->resolveName($tagName) === null, 'Validação reativou a tag.');
    echo "[OK] Tag desativada: mensagem específica, formulário preservado, nenhum documento parcial.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
