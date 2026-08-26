# CLAUDE.md

Guidance for Claude Code when working in the Smart Goal repository.

## Project

Smart Goal is a Laravel 12 work-order and task-tracking system with a Thai UI.

Stack:

* PHP 8.2 and Laravel 12
* Blade and Vite
* Bootstrap 5 and Bootstrap Icons
* SweetAlert2 and Chart.js
* Plain JavaScript ES modules
* PHPUnit and Node test runner with jsdom
* No SPA framework and no jQuery

Core models are `WorkOrder`, `WorkOrderList`, `User`, `Department`, and `Meeting`.

User and Admin workspaces share task, calendar, meeting, collaborator, and notification behavior.

## Commands

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

```bash
composer dev
npm run dev
npm run build
```

```bash
composer test
php artisan test
php artisan test --filter=test_method_name
php artisan test tests/Feature/WorkOrderPolicyTest.php
npm test
```

```bash
vendor/bin/pint
vendor/bin/pint --test
```

Treat `composer.json`, `package.json`, `phpunit.xml`, and `vite.config.js` as the current command source of truth.

The default test database is SQLite in-memory. SQLite does not enforce every MySQL behavior, including some column-length constraints.

## Working tree safety

Before editing:

* Run `git status --short`.
* Inspect relevant existing diffs.
* Preserve unrelated modified and untracked files.
* Assume existing changes belong to the user.

Do not commit, push, pull, stash, reset, rebase, amend, checkout, restore, or rewrite history unless explicitly requested.

Do not run destructive database operations without explicit approval:

* `php artisan migrate:fresh`
* `php artisan db:wipe`
* Destructive SQL against the real database

Use `php artisan migrate --pretend` when only SQL inspection is needed.

Do not run pending migrations against the real database unless requested.

Do not weaken or remove existing test assertions merely to make an implementation pass.

For bug fixes, add a regression test that fails before the fix and passes afterward whenever practical.

## Roles and authorization

Current roles:

* `admin`
* `user`
* `viewer`

Route middleware aliases are registered in `bootstrap/app.php`.

Fine-grained WorkOrder and WorkOrderList authorization belongs in:

* `App\Policies\WorkOrderPolicy`
* `App\Policies\WorkOrderListPolicy`

Before adding a permission check:

1. Inspect the relevant policy.
2. Inspect existing controller behavior.
3. Check for conflicting legacy inline checks.
4. Prefer adding or updating a policy ability.

Do not duplicate `abort_if`, `abort_unless`, role, ownership, or membership checks across controllers.

Authorization must be enforced by the server. Hiding controls in Blade, JavaScript, or CSS is not authorization.

`viewer` is read-only and must not be:

* Task owner or assignee
* Collaborator
* Meeting attendee candidate
* Notification recipient

Do not add an Admin bypass unless the business rule explicitly permits it.

## Work-order status

`work_orders.job_status` is an integer state machine:

* `1` — todo
* `2` — doing
* `3` — in review
* `4` — done/closed
* `5` — paused
* `6` — late

Status labels and presentation are centralized in `App\Support\WorkBoardDesign`.

All status transitions after creation must go through:

```php
App\Services\TaskStatusTransitionService::transition()
```

The transition service handles legal transitions, subtask gating, audit history, and notifications.

Do not assign `job_status` directly from controllers except for the defined initial status on creation.

Before adding or changing a status, search the complete repository for `job_status`.

## Shared business rules

Use `App\Support` for stateless rules and shared facts.

Use `App\Services` for orchestration, database-backed workflows, query shaping, and injected dependencies.

Important shared sources include:

* `WorkOrderApprovalResolver`
* `TaskCollaboratorOptions`
* `WorkBoardDesign`
* `TodayWorkspace`
* `ProtectedMedia`
* `AuditTrail`
* `TrashRetention`
* `TaskStatusTransitionService`
* `NotificationService`
* `MeetingQueryService`

When logic is duplicated, centralize it instead of copying it into another controller.

## Timezone

Datetimes are stored in UTC.

Business time is `Asia/Bangkok`.

Use existing task and meeting timezone helpers when deciding:

* Today
* Late or overdue
* Calendar date
* Meeting date range
* Business-day display

Convert timezone at query or display boundaries only.

Do not persist Bangkok-converted display values into UTC columns.

Do not compare `now()` directly when business-day behavior is required.

## Attachments and media

All WorkOrder attachment validation must use:

```php
App\Support\Concerns\ValidatesAttachments
```

Requirements:

