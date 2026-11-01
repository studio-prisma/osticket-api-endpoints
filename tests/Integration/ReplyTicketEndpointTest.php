<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../controllers/ExtendedTicketApiController.php';
// Real plugin class for its static config cache (Plugin base is mocked)
require_once __DIR__ . '/../../class.ApiEndpointsPlugin.php';

/**
 * Integration Tests for POST /api/tickets-reply.php endpoint
 *
 * A reply is emailed to the customer and cannot be recalled, so most of
 * these tests check that it is refused: missing permission, no configured
 * agent, inactive agent, agent without reply access, invalid options.
 */
class ReplyTicketEndpointTest extends TestCase {

    private Ticket $ticket;
    private Staff $agent;

    protected function setUp(): void {
        Ticket::$mockData = [];
        Ticket::$mockDataByNumber = [];
        API::$mockData = [];
        Staff::$mockData = [];

        $this->ticket = new Ticket([
            'id' => 123,
            'number' => '781258',
            'subject' => 'Test Ticket'
        ]);
        Ticket::$mockDataByNumber['781258'] = $this->ticket;

        $this->agent = new Staff(['id' => 7, 'name' => 'Dev Admin']);
        Staff::$mockData['devadmin'] = $this->agent;

        ApiEndpointsPlugin::$cached_config = ['reply_agent' => 'devadmin'];

        API::$mockData['test-key'] = new API([
            'key' => 'test-api-key',
            'can_reply_tickets' => true
        ]);

        $GLOBALS['thisstaff'] = null;
    }

    protected function tearDown(): void {
        ApiEndpointsPlugin::$cached_config = null;
        unset($GLOBALS['thisstaff']);
    }

    private function reply(array $data): array {
        return (new ExtendedTicketApiController())->reply('781258', $data);
    }

    public function testPostsReplyAsConfiguredAgentWithDefaults(): void {
        $result = $this->reply(['response' => "Hallo Alice,\n\nerledigt."]);

        $this->assertSame([
            'ticket' => '781258',
            'entryId' => 4711,
            'alerted' => true,
            'replyTo' => 'all',
            'agent' => 'Dev Admin',
        ], $result);

        $vars = $this->ticket->lastReplyVars;
        $this->assertInstanceOf(TextThreadEntryBody::class, $vars['response']);
        $this->assertSame("Hallo Alice,\n\nerledigt.", $vars['response']->body);
        $this->assertSame('all', $vars['reply-to']);
        $this->assertSame('mine', $vars['signature']);
        $this->assertSame(7, $vars['staffId']);
        $this->assertTrue($this->ticket->lastReplyAlert);
        $this->assertFalse($this->ticket->lastReplyClaim, 'API replies must not claim the ticket');
    }

    public function testSetsGlobalAgentDuringReplyAndRestoresIt(): void {
        $previous = new Staff(['id' => 99, 'name' => 'Someone else']);
        $GLOBALS['thisstaff'] = $previous;

        $this->reply(['response' => 'Hallo']);

        $this->assertSame($this->agent, $this->ticket->lastReplyGlobalStaff);
        $this->assertSame($previous, $GLOBALS['thisstaff']);
    }

    public function testRestoresGlobalAgentWhenReplyFails(): void {
        $this->ticket->replyErrors = ['err' => 'boom'];

        try {
            $this->reply(['response' => 'Hallo']);
            $this->fail('Expected exception');
        } catch (Exception $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertNull($GLOBALS['thisstaff']);
    }

    public function testHtmlFormatOwnerOnlyNoSignatureNoAlert(): void {
        $result = $this->reply([
            'response' => '<p>Hallo</p>',
            'format' => 'html',
            'replyTo' => 'user',
            'signature' => 'none',
            'alert' => false,
        ]);

        $vars = $this->ticket->lastReplyVars;
        $this->assertInstanceOf(HtmlThreadEntryBody::class, $vars['response']);
        $this->assertSame('user', $vars['reply-to']);
        $this->assertSame('none', $vars['signature']);
        $this->assertFalse($this->ticket->lastReplyAlert);
        $this->assertFalse($result['alerted']);
        $this->assertSame('user', $result['replyTo']);
    }

    public function testUpdatePermissionDoesNotAllowReplies(): void {
        API::$mockData['test-key'] = new API([
            'key' => 'test-api-key',
            'can_update_tickets' => true,
            'can_create_tickets' => true,
        ]);

        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('API key not authorized to reply to customers');
        $this->reply(['response' => 'Hallo']);
    }

    public function testRefusesWithoutConfiguredAgent(): void {
        ApiEndpointsPlugin::$cached_config = ['reply_agent' => '  '];

        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Customer replies are disabled');
        $this->reply(['response' => 'Hallo']);
    }

    public function testRefusesUnknownAgent(): void {
        ApiEndpointsPlugin::$cached_config = ['reply_agent' => 'nobody'];

        $this->expectExceptionCode(403);
        $this->reply(['response' => 'Hallo']);
    }

    public function testRefusesInactiveAgent(): void {
        Staff::$mockData['devadmin'] = new Staff(['id' => 7, 'name' => 'Dev Admin', 'active' => false]);

        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('does not exist or is inactive');
        $this->reply(['response' => 'Hallo']);
    }

    public function testRefusesAgentWithoutReplyAccess(): void {
        $this->ticket->replyStaffIds = [42];

        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('not allowed to reply to this ticket');
        $this->reply(['response' => 'Hallo']);
        $this->assertNull($this->ticket->lastReplyVars);
    }

    public function testReturns404ForUnknownTicket(): void {
        $this->expectExceptionCode(404);
        (new ExtendedTicketApiController())->reply('999999', ['response' => 'Hallo']);
    }

    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidInput(array $data, string $message): void {
        try {
            $this->reply($data);
            $this->fail('Expected exception');
        } catch (Exception $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertNull($this->ticket->lastReplyVars, 'nothing may be posted');
    }

    public static function invalidInputProvider(): array {
        return [
            'missing response' => [[], 'response is required'],
            'blank response' => [['response' => " \n "], 'response is required'],
            'non-string response' => [['response' => ['x']], 'response is required'],
            'too long' => [['response' => str_repeat('a', ExtendedTicketApiController::REPLY_MAX_BYTES + 1)], 'exceeds'],
            'markdown format' => [['response' => 'x', 'format' => 'markdown'], 'format must be one of: text, html'],
            'bad replyTo' => [['response' => 'x', 'replyTo' => 'collabs'], 'replyTo must be one of'],
            'bad signature' => [['response' => 'x', 'signature' => 'theirs'], 'signature must be one of'],
            'string alert' => [['response' => 'x', 'alert' => 'false'], 'alert must be true or false'],
        ];
    }
}
