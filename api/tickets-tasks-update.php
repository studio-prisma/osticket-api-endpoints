<?php
/**
 * API File: Task Update Endpoint
 *
 * Provides PATCH/PUT endpoint for changing a task: title, ticket link,
 * due date, department, assignment, status, internal note and update.
 * The changes are made as the "Tasks as Agent".
 *
 * This file should be deployed to: /api/tickets-tasks-update.php
 * URL pattern: /api/tickets-tasks-update.php/{task_number}.json
 *
 * Requires the can_update_tasks permission and the "Tasks as Agent"
 * plugin setting.
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
    'Invalid URL format. Expected: /api/tickets-tasks-update.php/{task_number}.json'
);

// Only accept PATCH or PUT methods
$bootstrap->requireMethod(['PATCH', 'PUT']);

// Parse JSON body
$data = $bootstrap->parseJsonBody(required: true);

// Execute with standardized error handling
$bootstrap->execute(function() use ($bootstrap, $params, $data) {
    return $bootstrap->getController()->updateTask($params['number'], $data);
});
