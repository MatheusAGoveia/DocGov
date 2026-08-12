<?php
require __DIR__ . '/../config/db.php';

function codeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$columnStmt = $pdo->query("
    SELECT column_default
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'documents' AND column_name = 'code_language'
");
$column = $columnStmt->fetch(PDO::FETCH_ASSOC);
codeAssert((bool)$column, 'A coluna documents.code_language não existe.');
codeAssert(str_contains((string)$column['column_default'], 'auto'), 'O padrão da linguagem deve ser auto.');

$constraintStmt = $pdo->query("
    SELECT pg_get_constraintdef(oid) AS definition
    FROM pg_constraint
    WHERE conrelid = 'documents'::regclass AND conname = 'documents_content_type_check'
");
$constraint = (string)$constraintStmt->fetchColumn();
codeAssert(str_contains($constraint, 'code'), 'A restrição de content_type não aceita code.');

$subjectId = (int)$pdo->query('SELECT id FROM subjects WHERE active = TRUE ORDER BY id LIMIT 1')->fetchColumn();
$adminId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
codeAssert($subjectId > 0 && $adminId > 0, 'É necessário um assunto ativo e um administrador para o teste.');

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        INSERT INTO documents (
            subject_id, created_by, title, slug, description, content_type, status,
            published_at, text_content, code_language
        ) VALUES (
            :subject_id, :created_by, :title, :slug, '', 'code', 'draft',
            NULL, :content, 'auto'
        ) RETURNING id
    ");
    $stmt->execute([
        ':subject_id' => $subjectId,
        ':created_by' => $adminId,
        ':title' => '__TEST_CODE_DOCUMENT__',
        ':slug' => '__test-code-document__-' . bin2hex(random_bytes(4)),
        ':content' => "const usuario = { nome: 'Ana' };\nconsole.log(usuario.nome);",
    ]);
    $documentId = (int)$stmt->fetchColumn();

    $readStmt = $pdo->prepare('SELECT content_type, status, published_at, text_content, code_language FROM documents WHERE id = ?');
    $readStmt->execute([$documentId]);
    $document = $readStmt->fetch(PDO::FETCH_ASSOC);
    codeAssert($document['content_type'] === 'code', 'O documento não foi salvo como código.');
    codeAssert($document['status'] === 'draft' && $document['published_at'] === null, 'Rascunhos não devem receber data de publicação.');
    codeAssert($document['code_language'] === 'auto', 'A linguagem automática não foi preservada.');
    codeAssert(str_contains($document['text_content'], 'console.log'), 'O código-fonte não foi preservado.');

    $updateStmt = $pdo->prepare("
        UPDATE documents
        SET status = 'published', published_at = CURRENT_TIMESTAMP, code_language = 'javascript'
        WHERE id = ?
    ");
    $updateStmt->execute([$documentId]);

    $readStmt->execute([$documentId]);
    $updated = $readStmt->fetch(PDO::FETCH_ASSOC);
    codeAssert($updated['status'] === 'published' && $updated['published_at'] !== null, 'A publicação não foi persistida.');
    codeAssert($updated['code_language'] === 'javascript', 'A escolha manual da linguagem não foi persistida.');

    echo "[OK] Documentos de código: migração, rascunho, publicação, conteúdo e linguagem.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
