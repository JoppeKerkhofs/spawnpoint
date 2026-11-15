# API parity and fine-grained token abilities

Date: 2026-11-15
Version: 1.5.0
Status: built (branch `api-parity`)

## Why

A mobile application is being built that is meant to be the panel, not a companion to it. It
authenticates with an API token. Today `/api/v1` exposes ten endpoints on one controller and
seven flat abilities, so the app can watch and poke a server but cannot create one, restore a
backup, manage a node or read the activity log. Everything else it needs is web-only.

Two things follow from that: every web route needs an API equivalent, and a token needs to say
much more precisely what it may do than seven checkboxes allow.

## Goals

- Every action a person can take in the web app can be taken through `/api/v1`, except the
  account-critical ones listed under Non-goals.
- A token names one ability per action group; roughly two dozen, each mapping to routes.
- A token can never exceed its owner's role or per-server share. That rule does not change.
- Tokens that exist today keep working with exactly the power they have today.
- Getting a token onto a phone does not involve typing 64 characters, and no password ever
  reaches the app.
- The ability picker reads as a statement of what the token may do, not as a list of flags.

## Non-goals

- **Per-server token scoping.** A token applies to every server its owner may see, as now.
- **Account-critical routes.** No API equivalent for: sign-in, two-factor enable/disable/
  challenge, passkeys, password change or reset, e-mail change, deleting the own account, and
  creating or revoking API tokens. A stolen token must not be able to lock the owner out or
  mint itself a second token. These stay session-only.
- **A v2.** The expansion is additive; `/api/v1` stays.
- **Node API changes.** The Rust service is untouched by this work.

## Approach

Thin `Api/V1` controllers per resource that reuse the Actions and FormRequests the web
controllers already use (`CreateServer`, `UpdateServer`, `DeleteServer`, `PowerServer`,
`MoveServer`, `StoreServerRequest`, `UpdateServerRequest`, …). Parity holds because the
behaviour has one implementation; only the response differs. Ability checks live on the route
as middleware beside the existing `can:` middleware, so a route states both what the token may
do and what its owner may do.

Rejected: routing `/api/v1` through the web controllers with content negotiation. One
implementation, but those controllers are Inertia-shaped (deferred props, flash toasts,
redirects) and session-authenticated; attaching token abilities to them risks exposing an
admin page through a path nobody tested.

Rejected: a schema-driven generic resource layer. More machinery than two dozen endpoints
justify.

## Components

### `App\Api\Abilities`

One source of truth, replacing `ApiTokenController::ABILITIES`. Holds, per ability: name,
group, English label, and one-line description. Also holds the presets and the legacy aliases.
Feeds the ability middleware, the token form validation, the picker component, the pairing
screen and the OpenAPI document. Labels go through `__()`, so `lang:sync` collects them.

Groups and abilities:

