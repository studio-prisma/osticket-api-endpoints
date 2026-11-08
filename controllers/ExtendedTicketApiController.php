<?php

declare(strict_types=1);

// Stub for external plugin class (Intelephense type-checking)
if (false) {
    /** @noinspection PhpMultipleClassDeclarationsInspection */
    class WildcardApiController {
        /** @return bool */
        public function requireApiKey() { return true; }
    }
}

// Only require if not in test environment
if (!class_exists('TicketApiController')) {
    require_once(INCLUDE_DIR . 'api.tickets.php');
}

// Load extracted services
require_once __DIR__ . '/../lib/Services/TicketValidatorService.php';
require_once __DIR__ . '/../lib/Services/PermissionChecker.php';
require_once __DIR__ . '/../lib/Services/TicketService.php';
require_once __DIR__ . '/../lib/Services/TaskService.php';
require_once __DIR__ . '/../lib/Services/KbService.php';
require_once __DIR__ . '/../lib/Enums/Permission.php';

/**
 * Extended Ticket API Controller
 *
 * Extends osTicket's TicketApiController with additional endpoints:
 * - GET/UPDATE/DELETE single tickets
 * - Search with filters, pagination, sorting
 * - Ticket statistics
 * - Subticket management
 *
 * Uses extracted services for validation, permission checking,
 * and ticket operations to follow Single Responsibility Principle.
 */
class ExtendedTicketApiController extends TicketApiController {

    private TicketValidatorService $validator;
    private PermissionChecker $permissionChecker;
    private TicketService $ticketService;

    /**
     * Flag to skip API key validation in create()
     * Set by handleTicketCreation() when it has already validated the key
     */
    private bool $skipApiKeyValidation = false;

    /**
     * Initialize controller with extracted services
     */
    public function __construct()
    {
        $this->validator = TicketValidatorService::getInstance();
        $this->permissionChecker = PermissionChecker::getInstance();
        $this->ticketService = TicketService::getInstance();
    }

    /**
     * Override API key validation to use Wildcard logic
     *
     * Uses WildcardApiController for validation if available,
     * otherwise falls back to standard ApiController
     */
    function requireApiKey() {
        // Try to use Wildcard API validation if plugin is available
        if (file_exists(INCLUDE_DIR . 'plugins/api-key-wildcard/api.wildcard.inc.php')) {
            require_once INCLUDE_DIR . 'plugins/api-key-wildcard/api.wildcard.inc.php';
            if (class_exists('WildcardApiController')) {
                $validator = new \WildcardApiController();
                return $validator->requireApiKey();
            }
        }

        // Fallback to standard API validation
        return parent::requireApiKey();
    }

    /**
     * Allow external callers to skip API key validation
     * Used by handleTicketCreation() after it has already validated the key
     */
    public function setSkipApiKeyValidation(bool $skip): void
    {
        $this->skipApiKeyValidation = $skip;
    }

    /**
     * Override create to conditionally skip API key validation
     *
     * Validates API key UNLESS handleTicketCreation() has already done it.
     * This prevents double-validation while maintaining security.
     */
    function create($format) {
        // Validate API key (unless already validated by handler)
        if (!$this->skipApiKeyValidation) {
            if (!($key=$this->requireApiKey()) || !$key->canCreateTickets()) {
                return $this->exerr(401, __('API key not authorized'));
            }
        }

        // Skip email format (not supported by our plugin)
        if (!strcasecmp($format, 'email')) {
            return parent::create($format);
        }

        // Get request body data
        // NOTE: We use $_POST directly because handleTicketCreation() has already
        // parsed the JSON body and merged it into $_POST. We cannot call getRequest()
        // again because php://input can only be read once!
        $data = $_POST;

        $ticket = $this->createTicket($data);

        if ($ticket) {
            /** @disregard P1013 (Plugin class may not exist in test environment) */
            $this->response(201, $ticket->getNumber());
        } else {
            $this->exerr(500, __("Unknown error"));
        }
    }

