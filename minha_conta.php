<?php
// minha_conta.php - Área "Minha Conta" Completa e Funcional (Estilo ChatGPT / SaaS Moderno)
require_once __DIR__ . '/config/session.php';
docgovStartSession();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/PermissionService.php';
require_once __DIR__ . '/services/NotificationService.php';
$permService = new PermissionService($pdo);

$loggedUser = $_SESSION['user'] ?? null;
if (!$loggedUser) {
    header('Location: login.php');
    exit;
}

$userId = (int)$loggedUser['id'];
$notificationService = new NotificationService($pdo);
$unreadNotificationCount = $notificationService->unreadCount($userId);

$stmtU = $pdo->prepare("
    SELECT id, name AS nome, username AS login, email, role, active,
           CASE WHEN active THEN 'ativo' ELSE 'inativo' END AS status,
           avatar, password_hash, auth_source, last_login_at
    FROM users WHERE id = :id
");
$stmtU->execute([':id' => $userId]);
$userData = $stmtU->fetch();

if (!$userData) {
    header('Location: logout.php');
    exit;
}

$accountAccessLabel = $permService->getAdminPanelAccessLabel($userId);
$hasAdministrativeAccess = $permService->canAccessAdminPanel($userId);
$isGlobalAdmin = $permService->isGlobalAdmin($userId);
$isAdUser = ($userData['auth_source'] ?? 'local') === 'ad';

// Processar Troca de Senha
$passMsg = '';
$passErr = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['change_password'])) {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if ($isAdUser) {
        $passErr = 'Sua senha é administrada pelo Active Directory e não pode ser alterada em ' . $appName . '.';
    } elseif (!empty($currentPass) && !empty($newPass) && !empty($confirmPass)) {
        if (!$userData['password_hash'] || !password_verify($currentPass, $userData['password_hash'])) {
            $passErr = 'A senha atual informada é incorreta.';
        } elseif ($newPass !== $confirmPass) {
            $passErr = 'A nova senha e a confirmação não coincidem.';
        } elseif (strlen($newPass) < 6) {
            $passErr = 'A nova senha deve ter no mínimo 6 caracteres.';
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmtP = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $stmtP->execute([':hash' => $newHash, ':id' => $userId]);
            $passMsg = 'Senha alterada com sucesso!';
        }
    } else {
        $passErr = 'Por favor, preencha todos os campos da senha.';
    }
}

// Processar Encerrar Outras Sessões
if (isset($_POST['end_other_sessions'])) {
    $stmtS = $pdo->prepare("DELETE FROM sessoes_usuario WHERE usuario_id = ? AND session_token != ?");
    $stmtS->execute([$userId, session_id()]);
    $sessMsg = 'Outras sessões encerradas com sucesso!';
}

// Buscar Documentos Favoritados / Recentes do Usuário no PostgreSQL (Restrito a Acessos Permitidos)
$recentes = [];
require_once __DIR__ . '/services/AccessService.php';
$accessService = new AccessService($pdo);
$allowedSubjectIds = $accessService->getAllowedSubjectIds($userId);

