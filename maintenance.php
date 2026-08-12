<?php
require_once __DIR__ . '/config/session.php';
docgovStartSession();
require_once __DIR__ . '/config/db.php';

$maintenance = $systemSettingsService->maintenanceStatus();
if (!$maintenance['enabled'] || $maintenance['expired']) {
    header('Location: index.php');
    exit;
}

$refreshSeconds = max(0, (int)($appSettings['maintenance_auto_refresh_seconds'] ?? 0));
http_response_code($maintenance['active'] ? 503 : 200);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <?php if ($refreshSeconds > 0): ?><meta http-equiv="refresh" content="<?= $refreshSeconds ?>"><?php endif; ?>
    <title>Estamos em manutenção — <?= htmlspecialchars($appName) ?></title>
    <style>
        :root { color-scheme: light dark; --maintenance-canvas: #f8f8f6; --maintenance-text: #242424; --maintenance-muted: #6d6d68; --maintenance-border: #deded9; --maintenance-surface: #fff; --maintenance-accent: #0f8f6f; }
        html[data-portal-theme="blue"] { --maintenance-canvas: #f5f8ff; --maintenance-text: #172554; --maintenance-muted: #50617f; --maintenance-border: #d8e4fb; --maintenance-accent: #2563eb; }
        html[data-portal-theme="indigo"] { --maintenance-canvas: #f7f7ff; --maintenance-text: #24235f; --maintenance-muted: #5c5b83; --maintenance-border: #deddf8; --maintenance-accent: #4f46e5; }
        html[data-portal-theme="violet"] { --maintenance-canvas: #faf7fe; --maintenance-text: #3b245d; --maintenance-muted: #705b88; --maintenance-border: #eadcf7; --maintenance-accent: #7c3aed; }
        html[data-portal-theme="rose"] { --maintenance-canvas: #fff7f8; --maintenance-text: #61152b; --maintenance-muted: #8a5966; --maintenance-border: #f6dbe2; --maintenance-accent: #be123c; }
        html[data-portal-theme="amber"] { --maintenance-canvas: #fffbf4; --maintenance-text: #5c3106; --maintenance-muted: #826644; --maintenance-border: #f2e3c7; --maintenance-accent: #b45309; }
        html[data-portal-theme="ocean"] { --maintenance-canvas: #f4fbfc; --maintenance-text: #123f4d; --maintenance-muted: #53717a; --maintenance-border: #d4eaee; --maintenance-accent: #0e7490; }
        html[data-portal-theme="graphite"] { --maintenance-canvas: #f8f8f8; --maintenance-text: #27272a; --maintenance-muted: #66666b; --maintenance-border: #dfdfe2; --maintenance-accent: #3f3f46; }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            background: var(--maintenance-canvas);
            color: var(--maintenance-text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main { width: min(100% - 40px, 540px); text-align: center; }
        .mark { width: 148px; height: 148px; margin: 0 auto 28px; overflow: visible; }
        .brand-logo { width: 32px; height: 32px; margin: 0 auto 12px; border: 1px solid var(--maintenance-border); border-radius: 9px; background: var(--maintenance-surface); object-fit: contain; padding: 2px; }
        .orbit { transform-origin: 74px 74px; animation: orbit 10s linear infinite; }
        .orbit-reverse { transform-origin: 74px 74px; animation: orbit 7s linear infinite reverse; }
        .core { transform-origin: 74px 74px; animation: breathe 2.8s ease-in-out infinite; }
        .spark { animation: flash 2.8s ease-in-out infinite; }
        .spark-delay { animation: flash 2.8s ease-in-out 1.4s infinite; }
        .eyebrow { margin: 0 0 10px; color: var(--maintenance-muted); font-size: .75rem; font-weight: 700; letter-spacing: .11em; text-transform: uppercase; }
        h1 { margin: 0; color: inherit; font-size: clamp(1.9rem, 5vw, 2.65rem); letter-spacing: -.045em; line-height: 1.08; }
        p.message { max-width: 440px; margin: 17px auto 0; color: var(--maintenance-muted); font-size: 1rem; line-height: 1.65; }
        .status { display: inline-flex; align-items: center; gap: 8px; margin-top: 26px; color: var(--maintenance-muted); font-size: .78rem; }
        .status::before { width: 7px; height: 7px; border-radius: 50%; background: var(--maintenance-accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--maintenance-accent) 14%, transparent); content: ""; animation: breathe 2.8s ease-in-out infinite; }
        .mark [fill="#f5a524"] { fill: var(--maintenance-accent); }
        .mark [stroke="#f5a524"] { stroke: var(--maintenance-accent); }
        @keyframes orbit { to { transform: rotate(360deg); } }
        @keyframes breathe { 50% { transform: scale(.88); opacity: .72; } }
        @keyframes flash { 50% { opacity: .25; } }
        @media (prefers-color-scheme: dark) {
            body { background: #1b1b1a; color: #f3f3ef; }
            .brand-logo { border-color: #454541; background: #252524; }
            .eyebrow, .status { color: #aaa9a3; }
            p.message { color: #c5c4be; }
        }
        @media (prefers-reduced-motion: reduce) {
            .orbit, .orbit-reverse, .core, .spark, .spark-delay, .status::before { animation: none; }
        }
    </style>
</head>
<body>
    <main>
        <svg class="mark" viewBox="0 0 148 148" aria-hidden="true">
            <circle cx="74" cy="74" r="57" fill="none" stroke="#f5a524" stroke-opacity=".18" stroke-width="1.5" stroke-dasharray="3 8"/>
            <g class="orbit">
                <circle cx="74" cy="17" r="4" fill="#f5a524"/>
                <circle cx="17" cy="74" r="2.5" fill="#f5a524" fill-opacity=".55"/>
            </g>
            <g class="orbit-reverse">
                <circle cx="131" cy="74" r="3" fill="#f5a524" fill-opacity=".7"/>
            </g>
            <g class="core">
                <circle cx="74" cy="74" r="37" fill="#f5a524" fill-opacity=".12"/>
                <path d="M89.5 57.5a15.2 15.2 0 0 0-19.1 18.8L53.7 93a6.2 6.2 0 1 0 8.8 8.8l16.7-16.7a15.2 15.2 0 0 0 18.8-19.1l-9.8 9.8-7.4-1.8-1.8-7.4 10.5-9.1Z" fill="#f5a524"/>
            </g>
            <circle class="spark" cx="112" cy="37" r="2" fill="#f5a524"/>
            <circle class="spark-delay" cx="35" cy="111" r="2" fill="#f5a524"/>
        </svg>
        <?php if ($appLogoUrl): ?><img class="brand-logo" src="<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($appName) ?>"><?php endif; ?>
        <p class="eyebrow"><?= htmlspecialchars($appName) ?></p>
        <h1>Estamos em manutenção</h1>
        <p class="message"><?= nl2br(htmlspecialchars((string)($appSettings['maintenance_message'] ?? 'Estamos realizando melhorias. Voltamos em breve.'))) ?></p>
        <span class="status">Trabalhando para voltar o quanto antes</span>
    </main>
</body>
</html>