| Group | Ability | Covers |
| --- | --- | --- |
| Servers | `servers:read` | list and show servers, service status |
| | `servers:power` | start, stop, restart, kill, stop all |
| | `servers:configure` | change a server's settings |
| | `servers:manage` | create, delete, move between nodes, re-add a DNS record |
| Console and logs | `console:use` | the console address; writing still needs the owner's `commands` |
| | `logs:read` | list, read and download log files |
| Files | `files:read` | list a folder, read and download a file |
| | `files:write` | write, create a folder, rename, delete |
| Backups | `backups:read` | list backups and download one |
| | `backups:create` | take a backup, cancel a running one, set retention |
| | `backups:restore` | restore a backup, lock one, import a world |
| | `backups:delete` | delete a backup |
| Schedules | `schedules:manage` | create, change and delete schedules |
| Administration | `nodes:read` | list nodes with their state |
| | `nodes:manage` | add, change and remove nodes |
| | `users:read` | list users and their shares |
| | `users:manage` | invite, change, remove users and their shares |
| | `settings:read` | read the panel settings (never a secret's value) |
| | `settings:manage` | change the panel settings |
| | `activity:read` | read the activity log |
| Own account | `profile:manage` | the owner's display name and language, nothing else |
| | `webhooks:manage` | the owner's own webhooks |
| | `modpacks:search` | search Modrinth, CurseForge and FTB |

Twenty-three abilities. `/api/v1/me` needs none: any valid token may ask who it belongs to and
what it may do, which is how the app decides what to show.

**Legacy alias.** `backups:manage` is not in the list above and is not offered in the UI. It
exists only for tokens issued before this change and resolves to `backups:read` plus
`backups:create`. A token holding it keeps exactly the two endpoints it has today, and gains
only the two that fall under the same ability and did not exist before: cancelling a running
backup and setting retention. It does not gain restore, lock, delete or world import, which
are the destructive ones. The other six existing names keep their current meaning, with these
widenings deliberately avoided: `files:write` does not gain world import (that is
`backups:restore`), and `servers:read` does not gain `nodes:read`.

This is the one place where a token issued before 1.5.0 can do something it could not before.
If even that is unacceptable, the alternative is to split retention and cancellation into a
`backups:configure` ability and leave the alias at read plus create exactly — a twenty-fourth
ability for a narrow gain. The design takes the simpler option deliberately.

**Presets**, used by the picker and defined here so the UI, the documentation and any future
client agree:

- **Read-only** — `servers:read`, `logs:read`, `files:read`, `backups:read`, `activity:read`
- **Operator** — read-only plus `servers:power`, `console:use`, `files:write`,
  `backups:create`, `backups:restore`, `schedules:manage`
- **Full access** — every ability. Not the default, and the form says plainly that it is
  everything the owner can do.

### `App\Http\Middleware\TokenAbility`

Registered as `ability`. Resolves legacy aliases, then refuses with 403 and a message naming
what is missing — `This token lacks the backups:restore ability.` — which is the message shape
the API already uses. Sanctum's own `ability` middleware is not used because it cannot resolve
aliases and its message is generic.

Routes read:

```php
Route::post('servers/{server}/backups/{backup}/restore', [BackupController::class, 'restore'])
    ->middleware(['ability:backups:restore', 'can:act,server,"backups"']);
```

### API controllers

`Api/V1/`: `ServerController` (existing, extended), `BackupController`, `ScheduleController`,
`FileController`, `NodeController`, `UserController`, `SettingController`,
`ActivityController`, `WebhookController`, `ModpackController`, `MeController`,
`PairingController`. Each thin: validate through the shared FormRequest where one exists, call
the Action, return JSON.

Response shapes follow what the API already does: `{"data": …}` for a resource or collection,
`{"message": …}` for an action that only succeeds or fails, the existing bespoke shapes
(`{"files": …}`, `{"url": …, "write": …}`) unchanged. Errors keep the existing handling in
`bootstrap/app.php`, including the 502 for a node that does not answer.

### Parity matrix

Every route below is new unless marked. `can:` is the owner permission the web route already
requires; where it is blank the route is admin-only through `can:manage-servers`,
`can:manage-users` or an explicit policy, exactly as on the web.

| Method | Path | Ability | Owner check |
| --- | --- | --- | --- |
| GET | `/me` | — | — |
| GET | `/servers` | `servers:read` | visible-to scope (existing) |
| POST | `/servers` | `servers:manage` | manage-servers |
| GET | `/servers/{server}` | `servers:read` | view (existing) |
| PUT | `/servers/{server}` | `servers:configure` | act configure |
| DELETE | `/servers/{server}` | `servers:manage` | manage-servers |
| POST | `/servers/{server}/move` | `servers:manage` | manage-servers |
| POST | `/servers/{server}/dns` | `servers:manage` | manage-servers |
| POST | `/servers/stop-all` | `servers:power` | manage-servers |
| POST | `/servers/{server}/power/{action}` | `servers:power` | act power (existing) |
| GET | `/servers/{server}/console` | `console:use` | view (existing) |
| GET | `/servers/{server}/logs` | `logs:read` | act logs (existing) |
| GET | `/servers/{server}/logs/download` | `logs:read` | act logs |
| GET | `/servers/{server}/files` | `files:read` | act files (existing) |
| PUT | `/servers/{server}/files` | `files:write` | act files (existing) |
| DELETE | `/servers/{server}/files` | `files:write` | act files (existing) |
| POST | `/servers/{server}/files/folder` | `files:write` | act files |
| POST | `/servers/{server}/files/rename` | `files:write` | act files |
| GET | `/servers/{server}/files/transfer` | `files:read` to download, `files:write` to upload | act files (existing) |
| GET | `/servers/{server}/backups` | `backups:read` | act backups (existing) |
| POST | `/servers/{server}/backups` | `backups:create` | act backups (existing) |
| DELETE | `/servers/{server}/backups/running` | `backups:create` | act backups |
| PUT | `/servers/{server}/backups/retention` | `backups:create` | act backups |
| GET | `/servers/{server}/backups/{backup}/download` | `backups:read` | act backups |
| POST | `/servers/{server}/backups/{backup}/restore` | `backups:restore` | act backups |
| POST | `/servers/{server}/backups/{backup}/lock` | `backups:restore` | act backups |
| DELETE | `/servers/{server}/backups/{backup}` | `backups:delete` | act backups |
| POST | `/servers/{server}/import` | `backups:restore` | act backups |
| POST | `/servers/{server}/schedules` | `schedules:manage` | act backups |
| PUT | `/servers/{server}/schedules/{schedule}` | `schedules:manage` | act backups |
| DELETE | `/servers/{server}/schedules/{schedule}` | `schedules:manage` | act backups |
| GET | `/nodes` | `nodes:read` | manage-users |
| POST | `/nodes` | `nodes:manage` | manage-users |
| PUT | `/nodes/{node}` | `nodes:manage` | manage-users |
| DELETE | `/nodes/{node}` | `nodes:manage` | manage-users |
| GET | `/users` | `users:read` | manage-users |
| POST | `/users` | `users:manage` | manage-users |
| PUT | `/users/{user}` | `users:manage` | manage-users |
| DELETE | `/users/{user}` | `users:manage` | manage-users |
| GET | `/settings` | `settings:read` | manage-users |
| PUT | `/settings` | `settings:manage` | manage-users |
| GET | `/activity` | `activity:read` | manage-users |
| GET | `/webhooks` | `webhooks:manage` | own webhooks |
| POST | `/webhooks` | `webhooks:manage` | own webhooks |
| PUT | `/webhooks/{webhook}` | `webhooks:manage` | own webhooks |
| DELETE | `/webhooks/{webhook}` | `webhooks:manage` | own webhooks |
| POST | `/webhooks/{webhook}/test` | `webhooks:manage` | own webhooks |
| GET | `/modpacks/search` | `modpacks:search` | search-modpacks |
| GET | `/modpacks/versions` | `modpacks:search` | search-modpacks |
| PATCH | `/me` | `profile:manage` | own account, name and locale only |
| GET | `/options` | `servers:read` | — |

`GET /options` returns what the server forms need (types, Minecraft versions, Java versions,
difficulties, modes, base domain, nodes with free memory), which the app needs to render a
create form without scraping the web pages.

`GET /settings` never returns a secret's value, only a "has a value" flag, exactly as the
Inertia page does.

### Device pairing

The app never sees a password. Flow:

1. App: `POST /api/v1/pair` `{device_name}` — no authentication, throttled 5 per minute per
   address. Returns `{code, secret, expires_at, interval}`. The code is eight characters from
   an alphabet without look-alikes (`ABCDEFGHJKMNPQRSTUVWXYZ23456789`), shown to the user. The
   secret is a 64-character random string the app keeps; only its hash is stored.
2. User, signed in to the panel: Settings → API tokens → **Pair a device**, types the code.
   The panel shows the device name and the ability picker, with **Read-only** preselected.
3. Approval mints the token with the chosen abilities, encrypts it onto the pairing row and
   stamps `approved_at`. `ActivityLog::record('token.pair', …)` with the device name and the
   abilities.
4. App: `POST /api/v1/pair/token` `{code, secret}`, polling at `interval` seconds. `202` with
   `{"status":"pending"}` until approved; then `200` with `{token, abilities, user}` and the
   row is deleted. `404` for unknown, expired or already-claimed.

Table `device_pairings`: `id`, `code` (unique), `secret_hash`, `device_name`, `token`
(encrypted, null until approved), `approved_at`, `expires_at`, `created_at`. Ten-minute
expiry. Expired rows are deleted when a new pairing is created and in the nightly
`activity:prune` run.

The minted token belongs to the approving user and can never exceed that user's role, so a
viewer who approves a device gets a viewer's token even if they tick `users:manage`: the
ability passes and the policy refuses. The picker only offers abilities the user could use.

### Ability picker

`resources/js/components/AbilityPicker.vue`, used by the token form and the pairing approval.

- Three preset chips at the top — Read-only, Operator, Full access — plus a Custom chip that
  lights up when the selection matches no preset. Choosing a preset replaces the selection.
- One collapsible section per group, each with a switch that turns the whole group on or off,
  and a count: `4 of 5`. Partly-on groups show a half state.
- Rows inside: label, one-line description, a switch.
- A plain-English summary under the form, built from the selection: "This token can watch and
  operate every server you can see, read its files, and nothing else."
- The Administration group is collapsed by default and shown only to admins.
- `v-model` is `string[]`; the groups, labels and presets come from the server as props, from
  `App\Api\Abilities`.

Accessibility: native `<button role="switch">` with `aria-checked`, group headers as real
buttons with `aria-expanded`, the count in the accessible name. The summary is `aria-live`
polite so a screen reader hears the effect of a preset.

## Data flow

A request arrives at `/api/v1/…` with a bearer token. `auth:sanctum` resolves the token and
its owner. `throttle:api` applies. `ability:…` checks what the token may do, resolving legacy
aliases. `can:…` checks what the owner may do on this server through `ServerPolicy`, which
reads `Server::permissionsFor()` — unchanged. The controller validates with the shared
FormRequest, calls the shared Action, and the Action talks to the node through `NodeClient`
and records to `ActivityLog`, which also feeds webhooks. The actor in the log reads
`Joppe (API token: Phone)`, as it already does.

## Error handling

Unchanged and already in `bootstrap/app.php`: 502 when the node does not answer, the node's
own 403/404/409/413/415 passed through, JSON for everything under `api/*`. New cases: 403 with
the missing ability named, 404 for an unknown or expired pairing, 422 for validation with
Laravel's shape, 429 from the limiter.

Rate limiting changes from the default to a named limiter: 120 requests a minute per token,
falling back to the address for unauthenticated pairing calls. An app polling server state
every few seconds under the current 60 would otherwise spend its budget on polling.

## Testing

- **Route guard test**: every registered `api/v1` route declares an `ability:` middleware
  except `/me` and the two pairing routes. Catches a forgotten guard on a new endpoint.
- **Documentation test**: the existing `the api documentation covers every api route` test
  already fails when `openapi.json` and the routes disagree. Every new endpoint is documented
  as part of the work, not after it.
- **Escalation tests**: a viewer's token with `users:manage` gets 403; a token without an
  ability gets 403 with the ability named; a legacy `backups:manage` token can list and create
  but not restore or delete.
- **Account-critical test**: asserts no `api/v1` route exists for login, password, two-factor,
  passkeys or token management.
- **Pairing tests**: happy path end to end; wrong secret; expired code; a claimed code cannot
  be claimed twice; an unapproved code answers 202.
- **Per-group feature tests** reusing the existing `Http::fake()` patterns for the node.
- **Picker tests**: a preset fills the selection; a group switch toggles its rows; the summary
  names what was chosen.

## Phases

1. **Abilities core** — `App\Api\Abilities`, `TokenAbility` middleware, legacy alias, the
   named limiter, route-guard test. Existing endpoints move to the middleware and lose their
   repeated `abort_unless`.
2. **API expansion** — the controllers and routes in the parity matrix, group by group, each
   with its documentation and tests: servers, files, backups and schedules, administration,
   account.
3. **Ability picker** — the component, the token form rebuilt on it, translations.
4. **Device pairing** — migration, endpoints, the panel screen, activity, tests.

Each phase ends green: `php artisan test`, `vendor/bin/pint --dirty`, `composer types:check`,
`npm run check:fix`, `npm run types:check`, `php artisan wayfinder:generate --with-form`.

## Risks

- **The surface doubles.** Every admin action becomes reachable with a stolen token. Mitigated
  by: account-critical routes staying web-only, a token never exceeding its owner, the default
  preset being Read-only, and `token.pair` plus every action already landing in the activity
  log.
- **Drift between web and API.** Mitigated by sharing Actions and FormRequests rather than
  reimplementing; a future web change that bypasses an Action would break parity silently,
  which the per-group tests are there to catch.
- **`openapi.json` grows to roughly fifty paths.** It is already the public contract and a
  test enforces it; the work is in the phases, not after them.