if (!empty($allowedSubjectIds)) {
    try {
        $subInSqlRec = implode(',', array_map('intval', $allowedSubjectIds));
        $stmtRec = $pdo->prepare("
            SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, MAX(e.created_at) AS acessado_em,
                   s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
            FROM usage_audit_events e
            JOIN documents d ON e.resource_id = d.id
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON c.id = sc.category_id
            WHERE e.user_id = :uid 
              AND e.resource_type = 'DOCUMENT'
              AND e.event_type IN ('document_view', 'document_file_view', 'document_download')
              AND d.subject_id IN ($subInSqlRec)
            GROUP BY d.id, d.title, d.description, d.content_type, s.name, sc.name, c.name
            ORDER BY MAX(e.created_at) DESC
            LIMIT 10
        ");
        $stmtRec->execute([':uid' => $userId]);
        $recentes = $stmtRec->fetchAll();
    } catch (Exception $e) {
        $recentes = [];
    }
}
// Métricas do Dashboard Pessoal do Usuário
$stmtM1 = $pdo->prepare("
    SELECT COUNT(DISTINCT resource_id) 
    FROM usage_audit_events 
    WHERE user_id = ? 
      AND resource_type = 'DOCUMENT' 
      AND event_type IN ('document_view', 'document_file_view') 
      AND created_at >= DATE_TRUNC('month', CURRENT_TIMESTAMP)
");
$stmtM1->execute([$userId]);
$userDocsConsultadosMes = (int)$stmtM1->fetchColumn();

$stmtM2 = $pdo->prepare("
    SELECT COUNT(*) 
    FROM documents 
    WHERE created_by = ? AND trashed_at IS NULL
");
$stmtM2->execute([$userId]);
$userDocsPublicados = (int)$stmtM2->fetchColumn();

$stmtM3 = $pdo->prepare("
    SELECT COUNT(*) 
    FROM usage_audit_events 
    WHERE user_id = ? AND event_type = 'document_download'
");
$stmtM3->execute([$userId]);
$userTotalDownloads = (int)$stmtM3->fetchColumn();

$stmtM4 = $pdo->prepare("
    SELECT COUNT(*) 
    FROM favorites 
    WHERE user_id = ?
");
$stmtM4->execute([$userId]);
$userTotalFavoritos = (int)$stmtM4->fetchColumn();

$userTopViewedDocs = [];
if (!empty($allowedSubjectIds)) {
    try {
        $subInSqlDash = implode(',', array_map('intval', $allowedSubjectIds));
        $stmtTop = $pdo->prepare("
            SELECT d.id, d.title AS titulo, d.content_type AS tipo_conteudo, COUNT(e.id) AS acessos, MAX(e.created_at) AS ultimo_acesso,
                   s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
            FROM usage_audit_events e
            JOIN documents d ON e.resource_id = d.id
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON c.id = sc.category_id
            WHERE e.user_id = :uid 
              AND e.resource_type = 'DOCUMENT'
              AND e.event_type IN ('document_view', 'document_file_view', 'document_download')
              AND d.subject_id IN ($subInSqlDash)
            GROUP BY d.id, d.title, d.content_type, s.name, sc.name, c.name
            ORDER BY COUNT(e.id) DESC, MAX(e.created_at) DESC
            LIMIT 5
        ");
        $stmtTop->execute([':uid' => $userId]);
        $userTopViewedDocs = $stmtTop->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $userTopViewedDocs = [];
    }
}

$activeTab = trim($_GET['tab'] ?? 'dashboard');
$userTheme = $userData['tema_preferido'] ?? ($loggedUser['tema_preferido'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Conta - <?= htmlspecialchars($appName) ?></title>
    
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
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-[#f8f9fa] dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 min-h-screen selection:bg-slate-800 selection:text-white dark:selection:bg-slate-200 dark:selection:text-slate-900">
    <?php require __DIR__ . '/partials/maintenance-banner.php'; ?>

    <!-- NAVBAR FIXA, LEVE E DE LARGURA TOTAL -->
    <div class="fixed inset-x-0 top-0 z-50 border-b border-slate-200/80 bg-white/90 shadow-sm shadow-slate-900/5 backdrop-blur-md dark:border-slate-800/80 dark:bg-[#1f2128]/95">
        <header class="max-container">
            <div class="flex min-h-[58px] items-center justify-between gap-4">
                
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

                    <nav class="hidden md:flex items-center gap-1">
                        <a href="index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                            Início
                        </a>
                        <a href="favoritos.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50 transition flex items-center">
                            <span>Favoritos</span>
                        </a>
                        <a href="minha_conta.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white">
                            Minha conta
                        </a>
                    </nav>
                </div>

                <div class="flex items-center gap-2">
                    <?php require __DIR__ . '/partials/theme_dropdown.php'; ?>
                    <?php if ($hasAdministrativeAccess): ?>
                        <a href="admin/index.php" class="hidden md:inline-flex text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-3 py-1.5 rounded-xl hover:opacity-90 transition">
                            Admin
                        </a>
                    <?php endif; ?>
                    <?php require __DIR__ . '/partials/notification_link.php'; ?>
                    <a href="logout.php" class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 dark:hover:text-red-400 transition" title="Sair do Sistema">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </a>
                    <button type="button" onclick="document.getElementById('mobile-menu-drawer').classList.toggle('hidden')" class="md:hidden p-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition" aria-label="Abrir navegação">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                </div>
            </div>

            <div id="mobile-menu-drawer" class="hidden md:hidden pt-3 pb-2 px-2 border-t border-slate-100 dark:border-slate-800 mt-2 space-y-1">
                <a href="index.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">Início</a>
                <a href="favoritos.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">Favoritos</a>
                <a href="minha_conta.php" class="block px-3 py-2 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white">Minha conta</a>
                <?php if ($hasAdministrativeAccess): ?>
                    <a href="admin/index.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">Painel Admin</a>
                <?php endif; ?>
                <a href="logout.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-slate-800">Sair</a>
            </div>
        </header>
    </div>

    <div class="max-container pb-10 pt-20 sm:pt-24">

        <!-- CABEÇALHO DO PERFIL -->
        <div class="bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 shadow-xs">
            <div class="flex items-center gap-4">
                <!-- AVATAR FOTO OU INICIAIS -->
                <div class="relative group">
                    <?php if (!empty($userData['avatar'])): ?>
                        <img src="<?= htmlspecialchars($userData['avatar']) ?>" alt="Avatar" class="w-16 h-16 rounded-md object-cover border border-slate-200 dark:border-[#454956]">
                    <?php else: ?>
                        <div class="w-16 h-16 rounded-md bg-slate-200 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-200 font-bold text-xl flex items-center justify-center border border-slate-300 dark:border-[#454956]">
                            <?= mb_strtoupper(mb_substr($userData['nome'] ?? $userData['name'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100 leading-tight">
                        <?= htmlspecialchars($userData['nome'] ?? $userData['name'] ?? 'Usuário') ?>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 font-mono mt-0.5">
                        @<?= htmlspecialchars($userData['login'] ?? $userData['username'] ?? 'usuario') ?> • <?= htmlspecialchars($userData['email'] ?? '') ?>
                    </p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded <?= $isGlobalAdmin ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20' : ($hasAdministrativeAccess ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-slate-200 dark:bg-[#2c2e33] text-slate-600 dark:text-slate-300') ?>">
                            Acesso: <?= htmlspecialchars($accountAccessLabel) ?>
                        </span>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            Status: <?= ucfirst($userData['status'] ?? ($userData['active'] ? 'ativo' : 'inativo')) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- OPÇÕES DE UPLOAD / REMOÇÃO DE FOTO -->
            <div class="flex items-center gap-2 w-full sm:w-auto pt-2 sm:pt-0 border-t sm:border-t-0 border-slate-100 dark:border-[#454956]">
                <form action="api_user.php" method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
                    <input type="hidden" name="action" value="upload_avatar">
                    <label class="cursor-pointer bg-slate-900 dark:bg-white hover:bg-slate-800 dark:hover:bg-slate-100 text-white dark:text-slate-900 font-semibold px-3 py-1.5 rounded-md text-xs transition">
                        <span>Trocar Foto</span>
                        <input type="file" name="avatar_file" accept="image/*" class="hidden" onchange="this.form.submit()">
                    </label>
                </form>

                <?php if (!empty($userData['avatar'])): ?>
                    <a href="api_user.php?action=remove_avatar" class="text-xs text-red-600 dark:text-red-400 hover:underline px-2.5 py-1.5 font-medium">
                        Remover Foto
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- NOTIFICAÇÕES GERAIS -->
        <?php if (isset($_GET['msg'])): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-400 text-xs p-3 rounded-md mb-6">
                <?php
                    if ($_GET['msg'] === 'avatar_updated') echo "Foto de perfil atualizada com sucesso!";
                    if ($_GET['msg'] === 'avatar_removed') echo "Foto de perfil removida com sucesso!";
                    if ($_GET['msg'] === 'err_ext') echo "Erro: Formato de imagem não suportado (use JPG, PNG ou WEBP).";
                    if ($_GET['msg'] === 'err_size') echo "Erro: A imagem deve ter no máximo 3MB.";
                ?>
            </div>
        <?php endif; ?>

        <!-- BARRA DE NAVEGAÇÃO DAS ABAS DA MINHA CONTA -->
        <div class="flex items-center gap-2 mb-6 border-b border-slate-200 dark:border-[#454956] pb-3 overflow-x-auto">
            <a href="minha_conta.php?tab=dashboard" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-md text-xs font-medium transition text-decoration-none <?= $activeTab === 'dashboard' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-[#353842]' ?>">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                <span>Meu Dashboard</span>
            </a>

            <a href="minha_conta.php?tab=perfil" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-md text-xs font-medium transition text-decoration-none <?= $activeTab === 'perfil' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-[#353842]' ?>">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <span>Perfil & Acesso</span>
            </a>

            <a href="minha_conta.php?tab=recentes" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-md text-xs font-medium transition text-decoration-none <?= $activeTab === 'recentes' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-[#353842]' ?>">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>Vistos Recentemente</span>
            </a>

            <a href="minha_conta.php?tab=preferencias" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-md text-xs font-medium transition text-decoration-none <?= $activeTab === 'preferencias' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-[#353842]' ?>">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span>Preferências</span>
            </a>

            <a href="minha_conta.php?tab=seguranca" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-md text-xs font-medium transition text-decoration-none <?= $activeTab === 'seguranca' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-[#353842]' ?>">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <span>Segurança & Senha</span>
            </a>
        </div>

        <!-- ABA 0: MEU DASHBOARD -->
        <?php if ($activeTab === 'dashboard'): ?>
            <div class="space-y-6">
                <!-- GRID DE CARDS DE MÉTRICAS PESSOAIS -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    
                    <!-- CARD 1: CONSULTAS NESTE MÊS -->
                    <div class="bg-white dark:bg-[#353842] p-4 rounded-lg border border-slate-200 dark:border-[#454956] shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Consultas este Mês</span>
                            <div class="p-2 rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </div>
                        </div>
                        <div class="mt-3">
                            <span class="text-2xl font-bold font-mono text-slate-900 dark:text-slate-100"><?= $userDocsConsultadosMes ?></span>
                            <span class="block text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Documentos únicos acessados no mês</span>
                        </div>
                    </div>

                    <!-- CARD 2: PUBLICADOS POR MIM -->
                    <div class="bg-white dark:bg-[#353842] p-4 rounded-lg border border-slate-200 dark:border-[#454956] shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Publicados por Mim</span>
                            <div class="p-2 rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                        </div>
                        <div class="mt-3">
                            <span class="text-2xl font-bold font-mono text-slate-900 dark:text-slate-100"><?= $userDocsPublicados ?></span>
                            <span class="block text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Normas e conteúdos criados por você</span>
                        </div>
                    </div>

                    <!-- CARD 3: TOTAL DE DOWNLOADS -->
                    <div class="bg-white dark:bg-[#353842] p-4 rounded-lg border border-slate-200 dark:border-[#454956] shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Downloads Realizados</span>
                            <div class="p-2 rounded-lg bg-purple-50 text-purple-600 dark:bg-purple-950/50 dark:text-purple-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            </div>
                        </div>
                        <div class="mt-3">
                            <span class="text-2xl font-bold font-mono text-slate-900 dark:text-slate-100"><?= $userTotalDownloads ?></span>
                            <span class="block text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Arquivos baixados para uso local</span>
                        </div>
                    </div>

                    <!-- CARD 4: TOTAL DE FAVORITOS -->
                    <div class="bg-white dark:bg-[#353842] p-4 rounded-lg border border-slate-200 dark:border-[#454956] shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Meus Favoritos</span>
                            <div class="p-2 rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                            </div>
                        </div>
                        <div class="mt-3">
                            <span class="text-2xl font-bold font-mono text-slate-900 dark:text-slate-100"><?= $userTotalFavoritos ?></span>
                            <span class="block text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Normas e arquivos favoritados</span>
                        </div>
                    </div>
                </div>

                <!-- SEÇÃO: SEUS DOCUMENTOS MAIS ACESSADOS (TOP CONSULTAS) -->
                <div class="bg-white dark:bg-[#353842] p-5 rounded-lg border border-slate-200 dark:border-[#454956]">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                        Documentos Mais Acessados por Você
                    </h3>

                    <?php if (!empty($userTopViewedDocs)): ?>
                        <div class="divide-y divide-slate-100 dark:divide-[#454956]/60">
                            <?php foreach ($userTopViewedDocs as $topDoc): ?>
                                <div class="py-3 flex items-center justify-between text-xs">
                                    <div>
                                        <a href="ver_conteudo.php?id=<?= (int)$topDoc['id'] ?>" class="font-bold text-slate-900 dark:text-slate-100 hover:underline text-xs">
                                            <?= htmlspecialchars($topDoc['titulo']) ?>
                                        </a>
                                        <p class="text-[11px] text-slate-400 mt-0.5">
                                            <?= htmlspecialchars($topDoc['categoria']) ?> &rsaquo; <?= htmlspecialchars($topDoc['subcategoria']) ?> &rsaquo; <?= htmlspecialchars($topDoc['assunto']) ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-3 ml-4">
                                        <span class="rounded bg-sky-100 dark:bg-sky-950/60 px-2 py-0.5 font-mono text-[11px] font-bold text-sky-800 dark:text-sky-300">
                                            <?= (int)$topDoc['acessos'] ?> acesso(s)
                                        </span>
                                        <span class="text-[11px] text-slate-400 font-mono hidden sm:inline">
                                            Último: <?= date('d/m/Y H:i', strtotime($topDoc['ultimo_acesso'])) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-xs text-slate-400 text-center py-8">
                            Seus documentos mais acessados aparecerão aqui conforme você navegar no portal.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ABA 1: PERFIL & ACESSO -->
        <?php if ($activeTab === 'perfil'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- CARD DE DADOS CADASTRAIS (SOMENTE LEITURA / ADMIN) -->
                <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                        Informações Cadastrais
                    </h2>

                    <div class="space-y-3.5 text-xs">
                        <div>
                            <span class="block text-[11px] font-semibold text-slate-400 uppercase">Nome Completo</span>
                            <span class="font-medium text-slate-900 dark:text-slate-100"><?= htmlspecialchars($userData['nome'] ?? $userData['name'] ?? 'Usuário') ?></span>
                        </div>

                        <div>
                            <span class="block text-[11px] font-semibold text-slate-400 uppercase">Login do Usuário</span>
                            <span class="font-mono font-medium text-slate-800 dark:text-slate-200">@<?= htmlspecialchars($userData['login'] ?? $userData['username'] ?? 'usuario') ?></span>
                        </div>

                        <div>
                            <span class="block text-[11px] font-semibold text-slate-400 uppercase">E-mail Corporativo</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($userData['email'] ?? '') ?></span>
                        </div>

                        <div>
                            <span class="block text-[11px] font-semibold text-slate-400 uppercase mb-1">Perfil de Acesso Atual</span>
                            <div class="p-3 rounded-md bg-slate-50 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956]">
                                <span class="font-bold text-slate-900 dark:text-slate-100 block mb-0.5">
                                    <?= htmlspecialchars($accountAccessLabel) ?>
                                </span>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                    <?php
                                        if ($isGlobalAdmin) echo "Acesso total para administrar conteúdos, usuários, equipes e permissões do sistema.";
                                        elseif ($hasAdministrativeAccess) echo "Cadastro de usuário comum com acesso de gestão concedido somente nas categorias autorizadas.";
                                        else echo "Cadastro de usuário comum, com leitura e consulta apenas nas áreas liberadas.";
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARD DE GRUPOS DE ACESSO DO USUÁRIO -->
                <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956] flex items-center justify-between">
                        <span>Grupos Pertencentes (Somente Leitura)</span>
                        <span class="text-[10px] text-slate-400 font-normal">Definido pelo Administrador</span>
                    </h2>

                    <?php if (!empty($userGroups)): ?>
                        <div class="space-y-2.5">
                            <?php foreach ($userGroups as $g): ?>
                                <div class="p-3 rounded-md bg-slate-50 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956] flex items-center justify-between">
                                    <div>
                                        <span class="font-bold text-xs text-slate-900 dark:text-slate-100 flex items-center gap-1.5">
                                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                            <?= htmlspecialchars($g['nome']) ?>
                                        </span>
                                        <span class="text-[11px] text-slate-500 dark:text-slate-400 block mt-0.5">
                                            <?= htmlspecialchars($g['descricao']) ?>
                                        </span>
                                    </div>
                                    <span class="text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-2 py-0.5 rounded">
                                        Ativo
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-xs text-slate-400 italic">Você não está vinculado a nenhum grupo no momento.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ABA 3: VISTOS RECENTEMENTE -->
        <?php if ($activeTab === 'recentes'): ?>
            <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                    Últimos Documentos Acessados
                </h2>

                <?php if (!empty($recentes)): ?>
                    <div class="divide-y divide-slate-100 dark:divide-[#454956]/60">
                        <?php foreach ($recentes as $r): ?>
                            <div class="py-3 flex items-center justify-between text-xs">
                                <div>
                                    <a href="ver_conteudo.php?id=<?= (int)$r['id'] ?>" class="font-bold text-slate-900 dark:text-slate-100 hover:underline text-xs">
                                        <?= htmlspecialchars($r['titulo']) ?>
                                    </a>
                                    <p class="text-[11px] text-slate-400 mt-0.5">
                                        <?= htmlspecialchars($r['categoria']) ?> &rsaquo; <?= htmlspecialchars($r['subcategoria']) ?> &rsaquo; <?= htmlspecialchars($r['assunto']) ?>
                                    </p>
                                </div>
                                <span class="text-[11px] text-slate-400 font-mono whitespace-nowrap ml-4">
                                    Acessado em: <?= date('d/m/Y H:i', strtotime($r['acessado_em'])) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-xs text-slate-400 text-center py-8">
                        Nenhum documento consultado recentemente.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ABA 4: PREFERÊNCIAS -->
        <?php if ($activeTab === 'preferencias'): ?>
            <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956] max-w-xl">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                    Preferências Visuais da Interface
                </h2>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                            Tema da Interface
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" onclick="setThemePreference('dark')" class="p-3 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-900 text-white font-semibold text-left flex items-center justify-between">
                                <span>Escuro (Grafite)</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                            </button>
                            <button type="button" onclick="setThemePreference('light')" class="p-3 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-100 text-slate-900 font-semibold text-left flex items-center justify-between">
                                <span>Claro</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ABA 5: SEGURANÇA & SENHA -->
        <?php if ($activeTab === 'seguranca'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- TROCA DE SENHA -->
                <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                        <?= $isAdUser ? 'Senha Corporativa' : 'Alterar Senha de Acesso' ?>
                    </h2>

                    <?php if (!empty($passMsg)): ?>
                        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-400 text-xs p-2.5 rounded-md mb-4">
                            <?= htmlspecialchars($passMsg) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($passErr)): ?>
                        <div class="bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs p-2.5 rounded-md mb-4">
                            <?= htmlspecialchars($passErr) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isAdUser): ?>
                        <div class="p-4 rounded-md bg-blue-500/10 border border-blue-500/30 text-xs text-blue-800 dark:text-blue-300 leading-relaxed">
                            Sua autenticação é feita pelo <strong>Active Directory BETIM</strong>. Use sempre a mesma senha da rede corporativa. Alterações ou recuperação de senha devem ser realizadas pelos canais de TI da Prefeitura.
                        </div>
                    <?php else: ?>
                    <form method="POST" action="minha_conta.php?tab=seguranca" class="space-y-3">
                        <input type="hidden" name="change_password" value="1">

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Senha Atual *</label>
                            <input type="password" name="current_password" required class="input-minimal w-full px-3 py-1.5 text-xs" placeholder="••••••••">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Nova Senha *</label>
                            <input type="password" name="new_password" required class="input-minimal w-full px-3 py-1.5 text-xs" placeholder="Mínimo 6 caracteres">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Confirmar Nova Senha *</label>
                            <input type="password" name="confirm_password" required class="input-minimal w-full px-3 py-1.5 text-xs" placeholder="••••••••">
                        </div>

                        <button type="submit" class="w-full bg-slate-900 dark:bg-white hover:bg-slate-800 text-white dark:text-slate-900 font-semibold py-2 rounded-md text-xs transition mt-2">
                            Atualizar Senha
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- INFORMAÇÕES DE SESSÃO -->
                <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                        Sessão & Dispositivo
                    </h2>

                    <div class="space-y-3 text-xs mb-6">
                        <div>
                            <span class="block text-[11px] font-semibold text-slate-400 uppercase">Endereço IP Atual</span>
                            <span class="font-mono font-medium text-slate-900 dark:text-slate-100"><?= $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ?></span>
                        </div>

                        <div>
                            <span class="block text-[11px] font-semibold text-slate-400 uppercase">Sessão Atual</span>
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">Ativa (ID: <?= substr(session_id(), 0, 10) ?>...)</span>
                        </div>
                    </div>

                    <form method="POST" action="minha_conta.php?tab=seguranca">
                        <input type="hidden" name="end_other_sessions" value="1">
                        <button type="submit" class="w-full bg-slate-200 dark:bg-[#2c2e33] hover:bg-slate-300 dark:hover:bg-[#3e424e] text-slate-700 dark:text-slate-300 font-semibold py-2 rounded-md text-xs transition">
                            Encerrar Outras Sessões Ativas
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function setThemePreference(mode) {
            const html = document.documentElement;
            if (mode === 'dark') {
                html.classList.add('dark');
                html.classList.remove('light');
                localStorage.setItem('theme', 'dark');
            } else {
                html.classList.remove('dark');
                html.classList.add('light');
                localStorage.setItem('theme', 'light');
            }
            fetch('api_user.php?action=update_theme&theme=' + mode)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(() => {});
        }

        // Aplicação Imediata de Tema por LocalStorage no Carregamento
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
</body>
</html>
