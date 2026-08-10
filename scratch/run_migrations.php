<?php
// scratch/run_migrations.php - Executa as migrações no banco PostgreSQL
require_once __DIR__ . '/../config/db.php';

echo "Executando migrações no PostgreSQL...\n";

$migrationFiles = [
    __DIR__ . '/../database/migrations/003_permissions_model.sql',
    __DIR__ . '/../database/migrations/004_permission_audit.sql'
];

foreach ($migrationFiles as $file) {
    if (file_exists($file)) {
        echo "Executando: " . basename($file) . " ... ";
        $sql = file_get_contents($file);
        try {
            $pdo->exec($sql);
            echo "✓ Sucesso!\n";
        } catch (Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
    }
}

echo "Migrações concluídas!\n";