* Shared extension and MIME allow-lists
* Maximum size of 10 MB
* MIME detected from file content
* Store `$file->getMimeType()`
* Never trust `$file->getClientMimeType()`

Attachments are private and served through `MediaController` and `ProtectedMedia`.

Never construct a public URL directly from an attachment storage path.

Never expose private paths in Blade, JSON, JavaScript, or email.

## Sessions and passwords

Real environments require:

```env
SESSION_DRIVER=database
```

`UserSessionSecurity` intentionally rejects unsupported session drivers.

`Auth::regenerate()` rotates the session ID but does not clear every session attribute.

Login must re-seed the My Tasks workspace view after regeneration.

Login uses normalized lowercase `users.username`, not email.

Password rules must reuse:

```php
App\Support\PasswordPolicy::rule()
```

## Controllers and responses

Mutation endpoints may be called by both Blade forms and AJAX.

Reuse:

```php
App\Http\Controllers\Concerns\RespondsWithTaskResult::jsonOrBack()
```

Do not create another controller-specific `expectsJson()` response branch when the shared trait applies.

Keep controllers focused on HTTP concerns. Put reusable business rules and orchestration in policies, Support classes, or Services.

## Migrations

Do not edit an already-applied migration to change production schema.

Add a new migration.

Migrations must use migration-time literal values instead of importing Model constants.

A destructive or narrowing `down()` must check for incompatible data and throw instead of silently truncating or deleting it.

## Shared User and Admin UI

Equivalent User and Admin workflows must use the same Blade component and JavaScript implementation.

This includes:

* Task Workspace
* Task details
* Collaborator management
* People Selector
* Calendar previews
* Shared meeting controls

Permission differences must come from server-side policies and explicit capability data.

Do not hardcode authorization decisions in JavaScript.

When replacing UI, delete obsolete markup, CSS, JavaScript, listeners, and test fixtures.

Do not leave old UI:

* Hidden with CSS
* Positioned off-screen
* Behind the replacement
* Disabled but still initialized
* Running in parallel

Each shared behavior must have one source of truth.

## Frontend conventions

`vite.config.js` lists entry points explicitly.

When adding a frontend entry:

1. Add the file.
2. Register it in the Vite `input` array.
3. Reference it with Blade `@vite()`.
4. Run `npm run build`.

Organization:

* `resources/css/components/` — shared components
* `resources/css/pages/` — page-specific styles
* `resources/js/components/` — shared widgets
* `resources/js/pages/<page>/` — page modules

Keep modules focused and avoid duplicate initialization.

Dynamic UI must use safe event delegation or an idempotent initializer.

Do not introduce jQuery or another UI framework.

Never use native `alert()`, `confirm()`, or `prompt()`.

Use:

```javascript
window.Swal.fire(...)
```

## Modal and popover behavior

A modal and a non-modal popover are different components.

Modal behavior may include:

* Backdrop
* Body scroll lock
* Focus management
* `aria-modal="true"`

Popover behavior must:

* Stay positioned relative to its trigger
* Avoid body scroll lock
* Avoid blocking the whole page
* Close on outside click and Escape
* Restore focus to its trigger

An overlay must have one owner for open state, close state, focus, Escape handling, and stacking.

Opening a component repeatedly must not register duplicate listeners.

## Testing

PHP tests use PHPUnit under `tests/Feature`, normally with `RefreshDatabase` and `actingAs()`.

JavaScript tests live under `tests/js` and run through `node --test`.

Use jsdom for real DOM interaction and pure functions for deterministic state or positioning logic.

Some JS tests read Blade, CSS, and JavaScript source directly with `fs.readFileSync()`.

Before renaming or moving frontend files, search `tests/js` for raw path assertions.

Shared workflows must be tested for both User and Admin.

Interactive UI tests should cover the real path from trigger click to visible result, not only internal helper functions.

## Definition of done

Before reporting completion:

1. Inspect the final diff.
2. Confirm unrelated user changes remain intact.
3. Run targeted tests.
4. Run the full PHP suite for backend changes.
5. Run `npm test` for frontend changes.
6. Run `npm run build` for frontend assets.
7. Run `vendor/bin/pint --test` for PHP formatting.
8. Report skipped or environment-specific tests.
9. Report remaining visual checks.
10. Confirm whether Git or database mutations were performed.

Passing tests alone does not prove visual correctness.

For UI changes, verify or request manual checks at:

* 1920px
* 1366px
* 768px
* 375px

Do not claim browser layout correctness from jsdom or CSS inspection alone.
