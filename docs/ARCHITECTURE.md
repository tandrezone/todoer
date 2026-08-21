# Todoer — MVC architecture

How the refactored application is put together, and what changed from the original procedural
version. The game itself is documented in [`../README.md`](../README.md); this file is about the
code.

---

## 1. Directory layout

```
todoer/
├── public/                          ← the only web-exposed directory
│   ├── index.php                    front controller (25 lines: autoload, boot, run)
│   ├── .htaccess                    Apache rewrite + baseline headers
│   ├── service-worker.js            push notifications
│   ├── site.webmanifest
│   ├── favicon.ico
│   └── assets/
│       ├── css/style.css
│       ├── js/{app,auth,group,import,prizes,backup}.js
│       └── {favicon.svg,icon-180.png,icon-192.png,icon-512.png}
│
├── config/
│   ├── settings.php                 paths, database, session, push — one closure, no globals
│   ├── container.php                the wiring: every service and its dependencies
│   └── routes.php                   the route table, with per-route auth guards
│
├── src/
│   ├── Application.php              boot() → container; handle(request) → response; run() → SAPI
│   │
│   ├── Container/                   PSR-11
│   │   ├── Container.php            explicit factories, lazy, memoised, cycle-detecting
│   │   ├── ContainerException.php
│   │   └── ServiceNotFoundException.php
│   │
│   ├── Http/                        PSR-7 / PSR-15 plumbing
│   │   ├── Route.php  RouteResult.php  Router.php
│   │   ├── MiddlewarePipeline.php   PSR-15 queue, re-entrant so routes can nest their own
│   │   ├── TerminalHandler.php      the "should never be reached" bottom of the pipeline
│   │   ├── Responder.php            json() / html() / redirect() / download()
│   │   ├── RequestAttribute.php     typed accessors for the request's user + membership
│   │   ├── UrlGenerator.php         sub-directory-safe URLs, asset cache-busting
│   │   ├── SapiEmitter.php          the only place that calls header()/echo
│   │   ├── Input/InputBag.php       typed, read-only request data (replaces $_POST/$_GET)
│   │   └── Middleware/
│   │       ├── ErrorHandlerMiddleware.php      exception → status + safe message (JSON or page)
│   │       ├── SecurityHeadersMiddleware.php
│   │       ├── SessionMiddleware.php           starts the session, hardens the cookie
│   │       ├── BodyParsingMiddleware.php       JSON body → parsed body
│   │       ├── CsrfMiddleware.php              every unsafe method, not per-endpoint opt-in
│   │       ├── AuthenticationMiddleware.php    resolves the user *and* their group, once
│   │       ├── MaintenanceMiddleware.php       the per-request bookkeeping sweep
│   │       ├── RoutingMiddleware.php
│   │       ├── DispatchMiddleware.php
│   │       ├── RequireWebSessionMiddleware.php redirect an anonymous visitor
│   │       └── RequireApiSessionMiddleware.php 401 an anonymous caller
│   │
│   ├── Session/                     SessionInterface + PhpSession + ArraySession + CsrfTokenManager
│   │
│   ├── Controller/
│   │   ├── Web/                     Dashboard, Login, Logout, GroupPage, PrizesPage,
│   │   │                            ImportPage, BackupPage — each returns a rendered response
│   │   └── Api/                     TaskApi, GroupApi, LeaderboardApi, PrizeApi,
│   │                                NotificationApi, KeepScan, KeepCommit, TaskRestore,
│   │                                TaskExport — each returns JSON
│   │
│   ├── Service/                     the application + game logic
│   │   ├── AuthService.php          sign in / register / sign out
│   │   ├── GroupService.php         membership, invites, admin actions — the privacy boundary
│   │   ├── TaskService.php          add / edit / delete / complete / reopen (incl. stealing)
│   │   ├── TaskDraftFactory.php     request input → a validated TaskDraft
│   │   ├── TaskBoardService.php     the dashboard payload and its ordering rules
│   │   ├── AssignmentService.php    distribution on Start, expiry/reassignment sweep, claims
│   │   ├── PeriodService.php        closing periods, crowning winners, drawing prizes
│   │   ├── LeaderboardService.php   PrizeService.php
│   │   ├── NotificationService.php  PushService.php (VAPID keys, best-effort delivery)
│   │   ├── KeepImportService.php    Google Takeout parsing, with zip-bomb guards
│   │   ├── TaskImportService.php    TaskExportService.php
│   │   ├── MaintenanceService.php   the sweep, in the order it has to happen
│   │   └── PasswordHasher.php
│   │
│   ├── Domain/                      the rules that need no database
│   │   ├── Enum/                    ListType (and its points), TaskStatus, Priority,
│   │   │                            AssignmentType, GroupRole, TaskEvent, ClaimDenial
│   │   ├── Task/                    Task (deadlines, claim rules, ordering), TaskView,
│   │   │                            TaskDraft, ClaimDecision
│   │   ├── Period/Period.php        period keys, labels, window shorthand → datetime
│   │   ├── Group/                   Group, GroupMembership, GroupMember
│   │   ├── User/User.php            Prize/{Award,PrizePool}  Notification/Notification
│   │
│   ├── Repository/                  one class per table; every SQL statement in the app
│   │   ├── UserRepository.php       GroupRepository.php     TaskRepository.php
│   │   ├── TaskHistoryRepository.php GameStateRepository.php ClosedPeriodRepository.php
│   │   ├── AwardRepository.php      LeaderboardRepository.php
│   │   └── NotificationRepository.php PushSubscriptionRepository.php
│   │
│   ├── Database/                    ConnectionFactory, Migrator, TransactionManager,
│   │                                InviteCodeGenerator
│   ├── View/TemplateRenderer.php    plain-PHP templates, escaper injected
│   ├── Support/                     Clock, FrozenClock, ErrorLogLogger (PSR-3)
│   └── Exception/                   ValidationException(400), AuthenticationException(401),
│                                    AuthorizationException(403), NotFoundException(404),
│                                    MethodNotAllowedException(405), ConflictException(409)
│
├── templates/                       layout.php, partials/topnav.php, dashboard.php, login.php,
│                                    group.php, prizes.php, import.php, backup.php, error.php
├── database/schema.sql
├── tests/smoke.php                  66 end-to-end checks over the real stack
├── data/                            todoer.sqlite + vapid.json (outside the web root, gitignored)
├── composer.json
└── docs/ARCHITECTURE.md
```

