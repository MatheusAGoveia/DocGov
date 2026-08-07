<?php
/**
 * services/PermissionService.php
 * Motor Central de Autorização e Permissões do DocGov
 * 
 * Modelo: Inspired by Grafana Folder Permissions / Teams.
 * Regras:
 * 1. PRINCIPAIS: Usuário individual e Grupos aos quais pertence.
 * 2. RECURSOS HIERÁRQUICOS: Categoria -> Subcategoria -> Assunto -> Documento.
 * 3. NÍVEIS: none (0), view (1), edit (2), admin (3).
 * 4. MAIOR NÍVEL VENCE: MAX() entre todas as permissões (diretas, de grupos e herdadas).
 * 5. HERANÇA DESCENDENTE: Documento herda do Assunto; Assunto herda de Subcategoria e Categoria; Subcategoria herda de Categoria.
 * 6. GRUPOS ATIVOS: Apenas grupos com `active = TRUE` concedem permissões.
 * 7. ADMIN GLOBAL: `users.role = 'admin'` concede nível 'admin' (3) em todo o sistema.
 * 8. EXPLICABILIDADE DE FONTES: Retorna lista detalhada com a rastreabilidade da origem de cada regra aplicável.
 */

class PermissionService {
    private PDO $pdo;

    private const LEVEL_MAP = [
        'none'  => 0,
        'view'  => 1,
        'edit'  => 2,
        'admin' => 3,
    ];

