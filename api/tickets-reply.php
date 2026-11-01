<?php
/**
 * API File: Ticket Reply Endpoint
 *
 * Provides POST endpoint for posting a public reply to the customer.
 * The reply is emailed to the recipients unless "alert" is false.
 *
 * This file should be deployed to: /api/tickets-reply.php
 * URL pattern: /api/tickets-reply.php/{ticket_number}.json
 *
 * Requires the can_reply_tickets permission and the "Reply as Agent"
 * plugin setting.
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse ticket number and format from path info
$params = $bootstrap->parsePathInfo(
    '#^/(?P<number>[^/.]+)\.(?P<format>json|xml)$#',
    'Invalid URL format. Expected: /api/tickets-reply.php/{ticket_number}.json'
);

// Only accept POST: every call creates a new thread entry
$bootstrap->requireMethod('POST');

// Parse JSON body
$data = $bootstrap->parseJsonBody(required: true);

// Execute with standardized error handling
$bootstrap->execute(function() use ($bootstrap, $params, $data) {
    return $bootstrap->getController()->reply($params['number'], $data);
});
