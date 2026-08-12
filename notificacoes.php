<?php
require_once __DIR__ . '/config/session.php';
docgovStartSession();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/NotificationService.php';

$loggedUser = $_SESSION['user'] ?? null;
if (!$loggedUser || (int)($loggedUser['id'] ?? 0) <= 0) {
    header('Location: login.php');
    exit;
}

$userId = (int)$loggedUser['id'];
$notifications = new NotificationService($pdo);

if (isset($_GET['go'])) {
    $notificationId = (int)$_GET['go'];
    $stmtNotification = $pdo->prepare('SELECT document_id FROM notifications WHERE id = :id AND user_id = :user_id');
    $stmtNotification->execute([':id' => $notificationId, ':user_id' => $userId]);
    $documentId = (int)$stmtNotification->fetchColumn();
    $notifications->markRead($notificationId, $userId);
    header('Location: ' . ($documentId > 0 ? 'admin/index.php?tab=detalhes_documento&id=' . $documentId : 'notificacoes.php'));
    exit;
}
if (isset($_GET['read'])) {
    $notifications->markRead((int)$_GET['read'], $userId);
    header('Location: notificacoes.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_all_read'])) {
    $notifications->markAllRead($userId);
    header('Location: notificacoes.php?msg=all_read');
    exit;
}

$notificationItems = $notifications->listForUser($userId);
$unreadNotificationCount = $notifications->unreadCount($userId);
$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';

function notificationTone(string $type): string {
    return match ($type) {
        'document_review_requested' => 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
        'document_published' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        'document_changes_requested' => 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        'permission_granted', 'team_membership_added' => 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    };
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificações - <?= htmlspecialchars($appName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="min-h-screen bg-[#f8f9fa] text-slate-900 dark:bg-[#2c2e33] dark:text-slate-100">
    <?php require __DIR__ . '/partials/maintenance-banner.php'; ?>
    <div class="fixed inset-x-0 top-0 z-50 border-b border-slate-200/80 bg-white/90 shadow-sm shadow-slate-900/5 backdrop-blur-md dark:border-slate-800/80 dark:bg-[#1f2128]/95">
        <header class="max-container flex min-h-[58px] items-center justify-between gap-4">
            <a href="index.php" class="inline-flex items-center gap-2.5 text-decoration-none">
                <?php if ($appLogoUrl): ?>
                    <img src="<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="h-8 w-8 rounded-xl border border-slate-200 bg-white object-contain p-0.5 dark:border-[#454956] dark:bg-[#353842]">
                <?php else: ?>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900">D</span>
                <?php endif; ?>
                <span class="text-sm font-extrabold tracking-tight text-slate-900 dark:text-slate-100"><?= htmlspecialchars($appName) ?></span>
            </a>
            <div class="flex items-center gap-2">
                <?php require __DIR__ . '/partials/notification_link.php'; ?>
                <a href="index.php" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">Voltar ao acervo</a>
            </div>
        </header>
    </div>

    <main class="max-container pb-10 pt-20 sm:pt-24">
        <div class="mx-auto max-w-3xl">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Central de avisos</p>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Notificações</h1>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Acompanhe acessos liberados, solicitações de revisão e publicações aprovadas.</p>
                </div>
                <?php if ($unreadNotificationCount > 0): ?>
                    <form method="POST" action="notificacoes.php"><button type="submit" name="mark_all_read" value="1" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-[#454956] dark:text-slate-300 dark:hover:bg-[#2c2e33]">Marcar todas como lidas</button></form>
                <?php endif; ?>
            </div>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'all_read'): ?><p class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-700 dark:text-emerald-300">Todas as notificações foram marcadas como lidas.</p><?php endif; ?>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                <?php if (empty($notificationItems)): ?>
                    <div class="p-12 text-center"><p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Nenhuma notificação por enquanto</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Os próximos avisos do seu trabalho aparecerão aqui.</p></div>
                <?php endif; ?>
                <ul class="divide-y divide-slate-100 dark:divide-[#454956]">
                    <?php foreach ($notificationItems as $notification): ?>
                        <li class="<?= empty($notification['read_at']) ? 'bg-slate-50/80 dark:bg-[#2c2e33]/70' : '' ?>">
                            <a href="notificacoes.php?go=<?= (int)$notification['id'] ?>" class="flex gap-3 p-4 text-decoration-none transition hover:bg-slate-50 dark:hover:bg-[#2c2e33]">
                                <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg <?= notificationTone($notification['type']) ?>"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg></span>
                                <span class="min-w-0 flex-1"><span class="flex items-start justify-between gap-3"><strong class="text-xs text-slate-800 dark:text-slate-100"><?= htmlspecialchars($notification['title']) ?></strong><time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($notification['created_at'])) ?></time></span><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400"><?= htmlspecialchars($notification['body'] ?? '') ?></span></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </main>
</body>
</html>
