<?php
// login.php - Autenticação corporativa via Active Directory
require_once __DIR__ . '/config/session.php';
docgovStartSession();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/PermissionService.php';
require_once __DIR__ . '/services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/services/UsageAuditService.php';
$permService = new PermissionService($pdo);
$adAuthService = new ActiveDirectoryAuthService($pdo);
$usageAuditService = new UsageAuditService($pdo);
$adConfig = require __DIR__ . '/config/active_directory.php';
$adDomainsMap = [];
foreach ($adConfig['domains'] ?? [] as $dKey => $dVal) {
    if (!isset($dVal['enabled']) || $dVal['enabled']) {
        $adDomainsMap[$dKey] = !empty($dVal['name']) ? $dVal['name'] : $dKey;
    }
}
$availableAdDomains = array_keys($adDomainsMap);
$selectedAdDomain = strtoupper((string)($adConfig['default_domain'] ?? 'BETIM'));

// A tela de entrada sempre termina no acervo. O painel administrativo é uma
// escolha posterior, disponível no botão Admin para quem tiver permissão.
if (isset($_SESSION['user'])) {
    unset($_SESSION['admin_logged']);
    header('Location: index.php');
    exit;
}

$errorMsg = '';
// A política do portal exige credenciais explícitas em toda nova sessão.
// REMOTE_USER pode existir no IIS/Apache, mas não inicia sessão automaticamente.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['email'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');
    $selectedAdDomain = strtoupper(trim((string)($_POST['ad_domain'] ?? $selectedAdDomain)));

    if (!in_array($selectedAdDomain, $availableAdDomains, true)) {
        $errorMsg = 'Domínio corporativo inválido.';
    } elseif (!empty($loginInput) && !empty($senha)) {
        // O domínio vem exclusivamente da lista controlada no formulário.
        $authResult = $adAuthService->authenticate($selectedAdDomain . '\\' . $loginInput, $senha);
        if ($authResult['success']) {
            $user = $authResult['user'];
            $usageAuditService->log('login', (int)$user['id'], 'PORTAL', null, [
                'auth_source' => (string)($user['auth_source'] ?? 'ad'),
            ]);
            session_regenerate_id(true);
            $_SESSION['user'] = $adAuthService->buildSessionUser($user);

            unset($_SESSION['admin_logged']);
            header('Location: index.php');
            exit;
        } else {
            $errorMsg = $authResult['message'];
        }
    } else {
        $errorMsg = 'Por favor, preencha todos os campos.';
    }
}

