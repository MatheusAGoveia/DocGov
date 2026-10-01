<?php
require_once __DIR__ . '/_cli_only.php';
if (PHP_SAPI !== 'cli' || !str_starts_with((string)getenv('DB_NAME'), 'docgov_nesting_test_')) exit(2);
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/GroupMembershipService.php';
try {
    $pdo->beginTransaction();
    if (($argv[4] ?? '') === 'raw') {
        $pdo->prepare('INSERT INTO group_memberships (parent_group_id, child_group_id) VALUES (?, ?)')->execute([(int)$argv[1], (int)$argv[2]]);
    } else {
        (new GroupMembershipService($pdo))->changeChild((int)$argv[1], (int)$argv[2], (int)$argv[3], true);
    }
    echo "INSERTED\n"; flush();
    $pdo->query('SELECT pg_sleep(0.6)');
    $pdo->commit();
    echo "COMMITTED\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "REJECTED\n";
    exit(1);
}
