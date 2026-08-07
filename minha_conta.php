<?php
// minha_conta.php - Área "Minha Conta" Completa e Funcional (Estilo ChatGPT / SaaS Moderno)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';

$loggedUser = $_SESSION['user'] ?? null;
if (!$loggedUser) {
    header('Location: login.php');
    exit;
}

$userId = (int)$loggedUser['id'];

$stmtU = $pdo->prepare("
    SELECT id, name AS nome, username AS login, email, role, active,
           CASE WHEN active THEN 'ativo' ELSE 'inativo' END AS status,
           avatar, password_hash
    FROM users WHERE id = :id
");
$stmtU->execute([':id' => $userId]);
$userData = $stmtU->fetch();

if (!$userData) {
    header('Location: logout.php');
    exit;
}

// Processar Troca de Senha
$passMsg = '';
$passErr = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['change_password'])) {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (!empty($currentPass) && !empty($newPass) && !empty($confirmPass)) {
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
        $subInSqlFav = implode(',', array_map('intval', $allowedSubjectIds));
        $stmtRec = $pdo->prepare("
            SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, f.created_at AS acessado_em,
                   s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
            FROM favorites f
            JOIN documents d ON f.document_id = d.id
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON c.id = sc.category_id
            WHERE f.user_id = :uid AND d.subject_id IN ($subInSqlFav)
            ORDER BY f.created_at DESC
            LIMIT 10
        ");
        $stmtRec->execute([':uid' => $userId]);
        $recentes = $stmtRec->fetchAll();
    } catch (Exception $e) {
        $recentes = [];
    }
}

$activeTab = trim($_GET['tab'] ?? 'perfil');
$userTheme = $userData['tema_preferido'] ?? ($loggedUser['tema_preferido'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Conta - Portal de Documentos</title>
    
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
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-[#f8f9fa] dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 min-h-screen selection:bg-slate-800 selection:text-white dark:selection:bg-slate-200 dark:selection:text-slate-900">

    <!-- NAVBAR FLUTUANTE COMPACTA (FLOATING NAVBAR) -->
    <div class="sticky top-4 z-50 w-full max-w-5xl mx-auto px-4 mb-4">
        <header class="bg-white/85 dark:bg-[#1f2128]/90 backdrop-blur-md border border-slate-200/80 dark:border-slate-800/80 shadow-md shadow-slate-900/5 rounded-2xl px-4 py-2.5 transition-all duration-200">
            <div class="flex items-center justify-between gap-4">
                
                <div class="flex items-center gap-6">
                    <a href="index.php" class="inline-flex items-center gap-2.5 group text-decoration-none shrink-0">
                        <div class="w-8 h-8 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center font-bold text-xs shadow-xs group-hover:scale-105 transition-transform duration-200">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0L9 14m3-3l3 3"></path>
                            </svg>
                        </div>
                        <span class="font-extrabold text-sm tracking-tight text-slate-900 dark:text-slate-100">DocGov</span>
                    </a>

                    <nav class="hidden md:flex items-center gap-1">
                        <a href="index.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                            Início
                        </a>
                        <a href="favoritos.php" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50 transition flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-amber-500 fill-amber-500" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                            <span>Favoritos</span>
                        </a>
                    </nav>
                </div>

                <div class="flex items-center gap-2">
                    <a href="index.php" class="text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 px-3 py-1.5 rounded-xl hover:bg-slate-200 transition">
                        &larr; Voltar ao Acervo
                    </a>
                    <a href="logout.php" class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 dark:hover:text-red-400 transition" title="Sair do Sistema">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </a>
                </div>
            </div>
        </header>
    </div>

    <div class="max-container py-6 sm:py-8">

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
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded <?= ($userData['role'] ?? '') === 'admin' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20' : (($userData['role'] ?? '') === 'editor' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-slate-200 dark:bg-[#2c2e33] text-slate-600 dark:text-slate-300') ?>">
                            Perfil: <?= ucfirst($userData['role'] ?? 'leitor') ?>
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
                                    <?= ucfirst($userData['role'] ?? 'leitor') ?>
                                </span>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                    <?php
                                        if ($userData['role'] === 'admin') echo "Acesso total irrestrito para administrar conteúdos, usuários e permissões do sistema.";
                                        elseif ($userData['role'] === 'editor') echo "Permite cadastrar e editar documentos oficiais nas áreas e categorias autorizadas.";
                                        else echo "Permissão apenas de leitura e consulta ao acervo municipal liberado.";
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
                                    <a href="index.php?cat=<?= urlencode($r['categoria']) ?>&subcat=<?= urlencode($r['subcategoria']) ?>&assunto=<?= urlencode($r['assunto']) ?>" class="font-bold text-slate-900 dark:text-slate-100 hover:underline text-xs">
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
                        Alterar Senha de Acesso
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
