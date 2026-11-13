<?php
/**
 * audit/actions/export.php
 * Entradas: $pdo, $canManageAuthentication.
 * Estado compartilhado: resposta HTTP/efeitos da acao.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// EXPORTAR RELATÓRIO DE AUDITORIA AD EM CSV
if (isset($_GET['export_ad_audit_logs'])) {
    if (!$canManageAuthentication) {
        http_response_code(403);
        exit('Acesso restrito.');
    }

    $stmtExport = $pdo->query("
        SELECT created_at, domain_key, username, server_name, server_uri, status, status_message, latency_ms, user_ip
        FROM ad_auth_logs
        ORDER BY created_at DESC
        LIMIT 5000
    ");
    $logs = $stmtExport ? $stmtExport->fetchAll(PDO::FETCH_ASSOC) : [];

    $filename = 'relatorio_auditoria_ad_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, ['Data / Hora', 'Domínio', 'Usuário', 'Nome do Servidor', 'URI do Servidor', 'Status', 'Mensagem', 'Latência (ms)', 'IP de Origem'], ';');

    foreach ($logs as $row) {
        $statusText = match((string)$row['status']) {
            'success' => 'Sucesso',
            'invalid_credentials' => 'Senha Incorreta',
            'account_disabled' => 'Conta Desativada no AD',
            'password_expired' => 'Senha Expirada / Alteração Obrigatória',
            'server_unreachable' => 'Servidor Inacessível',
            default => (string)$row['status']
        };

        fputcsv($out, [
            $row['created_at'],
            $row['domain_key'],
            $row['username'],
            $row['server_name'],
            $row['server_uri'],
            $statusText,
            $row['status_message'],
            $row['latency_ms'],
            $row['user_ip']
        ], ';');
    }
    fclose($out);
    exit;
}
