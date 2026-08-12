<?php
// scratch/run_migrations.php - Executa as migrações no banco PostgreSQL
define('DOCGOV_SKIP_APP_RUNTIME', true);
require_once __DIR__ . '/../config/db.php';

echo "Executando migrações no PostgreSQL...\n";

$migrationFiles = [
    __DIR__ . '/../database/migrations/003_permissions_model.sql',
    __DIR__ . '/../database/migrations/004_active_directory_auth.sql',
    __DIR__ . '/../database/migrations/004_permission_audit.sql',
    __DIR__ . '/../database/migrations/005_code_documents.sql',
    __DIR__ . '/../database/migrations/006_video_documents.sql',
    __DIR__ . '/../database/migrations/007_category_images.sql',
    __DIR__ . '/../database/migrations/008_document_workflow_notifications.sql',
    __DIR__ . '/../database/migrations/009_subcategory_images.sql',
    __DIR__ . '/../database/migrations/010_usage_audit.sql',
    __DIR__ . '/../database/migrations/011_access_model_hardening.sql',
    __DIR__ . '/../database/migrations/012_active_directory_multi_domain.sql',
    __DIR__ . '/../database/migrations/013_system_settings.sql',
    __DIR__ . '/../database/migrations/014_maintenance_operations.sql',
    __DIR__ . '/../database/migrations/015_system_logo.sql',
    __DIR__ . '/../database/migrations/016_document_tags.sql',
    __DIR__ . '/../database/migrations/017_portal_theme.sql',
    __DIR__ . '/../database/migrations/018_document_approval_workflow.sql',
];

foreach ($migrationFiles as $file) {
    if (file_exists($file)) {
        echo "Executando: " . basename($file) . " ... ";
        $sql = file_get_contents($file);
        try {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            $pdo->commit();
            echo "✓ Sucesso!\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, "❌ Erro em " . basename($file) . ": " . $e->getMessage() . "\n");
            exit(1);
        }
    } else {
        fwrite(STDERR, "❌ Migração ausente: {$file}\n");
        exit(1);
    }
}

echo "Migrações concluídas!\n";
