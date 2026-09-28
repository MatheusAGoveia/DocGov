<?php
// config/db.php - Conexão Centralizada PDO PostgreSQL (DocGov)

// Diretório Protegido de Uploads
$uploadsDocsDir = __DIR__ . '/../storage/documents';
if (!is_dir($uploadsDocsDir)) {
    mkdir($uploadsDocsDir, 0755, true);
}

// Segredos nunca possuem fallback versionado. Em desenvolvimento, valores podem
// ficar em config/local.php (ignorado pelo Git); em produção, use variáveis de
// ambiente do serviço web.
$localConfigPath = __DIR__ . '/local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($localConfig)) {
    $localConfig = [];
}

$configValue = static function (array $environmentNames, string $localKey, mixed $default = null) use ($localConfig): mixed {
    foreach ($environmentNames as $environmentName) {
        $value = getenv($environmentName);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return array_key_exists($localKey, $localConfig) ? $localConfig[$localKey] : $default;
};

$appEnvironment = strtolower(trim((string)$configValue(['APP_ENV'], 'app_environment', 'development')));
$dbHost = (string)$configValue(['DB_HOST', 'DB_HOSTNAME'], 'db_host', '127.0.0.1');
$dbPort = (string)$configValue(['DB_PORT'], 'db_port', '5432');
$dbName = (string)$configValue(['DB_NAME', 'DB_DATABASE'], 'db_name', 'docsec');
$dbUser = (string)$configValue(['DB_USER', 'DB_USERNAME'], 'db_user', 'postgres');
$dbPass = $configValue(['DB_PASS', 'DB_PASSWORD'], 'db_password');

if ($dbPass === null) {
    error_log('DocGov: DB_PASS não foi configurada no ambiente do servidor.');
    http_response_code(500);
    die('A configuração segura do banco de dados está incompleta. Contate a infraestrutura.');
}

if ($appEnvironment === 'production') {
    $requiredProductionValues = [
        'DB_HOST' => $dbHost,
        'DB_NAME' => $dbName,
        'DB_USER' => $dbUser,
        'DB_PASS' => (string)$dbPass,
    ];
    foreach ($requiredProductionValues as $setting => $value) {
        if (trim($value) === '') {
            error_log("DocGov: {$setting} obrigatório não foi configurado para produção.");
            http_response_code(500);
            die('A configuração segura do banco de dados está incompleta. Contate a infraestrutura.');
        }
    }
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, (string)$dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("Erro de Conexão com o PostgreSQL: " . $e->getMessage());
    die("Não foi possível conectar ao banco de dados PostgreSQL. Verifique os serviços e credenciais do servidor.");
}

// Políticas e identidade globais. O executor de migrações desliga este bloco
// enquanto a própria tabela de configurações ainda pode não existir.
if (!defined('DOCGOV_SKIP_APP_RUNTIME') || DOCGOV_SKIP_APP_RUNTIME !== true) {
    require_once __DIR__ . '/app_runtime.php';
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
