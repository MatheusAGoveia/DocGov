<?php
// config/db.php - Conexão Centralizada PDO PostgreSQL (DocGov)

// Diretório Protegido de Uploads
$uploadsDocsDir = __DIR__ . '/../storage/documents';
if (!is_dir($uploadsDocsDir)) {
    mkdir($uploadsDocsDir, 0755, true);
}

// Configurações do Banco via Ambiente com Fallbacks
$dbHost = getenv('DB_HOST') ?: (getenv('DB_HOSTNAME') ?: '127.0.0.1');
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'docsec');
$dbUser = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'postgres');
$dbPass = getenv('DB_PASS') ?: (getenv('DB_PASSWORD') ?: '2603');

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("Erro de Conexão com o PostgreSQL: " . $e->getMessage());
    die("Não foi possível conectar ao banco de dados PostgreSQL. Verifique os serviços e credenciais do servidor.");
}

/**
 * Funçao Auxiliar para Gerar Slugs Limpos de URLs Amigáveis
 */
if (!function_exists('slugify')) {
    function slugify($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        if (function_exists('iconv')) {
            $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        }
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        return empty($text) ? 'n-a' : $text;
    }
}
