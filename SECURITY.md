# Security Policy

## Supported Versions

Only the latest minor release of this fork receives security fixes. Older tags are archived, not maintained.

| Version | Supported |
|---|---|
| 2.6.x | yes |
| everything older | no |

The upstream project [markus-michalski/osticket-api-endpoints](https://github.com/markus-michalski/osticket-api-endpoints) has its own release line. Fixes made here are offered upstream as pull requests; whether and when they land there is the upstream maintainer's decision.

## Reporting a Vulnerability

Use **[GitHub Security Advisories](https://github.com/studio-prisma/osticket-api-endpoints/security/advisories/new)**, which are private by default. Do not open a public issue for a suspected vulnerability.

Include:

- the plugin version, the osTicket version and the PHP version
- the endpoint (for example `api/tickets-update.php`) and the permissions of the API key you used
- what the caller gains, not only what misbehaves
- reproduction steps, ideally against a clean osTicket 1.18 install

Expected response: an acknowledgement within 7 days, an assessment within 30. This is a spare-time project, not a vendor with an on-call rotation. That is the honest number, not a target.

Please do not include ticket contents, customer data, API keys or database dumps in a report.

## In Scope

The plugin adds endpoints under `api/` (tickets, replies, attachments, statistics, subtickets, tasks). Each one checks a permission flag on the osTicket API key (`can_read_tickets`, `can_update_tickets`, `can_delete_tickets`, `can_reply_tickets`, `can_read_stats`, `can_manage_subtickets`, `can_read_tasks`, `can_create_tasks`, … ). A real finding looks like one of these:

- **Permission bypass.** An endpoint does something the API key's flags do not allow: reading without `can_read_tickets`, deleting without `can_delete_tickets`, replying without `can_reply_tickets`, changing subticket links without `can_manage_subtickets`, and so on.
- **Acting as the wrong agent.** Replies run as the agent set in "Reply as Agent", task changes as the agent set in "Tasks as Agent"; with the setting off, the endpoint is disabled whatever the key allows. A request that makes them run as a different agent, runs them while the setting is off, or bypasses the agent's department access is a finding.
- **Attachment download beyond the ticket.** `api/tickets-attachment-download.php` returns a file that does not belong to the requested ticket, or a file from the server's file system.
- **Information disclosure in responses.** File system paths, stack traces, SQL, PHP or server details in any error response.
- **Injection.** SQL built from a request parameter or a name-based filter without parameterisation or `db_input()`; HTML or script in API-created content that osTicket renders unescaped in the staff or client view.

## Out of Scope

Documented design decisions are not findings. Naming them here saves everyone a round trip.

- **Vulnerabilities in osTicket core,** including its API key model (key generation, the IP binding of a key). Report those to the [osTicket project](https://github.com/osTicket/osTicket/security).
- **A caller with a valid key doing what the key allows.** An API key with `can_delete_tickets` can delete tickets; that is the permission, not a flaw. Keys are as sensitive as an admin password.
- **Statistics are system-wide.** `api/tickets-stats.php` returns counts across departments and agents to any key with `can_read_stats`. It returns numbers, not ticket contents.
- **Markdown rendering.** The optional Markdown Support plugin renders ticket bodies; findings in its renderer belong to [that project](https://github.com/markus-michalski/osticket-plugins).
- **Anything requiring an already-compromised API key, agent account, admin account or server.**
- **Findings in development dependencies** (`phpunit/phpunit`, `doctrine/instantiator`). They are not part of the release package; Dependabot tracks them.
- **Automated scanner output without a working proof of concept.**

## Handling of Secrets

The plugin stores no credentials of its own; API keys live in osTicket's database. No keys belong in this repository, not in tests, not commented out, not in fixtures. A secret that reached a commit is rotated first and removed second; the history stays readable either way.

## Disclosure

Coordinated. A fix ships first, the advisory follows once a patched release is available. Reporters are credited unless they ask not to be.
