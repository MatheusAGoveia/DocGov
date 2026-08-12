<?php
// ver_conteudo.php — Visualizador de Conteúdo em PostgreSQL (Fonte Única & Engine PDF.js Nativa)
require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/VideoEmbedService.php';

$loggedUser = $_SESSION['user'] ?? null;
$docId = (int)($_GET['id'] ?? 0);

if ($docId <= 0) {
    header('Location: index.php');
    exit;
}

// Consulta Relacional Completa no PostgreSQL (Documento + Hierarquia + Autor)
$stmt = $pdo->prepare("
    SELECT 
        d.id, d.subject_id, d.created_by, d.title, d.slug, d.description, 
        d.content_type, d.status, d.published_at, d.original_filename, 
        d.stored_filename, d.file_path, d.mime_type, d.file_extension, 
        d.file_size, d.text_content, d.code_language, d.external_url, d.created_at, d.updated_at,
        s.name AS subject_name, s.slug AS subject_slug,
        sc.name AS subcategory_name, sc.slug AS subcategory_slug,
        c.name AS category_name, c.slug AS category_slug,
        u.name AS autor_nome
    FROM documents d
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.id = :id
");
$stmt->execute([':id' => $docId]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/services/AccessService.php';
$accessService = new AccessService($pdo);
$userId = $loggedUser ? (int)$loggedUser['id'] : 0;

if (!$accessService->canAccessDocument($userId, $docId)) {
    http_response_code(403);
    die("
        <div style='font-family: sans-serif; text-align: center; padding: 50px;'>
            <h2 style='color: #e11d48; display: flex; items-center; justify-content: center; gap: 8px;'>
                <svg style='width: 28px; height: 28px; display: inline-block; vertical-align: middle;' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'/></svg>
                <span>Acesso Negado (403)</span>
            </h2>
            <p style='color: #64748b;'>Sua conta não possui permissão no grupo de acesso para visualizar este documento.</p>
            <a href='index.php' style='color: #0f172a; text-decoration: underline;'>&larr; Voltar para a Home</a>
        </div>
    ");
}

$canView = true;
require_once __DIR__ . '/services/PermissionService.php';
$_permSvcVC = new PermissionService($pdo);
require_once __DIR__ . '/services/UsageAuditService.php';
$usageAuditService = new UsageAuditService($pdo);
$usageAuditService->log('document_view', $userId, 'DOCUMENT', $docId, [
    'content_type' => (string)($doc['content_type'] ?? 'file'),
]);
$canEdit = $loggedUser && $_permSvcVC->canEditDocument($userId, $docId);
$canDelete = $loggedUser && $_permSvcVC->isGlobalAdmin($userId);
require_once __DIR__ . '/services/TagService.php';
$documentTags = (new TagService($pdo))->getDocumentTags($docId);

$isFavorite = false;
if ($loggedUser) {
    $stmtFav = $pdo->prepare("SELECT id FROM favorites WHERE user_id = :uid AND document_id = :did");
    $stmtFav->execute([':uid' => (int)$loggedUser['id'], ':did' => $docId]);
    $isFavorite = (bool)$stmtFav->fetch();
}

// Exclusão de Documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc']) && $canDelete) {
    if (!empty($doc['stored_filename'])) {
        $physicalFile = __DIR__ . '/storage/documents/' . basename($doc['stored_filename']);
        if (file_exists($physicalFile)) {
            @unlink($physicalFile);
        }
    }
    $pdo->prepare("DELETE FROM documents WHERE id = :id")->execute([':id' => $docId]);
    header('Location: index.php?msg=deleted');
    exit;
}

function viewerFormatSize(?int $bytes): ?string {
    if ($bytes === null || $bytes <= 0) return null;
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}

function viewerFormatDate(?string $dateStr): ?string {
    if ($dateStr === null || trim($dateStr) === '') return null;
    $ts = strtotime($dateStr);
    if ($ts === false) return null;
    return date('d/m/Y', $ts);
}

$mimeType = strtolower($doc['mime_type'] ?? '');
$fileExt = strtolower($doc['file_extension'] ?? '');
$contentType = $doc['content_type'] ?? 'file';

$isPdf = ($contentType === 'file') && ($fileExt === 'pdf' || str_contains($mimeType, 'pdf') || str_contains(strtolower($doc['original_filename'] ?? ''), '.pdf'));
$isImage = ($contentType === 'file') && (str_starts_with($mimeType, 'image/') || in_array($fileExt, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif'], true));
$isTextFile = ($contentType === 'file') && (str_starts_with($mimeType, 'text/') || in_array($fileExt, ['txt', 'log', 'csv', 'md', 'json', 'xml'], true));
$isDocx = ($contentType === 'file') && ($fileExt === 'docx' || str_contains($mimeType, 'officedocument.wordprocessingml'));
$isAudio = ($contentType === 'file') && (str_starts_with($mimeType, 'audio/') || in_array($fileExt, ['mp3', 'wav', 'ogg'], true));
$isVideo = in_array($contentType, ['file', 'video'], true)
    && !empty($doc['stored_filename'])
    && (str_starts_with($mimeType, 'video/') || in_array($fileExt, ['mp4', 'webm', 'ogv', 'm4v', 'mov'], true));
$externalVideo = !empty($doc['external_url']) && in_array($contentType, ['video', 'link'], true)
    ? VideoEmbedService::resolve((string)$doc['external_url'])
    : ['kind' => 'invalid'];
$isExternalVideo = in_array($externalVideo['kind'], ['youtube', 'vimeo', 'direct', 'external'], true);
$displayTypeLabel = $contentType === 'file' ? ($fileExt ?: 'arquivo') : ($contentType === 'video' ? 'vídeo' : $contentType);

$streamUrl = 'document-file.php?id=' . $docId;
$downloadUrl = 'download.php?id=' . $docId;
$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
$navigationTrail = [
    ['kind' => 'Portal', 'label' => 'Categorias', 'url' => 'index.php'],
    [
        'kind' => 'Categoria',
        'label' => $doc['category_name'],
        'url' => 'index.php?cat=' . urlencode($doc['category_slug']),
    ],
    [
        'kind' => 'Subcategoria',
        'label' => $doc['subcategory_name'],
        'url' => 'index.php?cat=' . urlencode($doc['category_slug']) . '&subcat=' . urlencode($doc['subcategory_slug']),
    ],
    [
        'kind' => 'Assunto',
        'label' => $doc['subject_name'],
        'url' => 'index.php?cat=' . urlencode($doc['category_slug']) . '&subcat=' . urlencode($doc['subcategory_slug']) . '&assunto=' . urlencode($doc['subject_slug']),
    ],
    ['kind' => 'Documento', 'label' => $doc['title'], 'url' => null],
];
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($doc['title']) ?> - <?= htmlspecialchars($appName) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/code-snippets.css">
    <script defer src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.11.1/build/highlight.min.js"></script>
    <script defer src="assets/code-snippets.js"></script>
    <?php if ($isDocx): ?>
        <script defer src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.6/dist/docx-preview.min.js"></script>
        <style>
            #docxPreviewContainer .docx-wrapper { background: #e2e8f0; padding: 1.5rem; }
            #docxPreviewContainer .docx-wrapper > section.docx { margin: 0 auto 1.25rem; box-shadow: 0 4px 18px rgba(15,23,42,.15); }
            .dark #docxPreviewContainer .docx-wrapper { background: #262626; }
        </style>
    <?php endif; ?>
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
    <?php if ($isPdf): ?>
        <!-- Biblioteca PDF.js Local -->
        <script src="assets/pdfjs/pdf.min.js"></script>
        <style>
            .pdf-page-canvas {
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                border-radius: 4px;
                background-color: #ffffff;
                max-width: 100%;
                height: auto;
            }
            .pdf-page-wrapper {
                display: flex;
                flex-direction: column;
                align-items: center;
                position: relative;
            }
        </style>
    <?php endif; ?>
</head>
<body class="bg-[#f8f9fa] dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 min-h-screen flex flex-col justify-between selection:bg-slate-800 selection:text-white">
    <?php require __DIR__ . '/partials/maintenance-banner.php'; ?>

    <div>
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
                        </nav>
                    </div>

                    <div class="flex items-center gap-2">
                        <a href="index.php" class="text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 px-3 py-1.5 rounded-xl hover:bg-slate-200 transition">
                            &larr; Voltar ao Acervo
                        </a>
                    </div>
                </div>
            </header>
        </div>

        <!-- CONTAINER DO VISUALIZADOR -->
        <main class="max-container pb-10 pt-20 sm:pt-24">
            <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[10.5rem_minmax(0,1fr)] lg:gap-6">
                <?php require __DIR__ . '/partials/vertical_navigation.php'; ?>
                <section class="min-w-0">

            <!-- CARD DE CONTEÚDO -->
            <div class="bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956] shadow-xs p-4 sm:p-5 md:p-6">
                
                <!-- CABEÇALHO DO DOCUMENTO -->
                <div class="border-b border-slate-100 dark:border-[#454956] pb-6 mb-6">
                    <div class="flex items-center justify-between gap-4 flex-wrap mb-2">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 px-2 py-0.5 rounded bg-slate-100 dark:bg-[#2c2e33]">
                            <?= htmlspecialchars(strtoupper($displayTypeLabel)) ?>
                        </span>
                        
                        <?php if ($loggedUser): ?>
                            <button type="button" 
                                    id="btn-fav"
                                    aria-label="<?= $isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"
                                    title="<?= $isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"
                                    onclick="toggleFavorito(<?= $docId ?>)"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-md border border-slate-200 dark:border-[#454956] hover:bg-slate-50 dark:hover:bg-[#2c2e33] transition flex items-center gap-1.5 cursor-pointer">
                                <span id="fav-icon">
                                    <svg class="w-4 h-4 <?= $isFavorite ? 'text-amber-500 fill-amber-500 stroke-amber-500' : 'text-slate-400 fill-none stroke-currentColor' ?>" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                </span>
                                <span id="fav-text"><?= $isFavorite ? 'Favoritado' : 'Favoritar' ?></span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight leading-tight">
                        <?= htmlspecialchars($doc['title']) ?>
                    </h1>
                    
                    <?php if (!empty($doc['description'])): ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                            <?= htmlspecialchars($doc['description']) ?>
                        </p>
                    <?php endif; ?>

                    <div class="flex items-center gap-4 text-[11px] text-slate-400 dark:text-slate-500 mt-4 flex-wrap">
                        <span>Autor: <b><?= htmlspecialchars($doc['autor_nome'] ?: 'Sistema') ?></b></span>
                        <span>Publicado em: <b><?= viewerFormatDate($doc['published_at'] ?: $doc['created_at']) ?></b></span>
                        <?php if ($doc['file_size']): ?>
                            <span>Tamanho: <b><?= viewerFormatSize((int)$doc['file_size']) ?></b></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($documentTags)): ?>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Tags</span>
                            <?php foreach ($documentTags as $tag): ?>
                                <a href="index.php?tag=<?= urlencode($tag['name']) ?>" class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-semibold text-slate-600 transition hover:border-slate-400 hover:bg-white hover:text-slate-900 dark:border-[#454956] dark:bg-[#2c2e33] dark:text-slate-300 dark:hover:bg-[#353842]">
                                    <?= htmlspecialchars($tag['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- EXIBIÇÃO DE CONTEÚDO POR TIPO -->
                <?php if ($isPdf): ?>
                    <!-- VISUALIZADOR PDF.JS NATIVO COM SCROLL CONTÍNUO -->
                    <div id="pdfViewerApp" class="w-full">
                        <!-- TOOLBAR SUPERIOR -->
                        <div class="flex items-center justify-between p-3 rounded-t-lg bg-slate-800 text-white text-xs flex-wrap gap-3 border-b border-slate-700">
                            <!-- NAVEGAÇÃO DE PÁGINA -->
                            <div class="flex items-center gap-2">
                                <span class="text-slate-400">Página</span>
                                <span id="page-num" class="font-bold text-white px-2 py-0.5 rounded bg-slate-900 border border-slate-700">1</span>
                                <span class="text-slate-400">/</span>
                                <span id="page-count" class="text-slate-400">--</span>
                            </div>

                            <!-- CONTROLES DE ZOOM E LARGURA -->
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="changeZoom(-0.25)" class="p-1.5 rounded hover:bg-slate-700 text-slate-300 font-bold" title="Reduzir Zoom">-</button>
                                <span id="zoom-val" class="font-mono text-[11px] px-2.5 py-1 rounded bg-slate-900 text-slate-300 font-semibold border border-slate-700">100%</span>
                                <button type="button" onclick="changeZoom(0.25)" class="p-1.5 rounded hover:bg-slate-700 text-slate-300 font-bold" title="Aumentar Zoom">+</button>
                                <button type="button" onclick="fitWidth()" class="px-2.5 py-1 rounded hover:bg-slate-700 text-slate-300 text-[11px] font-semibold border border-slate-700" title="Ajustar à Largura da Tela">Ajustar</button>
                            </div>

                            <!-- AÇÕES DE TELA CHEIA E DOWNLOAD -->
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="toggleFullscreen()" class="px-2.5 py-1 rounded bg-slate-700 hover:bg-slate-600 text-white text-[11px] font-semibold flex items-center gap-1 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                                    <span>Tela Cheia</span>
                                </button>
                                <a href="<?= $downloadUrl ?>" class="px-3 py-1 rounded bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] flex items-center gap-1.5 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    <span>Baixar PDF</span>
                                </a>
                            </div>
                        </div>

                        <!-- CONTAINER DOS CANVASES DAS PÁGINAS -->
                        <div id="pdfViewerContainer" class="bg-slate-900/95 p-4 sm:p-8 rounded-b-lg min-h-[550px] max-h-[85vh] overflow-y-auto space-y-6 flex flex-col items-center shadow-inner relative border border-slate-800">
                            <!-- SKELETON / CARREGANDO -->
                            <div id="pdfLoading" class="my-20 text-center text-slate-400 flex flex-col items-center gap-3">
                                <div class="w-9 h-9 border-4 border-slate-700 border-t-emerald-500 rounded-full animate-spin"></div>
                                <span class="text-xs font-semibold text-slate-300">Carregando e processando documento PDF...</span>
                            </div>

                            <!-- ESTADO DE ERRO -->
                            <div id="pdfError" class="hidden my-16 text-center text-slate-300 max-w-md p-6 rounded-lg bg-slate-800 border border-slate-700">
                                <svg class="w-10 h-10 text-amber-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <h3 class="font-bold text-sm text-white mb-1">Não foi possível visualizar este PDF</h3>
                                <p id="pdfErrorText" class="text-xs text-slate-400 mb-4">O arquivo pode não ter sido localizado ou o formato ser incompatível.</p>
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" onclick="loadPdfViewer()" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold">Tentar Novamente</button>
                                    <a href="<?= $downloadUrl ?>" class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">Baixar Arquivo</a>
                                </div>
                            </div>

                            <!-- PÁGINAS DO PDF SERÃO INSERIDAS AQUI -->
                            <div id="pdfPages" class="w-full flex flex-col items-center space-y-6"></div>
                        </div>
                    </div>

                    <!-- ENGINE PDF.JS INTEGRADA -->
                    <script>
                        // Configuração Oficial da biblioteca local PDF.js v3.11.174
                        pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/pdfjs/pdf.worker.min.js';

                        const pdfStreamUrl = '<?= $streamUrl ?>';
                        let currentPdf = null;
                        let currentScale = 1.25;
                        let totalPagesCount = 0;
                        let observer = null;

                        async function loadPdfViewer() {
                            const loadingEl = document.getElementById('pdfLoading');
                            const errorEl = document.getElementById('pdfError');
                            const pagesEl = document.getElementById('pdfPages');

                            loadingEl.classList.remove('hidden');
                            errorEl.classList.add('hidden');
                            pagesEl.innerHTML = '';

                            try {
                                const loadingTask = pdfjsLib.getDocument({
                                    url: pdfStreamUrl,
                                    cMapUrl: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/cmaps/',
                                    cMapPacked: true
                                });

                                currentPdf = await loadingTask.promise;
                                totalPagesCount = currentPdf.numPages;

                                document.getElementById('page-count').textContent = totalPagesCount;
                                loadingEl.classList.add('hidden');

                                // Renderização Sequencial Contínua das Páginas
                                for (let p = 1; p <= totalPagesCount; p++) {
                                    const pageWrapper = document.createElement('div');
                                    pageWrapper.className = 'pdf-page-wrapper';
                                    pageWrapper.dataset.pageNumber = p;

                                    const canvas = document.createElement('canvas');
                                    canvas.className = 'pdf-page-canvas';
                                    canvas.id = 'pdf-canvas-' + p;

                                    pageWrapper.appendChild(canvas);
                                    pagesEl.appendChild(pageWrapper);

                                    await renderPageCanvas(p, canvas);
                                }

                                setupPageObserver();

                            } catch (err) {
                                console.error('Erro ao interpretar PDF com PDF.js:', err);
                                loadingEl.classList.add('hidden');
                                errorEl.classList.remove('hidden');
                                document.getElementById('pdfErrorText').textContent = err.message || 'Falha ao processar os bytes do PDF.';
                            }
                        }

                        async function renderPageCanvas(pageNo, canvas) {
                            if (!currentPdf) return;
                            try {
                                const page = await currentPdf.getPage(pageNo);
                                const viewport = page.getViewport({ scale: currentScale });
                                const context = canvas.getContext('2d');

                                canvas.width = viewport.width;
                                canvas.height = viewport.height;

                                await page.render({
                                    canvasContext: context,
                                    viewport: viewport
                                }).promise;
                            } catch (e) {
                                console.error('Erro ao renderizar pagina ' + pageNo, e);
                            }
                        }

                        async function rerenderAllPages() {
                            if (!currentPdf) return;
                            document.getElementById('zoom-val').textContent = Math.round(currentScale * 100) + '%';
                            for (let p = 1; p <= totalPagesCount; p++) {
                                const canvas = document.getElementById('pdf-canvas-' + p);
                                if (canvas) {
                                    await renderPageCanvas(p, canvas);
                                }
                            }
                        }

                        function changeZoom(delta) {
                            let newScale = currentScale + delta;
                            if (newScale < 0.5) newScale = 0.5;
                            if (newScale > 2.5) newScale = 2.5;
                            currentScale = newScale;
                            rerenderAllPages();
                        }

                        function fitWidth() {
                            const container = document.getElementById('pdfViewerContainer');
                            if (!container || !currentPdf) return;
                            currentPdf.getPage(1).then(page => {
                                const unscaledViewport = page.getViewport({ scale: 1.0 });
                                const availWidth = container.clientWidth - 48; // margem interna
                                if (availWidth > 0) {
                                    currentScale = availWidth / unscaledViewport.width;
                                    rerenderAllPages();
                                }
                            });
                        }

                        function toggleFullscreen() {
                            const elem = document.getElementById('pdfViewerApp');
                            if (!document.fullscreenElement) {
                                elem.requestFullscreen().catch(err => alert('Erro ao entrar em Tela Cheia.'));
                            } else {
                                document.exitFullscreen();
                            }
                        }

                        function setupPageObserver() {
                            if (observer) observer.disconnect();
                            const options = {
                                root: document.getElementById('pdfViewerContainer'),
                                threshold: 0.3
                            };
                            observer = new IntersectionObserver((entries) => {
                                entries.forEach(entry => {
                                    if (entry.isIntersecting) {
                                        const pageNum = entry.target.dataset.pageNumber;
                                        document.getElementById('page-num').textContent = pageNum;
                                    }
                                });
                            }, options);

                            document.querySelectorAll('.pdf-page-wrapper').forEach(wrapper => {
                                observer.observe(wrapper);
                            });
                        }

                        // Inicialização Automática no Carregamento
                        document.addEventListener('DOMContentLoaded', loadPdfViewer);
                    </script>

                <?php elseif ($isImage): ?>
                    <div id="imageViewerApp" class="overflow-hidden rounded-lg border border-slate-200 dark:border-[#454956] bg-slate-100 dark:bg-[#202329]">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 dark:border-[#454956] bg-white dark:bg-[#2c2e33] px-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($doc['original_filename'] ?: 'Imagem') ?></p>
                                <p class="text-[10px] text-slate-400"><?= viewerFormatSize((int)$doc['file_size']) ?> · <?= htmlspecialchars(strtoupper($fileExt ?: 'imagem')) ?></p>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button type="button" onclick="changeImageZoom(-0.15)" class="rounded border border-slate-200 dark:border-[#454956] px-2 py-1 text-xs font-bold hover:bg-slate-100 dark:hover:bg-[#3e424e]" aria-label="Reduzir imagem">−</button>
                                <span id="imageZoomLabel" class="min-w-12 text-center font-mono text-[10px] text-slate-500 dark:text-slate-300">100%</span>
                                <button type="button" onclick="changeImageZoom(0.15)" class="rounded border border-slate-200 dark:border-[#454956] px-2 py-1 text-xs font-bold hover:bg-slate-100 dark:hover:bg-[#3e424e]" aria-label="Ampliar imagem">+</button>
                                <button type="button" onclick="rotateViewerImage()" class="rounded border border-slate-200 dark:border-[#454956] px-2.5 py-1 text-[10px] font-semibold hover:bg-slate-100 dark:hover:bg-[#3e424e]">Girar</button>
                                <button type="button" onclick="fitViewerImage()" class="rounded border border-slate-200 dark:border-[#454956] px-2.5 py-1 text-[10px] font-semibold hover:bg-slate-100 dark:hover:bg-[#3e424e]">Ajustar</button>
                                <button type="button" onclick="toggleImageFullscreen()" class="rounded border border-slate-200 dark:border-[#454956] px-2.5 py-1 text-[10px] font-semibold hover:bg-slate-100 dark:hover:bg-[#3e424e]">Tela cheia</button>
                                <a href="<?= $downloadUrl ?>" class="rounded bg-slate-900 dark:bg-white px-2.5 py-1 text-[10px] font-semibold text-white dark:text-slate-900">Baixar</a>
                            </div>
                        </div>
                        <div id="imageViewerCanvas" class="flex min-h-80 max-h-[75vh] items-center justify-center overflow-auto p-4 sm:p-6">
                            <img id="protectedImageViewer" src="<?= htmlspecialchars($streamUrl) ?>" alt="<?= htmlspecialchars($doc['title']) ?>" class="block rounded shadow-lg transition-[width,transform] duration-150">
                            <div id="imageViewerError" class="hidden max-w-md rounded-md border border-red-500/20 bg-red-500/10 p-5 text-center text-xs text-red-600 dark:text-red-400">
                                Não foi possível carregar esta imagem. Use o botão Baixar para obter o arquivo original.
                            </div>
                        </div>
                    </div>
                    <script>
                        let protectedImageBaseWidth = 0;
                        let protectedImageZoom = 1;
                        let protectedImageRotation = 0;

                        function initializeImageViewer() {
                            const image = document.getElementById('protectedImageViewer');
                            const canvas = document.getElementById('imageViewerCanvas');
                            if (!image || !canvas) return;
                            protectedImageBaseWidth = Math.max(1, Math.min(image.naturalWidth || 1, Math.max(280, canvas.clientWidth - 48)));
                            fitViewerImage();
                        }

                        function updateImageViewer() {
                            const image = document.getElementById('protectedImageViewer');
                            const label = document.getElementById('imageZoomLabel');
                            if (!image || !protectedImageBaseWidth) return;
                            image.style.width = Math.round(protectedImageBaseWidth * protectedImageZoom) + 'px';
                            image.style.maxWidth = 'none';
                            image.style.transform = 'rotate(' + protectedImageRotation + 'deg)';
                            if (label) label.textContent = Math.round(protectedImageZoom * 100) + '%';
                        }

                        function changeImageZoom(delta) {
                            protectedImageZoom = Math.min(4, Math.max(0.25, protectedImageZoom + delta));
                            updateImageViewer();
                        }

                        function rotateViewerImage() {
                            protectedImageRotation = (protectedImageRotation + 90) % 360;
                            updateImageViewer();
                        }

                        function fitViewerImage() {
                            protectedImageZoom = 1;
                            protectedImageRotation = 0;
                            updateImageViewer();
                        }

                        function toggleImageFullscreen() {
                            const viewer = document.getElementById('imageViewerApp');
                            if (!viewer) return;
                            if (!document.fullscreenElement) viewer.requestFullscreen().catch(() => {});
                            else document.exitFullscreen();
                        }

                        function showImageViewerError() {
                            const image = document.getElementById('protectedImageViewer');
                            const error = document.getElementById('imageViewerError');
                            if (image) image.classList.add('hidden');
                            if (error) error.classList.remove('hidden');
                        }

                        const protectedViewerImage = document.getElementById('protectedImageViewer');
                        if (protectedViewerImage) {
                            protectedViewerImage.addEventListener('error', showImageViewerError);
                            if (protectedViewerImage.complete && protectedViewerImage.naturalWidth > 0) initializeImageViewer();
                            else protectedViewerImage.addEventListener('load', initializeImageViewer, { once: true });
                        }
                    </script>

                <?php elseif ($isTextFile): ?>
                    <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-[#454956]">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] px-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($doc['original_filename'] ?: 'Arquivo de texto') ?></p>
                                <p class="text-[10px] text-slate-400"><?= viewerFormatSize((int)$doc['file_size']) ?> · visualização somente leitura</p>
                            </div>
                            <a href="<?= $downloadUrl ?>" class="shrink-0 rounded bg-slate-900 dark:bg-white px-3 py-1.5 text-[10px] font-semibold text-white dark:text-slate-900">Baixar</a>
                        </div>
                        <pre id="protectedTextFile" class="min-h-72 max-h-[70vh] overflow-auto bg-[#24272e] p-4 font-mono text-xs leading-6 text-slate-200 whitespace-pre-wrap break-words">Carregando arquivo...</pre>
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', async function () {
                            const output = document.getElementById('protectedTextFile');
                            if (!output) return;
                            try {
                                const response = await fetch(<?= json_encode($streamUrl) ?>, { credentials: 'same-origin' });
                                if (!response.ok) throw new Error('HTTP ' + response.status);
                                const buffer = await response.arrayBuffer();
                                const limit = 2 * 1024 * 1024;
                                const visibleBuffer = buffer.byteLength > limit ? buffer.slice(0, limit) : buffer;
                                let content = new TextDecoder('utf-8').decode(visibleBuffer);
                                <?php if ($fileExt === 'json'): ?>
                                try { content = JSON.stringify(JSON.parse(content), null, 2); } catch (error) {}
                                <?php endif; ?>
                                if (buffer.byteLength > limit) content += '\n\n[Prévia limitada aos primeiros 2 MB do arquivo.]';
                                output.textContent = content;
                            } catch (error) {
                                output.textContent = 'Não foi possível carregar a prévia deste arquivo.';
                            }
                        });
                    </script>

                <?php elseif ($isDocx): ?>
                    <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-[#454956]">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] px-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($doc['original_filename'] ?: 'Documento Word') ?></p>
                                <p class="text-[10px] text-slate-400">Prévia DOCX protegida no navegador</p>
                            </div>
                            <a href="<?= $downloadUrl ?>" class="shrink-0 rounded bg-slate-900 dark:bg-white px-3 py-1.5 text-[10px] font-semibold text-white dark:text-slate-900">Baixar original</a>
                        </div>
                        <div id="docxPreviewLoading" class="p-8 text-center text-xs text-slate-500 dark:text-slate-400">Carregando documento Word...</div>
                        <div id="docxPreviewError" class="hidden p-8 text-center text-xs text-red-600 dark:text-red-400">Não foi possível montar a prévia. Baixe o arquivo original para abri-lo no Word.</div>
                        <div id="docxPreviewContainer" class="max-h-[75vh] overflow-auto"></div>
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', async function () {
                            const loading = document.getElementById('docxPreviewLoading');
                            const error = document.getElementById('docxPreviewError');
                            const container = document.getElementById('docxPreviewContainer');
                            try {
                                if (!window.docx || !window.JSZip) throw new Error('Visualizador DOCX indisponível.');
                                const response = await fetch(<?= json_encode($streamUrl) ?>, { credentials: 'same-origin' });
                                if (!response.ok) throw new Error('HTTP ' + response.status);
                                const data = await response.arrayBuffer();
                                await window.docx.renderAsync(data, container, null, {
                                    inWrapper: true,
                                    breakPages: true,
                                    ignoreLastRenderedPageBreak: false,
                                    renderHeaders: true,
                                    renderFooters: true,
                                    renderFootnotes: true,
                                    renderAltChunks: false,
                                    useBase64URL: true,
                                });
                                loading.classList.add('hidden');
                            } catch (previewError) {
                                loading.classList.add('hidden');
                                error.classList.remove('hidden');
                            }
                        });
                    </script>

                <?php elseif ($isAudio): ?>
                    <div class="rounded-lg border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] p-6 text-center">
                        <p class="mb-4 text-xs font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($doc['original_filename'] ?: 'Arquivo de áudio') ?></p>
                        <audio controls preload="metadata" class="mx-auto w-full max-w-2xl" src="<?= htmlspecialchars($streamUrl) ?>">Seu navegador não consegue reproduzir este áudio.</audio>
                        <a href="<?= $downloadUrl ?>" class="mt-4 inline-flex rounded bg-slate-900 dark:bg-white px-3 py-1.5 text-[10px] font-semibold text-white dark:text-slate-900">Baixar áudio</a>
                    </div>

                <?php elseif ($isVideo): ?>
                    <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-[#454956] bg-black">
                        <video controls preload="metadata" class="mx-auto max-h-[75vh] w-full" src="<?= htmlspecialchars($streamUrl) ?>">Seu navegador não consegue reproduzir este vídeo.</video>
                        <div class="flex items-center justify-between gap-3 bg-slate-50 dark:bg-[#2c2e33] px-3 py-2">
                            <p class="truncate text-xs font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($doc['original_filename'] ?: 'Arquivo de vídeo') ?></p>
                            <a href="<?= $downloadUrl ?>" class="shrink-0 rounded bg-slate-900 dark:bg-white px-3 py-1.5 text-[10px] font-semibold text-white dark:text-slate-900">Baixar vídeo</a>
                        </div>
                    </div>

                <?php elseif ($isExternalVideo): ?>
                    <?php if (in_array($externalVideo['kind'], ['youtube', 'vimeo'], true)): ?>
                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-black dark:border-[#454956]">
                            <iframe
                                class="aspect-video w-full"
                                src="<?= htmlspecialchars((string)$externalVideo['embed_url']) ?>"
                                title="Vídeo do <?= htmlspecialchars((string)$externalVideo['provider']) ?>: <?= htmlspecialchars($doc['title']) ?>"
                                loading="lazy"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                allowfullscreen></iframe>
                            <div class="flex items-center justify-between gap-3 bg-slate-50 px-3 py-2 dark:bg-[#2c2e33]">
                                <p class="truncate text-xs font-bold text-slate-800 dark:text-slate-100">Vídeo incorporado do <?= htmlspecialchars((string)$externalVideo['provider']) ?></p>
                                <a href="<?= htmlspecialchars((string)$externalVideo['url']) ?>" target="_blank" rel="noopener noreferrer" class="shrink-0 rounded bg-slate-900 px-3 py-1.5 text-[10px] font-semibold text-white dark:bg-white dark:text-slate-900">Abrir origem</a>
                            </div>
                        </div>
                    <?php elseif ($externalVideo['kind'] === 'direct'): ?>
                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-black dark:border-[#454956]">
                            <video controls preload="metadata" class="mx-auto max-h-[75vh] w-full" src="<?= htmlspecialchars((string)$externalVideo['url']) ?>">Seu navegador não consegue reproduzir este vídeo externo.</video>
                            <div class="flex items-center justify-between gap-3 bg-slate-50 px-3 py-2 dark:bg-[#2c2e33]">
                                <p class="truncate text-xs font-bold text-slate-800 dark:text-slate-100">Vídeo hospedado externamente</p>
                                <a href="<?= htmlspecialchars((string)$externalVideo['url']) ?>" target="_blank" rel="noopener noreferrer" class="shrink-0 rounded bg-slate-900 px-3 py-1.5 text-[10px] font-semibold text-white dark:bg-white dark:text-slate-900">Abrir origem</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-6 text-center dark:border-[#454956] dark:bg-[#2c2e33]">
                            <p class="text-xs font-semibold text-slate-800 dark:text-slate-100">Vídeo hospedado em site externo</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Esse provedor não permite uma prévia segura aqui. Abra o vídeo na origem.</p>
                            <a href="<?= htmlspecialchars((string)$externalVideo['url']) ?>" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex rounded bg-slate-900 px-3 py-1.5 text-[10px] font-semibold text-white dark:bg-white dark:text-slate-900">Abrir vídeo externo</a>
                        </div>
                    <?php endif; ?>

                <?php elseif ($contentType === 'code'): ?>
                    <?php $codeLanguage = $doc['code_language'] ?: 'auto'; ?>
                    <div class="code-snippet" data-code-snippet data-code-language="<?= htmlspecialchars($codeLanguage) ?>">
                        <div class="code-snippet__header">
                            <span class="code-snippet__language" data-code-language-label>
                                <?= $codeLanguage === 'auto' ? 'Detectando...' : htmlspecialchars(strtoupper($codeLanguage)) ?>
                            </span>
                            <button type="button" class="code-snippet__copy" data-copy-code aria-label="Copiar código" title="Copiar código">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h10a2 2 0 012 2v10a2 2 0 01-2 2H8a2 2 0 01-2-2V10a2 2 0 012-2zM16 8V4a2 2 0 00-2-2H4a2 2 0 00-2 2v10a2 2 0 002 2h2"/></svg>
                                <span data-copy-label>Copiar</span>
                            </button>
                        </div>
                        <pre><code data-code-source><?= htmlspecialchars($doc['text_content'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></pre>
                    </div>

                <?php elseif ($contentType === 'text'): ?>
                    <div class="prose max-w-none text-slate-800 dark:text-slate-200 text-sm leading-relaxed">
                        <?= $doc['text_content'] ?: '<p>Nenhum conteúdo textual fornecido.</p>' ?>
                    </div>

                <?php elseif ($contentType === 'link'): ?>
                    <div class="p-6 rounded-md bg-slate-50 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956] text-center">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mb-4">Este documento consiste em um link externo oficial.</p>
                        <a href="<?= htmlspecialchars($doc['external_url']) ?>" target="_blank" rel="noopener noreferrer" 
                           class="inline-flex items-center gap-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold px-4 py-2 rounded-md text-xs hover:bg-slate-800 transition">
                            <span>Acessar Link Externo</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        </a>
                    </div>

                <?php else: ?>
                    <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33]">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 dark:border-[#454956] p-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded bg-slate-200 dark:bg-[#353842] flex items-center justify-center font-bold text-sm text-slate-700 dark:text-slate-200">
                                    <svg class="w-5 h-5 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-900 dark:text-slate-100">
                                        <?= htmlspecialchars($doc['original_filename'] ?: 'Arquivo Anexo') ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400">
                                        <?= viewerFormatSize((int)$doc['file_size']) ?> &bull; <?= htmlspecialchars($doc['mime_type'] ?: 'Formato Padrão') ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="<?= htmlspecialchars($streamUrl) ?>" target="_blank" rel="noopener" class="rounded border border-slate-300 dark:border-[#454956] px-3 py-1.5 text-[10px] font-semibold text-slate-700 dark:text-slate-200">Abrir no navegador</a>
                                <a href="<?= $downloadUrl ?>" class="rounded bg-slate-900 dark:bg-white px-3 py-1.5 text-[10px] font-semibold text-white dark:text-slate-900">Baixar arquivo</a>
                            </div>
                        </div>
                        <object data="<?= htmlspecialchars($streamUrl) ?>" type="<?= htmlspecialchars($doc['mime_type'] ?: 'application/octet-stream') ?>" class="h-[65vh] min-h-96 w-full bg-white" aria-label="Prévia de <?= htmlspecialchars($doc['title']) ?>">
                            <div class="p-8 text-center text-xs text-slate-500">
                                Este navegador não possui um renderizador nativo para o formato <?= htmlspecialchars(strtoupper($fileExt ?: 'do arquivo')) ?>.
                            </div>
                        </object>
                        <div class="border-t border-slate-200 dark:border-[#454956] px-3 py-2 text-[10px] text-slate-400">
                            Se a prévia nativa não aparecer, use “Abrir no navegador” ou baixe o arquivo original.
                        </div>
                    </div>
                <?php endif; ?>

            </div>

                </section>
            </div>

        </main>
    </div>

    <script>
        function toggleFavorito(docId) {
            const btn = document.getElementById('btn-fav');
            if (btn) btn.disabled = true;
            fetch('api_user.php?action=toggle_favorito&doc_id=' + docId)
                .then(r => r.json())
                .then(data => {
                    if (btn) btn.disabled = false;
                    if (data.success) {
                        const iconContainer = document.getElementById('fav-icon');
                        const text = document.getElementById('fav-text');
                        if (data.is_favorite) {
                            if (btn) {
                                btn.setAttribute('aria-label', 'Remover dos favoritos');
                                btn.setAttribute('title', 'Remover dos favoritos');
                            }
                            iconContainer.innerHTML = '<svg class="w-4 h-4 text-amber-500 fill-amber-500 stroke-amber-500" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>';
                            text.textContent = 'Favoritado';
                        } else {
                            if (btn) {
                                btn.setAttribute('aria-label', 'Adicionar aos favoritos');
                                btn.setAttribute('title', 'Adicionar aos favoritos');
                            }
                            iconContainer.innerHTML = '<svg class="w-4 h-4 text-slate-400 fill-none stroke-currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>';
                            text.textContent = 'Favoritar';
                        }
                    } else {
                        alert('Não foi possível atualizar o favorito.');
                    }
                })
                .catch(() => {
                    if (btn) btn.disabled = false;
                    alert('Não foi possível atualizar o favorito.');
                });
        }
    </script>
</body>
</html>
