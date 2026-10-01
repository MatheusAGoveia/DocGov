<?php
// O mesmo componente atende o portal e as páginas administrativas.
$dialogAssetsPrefix = defined('DOCGOV_ADMIN_DIALOG_ASSETS') ? '../assets/' : 'assets/';
$dialogAssetsRoot = __DIR__ . '/../assets/';
?>
<link rel="stylesheet" href="<?= $dialogAssetsPrefix ?>dialogs.css?v=<?= filemtime($dialogAssetsRoot . 'dialogs.css') ?>">
<script defer src="<?= $dialogAssetsPrefix ?>dialogs.js?v=<?= filemtime($dialogAssetsRoot . 'dialogs.js') ?>"></script>
