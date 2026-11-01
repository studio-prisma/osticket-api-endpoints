<?php
/**
 * API File: Task Get Endpoint
 *
 * Provides GET endpoint for a single task with its thread
 * (description, internal notes, updates).
 *
 * This file should be deployed to: /api/tickets-tasks-get.php
 * URL pattern: /api/tickets-tasks-get.php/{task_number}.json
 *
 * Requires the can_read_tasks permission.
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse task number and format from path info
$params = $bootstrap->parsePathInfo(
    '#^/(?P<number>[^/.]+)\.(?P<format>json|xml)$#',
    'Invalid URL format. Expected: /api/tickets-tasks-get.php/{task_number}.json'
);

// Only accept GET method
$bootstrap->requireMethod('GET');

// Execute with standardized error handling
$bootstrap->execute(function() use ($bootstrap, $params) {
    return $bootstrap->getController()->getTask($params['number']);
});
