# Changelog - api-endpoints

All notable changes to this plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Nothing yet

### Changed
- Nothing yet

### Deprecated
- Nothing yet

### Removed
- Nothing yet

### Fixed
- Nothing yet

### Security
- Nothing yet

## [2.8.0] - 2026-11-08

### Added
- Knowledge base endpoints for FAQ categories and articles. There is no delete; hiding an article means setting it to `internal`.
  - `GET /api/kb-categories-list.php`: all categories with parent, visibility and article count.
  - `POST /api/kb-categories-create.php`, `PATCH /api/kb-categories-update.php/{id}.json`: name, description, notes, parent, visibility. A category cannot become its own parent or move below one of its subcategories.
  - `GET /api/kb-faq-search.php`: text, category, visibility, slug; paging.
  - `GET /api/kb-faq-get.php/{id}.json`: article with answer (HTML), notes and help topics.
  - `POST /api/kb-faq-create.php`, `PATCH /api/kb-faq-update.php/{id}.json`: title, answer, category, notes, visibility (`internal`, `public`, `featured`). Fields left out keep their value; help topics and attachments stay linked.
- Changes go through osTicket's `FAQ::update()` and `Category::update()`, so the answer is sanitized like in the staff UI and duplicate titles/names are refused. New articles and categories are internal unless `visibility` is set.
- Article responses report `visibleInPortal` (article and category public), and `slug`/`publicUrl` for friendly `/kb/<slug>` URLs. The slugs are read from the `RewriteRule ^kb/<slug>$ kb/faq.php?id=<ID>` lines of the root `.htaccess`; the file is never written.
- Two API key permissions, off by default: `can_read_kb`, `can_write_kb`. Write does not imply read.

### Fixed
- Updating the plugin left the task rewrite rules behind in `api/.htaccess`: removing the old rule block stopped at the subticket unlink rule. The template now ends with an explicit end marker, and leftover plugin rules from earlier updates are removed.

## [2.7.0] - 2026-11-07

### Added
- `PATCH /api/tickets-update.php/{number}.json` accepts `subject` to rename a ticket. The subject is written as the answer of the ticket form's subject field, so `ticket__cdata` and the subject search follow. The change is logged as an "edited" thread event with the old and the new subject, like an edit in the staff UI. An unchanged subject is skipped.
- Validation, before any other field of the request is changed: string, trimmed, not empty, valid UTF-8, a single line without control characters, no HTML (refused, not stripped), at most the length configured on the subject field of the ticket form (255 if none). Errors are answered with 400.


### Added
- Task endpoints for osTicket tasks, standalone or linked to a ticket:
  - `GET /api/tickets-tasks-get.php/{number}.json`: task with description, internal notes and updates.
  - `GET /api/tickets-tasks-search.php`: filter by ticket, status, department, agent, team, unassigned, overdue, standalone, title/number; paging and sorting.
  - `POST /api/tickets-tasks-create.php`: title, description, ticket, department, agent or team, due date. Returns 201 with the task.
  - `PATCH /api/tickets-tasks-update.php/{number}.json`: title, link/move/unlink ticket, due date, transfer, assign/unassign, close/reopen, internal note, update. All input and permissions are checked before the first change; the response lists what changed.
  - `DELETE /api/tickets-tasks-delete.php/{number}.json`: deletes the task with its thread.
- Five new permissions, one checkbox each: `can_create_tasks`, `can_update_tasks`, `can_read_tasks`, `can_search_tasks` (falls back to read), `can_delete_tasks`. Columns are added on update. Ticket permissions do not grant task access.
- New setting "Tasks as Agent", separate from "Reply as Agent". Changes run as this agent, so osTicket applies its role per department, logs the same events and sends the same agent alerts as the staff UI. "Off" disables all task changes; reading needs only the key permission.
- Linking, moving, unlinking, creating and deleting a task leaves an internal note on the affected tickets.
- `ApiBootstrap::execute()` takes an optional success status code.

