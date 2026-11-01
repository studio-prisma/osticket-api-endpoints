<?php
/**
 * API File: Task Create Endpoint
 *
 * Provides POST endpoint for creating a task, standalone or linked to a
 * ticket. The task is created as the "Tasks as Agent".
 *
 * This file should be deployed to: /api/tickets-tasks-create.php
 * URL pattern: /api/tickets-tasks-create.php
 *
 * Requires the can_create_tasks permission and the "Tasks as Agent"
 * plugin setting.
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse optional format from path info
$bootstrap->parsePathInfo('#^(?:\.(?P<format>json|xml))?$#');

// Only accept POST: every call creates a new task
$bootstrap->requireMethod('POST');

// Parse JSON body
$data = $bootstrap->parseJsonBody(required: true);

// Execute with standardized error handling
$bootstrap->execute(function() use ($bootstrap, $data) {
    return $bootstrap->getController()->createTask($data);
}, 201);
