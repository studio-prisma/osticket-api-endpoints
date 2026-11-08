<?php
/**
 * API File: Knowledge Base Article Update
 *
 * Changes title, answer, category, notes or visibility of an FAQ article.
 * Fields left out keep their value, help topics stay linked.
 *
 * This file should be deployed to: /api/kb-faq-update.php
 * URL pattern: /api/kb-faq-update.php/{id}.json
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
    'Invalid URL format. Expected: /api/kb-faq-update.php/{id}.json'
);

$bootstrap->requireMethod(['PATCH', 'PUT']);

$data = $bootstrap->parseJsonBody(required: true);

$bootstrap->execute(function() use ($bootstrap, $params, $data) {
    return $bootstrap->getController()->updateKbArticle($params['id'], $data);
});
