<?php
require_once __DIR__ . '/../config/db.php';

// Criar tabela ad_auth_logs se não existir
$pdo->exec("
    CREATE TABLE IF NOT EXISTS ad_auth_logs (
        id SERIAL PRIMARY KEY,
        domain_key VARCHAR(50) NOT NULL,
        username VARCHAR(100) NOT NULL,
        server_uri VARCHAR(255) NULL,
        server_name VARCHAR(100) NULL,
        status VARCHAR(50) NOT NULL,
        status_message TEXT NULL,
        latency_ms INT DEFAULT 0,
        user_ip VARCHAR(50) NULL,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS idx_ad_auth_logs_created_at ON ad_auth_logs (created_at DESC);
");

// Adicionar colunas corporativas em users se não existirem
$columnsToAdd = [
    'department' => 'VARCHAR(150) NULL',
    'job_title' => 'VARCHAR(150) NULL',
    'phone' => 'VARCHAR(50) NULL',
];

foreach ($columnsToAdd as $col => $type) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$type}");
        echo "Coluna {$col} adicionada em users.\n";
    } catch (Throwable $e) {
        // Coluna já existe
    }
}

echo "Tabela ad_auth_logs e colunas de perfil verificadas com sucesso!\n";
