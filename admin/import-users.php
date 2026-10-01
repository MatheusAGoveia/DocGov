<?php

require_once __DIR__ . '/../config/session.php';
docgovStartSession();
require_once __DIR__ . '/../services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../config/db.php';

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};
if (empty($_SESSION['user']['id'])) {
    $respond(401, ['success' => false, 'error' => 'Entre novamente no sistema para continuar.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'error' => 'Método não permitido.']);
}
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!CsrfService::isValid(is_string($token) ? $token : null)) {
    $respond(419, ['success' => false, 'error' => 'A sessão de segurança expirou. Atualize a página.']);
}
$actorId = (int)$_SESSION['user']['id'];
session_write_close(); // Consulta LDAP não bloqueia a navegação nem mantém o lock da sessão.
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/BatchUserImportService.php';

try {
    $mode = $_POST['mode'] ?? null;
    $domainKey = $_POST['domain'] ?? null;
    $rawEntries = $_POST['entries'] ?? null;
    if (!in_array($mode, ['directory', 'group'], true) || !is_string($domainKey) || !is_string($rawEntries) || strlen($rawEntries) > 30000) {
        throw new InvalidArgumentException('Dados de importação inválidos.');
    }
    $groupId = null;
    if ($mode === 'group') {
        $groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($groupId === false || $groupId === null) {
            throw new InvalidArgumentException('Selecione uma equipe válida.');
        }
    }
    $entries = json_decode($rawEntries, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($entries)) {
        throw new InvalidArgumentException('A lista de usuários é inválida.');
    }
    $config = require __DIR__ . '/../config/active_directory.php';
    $service = new BatchUserImportService($pdo, new ActiveDirectoryAuthService($pdo, $config), $config);
    $respond(200, $service->process($entries, $domainKey, $actorId, $groupId));
} catch (InvalidArgumentException | JsonException $error) {
    $respond(422, ['success' => false, 'error' => $error->getMessage()]);
} catch (RuntimeException $error) {
    if ($error->getCode() === 403) {
        $respond(403, ['success' => false, 'error' => $error->getMessage()]);
    }
    error_log('DocGov API importação em lote: ' . $error->getMessage());
    $respond(500, ['success' => false, 'error' => 'Não foi possível processar esta etapa. Tente novamente.']);
} catch (Throwable $error) {
    error_log('DocGov API importação em lote: ' . $error->getMessage());
    $respond(500, ['success' => false, 'error' => 'Não foi possível processar esta etapa. Tente novamente.']);
}
