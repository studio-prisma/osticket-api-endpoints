<?php
/**
 * Task Service
 *
 * Task operations for the task endpoints: read, search, create, update
 * and delete osTicket tasks (ost_task).
 *
 * Every change runs as an agent (the "Tasks as Agent" setting). The agent
 * is set as the global $thisstaff for the call, so osTicket applies the
 * same role permissions, events, notes and alerts as in the staff UI.
 */

declare(strict_types=1);

require_once __DIR__ . '/TicketValidatorService.php';

class TaskService
{
    /** Maximum description, note and update size in bytes (TEXT column) */
    public const BODY_MAX_BYTES = 65535;

    /** Title length when the task form does not configure one */
    public const TITLE_DEFAULT_LENGTH = 50;

    /** Search page size */
    public const SEARCH_DEFAULT_LIMIT = 50;
    public const SEARCH_MAX_LIMIT = 100;

    /** Allowed search sort fields => ORM field */
    private const SORT_FIELDS = [
        'created' => 'created',
        'updated' => 'updated',
        'duedate' => 'duedate',
        'number' => 'number',
    ];

    private TicketValidatorService $validator;

    public function __construct()
    {
        $this->validator = TicketValidatorService::getInstance();
    }

    // =========================================================================
    // Read
    // =========================================================================

    /**
     * Look up a task by its number
     *
     * @throws Exception If the number is malformed (400) or unknown (404)
     */
    public function lookupTask(string $number): Task
    {
        $number = trim($number);
        if ($number === '' || !preg_match('/^[A-Za-z0-9-]{1,20}$/', $number)) {
            throw new Exception('Invalid task number', 400);
        }

        $task = Task::lookup(['number' => $number]);
        if (!$task) {
            throw new Exception('Task not found', 404);
        }
        return $task;
    }

    /**
     * Task with details and thread
     */
    public function getTask(string $number): array
    {
        return $this->buildTaskResponse($this->lookupTask($number), true);
    }

    /**
     * Build the task response
     *
     * @param bool $withThread Include description, notes and updates
     */
    public function buildTaskResponse(Task $task, bool $withThread): array
    {
        $ticket = $this->linkedTicket($task);
        $staff = $task->getStaff();
        $team = $task->getTeam();
        $dept = $task->getDept();

        $response = [
            'id' => (int)$task->getId(),
            'number' => (string)$task->getNumber(),
            'title' => (string)$task->getTitle(),
            'status' => $task->isOpen() ? 'open' : 'closed',
            'isOverdue' => (bool)$task->isOverdue(),
            'departmentId' => (int)$task->getDeptId(),
            'department' => $dept ? (string)$dept->getName() : null,
            'staffId' => (int)$task->getStaffId() ?: null,
            'staff' => $staff ? (string)$staff->getName() : null,
            'teamId' => (int)$task->getTeamId() ?: null,
            'team' => $team ? (string)$team->getName() : null,
            'ticket' => $ticket ? [
                'id' => (int)$ticket->getId(),
                'number' => (string)$ticket->getNumber(),
                'subject' => (string)$ticket->getSubject(),
            ] : null,
            'created' => $task->getCreateDate(),
            'updated' => $this->updatedDate($task),
            'duedate' => $task->getDueDate(),
            'closed' => $task->isClosed() ? $task->getCloseDate() : null,
        ];

        if ($withThread) {
            $response['thread'] = $this->getThreadEntries($task);
        }

        return $response;
    }

    /**
     * Last update time
     *
     * After a change in this request the ORM still holds the NOW()
     * expression it wrote, not the stored value, so it is read back.
     */
    private function updatedDate(Task $task): ?string
    {
        $updated = $task->updated ?? null;
        if ($updated === null || is_string($updated)) {
            return $updated;
        }
        $result = db_query(sprintf('SELECT updated FROM %s WHERE id = %d', TASK_TABLE, (int)$task->getId()));
        $row = $result ? db_fetch_array($result) : null;
        return $row ? (string)$row['updated'] : null;
    }

