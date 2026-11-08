<?php
/**
 * API File: Knowledge Base Category List
 *
 * Lists all FAQ categories with parent, visibility and article count.
 *
 * This file should be deployed to: /api/kb-categories-list.php
 * URL pattern: /api/kb-categories-list.php[.json]
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

$bootstrap->execute(function() use ($bootstrap) {
    return $bootstrap->getController()->listKbCategories();
});
