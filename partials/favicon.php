<?php
// Usa a mesma logo configurável do cabeçalho. A URL versionada muda ao trocar a imagem.
$faviconPrefix = $faviconPrefix ?? '';
if (!empty($appLogoUrl)):
    $faviconHref = $faviconPrefix . $appLogoUrl;
?>
<link rel="icon" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8') ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
