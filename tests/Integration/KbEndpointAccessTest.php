<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../controllers/ExtendedTicketApiController.php';
require_once __DIR__ . '/../../class.ApiEndpointsPlugin.php';

/**
 * Access control of the knowledge base endpoints
 *
 * Reading needs can_read_kb, every change needs can_write_kb. Ticket and
 * task permissions never grant knowledge base access, and can_write_kb
 * does not imply reading. These checks run before the knowledgebase is
 * touched; the FAQ operations themselves are covered against a real
 * osTicket in the devlab.
 */
class KbEndpointAccessTest extends TestCase {

    protected function setUp(): void {
        API::$mockData = [];
    }

    private function useKey(array $permissions): void {
        API::$mockData['test-key'] = new API(['key' => 'test-api-key'] + $permissions);
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function readEndpoints(): array {
        return [
            'categories' => ['read the knowledge base', fn($c) => $c->listKbCategories()],
            'search' => ['read the knowledge base', fn($c) => $c->searchKbArticles([])],
            'get' => ['read the knowledge base', fn($c) => $c->getKbArticle('1')],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function writeEndpoints(): array {
        return [
            'category create' => ['change the knowledge base', fn($c) => $c->createKbCategory(['name' => 'xyz', 'description' => 'd'])],
            'category update' => ['change the knowledge base', fn($c) => $c->updateKbCategory('1', ['name' => 'xyz'])],
            'article create' => ['change the knowledge base', fn($c) => $c->createKbArticle(['question' => 'q', 'answer' => 'a', 'categoryId' => 1])],
            'article update' => ['change the knowledge base', fn($c) => $c->updateKbArticle('1', ['question' => 'q'])],
        ];
    }

    public static function allEndpoints(): array {
        return self::readEndpoints() + self::writeEndpoints();
    }

    #[DataProvider('allEndpoints')]
    public function testTicketAndTaskPermissionsDoNotGrantKbAccess(string $action, callable $call): void {
        $this->useKey([
            'can_create_tickets' => true,
            'can_update_tickets' => true,
            'can_read_tickets' => true,
            'can_search_tickets' => true,
            'can_delete_tickets' => true,
            'can_reply_tickets' => true,
            'can_create_tasks' => true,
            'can_update_tasks' => true,
            'can_read_tasks' => true,
            'can_delete_tasks' => true,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('API key not authorized to ' . $action);
        $call(new ExtendedTicketApiController());
    }

    #[DataProvider('writeEndpoints')]
    public function testReadPermissionDoesNotAllowChanges(string $action, callable $call): void {
        $this->useKey(['can_read_kb' => true]);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('API key not authorized to ' . $action);
        $call(new ExtendedTicketApiController());
    }

    #[DataProvider('readEndpoints')]
    public function testWritePermissionDoesNotAllowReading(string $action, callable $call): void {
        $this->useKey(['can_write_kb' => true]);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('API key not authorized to ' . $action);
        $call(new ExtendedTicketApiController());
    }

    public function testConfigAndEnumListTheSameKbColumns(): void {
        $kbColumns = ['can_read_kb', 'can_write_kb'];

        foreach ($kbColumns as $column) {
            $this->assertContains($column, Permission::columnNames());
        }
        $this->assertSame($kbColumns, array_keys(ApiEndpointsConfig::kbPermissions()));
        $this->assertSame(
            array_merge(array_keys(ApiEndpointsConfig::taskPermissions()), $kbColumns),
            array_keys(ApiEndpointsConfig::extraPermissions())
        );
    }
}