    /**
     * Description (M), internal notes (N) and updates (R) of a task
     */
    private function getThreadEntries(Task $task): array
    {
        $entries = [];
        $thread = $task->getThread();
        if (!$thread) {
            return $entries;
        }

        foreach ($thread->getEntries() as $entry) {
            $body = $entry->getBody();
            $item = [
                'id' => (int)$entry->getId(),
                'type' => $entry->getType(),
                'title' => $entry->getTitle(),
                'poster' => (string)$entry->getPoster(),
                'timestamp' => $entry->getCreateDate(),
                'body' => (string)$body,
                'format' => is_object($body) && method_exists($body, 'getType') ? (string)$body->getType() : 'text',
            ];
            if ($entry->getStaffId()) {
                $item['staffId'] = (int)$entry->getStaffId();
                $item['staff'] = $entry->getStaff() ? (string)$entry->getStaff()->getName() : null;
            }

            $attachments = [];
            foreach ($entry->getAttachments() ?: [] as $attachment) {
                $file = $attachment->getFile();
                if ($file) {
                    $attachments[] = [
                        'id' => (int)$attachment->getId(),
                        'file_id' => (int)$file->getId(),
                        'filename' => $file->getName(),
                        'size' => (int)$file->getSize(),
                        'mime_type' => $file->getMimeType(),
                        'inline' => (bool)$attachment->inline,
                    ];
                }
            }
            if ($attachments) {
                $item['attachments'] = $attachments;
            }

            $entries[] = $item;
        }

        return $entries;
    }

    /**
     * Search tasks
     *
     * @param array $params ticket, status (open|closed|all), departmentId,
     *   staffId, teamId, unassigned, overdue, standalone, query, limit,
     *   offset, sort, order
     */
    public function searchTasks(array $params): array
    {
        $tasks = Task::objects();

        $status = strtolower(trim((string)($params['status'] ?? 'open')));
        switch ($status) {
            case 'open':
                $tasks->filter(['flags__hasbit' => TaskModel::ISOPEN]);
                break;
            case 'closed':
                $tasks->exclude(['flags__hasbit' => TaskModel::ISOPEN]);
                break;
            case 'all':
                break;
            default:
                throw new Exception('status must be one of: open, closed, all', 400);
        }

        if (isset($params['ticket']) && trim((string)$params['ticket']) !== '') {
            $ticket = $this->lookupTicket((string)$params['ticket']);
            $tasks->filter([
                'object_type' => ObjectModel::OBJECT_TYPE_TICKET,
                'object_id' => $ticket->getId(),
            ]);
        } elseif ($this->flag($params, 'standalone')) {
            $tasks->exclude(['object_type' => ObjectModel::OBJECT_TYPE_TICKET]);
        }

        if (isset($params['departmentId']) && $params['departmentId'] !== '') {
            $tasks->filter(['dept_id' => $this->validator->validateDepartmentId($params['departmentId'])]);
        }
        if (isset($params['staffId']) && $params['staffId'] !== '') {
            $tasks->filter(['staff_id' => $this->resolveStaff($params['staffId'], false)->getId()]);
        }
        if (isset($params['teamId']) && $params['teamId'] !== '') {
            $tasks->filter(['team_id' => $this->resolveTeam($params['teamId'])->getId()]);
        }
        if ($this->flag($params, 'unassigned')) {
            $tasks->filter(['staff_id' => 0, 'team_id' => 0]);
        }
        if ($this->flag($params, 'overdue')) {
            $tasks->filter(['flags__hasbit' => TaskModel::ISOVERDUE]);
        }

        $query = trim((string)($params['query'] ?? ''));
        if ($query !== '') {
            if (mb_strlen($query) > 100) {
                throw new Exception('query exceeds 100 characters', 400);
            }
            $tasks->filter(Q::any([
                'number__startswith' => $query,
                'cdata__title__contains' => $query,
            ]));
        }

        $limit = $this->intParam($params, 'limit', self::SEARCH_DEFAULT_LIMIT, 1, self::SEARCH_MAX_LIMIT);
        $offset = $this->intParam($params, 'offset', 0, 0, PHP_INT_MAX);

        $sort = strtolower((string)($params['sort'] ?? 'created'));
        if (!isset(self::SORT_FIELDS[$sort])) {
            throw new Exception('sort must be one of: ' . implode(', ', array_keys(self::SORT_FIELDS)), 400);
        }
        $order = strtolower((string)($params['order'] ?? 'desc'));
        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new Exception('order must be asc or desc', 400);
        }

