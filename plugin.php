<?php

return array(
    'id' =>             'com.osticket:api-endpoints',
    'version' =>        '2.4.1',
    'name' =>           'API Endpoints',
    'author' =>         'Markus Michalski',
    'description' =>    'Extends osTicket API with additional endpoints and parameters: ticket creation with Markdown support, department selection, and subticket functionality.',
    'url' =>            'https://github.com/studio-prisma/osticket-api-endpoints',
    'plugin' =>         'class.ApiEndpointsPlugin.php:ApiEndpointsPlugin',
    'config' =>         'config.php:ApiEndpointsConfig'
);
