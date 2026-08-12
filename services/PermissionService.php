<?php
require_once __DIR__ . '/NotificationService.php';
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
     * Verifica se um usuário possui o papel exclusivo de Admin Global (users.role = 'admin').
     * Permissões de recurso ('admin' em Categoria/Subcategoria/Assunto) NÃO transformam o usuário em Admin Global.
     */
    public function isGlobalAdmin(?int $userId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ? AND active = TRUE");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();

        return strtolower(trim((string)$role)) === 'admin';
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

        // 2. O recurso precisa existir até para o Admin Global. Isso evita
        // autorizações positivas sobre IDs inválidos ou já removidos.
        $resourceChain = $this->resolveResourceChain($resourceType, $resourceId);
        if (empty($resourceChain)) {
            return $this->buildResult('none', 0, []);
        }

        // 3. Admin Global Bypass
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

        // 4. Verificar se o usuário está ativo
        $stmtUser = $this->pdo->prepare("SELECT name FROM users WHERE id = ? AND active = TRUE");
        $stmtUser->execute([$userId]);
        $userName = $stmtUser->fetchColumn();
        if (!$userName) {
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
     * Contexto público e somente leitura usado pela API e pela interface administrativa.
     */
    public function getResourceContext(string $resourceType, int $resourceId): ?array {
        $resourceType = strtolower(trim($resourceType));
        if (!in_array($resourceType, ['category', 'subcategory', 'subject'], true) || $resourceId <= 0) {
            return null;
        }

        $chain = $this->resolveResourceChain($resourceType, $resourceId);
        if (empty($chain)) {
            return null;
        }

        $orderedChain = array_reverse($chain);
        return [
            'type' => $resourceType,
            'id' => $resourceId,
            'name' => (string)($chain[0]['name'] ?? ''),
            'path' => implode(' > ', array_column($orderedChain, 'name')),
            'ancestors' => array_values(array_map(
                static fn(array $item): array => [
                    'type' => $item['type'],
                    'id' => (int)$item['id'],
                    'name' => (string)$item['name'],
                ],
                array_filter($orderedChain, static fn(array $item): bool => (bool)$item['is_inherited'])
            )),
        ];
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
            $principalLabel = ($principalType === 'user') ? "acesso direto ($principalName)" : "Equipe $principalName";
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
                u.active AS user_active,
                g.name AS group_name,
                g.active AS group_active,
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
            $principalActive = !empty($row['user_id'])
                ? filter_var($row['user_active'], FILTER_VALIDATE_BOOLEAN)
                : filter_var($row['group_active'], FILTER_VALIDATE_BOOLEAN);

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
                'principal_active' => $principalActive,
                'permission_level' => strtolower($row['permission_level']),
                'is_direct'        => $isDirect,
                'origin_label'     => $originLabel,
                'ancestor_info'    => $ancestorInfo,
                'resource_type'    => $ruleResType,
                'resource_id'      => $ruleResId,
                'effective_level'  => $principalActive ? strtolower($row['permission_level']) : 'none',
                'conflict_warning' => null,
            ];
        }

        // Para usuários, a permissão efetiva é obtida pelo próprio motor (inclui equipes e herança).
        // Para equipes, este cálculo serve apenas à explicação visual das regras do mesmo principal.
        $groupMaximums = [];
        foreach ($results as $permission) {
            if ($permission['principal_type'] !== 'group' || !$permission['principal_active']) {
                continue;
            }
            $key = (int)$permission['principal_id'];
            $value = self::LEVEL_MAP[$permission['permission_level']] ?? 0;
            $groupMaximums[$key] = max($groupMaximums[$key] ?? 0, $value);
        }

        foreach ($results as &$permission) {
            if (!$permission['principal_active']) {
                $permission['effective_level'] = 'none';
                continue;
            }

            if ($permission['principal_type'] === 'user') {
                $effective = $this->getEffectivePermission((int)$permission['principal_id'], $resourceType, $resourceId);
                $permission['effective_level'] = $effective['effective_level'];

                $directValue = self::LEVEL_MAP[$permission['permission_level']] ?? 0;
                if ($permission['is_direct'] && $effective['effective_value'] > $directValue) {
                    $strongestSource = null;
                    foreach ($effective['sources'] as $source) {
                        if (($source['permission_value'] ?? 0) === $effective['effective_value']) {
                            $strongestSource = $source;
                            break;
                        }
                    }
                    $origin = $strongestSource['resource_name'] ?? 'um recurso ancestral ou equipe';
                    $permission['conflict_warning'] = sprintf(
                        'Esta permissão direta não reduzirá o acesso efetivo, pois este usuário já possui %s por %s.',
                        ucfirst($effective['effective_level']),
                        $origin
                    );
                }
            } else {
                $effectiveValue = $groupMaximums[(int)$permission['principal_id']] ?? 0;
                $permission['effective_level'] = self::VALUE_MAP[$effectiveValue] ?? 'none';
                $directValue = self::LEVEL_MAP[$permission['permission_level']] ?? 0;
                if ($permission['is_direct'] && $effectiveValue > $directValue) {
                    $permission['conflict_warning'] = sprintf(
                        'Esta permissão direta não reduzirá o acesso efetivo, pois esta equipe já herda %s de um recurso ancestral.',
                        ucfirst($permission['effective_level'])
                    );
                }
            }
        }
        unset($permission);

        return $results;
    }

    /**
     * Adiciona ou atualiza uma permissão em uma Categoria, Subcategoria ou Assunto (Upsert - Impede Duplicatas) com Auditoria
     */
    public function saveResourcePermission(string $resourceType, int $resourceId, ?int $userId, ?int $groupId, string $level, int $createdBy = 0): bool {
        $resourceType = strtolower(trim($resourceType));
        $level = strtolower($level);
        if (!in_array($resourceType, ['category', 'subcategory', 'subject'], true) || $this->getResourceContext($resourceType, $resourceId) === null) {
            throw new InvalidArgumentException('Recurso inválido ou inexistente.');
        }
        if (!in_array($level, ['view', 'edit', 'admin'], true)) {
            throw new InvalidArgumentException("Nível de permissão inválido: $level");
        }

        if (($userId === null && $groupId === null) || ($userId !== null && $groupId !== null)) {
            throw new InvalidArgumentException("Informe exatamente UM principal: user_id OU group_id.");
        }

        $catId = ($resourceType === 'category') ? $resourceId : null;
        $subId = ($resourceType === 'subcategory') ? $resourceId : null;
        $subjId = ($resourceType === 'subject') ? $resourceId : null;

        $principalType = ($userId !== null) ? 'USER' : 'TEAM';
        $principalId = ($userId !== null) ? $userId : $groupId;
        $resTypeUpper = strtoupper($resourceType);

        $principalTable = ($userId !== null) ? 'users' : 'groups';
        $principalColumns = ($userId !== null) ? 'id, active, role' : "id, active, NULL::varchar AS role";
        $stmtPrincipal = $this->pdo->prepare("SELECT {$principalColumns} FROM {$principalTable} WHERE id = ?");
        $stmtPrincipal->execute([$principalId]);
        $principal = $stmtPrincipal->fetch(PDO::FETCH_ASSOC);
        if (!$principal) {
            throw new InvalidArgumentException('Usuário ou equipe inexistente.');
        }
        if ($userId !== null && strtolower((string)($principal['role'] ?? '')) === 'admin') {
            throw new InvalidArgumentException('Administradores Globais já possuem acesso completo e não recebem regras individuais.');
        }

        if ($createdBy <= 0) {
            throw new InvalidArgumentException('O usuário executor é obrigatório para auditar a permissão.');
        }
        $actorStmt = $this->pdo->prepare('SELECT id FROM users WHERE id = ? AND active = TRUE');
        $actorStmt->execute([$createdBy]);
        if (!$actorStmt->fetchColumn()) {
            throw new InvalidArgumentException('O usuário executor não existe ou está inativo.');
        }

        $startedTransaction = !$this->pdo->inTransaction();
        $permissionChanged = false;
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Serializa gravações da mesma combinação principal/recurso. Sem
            // esse lock, duas requisições simultâneas poderiam ambas não ver a
            // linha e uma delas falhar no índice único em vez de fazer upsert.
            $lockIdentity = implode(':', [
                $principalType,
                (int)$principalId,
                $resTypeUpper,
                $resourceId,
            ]);
            $lockKey = crc32($lockIdentity);
            if ($lockKey > 2147483647) {
                $lockKey -= 4294967296;
            }
            $lockStmt = $this->pdo->prepare('SELECT pg_advisory_xact_lock(736421, :lock_key)');
            $lockStmt->bindValue(':lock_key', $lockKey, PDO::PARAM_INT);
            $lockStmt->execute();

            // Verificar se já existe uma permissão DIRETA para este principal neste recurso.
            $stmtCheck = $this->pdo->prepare("
                SELECT id, permission_level FROM permissions
                WHERE (user_id IS NOT DISTINCT FROM ?)
                  AND (group_id IS NOT DISTINCT FROM ?)
                  AND (category_id IS NOT DISTINCT FROM ?)
                  AND (subcategory_id IS NOT DISTINCT FROM ?)
                  AND (subject_id IS NOT DISTINCT FROM ?)
                LIMIT 1
                FOR UPDATE
            ");
            $stmtCheck->execute([$userId, $groupId, $catId, $subId, $subjId]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            if (!$existing && !filter_var($principal['active'], FILTER_VALIDATE_BOOLEAN)) {
                throw new InvalidArgumentException('Não é possível criar uma permissão para um usuário ou equipe inativa.');
            }

            if ($existing) {
                $existingId = (int)$existing['id'];
                $oldLevel = strtolower($existing['permission_level']);
                if ($oldLevel === $level) {
                    if ($startedTransaction) {
                        $this->pdo->commit();
                    }
                    return true;
                }

                $stmtUpd = $this->pdo->prepare("
                    UPDATE permissions
                    SET permission_level = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                if (!$stmtUpd->execute([$level, $existingId])) {
                    throw new RuntimeException('Falha ao atualizar a permissão.');
                }
                $permissionChanged = true;
                $this->logAudit(
                    $createdBy,
                    'PERMISSION_CHANGED',
                    $principalType,
                    $principalId,
                    $resTypeUpper,
                    $resourceId,
                    $oldLevel,
                    $level,
                    $ipAddress
                );
            } else {
                $stmtIns = $this->pdo->prepare("
                    INSERT INTO permissions (user_id, group_id, category_id, subcategory_id, subject_id, permission_level, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmtIns->execute([$userId, $groupId, $catId, $subId, $subjId, $level, $createdBy])) {
                    throw new RuntimeException('Falha ao criar a permissão.');
                }
                $permissionChanged = true;
                $this->logAudit(
                    $createdBy,
                    'PERMISSION_CREATED',
                    $principalType,
                    $principalId,
                    $resTypeUpper,
                    $resourceId,
                    null,
                    $level,
                    $ipAddress
                );
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }
            if ($permissionChanged) {
                $this->notifyPermissionGranted($resourceType, $resourceId, $userId, $groupId, $level);
            }
            return true;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Avisa usuários que receberam ou tiveram acesso direto/equipe atualizado. */
    private function notifyPermissionGranted(string $resourceType, int $resourceId, ?int $userId, ?int $groupId, string $level): void {
        try {
            $context = $this->getResourceContext($resourceType, $resourceId);
            if ($context === null) {
                return;
            }

            $recipientIds = [];
            if ($userId !== null) {
                $recipientIds[] = $userId;
            } elseif ($groupId !== null) {
                $stmt = $this->pdo->prepare('
                    SELECT u.id
                    FROM users u
                    JOIN user_groups ug ON ug.user_id = u.id
                    JOIN groups g ON g.id = ug.group_id
                    WHERE ug.group_id = :group_id AND u.active = TRUE AND g.active = TRUE
                ');
                $stmt->execute([':group_id' => $groupId]);
                $recipientIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            if (empty($recipientIds)) {
                return;
            }

            $levelLabel = ['view' => 'visualização', 'edit' => 'edição', 'admin' => 'administração'][$level] ?? $level;
            $typeLabel = ['category' => 'categoria', 'subcategory' => 'subcategoria', 'subject' => 'assunto'][$resourceType] ?? 'recurso';
            $notifications = new NotificationService($this->pdo);
            $notifications->createForUsers(
                $recipientIds,
                'permission_granted',
                'Novo acesso liberado',
                'Você recebeu acesso de ' . $levelLabel . ' ao ' . $typeLabel . ' “' . $context['path'] . '”.'
            );
        } catch (Throwable $exception) {
            error_log('DocGov: não foi possível criar notificação de acesso: ' . $exception->getMessage());
        }
    }

    /**
     * Remove uma regra de permissão direta pelo ID com auditoria
     */
    public function deletePermission(int $permissionId, int $actorUserId = 0): bool {
        if ($permissionId <= 0 || $actorUserId <= 0) {
            return false;
        }

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmtFetch = $this->pdo->prepare("SELECT * FROM permissions WHERE id = ? FOR UPDATE");
            $stmtFetch->execute([$permissionId]);
            $rule = $stmtFetch->fetch(PDO::FETCH_ASSOC);
            if (!$rule) {
                if ($startedTransaction) {
                    $this->pdo->commit();
                }
                return false;
            }

            $stmt = $this->pdo->prepare("DELETE FROM permissions WHERE id = ?");
            if (!$stmt->execute([$permissionId]) || $stmt->rowCount() !== 1) {
                throw new RuntimeException('Falha ao remover a permissão.');
            }

            $principalType = !empty($rule['user_id']) ? 'USER' : 'TEAM';
            $principalId = !empty($rule['user_id']) ? (int)$rule['user_id'] : (int)$rule['group_id'];

            $resType = !empty($rule['category_id']) ? 'CATEGORY' : (!empty($rule['subcategory_id']) ? 'SUBCATEGORY' : 'SUBJECT');
            $resId = !empty($rule['category_id']) ? (int)$rule['category_id'] : (!empty($rule['subcategory_id']) ? (int)$rule['subcategory_id'] : (int)$rule['subject_id']);

            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $this->logAudit(
                $actorUserId,
                'PERMISSION_REMOVED',
                $principalType,
                $principalId,
                $resType,
                $resId,
                strtolower($rule['permission_level']),
                null,
                $ipAddress
            );

            if ($startedTransaction) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Registra evento na tabela permission_audit
     */
    private function logAudit(
        int $userId,
        string $action,
        string $principalType,
        int $principalId,
        string $resourceType,
        int $resourceId,
        ?string $oldPermission,
        ?string $newPermission,
        ?string $ipAddress
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO permission_audit
            (user_id, action, principal_type, principal_id, resource_type, resource_id, old_permission, new_permission, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $principalType,
            $principalId,
            $resourceType,
            $resourceId,
            $oldPermission,
            $newPermission,
            $ipAddress
        ]);
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
        $inheritedResultIndexes = [];
        $levelWeight = ['view' => 1, 'edit' => 2, 'admin' => 3];

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
                            $candidate = [
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
                            if (!isset($inheritedResultIndexes[$subKey])) {
                                $inheritedResultIndexes[$subKey] = count($results);
                                $results[] = $candidate;
                            } elseif (($levelWeight[$level] ?? 0) > ($levelWeight[$results[$inheritedResultIndexes[$subKey]]['permission_level']] ?? 0)) {
                                $results[$inheritedResultIndexes[$subKey]] = $candidate;
                            }
                        }

                        // Assuntos da subcategoria
                        $stmtSubjs = $this->pdo->prepare("SELECT id, name FROM subjects WHERE subcategory_id = ? ORDER BY name ASC");
                        $stmtSubjs->execute([$sub['id']]);
                        $subjs = $stmtSubjs->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($subjs as $subj) {
                            $subjKey = "subject_" . $subj['id'];
                            if (!isset($directResourceKeys[$subjKey])) {
                                $candidate = [
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
                                if (!isset($inheritedResultIndexes[$subjKey])) {
                                    $inheritedResultIndexes[$subjKey] = count($results);
                                    $results[] = $candidate;
                                } elseif (($levelWeight[$level] ?? 0) > ($levelWeight[$results[$inheritedResultIndexes[$subjKey]]['permission_level']] ?? 0)) {
                                    $results[$inheritedResultIndexes[$subjKey]] = $candidate;
                                }
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
                            $candidate = [
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
                            if (!isset($inheritedResultIndexes[$subjKey])) {
                                $inheritedResultIndexes[$subjKey] = count($results);
                                $results[] = $candidate;
                            } elseif (($levelWeight[$level] ?? 0) > ($levelWeight[$results[$inheritedResultIndexes[$subjKey]]['permission_level']] ?? 0)) {
                                $results[$inheritedResultIndexes[$subjKey]] = $candidate;
                            }
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
    public function getResourceTree(bool $activeOnly = true): array {
        $tree = [];

        $activeFilter = $activeOnly ? ' WHERE active = TRUE' : '';
        $stmtCats = $this->pdo->query("SELECT id, name FROM categories{$activeFilter} ORDER BY name ASC");
        $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

        foreach ($categories as $cat) {
            $catItem = [
                'type' => 'category',
                'id' => (int)$cat['id'],
                'name' => $cat['name'],
                'subcategories' => []
            ];

            $subActiveFilter = $activeOnly ? ' AND active = TRUE' : '';
            $stmtSubs = $this->pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ?{$subActiveFilter} ORDER BY name ASC");
            $stmtSubs->execute([$cat['id']]);
            $subcategories = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subcategories as $sub) {
                $subItem = [
                    'type' => 'subcategory',
                    'id' => (int)$sub['id'],
                    'name' => $sub['name'],
                    'subjects' => []
                ];

                $subjectActiveFilter = $activeOnly ? ' AND active = TRUE' : '';
                $stmtSubjs = $this->pdo->prepare("SELECT id, name FROM subjects WHERE subcategory_id = ?{$subjectActiveFilter} ORDER BY name ASC");
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

    /**
     * Calcula e explica todos os acessos efetivos de um usuário para fins de suporte e diagnóstico.
     * NÃO salva nada no banco e NÃO duplica herança.
     */
    public function getUserEffectiveAccessDiagnosis(int $userId, string $filter = 'all'): array {
        if ($userId <= 0) {
            return [
                'user' => null,
                'is_global_admin' => false,
                'active_groups' => [],
                'resources' => []
            ];
        }

        // 1. Buscar dados do usuário
        $stmtU = $this->pdo->prepare("SELECT id, name, username, email, role, active FROM users WHERE id = ?");
        $stmtU->execute([$userId]);
        $userData = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            return [
                'user' => null,
                'is_global_admin' => false,
                'active_groups' => [],
                'resources' => []
            ];
        }

        if (!filter_var($userData['active'], FILTER_VALIDATE_BOOLEAN)) {
            return [
                'user' => $userData,
                'is_global_admin' => false,
                'active_groups' => [],
                'resources' => [],
            ];
        }

        // 2. Se for Admin Global
        if ($this->isGlobalAdmin($userId)) {
            $stmtG = $this->pdo->prepare("
                SELECT g.id, g.name, g.description
                FROM groups g
                JOIN user_groups ug ON g.id = ug.group_id
                WHERE ug.user_id = ? AND g.active = TRUE
                ORDER BY g.name ASC
            ");
            $stmtG->execute([$userId]);
            $activeGroups = $stmtG->fetchAll(PDO::FETCH_ASSOC);

            return [
                'user' => $userData,
                'is_global_admin' => true,
                'active_groups' => $activeGroups,
                'resources' => []
            ];
        }

        // 3. Buscar grupos ATIVOS do usuário
        $stmtG = $this->pdo->prepare("
            SELECT g.id, g.name, g.description
            FROM groups g
            JOIN user_groups ug ON g.id = ug.group_id
            WHERE ug.user_id = ? AND g.active = TRUE
            ORDER BY g.name ASC
        ");
        $stmtG->execute([$userId]);
        $activeGroups = $stmtG->fetchAll(PDO::FETCH_ASSOC);
        $activeGroupIds = array_column($activeGroups, 'id');

        // 4. Mapear regras diretas do usuário e regras dos grupos ATIVOS
        $whereClauses = ["p.user_id = ?"];
        $params = [$userId];

        if (!empty($activeGroupIds)) {
            $inClause = implode(',', array_fill(0, count($activeGroupIds), '?'));
            $whereClauses[] = "p.group_id IN ($inClause)";
            $params = array_merge($params, $activeGroupIds);
        }

        $whereSql = implode(' OR ', $whereClauses);

        $sql = "
            SELECT 
                p.id AS permission_id,
                p.user_id,
                p.group_id,
                p.category_id,
                p.subcategory_id,
                p.subject_id,
                p.permission_level,
                g.name AS group_name
            FROM permissions p
            LEFT JOIN groups g ON p.group_id = g.id
            WHERE ($whereSql)
        ";

        $stmtP = $this->pdo->prepare($sql);
        $stmtP->execute($params);
        $appliedRules = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        // 5. Carregar toda a estrutura documental ativa (Categorias -> Subcategorias -> Assuntos)
        $structure = $this->getResourceTree();

        $resourceDiagnoses = [];

        foreach ($structure as $cat) {
            $catId = $cat['id'];
            $catPath = $cat['name'];

            // Analisar Categoria
            $catSources = [];
            foreach ($appliedRules as $rule) {
                if (!empty($rule['category_id']) && (int)$rule['category_id'] === $catId) {
                    $isUser = !empty($rule['user_id']);
                    $catSources[] = [
                        'type' => $isUser ? 'direct' : 'group',
                        'via_group' => !$isUser,
                        'level' => strtolower($rule['permission_level']),
                        'description' => $isUser ? "Acesso direto na Categoria {$cat['name']}" : "Equipe {$rule['group_name']} → " . ucfirst(strtolower($rule['permission_level'])) . " na Categoria {$cat['name']}"
                    ];
                }
            }

            if (!empty($catSources)) {
                $diag = $this->buildResourceDiagnosis('category', $catId, $catPath, 'Categoria', $catSources, $filter);
                if ($diag) $resourceDiagnoses[] = $diag;
            }

            foreach ($cat['subcategories'] as $sub) {
                $subId = $sub['id'];
                $subPath = "{$cat['name']} / {$sub['name']}";

                $subSources = [];
                // 1. Regras diretas da Categoria (herança para Subcategoria)
                foreach ($catSources as $cs) {
                    $subSources[] = [
                        'type' => 'inherited',
                        'via_group' => !empty($cs['via_group']),
                        'level' => $cs['level'],
                        'description' => "Herdado da Categoria {$cat['name']} (" . $cs['description'] . ")"
                    ];
                }
                // 2. Regras diretas da Subcategoria
                foreach ($appliedRules as $rule) {
                    if (!empty($rule['subcategory_id']) && (int)$rule['subcategory_id'] === $subId) {
                        $isUser = !empty($rule['user_id']);
                        $subSources[] = [
                            'type' => $isUser ? 'direct' : 'group',
                            'via_group' => !$isUser,
                            'level' => strtolower($rule['permission_level']),
                            'description' => $isUser ? "Acesso direto na Subcategoria {$sub['name']}" : "Equipe {$rule['group_name']} → " . ucfirst(strtolower($rule['permission_level'])) . " na Subcategoria {$sub['name']}"
                        ];
                    }
                }

                if (!empty($subSources)) {
                    $diag = $this->buildResourceDiagnosis('subcategory', $subId, $subPath, 'Subcategoria', $subSources, $filter);
                    if ($diag) $resourceDiagnoses[] = $diag;
                }

                foreach ($sub['subjects'] as $subj) {
                    $subjId = $subj['id'];
                    $subjPath = "{$cat['name']} / {$sub['name']} / {$subj['name']}";

                    $subjSources = [];
                    // 1. Regras da Subcategoria (herdadas ou diretas na Subcat/Cat)
                    foreach ($subSources as $ss) {
                        $subjSources[] = [
                            'type' => 'inherited',
                            'via_group' => !empty($ss['via_group']),
                            'level' => $ss['level'],
                            'description' => "Herdado de ancestral (" . $ss['description'] . ")"
                        ];
                    }
                    // 2. Regras diretas do Assunto
                    foreach ($appliedRules as $rule) {
                        if (!empty($rule['subject_id']) && (int)$rule['subject_id'] === $subjId) {
                            $isUser = !empty($rule['user_id']);
                            $subjSources[] = [
                                'type' => $isUser ? 'direct' : 'group',
                                'via_group' => !$isUser,
                                'level' => strtolower($rule['permission_level']),
                                'description' => $isUser ? "Acesso direto no Assunto {$subj['name']}" : "Equipe {$rule['group_name']} → " . ucfirst(strtolower($rule['permission_level'])) . " no Assunto {$subj['name']}"
                            ];
                        }
                    }

                    if (!empty($subjSources)) {
                        $diag = $this->buildResourceDiagnosis('subject', $subjId, $subjPath, 'Assunto', $subjSources, $filter);
                        if ($diag) $resourceDiagnoses[] = $diag;
                    }
                }
            }
        }

        return [
            'user' => $userData,
            'is_global_admin' => false,
            'active_groups' => $activeGroups,
            'resources' => array_values($resourceDiagnoses)
        ];
    }

    private function buildResourceDiagnosis(string $resType, int $resId, string $resPath, string $typeLabel, array $sources, string $filter): ?array {
        $levelWeights = ['view' => 1, 'edit' => 2, 'admin' => 3];
        $maxWeight = 0;
        $effectiveLevel = 'none';

        $hasDirect = false;
        $hasGroup = false;
        $hasInherited = false;

        foreach ($sources as $src) {
            $w = $levelWeights[$src['level']] ?? 0;
            if ($w > $maxWeight) {
                $maxWeight = $w;
                $effectiveLevel = $src['level'];
            }

            if ($src['type'] === 'direct') $hasDirect = true;
            if ($src['type'] === 'group' || !empty($src['via_group'])) $hasGroup = true;
            if ($src['type'] === 'inherited') $hasInherited = true;
        }

        // Aplicar Filtro do Usuário
        if ($filter === 'direct' && !$hasDirect) return null;
        if ($filter === 'groups' && !$hasGroup) return null;
        if ($filter === 'inherited' && !$hasInherited) return null;

        // Construir explicação quando houver múltiplas fontes
        $explanation = '';
        if (count($sources) > 1) {
            $explanation = "Nível " . strtoupper($effectiveLevel) . " prevaleceu por ser a permissão de maior nível (MAX) entre as " . count($sources) . " fontes ativas encontradas.";
        }

        return [
            'resource_type' => $resType,
            'resource_id' => $resId,
            'resource_path' => $resPath,
            'resource_type_label' => $typeLabel,
            'effective_level' => $effectiveLevel,
            'sources' => $sources,
            'explanation' => $explanation,
            'has_direct' => $hasDirect,
            'has_group' => $hasGroup,
            'has_inherited' => $hasInherited
        ];
    }

    /**
     * Retorna a árvore documental filtrada apenas com itens visíveis para o usuário.
     * Pruning estrito de ancestrais: Se o usuário tem acesso apenas a RH/Férias/Solicitação,
     * retorna exatamente RH -> Férias -> Solicitação, omitindo todos os irmãos sem acesso.
     */
    public function getAccessibleResourceTree(?int $userId): array {
        $userId = (int)($userId ?? 0);
        $fullTree = $this->getResourceTree();

        if ($userId > 0 && $this->isGlobalAdmin($userId)) {
            return $fullTree;
        }

        $accessibleTree = [];

        foreach ($fullTree as $cat) {
            $catId = (int)$cat['id'];
            $catDirectView = $userId > 0 ? $this->canView($userId, 'category', $catId) : false;

            $accessibleSubs = [];

            foreach ($cat['subcategories'] as $sub) {
                $subId = (int)$sub['id'];
                $subDirectView = $catDirectView || ($userId > 0 ? $this->canView($userId, 'subcategory', $subId) : false);

                $accessibleSubjs = [];

                foreach ($sub['subjects'] as $subj) {
                    $subjId = (int)$subj['id'];
                    $subjView = $subDirectView || ($userId > 0 ? $this->canView($userId, 'subject', $subjId) : false);

                    if ($subjView) {
                        $accessibleSubjs[] = $subj;
                    }
                }

                if ($subDirectView || !empty($accessibleSubjs)) {
                    $sub['subjects'] = $accessibleSubjs;
                    $accessibleSubs[] = $sub;
                }
            }

            if ($catDirectView || !empty($accessibleSubs)) {
                $cat['subcategories'] = $accessibleSubs;
                $accessibleTree[] = $cat;
            }
        }

        return $accessibleTree;
    }

    /**
     * Retorna os IDs de Categorias permitidas/visíveis para o usuário
     */
    public function getAllowedCategoryIds(?int $userId): array {
        $tree = $this->getAccessibleResourceTree($userId);
        return array_values(array_unique(array_map(fn($c) => (int)$c['id'], $tree)));
    }

    /**
     * Retorna os IDs de Subcategorias permitidas/visíveis para o usuário
     */
    public function getAllowedSubcategoryIds(?int $userId): array {
        $tree = $this->getAccessibleResourceTree($userId);
        $subIds = [];
        foreach ($tree as $cat) {
            foreach ($cat['subcategories'] as $sub) {
                $subIds[] = (int)$sub['id'];
            }
        }
        return array_values(array_unique($subIds));
    }

    /**
     * Retorna os IDs de Assuntos permitidos/visíveis para o usuário
     */
    public function getAllowedSubjectIds(?int $userId): array {
        $tree = $this->getAccessibleResourceTree($userId);
        $subjIds = [];
        foreach ($tree as $cat) {
            foreach ($cat['subcategories'] as $sub) {
                foreach ($sub['subjects'] as $subj) {
                    $subjIds[] = (int)$subj['id'];
                }
            }
        }
        return array_values(array_unique($subjIds));
    }

    /**
     * Libera o painel quando o usuário é Admin Global ou possui EDIT/ADMIN efetivo
     * em pelo menos um recurso. O campo users.role não concede edição local.
     */
    public function canAccessAdminPanel(?int $userId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $scope = $this->getAdministrativeScope($userId);
        return !empty($scope['category_ids'])
            || !empty($scope['subcategory_ids'])
            || !empty($scope['subject_ids']);
    }

    public function getAdminPanelAccessLabel(?int $userId): string {
        $userId = (int)($userId ?? 0);
        if ($this->isGlobalAdmin($userId)) {
            return 'Super Admin';
        }

        $scope = $this->getAdministrativeScope($userId);
        foreach ($scope['category_ids'] as $categoryId) {
            if ($this->canAdmin($userId, 'category', $categoryId)) {
                return 'Administrador de Categoria';
            }
        }
        foreach ($scope['subcategory_ids'] as $subcategoryId) {
            if ($this->canAdmin($userId, 'subcategory', $subcategoryId)) {
                return 'Administrador Local';
            }
        }
        foreach ($scope['subject_ids'] as $subjectId) {
            if ($this->canAdmin($userId, 'subject', $subjectId)) {
                return 'Administrador Local';
            }
        }

        if (!empty($scope['category_ids'])) {
            return 'Editor de Categoria';
        }
        if (!empty($scope['subcategory_ids'])) {
            return 'Editor de Subcategoria';
        }
        if (!empty($scope['subject_ids'])) {
            return 'Editor de Assunto';
        }

        return 'Usuário';
    }

    /**
     * Retorna somente os recursos nos quais o usuário pode efetivamente editar.
     * Os IDs de cobertura são usados apenas para detectar regras ancestrais que
     * alcançam o ramo administrado; eles não ampliam a autorização do usuário.
     */
    public function getAdministrativeScope(?int $userId): array {
        $userId = (int)($userId ?? 0);
        $scope = [
            'category_ids' => [],
            'subcategory_ids' => [],
            'subject_ids' => [],
            'coverage_category_ids' => [],
            'coverage_subcategory_ids' => [],
        ];

        if ($userId <= 0) {
            return $scope;
        }

        $isGlobalAdmin = $this->isGlobalAdmin($userId);
        // O escopo administrativo inclui recursos inativos. Caso contrário, um
        // editor que desativasse seu próprio ramo perderia o painel e não poderia
        // reativá-lo sem intervenção do Super Admin.
        foreach ($this->getResourceTree(false) as $category) {
            $categoryId = (int)$category['id'];
            $canEditCategory = $isGlobalAdmin || $this->canEdit($userId, 'category', $categoryId);

            if ($canEditCategory) {
                $scope['category_ids'][] = $categoryId;
                $scope['coverage_category_ids'][] = $categoryId;
            }

            foreach ($category['subcategories'] as $subcategory) {
                $subcategoryId = (int)$subcategory['id'];
                $canEditSubcategory = $canEditCategory || $this->canEdit($userId, 'subcategory', $subcategoryId);

                if ($canEditSubcategory) {
                    $scope['subcategory_ids'][] = $subcategoryId;
                    $scope['coverage_category_ids'][] = $categoryId;
                    $scope['coverage_subcategory_ids'][] = $subcategoryId;
                }

                foreach ($subcategory['subjects'] as $subject) {
                    $subjectId = (int)$subject['id'];
                    $canEditSubject = $canEditSubcategory || $this->canEdit($userId, 'subject', $subjectId);

                    if ($canEditSubject) {
                        $scope['subject_ids'][] = $subjectId;
                        $scope['coverage_category_ids'][] = $categoryId;
                        $scope['coverage_subcategory_ids'][] = $subcategoryId;
                    }
                }
            }
        }

        foreach ($scope as $key => $ids) {
            $scope[$key] = array_values(array_unique(array_map('intval', $ids)));
        }

        return $scope;
    }

    /**
     * Lista de usuários visível no módulo administrativo.
     * Admin Global vê todos; gestores locais veem apenas pessoas cujo acesso
     * direto ou via equipe alcança algum recurso de seu próprio escopo.
     */
    public function getUsersForAdministrativeScope(?int $managerUserId): array {
        $managerUserId = (int)($managerUserId ?? 0);
        if ($managerUserId <= 0) {
            return [];
        }

        $userFilterSql = '';
        if (!$this->isGlobalAdmin($managerUserId)) {
            $visibleUserIds = $this->getVisibleUserIdsForAdministrativeScope($managerUserId);
            if (empty($visibleUserIds)) {
                return [];
            }
            $userFilterSql = 'WHERE u.id IN (' . implode(',', $visibleUserIds) . ')';
        }

        $stmt = $this->pdo->query("
            SELECT u.id, u.name, u.username, u.email, u.role, u.active, u.created_at,
                   COUNT(DISTINCT ug.group_id) AS total_grupos
            FROM users u
            LEFT JOIN user_groups ug ON u.id = ug.user_id
            {$userFilterSql}
            GROUP BY u.id, u.name, u.username, u.email, u.role, u.active, u.created_at
            ORDER BY u.name ASC
        ");

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$this->isGlobalAdmin($managerUserId)) {
            foreach ($users as &$user) {
                $user['total_grupos'] = count($this->getUserTeamsForAdministrativeScope(
                    $managerUserId,
                    (int)$user['id']
                ));
            }
            unset($user);
        }

        return $users;
    }

    public function canViewUserInAdministrativeScope(?int $managerUserId, int $targetUserId): bool {
        $managerUserId = (int)($managerUserId ?? 0);
        if ($managerUserId <= 0 || $targetUserId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($managerUserId)) {
            return true;
        }

        return in_array($targetUserId, $this->getVisibleUserIdsForAdministrativeScope($managerUserId), true);
    }

    /** Restringe o diagnóstico aos recursos que o gestor local pode editar. */
    public function filterDiagnosisToAdministrativeScope(?int $managerUserId, array $diagnosis): array {
        $managerUserId = (int)($managerUserId ?? 0);
        if ($this->isGlobalAdmin($managerUserId)) {
            return $diagnosis;
        }

        $scope = $this->getAdministrativeScope($managerUserId);
        $typeToScopeKey = [
            'category' => 'category_ids',
            'subcategory' => 'subcategory_ids',
            'subject' => 'subject_ids',
        ];

        $diagnosis['resources'] = array_values(array_filter(
            $diagnosis['resources'] ?? [],
            static function (array $resource) use ($scope, $typeToScopeKey): bool {
                $scopeKey = $typeToScopeKey[$resource['resource_type'] ?? ''] ?? null;
                return $scopeKey !== null
                    && in_array((int)($resource['resource_id'] ?? 0), $scope[$scopeKey], true);
            }
        ));

        $visibleTeams = $this->getUserTeamsForAdministrativeScope(
            $managerUserId,
            (int)($diagnosis['user']['id'] ?? 0)
        );
        $diagnosis['active_groups'] = array_values(array_filter(
            $visibleTeams,
            static fn(array $team): bool => (bool)($team['active'] ?? false)
        ));

        return $diagnosis;
    }

    /** Retorna apenas as equipes do usuário que concedem acesso dentro do escopo local. */
    public function getUserTeamsForAdministrativeScope(?int $managerUserId, int $targetUserId): array {
        $managerUserId = (int)($managerUserId ?? 0);
        if ($managerUserId <= 0 || $targetUserId <= 0) {
            return [];
        }

        if ($this->isGlobalAdmin($managerUserId)) {
            $stmt = $this->pdo->prepare("
                SELECT g.id, g.name, g.description, g.active, ug.created_at AS member_since
                FROM groups g
                JOIN user_groups ug ON ug.group_id = g.id
                WHERE ug.user_id = ?
                ORDER BY g.name ASC
            ");
            $stmt->execute([$targetUserId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $condition = $this->buildPermissionScopeCondition($this->getAdministrativeScope($managerUserId), 'p');
        if ($condition === '') {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT g.id, g.name, g.description, g.active, ug.created_at AS member_since
            FROM groups g
            JOIN user_groups ug ON ug.group_id = g.id
            JOIN permissions p ON p.group_id = g.id
            WHERE ug.user_id = ? AND ({$condition})
            ORDER BY g.name ASC
        ");
        $stmt->execute([$targetUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getVisibleUserIdsForAdministrativeScope(int $managerUserId): array {
        $condition = $this->buildPermissionScopeCondition($this->getAdministrativeScope($managerUserId), 'p');
        if ($condition === '') {
            return [];
        }

        $stmt = $this->pdo->query("
            SELECT DISTINCT scoped.user_id
            FROM (
                SELECT p.user_id
                FROM permissions p
                WHERE p.user_id IS NOT NULL AND ({$condition})

                UNION

                SELECT ug.user_id
                FROM permissions p
                JOIN groups g ON g.id = p.group_id AND g.active = TRUE
                JOIN user_groups ug ON ug.group_id = g.id
                WHERE p.group_id IS NOT NULL AND ({$condition})
            ) scoped
            WHERE scoped.user_id IS NOT NULL
        ");

        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function buildPermissionScopeCondition(array $scope, string $alias): string {
        $conditions = [];
        $mapping = [
            'category_id' => $scope['coverage_category_ids'] ?? [],
            'subcategory_id' => $scope['coverage_subcategory_ids'] ?? [],
            'subject_id' => $scope['subject_ids'] ?? [],
        ];

        foreach ($mapping as $column => $ids) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
            if (!empty($ids)) {
                $conditions[] = "{$alias}.{$column} IN (" . implode(',', $ids) . ')';
            }
        }

        return implode(' OR ', $conditions);
    }

    /**
     * Wrappers Explícitos de Conveniência
     */
    public function canViewCategory(?int $userId, int $categoryId): bool {
        return $this->canView((int)$userId, 'category', $categoryId);
    }

    public function canViewSubcategory(?int $userId, int $subcategoryId): bool {
        return $this->canView((int)$userId, 'subcategory', $subcategoryId);
    }

    public function canViewSubject(?int $userId, int $subjectId): bool {
        return $this->canView((int)$userId, 'subject', $subjectId);
    }

    public function canViewDocument(?int $userId, int $documentId): bool {
        $userId = (int)($userId ?? 0);
        $stmtDoc = $this->pdo->prepare("SELECT subject_id, status FROM documents WHERE id = ?");
        $stmtDoc->execute([$documentId]);
        $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            return false;
        }

        $isPublished = in_array(strtolower($doc['status'] ?? ''), ['published', 'ativo']);

        if (!$isPublished) {
            if ($userId <= 0) return false;
            if ($this->isGlobalAdmin($userId)) return true;
            return $this->canEdit($userId, 'subject', (int)$doc['subject_id']);
        }

        return $this->canView($userId, 'subject', (int)$doc['subject_id']);
    }

    public function canEditCategory(?int $userId, int $categoryId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || $categoryId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $perm = $this->getEffectiveCategoryPermission($userId, $categoryId);
        return ($perm['effective_level'] ?? 'none') === 'admin';
    }

    public function canEditSubcategory(?int $userId, int $subcategoryId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || $subcategoryId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $perm = $this->getEffectiveSubcategoryPermission($userId, $subcategoryId);
        return ($perm['effective_value'] ?? 0) >= self::LEVEL_MAP['edit'];
    }

    public function canEditSubject(?int $userId, int $subjectId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || $subjectId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $perm = $this->getEffectiveSubjectPermission($userId, $subjectId);
        return ($perm['effective_value'] ?? 0) >= self::LEVEL_MAP['edit'];
    }

    public function canCreateDocument(?int $userId, int $subjectId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || $subjectId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $perm = $this->getEffectiveSubjectPermission($userId, $subjectId);
        return ($perm['effective_value'] ?? 0) >= self::LEVEL_MAP['edit'];
    }

    public function canEditDocument(?int $userId, int $documentId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || $documentId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $stmtDoc = $this->pdo->prepare("SELECT subject_id FROM documents WHERE id = ?");
        $stmtDoc->execute([$documentId]);
        $subjectId = (int)$stmtDoc->fetchColumn();
        if (!$subjectId) return false;
        return $this->canCreateDocument($userId, $subjectId);
    }

    public function canAdminCategory(?int $userId, int $categoryId): bool {
        return $this->canAdmin((int)$userId, 'category', $categoryId);
    }

    public function canAdminSubcategory(?int $userId, int $subcategoryId): bool {
        return $this->canAdmin((int)$userId, 'subcategory', $subcategoryId);
    }

    public function canAdminSubject(?int $userId, int $subjectId): bool {
        return $this->canAdmin((int)$userId, 'subject', $subjectId);
    }

    public function canAdminDocument(?int $userId, int $documentId): bool {
        $userId = (int)($userId ?? 0);
        $stmtDoc = $this->pdo->prepare("SELECT subject_id FROM documents WHERE id = ?");
        $stmtDoc->execute([$documentId]);
        $subjectId = (int)$stmtDoc->fetchColumn();
        if (!$subjectId) return false;
        return $this->canAdmin($userId, 'subject', $subjectId);
    }

    /**
     * Verifica se o usuário possui permissão para criar uma nova Categoria no nível raiz.
     * Regra estrita: SOMENTE o Administrador Global (users.role = 'admin') pode criar Categorias.
     * Administradores ou Editores de recurso (Categoria/Subcategoria/Assunto) NÃO podem criar novas Categorias de nível raiz.
     */
    public function canCreateCategory(?int $userId): bool {
        return $this->isGlobalAdmin($userId);
    }

    /**
     * Verifica se o usuário possui permissão para criar uma Subcategoria dentro de uma Categoria.
     * Regra: Admin Geral OU permissão efetiva da Categoria >= Edit.
     */
    public function canCreateSubcategory(?int $userId, int $categoryId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || $categoryId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $perm = $this->getEffectiveCategoryPermission($userId, $categoryId);
        return ($perm['effective_value'] ?? 0) >= self::LEVEL_MAP['edit'];
    }

    /**
     * Verifica se o usuário possui permissão para criar um Assunto dentro de uma Subcategoria.
     * Regra: Admin Geral OU permissão efetiva da Subcategoria >= Edit.
     */
    public function canCreateSubject(?int $userId, int $subcategoryId): bool {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0 || $subcategoryId <= 0) {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $perm = $this->getEffectiveSubcategoryPermission($userId, $subcategoryId);
        return ($perm['effective_value'] ?? 0) >= self::LEVEL_MAP['edit'];
    }
}
