<?php
// favoritos.php — Página Dedicada de Gestão dos Favoritos (Documentos, Subcategorias e Assuntos)
require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';

$loggedUser = $_SESSION['user'] ?? null;
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

$searchQuery = trim($_GET['q'] ?? '');
$filterType = trim($_GET['type'] ?? 'todos');

require_once __DIR__ . '/services/AccessService.php';
$accessService = new AccessService($pdo);
require_once __DIR__ . '/services/PermissionService.php';
$permissionService = new PermissionService($pdo);
$canAccessAdminPanel = $loggedUser && $permissionService->canAccessAdminPanel($userId);
require_once __DIR__ . '/services/NotificationService.php';
$notificationService = new NotificationService($pdo);
$unreadNotificationCount = $loggedUser ? $notificationService->unreadCount($userId) : 0;

$allowedCatIds = $accessService->getAllowedCategoryIds($userId);
$allowedSubcatIds = $accessService->getAllowedSubcategoryIds($userId);
$allowedSubjectIds = $accessService->getAllowedSubjectIds($userId);

$favDocs = [];
$favSubcats = [];
$favSubjs = [];

if ($loggedUser && $userId > 0) {
    try {
        // 1. Favoritos de Documentos (Somente de assuntos permitidos)
        if (!empty($allowedSubjectIds)) {
            $subInSqlFav = implode(',', array_map('intval', $allowedSubjectIds));
            $stmtD = $pdo->prepare("
                SELECT d.id, d.title, d.slug, d.description, d.content_type, d.file_extension, d.mime_type, d.updated_at,
                       s.name AS subject_name, sc.name AS subcategory_name, c.name AS category_name,
                       f.created_at AS favoritado_em
                FROM favorites f
                JOIN documents d ON d.id = f.document_id
                JOIN subjects s ON s.id = d.subject_id
                JOIN subcategories sc ON sc.id = s.subcategory_id
                JOIN categories c ON c.id = sc.category_id
                WHERE f.user_id = :uid AND d.status = 'published' AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE
                  AND d.subject_id IN ($subInSqlFav)
                ORDER BY f.created_at DESC
            ");
            $stmtD->execute([':uid' => $userId]);
            $favDocs = $stmtD->fetchAll();
        }

        // 2. Favoritos de Subcategorias (Somente subcategorias permitidas)
        if (!empty($allowedSubcatIds)) {
            $subcatInSqlFav = implode(',', array_map('intval', $allowedSubcatIds));
            $stmtSC = $pdo->prepare("
                SELECT sc.id, sc.name, sc.slug, sc.description, sc.image_path,
                       c.name AS category_name, c.slug AS category_slug,
                       f.created_at AS favoritado_em
                FROM favorites f
                JOIN subcategories sc ON sc.id = f.subcategory_id
                JOIN categories c ON c.id = sc.category_id
                WHERE f.user_id = :uid AND sc.active = TRUE AND c.active = TRUE
                  AND sc.id IN ($subcatInSqlFav)
                ORDER BY f.created_at DESC
            ");
            $stmtSC->execute([':uid' => $userId]);
            $favSubcats = $stmtSC->fetchAll();
        }

        // 3. Favoritos de Assuntos (Somente assuntos permitidos)
        if (!empty($allowedSubjectIds)) {
            $subInSqlFav = implode(',', array_map('intval', $allowedSubjectIds));
            $stmtS = $pdo->prepare("
                SELECT s.id, s.name, s.slug, s.description,
                       sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                       c.name AS category_name, c.slug AS category_slug,
                       f.created_at AS favoritado_em
                FROM favorites f
                JOIN subjects s ON s.id = f.subject_id
                JOIN subcategories sc ON sc.id = s.subcategory_id
                JOIN categories c ON c.id = sc.category_id
                WHERE f.user_id = :uid AND s.active = TRUE AND sc.active = TRUE AND c.active = TRUE
                  AND s.id IN ($subInSqlFav)
                ORDER BY f.created_at DESC
            ");
            $stmtS->execute([':uid' => $userId]);
            $favSubjs = $stmtS->fetchAll();
        }

    } catch (Exception $e) {
        $favDocs = []; $favSubcats = []; $favSubjs = [];
    }
}

$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
$totalFavs = count($favDocs) + count($favSubcats) + count($favSubjs);
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Favoritos - <?= htmlspecialchars($appName) ?></title>
    
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
    <style>
        .fav-card-item {
            transition: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
        }
        .fav-item-fadeout {
            opacity: 0;
            transform: scale(0.97);
        }
    </style>
</head>
<body class="bg-[#f8f9fa] dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 min-h-screen flex flex-col justify-between selection:bg-slate-800 selection:text-white">
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
                            <a href="index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                Início
                            </a>

                            <a href="favoritos.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 transition flex items-center gap-1">
                                <span>Favoritos</span>
                                <?php if ($totalFavs > 0): ?>
                                    <span id="nav-fav-badge" class="px-1.5 py-0.2 text-[10px] rounded-full bg-amber-500/20 text-amber-600 dark:text-amber-400 font-extrabold font-mono"><?= $totalFavs ?></span>
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
                                   placeholder="Pesquisar..." 
                                   class="w-36 focus:w-56 pl-8 pr-3 py-1.5 text-xs rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-slate-100/70 dark:bg-slate-800/70 text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-slate-400 transition-all duration-200">
                            <svg class="w-3.5 h-3.5 absolute left-2.5 top-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </form>

                        <?php require __DIR__ . '/partials/theme_dropdown.php'; ?>

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

        <!-- CONTAINER PRINCIPAL DE FAVORITOS -->
        <main class="max-container pb-10 pt-20 sm:pt-24">
            
            <nav class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400 mb-6">
                <a href="index.php" class="hover:text-slate-900 dark:hover:text-white transition">Início</a>
                <span>/</span>
                <span class="font-bold text-slate-900 dark:text-white">Favoritos</span>
            </nav>

            <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight flex items-center gap-2.5">
                        <svg class="w-6 h-6 fill-amber-500 text-amber-500" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                        <span>Meus Favoritos</span>
                        <span id="fav-count-badge" class="text-xs font-bold px-2 py-0.5 rounded-md bg-slate-100 dark:bg-[#353842] border border-slate-200 dark:border-[#454956] text-slate-700 dark:text-slate-300 font-mono">
                            <?= $totalFavs ?>
                        </span>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        Seus documentos, subcategorias e assuntos salvos para acesso rápido.
                    </p>
                </div>

                <?php if ($loggedUser && $totalFavs > 0): ?>
                    <!-- PESQUISA DENTRO DOS FAVORITOS -->
                    <div class="relative w-full md:w-80">
                        <input type="text" 
                               id="fav-search-input"
                               placeholder="Pesquisar nos favoritos..." 
                               onkeyup="filterFavoritesClient(this.value)"
                               class="w-full pl-9 pr-3 py-1.5 text-xs rounded-md border border-slate-200 dark:border-[#454956] bg-white dark:bg-[#353842] text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-slate-400 transition">
                        <svg class="w-3.5 h-3.5 absolute left-3 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                <?php endif; ?>
            </div>

            <!-- CONTEÚDO DOS FAVORITOS -->
            <?php if (!$loggedUser): ?>
                <div class="p-8 text-center bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956] max-w-lg mx-auto my-12 shadow-xs">
                    <svg class="w-10 h-10 fill-amber-500/20 text-amber-500 mx-auto mb-3" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-1">Acesso Restrito</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Faça login no sistema para consultar e gerenciar seus favoritos.</p>
                    <a href="login.php" class="inline-block bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold px-4 py-2 rounded-md text-xs hover:bg-slate-800 transition">
                        Entrar no Sistema
                    </a>
                </div>

            <?php elseif ($totalFavs === 0): ?>
                <div id="fav-empty-state" class="max-w-xl mx-auto p-8 text-center bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956] my-8 shadow-xs">
                    <p class="text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nenhum favorito registrado.</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Clique na estrela ☆ em qualquer documento, subcategoria ou assunto para favoritá-lo!</p>
                </div>

            <?php else: ?>
                <div id="fav-list-container" class="space-y-6">

                    <!-- SEÇÃO 1: DOCUMENTOS FAVORITADOS -->
                    <?php if (!empty($favDocs)): ?>
                        <div>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-2">
                                <span>Documentos Salvos (<?= count($favDocs) ?>)</span>
                            </h2>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <?php foreach ($favDocs as $doc): ?>
                                    <div id="fav-card-doc-<?= $doc['id'] ?>" class="fav-card-item group relative min-h-28 overflow-hidden p-3 rounded-lg bg-white dark:bg-[#353842] border border-slate-200/80 dark:border-[#454956] hover:border-slate-400 dark:hover:border-slate-500 hover:-translate-y-0.5 transition shadow-xs flex flex-col justify-between">
                                        <a href="ver_conteudo.php?id=<?= $doc['id'] ?>" class="absolute inset-0 z-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400" aria-label="Visualizar <?= htmlspecialchars($doc['title']) ?>"></a>
                                        <div class="relative z-[1] pointer-events-none flex items-start gap-2.5">
                                            <div class="w-7 h-7 rounded bg-slate-100 dark:bg-[#2c2e33] flex items-center justify-center shrink-0">
                                                <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                            </div>
                                            <div class="fav-searchable min-w-0 flex-1" data-text="<?= strtolower(htmlspecialchars($doc['title'] . ' ' . $doc['category_name'] . ' ' . $doc['subcategory_name'] . ' ' . $doc['subject_name'])) ?>">
                                                <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100 line-clamp-2">
                                                    <?= htmlspecialchars($doc['title']) ?>
                                                </h3>
                                            </div>
                                            <button type="button" 
                                                    aria-label="Remover dos favoritos"
                                                    title="Remover dos favoritos"
                                                    onclick="removeFavoritePage(<?= $doc['id'] ?>, 'document', this, event)"
                                                    class="favorite-card-button pointer-events-auto">
                                                <svg class="favorite-card-button__icon is-saved" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                                </svg>
                                            </button>
                                        </div>
                                        <p class="relative z-[1] pointer-events-none mt-2 pt-2 border-t border-slate-100 dark:border-[#454956] text-[9px] leading-3 text-slate-400 line-clamp-2">
                                            <?= htmlspecialchars($doc['category_name']) ?> / <?= htmlspecialchars($doc['subcategory_name']) ?> / <?= htmlspecialchars($doc['subject_name']) ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SEÇÃO 2: SUBCATEGORIAS FAVORITADAS -->
                    <?php if (!empty($favSubcats)): ?>
                        <div>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-2">
                                <span>Subcategorias Salvas (<?= count($favSubcats) ?>)</span>
                            </h2>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                                <?php foreach ($favSubcats as $sub): ?>
                                    <div id="fav-card-subcat-<?= $sub['id'] ?>" class="fav-card-item group relative min-h-24 overflow-hidden p-3 rounded-lg bg-white dark:bg-[#353842] border border-slate-200/80 dark:border-[#454956] hover:border-slate-400 hover:-translate-y-0.5 transition shadow-xs flex items-start justify-between gap-2">
                                        <a href="index.php?cat=<?= urlencode($sub['category_slug']) ?>&subcat=<?= urlencode($sub['slug']) ?>" class="absolute inset-0 z-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400" aria-label="Abrir subcategoria <?= htmlspecialchars($sub['name']) ?>"></a>
                                        <div class="fav-searchable relative z-[1] pointer-events-none min-w-0" data-text="<?= strtolower(htmlspecialchars($sub['name'] . ' ' . $sub['category_name'])) ?>">
                                            <?php if (!empty($sub['image_path'])): ?>
                                                <img src="subcategory_image.php?id=<?= (int)$sub['id'] ?>&amp;v=<?= urlencode((string)$sub['image_path']) ?>" alt="" class="mb-2 h-7 w-7 rounded object-cover border border-slate-200 dark:border-[#454956]" loading="lazy">
                                            <?php endif; ?>
                                            <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100 line-clamp-2">
                                                <?= htmlspecialchars($sub['name']) ?>
                                            </h3>
                                            <p class="text-[9px] leading-3 text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                                Subcategoria em <?= htmlspecialchars($sub['category_name']) ?>
                                            </p>
                                        </div>

                                        <button type="button" 
                                                aria-label="Remover subcategoria dos favoritos"
                                                title="Remover subcategoria dos favoritos"
                                                onclick="removeFavoritePage(<?= $sub['id'] ?>, 'subcategory', this, event)"
                                                class="favorite-card-button">
                                            <svg class="favorite-card-button__icon is-saved" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SEÇÃO 3: ASSUNTOS FAVORITADOS -->
                    <?php if (!empty($favSubjs)): ?>
                        <div>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-2">
                                <span>Assuntos Salvos (<?= count($favSubjs) ?>)</span>
                            </h2>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                                <?php foreach ($favSubjs as $subj): ?>
                                    <div id="fav-card-subj-<?= $subj['id'] ?>" class="fav-card-item group relative min-h-24 overflow-hidden p-3 rounded-lg bg-white dark:bg-[#353842] border border-slate-200/80 dark:border-[#454956] hover:border-slate-400 hover:-translate-y-0.5 transition shadow-xs flex items-start justify-between gap-2">
                                        <a href="index.php?cat=<?= urlencode($subj['category_slug']) ?>&subcat=<?= urlencode($subj['subcategory_slug']) ?>&assunto=<?= urlencode($subj['slug']) ?>" class="absolute inset-0 z-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-400" aria-label="Abrir assunto <?= htmlspecialchars($subj['name']) ?>"></a>
                                        <div class="fav-searchable relative z-[1] pointer-events-none min-w-0" data-text="<?= strtolower(htmlspecialchars($subj['name'] . ' ' . $subj['category_name'] . ' ' . $subj['subcategory_name'])) ?>">
                                            <h3 class="text-xs font-bold text-slate-900 dark:text-slate-100 line-clamp-2">
                                                <?= htmlspecialchars($subj['name']) ?>
                                            </h3>
                                            <p class="text-[9px] leading-3 text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                                Assunto em <?= htmlspecialchars($subj['category_name']) ?> &rsaquo; <?= htmlspecialchars($subj['subcategory_name']) ?>
                                            </p>
                                        </div>

                                        <button type="button" 
                                                aria-label="Remover assunto dos favoritos"
                                                title="Remover assunto dos favoritos"
                                                onclick="removeFavoritePage(<?= $subj['id'] ?>, 'subject', this, event)"
                                                class="favorite-card-button">
                                            <svg class="favorite-card-button__icon is-saved" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363 1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- RODAPÉ -->
    <footer class="border-t border-slate-200/80 dark:border-[#454956] py-6 text-center text-xs text-slate-400">
        <?= htmlspecialchars($appName) ?> &bull; <?= htmlspecialchars($appDescription) ?> &bull; <?= htmlspecialchars($organizationName) ?>
    </footer>

    <script>
        function removeFavoritePage(id, type, btnElem, event) {
            if (event) event.stopPropagation();
            let cardId = 'fav-card-doc-' + id;
            if (type === 'subcategory') cardId = 'fav-card-subcat-' + id;
            if (type === 'subject') cardId = 'fav-card-subj-' + id;

            const card = document.getElementById(cardId);
            if (btnElem) btnElem.disabled = true;

            fetch('api_user.php?action=toggle_favorito&type=' + type + '&target_id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.success && !data.is_favorite) {
                        if (card) {
                            card.classList.add('fav-item-fadeout');
                            setTimeout(() => {
                                card.remove();
                                updateFavBadgeCount();
                            }, 150);
                        }
                    } else {
                        if (btnElem) btnElem.disabled = false;
                        alert('Não foi possível atualizar o favorito.');
                    }
                })
                .catch(() => {
                    if (btnElem) btnElem.disabled = false;
                    alert('Não foi possível atualizar o favorito.');
                });
        }

        function updateFavBadgeCount() {
            const container = document.getElementById('fav-list-container');
            const items = container ? container.querySelectorAll('.fav-card-item') : [];
            const badge = document.getElementById('fav-count-badge');
            const navBadge = document.getElementById('nav-fav-badge');

            if (badge) badge.textContent = items.length;
            if (navBadge) navBadge.textContent = items.length;

            if (items.length === 0) {
                location.reload();
            }
        }

        function filterFavoritesClient(query) {
            const q = query.toLowerCase().trim();
            document.querySelectorAll('.fav-card-item').forEach(card => {
                const searchElem = card.querySelector('.fav-searchable');
                const text = searchElem ? searchElem.dataset.text : '';
                if (!q || text.includes(q)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