        $total = $tasks->count();
        $tasks->order_by(($order === 'desc' ? '-' : '') . self::SORT_FIELDS[$sort])
            ->limit($limit)
            ->offset($offset);

        $items = [];
        foreach ($tasks as $task) {
            $items[] = $this->buildTaskResponse($task, false);
        }

        return [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'tasks' => $items,
        ];
    }

    // =========================================================================
    // Create
    // =========================================================================

    /**
     * Create a task, standalone or linked to a ticket
     *
     * @param array $data title (required), description, format,
     *   ticketNumber, departmentId, staffId|teamId, dueDate
     */
    public function createTask(Staff $agent, array $data): array
    {
        $this->rejectUnknown($data, [
            'title', 'description', 'format', 'ticketNumber', 'departmentId',
            'staffId', 'teamId', 'dueDate',
        ]);

        $title = $this->title($data['title'] ?? null);
        $description = $this->body($data, 'description', false);
        $format = $this->format($data);

        $ticket = null;
        if (isset($data['ticketNumber']) && $data['ticketNumber'] !== '') {
            $ticket = $this->lookupTicket((string)$data['ticketNumber']);
            if (!$ticket->checkStaffPerm($agent)) {
                throw new Exception('The task agent has no access to this ticket', 403);
            }
        }

        if (isset($data['departmentId']) && $data['departmentId'] !== '') {
            $dept = Dept::lookup($this->validator->validateDepartmentId($data['departmentId']));
        } elseif ($ticket) {
            $dept = $ticket->getDept();
        } else {
            throw new Exception('departmentId is required for a task without ticket', 400);
        }

        $role = $agent->getRole($dept);
        if (!$role || !$role->hasPerm(Task::PERM_CREATE)) {
            throw new Exception('The task agent is not allowed to create tasks in this department', 403);
        }

        $assignee = $this->assigneeFromData($data);
        if ($assignee && !$role->hasPerm(Task::PERM_ASSIGN)) {
            throw new Exception('The task agent is not allowed to assign tasks in this department', 403);
        }
        $dueDate = array_key_exists('dueDate', $data) ? $this->dueDate($data['dueDate']) : null;

        $vars = [
            'default_formdata' => ['title' => $title],
            'internal_formdata' => ['dept_id' => $dept->getId()],
            'staffId' => $agent->getId(),
            'poster' => $agent,
        ];
        if ($ticket) {
            $vars['object_id'] = $ticket->getId();
            $vars['object_type'] = ObjectModel::OBJECT_TYPE_TICKET;
        } else {
            $vars['object_id'] = 0;
            $vars['object_type'] = ObjectModel::OBJECT_TYPE_TASK;
        }

        $task = $this->asAgent($agent, function () use ($vars, $description, $format) {
            return $this->withBody($description ?? '', $format, function ($body) use ($vars) {
                $vars['description'] = $body;
                return Task::create($vars);
            });
        });

        if (!$task) {
            throw new Exception('Failed to create task', 500);
        }

        if ($dueDate !== null) {
            $task->duedate = $dueDate;
            $task->save();
        }

        if ($assignee) {
            $this->asAgent($agent, function () use ($task, $assignee) {
                $errors = [];
                $form = AssignmentForm::instantiate(['assignee' => $assignee]);
                if (!$task->assign($form, $errors)) {
                    throw new Exception('Task created, but the assignment failed: ' . $this->errorText($errors), 400);
                }
            });
        }

        if ($ticket) {
            $ticket->logNote(
                sprintf('Task %s created', $task->getNumber()),
                sprintf('Task %s "%s" was created via the API.', $task->getNumber(), $title),
                $agent,
                false
            );
        }

        return $this->buildTaskResponse(Task::lookup($task->getId()), true);
    }

    // =========================================================================
    // Update
    // =========================================================================

    /**
     * Update a task
     *
     * Every field is optional. All input is validated and every permission
     * is checked before the first change is written.
     *
     * @param array $data title, departmentId, staffId|teamId, unassign,
     *   dueDate (null clears), status (open|closed), ticketNumber (null or
     *   "" unlinks), note, noteTitle, response, format, comments, alert
     */
    public function updateTask(Staff $agent, string $number, array $data): array
    {
        $this->rejectUnknown($data, [
            'title', 'departmentId', 'staffId', 'teamId', 'unassign', 'dueDate',
            'status', 'ticketNumber', 'note', 'noteTitle', 'response', 'format',
            'comments', 'alert',
        ]);

        $task = $this->lookupTask($number);
        if (!$task->checkStaffPerm($agent)) {
            throw new Exception('The task agent has no access to this task', 403);
        }

        $alert = $data['alert'] ?? true;
        if (!is_bool($alert)) {
            throw new Exception('alert must be true or false', 400);
        }
        $format = $this->format($data);
        $comments = $this->body($data, 'comments', false);

        // --- validate and check permissions, nothing is written yet ---
        $plan = [];

        if (array_key_exists('title', $data)) {
            $title = $this->title($data['title']);
            if ($title !== (string)$task->getTitle()) {
                $this->requirePerm($agent, $task, Task::PERM_EDIT, 'edit');
                $plan['title'] = $title;
            }
        }

        if (array_key_exists('ticketNumber', $data)) {
            $target = ($data['ticketNumber'] === null || $data['ticketNumber'] === '')
                ? null
                : $this->lookupTicket((string)$data['ticketNumber']);
            $current = $this->linkedTicket($task);
            if (($target ? $target->getId() : 0) !== ($current ? (int)$current->getId() : 0)) {
                $this->requirePerm($agent, $task, Task::PERM_EDIT, 'edit');
                if ($target && !$target->checkStaffPerm($agent)) {
                    throw new Exception('The task agent has no access to this ticket', 403);
                }
                $plan['ticket'] = ['from' => $current, 'to' => $target];
            }
        }

        if (array_key_exists('dueDate', $data)) {
            $dueDate = $this->dueDate($data['dueDate']);
            if ($dueDate !== $task->getDueDate()) {
                $this->requirePerm($agent, $task, Task::PERM_EDIT, 'edit');
                $plan['dueDate'] = $dueDate;
            }
        }

        if (isset($data['departmentId']) && $data['departmentId'] !== '') {
            $deptId = $this->validator->validateDepartmentId($data['departmentId']);
            if ($deptId !== (int)$task->getDeptId()) {
                $this->requirePerm($agent, $task, Task::PERM_TRANSFER, 'transfer');
                $plan['departmentId'] = $deptId;
            }
        }

        $unassign = $data['unassign'] ?? false;
        if (!is_bool($unassign)) {
            throw new Exception('unassign must be true or false', 400);
        }
        $assignee = $this->assigneeFromData($data);
        if ($assignee && $unassign) {
            throw new Exception('Use either staffId/teamId or unassign', 400);
        }
        if ($assignee || $unassign) {
            $this->requirePerm($agent, $task, Task::PERM_ASSIGN, 'assign');
            if ($assignee && $assignee !== $task->getAssigneeId()) {
                $plan['assign'] = $assignee;
            } elseif ($unassign && ($task->getStaffId() || $task->getTeamId())) {
                $plan['unassign'] = true;
            }
        }

        if (isset($data['status'])) {
            if (!in_array($data['status'], ['open', 'closed'], true)) {
                throw new Exception('status must be open or closed', 400);
            }
            if ($data['status'] !== ($task->isOpen() ? 'open' : 'closed')) {
                $this->requirePerm($agent, $task, Task::PERM_CLOSE, 'close or reopen');
                $plan['status'] = $data['status'];
            }
        }

        $note = $this->body($data, 'note', false);
        if ($note !== null) {
            $noteTitle = $data['noteTitle'] ?? 'API Update';
            if (!is_string($noteTitle) || mb_strlen($noteTitle) > 255) {
                throw new Exception('noteTitle must be a string of at most 255 characters', 400);
            }
            $plan['note'] = [$noteTitle, $note];
        }

        $response = $this->body($data, 'response', false);
        if ($response !== null) {
            $this->requirePerm($agent, $task, Task::PERM_REPLY, 'post updates to');
            $plan['response'] = $response;
        }

        // --- apply ---
        $this->asAgent($agent, function () use ($task, $agent, $plan, $format, $comments, $alert) {
            $errors = [];

            if (isset($plan['title'])) {
                $this->setTitle($task, $plan['title']);
            }

            if (isset($plan['ticket'])) {
                $this->setTicket($task, $agent, $plan['ticket']['from'], $plan['ticket']['to']);
            }

            if (array_key_exists('dueDate', $plan)) {
                $previous = $task->getDueDate();
                $task->duedate = $plan['dueDate'];
                if ($plan['dueDate'] === null || strtotime($plan['dueDate']) > time()) {
                    $task->flags &= ~TaskModel::ISOVERDUE;
                }
                $task->updated = SqlFunction::NOW();
                $task->save();
                $task->logEvent('edited', ['duedate' => [$previous, $plan['dueDate']]]);
            }

            if (isset($plan['departmentId'])) {
                $form = TransferForm::instantiate([
                    'dept' => $plan['departmentId'],
                    'comments' => $comments ?? '',
                ]);
                if (!$task->transfer($form, $errors, $alert)) {
                    throw new Exception('Transfer failed: ' . $this->errorText($errors), 400);
                }
            }

            if (isset($plan['assign'])) {
                $form = AssignmentForm::instantiate([
                    'assignee' => $plan['assign'],
                    'comments' => $comments ?? '',
                ]);
                if (!$task->assign($form, $errors, $alert)) {
                    throw new Exception('Assignment failed: ' . $this->errorText($errors), 400);
                }
            }

            if (isset($plan['unassign'])) {
                $released = array_filter([
                    'staff' => (int)$task->getStaffId() ?: null,
                    'team' => (int)$task->getTeamId() ?: null,
                ]);
                $task->staff_id = 0;
                $task->team_id = 0;
                $task->save(true);
                $task->logEvent('released', $released);
            }

            if (isset($plan['status'])) {
                if (!$task->setStatus($plan['status'], $comments ?? '', $errors)) {
                    throw new Exception('Status change failed: ' . $this->errorText($errors), 400);
                }
            }

            if (isset($plan['note'])) {
                [$noteTitle, $noteText] = $plan['note'];
                $this->withBody($noteText, $format, function ($body) use ($task, $agent, $noteTitle, $alert) {
                    $errors = [];
                    if (!$task->postNote(['note' => $body, 'title' => $noteTitle], $errors, $agent, $alert)) {
                        throw new Exception('Failed to post note: ' . $this->errorText($errors), 400);
                    }
                });
            }

            if (isset($plan['response'])) {
                $this->withBody($plan['response'], $format, function ($body) use ($task, $agent, $alert) {
                    $errors = [];
                    $vars = ['response' => $body, 'poster' => $agent, 'staffId' => $agent->getId()];
                    if (!$task->postReply($vars, $errors, $alert)) {
                        throw new Exception('Failed to post update: ' . $this->errorText($errors), 400);
                    }
                });
            }
        });

        $result = $this->buildTaskResponse(Task::lookup($task->getId()), true);
        $result['changed'] = array_keys($plan);
        return $result;
    }

    // =========================================================================
    // Delete
    // =========================================================================

    /**
     * Delete a task with its thread and form data
     *
     * @return array Number and title of the deleted task
     */
    public function deleteTask(Staff $agent, string $number, ?string $comments = null): array
    {
        $task = $this->lookupTask($number);
        if (!$task->checkStaffPerm($agent)) {
            throw new Exception('The task agent has no access to this task', 403);
        }
        $this->requirePerm($agent, $task, Task::PERM_DELETE, 'delete');

        $deleted = [
            'number' => (string)$task->getNumber(),
            'title' => (string)$task->getTitle(),
        ];
        $ticket = $this->linkedTicket($task);

        $ok = $this->asAgent($agent, fn() => $task->delete($comments ?? ''));
        if (!$ok) {
            throw new Exception('Failed to delete task', 500);
        }

        if ($ticket) {
            $ticket->logNote(
                sprintf('Task %s deleted', $deleted['number']),
                sprintf('Task %s "%s" was deleted via the API.', $deleted['number'], $deleted['title']),
                $agent,
                false
            );
        }

        return $deleted;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Run $fn with $agent as the global staff, restore the previous one after
     */
    private function asAgent(Staff $agent, callable $fn): mixed
    {
        global $thisstaff;
        $previous = $thisstaff;
        $thisstaff = $agent;
        try {
            return $fn();
        } finally {
            $thisstaff = $previous;
        }
    }

    /**
     * Turn text into a thread entry body for the given format and run $fn
     *
     * Markdown is handed to the Markdown Support plugin the same way as
     * ticket notes: raw text plus $_POST['format']. Without the plugin the
     * text is kept as plain text, so line breaks survive.
     */
    private function withBody(string $text, string $format, callable $fn): mixed
    {
        if ($format === 'markdown' && $this->validator->isMarkdownPluginActive()) {
            $keys = ['format', 'note', 'message', 'description', 'response'];
            $saved = [];
            foreach ($keys as $key) {
                $saved[$key] = $_POST[$key] ?? null;
            }
            $_POST['format'] = 'markdown';
            foreach (['note', 'message', 'description', 'response'] as $key) {
                $_POST[$key] = $text;
            }
            try {
                return $fn($text);
            } finally {
                foreach ($saved as $key => $value) {
                    if ($value === null) {
                        unset($_POST[$key]);
                    } else {
                        $_POST[$key] = $value;
                    }
                }
            }
        }

        $body = $format === 'html'
            ? new HtmlThreadEntryBody($text)
            : new TextThreadEntryBody($text);
        return $fn($body);
    }

    /**
     * Change the title answer of the task form
     */
    private function setTitle(Task $task, string $title): void
    {
        $previous = (string)$task->getTitle();
        foreach ($task->getDynamicData() as $entry) {
            if ($field = $entry->getField('title')) {
                $entry->setAnswer('title', $title);
                $entry->save();
                $task->updated = SqlFunction::NOW();
                $task->save();
                $task->logEvent('edited', ['fields' => [$field->get('id') => [$previous, $title]]]);
                return;
            }
        }
        throw new Exception('The task form has no title field', 400);
    }

    /**
     * Link the task to a ticket, move it to another one, or unlink it
     *
     * The staff UI only links a task when it is created from a ticket. Here
     * the link can change later; both tickets and the task get a note.
     */
    private function setTicket(Task $task, Staff $agent, ?Ticket $from, ?Ticket $to): void
    {
        $task->object_id = $to ? $to->getId() : 0;
        $task->object_type = $to ? ObjectModel::OBJECT_TYPE_TICKET : ObjectModel::OBJECT_TYPE_TASK;
        $task->updated = SqlFunction::NOW();
        $task->save(true);

        $number = $task->getNumber();
        if ($from) {
            $from->logNote(
                sprintf('Task %s unlinked', $number),
                $to
                    ? sprintf('Task %s was moved to ticket #%s via the API.', $number, $to->getNumber())
                    : sprintf('Task %s was unlinked from this ticket via the API.', $number),
                $agent,
                false
            );
        }
        if ($to) {
            $to->logNote(
                sprintf('Task %s linked', $number),
                sprintf('Task %s "%s" was linked to this ticket via the API.', $number, (string)$task->getTitle()),
                $agent,
                false
            );
        }

        $errors = [];
        $task->postNote([
            'title' => 'Ticket link changed',
            'note' => new TextThreadEntryBody($to
                ? sprintf('Linked to ticket #%s%s.', $to->getNumber(), $from ? sprintf(' (was #%s)', $from->getNumber()) : '')
                : sprintf('Unlinked from ticket #%s.', $from ? $from->getNumber() : '?')),
        ], $errors, $agent, false);
    }

    /**
     * Ticket the task is linked to, or null
     */
    private function linkedTicket(Task $task): ?Ticket
    {
        if ($task->object_type !== ObjectModel::OBJECT_TYPE_TICKET || !$task->object_id) {
            return null;
        }
        return Ticket::lookup((int)$task->object_id) ?: null;
    }

    /**
     * @throws Exception 404 if the ticket does not exist
     */
    private function lookupTicket(string $number): Ticket
    {
        $number = trim($number);
        if ($number === '' || !preg_match('/^[A-Za-z0-9-]{1,20}$/', $number)) {
            throw new Exception('Invalid ticket number', 400);
        }
        $ticket = Ticket::lookupByNumber($number);
        if (!$ticket) {
            throw new Exception('Ticket not found', 404);
        }
        return $ticket;
    }

    /**
     * @throws Exception 403 if the agent's role lacks the permission
     */
    private function requirePerm(Staff $agent, Task $task, string $perm, string $action): void
    {
        if (!$task->checkStaffPerm($agent, $perm)) {
            throw new Exception(sprintf('The task agent is not allowed to %s this task', $action), 403);
        }
    }

    /**
     * Assignee id for AssignmentForm ("s<id>" or "t<id>") from staffId/teamId
     */
    private function assigneeFromData(array $data): ?string
    {
        $hasStaff = isset($data['staffId']) && $data['staffId'] !== '';
        $hasTeam = isset($data['teamId']) && $data['teamId'] !== '';
        if ($hasStaff && $hasTeam) {
            throw new Exception('Use either staffId or teamId', 400);
        }
        if ($hasStaff) {
            return 's' . $this->resolveStaff($data['staffId'], true)->getId();
        }
        if ($hasTeam) {
            return 't' . $this->resolveTeam($data['teamId'])->getId();
        }
        return null;
    }

    /**
     * Agent by ID, username or email
     */
    private function resolveStaff(int|string $staff, bool $mustBeActive): Staff
    {
        $found = Staff::lookup(is_string($staff) && ctype_digit($staff) ? (int)$staff : $staff);
        if (!$found) {
            throw new Exception('Staff member not found', 404);
        }
        if ($mustBeActive && !$found->isActive()) {
            throw new Exception('Staff member is not active', 400);
        }
        return $found;
    }

    private function resolveTeam(int|string $team): Team
    {
        $found = is_numeric($team)
            ? Team::lookup((int)$team)
            : Team::lookup(['name' => (string)$team]);
        if (!$found) {
            throw new Exception('Team not found', 404);
        }
        return $found;
    }

    /**
     * Validated title, limited to the length configured on the task form
     */
    private function title(mixed $title): string
    {
        if (!is_string($title) || trim($title) === '') {
            throw new Exception('title is required', 400);
        }
        $title = trim($title);
        $max = self::TITLE_DEFAULT_LENGTH;
        $form = TaskForm::getDefaultForm();
        if ($form && ($field = $form->getField('title'))) {
            $config = $field->getConfiguration();
            if (!empty($config['length'])) {
                $max = (int)$config['length'];
            }
        }
        if (mb_strlen($title) > $max) {
            throw new Exception(sprintf('title exceeds %d characters', $max), 400);
        }
        return $title;
    }

    /**
     * Optional text field; null when absent or blank
     */
    private function body(array $data, string $name, bool $required): ?string
    {
        $value = $data[$name] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw new Exception($name . ' is required', 400);
            }
            return null;
        }
        if (!is_string($value)) {
            throw new Exception($name . ' must be a string', 400);
        }
        if (strlen($value) > self::BODY_MAX_BYTES) {
            throw new Exception(sprintf('%s exceeds %d bytes', $name, self::BODY_MAX_BYTES), 400);
        }
        return $value;
    }

    private function format(array $data): string
    {
        return isset($data['format'])
            ? $this->validator->validateFormat((string)$data['format'])
            : 'markdown';
    }

    /**
     * Due date as "Y-m-d H:i:s" or null; a date without time means end of day
     *
     * Like the staff UI, a due date must lie in the future.
     */
    private function dueDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new Exception('dueDate must be a string', 400);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 23:59:00';
        }
        $normalized = $this->validator->validateDueDate($value);
        if ($normalized !== null && strtotime($normalized) <= time()) {
            throw new Exception('dueDate must be in the future', 400);
        }
        return $normalized;
    }

    /**
     * @throws Exception 400 for fields this endpoint does not know
     */
    private function rejectUnknown(array $data, array $allowed): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown) {
            throw new Exception('Unknown fields: ' . implode(', ', $unknown), 400);
        }
    }

    private function flag(array $params, string $name): bool
    {
        $value = $params[$name] ?? false;
        return $value === true || in_array(strtolower((string)$value), ['1', 'true', 'yes'], true);
    }

    private function intParam(array $params, string $name, int $default, int $min, int $max): int
    {
        if (!isset($params[$name]) || $params[$name] === '') {
            return $default;
        }
        if (!is_numeric($params[$name]) || (int)$params[$name] != $params[$name]) {
            throw new Exception($name . ' must be an integer', 400);
        }
        return max($min, min($max, (int)$params[$name]));
    }

    private function errorText(array $errors): string
    {
        $parts = [];
        array_walk_recursive($errors, function ($message) use (&$parts) {
            $parts[] = strip_tags((string)$message);
        });
        return $parts ? implode(', ', $parts) : 'unknown error';
    }

    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
