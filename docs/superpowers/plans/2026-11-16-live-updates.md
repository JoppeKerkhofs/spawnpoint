# Live updates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pages update when the backend says something changed, over one WebSocket per tab, with polling as the fallback.

**Architecture:** Laravel Reverb in a new `realtime` container. One private channel per user; a single `Changed` event (`server`, `what`) whose recipients are worked out when it is sent. `ActivityLog::record()` broadcasts panel actions; a watcher loop in the scheduler container broadcasts state changes it sees on the nodes. Pages answer an event with an Inertia partial reload.

**Tech Stack:** laravel/reverb, laravel-echo, pusher-js, Laravel scheduler, Caddy.

**Spec:** `docs/superpowers/specs/2026-11-16-live-updates-design.md`

## Global Constraints

- Work on local `main`; no feature branch; do not push without asking.
- Version stays `1.6.0`. No attribution lines, no `@` tokens in commit messages.
- No new settings an operator must invent: Reverb's id, key and secret derive from `APP_KEY`.
- Events carry no page data. A broadcast that fails never fails the action that caused it.
- Texts through `t()` / `__()`; short comments only.
- Before done: Pest, Pint, PHPStan, `npm run check:fix`, `npm run types:check`, `npm run build`, and the pages in a browser with Reverb running.

## Review Focus

- A viewer must not receive an event for a server that is not shared with them, also right after the share is withdrawn. Test in Task 1.
- Reverb down or absent: actions still succeed, the watcher does nothing, pages poll. Tests in Tasks 1 and 2; browser check in Task 4.
- The watcher must not call the nodes when no one is connected. Test in Task 2.
- A running server's changing CPU numbers must not cause an event every 2 seconds. Test in Task 2.
- A page must not reload over a form the user is submitting, nor Files over an open editor with unsaved text. Browser check in Task 4.

---

### Task 1: Reverb, the channel and the event

**Files:**
- Modify: `panel/composer.json` (`laravel/reverb`), `panel/bootstrap/app.php`, `panel/app/Models/ActivityLog.php`, `panel/app/Http/Middleware/HandleInertiaRequests.php`, `panel/.env.example`, `panel/phpunit.xml` (already `null`)
- Create: `panel/config/broadcasting.php`, `panel/config/reverb.php`, `panel/routes/channels.php`, `panel/app/Events/Changed.php`, `panel/tests/Feature/LiveTest.php`

**Interfaces:**
- Produces: `App\Events\Changed::__construct(public ?string $server, public string $what)`, `ShouldBroadcastNow`, `broadcastAs(): 'changed'`, `broadcastWith(): ['server' => slug|null, 'what' => ...]`, `broadcastOn()`: `PrivateChannel("users.{id}")` for every admin, plus the viewers of the server when there is one.
  `Changed::send(?Server $server, string $what): void` dispatches and swallows (reports at most once a minute) any failure to reach Reverb.
  Shared Inertia prop `live`: `{key, host?, port?}` or `null` when broadcasting is not `reverb`.
  Config: `reverb.apps.apps.0` and `broadcasting.connections.reverb` with id, key, secret = first 20 / 32 / 32 hex characters of `hash_hmac('sha256', 'reverb-id'|'reverb-key'|'reverb-secret', APP_KEY)`; server host from `REVERB_HOST` (default `realtime`), port `REVERB_PORT` (8081).

- [ ] **Step 1: failing tests** (`LiveTest.php`, `Event::fake([Changed::class])` where dispatch is asserted):
  - `Changed` for a server goes to the channels of both admins and the one viewer it is shared with, not to another viewer; after `detach` the viewer is gone.
  - `Changed` without a server goes to admins only.
  - `ActivityLog::record('backup.create', $server)` dispatches `Changed` with that slug and `what === 'backup'`; `record('user.create')` dispatches `server === null`, `what === 'user'`.
  - `/broadcasting/auth` (with `BROADCAST_CONNECTION=reverb` set in the test): a user may join `private-users.{own id}` and gets 403 for another id.
  - `Changed::send()` does not throw when the broadcaster throws.
  - the `live` prop is null under the `null` broadcaster and has a key under `reverb`.