// Métricas Reais do Banco para o Painel de Apresentação
try {
    $totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'published'")->fetchColumn();
    $totalCats = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE active = TRUE")->fetchColumn();
    $totalSubs = (int)$pdo->query("SELECT COUNT(*) FROM subcategories WHERE active = TRUE")->fetchColumn();
} catch (Exception $e) {
    $totalDocs = 0; $totalCats = 0; $totalSubs = 0;
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="light" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso ao Sistema - <?= htmlspecialchars($appName) ?></title>
    
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
    <style>
        .login-info-panel {
            background: #f3f4f6;
            border-left: 1px solid #e5e7eb;
        }
        .dark .login-info-panel {
            background: #262626;
            border-left-color: #424242;
        }
        .login-info-card {
            border: 1px solid #d1d5db;
            background: #ffffff;
        }
        .dark .login-info-card {
            border-color: #4a4a4a;
            background: #303030;
        }
    </style>
</head>
<body class="bg-[#f8f9fa] dark:bg-[#181a1f] text-slate-900 dark:text-slate-100 min-h-screen flex font-sans antialiased selection:bg-slate-800 selection:text-white">

    <div class="w-full flex flex-col md:flex-row min-h-screen">

        <!-- PAINEL DE LOGIN (FORMULÁRIO LATERAL) -->
        <div class="w-full md:w-[440px] lg:w-[480px] p-8 lg:p-12 flex flex-col justify-between border-r border-slate-200 dark:border-[#2c2e33] bg-white dark:bg-[#1e293b] z-10">
            
            <!-- CABEÇALHO DO MARCA -->
            <div>
                <div class="flex items-center gap-3 mb-10">
                    <?php if ($appLogoUrl): ?>
                        <img src="<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($appName) ?>" class="h-10 w-10 rounded-xl border border-slate-200 bg-white object-contain p-0.5 shadow-md dark:border-[#454956] dark:bg-[#353842]">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center font-bold">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0L9 14m3-3l3 3"/></svg>
                        </div>
                    <?php endif; ?>
                    <div>
                        <span class="font-extrabold text-lg tracking-tight text-slate-900 dark:text-slate-100 block leading-tight"><?= htmlspecialchars($appName) ?></span>
                        <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider"><?= htmlspecialchars($organizationName) ?></span>
                    </div>
                </div>

                <div class="space-y-1">
                    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">Portal Administrativo</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Use as mesmas credenciais da rede da Prefeitura (Active Directory).</p>
                </div>
            </div>

            <!-- FORMULÁRIO DE LOGIN -->
            <div class="my-8">
                <?php if (!empty($errorMsg)): ?>
                    <div class="mb-5 p-3.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs font-medium flex items-center gap-2.5 shadow-xs">
                        <svg class="w-4 h-4 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= htmlspecialchars($errorMsg) ?></span>
                    </div>
                <?php endif; ?>
                <form method="POST" action="login.php" class="space-y-4" autocomplete="off">
                    <div class="grid grid-cols-[120px_1fr] gap-2">
                        <div>
                            <label for="login-domain" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider text-[10px]">Domínio</label>
                            <select name="ad_domain" id="login-domain" class="w-full px-2.5 py-2.5 text-xs rounded-lg border border-slate-300 dark:border-[#353842] bg-slate-50 dark:bg-[#181a1f] text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-900 dark:focus:ring-white transition">
                                <?php foreach ($adDomainsMap as $domainKey => $domainName): ?>
                                    <option value="<?= htmlspecialchars($domainKey) ?>" <?= $selectedAdDomain === $domainKey ? 'selected' : '' ?>><?= htmlspecialchars($domainName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider text-[10px]">Usuário do AD</label>
                        <div class="relative">
                            <input type="text" 
                                   name="email" 
                                   id="login-input"
                                   required 
                                   class="w-full pl-9 pr-3 py-2.5 text-xs rounded-lg border border-slate-300 dark:border-[#353842] bg-slate-50 dark:bg-[#181a1f] text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-900 dark:focus:ring-white transition" 
                                   placeholder="Ex.: maria.silva"
                                   autocomplete="off"
                                   autocapitalize="none"
                                   spellcheck="false">
                            <span class="absolute left-3 top-3 text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </span>
                        </div>
                    </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider text-[10px]">Senha do Active Directory</label>
                        <div class="relative">
                            <input type="password" 
                                   name="senha" 
                                   id="login-password"
                                   required 
                                   class="w-full pl-9 pr-10 py-2.5 text-xs rounded-lg border border-slate-300 dark:border-[#353842] bg-slate-50 dark:bg-[#181a1f] text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-900 dark:focus:ring-white transition" 
                                   placeholder="••••••••"
                                   autocomplete="new-password">
                            <span class="absolute left-3 top-3 text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </span>
                            <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3 top-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                <svg class="w-4 h-4" id="eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" 
                            class="w-full bg-slate-900 dark:bg-white hover:bg-slate-800 dark:hover:bg-slate-100 text-white dark:text-slate-900 font-bold py-3 px-4 rounded-lg text-xs transition shadow-md flex items-center justify-center gap-2">
                        <span>Entrar no Sistema</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </form>
            </div>

            <!-- RODAPÉ DA TELA DE LOGIN -->
            <div class="pt-4 border-t border-slate-100 dark:border-[#2c2e33] text-[11px] text-slate-400">
                <span>© 2026 <?= htmlspecialchars($organizationName) ?></span>
            </div>

        </div>

        <!-- PAINEL DE CONTEXTO DO PORTAL -->
        <div class="hidden md:flex flex-1 login-info-panel p-12 lg:p-16 flex-col justify-between text-slate-900 dark:text-slate-100">
            <div class="max-w-xl my-auto py-12">
                <div class="inline-flex items-center gap-2 border-l-2 border-emerald-600 pl-3 text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400 mb-6">
                    Acesso corporativo
                </div>

                <h2 class="text-3xl lg:text-4xl font-bold tracking-tight leading-tight mb-5">
                    Gestão documental em um só lugar
                </h2>
                
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed mb-8 max-w-lg">
                    Acesso seguro à hierarquia completa de Categorias, Subcategorias, Assuntos e Documentos de <?= htmlspecialchars($organizationName) ?>.
                </p>

                <div class="grid grid-cols-3 gap-3">
                    <div class="login-info-card p-4 rounded-lg">
                        <span class="block text-xl font-bold text-slate-900 dark:text-slate-100"><?= $totalCats ?></span>
                        <span class="mt-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Categorias</span>
                    </div>
                    <div class="login-info-card p-4 rounded-lg">
                        <span class="block text-xl font-bold text-slate-900 dark:text-slate-100"><?= $totalSubs ?></span>
                        <span class="mt-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Subcategorias</span>
                    </div>
                    <div class="login-info-card p-4 rounded-lg">
                        <span class="block text-xl font-bold text-slate-900 dark:text-slate-100"><?= $totalDocs ?></span>
                        <span class="mt-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Documentos</span>
                    </div>
                </div>
            </div>

            <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center border-t border-slate-300 dark:border-[#424242] pt-6">
                <span><?= htmlspecialchars($appName) ?> &bull; <?= htmlspecialchars($organizationName) ?></span>
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const pwdInput = document.getElementById('login-password');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
            } else {
                pwdInput.type = 'password';
            }
        }
    </script>
</body>
</html>
