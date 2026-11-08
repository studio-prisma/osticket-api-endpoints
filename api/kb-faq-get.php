<?php
/**
 * API File: Knowledge Base Article Get
 *
 * Returns one FAQ article with answer (HTML), notes, help topics and slug.
 *
 * This file should be deployed to: /api/kb-faq-get.php
 * URL pattern: /api/kb-faq-get.php/{id}.json
 *
 * Requires the can_read_kb permission. Nothing is ever deleted.
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
    'Invalid URL format. Expected: /api/kb-faq-get.php/{id}.json'
);

$bootstrap->requireMethod('GET');

$bootstrap->execute(function() use ($bootstrap, $params) {
    return $bootstrap->getController()->getKbArticle($params['id']);
});
