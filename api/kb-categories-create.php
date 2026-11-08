<?php
/**
 * API File: Knowledge Base Category Create
 *
 * Creates an FAQ category (internal unless visibility is set).
 *
 * This file should be deployed to: /api/kb-categories-create.php
 * URL pattern: /api/kb-categories-create.php[.json]
 *
 * Requires the can_write_kb permission. Nothing is ever deleted.
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse optional format from path info
$bootstrap->parsePathInfo('#^(?:\.(?P<format>json|xml))?$#');

// Only accept POST: every call creates a new category
$bootstrap->requireMethod('POST');

$data = $bootstrap->parseJsonBody(required: true);

$bootstrap->execute(function() use ($bootstrap, $data) {
    return $bootstrap->getController()->createKbCategory($data);
}, 201);
