<?php
// config/permissions.php - Motor Centralizado de Avaliação de Permissões Hierárquicas
require_once __DIR__ . '/db.php';

/**
 * Retorna os Grupos associados a um usuário (Relacionamento N:N)
 */
function getUserGroups($pdo, $userId) {
    if (!$userId) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT g.* 
            FROM grupos g 
            JOIN usuario_grupos ug ON g.id = ug.grupo_id 
            WHERE ug.usuario_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Retorna os IDs dos grupos de um usuário
 */
function getUserGroupIds($pdo, $userId) {
    $groups = getUserGroups($pdo, $userId);
    return array_column($groups, 'id');
}

/**
 * Avalia se o Usuário tem permissão para realizar uma Ação sobre um Alvo
 * Regra de Prioridade: NEGAR explicitamente (-1) > PERMITIR explicitamente (1) > HERDAR (0)
 *
 * @param PDO $pdo
 * @param array|null $user Array do Usuário Logado ($_SESSION['user'])
 * @param string $tipoAlvo 'categoria', 'subcategoria', 'assunto', 'documento'
 * @param string|array $targetContext Contexto contendo nome do alvo e ancestrais [categoria, subcategoria, assunto, id]
 * @param string $acao 'visualizar', 'baixar', 'criar', 'editar', 'excluir'
 * @return bool
 */
function canAccessUser($pdo, $user, $tipoAlvo, $targetContext, $acao = 'visualizar') {
    // 1. Visitante (sem login): Acesso de Leitura Geral
    if (!$user) {
        return $acao === 'visualizar' || $acao === 'baixar';
    }

    // 2. Admin Global: Acesso Total Ilimitado
    if (isset($user['role']) && $user['role'] === 'admin') {
        return true;
    }

    // 3. Usuário Inativo: Bloqueado
    if (isset($user['status']) && $user['status'] === 'inativo') {
        return false;
    }

    // Obter IDs dos Grupos do Usuário
    $groupIds = getUserGroupIds($pdo, $user['id']);
    if (empty($groupIds)) {
        // Se usuário não possui grupo, leitor pode apenas visualizar se for pública
        return ($user['role'] === 'leitor' || $user['role'] === 'editor') && ($acao === 'visualizar' || $acao === 'baixar');
    }

    $inQuery = implode(',', array_map('intval', $groupIds));

    // Monta a pilha de avaliação da hierarquia (do item específico para a categoria pai)
    $hierarchyStack = [];

    if (is_array($targetContext)) {
        $cat = $targetContext['categoria'] ?? '';
        $subcat = $targetContext['subcategoria'] ?? '';
        $assunto = $targetContext['assunto'] ?? '';
        $docId = $targetContext['id'] ?? '';

        if ($tipoAlvo === 'documento' && !empty($docId)) {
            $hierarchyStack[] = ['tipo' => 'documento', 'valor' => (string)$docId];
        }
        if (($tipoAlvo === 'documento' || $tipoAlvo === 'assunto') && !empty($assunto)) {
            $hierarchyStack[] = ['tipo' => 'assunto', 'valor' => $assunto];
        }
        if (($tipoAlvo === 'documento' || $tipoAlvo === 'assunto' || $tipoAlvo === 'subcategoria') && !empty($subcat)) {
            $hierarchyStack[] = ['tipo' => 'subcategoria', 'valor' => $subcat];
        }
        if (!empty($cat)) {
            $hierarchyStack[] = ['tipo' => 'categoria', 'valor' => $cat];
        }
    } else {
        $hierarchyStack[] = ['tipo' => $tipoAlvo, 'valor' => (string)$targetContext];
    }

    // Avalia do nível mais específico (Documento) ao mais genérico (Categoria)
    foreach ($hierarchyStack as $node) {
        $stmt = $pdo->prepare("
            SELECT estado 
            FROM permissoes_grupo 
            WHERE grupo_id IN ($inQuery) 
              AND tipo_alvo = ? 
              AND valor_alvo = ? 
              AND acao = ?
        ");
        $stmt->execute([$node['tipo'], $node['valor'], $acao]);
        $estados = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 1. NEGAR EXPLICITAMENTE (-1): Se qualquer grupo proíbe, BLOQUEIA IMEDIATAMENTE
        if (in_array(-1, $estados, true) || in_array('-1', $estados, true)) {
            return false;
        }

        // 2. PERMITIR EXPLICITAMENTE (1): Se algum grupo permite e nenhum nega, PERMITE
        if (in_array(1, $estados, true) || in_array('1', $estados, true)) {
            return true;
        }
    }

    // Se nenhuma regra explícita foi definida, aplica padrão baseado no perfil do usuário
    if ($user['role'] === 'leitor') {
        return ($acao === 'visualizar' || $acao === 'baixar');
    }

    if ($user['role'] === 'editor') {
        return ($acao === 'visualizar' || $acao === 'baixar' || $acao === 'criar' || $acao === 'editar');
    }

    return true;
}

/**
 * Filtra um array de itens (Categorias, Subcategorias, Assuntos ou Docs) retornando apenas os permitidos
 */
function filterAllowedItems($pdo, $user, $items, $tipoAlvo, $acao = 'visualizar') {
    if (!$user || (isset($user['role']) && $user['role'] === 'admin')) {
        return $items;
    }

    return array_values(array_filter($items, function($item) use ($pdo, $user, $tipoAlvo, $acao) {
        if ($tipoAlvo === 'categoria') {
            $context = ['categoria' => $item['categoria']];
        } elseif ($tipoAlvo === 'subcategoria') {
            $context = ['categoria' => $item['categoria'] ?? ($_GET['cat'] ?? ''), 'subcategoria' => $item['subcategoria']];
        } elseif ($tipoAlvo === 'assunto') {
            $context = ['categoria' => $_GET['cat'] ?? '', 'subcategoria' => $_GET['subcat'] ?? '', 'assunto' => $item['assunto']];
        } else {
            $context = $item;
        }

        return canAccessUser($pdo, $user, $tipoAlvo, $context, $acao);
    }));
}
