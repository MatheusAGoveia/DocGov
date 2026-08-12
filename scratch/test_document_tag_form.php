<?php
// Renderização transacional do formulário: garante o seletor de tags para quem cria conteúdo.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/TagService.php';

function tagFormAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$suffix = bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'admin', TRUE) RETURNING id");
    $stmt->execute(['Admin Formulário Tags', "tag.form.$suffix", "tag.form.$suffix@example.invalid"]);
    $adminId = (int)$stmt->fetchColumn();
    $tags = new TagService($pdo);
    $tags->create("Nutanix $suffix", 'technology', $adminId);
    $tags->create("Host $suffix", 'asset', $adminId);
    $categoryStmt = $pdo->prepare("INSERT INTO categories (name, slug, description, active) VALUES (?, ?, '', TRUE) RETURNING id");
    $categoryStmt->execute(["Categoria vazia $suffix", "categoria-vazia-$suffix"]);
    $emptyCategoryId = (int)$categoryStmt->fetchColumn();

    session_start();
    $_SESSION['user'] = [
        'id' => $adminId,
        'nome' => 'Admin Formulário Tags',
        'login' => "tag.form.$suffix",
        'email' => "tag.form.$suffix@example.invalid",
        'role' => 'admin',
        'active' => true,
        'inicial' => 'A',
    ];
    $_SESSION['admin_logged'] = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = ['tab' => 'novo_documento'];
    $_POST = [];

    ob_start();
    require __DIR__ . '/../admin/index.php';
    $html = ob_get_clean();
    tagFormAssert(str_contains($html, 'id="document-tag-input"'), 'Campo de tags não foi renderizado.');
    tagFormAssert(str_contains($html, 'id="document-tag-suggestions"'), 'Área de sugestões não foi renderizada.');
    tagFormAssert(str_contains($html, "Nutanix $suffix") && str_contains($html, "Host $suffix"), 'Catálogo ativo não foi disponibilizado ao formulário.');
    tagFormAssert(str_contains($html, 'initDocumentTags();'), 'Inicialização do seletor de tags não foi incluída.');
    tagFormAssert(str_contains($html, 'value="' . $emptyCategoryId . '"') && str_contains($html, "Categoria vazia $suffix"), 'Categoria ativa sem descendentes desapareceu do formulário de documento.');
    tagFormAssert(str_contains($html, 'id="document-hierarchy-helper"'), 'Orientação para completar a hierarquia não foi renderizada.');
    echo "[OK] Formulário de conteúdo: criação livre e sugestões de tags renderizadas.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
