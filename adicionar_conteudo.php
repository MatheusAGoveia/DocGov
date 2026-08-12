<?php
// adicionar_conteudo.php - Redirecionamento para o Módulo de Gestão Administrativa (admin/index.php)
require_once __DIR__ . '/config/session.php';
docgovStartSession();

$cat = trim($_GET['cat'] ?? '');
$subcat = trim($_GET['subcat'] ?? '');
$assunto = trim($_GET['assunto'] ?? '');

$redirectUrl = "admin/index.php?tab=novo_documento";
if (!empty($cat)) $redirectUrl .= "&cat=" . urlencode($cat);
if (!empty($subcat)) $redirectUrl .= "&subcat=" . urlencode($subcat);
if (!empty($assunto)) $redirectUrl .= "&assunto=" . urlencode($assunto);

header("Location: " . $redirectUrl);
exit;
