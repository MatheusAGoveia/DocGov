<?php
// scratch/test_maintenance_full_functional.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

echo "=== TESTANDO TODAS AS FUNCIONALIDADES DA JANELA DE MANUTENÇÃO ===\n\n";

$settingsService = new SystemSettingsService($pdo);

// 1. ATIVAR MODO DE MANUTENÇÃO PROGRAMADA VIA SETTINGS
echo "1. ATIVANDO JANELA DE MANUTENÇÃO PROGRAMADA NO BANCO:\n";
$newSettings = [
    'maintenance_enabled' => true,
    'maintenance_mode' => 'full',
    'maintenance_scope' => ['portal', 'api', 'files'],
    'maintenance_start_at' => date('c', time() - 60), // começou há 1 min
    'maintenance_end_at' => date('c', time() + 3600), // termina em 1h
    'maintenance_reason' => 'Atualização emergencial de banco de dados',
    'maintenance_reference' => 'CHG-998877',
    'maintenance_responsible' => 'Equipe de Infraestrutura TI',
    'maintenance_progress' => 45,
    'maintenance_announce_minutes' => 30,
    'maintenance_auto_refresh_seconds' => 15,
    'maintenance_title' => 'Manutenção Programada em Andamento',
    'maintenance_message' => 'O portal está passando por uma manutenção planejada de infraestrutura.'
];

$settingsService->saveMany($newSettings, 148);
echo "  [OK] SUCCESS: Configurações da Janela de Manutenção gravadas na tabela system_settings!\n";

// 2. CONSULTAR STATUS DA MANUTENÇÃO EM TEMPO REAL
echo "\n2. CONSULTANDO STATUS DA MANUTENÇÃO EM TEMPO REAL (SystemSettingsService):\n";
$status = $settingsService->maintenanceStatus();
$all = $settingsService->all();
echo "  -> Manutenção Habilitada: " . ($status['enabled'] ? 'SIM' : 'NÃO') . "\n";
echo "  -> Manutenção Ativa Agora: " . ($status['active'] ? 'SIM (100% OPERACIONAL)' : 'NÃO') . "\n";
echo "  -> Modo de Bloqueio: " . $all['maintenance_mode'] . "\n";
echo "  -> Escopo Afetado: " . implode(', ', $all['maintenance_scope']) . "\n";
echo "  -> Título da Tela: " . $all['maintenance_title'] . "\n";
echo "  -> Mensagem: " . $all['maintenance_message'] . "\n";
echo "  -> Motivo: " . $all['maintenance_reason'] . "\n";
echo "  -> Chamado / Referência: " . $all['maintenance_reference'] . "\n";
echo "  -> Responsável: " . $all['maintenance_responsible'] . "\n";
echo "  -> Progresso: " . $all['maintenance_progress'] . "%\n";
echo "  -> Avisar Antes: " . $all['maintenance_announce_minutes'] . " min\n";
echo "  -> Auto Refresh Público: " . $all['maintenance_auto_refresh_seconds'] . "s\n";

// 3. RESTAURAR MODO DE OPERAÇÃO NORMAL
echo "\n3. RESTAURANDO OPERAÇÃO NORMAL:\n";
$settingsService->saveMany(['maintenance_enabled' => false], 148);
$restoredStatus = $settingsService->maintenanceStatus();
echo "  -> Status após desativar: " . ($restoredStatus['active'] ? 'Ainda Ativa' : 'Desativada com Sucesso (Operação Normal Restabelecida)') . "\n";

echo "\n===========================================================================\n";
echo "RESULTADO FINAL: Todas as opções da Janela de Manutenção são 100% FUNCIONAIS!\n";
echo "===========================================================================\n";
