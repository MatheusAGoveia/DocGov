<?php
// login.php - Autenticação contra a tabela PostgreSQL `users`
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';

// Se já estiver logado, redireciona
if (isset($_SESSION['user'])) {
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        header('Location: admin/index.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if (!empty($loginInput) && !empty($senha)) {
        // Busca por username OU email na tabela `users` do PostgreSQL
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :input OR username = :input");
        $stmt->execute([':input' => $loginInput]);
        $user = $stmt->fetch();

        if ($user) {
            if (!$user['active']) {
                $errorMsg = 'Esta conta está inativa. Entre em contato com o administrador.';
            } 
            // Validação estrita de senha via hash no PostgreSQL
            elseif ($user['password_hash'] && password_verify($senha, $user['password_hash'])) {
                
                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'nome' => $user['name'],
                    'login' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'active' => (bool)$user['active'],
                    'avatar' => $user['avatar'] ?? null,
                    'tema_preferido' => 'light',
                    'inicial' => mb_strtoupper(mb_substr($user['name'], 0, 1))
                ];

                if ($user['role'] === 'admin') {
                    $_SESSION['admin_logged'] = true;
                    header('Location: admin/index.php');
                } else {
                    header('Location: index.php');
                }
                exit;
            } else {
                $errorMsg = 'Login/E-mail ou senha incorretos.';
            }
        } else {
            $errorMsg = 'Usuário não encontrado. Verifique seus dados.';
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
<html lang="pt-BR" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso ao Sistema - DocGov Prefeitura Municipal</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        darkMode: 'class',
        theme: {
          extend: {
            colors: {
              graphite: {
                950: '#111318',
                900: '#181a1f',
                800: '#23252a',
                700: '#2c2e33',
                600: '#353842'
              }
            }
          }
        }
      }
    </script>
    
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .login-gradient-bg {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body class="bg-[#f8f9fa] dark:bg-[#181a1f] text-slate-900 dark:text-slate-100 min-h-screen flex font-sans antialiased selection:bg-slate-800 selection:text-white">

    <div class="w-full flex flex-col md:flex-row min-h-screen">

        <!-- PAINEL DE LOGIN (FORMULÁRIO LATERAL) -->
        <div class="w-full md:w-[440px] lg:w-[480px] p-8 lg:p-12 flex flex-col justify-between border-r border-slate-200 dark:border-[#2c2e33] bg-white dark:bg-[#1e293b] shadow-xl z-10">
            
            <!-- CABEÇALHO DO MARCA -->
            <div>
                <div class="flex items-center gap-3 mb-10">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-slate-900 to-slate-700 dark:from-white dark:to-slate-200 text-white dark:text-slate-900 flex items-center justify-center font-bold shadow-md">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0L9 14m3-3l3 3"/></svg>
                    </div>
                    <div>
                        <span class="font-extrabold text-lg tracking-tight text-slate-900 dark:text-slate-100 block leading-tight">DocGov</span>
                        <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Prefeitura Municipal</span>
                    </div>
                </div>

                <div class="space-y-1">
                    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">Portal Administrativo</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Insira suas credenciais corporativas registradas no sistema.</p>
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

                <form method="POST" action="login.php" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider text-[10px]">Usuário ou E-mail</label>
                        <div class="relative">
                            <input type="text" 
                                   name="email" 
                                   id="login-input"
                                   required 
                                   class="w-full pl-9 pr-3 py-2.5 text-xs rounded-lg border border-slate-300 dark:border-[#353842] bg-slate-50 dark:bg-[#181a1f] text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-900 dark:focus:ring-white transition" 
                                   placeholder="seu.usuario ou email@prefeitura.gov.br">
                            <span class="absolute left-3 top-3 text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider text-[10px]">Senha de Acesso</label>
                        <div class="relative">
                            <input type="password" 
                                   name="senha" 
                                   id="login-password"
                                   required 
                                   class="w-full pl-9 pr-10 py-2.5 text-xs rounded-lg border border-slate-300 dark:border-[#353842] bg-slate-50 dark:bg-[#181a1f] text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-900 dark:focus:ring-white transition" 
                                   placeholder="••••••••">
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
            <div class="pt-4 border-t border-slate-100 dark:border-[#2c2e33] text-[11px] text-slate-400 flex items-center justify-between">
                <span>© 2026 Prefeitura Municipal</span>
                <a href="index.php" class="font-semibold text-slate-700 dark:text-slate-300 hover:underline flex items-center gap-1">
                    <span>Área Pública</span>
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            </div>

        </div>

        <!-- PAINEL ILUSTRATIVO DE APRESENTAÇÃO (HERO 60%) -->
        <div class="hidden md:flex flex-1 login-gradient-bg p-12 lg:p-16 flex-col justify-between relative overflow-hidden text-white">
            
            <!-- EFEITOS DE GLOW AMBIENTE -->
            <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-slate-700/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute bottom-0 left-0 w-[400px] h-[400px] bg-sky-500/10 rounded-full blur-3xl pointer-events-none"></div>

            <div class="relative z-10 flex justify-end">
                <a href="index.php" class="text-xs font-semibold text-slate-200 bg-white/10 hover:bg-white/20 backdrop-blur-md px-4 py-2 rounded-lg border border-white/15 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    <span>Ir ao Acervo Público</span>
                </a>
            </div>

            <div class="relative z-10 max-w-xl my-auto py-12">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px] font-bold mb-6">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    PostgreSQL Fonte Única de Verdade
                </div>

                <h2 class="text-3xl lg:text-5xl font-extrabold tracking-tight leading-tight mb-6">
                    Plataforma Oficial de Gestão Documental
                </h2>
                
                <p class="text-sm text-slate-300 leading-relaxed mb-10">
                    Acesso seguro à hierarquia completa de **Categorias, Subcategorias, Assuntos e Documentos** da Prefeitura Municipal.
                </p>

                <!-- METRICAS CARD GLASS -->
                <div class="grid grid-cols-3 gap-4">
                    <div class="glass-card p-4 rounded-xl text-center">
                        <span class="block text-2xl font-black text-white"><?= $totalCats ?></span>
                        <span class="text-[10px] text-slate-300 font-semibold uppercase">Categorias</span>
                    </div>
                    <div class="glass-card p-4 rounded-xl text-center">
                        <span class="block text-2xl font-black text-white"><?= $totalSubs ?></span>
                        <span class="text-[10px] text-slate-300 font-semibold uppercase">Subcategorias</span>
                    </div>
                    <div class="glass-card p-4 rounded-xl text-center">
                        <span class="block text-2xl font-black text-white"><?= $totalDocs ?></span>
                        <span class="text-[10px] text-slate-300 font-semibold uppercase">Documentos</span>
                    </div>
                </div>
            </div>

            <div class="relative z-10 text-xs text-slate-400 flex items-center justify-between border-t border-white/10 pt-6">
                <span>DocGov &bull; Prefeitura Municipal</span>
                <span>PostgreSQL 18.4</span>
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
