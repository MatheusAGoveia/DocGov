<?php
// Garante que nomes repetidos nunca enviem um documento ao ramo errado.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/HierarchyService.php';

function hierarchyAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function hierarchyId(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$suffix = bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $categoryA = hierarchyId($pdo, "INSERT INTO categories (name, slug, description, active) VALUES (?, ?, '', TRUE) RETURNING id", ["Categoria A $suffix", "cat-a-$suffix"]);
    $categoryB = hierarchyId($pdo, "INSERT INTO categories (name, slug, description, active) VALUES (?, ?, '', TRUE) RETURNING id", ["Categoria B $suffix", "cat-b-$suffix"]);
    $subcategoryA = hierarchyId($pdo, "INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$categoryA, 'Operações', "operacoes-$suffix"]);
    $subcategoryB = hierarchyId($pdo, "INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$categoryB, 'Operações', "operacoes-$suffix"]);
    $subjectA = hierarchyId($pdo, "INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$subcategoryA, 'Procedimentos', "procedimentos-$suffix"]);
    $subjectB = hierarchyId($pdo, "INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id", [$subcategoryB, 'Procedimentos', "procedimentos-$suffix"]);

    $service = new HierarchyService($pdo);
    $byId = $service->resolveActiveSubject((string)$subjectB, (string)$subcategoryB, (string)$categoryB);
    hierarchyAssert($byId !== null && $byId['id'] === $subjectB && $byId['category_id'] === $categoryB, 'Resolução por IDs escolheu o ramo incorreto.');

    $byContext = $service->resolveActiveSubject('Procedimentos', (string)$subcategoryA, (string)$categoryA);
    hierarchyAssert($byContext !== null && $byContext['id'] === $subjectA, 'Contexto completo não desambiguou nomes repetidos.');

    try {
        $service->resolveActiveSubject('Procedimentos');
        throw new RuntimeException('Nome ambíguo foi aceito sem o ramo completo.');
    } catch (InvalidArgumentException) {
        // Esperado.
    }

    $pdo->prepare('UPDATE categories SET active = FALSE WHERE id = ?')->execute([$categoryA]);
    hierarchyAssert($service->resolveActiveSubject((string)$subjectA, (string)$subcategoryA, (string)$categoryA) === null, 'Ramo inativo continuou disponível para novos documentos.');

    echo "[OK] Hierarquia: IDs, nomes repetidos, contexto e inatividade validados.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
