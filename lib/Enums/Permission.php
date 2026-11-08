<?php
/**
 * Permission Enum
 *
 * Defines API key permission types for the extended API endpoints.
 * Maps to database columns in ost_api_key table.
 */

declare(strict_types=1);

enum Permission: string
{
    case CreateTickets = 'can_create_tickets';
    case UpdateTickets = 'can_update_tickets';
    case ReadTickets = 'can_read_tickets';
    case SearchTickets = 'can_search_tickets';
    case DeleteTickets = 'can_delete_tickets';
    case ReadStats = 'can_read_stats';
    case ManageSubtickets = 'can_manage_subtickets';
    case ReplyTickets = 'can_reply_tickets';
    case CreateTasks = 'can_create_tasks';
    case UpdateTasks = 'can_update_tasks';
    case ReadTasks = 'can_read_tasks';
    case SearchTasks = 'can_search_tasks';
    case DeleteTasks = 'can_delete_tasks';
    case ReadKb = 'can_read_kb';
    case WriteKb = 'can_write_kb';

    /**
     * Get human-readable label for this permission
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::CreateTickets => 'Create Tickets',
            self::UpdateTickets => 'Update Tickets',
            self::ReadTickets => 'Read Tickets',
            self::SearchTickets => 'Search Tickets',
            self::DeleteTickets => 'Delete Tickets',
            self::ReadStats => 'Read Statistics',
            self::ManageSubtickets => 'Manage Subtickets',
            self::ReplyTickets => 'Reply to Customers',
            self::CreateTasks => 'Create Tasks',
            self::UpdateTasks => 'Update Tasks',
            self::ReadTasks => 'Read Tasks',
            self::SearchTasks => 'Search Tasks',
            self::DeleteTasks => 'Delete Tasks',
            self::ReadKb => 'Read Knowledge Base',
            self::WriteKb => 'Change Knowledge Base',
        };
    }

    /**
     * Get associated API endpoint description
     *
     * @return string
     */
    public function endpoint(): string
    {
        return match ($this) {
            self::CreateTickets => 'POST /tickets',
            self::UpdateTickets => 'PATCH /tickets/:id',
            self::ReadTickets => 'GET /tickets/:number',
            self::SearchTickets => 'GET /tickets/search',
            self::DeleteTickets => 'DELETE /tickets/:number',
            self::ReadStats => 'GET /tickets-stats',
            self::ManageSubtickets => 'Subticket Operations',
            self::ReplyTickets => 'POST /tickets-reply/:number',
            self::CreateTasks => 'POST /tickets-tasks-create',
            self::UpdateTasks => 'PATCH /tickets-tasks-update/:number',
            self::ReadTasks => 'GET /tickets-tasks-get/:number',
            self::SearchTasks => 'GET /tickets-tasks-search',
            self::DeleteTasks => 'DELETE /tickets-tasks-delete/:number',
            self::ReadKb => 'GET /kb-categories-list, /kb-faq-search, /kb-faq-get/:id',
            self::WriteKb => 'POST/PATCH /kb-categories-*, /kb-faq-create, /kb-faq-update/:id',
        };
    }

    /**
     * Get error message for unauthorized access
     *
     * @param string $context Additional context (e.g., "tickets", "statistics")
     * @return string
     */
    public function unauthorizedMessage(string $context = 'this operation'): string
    {
        return sprintf('API key not authorized to %s', match ($this) {
            self::CreateTickets => 'create tickets',
            self::UpdateTickets => 'update tickets',
            self::ReadTickets => "read {$context}",
            self::SearchTickets => 'search tickets',
            self::DeleteTickets => 'delete tickets',
            self::ReadStats => 'read ticket statistics',
            self::ManageSubtickets => 'manage subtickets',
            self::ReplyTickets => 'reply to customers',
            self::CreateTasks => 'create tasks',
            self::UpdateTasks => 'update tasks',
            self::ReadTasks => 'read tasks',
            self::SearchTasks => 'search tasks',
            self::DeleteTasks => 'delete tasks',
            self::ReadKb => 'read the knowledge base',
            self::WriteKb => 'change the knowledge base',
        });
    }

    /**
     * Get all permission column names
     *
     * @return array<string>
     */
    public static function columnNames(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get fallback permission (if any)
     *
     * For some permissions, a more general permission may grant access.
     * This matches the original fallback behavior in the controller:
     * - SearchTickets falls back to ReadTickets
     * - ReadStats falls back to ReadTickets
     * - SearchTasks falls back to ReadTasks
     *
     * @return self|null
     */
    public function fallback(): ?self
    {
        return match ($this) {
            self::SearchTickets => self::ReadTickets,
            self::ReadStats => self::ReadTickets,
            self::SearchTasks => self::ReadTasks,
            default => null,
        };
    }
}