    /**
     * Override createTicket to intercept ticket AFTER creation
     *
     * This method is called by create() during ticket creation.
     * We use it to apply department/parent overrides that would otherwise
     * be overwritten by osTicket's internal rules (e.g., Topic -> Department).
     *
     * @param array $data The ticket data from $_POST
     * @param string $source The source (usually 'API')
     * @return Ticket|null The created ticket object
     */
    public function createTicket($data, $source = 'API'): ?Ticket
    {
        // Extract extended parameters that need to be applied AFTER creation
        // parentTicketNumber: User provides ticket NUMBER, we convert to ID
        $parentTicketNumber = $data['parentTicketNumber'] ?? null;
        $deptId = $data['deptId'] ?? null;

        // Remove all plugin-specific fields before passing to osTicket core.
        // This prevents "Unexpected data received in API request" warnings
        // from osTicket's validateRequestStructure().
        unset(
            $data['parentTicketNumber'],
            $data['deptId'],
            $data['departmentId'],
            $data['format'],
            $data['noteFormat'],
            $data['note'],
            $data['noteTitle']
        );

        // Call parent to create the ticket
        $ticket = parent::createTicket($data, $source);

        if (!$ticket) {
            error_log('[API-ENDPOINTS-ERROR] parent::createTicket() returned NULL!');
            return null;
        }

        // NOW apply our overrides using extracted validator
        try {
            // Set department if provided
            if ($deptId !== null) {
                $validatedDeptId = $this->validator->validateDepartmentId($deptId);
                if ($ticket->getDeptId() != $validatedDeptId) {
                    $ticket->setDeptId($validatedDeptId);
                    $ticket->save();
                }
            }

            // Set parent ticket if provided (convert NUMBER to ID)
            if ($parentTicketNumber !== null) {
                $parentId = $this->validator->validateParentTicketId($parentTicketNumber);
                if ($ticket->getPid() != $parentId) {
                    if (method_exists($ticket, 'setPid')) {
                        $ticket->setPid($parentId);
                        $ticket->save();
                    }
                }
            }
        } catch (Exception $e) {
            // Log error but don't fail the ticket creation
            error_log('[API-ENDPOINTS-ERROR] Failed to apply post-create updates: ' . $e->getMessage());
        }

        return $ticket;
    }

