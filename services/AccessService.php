<?php
/**
 * services/AccessService.php
 * Motor Central de Autorização por Grupos do DocGov
 * 
 * Regras:
 * - ROLE: 'admin' possui acesso global ilimitado.
 * - GRUPOS: Definem onde usuários não-admin podem acessar.
 * - DEFAULT DENY: Sem permissão = sem acesso.
 * - GRUPO INATIVO: `groups.active = FALSE` não concede acesso.
 * - HERANÇA PARA BAIXO: Categoria → Subcategorias → Assuntos → Documentos.
 * - ANCESTRAIS: Categoria/Subcategoria pai é visível se o usuário tiver acesso a um descendente.
 * - UNIÃO DE GRUPOS: Múltiplos grupos acumulam acessos (OR).
 */

class AccessService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Verifica se o usuário é Admin Global (Bypass)
     */
    public function isAdmin(int $userId): bool {
        if ($userId <= 0) return false;
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ? AND active = TRUE");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();
        return $role === 'admin';
    }

    /**
     * Retorna a lista de IDs de Grupos ATIVOS a que o usuário pertence
     */
    public function getUserActiveGroupIds(int $userId): array {
        if ($userId <= 0) return [];
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
     * Verifica se o usuário possui acesso (ou visibilidade ancestral) a uma Categoria
     */
    public function canAccessCategory(int $userId, int $categoryId): bool {
        if ($userId <= 0 || $categoryId <= 0) return false;
        if ($this->isAdmin($userId)) return true;

        $groupIds = $this->getUserActiveGroupIds($userId);
        if (empty($groupIds)) return false;

        $inQuery = implode(',', array_map('intval', $groupIds));

        $sql = "
            SELECT 1 FROM group_access ga
            LEFT JOIN subcategories sc ON ga.subcategory_id = sc.id
            LEFT JOIN subjects s ON ga.subject_id = s.id
            LEFT JOIN subcategories sc_parent ON s.subcategory_id = sc_parent.id
            WHERE ga.group_id IN ($inQuery)
              AND (
                  ga.category_id = :cat_id
                  OR sc.category_id = :cat_id
                  OR sc_parent.category_id = :cat_id
              )
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':cat_id' => $categoryId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Verifica se o usuário possui acesso (ou visibilidade ancestral) a uma Subcategoria
     */
    public function canAccessSubcategory(int $userId, int $subcategoryId): bool {
        if ($userId <= 0 || $subcategoryId <= 0) return false;
        if ($this->isAdmin($userId)) return true;

        $groupIds = $this->getUserActiveGroupIds($userId);
        if (empty($groupIds)) return false;

        $inQuery = implode(',', array_map('intval', $groupIds));

        $sql = "
            SELECT 1 FROM group_access ga
            JOIN subcategories target_sc ON target_sc.id = :subcat_id
            LEFT JOIN subjects s ON ga.subject_id = s.id
            WHERE ga.group_id IN ($inQuery)
              AND (
                  ga.category_id = target_sc.category_id
                  OR ga.subcategory_id = target_sc.id
                  OR s.subcategory_id = target_sc.id
              )
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':subcat_id' => $subcategoryId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Verifica se o usuário possui acesso a um Assunto
     */
    public function canAccessSubject(int $userId, int $subjectId): bool {
        if ($userId <= 0 || $subjectId <= 0) return false;
        if ($this->isAdmin($userId)) return true;

        $groupIds = $this->getUserActiveGroupIds($userId);
        if (empty($groupIds)) return false;

        $inQuery = implode(',', array_map('intval', $groupIds));

        $sql = "
            SELECT 1 FROM group_access ga
            JOIN subjects target_s ON target_s.id = :subject_id
            JOIN subcategories target_sc ON target_s.subcategory_id = target_sc.id
            WHERE ga.group_id IN ($inQuery)
              AND (
                  ga.category_id = target_sc.category_id
                  OR ga.subcategory_id = target_sc.id
                  OR ga.subject_id = target_s.id
              )
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':subject_id' => $subjectId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Verifica se o usuário possui acesso a um Documento (herda do Assunto pai)
     */
    public function canAccessDocument(int $userId, int $documentId): bool {
        if ($userId <= 0 || $documentId <= 0) return false;
        if ($this->isAdmin($userId)) return true;

        $stmtDoc = $this->pdo->prepare("SELECT subject_id FROM documents WHERE id = ?");
        $stmtDoc->execute([$documentId]);
        $subjectId = $stmtDoc->fetchColumn();

        if (!$subjectId) return false;

        return $this->canAccessSubject($userId, (int)$subjectId);
    }

    /**
     * Retorna todos os IDs de Categorias permitidas / visíveis para o usuário
     */
    public function getAllowedCategoryIds(int $userId): array {
        if ($userId <= 0) return [];
        if ($this->isAdmin($userId)) {
            $stmt = $this->pdo->query("SELECT id FROM categories WHERE active = TRUE");
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $groupIds = $this->getUserActiveGroupIds($userId);
        if (empty($groupIds)) return [];

        $inQuery = implode(',', array_map('intval', $groupIds));

        $sql = "
            SELECT DISTINCT c.id
            FROM categories c
            WHERE c.active = TRUE AND (
                c.id IN (SELECT category_id FROM group_access WHERE group_id IN ($inQuery) AND category_id IS NOT NULL)
                OR c.id IN (
                    SELECT sc.category_id 
                    FROM group_access ga 
                    JOIN subcategories sc ON ga.subcategory_id = sc.id 
                    WHERE ga.group_id IN ($inQuery) AND ga.subcategory_id IS NOT NULL
                )
                OR c.id IN (
                    SELECT sc_p.category_id 
                    FROM group_access ga 
                    JOIN subjects s ON ga.subject_id = s.id 
                    JOIN subcategories sc_p ON s.subcategory_id = sc_p.id 
                    WHERE ga.group_id IN ($inQuery) AND ga.subject_id IS NOT NULL
                )
            )
        ";

        $stmt = $this->pdo->query($sql);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Retorna todos os IDs de Subcategorias permitidas / visíveis para o usuário
     */
    public function getAllowedSubcategoryIds(int $userId): array {
        if ($userId <= 0) return [];
        if ($this->isAdmin($userId)) {
            $stmt = $this->pdo->query("SELECT id FROM subcategories WHERE active = TRUE");
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $groupIds = $this->getUserActiveGroupIds($userId);
        if (empty($groupIds)) return [];

        $inQuery = implode(',', array_map('intval', $groupIds));

        $sql = "
            SELECT DISTINCT sc.id
            FROM subcategories sc
            WHERE sc.active = TRUE AND (
                sc.category_id IN (SELECT category_id FROM group_access WHERE group_id IN ($inQuery) AND category_id IS NOT NULL)
                OR sc.id IN (SELECT subcategory_id FROM group_access WHERE group_id IN ($inQuery) AND subcategory_id IS NOT NULL)
                OR sc.id IN (
                    SELECT s.subcategory_id 
                    FROM group_access ga 
                    JOIN subjects s ON ga.subject_id = s.id 
                    WHERE ga.group_id IN ($inQuery) AND ga.subject_id IS NOT NULL
                )
            )
        ";

        $stmt = $this->pdo->query($sql);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Retorna todos os IDs de Assuntos permitidos para o usuário
     */
    public function getAllowedSubjectIds(int $userId): array {
        if ($userId <= 0) return [];
        if ($this->isAdmin($userId)) {
            $stmt = $this->pdo->query("SELECT id FROM subjects WHERE active = TRUE");
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $groupIds = $this->getUserActiveGroupIds($userId);
        if (empty($groupIds)) return [];

        $inQuery = implode(',', array_map('intval', $groupIds));

        $sql = "
            SELECT DISTINCT s.id
            FROM subjects s
            JOIN subcategories sc ON s.subcategory_id = sc.id
            WHERE s.active = TRUE AND (
                sc.category_id IN (SELECT category_id FROM group_access WHERE group_id IN ($inQuery) AND category_id IS NOT NULL)
                OR sc.id IN (SELECT subcategory_id FROM group_access WHERE group_id IN ($inQuery) AND subcategory_id IS NOT NULL)
                OR s.id IN (SELECT subject_id FROM group_access WHERE group_id IN ($inQuery) AND subject_id IS NOT NULL)
            )
        ";

        $stmt = $this->pdo->query($sql);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