- [ ] **Step 2:** `php artisan test --compact --filter=LiveTest`. Expected: FAIL, class not found.
- [ ] **Step 3:** `composer require laravel/reverb`; implement.
- [ ] **Step 4:** run the filter, then the whole suite. Expected: PASS.
- [ ] **Step 5:** commit "Broadcast what changes to the users who may see it".

### Task 2: The watcher

**Files:**
- Create: `panel/app/Console/Commands/BroadcastServers.php`
- Modify: `panel/routes/console.php`, `panel/app/Services/NodeClient.php`, the six controllers that pass `states` to a page, `panel/tests/Feature/LiveTest.php`

**Interfaces:**
- Consumes: `Changed::send()`.
- Produces: `servers:broadcast {--once}`: loops for 58 seconds, 2 seconds apart (`--once`: one run). Each run: `BroadcastServers::occupied(): bool` (Reverb's `GET /channels` through the broadcaster; false on any failure) → `NodeClient::states()` → `Cache::put('server-states', $states, 6)` → `Changed::send($server, 'state')` for every server whose entry differs from the previous run's (kept in the cache under `server-states-seen`). `usage`, `players` and tick time are left out of the comparison except on every fifth run.
  `NodeClient::recentStates(): array` returns the cached copy or `states()`; the web controllers' `states` props use it.
  Schedule: `everyMinute()->runInBackground()->withoutOverlapping(2)`.

- [ ] **Step 1: failing tests:** nobody connected → no node request; connected → a state change dispatches `Changed` once for that server only; a change in CPU alone dispatches nothing on runs 1–4 and once on run 5; `recentStates()` makes no HTTP request after a watcher run and one without.
- [ ] **Step 2:** run. Expected: FAIL, command not found.
- [ ] **Step 3:** implement.
- [ ] **Step 4:** run the suite. Expected: PASS.
- [ ] **Step 5:** commit "Watch the nodes once for everyone and broadcast what changes".

### Task 3: Deployment

**Files:**
- Modify: `docker/Caddyfile`, `compose.yaml`, `compose.prod.yaml`, `.env.compose.example` if it needs a line, `panel/composer.json` or the `dev` command so local development starts Reverb, `README.md`, `CLAUDE.md`

- [ ] **Step 1:** Caddy: `handle /app/*` → `reverse_proxy {$REVERB_UPSTREAM:realtime:8081}`, before `php_server`. Compose: `BROADCAST_CONNECTION: reverb` in the shared environment; service `realtime` (`php artisan reverb:start --host=0.0.0.0 --port=8081`, `RUN_MIGRATIONS=false`, panel network, no published port). Document it, including that an operator's own proxy must pass WebSocket upgrades and that without the container the panel polls.
- [ ] **Step 2:** `docker compose -f compose.yaml config` parses (with dummy env). Expected: no error.
- [ ] **Step 3:** commit "Run the WebSocket server beside the panel".

### Task 4: The browser

**Files:**
- Create: `panel/resources/js/lib/live.ts`
- Modify: `panel/package.json` (`laravel-echo`, `pusher-js`), `ServerHeader.vue`, `pages/Dashboard.vue`, `pages/servers/Backups.vue`, `pages/servers/Mods.vue`, `pages/servers/Files.vue`, `pages/Activity.vue`

**Interfaces:**
- `live.ts`: `connected: Ref<boolean>`; `useLive(handler: (change: {server: string | null; what: string}) => void): void` (subscribes in setup, unsubscribes on unmount; a no-op when the `live` prop is null). One Echo instance per tab, on the page's own host and scheme unless `live.host` says otherwise.
- States: reload `only: ['states']` on `what === 'state'`; `usePoll(4000, …, {autoStart: false})` started and stopped by watching `connected`.

- [ ] **Step 1:** implement as the spec's Browser section says. Reloads are debounced by 300 ms per page and skipped while `router` has a visit of the page's own in flight; Files does not reload while the editor has unsaved text.
- [ ] **Step 2:** `npm run check:fix`, `npm run types:check`, `npm run build`, Pest. Expected: clean.
- [ ] **Step 3:** browser check with Reverb and the watcher running against a real node: two tabs; an action in one (make a folder, start a backup if a server can be backed up, install a mod) appears in the other without polling; stop Reverb → polling resumes; start it → polling stops.
- [ ] **Step 4:** commit "Update pages when the panel says something changed".