    /**
     * Update ticket with extended parameters
     *
     * Allows updating ticket properties that can't be set during creation
     * due to osTicket's internal rules (e.g., Topic overriding departmentId)
     *
     * `subject` renames the ticket. It is validated before any other change
     * (non-empty single line, no HTML, form-configured length) and logged as
     * an "edited" thread event, as in the staff UI.
     *
     * @param string $ticketNumber Ticket number
     * @param array $data Update data
     * @param bool $skipPermissionCheck Skip permission check (internal calls only)
     * @return Ticket Updated ticket object
     * @throws Exception If ticket not found or unauthorized
     */
    public function update(string $ticketNumber, array $data, bool $skipPermissionCheck = false): Ticket
    {
        // API key validation with permission check (unless skipped for internal calls)
        if (!$skipPermissionCheck) {
            $key = $this->requireApiKey();
            if (!$key) {
                throw new Exception('API key not authorized', 401);
            }

            // Check for UPDATE permission using PermissionChecker
            $this->permissionChecker->require($key, Permission::UpdateTickets, 'update tickets');
        }

        // Lookup ticket by number
        $ticket = Ticket::lookupByNumber($ticketNumber);
        if (!$ticket) {
            throw new Exception('Ticket not found', 404);
        }

        // Validate the subject before the first change, so a bad subject
        // does not leave the ticket half updated.
        $subject = array_key_exists('subject', $data)
            ? $this->validateSubject($data['subject'])
            : null;

        $updated = false;

        // Update subject if provided (answer of the ticket form, like the staff UI)
        if ($subject !== null && $subject !== (string)$ticket->getSubject()) {
            if ($this->setTicketSubject($ticket, $subject)) {
                $updated = true;
            }
        }

        // Update departmentId if provided
        if (isset($data['departmentId'])) {
            $deptId = $this->validator->validateDepartmentId($data['departmentId']);
            if ($ticket->getDeptId() != $deptId) {
                if ($ticket->setDeptId($deptId)) {
                    $updated = true;
                }
            }
        }

        // Update topicId (Help Topic) if provided
        if (isset($data['topicId'])) {
            $topicId = $this->validator->validateTopicId($data['topicId']);
            if ($ticket->getTopicId() != $topicId) {
                // Direct DB update via ht array (topic_id field)
                $ticket->ht['topic_id'] = $topicId;
                if ($ticket->save()) {
                    $updated = true;
                }
            }
        }

        // Update parentTicketNumber (set as subticket) if provided
        // User provides ticket NUMBER, we convert to internal ID
        if (isset($data['parentTicketNumber'])) {
            $parentId = $this->validator->validateParentTicketId($data['parentTicketNumber']);
            // Check if not already a child of this parent
            if ($ticket->getPid() != $parentId) {
                if (method_exists($ticket, 'setPid')) {
                    $ticket->setPid($parentId);
                    $ticket->save();
                    $updated = true;
                }
            }
        }

        // Update statusId if provided
        if (isset($data['statusId'])) {
            $statusId = $this->validator->validateStatusId($data['statusId']);
            if ($ticket->getStatusId() != $statusId) {
                if ($ticket->setStatus($statusId)) {
                    $updated = true;
                }
            }
        }

        // Update priorityId if provided. `priority` is the name documented in
        // openapi.yaml since 2.x and is accepted as an alias.
        $priorityInput = $data['priorityId'] ?? $data['priority'] ?? null;
        if ($priorityInput !== null) {
            $priorityId = $this->validator->validatePriorityId($priorityInput);
            if ((int)$ticket->getPriorityId() !== $priorityId) {
                if ($this->setTicketPriority($ticket, $priorityId)) {
                    $updated = true;
                }
            }
        }

        // Update slaId if provided
        if (isset($data['slaId'])) {
            $slaId = $this->validator->validateSlaId($data['slaId']);
            if ($ticket->getSLAId() != $slaId) {
                if ($ticket->setSLAId($slaId)) {
                    $updated = true;
                }
            }
        }

        // Update staffId (assign to staff) if provided
        if (isset($data['staffId'])) {
            $staffId = $this->validator->validateStaffId($data['staffId']);
            if ($ticket->getStaffId() != $staffId) {
                if ($ticket->setStaffId($staffId)) {
                    $updated = true;
                }
            }
        }

        // Update dueDate if provided
        if (array_key_exists('dueDate', $data)) {
            $validatedDueDate = $this->validator->validateDueDate($data['dueDate']);

            // Only update if different (normalize both for comparison)
            $currentDueDate = $ticket->getDueDate();
            if ($validatedDueDate !== $currentDueDate) {
                // osTicket uses VerySimpleModel ORM - must set as property, not via ht array
                // Setting $ticket->duedate marks it as "dirty" for save()
                $ticket->duedate = $validatedDueDate;

                // Clear overdue flag if setting a future due date
                if ($validatedDueDate !== null && $ticket->isOverdue()) {
                    $dueDateTimestamp = strtotime($validatedDueDate);
                    if ($dueDateTimestamp > time()) {
                        $ticket->clearOverdue(false);
                    }
                }

                if ($ticket->save()) {
                    $updated = true;
                }
            }
        }

        // Post internal note if provided (internal staff note)
        if (isset($data['note']) && trim($data['note']) !== '') {
            $errors = [];

            // Determine format (default: markdown)
            $format = $data['noteFormat'] ?? 'markdown';

            // CRITICAL: Set $_POST['format'] so Markdown-Support Plugin can intercept
            // The plugin listens to 'threadentry.created' signal and checks $_POST['format']
            $_POST['format'] = $format;
            $_POST['note'] = $data['note'];  // Also set note for newline restoration

            $noteVars = [
                'note' => $data['note'],
                'title' => $data['noteTitle'] ?? 'API Update',
                'format' => $format,
                'poster' => 'API',
                'staffId' => 0  // System/API
            ];

            // Convert data-URI attachments to format expected by postNote()
            // API sends: [{"filename": "data:mime;base64,content"}]
            // postNote() needs: [["name" => "filename", "type" => "mime", "data" => "content", "encoding" => "base64"]]
            // Same conversion as ApiJsonDataParser::fixup() does for ticket creation
            if (!empty($data['attachments']) && is_array($data['attachments'])) {
                $noteVars['attachments'] = self::convertDataUriAttachments($data['attachments']);
            }

            if ($ticket->postNote($noteVars, $errors, false, false)) {
                $updated = true;
            } else {
                throw new Exception('Failed to post note: ' . implode(', ', $errors), 400);
            }

            // Clean up $_POST after note creation
            unset($_POST['format'], $_POST['note']);
        }

        return $ticket;
    }

    /** Maximum reply size in bytes (thread entry body is a TEXT column) */
    public const REPLY_MAX_BYTES = 65535;