    private const VALUE_MAP = [
        0 => 'none',
        1 => 'view',
        2 => 'edit',
        3 => 'admin',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Verifica se um usuário possui o papel de Admin Global (users.role = 'admin')
     */
    public function isGlobalAdmin(int $userId): bool {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ? AND active = TRUE");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();

        return $role === 'admin';
    }

    /**
     * Retorna a lista de IDs de grupos ATIVOS aos quais o usuário pertence
     */
    public function getActiveUserGroupIds(int $userId): array {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT g.id 
            FROM groups g
            JOIN user_groups ug ON g.id = ug.group_id
            WHERE ug.user_id = ? AND g.active = TRUE
        ");
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Obtém a permissão efetiva em uma Categoria
     */
    public function getEffectiveCategoryPermission(int $userId, int $categoryId): array {
        return $this->getEffectivePermission($userId, 'category', $categoryId);
    }

    /**
     * Obtém a permissão efetiva em uma Subcategoria
     */
    public function getEffectiveSubcategoryPermission(int $userId, int $subcategoryId): array {
        return $this->getEffectivePermission($userId, 'subcategory', $subcategoryId);
    }

    /**
     * Obtém a permissão efetiva em um Assunto
     */
    public function getEffectiveSubjectPermission(int $userId, int $subjectId): array {
        return $this->getEffectivePermission($userId, 'subject', $subjectId);
    }

    /**
     * Obtém a permissão efetiva em um Documento (herda do Assunto pai)
     */
    public function getEffectiveDocumentPermission(int $userId, int $documentId): array {
        return $this->getEffectivePermission($userId, 'document', $documentId);
    }

    /**
     * Motor Polimórfico Principal de Avaliação de Permissão Efetiva
     * 
     * @param int $userId ID do Usuário
     * @param string $resourceType 'category' | 'subcategory' | 'subject' | 'document'
     * @param int $resourceId ID do Recurso
     * @return array [ 'effective_level' => string, 'effective_value' => int, 'has_permission' => bool, 'sources' => array ]
     */
    public function getEffectivePermission(int $userId, string $resourceType, int $resourceId): array {
        $resourceType = strtolower(trim($resourceType));

        // 1. Validação básica de entrada
        if ($userId <= 0 || $resourceId <= 0) {
            return $this->buildResult('none', 0, []);
        }

        // 2. Admin Global Bypass
        if ($this->isGlobalAdmin($userId)) {
            $adminSource = [
                'principal_type'   => 'admin',
                'principal_id'     => $userId,
                'principal_name'   => 'Admin Global',
                'resource_type'    => $resourceType,
                'resource_id'      => $resourceId,
                'resource_name'    => 'Sistema',
                'permission_level' => 'admin',
                'permission_value' => 3,
                'is_inherited'     => false,
                'description'      => 'Admin global (users.role = admin)'
            ];
            return $this->buildResult('admin', 3, [$adminSource]);
        }

        // 3. Verificar se o usuário está ativo
        $stmtUser = $this->pdo->prepare("SELECT name FROM users WHERE id = ? AND active = TRUE");
        $stmtUser->execute([$userId]);
        $userName = $stmtUser->fetchColumn();
        if (!$userName) {
            return $this->buildResult('none', 0, []);
        }

        // 4. Mapear a cadeia de recursos ancestrais
        $resourceChain = $this->resolveResourceChain($resourceType, $resourceId);
        if (empty($resourceChain)) {
            return $this->buildResult('none', 0, []);
        }

        // 5. Obter grupos ativos do usuário
        $activeGroupIds = $this->getActiveUserGroupIds($userId);

        // 6. Consultar todas as regras na tabela 'permissions' aplicáveis aos principais e recursos da cadeia
        $sources = $this->queryApplicableRules($userId, $userName, $activeGroupIds, $resourceType, $resourceId, $resourceChain);

        // 7. Calcular o nível efetivo final via MAX()
        $maxValue = 0;
        foreach ($sources as $source) {
            if ($source['permission_value'] > $maxValue) {
                $maxValue = $source['permission_value'];
            }
        }

        $effectiveLevel = self::VALUE_MAP[$maxValue] ?? 'none';

        return $this->buildResult($effectiveLevel, $maxValue, $sources);
    }

    /**
     * Métodos Convenientes de Verificação Booleana (canView, canEdit, canAdmin)
     */
    public function canView(int $userId, string $resourceType, int $resourceId): bool {
        $result = $this->getEffectivePermission($userId, $resourceType, $resourceId);
        return $result['effective_value'] >= self::LEVEL_MAP['view'];
    }

    public function canEdit(int $userId, string $resourceType, int $resourceId): bool {
        $result = $this->getEffectivePermission($userId, $resourceType, $resourceId);
        return $result['effective_value'] >= self::LEVEL_MAP['edit'];
    }

    public function canAdmin(int $userId, string $resourceType, int $resourceId): bool {
        $result = $this->getEffectivePermission($userId, $resourceType, $resourceId);
        return $result['effective_value'] >= self::LEVEL_MAP['admin'];
    }

    /**
     * Resolve a cadeia de recursos (nó alvo + ancestrais de cima para baixo)
     */
    private function resolveResourceChain(string $resourceType, int $resourceId): array {
        $chain = [];

        if ($resourceType === 'document') {
            $stmt = $this->pdo->prepare("SELECT subject_id FROM documents WHERE id = ?");
            $stmt->execute([$resourceId]);
            $subjectId = $stmt->fetchColumn();
            if (!$subjectId) {
                return [];
            }
            // Documento herda 100% do Assunto pai
            return $this->resolveResourceChain('subject', (int)$subjectId);
        }

        if ($resourceType === 'subject') {
            $stmt = $this->pdo->prepare("
                SELECT s.id AS subject_id, s.name AS subject_name,
                       sc.id AS subcategory_id, sc.name AS subcategory_name,
                       c.id AS category_id, c.name AS category_name
                FROM subjects s
                JOIN subcategories sc ON s.subcategory_id = sc.id
                JOIN categories c ON sc.category_id = c.id
                WHERE s.id = ?
            ");
            $stmt->execute([$resourceId]);
            $row = $stmt->fetch();
            if (!$row) return [];

            $chain[] = ['type' => 'subject', 'id' => (int)$row['subject_id'], 'name' => $row['subject_name'], 'is_inherited' => false];
            $chain[] = ['type' => 'subcategory', 'id' => (int)$row['subcategory_id'], 'name' => $row['subcategory_name'], 'is_inherited' => true];
            $chain[] = ['type' => 'category', 'id' => (int)$row['category_id'], 'name' => $row['category_name'], 'is_inherited' => true];
        } elseif ($resourceType === 'subcategory') {
            $stmt = $this->pdo->prepare("
                SELECT sc.id AS subcategory_id, sc.name AS subcategory_name,
                       c.id AS category_id, c.name AS category_name
                FROM subcategories sc
                JOIN categories c ON sc.category_id = c.id
                WHERE sc.id = ?
            ");
            $stmt->execute([$resourceId]);
            $row = $stmt->fetch();
            if (!$row) return [];

            $chain[] = ['type' => 'subcategory', 'id' => (int)$row['subcategory_id'], 'name' => $row['subcategory_name'], 'is_inherited' => false];
            $chain[] = ['type' => 'category', 'id' => (int)$row['category_id'], 'name' => $row['category_name'], 'is_inherited' => true];
        } elseif ($resourceType === 'category') {
            $stmt = $this->pdo->prepare("SELECT id, name FROM categories WHERE id = ?");
            $stmt->execute([$resourceId]);
            $row = $stmt->fetch();
            if (!$row) return [];

            $chain[] = ['type' => 'category', 'id' => (int)$row['id'], 'name' => $row['name'], 'is_inherited' => false];
        }

        return $chain;
    }

    /**
     * Consulta as regras ativas no banco e formata a explicação das fontes de acesso
     */
    private function queryApplicableRules(
        int $userId,
        string $userName,
        array $activeGroupIds,
        string $targetResourceType,
        int $targetResourceId,
        array $resourceChain
    ): array {
        $sources = [];

        $categoryIds = [];
        $subcategoryIds = [];
        $subjectIds = [];

        foreach ($resourceChain as $item) {
            if ($item['type'] === 'category') $categoryIds[] = $item['id'];
            if ($item['type'] === 'subcategory') $subcategoryIds[] = $item['id'];
            if ($item['type'] === 'subject') $subjectIds[] = $item['id'];
        }

        // Montagem da query com parâmetros dinâmicos
        $whereClauseParts = [];
        $params = [];

        // Filtro de Principais (user_id OR active group_ids)
        $principalConditions = ["p.user_id = :user_id"];
        $params[':user_id'] = $userId;

        if (!empty($activeGroupIds)) {
            $groupPlaceholders = [];
            foreach ($activeGroupIds as $idx => $gid) {
                $ph = ":gid_$idx";
                $groupPlaceholders[] = $ph;
                $params[$ph] = $gid;
            }
            $principalConditions[] = "p.group_id IN (" . implode(',', $groupPlaceholders) . ")";
        }

        $whereClauseParts[] = "(" . implode(" OR ", $principalConditions) . ")";

        // Filtro de Recursos da Cadeia
        $resourceConditions = [];
        if (!empty($categoryIds)) {
            $resourceConditions[] = "p.category_id IN (" . implode(',', $categoryIds) . ")";
        }
        if (!empty($subcategoryIds)) {
            $resourceConditions[] = "p.subcategory_id IN (" . implode(',', $subcategoryIds) . ")";
        }
        if (!empty($subjectIds)) {
            $resourceConditions[] = "p.subject_id IN (" . implode(',', $subjectIds) . ")";
        }

        $whereClauseParts[] = "(" . implode(" OR ", $resourceConditions) . ")";

        $sql = "
            SELECT 
                p.id,
                p.user_id,
                p.group_id,
                p.category_id,
                p.subcategory_id,
                p.subject_id,
                p.permission_level,
                u.name AS user_name,
                g.name AS group_name,
                c.name AS category_name,
                sc.name AS subcategory_name,
                s.name AS subject_name
            FROM permissions p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN groups g ON p.group_id = g.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
            LEFT JOIN subjects s ON p.subject_id = s.id
            WHERE " . implode(" AND ", $whereClauseParts);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $principalType = !empty($row['user_id']) ? 'user' : 'group';
            $principalId = !empty($row['user_id']) ? (int)$row['user_id'] : (int)$row['group_id'];
            $principalName = !empty($row['user_id']) ? $row['user_name'] : $row['group_name'];

            $ruleResourceType = !empty($row['category_id']) ? 'category' : (!empty($row['subcategory_id']) ? 'subcategory' : 'subject');
            $ruleResourceId = !empty($row['category_id']) ? (int)$row['category_id'] : (!empty($row['subcategory_id']) ? (int)$row['subcategory_id'] : (int)$row['subject_id']);
            $ruleResourceName = !empty($row['category_id']) ? $row['category_name'] : (!empty($row['subcategory_id']) ? $row['subcategory_name'] : $row['subject_name']);

            // Verificar se a regra é herdada (se o recurso da regra é um ancestral e não o recurso alvo)
            $isInherited = !($ruleResourceType === $targetResourceType && $ruleResourceId === $targetResourceId);
            if ($targetResourceType === 'document') {
                $isInherited = true; // Documentos sempre herdam de um ancestral
            }

            $permLevel = strtolower($row['permission_level']);
            $permValue = self::LEVEL_MAP[$permLevel] ?? 0;

            // Formatação amigável da descrição da fonte de permissão
            $principalLabel = ($principalType === 'user') ? "acesso direto ($principalName)" : "Grupo $principalName";
            $resourceLabel = ucfirst($ruleResourceType) . " $ruleResourceName";
            $description = "$principalLabel / $resourceLabel / " . ucfirst($permLevel);

            $sources[] = [
                'principal_type'   => $principalType,
                'principal_id'     => $principalId,
                'principal_name'   => $principalName,
                'resource_type'    => $ruleResourceType,
                'resource_id'      => $ruleResourceId,
                'resource_name'    => $ruleResourceName,
                'permission_level' => $permLevel,
                'permission_value' => $permValue,
                'is_inherited'     => $isInherited,
                'description'      => $description
            ];
        }

        return $sources;
    }

    /**
     * Constrói a estrutura padronizada do resultado de autorização
     */
    private function buildResult(string $effectiveLevel, int $effectiveValue, array $sources): array {
        return [
            'effective_level' => $effectiveLevel,
            'effective_value' => $effectiveValue,
            'has_permission'  => ($effectiveValue >= 1),
            'sources'         => $sources
        ];
    }

    /**
     * Retorna a lista detalhada de permissões (DIRETAS e HERDADAS) configuradas em uma Categoria, Subcategoria ou Assunto.
     */
    public function getResourcePermissions(string $resourceType, int $resourceId): array {
        $chain = $this->resolveResourceChain($resourceType, $resourceId);
        
        $categoryId = null;
        $subcategoryId = null;
        $subjectId = null;
        $namesMap = [];

        foreach ($chain as $item) {
            $namesMap[$item['type']] = ['id' => $item['id'], 'name' => $item['name']];
            if ($item['type'] === 'category') $categoryId = $item['id'];
            if ($item['type'] === 'subcategory') $subcategoryId = $item['id'];
            if ($item['type'] === 'subject') $subjectId = $item['id'];
        }

        // Montar a query para buscar regras diretas e de ancestrais
        $conditions = [];
        $params = [];

        if ($categoryId) {
            $conditions[] = "p.category_id = ?";
            $params[] = $categoryId;
        }
        if ($subcategoryId) {
            $conditions[] = "p.subcategory_id = ?";
            $params[] = $subcategoryId;
        }
        if ($subjectId) {
            $conditions[] = "p.subject_id = ?";
            $params[] = $subjectId;
        }

        if (empty($conditions)) {
            return [];
        }

        $sql = "
            SELECT 
                p.id AS permission_id,
                p.user_id,
                p.group_id,
                p.category_id,
                p.subcategory_id,
                p.subject_id,
                p.permission_level,
                p.created_at,
                u.name AS user_name,
                u.username AS user_handle,
                u.email AS user_email,
                g.name AS group_name,
                (SELECT COUNT(*) FROM user_groups ug WHERE ug.group_id = g.id) AS group_members_count
            FROM permissions p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN groups g ON p.group_id = g.id
            WHERE " . implode(" OR ", $conditions) . "
            ORDER BY 
                CASE 
                    WHEN p.user_id IS NOT NULL THEN u.name 
                    ELSE g.name 
                END ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $principalType = !empty($row['user_id']) ? 'user' : 'group';
            $principalId = !empty($row['user_id']) ? (int)$row['user_id'] : (int)$row['group_id'];
            $principalName = !empty($row['user_id']) ? $row['user_name'] : $row['group_name'];
            $principalSubtext = !empty($row['user_id']) ? "@" . $row['user_handle'] : (int)$row['group_members_count'] . " membro(s)";

            // Determinar o recurso da regra atual
            $ruleResType = !empty($row['category_id']) ? 'category' : (!empty($row['subcategory_id']) ? 'subcategory' : 'subject');
            $ruleResId = !empty($row['category_id']) ? (int)$row['category_id'] : (!empty($row['subcategory_id']) ? (int)$row['subcategory_id'] : (int)$row['subject_id']);

            // É direta se pertencer exatamente ao recurso que estamos editando
            $isDirect = ($ruleResType === $resourceType && $ruleResId === $resourceId);

            $originLabel = 'Direta';
            $ancestorInfo = null;

            if (!$isDirect) {
                $ancestorName = $namesMap[$ruleResType]['name'] ?? "Pasta " . ucfirst($ruleResType);
                $originLabel = "Herdado de " . $ancestorName;
                $ancestorInfo = [
                    'type' => $ruleResType,
                    'id'   => $ruleResId,
                    'name' => $ancestorName
                ];
            }

            $results[] = [
                'permission_id'    => (int)$row['permission_id'],
                'principal_type'   => $principalType,
                'principal_id'     => $principalId,
                'principal_name'   => $principalName,
                'principal_subtext'=> $principalSubtext,
                'permission_level' => strtolower($row['permission_level']),
                'is_direct'        => $isDirect,
                'origin_label'     => $originLabel,
                'ancestor_info'    => $ancestorInfo,
                'resource_type'    => $ruleResType,
                'resource_id'      => $ruleResId,
            ];
        }

        return $results;
    }

    /**
     * Adiciona ou atualiza uma permissão em uma Categoria, Subcategoria ou Assunto (Upsert - Impede Duplicatas)
     */
    public function saveResourcePermission(string $resourceType, int $resourceId, ?int $userId, ?int $groupId, string $level, int $createdBy = 0): bool {
        $level = strtolower($level);
        if (!in_array($level, ['view', 'edit', 'admin'])) {
            throw new InvalidArgumentException("Nível de permissão inválido: $level");
        }

        if (($userId === null && $groupId === null) || ($userId !== null && $groupId !== null)) {
            throw new InvalidArgumentException("Informe exatamente UM principal: user_id OU group_id.");
        }

        $catId = ($resourceType === 'category') ? $resourceId : null;
        $subId = ($resourceType === 'subcategory') ? $resourceId : null;
        $subjId = ($resourceType === 'subject') ? $resourceId : null;

        // Verificar se já existe uma permissão DIRETA para este principal neste recurso
        $sqlCheck = "
            SELECT id FROM permissions 
            WHERE (user_id IS NOT DISTINCT FROM ?)
              AND (group_id IS NOT DISTINCT FROM ?)
              AND (category_id IS NOT DISTINCT FROM ?)
              AND (subcategory_id IS NOT DISTINCT FROM ?)
              AND (subject_id IS NOT DISTINCT FROM ?)
            LIMIT 1
        ";
        $stmtCheck = $this->pdo->prepare($sqlCheck);
        $stmtCheck->execute([$userId, $groupId, $catId, $subId, $subjId]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            // Atualiza o nível existente (UPSERT)
            $stmtUpd = $this->pdo->prepare("
                UPDATE permissions 
                SET permission_level = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            return $stmtUpd->execute([$level, $existingId]);
        } else {
            // Insere nova regra direta
            $stmtIns = $this->pdo->prepare("
                INSERT INTO permissions (user_id, group_id, category_id, subcategory_id, subject_id, permission_level, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            return $stmtIns->execute([$userId, $groupId, $catId, $subId, $subjId, $level, $createdBy > 0 ? $createdBy : null]);
        }
    }

    /**
     * Remove uma regra de permissão direta pelo ID
     */
    public function deletePermission(int $permissionId): bool {
        if ($permissionId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare("DELETE FROM permissions WHERE id = ?");
        return $stmt->execute([$permissionId]);
    }

    /**
     * Retorna a lista de permissões concedidas a um Grupo de Acesso específico (Visão do Grupo)
     */
    public function getGroupPermissions(int $groupId, bool $includeInherited = false): array {
        if ($groupId <= 0) {
            return [];
        }

        // 1. Buscar todas as regras diretas do grupo na tabela permissions
        $sql = "
            SELECT 
                p.id AS permission_id,
                p.category_id,
                p.subcategory_id,
                p.subject_id,
                p.permission_level,
                p.created_at,
                c.name AS category_name,
                sc.name AS subcategory_name,
                c_sub.name AS subcat_parent_cat_name,
                s.name AS subject_name,
                sc_subj.name AS subj_parent_subcat_name,
                c_subj.name AS subj_parent_cat_name
            FROM permissions p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
            LEFT JOIN categories c_sub ON sc.category_id = c_sub.id
            LEFT JOIN subjects s ON p.subject_id = s.id
            LEFT JOIN subcategories sc_subj ON s.subcategory_id = sc_subj.id
            LEFT JOIN categories c_subj ON sc_subj.category_id = c_subj.id
            WHERE p.group_id = ?
            ORDER BY p.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$groupId]);
        $directRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $directResourceKeys = [];

        foreach ($directRows as $row) {
            $resType = !empty($row['category_id']) ? 'category' : (!empty($row['subcategory_id']) ? 'subcategory' : 'subject');
            $resId = !empty($row['category_id']) ? (int)$row['category_id'] : (!empty($row['subcategory_id']) ? (int)$row['subcategory_id'] : (int)$row['subject_id']);

            $pathName = '';
            $typeLabel = '';

            if ($resType === 'category') {
                $pathName = $row['category_name'];
                $typeLabel = 'Categoria';
            } elseif ($resType === 'subcategory') {
                $pathName = $row['subcat_parent_cat_name'] . ' / ' . $row['subcategory_name'];
                $typeLabel = 'Subcategoria';
            } else {
                $pathName = $row['subj_parent_cat_name'] . ' / ' . $row['subj_parent_subcat_name'] . ' / ' . $row['subject_name'];
                $typeLabel = 'Assunto';
            }

            $key = "{$resType}_{$resId}";
            $directResourceKeys[$key] = true;

            $results[] = [
                'permission_id'   => (int)$row['permission_id'],
                'resource_type'   => $resType,
                'resource_id'     => $resId,
                'resource_path'   => $pathName,
                'resource_type_label' => $typeLabel,
                'permission_level'=> strtolower($row['permission_level']),
                'is_direct'       => true,
                'origin_label'    => 'Direta',
                'ancestor_info'   => null
            ];
        }

        // 2. Se includeInherited for verdadeiro, expandir os descendentes das regras de nível superior
        if ($includeInherited) {
            foreach ($directRows as $row) {
                $level = strtolower($row['permission_level']);

                // Se a regra é em Categoria, expandir subcategorias e assuntos
                if (!empty($row['category_id'])) {
                    $catId = (int)$row['category_id'];
                    $catName = $row['category_name'];

                    // Subcategorias da categoria
                    $stmtSubs = $this->pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? ORDER BY name ASC");
                    $stmtSubs->execute([$catId]);
                    $subs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($subs as $sub) {
                        $subKey = "subcategory_" . $sub['id'];
                        if (!isset($directResourceKeys[$subKey])) {
                            $results[] = [
                                'permission_id'   => 0,
                                'resource_type'   => 'subcategory',
                                'resource_id'     => (int)$sub['id'],
                                'resource_path'   => $catName . ' / ' . $sub['name'],
                                'resource_type_label' => 'Subcategoria',
                                'permission_level'=> $level,
                                'is_direct'       => false,
                                'origin_label'    => 'Herdado de ' . $catName,
                                'ancestor_info'   => ['type' => 'category', 'id' => $catId, 'name' => $catName]
                            ];
                        }

                        // Assuntos da subcategoria
                        $stmtSubjs = $this->pdo->prepare("SELECT id, name FROM subjects WHERE subcategory_id = ? ORDER BY name ASC");
                        $stmtSubjs->execute([$sub['id']]);
                        $subjs = $stmtSubjs->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($subjs as $subj) {
                            $subjKey = "subject_" . $subj['id'];
                            if (!isset($directResourceKeys[$subjKey])) {
                                $results[] = [
                                    'permission_id'   => 0,
                                    'resource_type'   => 'subject',
                                    'resource_id'     => (int)$subj['id'],
                                    'resource_path'   => $catName . ' / ' . $sub['name'] . ' / ' . $subj['name'],
                                    'resource_type_label' => 'Assunto',
                                    'permission_level'=> $level,
                                    'is_direct'       => false,
                                    'origin_label'    => 'Herdado de ' . $catName,
                                    'ancestor_info'   => ['type' => 'category', 'id' => $catId, 'name' => $catName]
                                ];
                            }
                        }
                    }
                }

                // Se a regra é em Subcategoria, expandir assuntos
                if (!empty($row['subcategory_id'])) {
                    $subId = (int)$row['subcategory_id'];
                    $subPath = $row['subcat_parent_cat_name'] . ' / ' . $row['subcategory_name'];

                    $stmtSubjs = $this->pdo->prepare("SELECT id, name FROM subjects WHERE subcategory_id = ? ORDER BY name ASC");
                    $stmtSubjs->execute([$subId]);
                    $subjs = $stmtSubjs->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($subjs as $subj) {
                        $subjKey = "subject_" . $subj['id'];
                        if (!isset($directResourceKeys[$subjKey])) {
                            $results[] = [
                                'permission_id'   => 0,
                                'resource_type'   => 'subject',
                                'resource_id'     => (int)$subj['id'],
                                'resource_path'   => $subPath . ' / ' . $subj['name'],
                                'resource_type_label' => 'Assunto',
                                'permission_level'=> $level,
                                'is_direct'       => false,
                                'origin_label'    => 'Herdado de ' . $row['subcategory_name'],
                                'ancestor_info'   => ['type' => 'subcategory', 'id' => $subId, 'name' => $row['subcategory_name']]
                            ];
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Retorna a árvore hierárquica compacta da estrutura (Categorias -> Subcategorias -> Assuntos) sem documentos
     */
    public function getResourceTree(): array {
        $tree = [];

        $stmtCats = $this->pdo->query("SELECT id, name FROM categories WHERE active = TRUE ORDER BY name ASC");
        $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

        foreach ($categories as $cat) {
            $catItem = [
                'type' => 'category',
                'id' => (int)$cat['id'],
                'name' => $cat['name'],
                'subcategories' => []
            ];

            $stmtSubs = $this->pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? AND active = TRUE ORDER BY name ASC");
            $stmtSubs->execute([$cat['id']]);
            $subcategories = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subcategories as $sub) {
                $subItem = [
                    'type' => 'subcategory',
                    'id' => (int)$sub['id'],
                    'name' => $sub['name'],
                    'subjects' => []
                ];

                $stmtSubjs = $this->pdo->prepare("SELECT id, name FROM subjects WHERE subcategory_id = ? AND active = TRUE ORDER BY name ASC");
                $stmtSubjs->execute([$sub['id']]);
                $subjects = $stmtSubjs->fetchAll(PDO::FETCH_ASSOC);

                foreach ($subjects as $subj) {
                    $subItem['subjects'][] = [
                        'type' => 'subject',
                        'id' => (int)$subj['id'],
                        'name' => $subj['name']
                    ];
                }

                $catItem['subcategories'][] = $subItem;
            }

            $tree[] = $catItem;
        }

        return $tree;
    }
}