## 2. The request lifecycle

```
public/index.php
  └─ Application::boot()            settings → container (config/container.php)
     └─ Application::run()          PSR-7 request from globals → handle() → SapiEmitter

handle() runs one PSR-15 pipeline, outermost first:

  ErrorHandler ─ SecurityHeaders ─ Session ─ BodyParsing ─ Csrf ─ Authentication ─
  Maintenance ─ Routing ─ Dispatch
                                                                     │
                                              route middleware (RequireWeb/ApiSession)
                                                                     │
                                                             Controller (a PSR-15 handler)
                                                                     │
                                          Service ── Domain ── Repository ── PDO
                                                                     │
                                                     TemplateRenderer / Responder → Response
```

Why this order: errors are caught outside everything, so even a failure in session handling still
produces a proper response; security headers wrap the whole thing so they are on error responses
too; the session, the CSRF guard and the current user are all established before any route-specific
code runs.

**Controllers** read validated input, call one service, and return a response. They contain no SQL,
no game rules and no HTML. **Services** own the rules and are the only things that orchestrate
repositories. **Domain** objects hold the rules that need no database — a task's deadline, whether
it is up for grabs, the points a list is worth, what a period key means. **Repositories** are the
only code that knows the table layout, and every statement in them is prepared with bound
parameters. **Templates** receive finished data plus an escaper.

## 3. Standards

