<?php
// index.php - Portal de Documentos da Prefeitura (PostgreSQL Fonte Única de Verdade)
require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';

$loggedUser = $_SESSION['user'] ?? null;
$isPortalLoginRequired = !$loggedUser;
if ($isPortalLoginRequired) {
    header('Location: login.php');
    exit;
}
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

require_once __DIR__ . '/services/AccessService.php';
$accessService = new AccessService($pdo);
require_once __DIR__ . '/services/PermissionService.php';
$permissionService = new PermissionService($pdo);
$canAccessAdminPanel = $loggedUser && $permissionService->canAccessAdminPanel($userId);
require_once __DIR__ . '/services/NotificationService.php';
$notificationService = new NotificationService($pdo);
$unreadNotificationCount = $loggedUser ? $notificationService->unreadCount($userId) : 0;
require_once __DIR__ . '/services/UsageAuditService.php';
$usageAuditService = new UsageAuditService($pdo);
require_once __DIR__ . '/services/TagService.php';
$tagService = new TagService($pdo);

$allowedCatIds = $accessService->getAllowedCategoryIds($userId);
$allowedSubcatIds = $accessService->getAllowedSubcategoryIds($userId);
$allowedSubjectIds = $accessService->getAllowedSubjectIds($userId);

// Captura Parâmetros de Navegação via GET
$selectedCat = trim($_GET['cat'] ?? '');
$selectedSubcat = trim($_GET['subcat'] ?? '');
$selectedAssunto = trim($_GET['assunto'] ?? '');
$currentView = trim($_GET['view'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');
$searchCategory = trim($_GET['search_category'] ?? '');
$searchType = trim($_GET['search_type'] ?? '');
$searchAuthor = trim($_GET['search_author'] ?? '');
$searchDateFrom = trim($_GET['date_from'] ?? '');
$searchDateTo = trim($_GET['date_to'] ?? '');
$requestedTag = trim($_GET['tag'] ?? '');
$selectedTag = $requestedTag !== '' ? $tagService->resolveName($requestedTag) : null;
if (!in_array($searchType, ['', 'file', 'text', 'link', 'code', 'video'], true)) {
    $searchType = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDateFrom)) {
    $searchDateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDateTo)) {
    $searchDateTo = '';
}
$searchMode = $searchQuery !== '' || $searchCategory !== '' || $searchType !== '' || $searchAuthor !== '' || $searchDateFrom !== '' || $searchDateTo !== '' || $requestedTag !== '';
$hasAdvancedSearchFilters = $searchCategory !== '' || $searchType !== '' || $searchAuthor !== '' || $searchDateFrom !== '' || $searchDateTo !== '' || $requestedTag !== '';

function highlightSearchTerm(string $text, string $query): string {
    $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $tokens = array_values(array_filter(preg_split('/\s+/u', trim($query)) ?: [], static fn(string $token): bool => mb_strlen($token) >= 2));
    if (empty($tokens)) {
        return $safeText;
    }
    $pattern = '/(' . implode('|', array_map(static fn(string $token): string => preg_quote($token, '/'), $tokens)) . ')/iu';
    return preg_replace($pattern, '<mark class="rounded bg-amber-200/80 px-0.5 text-inherit dark:bg-amber-400/30">$1</mark>', $safeText) ?? $safeText;
}

// Determina o Nível Atual da Navegação
$currentLevel = 1; // 1: Categorias, 2: Subcategorias, 3: Assuntos, 4: Documentos
if (!empty($selectedCat)) {
    $currentLevel = 2;
    if (!empty($selectedSubcat)) {
        $currentLevel = 3;
        if (!empty($selectedAssunto)) {
            $currentLevel = 4;
        }
    }
}

$items = [];
$favoritos = [];
$favMapDocs = [];
$favMapSubcats = [];
$favMapSubjs = [];

