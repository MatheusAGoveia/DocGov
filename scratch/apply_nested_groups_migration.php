<?php
require_once __DIR__ . '/_cli_only.php';
// Aplica exclusivamente a expansão de subgrupos, sem executar migrações antigas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
try {
    $pdo->beginTransaction();
    $pdo->exec("SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '15s'");
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/027_nested_groups.sql'));
    $pdo->commit();
    echo "OK: migração 027 aplicada de forma aditiva, sem reiniciar o sistema.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Migração não aplicada; transação revertida. " . $e->getMessage() . PHP_EOL);
    exit(1);
}