| Standard | How it is met |
| --- | --- |
| **PSR-1 / PSR-12** | One class per file, `StudlyCaps` classes, `camelCase` methods, `UPPER_SNAKE` constants, 4-space indent, braces on their own line for classes/methods, one blank line after the namespace, trailing-comma multi-line argument lists, visibility on every member. `declare(strict_types=1);` at the top of every PHP file. |
| **PSR-4** | `App\` → `src/`, declared in `composer.json`; every namespace matches its directory (`App\Controller\Api\TaskApiController` → `src/Controller/Api/TaskApiController.php`). |
| **PSR-7 / PSR-17** | `nyholm/psr7` + `nyholm/psr7-server`. Requests and responses are PSR-7 objects everywhere; `Responder` builds responses through `ResponseFactoryInterface`/`StreamFactoryInterface`; file uploads are `UploadedFileInterface`. |
| **PSR-15** | Every middleware implements `MiddlewareInterface`; every controller implements `RequestHandlerInterface`; `MiddlewarePipeline` is the queue and nests a second pipeline for route-specific guards. |
| **PSR-11** | `App\Container\Container` implements `ContainerInterface`, with `NotFoundExceptionInterface`/`ContainerExceptionInterface` exceptions. Everything is constructor-injected; nothing pulls from the container except `DispatchMiddleware` (which is what a dispatcher is for). |
| **PSR-3** | `ErrorLogLogger implements LoggerInterface`; services log through the interface, so swapping in Monolog is one line of `config/container.php`. |
| **Typing** | Full parameter, return and property types, including nullables and `list<T>`/`array<K,V>` docblocks where the type system cannot express it. PHP 8.1 features used deliberately: `enum` for domain vocabulary, `readonly` properties for immutable domain objects, constructor promotion, `match`, first-class callable syntax, `never`-style throw expressions. |

## 4. What was refactored, and why

### 4.1 Security fixes

| # | Issue in the original | Fix |
| --- | --- | --- |
| 1 | **The database was downloadable.** The app was served from the project root (`php -S 0.0.0.0:8080`), so `data/todoer.sqlite` — every account's password hash, every task — was a public URL, as were `includes/*.php`, `schema.sql` and `data/vapid.json` (the Web Push private key). | The web root is now `public/`. Code, database and keys live above it. `data/` is also relocatable with `TODOER_DATA_DIR`. |
| 2 | **SQL built by string concatenation** in the migration path: the legacy group id was interpolated into `INSERT … SELECT`, `UPDATE tasks SET group_id = …` and a `COUNT(*) … WHERE group_id = …`, and table names were interpolated into DDL. | Every statement in `src/Repository/` and `src/Database/Migrator.php` is a prepared statement with bound parameters. The few unavoidable identifier interpolations go through `quoteIdentifier()`, which refuses anything outside `[A-Za-z0-9_]`. |
| 3 | **CSRF was opt-in per endpoint** — each `api/*.php` called `todoer_require_csrf()` by hand, so a new endpoint that forgot to was unprotected. | `CsrfMiddleware` guards *every* unsafe method for every route. Tokens arrive as a header (JSON) or a hidden field (forms); both are accepted. |
| 4 | **Sign-out was a GET link**, so any page could sign a visitor out with an `<img>` tag. | `POST /logout` with the session token, from a small form in the nav. |
| 5 | **Error handling leaked and corrupted output.** A global exception handler `echo`ed a JSON error body — including into a half-rendered HTML page — and `set_error_handler` promoted every notice to an exception. | `ErrorHandlerMiddleware` maps expected failures (validation, permission, not-found, conflict) to their status codes with user-facing messages, logs everything else in full and returns a generic 500, and content-negotiates JSON vs. a rendered error page. Stack traces only with `TODOER_DEBUG=1`. |
| 6 | **Superglobals everywhere** (`$_POST`, `$_GET`, `$_SERVER`, `$_FILES`, `$_SESSION`) plus `$GLOBALS['pdo']` as the database handle. | PSR-7 request objects at the edge; `InputBag` for typed, read-only access; `SessionInterface` for session state; the PDO handle is injected. No global mutable state. |
| 7 | **Escaping was per-echo.** Every template interpolation called `htmlspecialchars()` by hand with default flags (single quotes unescaped). | One escaper injected into every template, `ENT_QUOTES | ENT_SUBSTITUTE`, applied to every interpolation — so it is safe inside single-quoted attributes and invalid UTF-8 does not silently blank the output. The sign-in page's inline `<script>` moved to `assets/js/auth.js`. |
| 8 | No response hardening. | `SecurityHeadersMiddleware`: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Cross-Origin-Opener-Policy`. CSP is deliberately *not* set yet — the per-user colour swatches are still inline `style` attributes, and a policy that silently breaks them is worse than none. |
| 9 | `mkdir($dataDir, 0777)` for the directory holding the database and the push private key. | `0770`, and the key file keeps its `0600`. |
| 10 | **Task titles were only length-limited in the browser** (`maxlength=200`); the add/edit endpoints stored whatever arrived. | Clamped server-side in `TaskDraftFactory` (the import path already did this). |
| 11 | **A latent timing bug with security-ish consequences**: `assigned_at` was written by SQLite's `datetime('now')` (UTC) but compared using PHP's `strtotime()`/`time()` (server timezone), so on any machine not set to UTC every task timer was off by the UTC offset — an hour of free time, or an hour stolen. | All timestamps are written from an injected `Clock`, so stored values and comparisons share one timezone. `FrozenClock` makes the behaviour testable. |
| 12 | A stale session for a deleted account re-queried a user that would never exist again. | `AuthService::currentUser()` clears the session key when the account is gone. |

Deliberately **kept** from the original, because they were already right: `password_hash`/
`password_verify`, session-id rotation on privilege change, the hardened session cookie
(HttpOnly + SameSite=Lax + conditional Secure), timing-safe token comparison, the conditional
`UPDATE` that makes two players racing for one task safe, the zip-bomb guards on the Keep import,
and the rule that a group is resolved from the session and never from client input.

### 4.2 Design patterns applied

- **MVC with a front controller** — one entry point, a route table, thin controllers, passive
  templates.
- **Layered architecture** with a dependency rule that is enforced by inspection: `Domain/` imports
  nothing from `Http/`, `Repository/` or `Service/`; `Repository/` imports only `Domain/` and
  `Support/`.
- **Repository / DAO** — `TaskRepository`, `GroupRepository`, … Every SQL statement lives in one of
  them, which is also what makes `group_id` scoping auditable: it is a parameter of nearly every
  method, not an `if` in a caller.
- **Service layer** — the game rules, callable from anywhere (an endpoint, a test, a future CLI
  command). `MaintenanceService` is the whole background sweep in eight readable lines.
- **Dependency injection with an explicit PSR-11 container** — `config/container.php` is the
  application's parts list. No autowiring magic, no service locator smuggled into a constructor, no
  `new` in business code.
- **Value objects and DTOs** — `Period`, `TaskDraft`, `ClaimDecision`, `GroupMembership`,
  `TaskView`. Immutable, `readonly`, no setters.
- **Enums as domain vocabulary** — `ListType::Weekly->points()` replaces `TODOER_POINTS['weekly']`;
  a list type can no longer be a typo. `ClaimDenial` carries both the machine code the API exposes
  and the sentence the user reads.
- **Rich domain model instead of transaction scripts** — deadlines, claimability and list ordering
  were free functions taking an associative array; they are now methods on `Task`.
- **Middleware pipeline** (chain of responsibility) for cross-cutting concerns: errors, headers,
  session, body parsing, CSRF, authentication, the sweep.
- **Exception-to-HTTP mapping** — services throw domain exceptions carrying a user-facing message;
  one middleware decides what that means in HTTP terms.
- **Seams for testability** — `Clock`, `SessionInterface`, `LoggerInterface`, `TransactionManager`.
  The smoke tests swap the session and the data directory and leave the rest of the wiring alone.

### 4.3 Structural changes

| Before | After |
| --- | --- |
| `index.php`, `login.php`, `group.php`, `prizes.php`, `import.php`, `backup.php`, `logout.php` (PHP + HTML + business logic in one file each) | `src/Controller/Web/*` + `templates/*` |
| `api/tasks.php` (500 lines of `if ($action === …)`), `api/group.php`, `api/leaderboard.php`, `api/prizes.php`, `api/notifications.php`, `api/import.php`, `api/import_json.php`, `api/export.php` | `src/Controller/Api/*` (thin) + `src/Service/*` (the logic) |
| `includes/db.php` (connection + migrations + prize seeding) | `Database/ConnectionFactory`, `Database/Migrator`, `Domain/Prize/PrizePool` |
| `includes/auth.php`, `includes/groups.php` | `Service/AuthService`, `Service/GroupService`, `Repository/{User,Group}Repository`, `Domain/{User,Group}` |
| `includes/period.php` | `Domain/Period/Period`, `Service/PeriodService`, `Repository/{ClosedPeriod,Award,Leaderboard}Repository` |
| `includes/assignment.php` | `Service/AssignmentService`, `Domain/Task/*`, `Repository/{Task,TaskHistory,GameState}Repository` |
| `includes/notifications.php` | `Service/NotificationService`, `Service/PushService`, `Repository/{Notification,PushSubscription}Repository` |
| `includes/keep_import.php` | `Service/KeepImportService` |
| `includes/bootstrap.php` (globals + a sweep at include time) | `Application`, `config/container.php`, `MaintenanceMiddleware` |
| `includes/api_helpers.php` (`echo json_encode(); exit;`) | `Http/Responder` — and no `exit` anywhere in the request path |
| URLs like `/api/tasks.php`, `/login.php` | `/api/tasks`, `/login`, … via the route table; the front-end builds them from `<meta name="base-path">`, so a sub-directory deployment works with no configuration |
| — | `tests/smoke.php`: 66 end-to-end checks |

**Unchanged on purpose:** the database schema and all of its migrations; every JSON key the
front-end reads (so `assets/js/*.js` only changed where the endpoint URLs did); the game's rules,
wording and prize pool.

### 4.4 Verification

- `php tests/smoke.php` — 66 checks, all passing: the auth and CSRF guards, the task lifecycle
  (add → start → complete → reopen), the steal-and-notify flow between two players, group isolation
  (a second group sees nothing of the first, and a task id from another group is simply not found),
  output escaping of a hostile group name, an SQL-injection attempt in the username, export →
  restore, Keep scanning, timer expiry and reassignment, a locked task expiring instead of moving,
  and prize draws including the "closed early" case and the no-double-award guard.
- Served with `php -S` and exercised over HTTP: pages, assets, the service worker, 302/401/404/405
  behaviour, and the same again with the app mounted at `/todoer/` to confirm the base-path handling.
- Driven in headless Chromium: register → add two tasks → Start → tick one off → leaderboard
  updates → group and prize pages render, with zero console errors and zero failed requests.
- The `Migrator` was run against a copy of the existing production database: 3 users, 2 groups, 1
  task, 1 award and 3 notifications all intact afterwards, with the prize pool and the new
  group-scoped tables in place, and the same data read back correctly through the new services.