$pageTitle = "Documentos e Referências";
$pageDesc = "Encontre rapidamente documentos, normas, manuais e orientações de {$organizationName}.";
$searchCategoryOptions = [];
$searchTagOptions = $tagService->allActive();
if (!empty($allowedCatIds)) {
    $categoryIdsSql = implode(',', array_map('intval', $allowedCatIds));
    $searchCategoryOptions = $pdo->query("SELECT name, slug FROM categories WHERE id IN ($categoryIdsSql) AND active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Mapeamento Geral de Favoritos do Usuário Logado (Somente Itens Permitidos)
if ($loggedUser && $userId > 0 && !empty($allowedSubjectIds)) {
    try {
        $subInSqlFav = implode(',', array_map('intval', $allowedSubjectIds));
        $subcatInSqlFav = !empty($allowedSubcatIds) ? implode(',', array_map('intval', $allowedSubcatIds)) : '0';

        $stmtFav = $pdo->prepare("
            SELECT f.document_id, f.subcategory_id, f.subject_id 
            FROM favorites f
            LEFT JOIN documents d ON f.document_id = d.id
            LEFT JOIN subcategories sc ON f.subcategory_id = sc.id
            LEFT JOIN subjects s ON f.subject_id = s.id
            WHERE f.user_id = :uid
              AND (
                  (f.document_id IS NOT NULL AND d.subject_id IN ($subInSqlFav)) OR
                  (f.subcategory_id IS NOT NULL AND f.subcategory_id IN ($subcatInSqlFav)) OR
                  (f.subject_id IS NOT NULL AND f.subject_id IN ($subInSqlFav))
              )
        ");
        $stmtFav->execute([':uid' => $userId]);
        $rows = $stmtFav->fetchAll();
        foreach ($rows as $r) {
            if (!empty($r['document_id'])) $favMapDocs[(int)$r['document_id']] = true;
            if (!empty($r['subcategory_id'])) $favMapSubcats[(int)$r['subcategory_id']] = true;
            if (!empty($r['subject_id'])) $favMapSubjs[(int)$r['subject_id']] = true;
        }
    } catch (Exception $e) {
        $favMapDocs = []; $favMapSubcats = []; $favMapSubjs = [];
    }
}

// Lógica de Busca / Filtros (Restrita a assuntos permitidos no SQL)
if ($searchMode) {
    if (empty($allowedSubjectIds)) {
        $items = [];
        $pageTitle = "Resultados da pesquisa";
        $pageDesc = "Nenhum resultado encontrado para \"" . htmlspecialchars($searchQuery) . "\".";
    } else {
        $subInSql = implode(',', array_map('intval', $allowedSubjectIds));
        $searchWhere = [
            "d.status = 'published'",
            's.active = TRUE',
            'sc.active = TRUE',
            'c.active = TRUE',
            "d.subject_id IN ($subInSql)",
        ];
        $searchParams = [':uid' => $userId];
        if ($searchQuery !== '') {
            $searchWhere[] = "(LOWER(d.title) LIKE :q OR LOWER(d.description) LIKE :q OR LOWER(s.name) LIKE :q OR LOWER(sc.name) LIKE :q OR LOWER(c.name) LIKE :q OR LOWER(COALESCE(u.name, '')) LIKE :q OR EXISTS (SELECT 1 FROM document_tags dtq JOIN tags tq ON tq.id = dtq.tag_id LEFT JOIN tag_aliases taq ON taq.tag_id = tq.id WHERE dtq.document_id = d.id AND tq.active = TRUE AND (LOWER(tq.name) LIKE :q OR LOWER(COALESCE(taq.alias, '')) LIKE :q)))";
            $searchParams[':q'] = '%' . mb_strtolower($searchQuery) . '%';
        }
        if ($requestedTag !== '') {
            if ($selectedTag === null) {
                $searchWhere[] = 'FALSE';
            } else {
                $searchWhere[] = 'EXISTS (SELECT 1 FROM document_tags dtt WHERE dtt.document_id = d.id AND dtt.tag_id = :tag_id)';
                $searchParams[':tag_id'] = (int)$selectedTag['id'];
            }
        }
        if ($searchCategory !== '') {
            $searchWhere[] = 'c.slug = :search_category';
            $searchParams[':search_category'] = $searchCategory;
        }
        if ($searchType !== '') {
            $searchWhere[] = 'd.content_type = :search_type';
            $searchParams[':search_type'] = $searchType;
        }
        if ($searchAuthor !== '') {
            $searchWhere[] = "LOWER(COALESCE(u.name, '')) LIKE :search_author";
            $searchParams[':search_author'] = '%' . mb_strtolower($searchAuthor) . '%';
        }
        if ($searchDateFrom !== '') {
            $searchWhere[] = 'd.created_at >= CAST(:date_from AS date)';
            $searchParams[':date_from'] = $searchDateFrom;
        }
        if ($searchDateTo !== '') {
            $searchWhere[] = "d.created_at < CAST(:date_to AS date) + INTERVAL '1 day'";
            $searchParams[':date_to'] = $searchDateTo;
        }

        $stmt = $pdo->prepare("
            SELECT 
                d.id, d.title, d.slug, d.description, d.content_type, d.status, d.published_at,
                d.original_filename, d.file_size, d.external_url, d.created_at,
                s.name AS subject_name, s.slug AS subject_slug,
                sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                c.name AS category_name, c.slug AS category_slug,
                u.name AS author_name,
                CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_favorited
            FROM documents d
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            LEFT JOIN favorites f ON f.document_id = d.id AND f.user_id = :uid
            WHERE " . implode(' AND ', $searchWhere) . "
            ORDER BY is_favorited DESC, c.name ASC, sc.name ASC, s.name ASC, d.title ASC
        ");
        $stmt->execute($searchParams);
        $items = $stmt->fetchAll();
        $pageTitle = "Resultados da pesquisa";
        $searchDescription = $searchQuery !== '' ? ' para “' . $searchQuery . '”' : ' com os filtros aplicados';
        if ($selectedTag !== null) $searchDescription .= ' · tag “' . $selectedTag['name'] . '”';
        $pageDesc = count($items) . ' resultado(s) encontrado(s)' . $searchDescription . '.';
    }

} else {
    if ($currentLevel === 1) {
        // Nível 1: Lista Categorias Permitidas (Ancestrais ou Diretas)
        if (empty($allowedCatIds)) {
            $items = [];
        } else {
            $catInSql = implode(',', array_map('intval', $allowedCatIds));
            $subcatInSql = !empty($allowedSubcatIds) ? implode(',', array_map('intval', $allowedSubcatIds)) : '0';
            $subInSql = !empty($allowedSubjectIds) ? implode(',', array_map('intval', $allowedSubjectIds)) : '0';

            $stmt = $pdo->query("
                SELECT 
                    c.id, c.name, c.slug, c.description, c.image_path,
                    COUNT(DISTINCT sc.id) AS total_subcat,
                    COUNT(DISTINCT d.id) AS total_docs
                FROM categories c
                LEFT JOIN subcategories sc ON sc.category_id = c.id AND sc.active = TRUE AND sc.id IN ($subcatInSql)
                LEFT JOIN subjects s ON s.subcategory_id = sc.id AND s.active = TRUE AND s.id IN ($subInSql)
                LEFT JOIN documents d ON d.subject_id = s.id AND d.status = 'published'
                WHERE c.active = TRUE AND c.id IN ($catInSql)
                GROUP BY c.id, c.name, c.slug, c.description, c.image_path
                ORDER BY c.name ASC
            ");
            $items = $stmt->fetchAll();
        }
        $pageTitle = "Documentos e Referências";
        $pageDesc = "Encontre rapidamente documentos, normas, manuais e orientações de {$organizationName}.";

    } elseif ($currentLevel === 2) {
        // Nível 2: Lista Subcategorias Permitidas da Categoria Selecionada
        $stmtCatRes = $pdo->prepare("SELECT id, name FROM categories WHERE (slug = :cat OR id::text = :cat) AND active = TRUE LIMIT 1");
        $stmtCatRes->execute([':cat' => $selectedCat]);
        $catRes = $stmtCatRes->fetch();

        if (!$catRes || !$accessService->canAccessCategory($userId, (int)$catRes['id']) || empty($allowedSubcatIds)) {
            $items = [];
            $pageTitle = $catRes['name'] ?? $selectedCat;
            $pageDesc = "Nenhuma subcategoria disponível nesta categoria para seu grupo.";
        } else {
            $subcatInSql = implode(',', array_map('intval', $allowedSubcatIds));
            $subInSql = !empty($allowedSubjectIds) ? implode(',', array_map('intval', $allowedSubjectIds)) : '0';

            $stmt = $pdo->prepare("
                SELECT 
                    sc.id, sc.name, sc.slug, sc.description, sc.image_path,
                    c.name AS category_name, c.slug AS category_slug,
                    COUNT(DISTINCT s.id) AS total_assuntos,
                    COUNT(DISTINCT d.id) AS total_docs,
                    CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_favorited
                FROM subcategories sc
                JOIN categories c ON sc.category_id = c.id
                LEFT JOIN subjects s ON s.subcategory_id = sc.id AND s.active = TRUE AND s.id IN ($subInSql)
                LEFT JOIN documents d ON d.subject_id = s.id AND d.status = 'published'
                LEFT JOIN favorites f ON f.subcategory_id = sc.id AND f.user_id = :uid
                WHERE (c.slug = :cat OR c.id::text = :cat) 
                  AND sc.active = TRUE AND c.active = TRUE
                  AND sc.id IN ($subcatInSql)
                GROUP BY sc.id, sc.name, sc.slug, sc.description, sc.image_path, c.name, c.slug, f.id
                ORDER BY is_favorited DESC, sc.name ASC
            ");
            $stmt->execute([':cat' => $selectedCat, ':uid' => $userId]);
            $items = $stmt->fetchAll();

            $catName = $items[0]['category_name'] ?? $catRes['name'];
            $pageTitle = $catName;
            $pageDesc = count($items) . " subcategoria(s) disponível(is) nesta categoria.";
        }

    } elseif ($currentLevel === 3) {
        // Nível 3: Lista Assuntos Permitidos
        $stmtSubRes = $pdo->prepare("
            SELECT sc.id, sc.name, c.name AS category_name FROM subcategories sc
            JOIN categories c ON sc.category_id = c.id
            WHERE (sc.slug = :sub OR sc.id::text = :sub) AND (c.slug = :cat OR c.id::text = :cat) AND sc.active = TRUE LIMIT 1
        ");
        $stmtSubRes->execute([':sub' => $selectedSubcat, ':cat' => $selectedCat]);
        $subRes = $stmtSubRes->fetch();

        if (!$subRes || !$accessService->canAccessSubcategory($userId, (int)$subRes['id']) || empty($allowedSubjectIds)) {
            $items = [];
            $pageTitle = $subRes['name'] ?? $selectedSubcat;
            $pageDesc = "Nenhum assunto disponível nesta subcategoria para seu grupo.";
        } else {
            $subInSql = implode(',', array_map('intval', $allowedSubjectIds));

            $stmt = $pdo->prepare("
                SELECT 
                    s.id, s.name, s.slug, s.description,
                    sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                    c.name AS category_name, c.slug AS category_slug,
                    COUNT(DISTINCT d.id) AS total_docs,
                    CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_favorited
                FROM subjects s
                JOIN subcategories sc ON s.subcategory_id = sc.id
                JOIN categories c ON sc.category_id = c.id
                LEFT JOIN documents d ON d.subject_id = s.id AND d.status = 'published'
                LEFT JOIN favorites f ON f.subject_id = s.id AND f.user_id = :uid
                WHERE (sc.slug = :subcat OR sc.id::text = :subcat) 
                  AND (c.slug = :cat OR c.id::text = :cat)
                  AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE
                  AND s.id IN ($subInSql)
                GROUP BY s.id, s.name, s.slug, s.description, sc.name, sc.slug, c.name, c.slug, f.id
                ORDER BY is_favorited DESC, s.name ASC
            ");
            $stmt->execute([':cat' => $selectedCat, ':subcat' => $selectedSubcat, ':uid' => $userId]);
            $items = $stmt->fetchAll();

            $subName = $items[0]['subcategory_name'] ?? $subRes['name'];
            $pageTitle = $subName;
            $pageDesc = count($items) . " assunto(s) cadastrado(s) nesta subcategoria.";
        }

    } elseif ($currentLevel === 4) {
        // Nível 4: Lista Documentos Publicados dos Assuntos Permitidos
        $stmtAssRes = $pdo->prepare("
            SELECT s.id, s.name, sc.name AS subcategory_name, c.name AS category_name FROM subjects s
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            WHERE (s.slug = :ass OR s.id::text = :ass) AND (sc.slug = :sub OR sc.id::text = :sub) AND (c.slug = :cat OR c.id::text = :cat) AND s.active = TRUE LIMIT 1
        ");
        $stmtAssRes->execute([':ass' => $selectedAssunto, ':sub' => $selectedSubcat, ':cat' => $selectedCat]);
        $assRes = $stmtAssRes->fetch();

        if (!$assRes || !$accessService->canAccessSubject($userId, (int)$assRes['id'])) {
            $items = [];
            $pageTitle = $assRes['name'] ?? $selectedAssunto;
            $pageDesc = "Acesso restrito. Você não possui permissão para visualizar este assunto.";
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    d.id, d.title, d.slug, d.description, d.content_type, d.status, d.published_at,
                    d.original_filename, d.file_size, d.external_url, d.created_at,
                    s.name AS subject_name, s.slug AS subject_slug,
                    sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                    c.name AS category_name, c.slug AS category_slug,
                    u.name AS author_name,
                    CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_favorited
                FROM documents d
                JOIN subjects s ON d.subject_id = s.id
                JOIN subcategories sc ON s.subcategory_id = sc.id
                JOIN categories c ON sc.category_id = c.id
                LEFT JOIN users u ON d.created_by = u.id
                LEFT JOIN favorites f ON f.document_id = d.id AND f.user_id = :uid
                WHERE (s.slug = :assunto OR s.id::text = :assunto)
                  AND (sc.slug = :subcat OR sc.id::text = :subcat)
                  AND (c.slug = :cat OR c.id::text = :cat)
                  AND d.status = 'published' AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE
                ORDER BY is_favorited DESC, d.title ASC
            ");
            $stmt->execute([':cat' => $selectedCat, ':subcat' => $selectedSubcat, ':assunto' => $selectedAssunto, ':uid' => $userId]);
            $items = $stmt->fetchAll();

            $assuntoName = $items[0]['subject_name'] ?? $assRes['name'];
            $pageTitle = $assuntoName;
            $pageDesc = count($items) . " documento(s) oficial(is) encontrado(s).";
        }
    }
}

// O vínculo só é carregado após a consulta que já aplicou o escopo do usuário.
if (($searchMode || $currentLevel === 4) && !empty($items)) {
    $tagsByDocument = $tagService->mapDocumentTags(array_map(static fn(array $item): int => (int)$item['id'], $items));
    foreach ($items as &$item) $item['tags'] = $tagsByDocument[(int)$item['id']] ?? [];
    unset($item);
}

$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
$navigationTrail = [
    ['kind' => 'Portal', 'label' => 'Categorias', 'url' => 'index.php'],
];

if ($searchMode) {
    $searchTrailLabel = $selectedTag !== null ? 'Tag: ' . $selectedTag['name'] : 'Resultados para “' . $searchQuery . '”';
    $navigationTrail[] = ['kind' => 'Pesquisa', 'label' => $searchTrailLabel, 'url' => null];
} else {
    if ($selectedCat !== '') {
        $categoryTrailName = $items[0]['category_name'] ?? ($catRes['name'] ?? ($subRes['category_name'] ?? ($assRes['category_name'] ?? $selectedCat)));
        $navigationTrail[] = [
            'kind' => 'Categoria',
            'label' => $categoryTrailName,
            'url' => 'index.php?cat=' . urlencode($selectedCat),
        ];
    }
    if ($selectedSubcat !== '') {
        $subcategoryTrailName = $items[0]['subcategory_name'] ?? ($subRes['name'] ?? ($assRes['subcategory_name'] ?? $selectedSubcat));
        $navigationTrail[] = [
            'kind' => 'Subcategoria',
            'label' => $subcategoryTrailName,
            'url' => 'index.php?cat=' . urlencode($selectedCat) . '&subcat=' . urlencode($selectedSubcat),
        ];
    }
    if ($selectedAssunto !== '') {
        $subjectTrailName = $items[0]['subject_name'] ?? ($assRes['name'] ?? $selectedAssunto);
        $navigationTrail[] = [
            'kind' => 'Assunto',
            'label' => $subjectTrailName,
            'url' => 'index.php?cat=' . urlencode($selectedCat) . '&subcat=' . urlencode($selectedSubcat) . '&assunto=' . urlencode($selectedAssunto),
        ];
    }
}

$lastTrailIndex = array_key_last($navigationTrail);
$navigationTrail[$lastTrailIndex]['url'] = null;

if ($userId > 0) {
    if ($searchMode) {
        $usageAuditService->log('search', $userId, 'PORTAL', null, [
            'query_length' => mb_strlen($searchQuery),
            'has_category_filter' => $searchCategory !== '',
            'has_type_filter' => $searchType !== '',
            'has_author_filter' => $searchAuthor !== '',
            'has_period_filter' => $searchDateFrom !== '' || $searchDateTo !== '',
            'tag_id' => $selectedTag['id'] ?? null,
        ]);
    } elseif ($currentLevel === 2 && !empty($catRes['id'])) {
        $usageAuditService->log('category_view', $userId, 'CATEGORY', (int)$catRes['id']);
    } elseif ($currentLevel === 3 && !empty($subRes['id'])) {
        $usageAuditService->log('subcategory_view', $userId, 'SUBCATEGORY', (int)$subRes['id']);
    } elseif ($currentLevel === 4 && !empty($assRes['id'])) {
        $usageAuditService->log('subject_view', $userId, 'SUBJECT', (int)$assRes['id']);
    } else {
        $usageAuditService->log('portal_view', $userId, 'PORTAL');
    }
}

$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
$totalFavsCount = count($favMapDocs) + count($favMapSubcats) + count($favMapSubjs);
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($appName) ?></title>
    <meta name="description" content="<?= htmlspecialchars($appDescription) ?> — <?= htmlspecialchars($organizationName) ?>.">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        darkMode: 'class',
        theme: {
          extend: {
            colors: {
              graphite: {
                950: '#171717',
                900: '#212121',
                800: '#2f2f2f',
                700: '#383838',
                600: '#424242'
              }
            }
          }
        }
      }
    </script>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('dark');
                document.documentElement.classList.remove('light');
            } else if (savedTheme === 'light') {
                document.documentElement.classList.remove('dark');
                document.documentElement.classList.add('light');
            }
        })();
    </script>
    
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-[#f8f9fa] dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 min-h-screen flex flex-col justify-between selection:bg-slate-800 selection:text-white dark:selection:bg-slate-200 dark:selection:text-slate-900">
    <?php require __DIR__ . '/partials/maintenance-banner.php'; ?>

    <div>
        <!-- NAVBAR FIXA, LEVE E DE LARGURA TOTAL -->
        <div class="fixed inset-x-0 top-0 z-50 border-b border-slate-200/80 bg-white/90 shadow-sm shadow-slate-900/5 backdrop-blur-md dark:border-slate-800/80 dark:bg-[#1f2128]/95">
            <header class="max-container">
                <div class="flex min-h-[58px] items-center justify-between gap-4">
                    
                    <!-- ESQUERDA: LOGO & LINK PRINCIPAL -->
                    <div class="flex items-center gap-6">
                        <a href="index.php" class="inline-flex items-center gap-2.5 group text-decoration-none shrink-0">
                            <?php if ($appLogoUrl): ?>
                                <img src="<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="h-8 w-8 rounded-xl border border-slate-200 bg-white object-contain p-0.5 shadow-xs transition-transform duration-200 group-hover:scale-105 dark:border-[#454956] dark:bg-[#353842]">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center font-bold text-xs shadow-xs group-hover:scale-105 transition-transform duration-200">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0L9 14m3-3l3 3"></path>
                                    </svg>
                                </div>
                            <?php endif; ?>
                            <span class="font-extrabold text-sm tracking-tight text-slate-900 dark:text-slate-100"><?= htmlspecialchars($appName) ?></span>
                        </a>

                        <!-- NAVEGAÇÃO PRINCIPAL (DESKTOP) -->
                        <nav class="hidden md:flex items-center gap-1">
                            <a href="index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white">
                                Início
                            </a>

                            <a href="favoritos.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50 transition flex items-center gap-1">
                                <span>Favoritos</span>
                                <?php if ($totalFavsCount > 0): ?>
                                    <span class="px-1.5 py-0.2 text-[10px] rounded-full bg-amber-500/20 text-amber-600 dark:text-amber-400 font-extrabold font-mono"><?= $totalFavsCount ?></span>
                                <?php endif; ?>
                            </a>
                        </nav>
                    </div>

                    <!-- DIREITA: PESQUISA INTELIGENTE + PERFIL DO USUÁRIO -->
                    <div class="flex items-center gap-3">
                        
                        <!-- BUSCA COM FILTROS INTEGRADOS -->
                        <form id="navbar-search-form" action="index.php" method="GET" class="relative hidden sm:block">
                            <div class="flex items-center rounded-xl border border-slate-200/80 bg-slate-100/70 transition-all duration-200 focus-within:ring-1 focus-within:ring-slate-400 dark:border-slate-700/80 dark:bg-slate-800/70">
                                <svg class="ml-2.5 h-3.5 w-3.5 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                                <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Pesquisar..." class="w-28 bg-transparent px-2 py-1.5 text-xs text-slate-900 outline-none transition-all duration-200 focus:w-48 dark:text-slate-100">
                                <button id="navbar-search-filter-toggle" type="button" onclick="toggleNavbarSearchFilters(event)" class="relative mr-1 inline-flex h-6 w-6 items-center justify-center rounded-lg text-slate-500 transition hover:bg-white hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-white" title="Filtros de busca" aria-label="Abrir filtros de busca" aria-expanded="<?= $hasAdvancedSearchFilters ? 'true' : 'false' ?>">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L15 12.414V18a1 1 0 01-.553.894l-4 2A1 1 0 019 20v-7.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                                    <?php if ($hasAdvancedSearchFilters): ?><span class="absolute right-0.5 top-0.5 h-1.5 w-1.5 rounded-full bg-emerald-500"></span><?php endif; ?>
                                </button>
                            </div>
                            <div id="navbar-search-filters" class="<?= $hasAdvancedSearchFilters ? '' : 'hidden' ?> absolute right-0 top-full z-[60] mt-2 w-[34rem] max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-3 shadow-xl shadow-slate-900/10 dark:border-[#454956] dark:bg-[#353842]">
                                <div class="mb-2 flex items-center justify-between"><span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Refinar busca</span><span class="text-[10px] text-slate-400">Conteúdos publicados</span></div>
                                <div class="grid grid-cols-2 gap-2">
                                    <label><span class="mb-1 block text-[10px] font-semibold text-slate-500 dark:text-slate-400">Categoria</span><select name="search_category" class="input-minimal w-full px-2 py-1.5 text-xs"><option value="">Todas</option><?php foreach ($searchCategoryOptions as $categoryOption): ?><option value="<?= htmlspecialchars($categoryOption['slug']) ?>" <?= $searchCategory === $categoryOption['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption['name']) ?></option><?php endforeach; ?></select></label>
                                    <label><span class="mb-1 block text-[10px] font-semibold text-slate-500 dark:text-slate-400">Tipo</span><select name="search_type" class="input-minimal w-full px-2 py-1.5 text-xs"><option value="">Todos</option><option value="file" <?= $searchType === 'file' ? 'selected' : '' ?>>Arquivo</option><option value="text" <?= $searchType === 'text' ? 'selected' : '' ?>>Texto</option><option value="code" <?= $searchType === 'code' ? 'selected' : '' ?>>Código</option><option value="video" <?= $searchType === 'video' ? 'selected' : '' ?>>Vídeo</option><option value="link" <?= $searchType === 'link' ? 'selected' : '' ?>>Link</option></select></label>
                                    <label><span class="mb-1 block text-[10px] font-semibold text-slate-500 dark:text-slate-400">Tag</span><select name="tag" class="input-minimal w-full px-2 py-1.5 text-xs"><option value="">Todas</option><?php foreach ($searchTagOptions as $tagOption): ?><option value="<?= htmlspecialchars($tagOption['name']) ?>" <?= $selectedTag !== null && (int)$selectedTag['id'] === (int)$tagOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tagOption['name']) ?></option><?php endforeach; ?></select></label>
                                    <label class="col-span-2"><span class="mb-1 block text-[10px] font-semibold text-slate-500 dark:text-slate-400">Autor</span><input type="search" name="search_author" value="<?= htmlspecialchars($searchAuthor) ?>" class="input-minimal w-full px-2 py-1.5 text-xs" placeholder="Nome do autor"></label>
                                    <label><span class="mb-1 block text-[10px] font-semibold text-slate-500 dark:text-slate-400">De</span><input type="date" name="date_from" value="<?= htmlspecialchars($searchDateFrom) ?>" class="input-minimal w-full px-2 py-1.5 text-xs"></label>
                                    <label><span class="mb-1 block text-[10px] font-semibold text-slate-500 dark:text-slate-400">Até</span><input type="date" name="date_to" value="<?= htmlspecialchars($searchDateTo) ?>" class="input-minimal w-full px-2 py-1.5 text-xs"></label>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 dark:border-[#454956]"><a href="index.php" class="text-xs font-semibold text-slate-500 transition hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Limpar</a><button type="submit" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 dark:bg-white dark:text-slate-900">Pesquisar</button></div>
                            </div>
                        </form>

                        <?php if ($loggedUser): ?>
                            <!-- PAINEL ADMIN SE FOR ADMIN/EDITOR -->
                            <?php if ($canAccessAdminPanel): ?>
                                <a href="admin/index.php" class="hidden md:inline-flex text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-3 py-1.5 rounded-xl hover:opacity-90 transition">
                                    Admin
                                </a>
                            <?php endif; ?>
                            <?php require __DIR__ . '/partials/notification_link.php'; ?>

                            <!-- DROPDOWN DO USUÁRIO LOGADO -->
                            <a href="minha_conta.php" class="flex items-center gap-2 p-1 pl-1.5 pr-2.5 rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-slate-50 dark:bg-slate-800/60 hover:bg-slate-100 dark:hover:bg-slate-800 transition text-xs font-medium text-slate-800 dark:text-slate-200">
                                <div class="w-6 h-6 rounded-lg bg-slate-200 dark:bg-slate-700 flex items-center justify-center font-bold text-[11px]">
                                    <?= htmlspecialchars($loggedUser['inicial'] ?? 'U') ?>
                                </div>
                                <span class="hidden sm:inline font-semibold"><?= htmlspecialchars($loggedUser['nome']) ?></span>
                            </a>

                            <a href="logout.php" class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 dark:hover:text-red-400 transition" title="Sair do Sistema">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-3.5 py-1.5 rounded-xl hover:opacity-90 transition">
                                Entrar
                            </a>
                        <?php endif; ?>

                        <!-- BOTÃO HAMBÚRGUER MOBILE -->
                        <button type="button" 
                                onclick="document.getElementById('mobile-menu-drawer').classList.toggle('hidden')"
                                class="md:hidden p-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>

                    </div>
                </div>

                <!-- DRAWER / MENU MOBILE COMPACTO -->
                <div id="mobile-menu-drawer" class="hidden md:hidden pt-3 pb-2 px-2 border-t border-slate-100 dark:border-slate-800 mt-2 space-y-1">
                    <a href="index.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">Início</a>
                    <a href="favoritos.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-amber-600 dark:text-amber-400 hover:bg-slate-100 dark:hover:bg-slate-800">Favoritos</a>
                    <?php if ($loggedUser): ?>
                        <a href="minha_conta.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">Minha Conta</a>
                        <?php if ($canAccessAdminPanel): ?>
                            <a href="admin/index.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">Painel Admin</a>
                        <?php endif; ?>
                        <a href="logout.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-slate-800">Sair</a>
                    <?php else: ?>
                        <a href="login.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Entrar</a>
                    <?php endif; ?>
                </div>

            </header>
        </div>

        <!-- CONTAINER PRINCIPAL -->
        <main class="max-container pb-10 pt-20 sm:pt-24">
            <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[10.5rem_minmax(0,1fr)] lg:gap-6">
                <?php require __DIR__ . '/partials/vertical_navigation.php'; ?>
                <section class="min-w-0">

            <!-- TÍTULO DA SEÇÃO PRINCIPAL -->
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">
                        <?= htmlspecialchars($pageTitle) ?>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        <?= htmlspecialchars($pageDesc) ?>
                    </p>
                </div>
            </div>

            <!-- ESTADO VAZIO (SEM CONTEÚDOS PERMITIDOS PARA O GRUPO / NENHUM REGISTRO) -->
            <?php if (empty($items)): ?>
                <div class="p-10 text-center bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956] shadow-xs space-y-3">
                    <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-[#2c2e33] text-slate-400 flex items-center justify-center mx-auto">
                        <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Nenhum conteúdo disponível para sua conta.</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                        Sua conta não possui permissão de acesso às equipes desta área ou nenhum item publicado atende ao filtro.
                    </p>
                </div>
            <?php else: ?>

            <!-- NÍVEL 1: LISTA CATEGORIAS -->
            <?php if ($currentLevel === 1 && !$searchMode): ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <?php foreach ($items as $cat): ?>
                        <a href="index.php?cat=<?= urlencode($cat['slug']) ?>" 
                           class="group min-h-32 p-3 rounded-lg bg-white dark:bg-[#353842] border border-slate-200/80 dark:border-[#454956] hover:border-slate-400 dark:hover:border-slate-500 hover:-translate-y-0.5 transition shadow-xs flex flex-col justify-between focus:outline-none focus:ring-2 focus:ring-slate-400">
                            <div>
                                <?php if (!empty($cat['image_path'])): ?>
                                    <img src="category_image.php?id=<?= (int)$cat['id'] ?>&amp;v=<?= urlencode((string)$cat['image_path']) ?>" alt="" class="w-7 h-7 rounded object-cover border border-slate-200 dark:border-[#454956] mb-2" loading="lazy">
                                <?php else: ?>
                                    <div class="w-7 h-7 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-800 dark:text-slate-200 flex items-center justify-center font-bold text-xs mb-2 group-hover:bg-slate-900 group-hover:text-white dark:group-hover:bg-white dark:group-hover:text-slate-900 transition">
                                        <svg class="w-3.5 h-3.5 text-slate-700 dark:text-slate-300 group-hover:text-white dark:group-hover:text-slate-900 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    </div>
                                <?php endif; ?>
                                <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover:text-slate-900 dark:group-hover:text-white line-clamp-2">
                                    <?= htmlspecialchars($cat['name']) ?>
                                </h3>
                                <p class="text-[10px] leading-4 text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                    <?= htmlspecialchars($cat['description'] ?: 'Documentação oficial e manuais.') ?>
                                </p>
                            </div>
                            <div class="mt-2 pt-2 border-t border-slate-100 dark:border-[#454956] flex items-center justify-between text-[9px] text-slate-400 font-medium">
                                <span><?= (int)$cat['total_subcat'] ?> subcat.</span>
                                <span><?= (int)$cat['total_docs'] ?> docs</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

            <!-- NÍVEL 2: LISTA SUBCATEGORIAS COM FAVORITO ★ -->
            <?php elseif ($currentLevel === 2 && !$searchMode): ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <?php foreach ($items as $subcat): ?>
                        <?php $isFavSub = isset($favMapSubcats[(int)$subcat['id']]); ?>
                        <div class="group relative min-h-28 overflow-hidden p-3 rounded-lg bg-white dark:bg-[#353842] border <?= $isFavSub ? 'border-amber-500/40 dark:border-amber-500/50' : 'border-slate-200/80 dark:border-[#454956]' ?> hover:border-slate-400 dark:hover:border-slate-500 hover:-translate-y-0.5 transition shadow-xs flex flex-col justify-between">
                            <a href="index.php?cat=<?= urlencode($selectedCat) ?>&subcat=<?= urlencode($subcat['slug']) ?>" class="absolute inset-0 z-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400" aria-label="Abrir subcategoria <?= htmlspecialchars($subcat['name']) ?>"></a>
                            <div class="relative z-[1] pointer-events-none">
                                <?php if (!empty($subcat['image_path'])): ?>
                                    <img src="subcategory_image.php?id=<?= (int)$subcat['id'] ?>&amp;v=<?= urlencode((string)$subcat['image_path']) ?>" alt="" class="mb-2 h-7 w-7 rounded object-cover border border-slate-200 dark:border-[#454956]" loading="lazy">
                                <?php else: ?>
                                    <div class="mb-2 flex h-7 w-7 items-center justify-center rounded bg-slate-100 text-slate-700 transition group-hover:bg-slate-900 group-hover:text-white dark:bg-[#2c2e33] dark:text-slate-300 dark:group-hover:bg-white dark:group-hover:text-slate-900">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7a2 2 0 012-2h3l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V7z"/></svg>
                                    </div>
                                <?php endif; ?>
                                <div class="flex items-start justify-between gap-2 mb-1">
                                    <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100 line-clamp-2">
                                        <?= htmlspecialchars($subcat['name']) ?>
                                    </h3>
                                    <?php if ($loggedUser): ?>
                                        <button type="button" 
                                                aria-label="<?= $isFavSub ? 'Remover subcategoria dos favoritos' : 'Favoritar subcategoria' ?>"
                                                title="<?= $isFavSub ? 'Remover subcategoria dos favoritos' : 'Favoritar subcategoria' ?>"
                                                onclick="toggleEntityFavorito(<?= $subcat['id'] ?>, 'subcategory', this, event)"
                                                class="favorite-card-button pointer-events-auto"
                                                data-favorite-card-control>
                                            <svg class="favorite-card-button__icon<?= $isFavSub ? ' is-saved' : '' ?>" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[10px] leading-4 text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                    <?= htmlspecialchars($subcat['description'] ?: 'Subcategoria de conteúdo.') ?>
                                </p>
                            </div>
                            <div class="relative z-[1] pointer-events-none mt-2 pt-2 border-t border-slate-100 dark:border-[#454956] flex items-center justify-between text-[9px] text-slate-400 font-medium">
                                <span><?= (int)($subcat['total_assuntos'] ?? 0) ?> assuntos</span>
                                <span aria-hidden="true">Abrir &rarr;</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- NÍVEL 3: LISTA ASSUNTOS COM FAVORITO ★ -->
            <?php elseif ($currentLevel === 3 && !$searchMode): ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <?php foreach ($items as $assunto): ?>
                        <?php $isFavSubj = isset($favMapSubjs[(int)$assunto['id']]); ?>
                        <div class="group relative min-h-28 overflow-hidden p-3 rounded-lg bg-white dark:bg-[#353842] border <?= $isFavSubj ? 'border-amber-500/40 dark:border-amber-500/50' : 'border-slate-200/80 dark:border-[#454956]' ?> hover:border-slate-400 dark:hover:border-slate-500 hover:-translate-y-0.5 transition shadow-xs flex flex-col justify-between">
                            <a href="index.php?cat=<?= urlencode($selectedCat) ?>&subcat=<?= urlencode($selectedSubcat) ?>&assunto=<?= urlencode($assunto['slug']) ?>" class="absolute inset-0 z-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400" aria-label="Abrir assunto <?= htmlspecialchars($assunto['name']) ?>"></a>
                            <div class="relative z-[1] pointer-events-none">
                                <div class="flex items-start justify-between gap-2 mb-1">
                                    <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100 line-clamp-2">
                                        <?= htmlspecialchars($assunto['name']) ?>
                                    </h3>
                                    <?php if ($loggedUser): ?>
                                        <button type="button" 
                                                aria-label="<?= $isFavSubj ? 'Remover assunto dos favoritos' : 'Favoritar assunto' ?>"
                                                title="<?= $isFavSubj ? 'Remover assunto dos favoritos' : 'Favoritar assunto' ?>"
                                                onclick="toggleEntityFavorito(<?= $assunto['id'] ?>, 'subject', this, event)"
                                                class="favorite-card-button pointer-events-auto"
                                                data-favorite-card-control>
                                            <svg class="favorite-card-button__icon<?= $isFavSubj ? ' is-saved' : '' ?>" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[10px] leading-4 text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                    <?= htmlspecialchars($assunto['description'] ?: 'Coleção de documentos oficiais.') ?>
                                </p>
                            </div>
                            <div class="relative z-[1] pointer-events-none mt-2 pt-2 border-t border-slate-100 dark:border-[#454956] flex items-center justify-between text-[9px] text-slate-400 font-medium">
                                <span><?= (int)($assunto['total_docs'] ?? 0) ?> documento(s)</span>
                                <span aria-hidden="true">Abrir &rarr;</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- NÍVEL 4 / PESQUISA: LISTA DE DOCUMENTOS COM ESTRELA DISCRETA ★ -->
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                    <?php if (empty($items)): ?>
                        <div class="p-8 text-center bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956]">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Nenhum documento encontrado nesta categoria ou filtro.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($items as $doc): ?>
                            <?php $isFavDoc = isset($favMapDocs[(int)$doc['id']]); ?>
                            <div class="group relative min-h-28 overflow-hidden p-3 rounded-lg bg-white dark:bg-[#353842] border <?= $isFavDoc ? 'border-amber-500/40 dark:border-amber-500/50' : 'border-slate-200/80 dark:border-[#454956]' ?> hover:border-slate-400 dark:hover:border-slate-500 hover:-translate-y-0.5 transition shadow-xs flex flex-col justify-between">
                                <a href="ver_conteudo.php?id=<?= $doc['id'] ?>" class="absolute inset-0 z-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400" aria-label="Visualizar <?= htmlspecialchars($doc['title']) ?>"></a>
                                <div class="relative z-[1] pointer-events-none flex items-start gap-2.5">
                                    <div class="w-7 h-7 rounded bg-slate-100 dark:bg-[#2c2e33] flex items-center justify-center shrink-0">
                                        <?php if (($doc['content_type'] ?? '') === 'link'): ?>
                                            <svg class="w-3.5 h-3.5 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                        <?php elseif (($doc['content_type'] ?? '') === 'video'): ?>
                                            <svg class="w-3.5 h-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        <?php elseif (($doc['content_type'] ?? '') === 'code'): ?>
                                            <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l-3 3 3 3m8-6l3 3-3 3m-3-8l-2 10"/></svg>
                                        <?php elseif (($doc['content_type'] ?? '') === 'text'): ?>
                                            <svg class="w-3.5 h-3.5 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <?php else: ?>
                                            <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="min-w-0 flex-1 text-xs font-bold leading-4 text-slate-900 dark:text-slate-100 line-clamp-2">
                                        <?= $searchMode ? highlightSearchTerm((string)$doc['title'], $searchQuery) : htmlspecialchars($doc['title']) ?>
                                    </h3>
                                    <?php if ($loggedUser): ?>
                                        <button type="button" 
                                                aria-label="<?= $isFavDoc ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"
                                                title="<?= $isFavDoc ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"
                                                onclick="toggleEntityFavorito(<?= $doc['id'] ?>, 'document', this, event)"
                                                class="favorite-card-button pointer-events-auto"
                                                data-favorite-card-control>
                                            <svg class="favorite-card-button__icon<?= $isFavDoc ? ' is-saved' : '' ?>" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php if ($searchMode && !empty($doc['description'])): ?>
                                    <p class="relative z-[1] pointer-events-none mt-1 line-clamp-1 text-[10px] text-slate-500 dark:text-slate-400"><?= highlightSearchTerm((string)$doc['description'], $searchQuery) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($doc['tags'])): ?>
                                    <div class="relative z-[1] pointer-events-auto mt-1.5 flex flex-wrap gap-1">
                                        <?php foreach (array_slice($doc['tags'], 0, 3) as $tag): ?>
                                            <a href="index.php?tag=<?= urlencode($tag['name']) ?>" class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold text-slate-500 transition hover:bg-slate-200 hover:text-slate-800 dark:bg-[#2c2e33] dark:text-slate-300 dark:hover:bg-[#454956]" title="Ver documentos com a tag <?= htmlspecialchars($tag['name']) ?>"><?= htmlspecialchars($tag['name']) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="relative z-[1] pointer-events-none mt-2 pt-2 border-t border-slate-100 dark:border-[#454956] flex items-end justify-between gap-2">
                                    <p class="min-w-0 text-[9px] leading-3 text-slate-400 line-clamp-2">
                                        <?= htmlspecialchars($doc['category_name'] ?? '') ?> / <?= htmlspecialchars($doc['subcategory_name'] ?? '') ?> / <?= htmlspecialchars($doc['subject_name'] ?? '') ?>
                                    </p>
                                    <span class="shrink-0 text-[9px] font-semibold text-slate-500 dark:text-slate-300">Abrir &rarr;</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>

                </section>
            </div>

        </main>
    </div>

    <!-- RODAPÉ -->
    <footer class="border-t border-slate-200/80 dark:border-[#454956] py-6 text-center text-xs text-slate-400">
        <?= htmlspecialchars($appName) ?> &bull; <?= htmlspecialchars($appDescription) ?> &bull; <?= htmlspecialchars($organizationName) ?>
    </footer>

    <script>
        function toggleEntityFavorito(targetId, targetType, btnElem, event) {
            if (event) event.stopPropagation();
            if (btnElem) btnElem.disabled = true;

            fetch('api_user.php?action=toggle_favorito&type=' + targetType + '&target_id=' + targetId)
                .then(r => r.json())
                .then(data => {
                    if (btnElem) btnElem.disabled = false;
                    if (data.success) {
                        const svg = btnElem.querySelector('svg');
                        if (data.is_favorite) {
                            btnElem.setAttribute('aria-label', 'Remover dos favoritos');
                            btnElem.setAttribute('title', 'Remover dos favoritos');
                            if (svg) svg.classList.add('is-saved');
                        } else {
                            btnElem.setAttribute('aria-label', 'Adicionar aos favoritos');
                            btnElem.setAttribute('title', 'Adicionar aos favoritos');
                            if (svg) svg.classList.remove('is-saved');
                        }
                    } else {
                        alert('Não foi possível atualizar o favorito.');
                    }
                })
                .catch(() => {
                    if (btnElem) btnElem.disabled = false;
                    alert('Não foi possível atualizar o favorito.');
                });
        }

        function toggleNavbarSearchFilters(event) {
            if (event) event.stopPropagation();

            const panel = document.getElementById('navbar-search-filters');
            const trigger = document.getElementById('navbar-search-filter-toggle');
            if (!panel || !trigger) return;

            const willOpen = panel.classList.contains('hidden');
            panel.classList.toggle('hidden', !willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }

        document.addEventListener('click', function (event) {
            const form = document.getElementById('navbar-search-form');
            const panel = document.getElementById('navbar-search-filters');
            const trigger = document.getElementById('navbar-search-filter-toggle');

            if (form && panel && !panel.classList.contains('hidden') && !form.contains(event.target)) {
                panel.classList.add('hidden');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;

            const panel = document.getElementById('navbar-search-filters');
            const trigger = document.getElementById('navbar-search-filter-toggle');
            if (panel && !panel.classList.contains('hidden')) {
                panel.classList.add('hidden');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
            }
        });
    </script>
</body>
</html>
