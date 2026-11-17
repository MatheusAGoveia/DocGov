<?php
/** Despacho estatico na ordem original; os includes compartilham o contexto de index.php. */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/modules/structure/actions/permanent-delete.php';
require __DIR__ . '/modules/structure/actions/archive.php';
require __DIR__ . '/modules/subject-workspaces/actions/transition.php';
require __DIR__ . '/modules/subject-workspaces/actions/save.php';
require __DIR__ . '/modules/authentication/actions/test-connection.php';
require __DIR__ . '/modules/users/actions/replicate.php';
require __DIR__ . '/modules/settings/actions/save.php';
require __DIR__ . '/modules/authentication/actions/test-connection-legacy.php';
require __DIR__ . '/modules/authentication/actions/health.php';
require __DIR__ . '/modules/users/actions/details.php';
require __DIR__ . '/modules/audit/actions/export.php';
require __DIR__ . '/modules/users/actions/replicate-legacy.php';
require __DIR__ . '/modules/authentication/actions/delete-domain.php';
require __DIR__ . '/modules/authentication/actions/create-domain.php';
require __DIR__ . '/modules/authentication/actions/save-settings.php';
require __DIR__ . '/modules/tags/actions/manage.php';
require __DIR__ . '/modules/users/actions/create.php';
require __DIR__ . '/modules/users/actions/grant-permission.php';
require __DIR__ . '/modules/users/actions/revoke-permission.php';
require __DIR__ . '/modules/groups/actions/manage.php';
require __DIR__ . '/modules/documents/actions/batch-upload.php';
require __DIR__ . '/modules/documents/actions/save.php';
require __DIR__ . '/modules/documents/actions/recover-submission.php';
require __DIR__ . '/modules/documents/actions/transition.php';
require __DIR__ . '/modules/documents/actions/trash.php';
require __DIR__ . '/modules/documents/actions/batch.php';
require __DIR__ . '/modules/structure/actions/save-category.php';
require __DIR__ . '/modules/structure/actions/save-subcategory.php';
require __DIR__ . '/modules/structure/actions/save-subject.php';
require __DIR__ . '/modules/integrations/actions/manage.php';
