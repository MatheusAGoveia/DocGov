<?php
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../services/UploadRequestService.php';

function uploadAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
foreach (['8M' => 8388608, '50M' => 52428800, '512M' => 536870912, '1G' => 1073741824, '1024' => 1024, '0' => 0] as $value => $expected) {
    uploadAssert(UploadRequestService::bytes((string)$value) === $expected, 'Conversão incorreta: ' . $value);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$limit = UploadRequestService::limits()['post'];
$_SERVER['CONTENT_LENGTH'] = $limit;
uploadAssert(UploadRequestService::requestError() === null, 'O limite exato deveria ser aceito.');
$_SERVER['CONTENT_LENGTH'] = $limit + 1;
uploadAssert(($limit === 0) === (UploadRequestService::requestError() === null), 'POST excedido não foi detectado.');
uploadAssert(UploadRequestService::filesError([['error' => UPLOAD_ERR_INI_SIZE]]) !== null, 'Limite por arquivo não foi tratado.');
uploadAssert(UploadRequestService::filesError([['error' => [UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL]]]) !== null, 'Envio parcial no lote não foi tratado.');
uploadAssert(UploadRequestService::filesError([['error' => UPLOAD_ERR_NO_FILE]]) === null, 'Arquivo opcional bloqueado.');
echo "OK: limites de POST e erros de upload.\n";
