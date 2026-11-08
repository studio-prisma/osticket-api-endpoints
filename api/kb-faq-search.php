<?php
/**
 * API File: Knowledge Base Article Search
 *
 * Searches FAQ articles by text, category, visibility or slug.
 *
 * This file should be deployed to: /api/kb-faq-search.php
 * URL pattern: /api/kb-faq-search.php[.json]?query=&categoryId=&visibility=&slug=&limit=&offset=
 *
 * Requires the can_read_kb permission. Nothing is ever deleted.
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse optional format from path info
$bootstrap->parsePathInfo('#^(?:\.(?P<format>json|xml))?$#');

$bootstrap->requireMethod('GET');

$params = $bootstrap->parseQueryParams([
    'query' => null,
    'categoryId' => null,
    'visibility' => null,
    'slug' => null,
    'limit' => null,
    'offset' => null,
]);

$bootstrap->execute(function() use ($bootstrap, $params) {
    return $bootstrap->getController()->searchKbArticles($params);
});
