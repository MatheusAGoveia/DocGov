<?php
/**
 * services/AccessService.php
 * Adapter de compatibilidade que redireciona 100% das chamadas operacionais
 * para o novo motor de autorização PermissionService.php.
 */

require_once __DIR__ . '/PermissionService.php';

class AccessService {
    private PermissionService $permService;

    public function __construct(PDO $pdo) {
        $this->permService = new PermissionService($pdo);
    }

    public function isAdmin(int $userId): bool {
        return $this->permService->isGlobalAdmin($userId);
    }

    public function getUserActiveGroupIds(int $userId): array {
        return $this->permService->getActiveUserGroupIds($userId);
    }

    public function canAccessCategory(int $userId, int $categoryId): bool {
        return $this->permService->canViewCategory($userId, $categoryId);
    }

    public function canAccessSubcategory(int $userId, int $subcategoryId): bool {
        return $this->permService->canViewSubcategory($userId, $subcategoryId);
    }

    public function canAccessSubject(int $userId, int $subjectId): bool {
        return $this->permService->canViewSubject($userId, $subjectId);
    }

    public function canAccessDocument(int $userId, int $documentId): bool {
        return $this->permService->canViewDocument($userId, $documentId);
    }

    public function getAllowedCategoryIds(int $userId): array {
        return $this->permService->getAllowedCategoryIds($userId);
    }

    public function getAllowedSubcategoryIds(int $userId): array {
        return $this->permService->getAllowedSubcategoryIds($userId);
    }

    public function getAllowedSubjectIds(int $userId): array {
        return $this->permService->getAllowedSubjectIds($userId);
    }
}