    /**
     * Post a public reply to the customer
     *
     * Unlike the internal note in update(), a reply is visible to the
     * customer and osTicket emails it to the recipients. It cannot be
     * recalled. That is why it needs its own permission (can_reply_tickets,
     * never implied by can_update_tickets) and an agent configured in the
     * plugin settings ("Reply as Agent"). The reply is posted as that agent,
     * with the same department, role and signature rules as in the staff UI.
     *
     * Markdown is not accepted: the Markdown Support plugin converts replies
     * for the email from $_POST at bootstrap, which a JSON request never
     * fills, so the customer would receive raw Markdown.
     *
     * @param string $ticketNumber Ticket number
     * @param array $data Reply data:
     *   - response  (string, required) Reply text
     *   - format    (string) 'text' (default) or 'html'
     *   - replyTo   (string) 'all' (default, owner + active collaborators) or 'user' (owner only)
     *   - signature (string) 'mine' (default), 'dept' or 'none'
     *   - alert     (bool)   Send the reply email (default true)
     * @return array Ticket number, thread entry ID and what was sent
     * @throws Exception 400 invalid input, 401 key or permission, 403 agent, 404 ticket
     */
    public function reply(string $ticketNumber, array $data): array
    {
        $key = $this->requireApiKey();
        if (!$key) {
            throw new Exception('API key not authorized', 401);
        }
        $this->permissionChecker->require($key, Permission::ReplyTickets, 'reply to customers');

        $ticket = Ticket::lookupByNumber($ticketNumber);
        if (!$ticket) {
            throw new Exception('Ticket not found', 404);
        }

        $text = $data['response'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new Exception('response is required', 400);
        }
        if (strlen($text) > self::REPLY_MAX_BYTES) {
            throw new Exception(sprintf('response exceeds %d bytes', self::REPLY_MAX_BYTES), 400);
        }

        $format = $this->replyOption($data, 'format', ['text', 'html'], 'text');
        $replyTo = $this->replyOption($data, 'replyTo', ['all', 'user'], 'all');
        $signature = $this->replyOption($data, 'signature', ['mine', 'dept', 'none'], 'mine');

        $alert = $data['alert'] ?? true;
        if (!is_bool($alert)) {
            throw new Exception('alert must be true or false', 400);
        }

        $staff = $this->resolveReplyAgent();
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_REPLY)) {
            throw new Exception('The reply agent is not allowed to reply to this ticket', 403);
        }

        $vars = [
            'response' => $format === 'html'
                ? new HtmlThreadEntryBody($text)
                : new TextThreadEntryBody($text),
            'reply-to' => $replyTo,
            'signature' => $signature,
            'staffId' => $staff->getId(),
            'poster' => $staff,
        ];

        // postReply() takes signature, sender name and template variables
        // from the global agent, like a reply typed in the staff UI.
        global $thisstaff;
        $previousStaff = $thisstaff;
        $thisstaff = $staff;
        $errors = [];
        try {
            // claim=false: an API reply must not assign the ticket
            $entry = $ticket->postReply($vars, $errors, $alert, false);
        } finally {
            $thisstaff = $previousStaff;
        }

        if (!$entry) {
            throw new Exception('Failed to post reply: ' . implode(', ', $errors), 400);
        }

        return [
            'ticket' => $ticket->getNumber(),
            'entryId' => (int)$entry->getId(),
            'alerted' => $alert,
            'replyTo' => $replyTo,
            'agent' => (string)$staff->getName(),
        ];
    }

    /**
     * Read an enumerated reply option, falling back to its default
     *
     * @throws Exception If the value is not one of $allowed (400)
     */
    private function replyOption(array $data, string $name, array $allowed, string $default): string
    {
        $value = $data[$name] ?? $default;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new Exception(sprintf('%s must be one of: %s', $name, implode(', ', $allowed)), 400);
        }
        return $value;
    }

    /**
     * Look up the agent replies are posted as ("Reply as Agent" setting)
     *
     * @throws Exception If no agent is configured or it is inactive (403)
     */
    private function resolveReplyAgent(): Staff
    {
        return $this->resolveConfiguredAgent(
            'reply_agent',
            'Customer replies are disabled: set "Reply as Agent" in the API Endpoints plugin settings',
            'The configured reply agent does not exist or is inactive'
        );
    }

    /**
     * Look up the agent tasks are changed as ("Tasks as Agent" setting)
     *
     * @throws Exception If no agent is configured or it is inactive (403)
     */
    private function resolveTaskAgent(): Staff
    {
        return $this->resolveConfiguredAgent(
            'task_agent',
            'Task changes are disabled: set "Tasks as Agent" in the API Endpoints plugin settings',
            'The configured task agent does not exist or is inactive'
        );
    }

    /**
     * Active agent stored (as username) in a plugin setting
     *
     * @throws Exception If the setting is empty or the agent unusable (403)
     */
    private function resolveConfiguredAgent(string $setting, string $disabled, string $unusable): Staff
    {
        $configured = '';
        if (class_exists('ApiEndpointsPlugin') && is_array(ApiEndpointsPlugin::$cached_config)) {
            $configured = trim((string)(ApiEndpointsPlugin::$cached_config[$setting] ?? ''));
        }
        if ($configured === '') {
            throw new Exception($disabled, 403);
        }

        $staff = Staff::lookup($configured);
        if (!$staff || !$staff->isActive()) {
            throw new Exception($unusable, 403);
        }
        return $staff;
    }

    // =========================================================================
    // Tasks
    // Reads need only the API key permission. Changes also run as the
    // "Tasks as Agent", whose role osTicket checks per department.
    // =========================================================================

    /**
     * Get a task with its thread
     *
     * @throws Exception 401 key/permission, 404 unknown task
     */
    public function getTask(string $number): array
    {
        $key = $this->requireApiKey();
        if (!$key) {
            throw new Exception('API key not authorized', 401);
        }
        $this->permissionChecker->require($key, Permission::ReadTasks, 'read tasks');

        return TaskService::getInstance()->getTask($number);
    }

    /**
     * Search tasks
     *
     * @throws Exception 401 key/permission, 400 invalid filter
     */
    public function searchTasks(array $params): array
    {
        $key = $this->requireApiKey();
        if (!$key) {
            throw new Exception('API key not authorized', 401);
        }
        $this->permissionChecker->require($key, Permission::SearchTasks, 'search tasks');

        return TaskService::getInstance()->searchTasks($params);
    }

    /**
     * Create a task
     *
     * @throws Exception 401 key/permission, 403 agent, 400 invalid input
     */
    public function createTask(array $data): array
    {
        $key = $this->requireApiKey();
        if (!$key) {
            throw new Exception('API key not authorized', 401);
        }
        $this->permissionChecker->require($key, Permission::CreateTasks, 'create tasks');

        return TaskService::getInstance()->createTask($this->resolveTaskAgent(), $data);
    }

    /**
     * Update a task
     *
     * @throws Exception 401 key/permission, 403 agent, 404 task, 400 invalid input
     */
    public function updateTask(string $number, array $data): array
    {
        $key = $this->requireApiKey();
        if (!$key) {
            throw new Exception('API key not authorized', 401);
        }
        $this->permissionChecker->require($key, Permission::UpdateTasks, 'update tasks');

        return TaskService::getInstance()->updateTask($this->resolveTaskAgent(), $number, $data);
    }

    /**
     * Delete a task
     *
     * @throws Exception 401 key/permission, 403 agent, 404 task
     */
    public function deleteTask(string $number, ?string $comments = null): array
    {
        $key = $this->requireApiKey();
        if (!$key) {
            throw new Exception('API key not authorized', 401);
        }
        $this->permissionChecker->require($key, Permission::DeleteTasks, 'delete tasks');

        return TaskService::getInstance()->deleteTask($this->resolveTaskAgent(), $number, $comments);
    }

    // =========================================================================
    // Knowledge base (FAQ articles and categories)
    // Two API key permissions: can_read_kb, can_write_kb. FAQs have no
    // department, so no agent is involved. Nothing is ever deleted.
    // =========================================================================

    /**
     * @throws Exception 401 key/permission
     */
    private function requireKbPermission(Permission $permission): void
    {
        $key = $this->requireApiKey();
        if (!$key) {
            throw new Exception('API key not authorized', 401);
        }
        $this->permissionChecker->require($key, $permission);
    }

    public function listKbCategories(): array
    {
        $this->requireKbPermission(Permission::ReadKb);
        return KbService::getInstance()->listCategories();
    }

    public function createKbCategory(array $data): array
    {
        $this->requireKbPermission(Permission::WriteKb);
        return KbService::getInstance()->createCategory($data);
    }

    public function updateKbCategory(string $id, array $data): array
    {
        $this->requireKbPermission(Permission::WriteKb);
        return KbService::getInstance()->updateCategory($id, $data);
    }

    public function searchKbArticles(array $params): array
    {
        $this->requireKbPermission(Permission::ReadKb);
        return KbService::getInstance()->searchArticles($params);
    }

    public function getKbArticle(string $id): array
    {
        $this->requireKbPermission(Permission::ReadKb);
        return KbService::getInstance()->getArticle($id);
    }

    public function createKbArticle(array $data): array
    {
        $this->requireKbPermission(Permission::WriteKb);
        return KbService::getInstance()->createArticle($data);
    }

    public function updateKbArticle(string $id, array $data): array
    {
        $this->requireKbPermission(Permission::WriteKb);
        return KbService::getInstance()->updateArticle($id, $data);
    }

    /**
     * Set the ticket priority.
     *
     * osTicket has no Ticket::setPriority(). The priority is the answer to the
     * built-in "priority" field of the ticket form, so it is written the same
     * way core does it on ticket creation: setAnswer() on the form entry, then
     * save(), which also refreshes the ticket__cdata search view.
     *
     * @throws Exception If the ticket has no priority field (400)
     */
    private function setTicketPriority(Ticket $ticket, int $priorityId): bool
    {
        foreach (DynamicFormEntry::forTicket($ticket->getId()) as $form) {
            if (!$form->getAnswer('priority')) {
                continue;
            }
            $form->setAnswer('priority', null, $priorityId);
            $saved = (bool)$form->save();
            $ticket->loadDynamicData(true);

            return $saved;
        }

        throw new Exception('Ticket has no priority field', 400);
    }

    /** Subject length when the ticket form does not configure one */
    public const SUBJECT_DEFAULT_LENGTH = 255;

    /**
     * Validated subject: a non-empty single line of plain text
     *
     * The limit is the length configured on the subject field of the ticket
     * form (Admin > Manage > Forms > Ticket Details), so the API accepts
     * what the staff UI accepts. HTML is refused rather than stripped: the
     * caller should see that the subject would not be stored as sent.
     *
     * @throws Exception 400 on invalid input
     */
    public function validateSubject(mixed $subject): string
    {
        if (!is_string($subject)) {
            throw new Exception('subject must be a string', 400);
        }
        $subject = trim($subject);
        if ($subject === '') {
            throw new Exception('subject must not be empty', 400);
        }
        if (!mb_check_encoding($subject, 'UTF-8')) {
            throw new Exception('subject must be valid UTF-8', 400);
        }
        if (preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $subject)) {
            throw new Exception('subject must be a single line without control characters', 400);
        }
        if (strip_tags($subject) !== $subject) {
            throw new Exception('subject must not contain HTML', 400);
        }
        $max = $this->subjectMaxLength();
        if (mb_strlen($subject) > $max) {
            throw new Exception(sprintf('subject exceeds %d characters', $max), 400);
        }
        return $subject;
    }

    /**
     * Length configured on the ticket form's subject field
     */
    private function subjectMaxLength(): int
    {
        if (class_exists('TicketForm')
            && ($form = TicketForm::getInstance())
            && ($field = $form->getField('subject'))
        ) {
            $config = $field->getConfiguration();
            if (!empty($config['length']) && (int)$config['length'] > 0) {
                return (int)$config['length'];
            }
        }
        return self::SUBJECT_DEFAULT_LENGTH;
    }

    /**
     * Set the ticket subject
     *
     * The subject is the answer to the built-in "subject" field of the ticket
     * form and is mirrored into ticket__cdata. Saving the form entry fires
     * the signal that refreshes that view. Like Ticket::updateField() in the
     * staff UI, the change is logged as an "edited" thread event with the old
     * and the new value, and the ticket's last update is set.
     *
     * @throws Exception If the ticket has no subject field (400)
     */
    private function setTicketSubject(Ticket $ticket, string $subject): bool
    {
        $previous = (string)$ticket->getSubject();
        foreach (DynamicFormEntry::forTicket($ticket->getId()) as $form) {
            if (!($field = $form->getField('subject')) || !$form->getAnswer('subject')) {
                continue;
            }
            $form->setAnswer('subject', $subject);
            // saveAnswers() returns false on failure, else the number of saved answers
            if ($form->save() === false) {
                throw new Exception('Failed to save subject', 500);
            }
            $ticket->loadDynamicData(true);
            $ticket->logEvent('edited', ['fields' => [$field->get('id') => [$previous, $subject]]], 'API');
            $ticket->lastupdate = SqlFunction::NOW();
            $ticket->save();

            return true;
        }

        throw new Exception('Ticket has no subject field', 400);
    }

    // =========================================================================
    // Attachment Helpers
    // =========================================================================

    /**
     * Convert data-URI attachments to the format AttachmentFile::create() expects.
     *
     * API sends:  [{"filename.txt": "data:text/plain;base64,SGVsbG8="}]
     * postNote needs: [["name" => "filename.txt", "type" => "text/plain",
     *                   "data" => "SGVsbG8=", "encoding" => "base64"]]
     *
     * For ticket creation, ApiJsonDataParser::fixup() does this conversion
     * internally. For notes we must do it ourselves.
     *
     * @param array $attachments Data-URI formatted attachments from API
     * @return array Attachments in osTicket internal format
     */
    private static function convertDataUriAttachments(array $attachments): array
    {
        $converted = [];
        foreach ($attachments as $info) {
            if (!is_array($info)) {
                continue;
            }
            // Each element is {"filename": "data:mime;base64,content"}
            $dataUri = reset($info);
            $filename = key($info);
            if (!$dataUri || !$filename || !is_string($dataUri)) {
                continue;
            }

            // Parse RFC 2397 data URI manually
            // Format: data:[<mediatype>][;base64],<data>
            if (!preg_match('#^data:([^;,]+)?(?:;(base64))?,(.*)$#s', $dataUri, $m)) {
                continue;
            }

            $entry = [
                'name' => $filename,
                'type' => $m[1] ?: 'application/octet-stream',
                'data' => $m[3],
            ];

            // Tell AttachmentFile::create() to base64-decode the data
            if (!empty($m[2])) {
                $entry['encoding'] = 'base64';
            }

            $converted[] = $entry;
        }
        return $converted;
    }

    // =========================================================================
    // Validation Delegation Methods (backward compatibility)
    // All validation logic has been extracted to TicketValidatorService
    // =========================================================================

    /**
     * Validate format parameter
     * @deprecated Use TicketValidatorService::getInstance()->validateFormat() directly
     */
    public function validateFormat(?string $format): string
    {
        return $this->validator->validateFormat($format);
    }

    /**
     * Check if Markdown Support Plugin is active
     * @deprecated Use TicketValidatorService::getInstance()->isMarkdownPluginActive() directly
     */
    public function isMarkdownPluginActive(): bool
    {
        return $this->validator->isMarkdownPluginActive();
    }

    /**
     * Validate department ID
     * @deprecated Use TicketValidatorService::getInstance()->validateDepartmentId() directly
     */
    public function validateDepartmentId(int|string $deptId): int
    {
        return $this->validator->validateDepartmentId($deptId);
    }

    /**
     * Validate parent ticket ID (for subtickets)
     * @deprecated Use TicketValidatorService::getInstance()->validateParentTicketId() directly
     */
    public function validateParentTicketId(int|string $parentId): int
    {
        return $this->validator->validateParentTicketId($parentId);
    }

    /**
     * Validate topic ID (Help Topic)
     * @deprecated Use TicketValidatorService::getInstance()->validateTopicId() directly
     */
    public function validateTopicId(int|string $topicId): int
    {
        return $this->validator->validateTopicId($topicId);
    }

    /**
     * Validate status ID
     * @deprecated Use TicketValidatorService::getInstance()->validateStatusId() directly
     */
    public function validateStatusId(int|string $statusId): int
    {
        return $this->validator->validateStatusId($statusId);
    }

    /**
     * Validate SLA ID
     * @deprecated Use TicketValidatorService::getInstance()->validateSlaId() directly
     */
    public function validateSlaId(int|string $slaId): int
    {
        return $this->validator->validateSlaId($slaId);
    }

    /**
     * Validate staff ID
     * @deprecated Use TicketValidatorService::getInstance()->validateStaffId() directly
     */
    public function validateStaffId(int|string $staffId): int
    {
        return $this->validator->validateStaffId($staffId);
    }

    // =========================================================================
    // Permission Check Delegation Methods (backward compatibility)
    // All permission logic has been extracted to PermissionChecker
    // =========================================================================

    /**
     * Check if API key has UPDATE permission
     * @deprecated Use PermissionChecker::getInstance()->require() directly
     */
    private function requireUpdatePermission($key): void
    {
        $this->permissionChecker->require($key, Permission::UpdateTickets, 'update tickets');
    }

    /**
     * Check if API key has READ permission
     * @deprecated Use PermissionChecker::getInstance()->require() directly
     */
    private function requireReadPermission($key, string $context = 'tickets'): void
    {
        $this->permissionChecker->require($key, Permission::ReadTickets, $context);
    }

    /**
     * Check if API key has STATS permission
     * @deprecated Use PermissionChecker::getInstance()->require() directly
     */
    private function requireStatsPermission($key): void
    {
        $this->permissionChecker->require($key, Permission::ReadStats, 'ticket statistics');
    }

    /**
     * Check if API key has SEARCH permission
     * @deprecated Use PermissionChecker::getInstance()->require() directly
     */
    private function requireSearchPermission($key): void
    {
        $this->permissionChecker->require($key, Permission::SearchTickets, 'search tickets');
    }

    /**
     * Check if API key has DELETE permission
     * @deprecated Use PermissionChecker::getInstance()->require() directly
     */
    private function requireDeletePermission($key): void
    {
        $this->permissionChecker->require($key, Permission::DeleteTickets, 'delete tickets');
    }

    /**
     * Get ticket by number with full details including thread/messages
     *
     * @param string|int $ticketIdentifier Ticket number (e.g. ABC-123-456) or ID
     * @return array Ticket data with all messages
     * @throws Exception if ticket not found or permission denied
     */
    public function getTicket(string|int $ticketIdentifier): array
    {
        // Get API key and check READ permission
        $key = $this->requireApiKey();
        $this->requireReadPermission($key);

        // Delegate to TicketService
        return $this->ticketService->getTicket($ticketIdentifier);
    }

    /**
     * Download attachment content by file ID
     *
     * Returns base64-encoded file content with metadata.
     * Security: Validates API key has read permission and verifies
     * the file belongs to a ticket attachment (not arbitrary file access).
     *
     * @param int $fileId The file ID from ost_file table
     * @return array {file_id, filename, mime_type, size, content (base64)}
     * @throws Exception 401 if unauthorized
     * @throws Exception 403 if file not linked to a ticket attachment
     * @throws Exception 404 if file not found
     */
    public function downloadAttachment(int $fileId): array
    {
        $key = $this->requireApiKey();
        $this->requireReadPermission($key, 'attachments');

        require_once __DIR__ . '/../lib/Services/AttachmentService.php';
        $attachmentService = AttachmentService::getInstance();

        return $attachmentService->downloadAttachment($fileId);
    }

    /**
     * Delete a ticket and all associated data
     *
     * @param string|int $ticketNumber Ticket number or internal ticket ID
     * @return string Deleted ticket number
     * @throws Exception 401 if API key lacks delete permission
     * @throws Exception 404 if ticket not found
     * @throws Exception 500 if deletion fails
     */
    public function deleteTicket(string|int $ticketNumber): string
    {
        try {
            // API key validation with permission check
            $key = $this->requireApiKey();
            if (!$key) {
                throw new Exception('API key not authorized', 401);
            }

            $this->requireDeletePermission($key);

            // Delegate to TicketService
            return $this->ticketService->deleteTicket($ticketNumber);
        } catch (Exception $e) {
            // Re-throw known exceptions (401, 404) without wrapping
            if (in_array($e->getCode(), [401, 404], true)) {
                throw $e;
            }

            error_log('[API-ENDPOINTS-ERROR] Delete failed for ticket ' . $ticketNumber . ': ' . $e->getMessage());
            throw new Exception('Failed to delete ticket: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get comprehensive ticket statistics
     *
     * @return array Statistics data structure
     * @throws Exception with code 401 if API key not authorized
     * @throws Exception with code 500 if stats aggregation fails
     */
    public function getTicketStats(): array
    {
        try {
            $key = $this->requireApiKey();
            if (!$key) {
                throw new Exception('API key not authorized', 401);
            }
            $this->requireStatsPermission($key);

            // Delegate to TicketService
            return $this->ticketService->getTicketStats();
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                throw $e;
            }

            error_log('[API-ENDPOINTS-ERROR] Stats aggregation failed: ' . $e->getMessage());
            throw new Exception('Failed to retrieve ticket statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search tickets with filters, pagination and sorting
     *
     * @param array $params Search parameters
     * @return array Array of tickets (without thread entries for performance)
     * @throws Exception if permission denied
     */
    public function searchTickets(array $params): array
    {
        // Get API key and check SEARCH permission
        $key = $this->requireApiKey();
        $this->requireSearchPermission($key);

        // Delegate to TicketService
        return $this->ticketService->searchTickets($params);
    }

    /**
     * Get all ticket statuses from database
     *
     * @return array Array of status objects sorted by sort order
     * @throws Exception with code 401 if API key not authorized
     * @throws Exception with code 500 if database query fails
     */
    public function getTicketStatuses(): array
    {
        try {
            $key = $this->requireApiKey();
            if (!$key) {
                throw new Exception('API key not authorized', 401);
            }
            $this->requireStatsPermission($key);

            // Delegate to TicketService
            return $this->ticketService->getTicketStatuses();
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                throw $e;
            }

            error_log('[API-ENDPOINTS-ERROR] Status lookup failed: ' . $e->getMessage());
            throw new Exception('Failed to retrieve ticket statuses: ' . $e->getMessage(), 500);
        }
    }
}
