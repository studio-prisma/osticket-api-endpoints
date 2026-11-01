<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../controllers/ExtendedTicketApiController.php';
// Real plugin class for its static config cache (Plugin base is mocked)
require_once __DIR__ . '/../../class.ApiEndpointsPlugin.php';

/**
 * Access control of the task endpoints
 *
 * Every task endpoint needs its own API key permission; ticket permissions
 * never grant task access. Changes additionally need the "Tasks as Agent"
 * setting, which is separate from "Reply as Agent". These checks run
 * before any task is touched; the task operations themselves are covered
 * against a real osTicket in the devlab.
 */
class TaskEndpointAccessTest extends TestCase {

    protected function setUp(): void {
        API::$mockData = [];
        Staff::$mockData = [];
        Staff::$mockData['devadmin'] = new Staff(['id' => 7, 'name' => 'Dev Admin']);
        ApiEndpointsPlugin::$cached_config = ['task_agent' => 'devadmin'];
    }

    protected function tearDown(): void {
        ApiEndpointsPlugin::$cached_config = null;
    }

    private function useKey(array $permissions): void {
        API::$mockData['test-key'] = new API(['key' => 'test-api-key'] + $permissions);
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function endpoints(): array {
        return [
            'get' => ['read tasks', fn($c) => $c->getTask('1')],
            'search' => ['search tasks', fn($c) => $c->searchTasks([])],
            'create' => ['create tasks', fn($c) => $c->createTask(['title' => 'x', 'departmentId' => 1])],
            'update' => ['update tasks', fn($c) => $c->updateTask('1', ['title' => 'x'])],
            'delete' => ['delete tasks', fn($c) => $c->deleteTask('1')],
        ];
    }

    #[DataProvider('endpoints')]
    public function testTicketPermissionsDoNotGrantTaskAccess(string $action, callable $call): void {
        $this->useKey([
            'can_create_tickets' => true,
            'can_update_tickets' => true,
            'can_read_tickets' => true,
            'can_search_tickets' => true,
            'can_delete_tickets' => true,
            'can_reply_tickets' => true,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('API key not authorized to ' . $action);
        $call(new ExtendedTicketApiController());
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function writeEndpoints(): array {
        return [
            'create' => ['can_create_tasks', fn($c) => $c->createTask(['title' => 'x', 'departmentId' => 1])],
            'update' => ['can_update_tasks', fn($c) => $c->updateTask('1', ['title' => 'x'])],
            'delete' => ['can_delete_tasks', fn($c) => $c->deleteTask('1')],
        ];
    }

    #[DataProvider('writeEndpoints')]
    public function testChangesAreRefusedWithoutTaskAgent(string $permission, callable $call): void {
        $this->useKey([$permission => true]);
        ApiEndpointsPlugin::$cached_config = ['task_agent' => ''];

        $this->expectException(Exception::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Task changes are disabled');
        $call(new ExtendedTicketApiController());
    }

    #[DataProvider('writeEndpoints')]
    public function testReplyAgentIsNotUsedForTasks(string $permission, callable $call): void {
        $this->useKey([$permission => true]);
        ApiEndpointsPlugin::$cached_config = ['reply_agent' => 'devadmin', 'task_agent' => ''];

        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Task changes are disabled');
        $call(new ExtendedTicketApiController());
    }

    #[DataProvider('writeEndpoints')]
    public function testChangesAreRefusedForInactiveTaskAgent(string $permission, callable $call): void {
        $this->useKey([$permission => true]);
        Staff::$mockData['devadmin'] = new Staff(['id' => 7, 'name' => 'Dev Admin', 'active' => false]);

        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('The configured task agent does not exist or is inactive');
        $call(new ExtendedTicketApiController());
    }

    #[DataProvider('writeEndpoints')]
    public function testChangesAreRefusedForUnknownTaskAgent(string $permission, callable $call): void {
        $this->useKey([$permission => true]);
        ApiEndpointsPlugin::$cached_config = ['task_agent' => 'nobody'];

        $this->expectExceptionCode(403);
        $call(new ExtendedTicketApiController());
    }

    public function testUpdatePermissionDoesNotAllowDelete(): void {
        $this->useKey(['can_update_tasks' => true, 'can_create_tasks' => true]);

        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('API key not authorized to delete tasks');
        (new ExtendedTicketApiController())->deleteTask('1');
    }

    public function testTaskAgentDoesNotEnableCustomerReplies(): void {
        $this->useKey(['can_reply_tickets' => true]);
        ApiEndpointsPlugin::$cached_config = ['task_agent' => 'devadmin', 'reply_agent' => ''];
        Ticket::$mockDataByNumber['781258'] = new Ticket(['id' => 1, 'number' => '781258', 'subject' => 'x']);

        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Customer replies are disabled');
        (new ExtendedTicketApiController())->reply('781258', ['response' => 'Hallo']);
    }

    public function testSearchFallsBackToReadPermission(): void {
        $checker = PermissionChecker::getInstance();

        $this->assertTrue($checker->has(new API(['key' => 'k', 'can_read_tasks' => true]), Permission::SearchTasks));
        $this->assertFalse($checker->has(new API(['key' => 'k', 'can_read_tasks' => true]), Permission::UpdateTasks));
        $this->assertFalse($checker->has(new API(['key' => 'k', 'can_search_tasks' => true]), Permission::ReadTasks));
    }

    public function testConfigAndEnumListTheSameTaskColumns(): void {
        $taskColumns = ['can_create_tasks', 'can_update_tasks', 'can_read_tasks', 'can_search_tasks', 'can_delete_tasks'];

        foreach ($taskColumns as $column) {
            $this->assertContains($column, Permission::columnNames());
        }
        $this->assertSame($taskColumns, array_keys(ApiEndpointsConfig::taskPermissions()));
    }
}