### Fixed
- `isMarkdownPluginActive()` threw an `ArgumentCountError` on osTicket 1.18 when the Markdown Support plugin was missing (`PluginManager::getInstance()` needs an argument there). It now returns false.
- A JSON request with an empty body failed with "Syntax error" even where the body is optional. A JSON scalar as body now gets a 400 instead of a server error.

### Security
- PHP runtime errors (`TypeError`, `ArgumentCountError`, ...) are answered with a generic 500. Their messages contained server paths and were sent to the client as 400. The details go to the PHP error log.

## [2.5.1] - 2026-11-01

### Changed
- "Reply as Agent" is a dropdown of the active agents instead of a text field, with "Off" as the empty choice. It still stores the username, so a username typed into 2.5.0 keeps working. The agent list is loaded only when the settings form is shown or saved, not on every request.

## [2.5.0] - 2026-11-01

### Added
- `POST /api/tickets-reply.php/{number}.json` posts a public reply to the customer. osTicket emails it to the recipients like a reply from the staff UI: department template, signature, sender name. Options: `format` (`text` or `html`), `replyTo` (`all` or `user`), `signature` (`mine`, `dept` or `none`), `alert` (`false` posts without email). Returns JSON with ticket number, thread entry ID and the agent name.
- New permission `can_reply_tickets` (column added on update, checkbox in the plugin's permission table). `can_update_tickets` does not imply it.
- New setting "Reply as Agent": username, email or ID of the agent replies are posted as. Empty keeps the endpoint off. The agent must be active and allowed to reply in the ticket's department, otherwise 403.

### Security
- A reply email cannot be recalled, so the endpoint is off by default twice: no API key has the permission, and no agent is set. Replies never assign the ticket (`claim=false`) and are capped at 65535 bytes. Markdown is rejected because the Markdown Support plugin does not convert JSON requests for the email, and the customer would receive raw Markdown.

## [2.4.1] - 2026-10-31

### Fixed
- Ticket update ignored the priority. `openapi.yaml` documented a `priority` field, but `update()` had no branch for it, so the request succeeded and the priority stayed unchanged. The update now accepts `priorityId` (name or ID, e.g. `High` or `4`) and `priority` as alias, writes it through the ticket form like osTicket core does, and refreshes the search view. Unknown names return 404, a ticket form without priority field returns 400.

## [2.4.0] - 2026-10-27

Release of the studio-prisma fork on top of upstream [v2.4.0](https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.4.0). Upstream now contains all fixes from this fork, including the 2.3.5 security fix (upstream #11, #13). The plugin code matches upstream v2.4.0; the fork adds the repository URL, packaging and CI below.

### Added
- `email` filter on `/api/tickets-search.php` (upstream #7)
- `tools/build-zip.sh` builds the install package from `.releaseinclude`: reproducible, refuses missing paths, uncovered PHP files, development directories and literal secrets
- `Release` workflow: on a `v*` tag it checks that `plugin.php` matches the tag, builds the package, takes the notes from this file, attaches `api-endpoints-vX.Y.Z.zip` and a signed build-provenance attestation
- Dependency review on pull requests

### Changed
- `plugin.php` `url` and the README download link point to this fork and its releases
- CI workflow token is read-only; dev dependencies resolve against PHP 8.2, PHPUnit 11.5.57 (development only, not part of the package)
- README credits contributors (upstream #14)

### Fixed
- Subticket endpoints: resolve the Subticket Manager plugin via `PluginManager` and stop the infinite `getApiKey()` recursion when `api-key-wildcard` is absent (upstream #10, issue #12)
- Bootstrap catches `Throwable` instead of `Exception` (upstream #9)
- Statistics: cast the staff name to string before `strcmp` (upstream #8)

### Security
- Ticket creation authenticates before anything else (upstream #13, already in fork 2.3.5)

## [2.3.5] - 2026-10-26

### Security
- `POST /api/tickets.json` created tickets without an API key. The plugin's route replaces osTicket's own ticket-creation route, and `handleTicketCreation()` called `create()` with the key check skipped (`setSkipApiKeyValidation(true)`) although it had never checked the key itself. The key and its `can_create_tickets` permission are now checked first, before any parameter is validated, so an unauthenticated caller also no longer learns from the validation errors which departments or ticket numbers exist. Regression test: `tests/Unit/TicketCreationAuthTest.php`.

## [2.3.4] - 2026-03-05

### Fixed
- prevent ApiEndpointsConfig class redeclaration in file.php

## [2.3.3] - 2026-03-04

### Changed
- add attachment download endpoint to OpenAPI spec

### Fixed
- trim whitespace in department name comparison
- resolve sub-department name lookup failure
- resolve departmentId "Unexpected data" error on ticket creation

## [2.3.2] - 2026-03-03

### Fixed
- use ORM property access for attachment inline flag

## [2.3.1] - 2026-03-03

### Fixed
- use direct iteration instead of getSeparates() for thread attachments

## [2.3.0] - 2026-03-03

### Added
- add attachment download endpoint and metadata in thread entries

### Changed
- add OpenAPI 3.1 specification for all API endpoints

## [2.2.2] - 2026-02-22

### Fixed
- return structured JSON error responses instead of text/plain

## [2.2.1] - 2026-02-21

### Fixed
- convert data-URI attachments for postNote() compatibility

## [2.2.0] - 2026-02-21

### Added
- pass attachments to notes in update endpoint

### Changed
- update README for clarity and remove outdated API documentation

## [2.1.2] - 2025-12-25

### Fixed
- clear overdue flag when setting future due date

## [2.1.1] - 2025-12-25

### Fixed
- use ORM property setter instead of ht array

## [2.1.0] - 2025-12-25

### Added
- Add dueDate parameter to ticket update endpoint

### Changed
- Add .phpunit.cache/ to gitignore

### Fixed
- Initialize $ht property in mock Ticket class

## [2.0.2] - 2025-12-17

### Fixed
- Handle null return from Osticket::get_path_info()

## [2.0.1] - 2025-12-17

### Fixed
- Rename Format enum to MessageFormat to avoid osTicket conflict
- Load main.inc.php directly in API files for correct osTicket bootstrap
- Use SCRIPT_FILENAME for main.inc.php path calculation
- Correct dirname level to 4 for main.inc.php path
- Correct dirname level in ApiBootstrap for osTicket main.inc.php

## [2.0.0] - 2025-12-17

### Changed
- Update PHP requirement to 8.1+ and test count to 168
- update package validation for new services and enums
- extract TicketService for CRUD operations
- extract validation & permission logic into services
- Extract ApiBootstrap and modernize to PHP 8.1+
- added zip to .gitignore
- Auto-sync from Markus-PC

### Fixed
- Update CI matrix to PHP 8.2+ (PHPUnit 11 requirement)
- Restore original composer.json for PHP 7.4+ compatibility
- Restore original composer.lock for PHP 8.1 compatibility

## [1.0.0] - 2025-11-08

### Added
- Add XML support to tickets-stats.php and tickets-statuses.php
- Add test infrastructure for integration tests
- Register DELETE Unlink Subticket endpoint in deployment system
- Implement DELETE Unlink Subticket endpoint
- Register POST Create Subticket Link endpoint in deployment system
- Implement POST Create Subticket Link with department authorization
- Register GET List Children endpoint in deployment system
- Implement GET List Children endpoint with DRY refactoring
- Register GET Parent Endpoint in deployment system
- Add subticket permission system and controller foundation
- Add tickets-statuses API endpoint for dynamic status lookup

### Changed
- Reset CHANGELOG.md für Release 1.0.0
- Add GitHub Actions workflow for automated testing
- Remove all debug logging from production code
- Remove test script with API key from git tracking
- Add cleanup step before CREATE subticket tests
- Change subticket endpoints to use ticket numbers instead of IDs
- Bump version to 1.2.1 for deployment
- Simplify SubticketApiController by removing plugin checks
- Replace dynamic .htaccess generation with static template
- debug: write to file instead of error_log to bypass config issues
- debug: add logging to tickets-get.php to diagnose wildcard routing
- debug: add constructor logging to track class instantiation
- debug: test both pre_uninstall AND uninstall hooks
- debug: add test logs to identify which hook is actually called
- cleanup only on uninstall, not on disable
- Remove redundant permission note and highlight important message
- Replace endpoint enable checkboxes with info section
- Add debug logging for subticket checkbox visibility issue
- Make API file deployment and .htaccess management dynamic
- Add comprehensive subticket API documentation
- Apply code review fixes from agent reviews
- Add SubticketPluginUnavailableTest integration tests
- Add SubticketWorkflowTest integration tests
- Add RED phase tests for DELETE Unlink Subticket endpoint
- Add RED phase tests for POST Create Subticket Link endpoint
- Add RED phase tests for GET List Children endpoint
- Add RED phase tests for GET Parent Ticket endpoint
- Add CLAUDE.md to .gitignore
- Initial repository setup for API Endpoints Plugin

### Fixed
- Behebe verbleibende 11 Test-Failures (11 → 0)
- Implement plugin availability control for tests (27 → 16 failures)
- Resolve all test errors (22 → 0) by fixing ID/Number confusion
- Pin doctrine/instantiator to ^1.5 for PHP 7.4 compatibility
- Improve test infrastructure and controller error handling
- Use HTTP 422 instead of 409 for duplicate relationships
- Use array data from getChildren instead of Ticket lookup
- Load SubticketPlugin class in all subticket API endpoints
- Use direct SubticketPlugin instantiation instead of PluginManager
- Use correct Subticket Manager Plugin method signatures
- Use correct unlinkTicket method instead of non-existent removeLink
- Add shutdown handler workaround for removeLink exit issue
- Correct PATH_INFO format in test URLs (use /.json and /.xml instead of .json and .xml)
- Resolve HTTP 500 errors in topicId update and parent lookup
- Always replace .htaccess rules on plugin enable to apply template updates
- Allow PATH_INFO suffix in .htaccess rules for stats/statuses/search
- Change getApiKey() visibility from private to protected
- Remove trailing slash from tickets-search.php .htaccess rule
- Add missing files to .releaseinclude for deployment
- Correct TEST_CURL_COMMANDS.sh BASE_URL for direct API access
- correct .htaccess trailing slash logic for subticket endpoints
- move cleanup to enable() - osTicket doesn't call uninstall hooks
- use pre_uninstall hook instead of uninstall for cleanup
- remove debug logging and make installed_version field visible
- remove obsolete endpoint validation from pre_save
- add debug logging to cleanup methods for troubleshooting
- Cleanup works now even after plugin deletion
- Simplify subticket checkbox rendering - always show without plugin check
- Show can_manage_subtickets checkbox even without Subticket Manager Plugin
- Use setTopicId() method instead of direct property access
- Address critical security and performance issues from Phase 2 review

### Security
- Remove API key from error logs
- Fix critical vulnerabilities in legacy API endpoints

[2.2.0]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.2.0
[2.2.1]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.2.1
[2.2.2]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.2.2
[2.3.0]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.3.0
[2.3.1]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.3.1
[2.3.2]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.3.2
[2.3.3]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.3.3
[2.3.4]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.3.4
[Unreleased]: https://github.com/studio-prisma/osticket-api-endpoints/compare/v2.7.0...HEAD
[2.4.0]: https://github.com/markus-michalski/osticket-api-endpoints/releases/tag/v2.4.0
[2.4.1]: https://github.com/studio-prisma/osticket-api-endpoints/releases/tag/v2.4.1
[2.5.0]: https://github.com/studio-prisma/osticket-api-endpoints/releases/tag/v2.5.0
[2.5.1]: https://github.com/studio-prisma/osticket-api-endpoints/releases/tag/v2.5.1
[2.6.0]: https://github.com/studio-prisma/osticket-api-endpoints/releases/tag/v2.6.0
[2.7.0]: https://github.com/studio-prisma/osticket-api-endpoints/releases/tag/v2.7.0
