<?php
/**
 * API File: Task Delete Endpoint
 *
 * Provides DELETE endpoint for deleting a task with its thread.
 * The task is deleted as the "Tasks as Agent".
 *
 * This file should be deployed to: /api/tickets-tasks-delete.php
 * URL pattern: /api/tickets-tasks-delete.php/{task_number}.json
 * Optional JSON body: {"comments": "reason"}
 *
 * Requires the can_delete_tasks permission and the "Tasks as Agent"
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
    'Invalid URL format. Expected: /api/tickets-tasks-delete.php/{task_number}.json'
);

// Only accept DELETE method
$bootstrap->requireMethod('DELETE');

// Optional body with a reason for the delete log
$data = $bootstrap->parseJsonBody(required: false);
$comments = isset($data['comments']) && is_string($data['comments']) ? $data['comments'] : null;

// Execute with standardized error handling
$bootstrap->execute(function() use ($bootstrap, $params, $comments) {
    return $bootstrap->getController()->deleteTask($params['number'], $comments);
});
