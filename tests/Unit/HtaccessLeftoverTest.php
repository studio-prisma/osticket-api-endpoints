<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../class.ApiEndpointsPlugin.php';

/**
 * Leftover rules in api/.htaccess
 *
 * Up to 2.7.0 removing the template block stopped at the subticket unlink
 * rule, so updates left the task rules behind. stripLeftoverRules() removes
 * them without touching osTicket's own rules.
 */
class HtaccessLeftoverTest extends TestCase {

    public function testRemovesPluginRulesAndTheirCommentsOnly(): void {
        $content = implode("\n", [
            '<IfModule mod_rewrite.c>',
            '',
            'RewriteEngine On',
            '',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteCond %{REQUEST_URI} (.*/api)',
            '',
            'RewriteRule ^(.*)$ %1/http.php/$1 [L]',
            '',
            '',
            '',
            '# Task Get API endpoint (path parameter: task number)',
            'RewriteRule ^tickets-tasks-get\.php/ - [L]',
            '',
            '# Task Search API endpoint (no path parameter - uses query params + optional .json in PATH_INFO)',
            'RewriteRule ^tickets-tasks-search\.php.*$ - [L]',
            'RewriteRule ^kb-faq-get\.php/ - [L]',
            '# my own note',
            'RewriteRule ^custom\.php - [L]',
            '',
            '</IfModule>',
            '',
        ]);

        $cleaned = ApiEndpointsPlugin::stripLeftoverRules($content, __DIR__ . '/../../htaccess.template');

        $this->assertStringNotContainsString('tickets-tasks', $cleaned);
        $this->assertStringNotContainsString('kb-faq', $cleaned);
        $this->assertStringNotContainsString('Task Get API endpoint', $cleaned);
        $this->assertStringContainsString('RewriteRule ^(.*)$ %1/http.php/$1 [L]', $cleaned);
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} (.*/api)', $cleaned);
        $this->assertStringContainsString('# my own note', $cleaned);
        $this->assertStringContainsString('RewriteRule ^custom\.php - [L]', $cleaned);
        $this->assertStringContainsString('</IfModule>', $cleaned);
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $cleaned);
    }

    public function testTemplateEndsWithEndMarker(): void {
        $template = rtrim((string)file_get_contents(__DIR__ . '/../../htaccess.template'));
        $this->assertStringEndsWith('# End of API Endpoints Plugin rules', $template);
    }

    public function testEveryEndpointFileHasARewriteRule(): void {
        $template = (string)file_get_contents(__DIR__ . '/../../htaccess.template');
        foreach (glob(__DIR__ . '/../../api/*.php') as $file) {
            $name = preg_quote(basename($file, '.php'), '#');
            $this->assertMatchesRegularExpression('#RewriteRule \^' . $name . '\\\\\.php#', $template, basename($file));
        }
    }
}
