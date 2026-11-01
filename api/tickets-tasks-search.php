<?php
/**
 * API File: Task Search Endpoint
 *
 * Provides GET endpoint for listing and filtering tasks.
 *
 * This file should be deployed to: /api/tickets-tasks-search.php
 * URL pattern: /api/tickets-tasks-search.php?status=open&ticket=123456
 *
 * Query parameters: ticket, status (open|closed|all), departmentId, staffId,
 * teamId, unassigned, overdue, standalone, query, limit, offset, sort, order
 *
 * Requires the can_search_tasks permission (or can_read_tasks).
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse optional format from path info
$bootstrap->parsePathInfo('#^(?:\.(?P<format>json|xml))?$#');

// Only accept GET method
$bootstrap->requireMethod('GET');

// Parse query parameters with defaults
$params = $bootstrap->parseQueryParams([
    'ticket' => null,
    'status' => 'open',
    'departmentId' => null,
    'staffId' => null,
    'teamId' => null,
    'unassigned' => null,
    'overdue' => null,
    'standalone' => null,
    'query' => null,
    'limit' => 50,
    'offset' => 0,
    'sort' => 'created',
    'order' => 'desc',
]);

// Execute with standardized error handling
$bootstrap->execute(function() use ($bootstrap, $params) {
    return $bootstrap->getController()->searchTasks($params);
});
