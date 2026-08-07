<?php
// api/search_principals.php - Endpoint JSON para pesquisa ao vivo de Usuários e Grupos com detecção de permissão existente
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
if (!headers_sent()) {
    header('Content-Type: application/json');
}

$loggedUser = $_SESSION['user'] ?? null;
if (!$loggedUser || ($loggedUser['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores podem pesquisar principais para permissões.']);
    exit;
}

$query = trim($_GET['q'] ?? '');
$principalType = trim($_GET['type'] ?? 'group'); // 'user' ou 'group'
$resType = trim($_GET['resource_type'] ?? '');
$resId = (int)($_GET['resource_id'] ?? 0);

$catId = ($resType === 'category') ? $resId : null;
$subId = ($resType === 'subcategory') ? $resId : null;
$subjId = ($resType === 'subject') ? $resId : null;

$results = [];

try {
    if ($principalType === 'user') {
        $sql = "
            SELECT u.id, u.name, u.username, u.email,
                   (
                       SELECT p.permission_level 
                       FROM permissions p 
                       WHERE p.user_id = u.id 
                         AND (p.category_id IS NOT DISTINCT FROM :cat_id)
                         AND (p.subcategory_id IS NOT DISTINCT FROM :sub_id)
                         AND (p.subject_id IS NOT DISTINCT FROM :subj_id)
                       LIMIT 1
                   ) AS existing_level
            FROM users u
            WHERE u.active = TRUE
        ";
        $params = [
            ':cat_id'  => $catId,
            ':sub_id'  => $subId,
            ':subj_id' => $subjId,
        ];

        if (!empty($query)) {
            $sql .= " AND (u.name ILIKE :q OR u.username ILIKE :q OR u.email ILIKE :q)";
            $params[':q'] = '%' . $query . '%';
        }

        $sql .= " ORDER BY u.name ASC LIMIT 25";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $results[] = [
                'id'             => (int)$r['id'],
                'name'           => $r['name'],
                'type'           => 'user',
                'subtext'        => '@' . $r['username'] . ' • ' . $r['email'],
                'existing_level' => $r['existing_level'] ? strtolower($r['existing_level']) : null
            ];
        }
    } else {
        $sql = "
            SELECT g.id, g.name, g.description,
                   (
                       SELECT p.permission_level 
                       FROM permissions p 
                       WHERE p.group_id = g.id 
                         AND (p.category_id IS NOT DISTINCT FROM :cat_id)
                         AND (p.subcategory_id IS NOT DISTINCT FROM :sub_id)
                         AND (p.subject_id IS NOT DISTINCT FROM :subj_id)
                       LIMIT 1
                   ) AS existing_level
            FROM groups g
            WHERE g.active = TRUE
        ";
        $params = [
            ':cat_id'  => $catId,
            ':sub_id'  => $subId,
            ':subj_id' => $subjId,
        ];

        if (!empty($query)) {
            $sql .= " AND (g.name ILIKE :q OR g.description ILIKE :q)";
            $params[':q'] = '%' . $query . '%';
        }

        $sql .= " ORDER BY g.name ASC LIMIT 25";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $results[] = [
                'id'             => (int)$r['id'],
                'name'           => $r['name'],
                'type'           => 'group',
                'subtext'        => $r['description'] ?: 'Grupo de Acesso',
                'existing_level' => $r['existing_level'] ? strtolower($r['existing_level']) : null
            ];
        }
    }

    echo json_encode(['success' => true, 'data' => $results]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar principais: ' . $e->getMessage()]);
}
