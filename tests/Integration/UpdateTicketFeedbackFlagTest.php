<?php

use PHPUnit\Framework\TestCase;

/**
 * `feedback` on PATCH tickets-update: passed to the Feedback plugin as
 * $_POST['feedback_ask'] while the status changes, and only then.
 */
class UpdateTicketFeedbackFlagTest extends TestCase {

    protected function setUp(): void {
        Ticket::$mockData = [];
        Ticket::$mockDataByNumber = [];
        API::$mockData = [];
        TicketStatus::$mockData = [];
        Ticket::$feedbackAskSeen = 'unset';
        unset($_POST['feedback_ask']);

        TicketStatus::$mockData[1] = new TicketStatus(['id' => 1, 'name' => 'Open']);
        TicketStatus::$mockData[3] = new TicketStatus(['id' => 3, 'name' => 'Closed']);
        Ticket::$mockDataByNumber['500100'] = new Ticket([
            'id' => 77, 'number' => '500100', 'subject' => 'Test', 'status_id' => 1,
        ]);
        API::$mockData['test-key'] = new API(['key' => 'test-api-key', 'can_update_tickets' => true]);
    }

    protected function tearDown(): void {
        unset($_POST['feedback_ask']);
    }

    public function testTrueIsPassedAsOneDuringTheStatusChange(): void {
        (new ExtendedTicketApiController())->update('500100', ['statusId' => 3, 'feedback' => true]);
        $this->assertSame('1', Ticket::$feedbackAskSeen);
        $this->assertArrayNotHasKey('feedback_ask', $_POST, 'cleaned up after the call');
    }

    public function testFalseIsPassedAsZero(): void {
        (new ExtendedTicketApiController())->update('500100', ['statusId' => 3, 'feedback' => false]);
        $this->assertSame('0', Ticket::$feedbackAskSeen);
    }

    public function testAbsentFieldSetsNothing(): void {
        (new ExtendedTicketApiController())->update('500100', ['statusId' => 3]);
        $this->assertSame('unset', Ticket::$feedbackAskSeen);
    }

    public function testAnExistingValueIsRestored(): void {
        $_POST['feedback_ask'] = 'x';
        (new ExtendedTicketApiController())->update('500100', ['statusId' => 3, 'feedback' => true]);
        $this->assertSame('1', Ticket::$feedbackAskSeen);
        $this->assertSame('x', $_POST['feedback_ask']);
    }

    public function testInvalidValueIsRefusedBeforeAnyChange(): void {
        $controller = new ExtendedTicketApiController();
        try {
            $controller->update('500100', ['statusId' => 3, 'feedback' => 'maybe']);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->assertSame('unset', Ticket::$feedbackAskSeen, 'status not changed');
        $this->assertEquals(1, Ticket::$mockDataByNumber['500100']->getStatusId());
    }

    /** @dataProvider flags */
    public function testAcceptedSpellings($in, bool $out): void {
        $this->assertSame($out, ExtendedTicketApiController::validateFeedbackFlag($in));
    }

    public static function flags(): array {
        return [[true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false], ['true', true], ['false', false]];
    }
}
