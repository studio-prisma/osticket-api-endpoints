<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../controllers/ExtendedTicketApiController.php';

/**
 * PATCH /api/tickets-update.php: renaming a ticket via `subject`
 *
 * The subject is the answer to the ticket form's subject field. A change is
 * logged as an "edited" thread event with old and new value, like the staff
 * UI does. Invalid subjects are refused with 400 before anything changes.
 */
class UpdateTicketSubjectTest extends TestCase {

    private const NUMBER = '781258';

    protected function setUp(): void {
        Ticket::$mockData = [];
        Ticket::$mockDataByNumber = [];
        API::$mockData = [];
        Dept::$mockData = [];
        DynamicFormEntry::$withoutSubject = [];
        DynamicFormEntry::$saveFails = false;
        TicketForm::$subjectLength = null;
        API::$mockData['test-key'] = new API(['key' => 'test-api-key', 'can_update_tickets' => true]);
    }

    protected function tearDown(): void {
        DynamicFormEntry::$withoutSubject = [];
        DynamicFormEntry::$saveFails = false;
        TicketForm::$subjectLength = null;
    }

    private function ticket(string $subject = 'Alter Betreff', int $id = 123): Ticket {
        $ticket = new Ticket(['id' => $id, 'number' => self::NUMBER, 'subject' => $subject, 'dept_id' => 1]);
        Ticket::$mockDataByNumber[self::NUMBER] = $ticket;
        return $ticket;
    }

    private function update(array $data): Ticket {
        return (new ExtendedTicketApiController())->update(self::NUMBER, $data);
    }

    private function assertRefused(array $data, string $message): void {
        try {
            $this->update($data);
            $this->fail('Expected a 400 for ' . json_encode($data));
        } catch (Exception $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testRenamesTicketAndLogsEditedEvent(): void {
        $ticket = $this->ticket();

        $result = $this->update(['subject' => 'Neuer Betreff']);

        $this->assertSame('Neuer Betreff', $result->getSubject());
        $this->assertSame(
            [['edited', ['fields' => [20 => ['Alter Betreff', 'Neuer Betreff']]], 'API']],
            $ticket->events
        );
        $this->assertInstanceOf(SqlFunction::class, $ticket->lastupdate);
    }

    public function testTrimsSurroundingWhitespace(): void {
        $this->ticket();
        $this->assertSame('Neu', $this->update(['subject' => "  Neu  "])->getSubject());
    }

    public function testKeepsUmlautsAndSpecialCharacters(): void {
        $this->ticket();
        $subject = 'Größe ändern: 5 < 7 & "Zitat" – ok';
        $this->assertSame($subject, $this->update(['subject' => $subject])->getSubject());
    }

    public function testUnchangedSubjectLogsNoEvent(): void {
        $ticket = $this->ticket('Gleich');
        $this->update(['subject' => ' Gleich ']);
        $this->assertSame([], $ticket->events);
    }

    public function testRefusesEmptySubject(): void {
        $this->ticket();
        $this->assertRefused(['subject' => ''], 'must not be empty');
        $this->assertRefused(['subject' => "   \t "], 'must not be empty');
    }

    public function testRefusesNonStringSubject(): void {
        $this->ticket();
        $this->assertRefused(['subject' => null], 'must be a string');
        $this->assertRefused(['subject' => 42], 'must be a string');
        $this->assertRefused(['subject' => ['a']], 'must be a string');
    }

    public function testRefusesHtml(): void {
        $this->ticket();
        $this->assertRefused(['subject' => 'Hallo <b>Welt</b>'], 'must not contain HTML');
        $this->assertRefused(['subject' => '<script>alert(1)</script>'], 'must not contain HTML');
        $this->assertRefused(['subject' => 'x<img src=x onerror=alert(1)>'], 'must not contain HTML');
        $this->assertRefused(['subject' => 'a <!-- b --> c'], 'must not contain HTML');
    }

    public function testRefusesLineBreaksAndControlCharacters(): void {
        $this->ticket();
        $this->assertRefused(['subject' => "Zeile 1\nZeile 2"], 'single line');
        $this->assertRefused(['subject' => "a\rb"], 'single line');
        $this->assertRefused(['subject' => "a\tb"], 'single line');
        $this->assertRefused(['subject' => "a\x00b"], 'single line');
        $this->assertRefused(['subject' => "a\u{2028}b"], 'single line');
    }

    public function testRefusesInvalidUtf8(): void {
        $this->ticket();
        $this->assertRefused(['subject' => "Betreff \xC3\x28"], 'valid UTF-8');
    }

    public function testDefaultLengthLimitIs255Characters(): void {
        $this->ticket();
        $this->assertSame(255, mb_strlen($this->update(['subject' => str_repeat('ä', 255)])->getSubject()));
        $this->assertRefused(['subject' => str_repeat('a', 256)], 'exceeds 255 characters');
    }

    public function testUsesLengthConfiguredOnTicketForm(): void {
        TicketForm::$subjectLength = 50;
        $this->ticket();
        $this->assertSame(50, mb_strlen($this->update(['subject' => str_repeat('b', 50)])->getSubject()));
        $this->assertRefused(['subject' => str_repeat('b', 51)], 'exceeds 50 characters');
    }

    public function testInvalidSubjectChangesNothingElse(): void {
        Dept::$mockData[1] = new Dept(['id' => 1, 'name' => 'Sales', 'active' => true]);
        Dept::$mockData[5] = new Dept(['id' => 5, 'name' => 'Support', 'active' => true]);
        $ticket = $this->ticket();

        $this->assertRefused(['departmentId' => 5, 'subject' => '<b>x</b>'], 'HTML');

        $this->assertSame(1, $ticket->getDeptId());
        $this->assertSame('Alter Betreff', $ticket->getSubject());
    }

    public function testSubjectCombinesWithOtherFields(): void {
        Dept::$mockData[1] = new Dept(['id' => 1, 'name' => 'Sales', 'active' => true]);
        Dept::$mockData[5] = new Dept(['id' => 5, 'name' => 'Support', 'active' => true]);
        $this->ticket();

        $result = $this->update(['departmentId' => 5, 'subject' => 'Umbenannt']);

        $this->assertSame(5, $result->getDeptId());
        $this->assertSame('Umbenannt', $result->getSubject());
    }

    public function testTicketWithoutSubjectFieldGets400(): void {
        $this->ticket();
        DynamicFormEntry::$withoutSubject = [123];
        $this->assertRefused(['subject' => 'Neu'], 'no subject field');
    }

    public function testFailedSaveGets500AndLogsNoEvent(): void {
        $ticket = $this->ticket();
        DynamicFormEntry::$saveFails = true;
        try {
            $this->update(['subject' => 'Neu']);
            $this->fail('Expected a 500');
        } catch (Exception $e) {
            $this->assertSame(500, $e->getCode());
        }
        $this->assertSame([], $ticket->events);
    }

    public function testRequiresUpdatePermission(): void {
        $ticket = $this->ticket();
        API::$mockData['test-key'] = new API([
            'key' => 'test-api-key',
            'can_update_tickets' => false,
            'permissions' => ['can_create_tickets' => false],
        ]);
        try {
            $this->update(['subject' => 'Neu']);
            $this->fail('Expected a 401');
        } catch (Exception $e) {
            $this->assertSame(401, $e->getCode());
        }
        $this->assertSame('Alter Betreff', $ticket->getSubject());
    }
}
