<?php
/**
 * API File: Knowledge Base Category Update
 *
 * Changes name, description, notes, parent or visibility of an FAQ category.
 *
 * This file should be deployed to: /api/kb-categories-update.php
 * URL pattern: /api/kb-categories-update.php/{id}.json
 *
 * Requires the can_write_kb permission. Nothing is ever deleted.
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse id and format from path info
$params = $bootstrap->parsePathInfo(
    '#^/(?P<id>[0-9]{1,10})\.(?P<format>json|xml)$#',
    'Invalid URL format. Expected: /api/kb-categories-update.php/{id}.json'
);

$bootstrap->requireMethod(['PATCH', 'PUT']);

$data = $bootstrap->parseJsonBody(required: true);

$bootstrap->execute(function() use ($bootstrap, $params, $data) {
    return $bootstrap->getController()->updateKbCategory($params['id'], $data);
});
