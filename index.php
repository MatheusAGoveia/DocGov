<?php
// index.php - Portal de Documentos da Prefeitura (PostgreSQL Fonte Única de Verdade)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

require_once __DIR__ . '/services/AccessService.php';
$accessService = new AccessService($pdo);

$allowedCatIds = $accessService->getAllowedCategoryIds($userId);
$allowedSubcatIds = $accessService->getAllowedSubcategoryIds($userId);
$allowedSubjectIds = $accessService->getAllowedSubjectIds($userId);

// Captura Parâmetros de Navegação via GET
$selectedCat = trim($_GET['cat'] ?? '');
$selectedSubcat = trim($_GET['subcat'] ?? '');
$selectedAssunto = trim($_GET['assunto'] ?? '');
$currentView = trim($_GET['view'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');
$searchMode = $searchQuery !== '';

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
$pageDesc = "Encontre rapidamente documentos, normas, manuais e orientações da Prefeitura Municipal.";

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
        $searchLike = '%' . mb_strtolower($searchQuery) . '%';
        $subInSql = implode(',', array_map('intval', $allowedSubjectIds));

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
            WHERE d.status = 'published' AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE
              AND d.subject_id IN ($subInSql)
              AND (
                  LOWER(d.title) LIKE :q OR 
                  LOWER(d.description) LIKE :q OR 
                  LOWER(s.name) LIKE :q OR 
                  LOWER(sc.name) LIKE :q OR 
                  LOWER(c.name) LIKE :q
              )
            ORDER BY is_favorited DESC, c.name ASC, sc.name ASC, s.name ASC, d.title ASC
        ");
        $stmt->execute([':q' => $searchLike, ':uid' => $userId]);
        $items = $stmt->fetchAll();
        $pageTitle = "Resultados da pesquisa";
        $pageDesc = count($items) . " resultado(s) encontrado(s) para \"" . htmlspecialchars($searchQuery) . "\".";
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
                    c.id, c.name, c.slug, c.description,
                    COUNT(DISTINCT sc.id) AS total_subcat,
                    COUNT(DISTINCT d.id) AS total_docs
                FROM categories c
                LEFT JOIN subcategories sc ON sc.category_id = c.id AND sc.active = TRUE AND sc.id IN ($subcatInSql)
                LEFT JOIN subjects s ON s.subcategory_id = sc.id AND s.active = TRUE AND s.id IN ($subInSql)
                LEFT JOIN documents d ON d.subject_id = s.id AND d.status = 'published'
                WHERE c.active = TRUE AND c.id IN ($catInSql)
                GROUP BY c.id, c.name, c.slug, c.description
                ORDER BY c.name ASC
            ");
            $items = $stmt->fetchAll();
        }
        $pageTitle = "Documentos e Referências";
        $pageDesc = "Encontre rapidamente documentos, normas, manuais e orientações da Prefeitura Municipal.";

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
                    sc.id, sc.name, sc.slug, sc.description,
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
                GROUP BY sc.id, sc.name, sc.slug, sc.description, c.name, c.slug, f.id
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
            SELECT sc.id, sc.name FROM subcategories sc 
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
            SELECT s.id, s.name FROM subjects s
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

$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';

$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
$totalFavsCount = count($favMapDocs) + count($favMapSubcats) + count($favMapSubjs);
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Prefeitura Municipal</title>
    <meta name="description" content="Portal oficial de documentos públicos da Prefeitura Municipal.">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        darkMode: 'class',
        theme: {
          extend: {
            colors: {
              graphite: {
                950: '#181a1f',
                900: '#23252a',
                800: '#2c2e33',
                700: '#353842',
                600: '#454956'
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

    <div>
        <!-- NAVBAR FLUTUANTE COMPACTA (FLOATING NAVBAR) -->
        <div class="sticky top-4 z-50 w-full max-w-5xl mx-auto px-4 mb-4">
            <header class="bg-white/85 dark:bg-[#1f2128]/90 backdrop-blur-md border border-slate-200/80 dark:border-slate-800/80 shadow-md shadow-slate-900/5 rounded-2xl px-4 py-2.5 transition-all duration-200">
                <div class="flex items-center justify-between gap-4">
                    
                    <!-- ESQUERDA: LOGO & LINK PRINCIPAL -->
                    <div class="flex items-center gap-6">
                        <a href="index.php" class="inline-flex items-center gap-2.5 group text-decoration-none shrink-0">
                            <div class="w-8 h-8 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center font-bold text-xs shadow-xs group-hover:scale-105 transition-transform duration-200">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0L9 14m3-3l3 3"></path>
                                </svg>
                            </div>
                            <span class="font-extrabold text-sm tracking-tight text-slate-900 dark:text-slate-100">DocGov</span>
                        </a>

                        <!-- NAVEGAÇÃO PRINCIPAL (DESKTOP) -->
                        <nav class="hidden md:flex items-center gap-1">
                            <a href="index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white">
                                Início
                            </a>

                            <a href="favoritos.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50 transition flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-amber-500 fill-amber-500" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                <span>Favoritos</span>
                                <?php if ($totalFavsCount > 0): ?>
                                    <span class="px-1.5 py-0.2 text-[10px] rounded-full bg-amber-500/20 text-amber-600 dark:text-amber-400 font-extrabold font-mono"><?= $totalFavsCount ?></span>
                                <?php endif; ?>
                            </a>
                        </nav>
                    </div>

                    <!-- DIREITA: PESQUISA INTELIGENTE + PERFIL DO USUÁRIO -->
                    <div class="flex items-center gap-3">
                        
                        <!-- BUSCA EXPANSÍVEL OU COMPACTA -->
                        <form action="index.php" method="GET" class="relative hidden sm:block">
                            <input type="text" 
                                   name="q" 
                                   value="<?= htmlspecialchars($searchQuery) ?>"
                                   placeholder="Pesquisar..." 
                                   class="w-36 focus:w-56 pl-8 pr-3 py-1.5 text-xs rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-slate-100/70 dark:bg-slate-800/70 text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-slate-400 transition-all duration-200">
                            <svg class="w-3.5 h-3.5 absolute left-2.5 top-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </form>

                        <?php if ($loggedUser): ?>
                            <!-- PAINEL ADMIN SE FOR ADMIN/EDITOR -->
                            <?php if (($loggedUser['role'] ?? '') === 'admin' || ($loggedUser['role'] ?? '') === 'editor'): ?>
                                <a href="admin/index.php" class="hidden md:inline-flex text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-3 py-1.5 rounded-xl hover:opacity-90 transition">
                                    Admin
                                </a>
                            <?php endif; ?>

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
                        <?php if (($loggedUser['role'] ?? '') === 'admin' || ($loggedUser['role'] ?? '') === 'editor'): ?>
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
        <main class="max-container py-8">
            
            <!-- BREADCRUMB NAVEGAÇÃO -->
            <nav class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400 mb-6 flex-wrap">
                <a href="index.php" class="hover:text-slate-900 dark:hover:text-white transition">Início</a>
                <?php if (!empty($selectedCat)): ?>
                    <span>/</span>
                    <a href="index.php?cat=<?= urlencode($selectedCat) ?>" class="hover:text-slate-900 dark:hover:text-white font-medium text-slate-800 dark:text-slate-200">
                        <?= htmlspecialchars($items[0]['category_name'] ?? $selectedCat) ?>
                    </a>
                <?php endif; ?>
                <?php if (!empty($selectedSubcat)): ?>
                    <span>/</span>
                    <a href="index.php?cat=<?= urlencode($selectedCat) ?>&subcat=<?= urlencode($selectedSubcat) ?>" class="hover:text-slate-900 dark:hover:text-white font-medium text-slate-800 dark:text-slate-200">
                        <?= htmlspecialchars($items[0]['subcategory_name'] ?? $selectedSubcat) ?>
                    </a>
                <?php endif; ?>
                <?php if (!empty($selectedAssunto)): ?>
                    <span>/</span>
                    <span class="font-bold text-slate-900 dark:text-white">
                        <?= htmlspecialchars($items[0]['subject_name'] ?? $selectedAssunto) ?>
                    </span>
                <?php endif; ?>
            </nav>

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
                        Sua conta não possui permissão de acesso aos grupos desta área ou nenhum item publicado atende ao filtro.
                    </p>
                </div>
            <?php else: ?>

            <!-- NÍVEL 1: LISTA CATEGORIAS -->
            <?php if ($currentLevel === 1 && !$searchMode): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <?php foreach ($items as $cat): ?>
                        <a href="index.php?cat=<?= urlencode($cat['slug']) ?>" 
                           class="group p-5 rounded-md bg-white dark:bg-[#353842] border border-slate-200/80 dark:border-[#454956] hover:border-slate-400 dark:hover:border-slate-500 transition shadow-xs flex flex-col justify-between">
                            <div>
                                <div class="w-8 h-8 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-800 dark:text-slate-200 flex items-center justify-center font-bold text-xs mb-3 group-hover:bg-slate-900 group-hover:text-white dark:group-hover:bg-white dark:group-hover:text-slate-900 transition">
                                    <svg class="w-4 h-4 text-slate-700 dark:text-slate-300 group-hover:text-white dark:group-hover:text-slate-900 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                </div>
                                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 group-hover:text-slate-900 dark:group-hover:text-white">
                                    <?= htmlspecialchars($cat['name']) ?>
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                    <?= htmlspecialchars($cat['description'] ?: 'Documentação oficial e manuais.') ?>
                                </p>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 dark:border-[#454956] flex items-center justify-between text-[11px] text-slate-400 font-medium">
                                <span><?= (int)$cat['total_subcat'] ?> subcategorias</span>
                                <span><?= (int)$cat['total_docs'] ?> docs</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

            <!-- NÍVEL 2: LISTA SUBCATEGORIAS COM FAVORITO ★ -->
            <?php elseif ($currentLevel === 2 && !$searchMode): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($items as $subcat): ?>
                        <?php $isFavSub = isset($favMapSubcats[(int)$subcat['id']]); ?>
                        <div class="group p-4 rounded-md bg-white dark:bg-[#353842] border <?= $isFavSub ? 'border-amber-500/40 dark:border-amber-500/50' : 'border-slate-200/80 dark:border-[#454956]' ?> hover:border-slate-400 dark:hover:border-slate-500 transition shadow-xs flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <a href="index.php?cat=<?= urlencode($selectedCat) ?>&subcat=<?= urlencode($subcat['slug']) ?>" class="text-sm font-bold text-slate-900 dark:text-slate-100 group-hover:text-slate-900 dark:group-hover:text-white hover:underline">
                                        <?= htmlspecialchars($subcat['name']) ?>
                                    </a>
                                    <?php if ($loggedUser): ?>
                                        <button type="button" 
                                                aria-label="<?= $isFavSub ? 'Remover subcategoria dos favoritos' : 'Favoritar subcategoria' ?>"
                                                title="<?= $isFavSub ? 'Remover subcategoria dos favoritos' : 'Favoritar subcategoria' ?>"
                                                onclick="toggleEntityFavorito(<?= $subcat['id'] ?>, 'subcategory', this, event)"
                                                class="p-1 rounded-full hover:bg-slate-100 dark:hover:bg-[#2c2e33] transition cursor-pointer shrink-0">
                                            <svg class="w-4 h-4 <?= $isFavSub ? 'fill-amber-500 text-amber-500 stroke-amber-500' : 'fill-none text-slate-400 stroke-currentColor hover:text-amber-500' ?>" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                    <?= htmlspecialchars($subcat['description'] ?: 'Subcategoria de conteúdo.') ?>
                                </p>
                            </div>
                            <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-[#454956] flex items-center justify-between text-[11px] text-slate-400 font-medium">
                                <span><?= (int)($subcat['total_assuntos'] ?? 0) ?> assuntos</span>
                                <a href="index.php?cat=<?= urlencode($selectedCat) ?>&subcat=<?= urlencode($subcat['slug']) ?>" class="hover:underline">Acessar &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- NÍVEL 3: LISTA ASSUNTOS COM FAVORITO ★ -->
            <?php elseif ($currentLevel === 3 && !$searchMode): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($items as $assunto): ?>
                        <?php $isFavSubj = isset($favMapSubjs[(int)$assunto['id']]); ?>
                        <div class="group p-4 rounded-md bg-white dark:bg-[#353842] border <?= $isFavSubj ? 'border-amber-500/40 dark:border-amber-500/50' : 'border-slate-200/80 dark:border-[#454956]' ?> hover:border-slate-400 dark:hover:border-slate-500 transition shadow-xs flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <a href="index.php?cat=<?= urlencode($selectedCat) ?>&subcat=<?= urlencode($selectedSubcat) ?>&assunto=<?= urlencode($assunto['slug']) ?>" class="text-sm font-bold text-slate-900 dark:text-slate-100 group-hover:text-slate-900 dark:group-hover:text-white hover:underline">
                                        <?= htmlspecialchars($assunto['name']) ?>
                                    </a>
                                    <?php if ($loggedUser): ?>
                                        <button type="button" 
                                                aria-label="<?= $isFavSubj ? 'Remover assunto dos favoritos' : 'Favoritar assunto' ?>"
                                                title="<?= $isFavSubj ? 'Remover assunto dos favoritos' : 'Favoritar assunto' ?>"
                                                onclick="toggleEntityFavorito(<?= $assunto['id'] ?>, 'subject', this, event)"
                                                class="p-1 rounded-full hover:bg-slate-100 dark:hover:bg-[#2c2e33] transition cursor-pointer shrink-0">
                                            <svg class="w-4 h-4 <?= $isFavSubj ? 'fill-amber-500 text-amber-500 stroke-amber-500' : 'fill-none text-slate-400 stroke-currentColor hover:text-amber-500' ?>" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                    <?= htmlspecialchars($assunto['description'] ?: 'Coleção de documentos oficiais.') ?>
                                </p>
                            </div>
                            <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-[#454956] flex items-center justify-between text-[11px] text-slate-400 font-medium">
                                <span><?= (int)($assunto['total_docs'] ?? 0) ?> documento(s)</span>
                                <a href="index.php?cat=<?= urlencode($selectedCat) ?>&subcat=<?= urlencode($selectedSubcat) ?>&assunto=<?= urlencode($assunto['slug']) ?>" class="hover:underline">Acessar &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- NÍVEL 4 / PESQUISA: LISTA DE DOCUMENTOS COM ESTRELA DISCRETA ★ -->
            <?php else: ?>
                <div class="space-y-3">
                    <?php if (empty($items)): ?>
                        <div class="p-8 text-center bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956]">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Nenhum documento encontrado nesta categoria ou filtro.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($items as $doc): ?>
                            <?php $isFavDoc = isset($favMapDocs[(int)$doc['id']]); ?>
                            <div class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200/80 dark:border-[#454956] flex items-center justify-between gap-4 hover:border-slate-300 dark:hover:border-slate-600 transition shadow-xs">
                                <div class="flex items-center gap-3.5">
                                    <div class="w-9 h-9 rounded bg-slate-100 dark:bg-[#2c2e33] flex items-center justify-center shrink-0">
                                        <?php if (($doc['content_type'] ?? '') === 'link'): ?>
                                            <svg class="w-4 h-4 text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                        <?php elseif (($doc['content_type'] ?? '') === 'text'): ?>
                                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <?php else: ?>
                                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <a href="ver_conteudo.php?id=<?= $doc['id'] ?>" class="text-xs font-bold text-slate-900 dark:text-slate-100 hover:underline">
                                                <?= htmlspecialchars($doc['title']) ?>
                                            </a>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                            <?= htmlspecialchars($doc['category_name'] ?? '') ?> &bull; <?= htmlspecialchars($doc['subcategory_name'] ?? '') ?> &bull; <?= htmlspecialchars($doc['subject_name'] ?? '') ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2.5 shrink-0">
                                    <?php if ($loggedUser): ?>
                                        <button type="button" 
                                                aria-label="<?= $isFavDoc ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"
                                                title="<?= $isFavDoc ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"
                                                onclick="toggleEntityFavorito(<?= $doc['id'] ?>, 'document', this, event)"
                                                class="p-1.5 rounded-full hover:bg-slate-100 dark:hover:bg-[#2c2e33] transition cursor-pointer">
                                            <svg class="w-4 h-4 <?= $isFavDoc ? 'fill-amber-500 text-amber-500 stroke-amber-500' : 'fill-none text-slate-400 stroke-currentColor hover:text-amber-500' ?>" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <a href="ver_conteudo.php?id=<?= $doc['id'] ?>" class="text-xs font-semibold bg-slate-100 dark:bg-[#2c2e33] hover:bg-slate-200 text-slate-700 dark:text-slate-200 px-3 py-1.5 rounded transition">
                                        Visualizar
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>

        </main>
    </div>

    <!-- RODAPÉ -->
    <footer class="border-t border-slate-200/80 dark:border-[#454956] py-6 text-center text-xs text-slate-400">
        DocGov &bull; Sistema de Gestão Documental &bull; Prefeitura Municipal
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
                            if (svg) svg.setAttribute('class', 'w-4 h-4 fill-amber-500 text-amber-500 stroke-amber-500');
                        } else {
                            btnElem.setAttribute('aria-label', 'Adicionar aos favoritos');
                            btnElem.setAttribute('title', 'Adicionar aos favoritos');
                            if (svg) svg.setAttribute('class', 'w-4 h-4 fill-none text-slate-400 stroke-currentColor hover:text-amber-500');
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
    </script>
</body>
</html>
