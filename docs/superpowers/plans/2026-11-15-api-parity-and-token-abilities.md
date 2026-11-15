# API Parity and Fine-Grained Token Abilities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `/api/v1` an equivalent for every web action a mobile app needs, guarded by 23 fine-grained token abilities, with a grouped ability picker and a device-pairing flow so no password reaches the app.

**Architecture:** Thin `Api/V1` controllers reuse the Actions and FormRequests the web controllers already use, so the behaviour has one implementation and only the response differs. Each route declares two guards side by side: `ability:<name>` for what the token may do, and the existing `can:` middleware for what its owner may do. One class, `App\Api\Abilities`, is the single source of truth feeding the middleware, the validation, the Vue picker and the OpenAPI document.

**Tech Stack:** Laravel 13, Sanctum 4, Inertia v3, Vue 3 + TypeScript, Tailwind v4, Pest, PHPStan, Pint.

**Spec:** `docs/superpowers/specs/2026-11-15-api-parity-and-token-abilities-design.md`

## Global Constraints

- All work happens in `panel/`. The Rust service in `service/` is **not** touched by this plan.
- Follow `panel/AGENTS.md` for Laravel conventions and `CLAUDE.md` for project rules.
- Every interface text goes through `t()` (frontend) or `__()` (backend) with the English text as the key, and must be a **literal** in the call so `lang:sync` can find it.
- A token can never exceed its owner's role or per-server share. `ability:` never replaces `can:`; it is added beside it.
- `panel/composer.json` version stays `1.5.0` — this ships in the version already in flight.
- Form numbers arrive as strings; cast on the model. Tests send strings.
- `Http::fake()`: the first registered stub wins. To replace one set in `beforeEach`, call `Http::swap(new HttpFactory)` first.
- Tests need built assets: run `npm run build` once before `php artisan test`.
- After changing routes, run `php artisan wayfinder:generate --with-form`.
- No API equivalent may be added for: login, logout, password change or reset, e-mail change, two-factor, passkeys, account deletion, or creating/revoking API tokens.
- **Before Task 1:** the working tree on `main` holds the rest of 1.5.0 uncommitted (node TLS, moving servers, activity pruning; `MoveServer.php` and `PruneActivity.php` are untracked and this plan uses both). Commit that work on a branch first and build this plan on top of it, so each task's commit holds only its own files.
- The existing tests authenticate with `Sanctum::actingAs($user, [...])`, which gives the user a **mocked** token: `$token->abilities` is null on it and only `can()` answers. Anything that decides what a token may do must ask `$request->user()->tokenCan(...)`, never read `->abilities`.
- The sanctum guard keeps the first user and token it resolved **for the whole test**, so a second request with another token is silently answered as the first one. Every test in this plan sends its token with `bearer($token)` (added to `tests/Pest.php` in Task 1), which forgets the guards first. Never write the `Authorization` header by hand, and call `app('auth')->forgetGuards()` before a token-less request that follows a request with a token.
- An API endpoint records the same `ActivityLog` events as its web twin. The log is how an admin sees what a token did.

**Every task ends green on:**

```sh
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer types:check
npm run check:fix && npm run types:check
```

## Review Focus

What the spec implies but is easy to ship without. Each has its test in the task named.

1. **A token made before 1.5.0 loses something.** A `files:write`-only token can fetch both transfer addresses today, and a `backups:manage` token can list and start backups. Both must still work (Task 3, the existing `AccessTest` cases; Task 2, the retired-name test).
2. **A mocked token is refused everywhere.** `Sanctum::actingAs` tokens have no `abilities` attribute; the middleware must pass them (Task 2, `a token from Sanctum::actingAs passes the guard`).
3. **An app that polls as told is rate-limited.** Polling `pair/token` every 3 seconds is 20 calls a minute; the limit must sit above that and apart from the limit on starting a pairing (Task 14, `polling at the advertised interval is never throttled`).
4. **A viewer is offered abilities they can never use.** The picker must not show the Administration group to a viewer, and their presets must not contain it (Tasks 1 and 13, `a viewer is not offered the administration abilities`).
5. **A secret can be set through the API but never removed.** `remove_curseforge_key` and `remove_cloudflare_token` must work as on the web form (Task 11, `a saved secret can be removed`).

## File Structure

| File | Responsibility |
| --- | --- |
| `app/Api/Abilities.php` | The 23 abilities, their groups, labels, presets and legacy aliases. Single source of truth. |
| `app/Http/Middleware/TokenAbility.php` | Route guard `ability:<name>`; resolves legacy aliases; 403 names the missing ability. |
| `app/Http/Controllers/Api/V1/MeController.php` | Who the token belongs to, what it may do, and the choices the server forms need. |
| `app/Http/Controllers/Api/V1/ServerController.php` | Existing. Extended with create, update, delete, move, dns, stop-all. |
| `app/Http/Controllers/Api/V1/FileController.php` | Folder, rename, and downloading a whole log. (List/read/write/delete/transfer stay on `ServerController`.) |
| `app/Http/Controllers/Api/V1/BackupController.php` | List, create, cancel, retention, download, restore, lock, delete, world import. |
| `app/Http/Controllers/Api/V1/ScheduleController.php` | Schedules on a server. |
| `app/Http/Controllers/Api/V1/NodeController.php` | Nodes. |
| `app/Http/Controllers/Api/V1/UserController.php` | Users and their shares. |
| `app/Http/Controllers/Api/V1/PanelController.php` | Panel settings and the activity log. |
| `app/Http/Controllers/Api/V1/WebhookController.php` | The owner's own webhooks. |
| `app/Http/Controllers/Api/V1/ModpackController.php` | Modpack search and versions. |
| `app/Http/Controllers/Api/V1/PairingController.php` | `POST /pair` and `POST /pair/token`. |
| `app/Models/DevicePairing.php` | A pending pairing: code, secret hash, device name, minted token, expiry. |
| `resources/js/components/AbilityPicker.vue` | Presets, collapsible groups, per-ability switches, live summary. |
| `resources/api/openapi.json` | The public contract. A test fails when it and the routes disagree. |

---

### Task 1: The abilities catalogue

**Files:**
- Create: `app/Api/Abilities.php`
- Modify: `tests/Pest.php` (the `bearer()` helper)
- Test: `tests/Feature/ApiAbilityTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Api\Abilities` with `all(): list<string>`, `groups(): list<array{key: string, label: string, abilities: list<array{name: string, label: string, description: string}>}>`, `presets(): array<string, list<string>>`, `resolve(array $granted): list<string>`, `grants(string $ability): list<string>` (the names that give an ability: itself plus any retired name that stands for it), `offeredTo(User $user): array{groups: ..., presets: ...}` (what the picker shows that user), and the constant `LEGACY`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Api\Abilities;

test('the catalogue lists every ability once, grouped, with a label for each', function () {
    $all = Abilities::all();

    expect($all)->toHaveCount(23)
        ->and($all)->toBe(array_unique($all))
        ->and($all)->toContain('servers:read', 'backups:restore', 'nodes:manage', 'profile:manage')
        // The retired name is never offered.
        ->and($all)->not->toContain('backups:manage');

    $grouped = collect(Abilities::groups())->flatMap(fn (array $group): array => array_column($group['abilities'], 'name'))->all();
    expect($grouped)->toBe($all);

    foreach (Abilities::groups() as $group) {
        expect($group['label'])->not->toBeEmpty();
        foreach ($group['abilities'] as $ability) {
            expect($ability['label'])->not->toBeEmpty()->and($ability['description'])->not->toBeEmpty();
        }
    }
});

test('a preset is a subset of the catalogue and full access is all of it', function () {
    $presets = Abilities::presets();

    expect(array_keys($presets))->toBe(['read-only', 'operator', 'full'])
        ->and($presets['full'])->toBe(Abilities::all())
        ->and(array_diff($presets['operator'], Abilities::all()))->toBeEmpty()
        ->and(array_diff($presets['read-only'], $presets['operator']))->toBeEmpty()
        ->and($presets['read-only'])->toContain('servers:read')
        ->and($presets['read-only'])->not->toContain('servers:power')
        ->and($presets['operator'])->toContain('servers:power', 'backups:restore')
        ->and($presets['operator'])->not->toContain('nodes:manage', 'servers:manage');
});

test('the retired backups:manage name resolves to what it allows today and nothing more', function () {
    expect(Abilities::resolve(['backups:manage']))->toBe(['backups:read', 'backups:create'])
        ->and(Abilities::resolve(['servers:read', 'backups:manage']))->toBe(['servers:read', 'backups:read', 'backups:create'])
        ->and(Abilities::resolve(['files:write']))->toBe(['files:write'])
        ->and(Abilities::resolve(['*']))->toBe(['*'])
        // The other way round, for the route guard: which names give an ability.
        ->and(Abilities::grants('backups:read'))->toBe(['backups:read', 'backups:manage'])
        ->and(Abilities::grants('backups:restore'))->toBe(['backups:restore']);
});

test('a viewer is not offered the administration abilities', function () {
    $viewer = Abilities::offeredTo(App\Models\User::factory()->create());
    $admin = Abilities::offeredTo(App\Models\User::factory()->admin()->create());

    expect(array_column($viewer['groups'], 'key'))->not->toContain('administration')
        ->and($viewer['presets']['read-only'])->not->toContain('activity:read')
        ->and($viewer['presets']['full'])->not->toContain('users:manage')
        ->and($admin['groups'])->toBe(Abilities::groups())
        ->and($admin['presets'])->toBe(Abilities::presets());
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiAbilityTest`
Expected: FAIL, `Class "App\Api\Abilities" not found`.

- [ ] **Step 3: Write the catalogue**

```php
<?php

namespace App\Api;

/**
 * What an API token may be allowed to do. The one source of truth: the ability middleware, the
 * token form, the pairing screen and the API documentation all read this.
 *
 * A token never exceeds its owner: these say what the token may ask for, the policies and gates
 * still say what its owner may do.
 */
class Abilities
{
    // Names issued before 1.5.0 that are no longer offered, and what they stand for now.
    public const array LEGACY = ['backups:manage' => ['backups:read', 'backups:create']];

    /**
     * Every ability, by group. The labels are what the token form shows.
     *
     * @return list<array{key: string, label: string, abilities: list<array{name: string, label: string, description: string}>}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'servers', 'label' => (string) __('Servers'), 'abilities' => [
                ['name' => 'servers:read', 'label' => (string) __('See servers'), 'description' => (string) __('List servers and read their live state.')],
                ['name' => 'servers:power', 'label' => (string) __('Power'), 'description' => (string) __('Start, stop, restart and kill servers.')],
                ['name' => 'servers:configure', 'label' => (string) __('Configure'), 'description' => (string) __("Change a server's settings and recreate its container.")],
                ['name' => 'servers:manage', 'label' => (string) __('Create and delete'), 'description' => (string) __('Create and delete servers, move them between nodes, and add DNS records.')],
            ]],
            ['key' => 'console', 'label' => (string) __('Console and logs'), 'abilities' => [
                ['name' => 'console:use', 'label' => (string) __('Use the console'), 'description' => (string) __('Open the live console. Sending commands still needs that permission on the server.')],
                ['name' => 'logs:read', 'label' => (string) __('Read logs'), 'description' => (string) __('List, read and download log files.')],
            ]],
            ['key' => 'files', 'label' => (string) __('Files'), 'abilities' => [
                ['name' => 'files:read', 'label' => (string) __('Read files'), 'description' => (string) __('Browse folders, read and download files.')],
                ['name' => 'files:write', 'label' => (string) __('Write files'), 'description' => (string) __('Save, upload, rename, create folders and delete.')],
            ]],
            ['key' => 'backups', 'label' => (string) __('Backups'), 'abilities' => [
                ['name' => 'backups:read', 'label' => (string) __('See backups'), 'description' => (string) __('List backups and download one.')],
                ['name' => 'backups:create', 'label' => (string) __('Make backups'), 'description' => (string) __('Start a backup, stop a running one and set how long they are kept.')],
                ['name' => 'backups:restore', 'label' => (string) __('Restore'), 'description' => (string) __('Restore a backup, lock one, and import a world.')],
                ['name' => 'backups:delete', 'label' => (string) __('Delete backups'), 'description' => (string) __('Remove a backup for good.')],
            ]],
            ['key' => 'schedules', 'label' => (string) __('Schedules'), 'abilities' => [
                ['name' => 'schedules:manage', 'label' => (string) __('Schedules'), 'description' => (string) __('Add, pause and remove scheduled actions.')],
            ]],
            ['key' => 'administration', 'label' => (string) __('Administration'), 'abilities' => [
                ['name' => 'nodes:read', 'label' => (string) __('See nodes'), 'description' => (string) __('List the hosts that run servers and their state.')],
                ['name' => 'nodes:manage', 'label' => (string) __('Manage nodes'), 'description' => (string) __('Add, change and remove nodes.')],
                ['name' => 'users:read', 'label' => (string) __('See users'), 'description' => (string) __('List users and what is shared with them.')],
                ['name' => 'users:manage', 'label' => (string) __('Manage users'), 'description' => (string) __('Invite, change and remove users and their shares.')],
                ['name' => 'settings:read', 'label' => (string) __('See settings'), 'description' => (string) __('Read the panel settings. Secrets are never sent.')],
                ['name' => 'settings:manage', 'label' => (string) __('Change settings'), 'description' => (string) __('Change the panel settings.')],
                ['name' => 'activity:read', 'label' => (string) __('Read the activity log'), 'description' => (string) __('See who did what in the panel.')],
            ]],
            ['key' => 'account', 'label' => (string) __('Own account'), 'abilities' => [
                ['name' => 'profile:manage', 'label' => (string) __('Change your name and language'), 'description' => (string) __('Nothing else: not your e-mail, password, two-factor or tokens.')],
                ['name' => 'webhooks:manage', 'label' => (string) __('Webhooks'), 'description' => (string) __('Add, change, test and remove your own webhooks.')],
                ['name' => 'modpacks:search', 'label' => (string) __('Search modpacks'), 'description' => (string) __('Search Modrinth, CurseForge and FTB.')],
            ]],
        ];
    }

    /**
     * Every ability name, in the order the groups list them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(fn (array $group): array => array_column($group['abilities'], 'name'), self::groups()));
    }

    /**
     * The three starting points the token form offers.
     *
     * @return array<string, list<string>>
     */
    public static function presets(): array
    {
        $readOnly = ['servers:read', 'logs:read', 'files:read', 'backups:read', 'activity:read'];

        return [
            'read-only' => $readOnly,
            'operator' => [...$readOnly, 'servers:power', 'console:use', 'files:write', 'backups:create', 'backups:restore', 'schedules:manage'],
            'full' => self::all(),
        ];
    }

    /**
     * What a token's stored abilities mean now, with retired names expanded. "*" is Sanctum's
     * "everything" and is left alone.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public static function resolve(array $granted): array
    {
        $resolved = [];

        foreach ($granted as $ability) {
            array_push($resolved, ...(self::LEGACY[$ability] ?? [$ability]));
        }

        return array_values(array_unique($resolved));
    }

    /**
     * The names that give an ability: itself, and any retired name that stands for it. The route
     * guard asks the token for each, because a test token only answers can(), it has no list.
     *
     * @return list<string>
     */
    public static function grants(string $ability): array
    {
        return [$ability, ...array_keys(array_filter(self::LEGACY, fn (array $stands): bool => in_array($ability, $stands, true)))];
    }

    /**
     * What the picker shows a user: a viewer could never use the administration abilities, so
     * they are not offered them, in the groups or in a preset.
     *
     * @return array{groups: list<array{key: string, label: string, abilities: list<array{name: string, label: string, description: string}>}>, presets: array<string, list<string>>}
     */
    public static function offeredTo(User $user): array
    {
        $groups = array_values(array_filter(self::groups(), fn (array $group): bool => $user->isAdmin() || $group['key'] !== 'administration'));
        $names = array_merge(...array_map(fn (array $group): array => array_column($group['abilities'], 'name'), $groups));

        return [
            'groups' => $groups,
            'presets' => array_map(fn (array $preset): array => array_values(array_intersect($preset, $names)), self::presets()),
        ];
    }
}
```

with `use App\Models\User;` at the top.

In `tests/Pest.php`, replace the placeholder `something()` function with the helper every later
test sends its token through:

```php
/**
 * The header for a request with an API token. The sanctum guard keeps the first token it saw
 * for the whole test, so it is told to forget before each one.
 *
 * @return array<string, string>
 */
function bearer(string $token): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => "Bearer {$token}"];
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter=ApiAbilityTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Run the full checks and commit**

```sh
php artisan test --compact && vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Api/Abilities.php panel/tests/Pest.php panel/tests/Feature/ApiAbilityTest.php
git commit -m "Add the catalogue of API token abilities"
```

---

### Task 2: The ability middleware

**Files:**
- Create: `app/Http/Middleware/TokenAbility.php`
- Modify: `bootstrap/app.php` (register the alias in `withMiddleware`)
- Test: `tests/Feature/ApiAbilityTest.php` (append)

**Interfaces:**
- Consumes: `Abilities::resolve()` from Task 1.
- Produces: route middleware `ability:<name>`, which aborts 403 with the message `This token lacks the <name> ability.` Several names, `ability:files:read,files:write`, mean any one of them is enough; the message names the first.

- [ ] **Step 1: Write the failing test**

```php
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

test('a route guarded by an ability refuses a token without it and names what is missing', function () {
    Http::fake(['node.test:8080/*' => Http::response([])]);
    Route::middleware(['auth:sanctum', 'ability:files:write'])->get('/api/v1/testing-ability', fn (): array => ['ok' => true]);

    $user = User::factory()->admin()->create();
    $narrow = $user->createToken('Narrow', ['files:read'])->plainTextToken;
    $wide = $user->createToken('Wide', ['files:write'])->plainTextToken;

    $this->getJson('/api/v1/testing-ability', bearer($narrow))
        ->assertStatus(403)
        ->assertJsonPath('message', 'This token lacks the files:write ability.');

    $this->getJson('/api/v1/testing-ability', bearer($wide))->assertOk();

    // Without a token; the guard must forget the one it just saw.
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/testing-ability')->assertStatus(401);
});

test('a route that lists several abilities takes any one of them', function () {
    Route::middleware(['auth:sanctum', 'ability:files:read,files:write'])->get('/api/v1/testing-either', fn (): array => ['ok' => true]);
    $user = User::factory()->admin()->create();

    foreach (['files:read', 'files:write'] as $ability) {
        $token = $user->createToken($ability, [$ability])->plainTextToken;
        $this->getJson('/api/v1/testing-either', bearer($token))->assertOk();
    }

    $neither = $user->createToken('Logs', ['logs:read'])->plainTextToken;
    $this->getJson('/api/v1/testing-either', bearer($neither))
        ->assertStatus(403)
        ->assertJsonPath('message', 'This token lacks the files:read ability.');
});

test('a token from Sanctum::actingAs passes the guard', function () {
    // Such a token is a mock: it answers can() and has no abilities attribute to read.
    Route::middleware(['auth:sanctum', 'ability:backups:read'])->get('/api/v1/testing-mock', fn (): array => ['ok' => true]);

    Laravel\Sanctum\Sanctum::actingAs(User::factory()->create(), ['backups:manage']);
    $this->getJson('/api/v1/testing-mock')->assertOk();

    Laravel\Sanctum\Sanctum::actingAs(User::factory()->create(), ['files:read']);
    $this->getJson('/api/v1/testing-mock')->assertStatus(403);
});

test('a token issued before 1.5.0 keeps working through the retired name', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    Http::fake(['node.test:8080/*' => Http::response([])]);
    $token = User::factory()->admin()->create()->createToken('Old', ['backups:manage'])->plainTextToken;

    $this->getJson("/api/v1/servers/{$server->slug}/backups", bearer($token))->assertOk();
    $this->postJson("/api/v1/servers/{$server->slug}/backups", [], bearer($token))->assertStatus(202);
    // But not the destructive ones it never had.
    $this->postJson("/api/v1/servers/{$server->slug}/backups/1794600000.tar.gz/restore", [], bearer($token))
        ->assertStatus(403)
        ->assertJsonPath('message', 'This token lacks the backups:restore ability.');
});
```

The retired-name test is expected to stay red until Task 6, which adds the restore route; leave it out of the filter in Step 2 and Step 4 and note it as pending.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter="a route guarded by an ability|several abilities|actingAs passes"`
Expected: FAIL, `Target class [ability] does not exist.`

- [ ] **Step 3: Write the middleware and register it**

`app/Http/Middleware/TokenAbility.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Api\Abilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the token may ask for, beside the can: middleware that says what its owner may do.
 * Used as ability:files:write on an API route; with several names, any one is enough.
 */
class TokenAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user() ?? abort(401);

        // Asked of the token one name at a time, never read from its list: a session answers yes
        // to everything, "*" does too, and a test token only knows how to answer.
        foreach ($abilities as $ability) {
            foreach (Abilities::grants($ability) as $name) {
                if ($user->tokenCan($name)) {
                    return $next($request);
                }
            }
        }

        abort(403, "This token lacks the {$abilities[0]} ability.");
    }
}
```

In `bootstrap/app.php`, inside `->withMiddleware(function (Middleware $middleware): void {`, add after the `trimStrings` line:

```php
        // Guards an API route by what the token may do; the can: middleware guards what its owner may do.
        $middleware->alias(['ability' => TokenAbility::class]);
```

and the import `use App\Http\Middleware\TokenAbility;` at the top.

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="a route guarded by an ability|several abilities|actingAs passes"`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Middleware/TokenAbility.php panel/bootstrap/app.php panel/tests/Feature/ApiAbilityTest.php
git commit -m "Guard API routes with a token ability middleware"
```

---

### Task 3: Move the existing endpoints onto the middleware

**Files:**
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/Api/V1/ServerController.php` (remove every `abort_unless(... tokenCan ...)`)
- Modify: `app/Providers/AppServiceProvider.php:44` (the `api` limiter)
- Test: `tests/Feature/ApiGuardTest.php`

**Interfaces:**
- Consumes: `ability:` from Task 2.
- Produces: `routes/api.php` where every route carries an `ability:` middleware; the guard test that keeps it that way.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Illuminate\Support\Facades\Route;

test('every api route declares which ability it needs', function () {
    // These are the only ones a plain valid token may call: who am I, and the pairing handshake.
    $exempt = ['GET api/v1/me', 'POST api/v1/pair', 'POST api/v1/pair/token'];
    $unguarded = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') && $route->uri() !== 'api/v1') {
            continue;
        }

        $name = $route->methods()[0].' '.$route->uri();
        $guarded = collect($route->gatherMiddleware())->contains(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'ability:'));

        if (! $guarded && ! in_array($name, $exempt, true)) {
            $unguarded[] = $name;
        }
    }

    expect($unguarded)->toBe([]);
});

test('an api token may make 120 requests a minute, counted per token', function () {
    $request = Illuminate\Http\Request::create('/api/v1/servers');
    $request->headers->set('Authorization', 'Bearer 1|abc');

    $limit = Illuminate\Support\Facades\RateLimiter::limiter('api')($request);

    // Two tokens of one user each get their own budget.
    expect($limit->maxAttempts)->toBe(120)->and($limit->key)->toBe(hash('sha256', '1|abc'));
});

test('a token that may only read files gets a download address and no upload address', function () {
    $server = App\Models\Server::factory()->create(['slug' => 'survival']);
    $admin = App\Models\User::factory()->admin()->create();

    $this->getJson('/api/v1/servers/survival/files/transfer', bearer($admin->createToken('Read', ['files:read'])->plainTextToken))
        ->assertOk()->assertJsonStructure(['download'])->assertJsonMissingPath('upload');

    // As before 1.5.0: a token that may write gets both.
    $this->getJson('/api/v1/servers/survival/files/transfer', bearer($admin->createToken('Write', ['files:write'])->plainTextToken))
        ->assertOk()->assertJsonStructure(['upload', 'download']);

    $this->getJson('/api/v1/servers/survival/files/transfer', bearer($admin->createToken('Logs', ['logs:read'])->plainTextToken))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the files:read ability.');
});
```


- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiGuardTest`
Expected: FAIL — the route list is unguarded, `maxAttempts` is 60, and a `files:read` token is refused the transfer addresses.

- [ ] **Step 3: Rewrite the API routes and drop the inline checks**

`routes/api.php`:

```php
<?php

use App\Actions\Servers\PowerServer;
use App\Http\Controllers\Api\V1\ServerController;
use Illuminate\Support\Facades\Route;

// Two guards on every route: ability: is what the token may ask for, can: is what its owner
// may do. A token never exceeds its owner.
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('servers', [ServerController::class, 'index'])->middleware('ability:servers:read');

    Route::middleware('can:view,server')->group(function () {
        Route::get('servers/{server}', [ServerController::class, 'show'])->middleware('ability:servers:read');
        Route::get('servers/{server}/console', [ServerController::class, 'console'])->middleware('ability:console:use');
    });

    Route::get('servers/{server}/logs', [ServerController::class, 'logs'])
        ->middleware(['ability:logs:read', 'can:act,server,"logs"']);

    Route::middleware('can:act,server,"backups"')->group(function () {
        Route::get('servers/{server}/backups', [ServerController::class, 'backups'])->middleware('ability:backups:read');
        Route::post('servers/{server}/backups', [ServerController::class, 'backup'])->middleware('ability:backups:create');
    });

    Route::middleware('can:act,server,"files"')->group(function () {
        Route::get('servers/{server}/files', [ServerController::class, 'files'])->middleware('ability:files:read');
        Route::put('servers/{server}/files', [ServerController::class, 'writeFile'])->middleware('ability:files:write');
        Route::delete('servers/{server}/files', [ServerController::class, 'deleteFile'])->middleware('ability:files:write');
        // Either is enough: a token that may write could always fetch both addresses.
        Route::get('servers/{server}/files/transfer', [ServerController::class, 'transfer'])->middleware('ability:files:read,files:write');
    });

    Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])
        ->whereIn('action', PowerServer::ACTIONS)
        ->middleware(['ability:servers:power', 'can:act,server,"power"']);
});
```

In `app/Http/Controllers/Api/V1/ServerController.php`, delete every line of the form
`abort_unless($request->user()->tokenCan('…'), 403, '…');` — one in each of the eleven public
methods. Where removing it leaves `$request` unused, drop the parameter (`show()`, `backups()`).

`transfer()` hands out the upload address only to a token that may write. Its body becomes:

```php
        $client = new NodeClient($server->node);

        // The download address was always part of this answer; the upload one needs files:write.
        return response()->json(array_filter([
            'upload' => $request->user()->tokenCan('files:write') ? $client->fileUrl($server, 'upload') : null,
            'download' => $client->fileUrl($server, 'download'),
        ]));
```

One existing assertion changes on purpose. In `tests/Feature/AccessTest.php`, in
`the api reads and writes files only with the files abilities, for admins`, the `files:read`
token used to be refused the transfer addresses; the spec now gives it the download one. Replace

```php
    $this->getJson('/api/v1/servers/shared/files/transfer')->assertForbidden();
```

with

```php
    $this->getJson('/api/v1/servers/shared/files/transfer')->assertJsonStructure(['download'])->assertJsonMissingPath('upload');
```

No other existing test may be edited: they are the proof that old tokens keep their power.

In `app/Providers/AppServiceProvider.php`, replace the limiter line with:

```php
        // Per token, so two apps of one user do not eat each other's budget; an app polling
        // server state would spend 60 a minute on polling alone.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->bearerToken() !== null ? hash('sha256', $request->bearerToken()) : (string) ($request->user()?->id ?: $request->ip()),
        ));
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter=ApiGuardTest`
Expected: PASS. Then `php artisan test --compact` — the existing API tests in `AdminTest.php` and `AccessTest.php` must pass with only the one assertion above changed.

- [ ] **Step 5: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/routes/api.php panel/app/Http/Controllers/Api/V1/ServerController.php panel/app/Providers/AppServiceProvider.php panel/tests/Feature/ApiGuardTest.php panel/tests/Feature/AccessTest.php
git commit -m "Move the API to ability middleware and raise its rate limit"
```

---

### Task 4: Who the token belongs to, and the form choices

**Files:**
- Create: `app/Http/Controllers/Api/V1/MeController.php`
- Modify: `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiMeTest.php`

**Interfaces:**
- Consumes: `Abilities::resolve()` (Task 1), `ability:` (Task 2).
- Produces: `GET /api/v1/me` and `GET /api/v1/options`. The app reads `data.abilities` to decide what to show, and `options` to render a create form.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('me says who the token belongs to and what it may do, without an ability of its own', function () {
    $user = User::factory()->admin()->create(['name' => 'Joppe', 'locale' => 'nl']);
    $token = $user->createToken('Phone', ['servers:read', 'backups:manage'])->plainTextToken;

    $this->getJson('/api/v1/me', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'Joppe')
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.locale', 'nl')
        ->assertJsonPath('data.can.manage_servers', true)
        // The retired name is reported as what it now means.
        ->assertJsonPath('data.abilities', ['servers:read', 'backups:read', 'backups:create']);
});

test('me refuses without a token', function () {
    $this->getJson('/api/v1/me')->assertStatus(401);
});

test('options gives the app what the create form needs', function () {
    // The Minecraft version list is fetched from Mojang; nothing in a test may reach the network.
    Http::fake(['*' => Http::response(['versions' => []])]);
    Node::factory()->create(['name' => 'home', 'memory_limit_mb' => 8192]);
    $token = User::factory()->admin()->create()->createToken('Phone', ['servers:read'])->plainTextToken;

    $this->getJson('/api/v1/options', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.types.0', 'VANILLA')
        ->assertJsonPath('data.java_versions', [8, 17, 21, 25])
        ->assertJsonPath('data.difficulties', ['peaceful', 'easy', 'normal', 'hard'])
        ->assertJsonPath('data.nodes.0.name', 'home')
        ->assertJsonPath('data.nodes.0.free_mb', 8192);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiMeTest`
Expected: FAIL with 404 — the routes do not exist.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/MeController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Abilities;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Server;
use App\Models\Setting;
use App\Services\MemoryLimit;
use App\Services\MinecraftVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class MeController extends Controller
{
    /**
     * Who this token belongs to and what it may do. Needs no ability of its own: an app has to
     * be able to ask what it is allowed to show.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'locale' => $user->locale,
            'abilities' => $token instanceof PersonalAccessToken ? Abilities::resolve($token->abilities ?: []) : ['*'],
            'can' => [
                'manage_servers' => $user->can('manage-servers'),
                'manage_users' => $user->can('manage-users'),
                'search_modpacks' => $user->can('search-modpacks'),
            ],
        ]]);
    }

    /**
     * The choices the server forms offer, so an app can render them without reading a web page.
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'base_domain' => Setting::value('base_domain'),
            'types' => Server::TYPES,
            'pack_types' => Server::PACK_TYPES,
            'java_versions' => Server::JAVA_VERSIONS,
            'mc_versions' => MinecraftVersions::releases(),
            'difficulties' => Server::DIFFICULTIES,
            'modes' => Server::MODES,
            'permissions' => Server::PERMISSIONS,
            'curseforge' => filled(Setting::value('curseforge_key')),
            'nodes' => Node::query()->orderBy('id')->get()->map(fn (Node $node): array => [
                'id' => $node->id,
                'name' => $node->name,
                'limit_mb' => MemoryLimit::mb($node),
                'free_mb' => MemoryLimit::freeMb($node),
            ]),
        ]]);
    }
}
```

In `routes/api.php`, inside the `v1` group, above the server routes, with
`use App\Http\Controllers\Api\V1\MeController;` at the top:

```php
    // No ability: an app must be able to ask who it is and what it may do.
    Route::get('me', [MeController::class, 'show']);
    Route::get('options', [MeController::class, 'options'])->middleware('ability:servers:read');
```

- [ ] **Step 4: Document both paths in `resources/api/openapi.json`**

Add to `paths`, keeping the file's style — `summary` and `description` are collected by
`lang:sync`, so they must read as sentences:

```json
"/me": {
  "get": {
    "summary": "Show who the token belongs to and what it may do",
    "description": "Needs no ability. Returns the owner, their role and the token's abilities with retired names expanded.",
    "responses": {
      "200": { "description": "The token's owner" },
      "401": { "description": "No valid token" }
    }
  }
},
"/options": {
  "get": {
    "summary": "List the choices the server forms offer",
    "description": "Needs servers:read. Server types, Minecraft and Java versions, difficulties, modes, and the nodes with their free memory.",
    "responses": {
      "200": { "description": "The choices" },
      "403": { "description": "The token lacks servers:read" }
    }
  }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiMeTest|api documentation covers"`
Expected: PASS. The documentation test proves both new paths are documented.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1/MeController.php panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiMeTest.php
git commit -m "Add the me and options endpoints to the API"
```

---

### Task 5: Creating, changing, deleting and moving servers

**Files:**
- Modify: `app/Http/Controllers/Api/V1/ServerController.php`, `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiServerTest.php`

**Interfaces:**
- Consumes: `StoreServerRequest`, `UpdateServerRequest`, `CreateServer`, `UpdateServer`, `DeleteServer` and the `MoveServer` job — all unchanged.
- Produces: `POST /servers`, `PUT /servers/{server}`, `DELETE /servers/{server}`, `POST /servers/{server}/move`, `POST /servers/{server}/dns`, `POST /servers/stop-all`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\MoveServer;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->node = Node::factory()->create(['name' => 'home', 'base_url' => 'http://node.test:8080']);
    $this->admin = User::factory()->admin()->create();
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
});

test('a token with servers:manage creates a server through the same action as the web form', function () {
    $token = $this->admin->createToken('Phone', ['servers:manage'])->plainTextToken;

    $this->postJson('/api/v1/servers', [
        'name' => 'Survival', 'slug' => 'survival', 'server_type' => 'PAPER', 'mc_version' => '1.21.10',
        'java_version' => '21', 'memory_mb' => '4096', 'motd' => 'hi', 'difficulty' => 'normal',
        'mode' => 'survival', 'max_players' => '20', 'eula' => '1',
    ], bearer($token))->assertStatus(201)->assertJsonPath('data.slug', 'survival');

    expect(Server::sole()->name)->toBe('Survival');
});

test('configuring is a different ability from creating', function () {
    $server = Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $creator = $this->admin->createToken('Creator', ['servers:manage'])->plainTextToken;

    $this->putJson('/api/v1/servers/survival', ['name' => 'Renamed'], bearer($creator))
        ->assertStatus(403)
        ->assertJsonPath('message', 'This token lacks the servers:configure ability.');

    $configurer = $this->admin->createToken('Configurer', ['servers:configure'])->plainTextToken;
    $this->putJson('/api/v1/servers/survival', [
        'name' => 'Renamed', 'server_type' => 'PAPER', 'mc_version' => '1.21.10', 'java_version' => '21',
        'memory_mb' => '4096', 'motd' => 'hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20',
    ], bearer($configurer))->assertOk();

    expect($server->refresh()->name)->toBe('Renamed');
});

test('a viewer token cannot create or delete, whatever its abilities say', function () {
    $viewer = User::factory()->create();
    $server = Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $server->viewers()->attach($viewer, ['permissions' => json_encode(['power'])]);
    $token = $viewer->createToken('Phone', ['servers:manage'])->plainTextToken;

    $this->postJson('/api/v1/servers', [], bearer($token))->assertStatus(403);
    $this->deleteJson('/api/v1/servers/survival', [], bearer($token))->assertStatus(403);
});

test('a move is queued and a delete removes the server', function () {
    Queue::fake();
    $elsewhere = Node::factory()->create(['name' => 'attic', 'base_url' => 'http://attic.test:8080', 'memory_limit_mb' => 8192]);
    Server::factory()->for($this->node)->create(['slug' => 'survival', 'memory_mb' => 2048]);
    $token = $this->admin->createToken('Phone', ['servers:manage'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/move', ['node_id' => $elsewhere->id], bearer($token))
        ->assertStatus(202);
    Queue::assertPushed(MoveServer::class, 1);

    $this->deleteJson('/api/v1/servers/survival', [], bearer($token))->assertStatus(204);
    expect(Server::count())->toBe(0);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiServerTest`
Expected: FAIL with 405 or 404 — the routes do not exist.

- [ ] **Step 3: Add the methods**

Append to `app/Http/Controllers/Api/V1/ServerController.php`. Add the imports
`App\Actions\Servers\CreateServer`, `App\Actions\Servers\DeleteServer`,
`App\Actions\Servers\UpdateServer`, `App\Http\Requests\StoreServerRequest`,
`App\Http\Requests\UpdateServerRequest`, `App\Jobs\MoveServer`, `App\Models\Node`,
`App\Services\CloudflareDns`, `App\Services\MemoryLimit`, `Illuminate\Http\Response`,
`Illuminate\Validation\Rule`:

```php
    /**
     * Create a server, the same way the web form does.
     */
    public function store(StoreServerRequest $request, CreateServer $createServer): JsonResponse
    {
        $server = $createServer($request->user(), $request->validated());

        return response()->json([
            'data' => self::describe($server, NodeClient::states()),
            // Null unless the server was made but its DNS record was refused.
            'dns_error' => $createServer->dnsError,
        ], 201);
    }

    /**
     * Change a server's settings and recreate its container.
     */
    public function update(UpdateServerRequest $request, Server $server, UpdateServer $updateServer): JsonResponse
    {
        $updateServer($server, [
            ...$request->validated(),
            'is_default' => $request->user()->isAdmin() ? $request->boolean('is_default') : $server->is_default,
        ]);

        return response()->json(['data' => self::describe($server->refresh(), NodeClient::states())]);
    }

    /**
     * Delete a server and its container. The data folder stays on the host.
     */
    public function destroy(Server $server, DeleteServer $deleteServer): Response
    {
        $deleteServer($server);

        return response()->noContent();
    }

    /**
     * Hand a server over to another node; the work itself is queued.
     */
    public function move(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'integer', Rule::exists('nodes', 'id')->whereNot('id', $server->node_id)],
        ]);

        $target = Node::query()->whereKey($data['node_id'])->firstOrFail();
        $free = MemoryLimit::freeMb($target);

        if ($free !== null && $free < $server->memory_mb) {
            return response()->json(['message' => __('Only :free MB is free on :node, and this server needs :needed MB.', [
                'free' => $free, 'node' => $target->name, 'needed' => $server->memory_mb,
            ])], 422);
        }

        MoveServer::dispatch($server->id, $target->id, $request->user()->id);

        return response()->json(['message' => __('The move has started.')], 202);
    }

    /**
     * Add the server's DNS record in Cloudflare again.
     */
    public function dns(Server $server): JsonResponse
    {
        abort_unless(CloudflareDns::enabled(), 404);

        // Caught here: left alone, the handler in bootstrap/app.php would blame the Rust service.
        try {
            CloudflareDns::create($server->hostname, $server->node->dns_target);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            ActivityLog::record('dns.failed', $server, ['hostname' => $server->hostname]);

            return response()->json(['message' => __('Cloudflare did not accept the DNS record. Check the token and its zone access in Settings.')], 502);
        }

        ActivityLog::record('dns.create', $server, ['hostname' => $server->hostname]);

        return response()->json(['message' => __('The DNS record for :hostname is in place.', ['hostname' => $server->hostname])]);
    }

    /**
     * Stop every server that is running or starting.
     */
    public function stopAll(PowerServer $powerServer): JsonResponse
    {
        $up = array_keys(array_filter(
            NodeClient::states()['servers'],
            fn (array $live): bool => in_array($live['state'], ['running', 'starting'], true),
        ));

        $servers = Server::query()->whereIn('slug', $up)->get();
        foreach ($servers as $server) {
            $powerServer($server, 'stop');
        }

        return response()->json(['message' => trans_choice('{0} No server was running.|{1} Stopping one server.|[2,*] Stopping :count servers.', $servers->count())], 202);
    }
```

In `routes/api.php`, inside the `v1` group. `servers/stop-all` must come **before**
`servers/{server}` so the slug never swallows it, exactly as `routes/web.php` orders them:

```php
    Route::middleware('can:manage-servers')->group(function () {
        Route::post('servers', [ServerController::class, 'store'])->middleware('ability:servers:manage');
        Route::post('servers/stop-all', [ServerController::class, 'stopAll'])->middleware('ability:servers:power');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->middleware('ability:servers:manage');
        Route::post('servers/{server}/move', [ServerController::class, 'move'])->middleware('ability:servers:manage');
        Route::post('servers/{server}/dns', [ServerController::class, 'dns'])->middleware('ability:servers:manage');
    });

    Route::put('servers/{server}', [ServerController::class, 'update'])
        ->middleware(['ability:servers:configure', 'can:act,server,"configure"']);
```

- [ ] **Step 4: Document the new paths in `resources/api/openapi.json`**

Add `put` and `delete` to the existing `/servers/{slug}` entry, `post` to the existing
`/servers` entry, and these three new paths:

```json
"/servers/stop-all": {
  "post": {
    "summary": "Stop every server that is running",
    "description": "Needs servers:power and an admin owner.",
    "responses": { "202": { "description": "Stopping" }, "403": { "description": "The token lacks servers:power, or its owner is not an admin" } }
  }
},
"/servers/{slug}/move": {
  "post": {
    "summary": "Move a server to another node",
    "description": "Needs servers:manage and an admin owner. The move is queued; watch the server's state to see it finish.",
    "responses": { "202": { "description": "The move has started" }, "422": { "description": "Not enough memory is free on that node" } }
  }
},
"/servers/{slug}/dns": {
  "post": {
    "summary": "Add the server's DNS record again",
    "description": "Needs servers:manage and an admin owner. Only when Cloudflare is configured.",
    "responses": { "200": { "description": "The record is in place" }, "404": { "description": "Cloudflare is not configured" }, "502": { "description": "Cloudflare refused the record" } }
  }
},
```

The summaries for the two extended paths: `put` is "Change a server's settings and recreate its
container" (needs servers:configure and the configure permission), `delete` is "Delete a server
and its container" (needs servers:manage and an admin owner), `post` on `/servers` is "Create a
server" (needs servers:manage and an admin owner).

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiServerTest|api documentation covers|ApiGuardTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1/ServerController.php panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiServerTest.php
git commit -m "Let the API create, change, delete and move servers"
```

---

### Task 6: The whole backup surface, and world import

**Files:**
- Create: `app/Http/Controllers/Api/V1/BackupController.php`
- Modify: `routes/api.php` (move the two existing backup routes to the new controller), `app/Http/Controllers/Api/V1/ServerController.php` (delete `backups()` and `backup()`), `resources/api/openapi.json`
- Test: `tests/Feature/ApiBackupTest.php`

**Interfaces:**
- Consumes: `NodeClient::backups/createBackup/cancelBackup/restoreBackup/lockBackup/deleteBackup/backupUrl/imports/importWorld`, `FileRequest`, `ability:` (Task 2).
- Produces: `GET|POST /servers/{server}/backups`, `DELETE /servers/{server}/backups/running`, `PUT /servers/{server}/backups/retention`, `GET|POST|DELETE /servers/{server}/backups/{backup}…`, `GET|POST /servers/{server}/import`. `GET …/import` is not in the spec's matrix: it lists the worlds in the node's import folder, without which an app cannot offer `source: host`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->node = Node::factory()->create(['base_url' => 'http://node.test:8080']);
    $this->server = Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $this->admin = User::factory()->admin()->create();
    Http::fake([
        'node.test:8080/servers/survival/backups' => Http::response([
            ['name' => '1794600000.tar.gz', 'size' => 10, 'created' => 1794600000, 'locked' => false, 'status' => 'done', 'by' => null, 'reason' => null],
        ]),
        'node.test:8080/*' => Http::response(status: 202),
    ]);
});

test('each backup action needs its own ability', function () {
    $read = $this->admin->createToken('Read', ['backups:read'])->plainTextToken;
    $restore = $this->admin->createToken('Restore', ['backups:restore'])->plainTextToken;

    $this->getJson('/api/v1/servers/survival/backups', bearer($read))
        ->assertOk()->assertJsonPath('data.0.name', '1794600000.tar.gz');

    $this->postJson('/api/v1/servers/survival/backups/1794600000.tar.gz/restore', [], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the backups:restore ability.');

    $this->postJson('/api/v1/servers/survival/backups/1794600000.tar.gz/restore', [], bearer($restore))
        ->assertStatus(202);

    $this->deleteJson('/api/v1/servers/survival/backups/1794600000.tar.gz', [], bearer($restore))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the backups:delete ability.');
});

test('retention and cancelling belong to making backups', function () {
    $token = $this->admin->createToken('Maker', ['backups:create'])->plainTextToken;

    $this->putJson('/api/v1/servers/survival/backups/retention', ['backup_keep' => '5', 'backup_retention_days' => '30'], bearer($token))
        ->assertOk();
    expect($this->server->refresh()->backup_keep)->toBe(5);

    $this->deleteJson('/api/v1/servers/survival/backups/running', [], bearer($token))->assertStatus(202);

    // The same events the web pages record.
    expect(App\Models\ActivityLog::query()->pluck('event')->all())->toBe(['backup.retention', 'backup.cancel']);
});

test('the record of a failed backup can be deleted too, and the import folder can be listed', function () {
    $delete = $this->admin->createToken('Delete', ['backups:delete'])->plainTextToken;
    $this->deleteJson('/api/v1/servers/survival/backups/1794600000.failed', [], bearer($delete))->assertOk();

    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['node.test:8080/imports' => Http::response(['worlds/old'])]);
    $restore = $this->admin->createToken('Restore', ['backups:restore'])->plainTextToken;
    $this->getJson('/api/v1/servers/survival/import', bearer($restore))->assertOk()->assertJsonPath('data', ['worlds/old']);
});

test('a download gives back an address with a short-lived token', function () {
    $token = $this->admin->createToken('Read', ['backups:read'])->plainTextToken;

    $this->getJson('/api/v1/servers/survival/backups/1794600000.tar.gz/download', bearer($token))
        ->assertOk()
        ->assertJsonPath('url', fn (string $url): bool => str_contains($url, '/servers/survival/backups/1794600000.tar.gz/download?token='));
});

test('importing a world needs the restore ability and reaches the node', function () {
    $token = $this->admin->createToken('Restore', ['backups:restore'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/import', ['source' => 'zip', 'path' => 'world.zip'], bearer($token))
        ->assertStatus(202);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://node.test:8080/servers/survival/import'
        && $request['source'] === 'zip' && $request['path'] === 'world.zip');
});

test('a backup name that the node never writes is refused by the route', function () {
    $token = $this->admin->createToken('Delete', ['backups:delete'])->plainTextToken;

    $this->deleteJson('/api/v1/servers/survival/backups/..%2F..%2Fetc%2Fpasswd', [], bearer($token))
        ->assertStatus(404);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiBackupTest`
Expected: FAIL — most routes 404, and the two that exist answer the wrong shape.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/BackupController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BackupController extends Controller
{
    /**
     * List the backups, newest first.
     */
    public function index(Server $server): JsonResponse
    {
        return response()->json(['data' => (new NodeClient($server->node))->backups($server)]);
    }

    /**
     * Start a backup. It runs in the background on the node.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        // "(API)" is what the node's backup list has always shown for a backup made by a token.
        (new NodeClient($server->node))->createBackup($server, "{$request->user()->name} (API)");
        ActivityLog::record('backup.create', $server);

        return response()->json(['message' => "Accepted: backup of {$server->slug}."], 202);
    }

    /**
     * Stop the backup that is running; what was written so far is thrown away.
     */
    public function cancel(Server $server): JsonResponse
    {
        (new NodeClient($server->node))->cancelBackup($server);
        ActivityLog::record('backup.cancel', $server);

        return response()->json(['message' => __('The backup was stopped.')], 202);
    }

    /**
     * How many backups to keep and for how long. The daily automatic backup of the web page is
     * an ordinary schedule; an app sets it through the schedules endpoints.
     */
    public function retention(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'backup_keep' => ['required', 'integer', 'between:1,365'],
            'backup_retention_days' => ['nullable', 'integer', 'between:1,3650'],
        ]);

        $server->update(['backup_keep' => $data['backup_keep'], 'backup_retention_days' => $data['backup_retention_days'] ?? null]);
        ActivityLog::record('backup.retention', $server, $server->only('backup_keep', 'backup_retention_days'));

        return response()->json(['data' => $server->only('backup_keep', 'backup_retention_days')]);
    }

    /**
     * The address the backup can be downloaded from, with a token valid for five minutes.
     */
    public function download(Server $server, string $backup): JsonResponse
    {
        return response()->json(['url' => (new NodeClient($server->node))->backupUrl($server, $backup)]);
    }

    /**
     * Put a backup back. The node archives the current state first.
     */
    public function restore(Server $server, string $backup): JsonResponse
    {
        (new NodeClient($server->node))->restoreBackup($server, $backup);
        ActivityLog::record('backup.restore', $server, ['backup' => $backup]);

        return response()->json(['message' => __('The restore has started.')], 202);
    }

    /**
     * Keep a backup through retention, or let it expire again.
     */
    public function lock(Request $request, Server $server, string $backup): JsonResponse
    {
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];
        (new NodeClient($server->node))->lockBackup($server, $backup, $locked);

        return response()->json(['message' => $locked ? __('The backup is locked.') : __('The backup is unlocked.')]);
    }

    /**
     * Remove a backup for good.
     */
    public function destroy(Server $server, string $backup): JsonResponse
    {
        (new NodeClient($server->node))->deleteBackup($server, $backup);
        ActivityLog::record('backup.delete', $server, ['backup' => $backup]);

        return response()->json(['message' => __('The backup was deleted.')]);
    }

    /**
     * Replace the world with one from a zip in the server's folder or from the node's import folder.
     */
    public function import(FileRequest $request, Server $server): JsonResponse
    {
        // FileRequest already refused a path that leaves the folder.
        $data = $request->validate([
            'source' => ['required', Rule::in(['zip', 'host'])],
            'path' => ['required'],
            'start' => ['nullable', 'boolean'],
        ]);

        (new NodeClient($server->node))->importWorld($server, $data['source'], $request->path(), (bool) ($data['start'] ?? false));
        ActivityLog::record('world.import', $server, ['source' => $data['source'], 'path' => $request->path()]);

        return response()->json(['message' => __('The import has started.')], 202);
    }

    /**
     * The worlds in the node's import folder, for an import with source "host".
     */
    public function imports(Server $server): JsonResponse
    {
        return response()->json(['data' => (new NodeClient($server->node))->imports()]);
    }
}
```

Delete `backups()` and `backup()` from `app/Http/Controllers/Api/V1/ServerController.php`.

In `routes/api.php`, replace the two existing backup routes with, inside the `v1` group:

```php
    Route::middleware('can:act,server,"backups"')->group(function () {
        Route::get('servers/{server}/backups', [BackupController::class, 'index'])->middleware('ability:backups:read');
        Route::post('servers/{server}/backups', [BackupController::class, 'store'])->middleware('ability:backups:create');
        Route::delete('servers/{server}/backups/running', [BackupController::class, 'cancel'])->middleware('ability:backups:create');
        Route::put('servers/{server}/backups/retention', [BackupController::class, 'retention'])->middleware('ability:backups:create');
        Route::get('servers/{server}/import', [BackupController::class, 'imports'])->middleware('ability:backups:restore');
        Route::post('servers/{server}/import', [BackupController::class, 'import'])->middleware('ability:backups:restore');

        // The name becomes part of a path on the node, so only the form the node itself writes is routed.
        Route::where(['backup' => '[0-9]{1,12}(\.locked)?\.tar\.gz'])->group(function () {
            Route::get('servers/{server}/backups/{backup}/download', [BackupController::class, 'download'])->middleware('ability:backups:read');
            Route::post('servers/{server}/backups/{backup}/restore', [BackupController::class, 'restore'])->middleware('ability:backups:restore');
            Route::post('servers/{server}/backups/{backup}/lock', [BackupController::class, 'lock'])->middleware('ability:backups:restore');
        });
        // Also the record of a failed backup, which has no archive.
        Route::delete('servers/{server}/backups/{backup}', [BackupController::class, 'destroy'])
            ->where('backup', '[0-9]{1,12}((\.locked)?\.tar\.gz|\.failed)')
            ->middleware('ability:backups:delete');
    });
```

with `use App\Http\Controllers\Api\V1\BackupController;` at the top. The
`servers/{server}/backups/running` route must be registered **before** the `{backup}` ones.

- [ ] **Step 4: Document the seven new paths in `resources/api/openapi.json`**

Keep the existing `/servers/{slug}/backups` entry and add the `description` note that `get`
needs `backups:read` and `post` needs `backups:create`. Then add:

```json
"/servers/{slug}/backups/running": {
  "delete": { "summary": "Stop the backup that is running", "description": "Needs backups:create. What was written so far is thrown away.", "responses": { "202": { "description": "Stopping" }, "404": { "description": "No backup is running" } } }
},
"/servers/{slug}/backups/retention": {
  "put": { "summary": "Set how many backups are kept and for how long", "description": "Needs backups:create.", "responses": { "200": { "description": "The new retention" }, "422": { "description": "The values were refused" } } }
},
"/servers/{slug}/backups/{name}/download": {
  "get": { "summary": "Get the address a backup can be downloaded from", "description": "Needs backups:read. The address holds a token that is valid for five minutes.", "responses": { "200": { "description": "The address" }, "404": { "description": "No such backup" } } }
},
"/servers/{slug}/backups/{name}/restore": {
  "post": { "summary": "Put a backup back", "description": "Needs backups:restore. The node archives the current state first, so a restore can itself be undone.", "responses": { "202": { "description": "The restore has started" } } }
},
"/servers/{slug}/backups/{name}/lock": {
  "post": { "summary": "Lock a backup so retention never deletes it", "description": "Needs backups:restore.", "responses": { "200": { "description": "The new state" } } }
},
"/servers/{slug}/backups/{name}": {
  "delete": { "summary": "Delete a backup", "description": "Needs backups:delete. This cannot be undone.", "responses": { "200": { "description": "Deleted" } } }
},
"/servers/{slug}/import": {
  "get": { "summary": "List the worlds in the node's import folder", "description": "Needs backups:restore. These are the paths an import with source host accepts.", "responses": { "200": { "description": "The worlds" } } },
  "post": { "summary": "Replace the world with an imported one", "description": "Needs backups:restore. From a zip in the server's own folder, or a world folder on the node.", "responses": { "202": { "description": "The import has started" } } }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiBackupTest|api documentation covers|ApiGuardTest|ApiAbilityTest"`
Expected: PASS. `ApiAbilityTest`'s legacy-name test now passes too, because the restore route exists.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1 panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiBackupTest.php
git commit -m "Give the API the whole backup surface and world import"
```

---

### Task 7: Folders, renaming, and downloading a log

**Files:**
- Create: `app/Http/Controllers/Api/V1/FileController.php`
- Modify: `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiFileTest.php`

**Interfaces:**
- Consumes: `FileRequest`, `LogRequest` (existing), `NodeClient::makeFolder/renameFile/log`.
- Produces: `POST /servers/{server}/files/folder`, `POST /servers/{server}/files/rename`, `GET /servers/{server}/logs/download`. Downloading a file needs no endpoint of its own: `files/transfer` (Task 3) already hands out the download address.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->node = Node::factory()->create(['base_url' => 'http://node.test:8080']);
    Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $this->admin = User::factory()->admin()->create();
    Http::fake(['node.test:8080/*' => Http::response(status: 204)]);
});

test('making a folder and renaming need files:write', function () {
    $read = $this->admin->createToken('Read', ['files:read'])->plainTextToken;
    $write = $this->admin->createToken('Write', ['files:write'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/files/folder', ['path' => 'mods/new'], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the files:write ability.');

    $this->postJson('/api/v1/servers/survival/files/folder', ['path' => 'mods/new'], bearer($write))
        ->assertStatus(201);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://node.test:8080/servers/survival/files/mkdir' && $request['path'] === 'mods/new');

    $this->postJson('/api/v1/servers/survival/files/rename', ['from' => 'a.txt', 'to' => 'b.txt'], bearer($write))
        ->assertOk();

    // The same events the web pages record.
    expect(App\Models\ActivityLog::query()->pluck('event')->all())->toBe(['file.folder', 'file.rename']);
});

test('a path that tries to leave the server folder is refused', function () {
    $write = $this->admin->createToken('Write', ['files:write'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/files/folder', ['path' => '../escape'], bearer($write))
        ->assertStatus(422);
});

test('a whole log file comes back as plain text with logs:read', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['node.test:8080/servers/survival/logs/latest.log' => Http::response("one\ntwo\n")]);
    $token = $this->admin->createToken('Logs', ['logs:read'])->plainTextToken;

    $this->get('/api/v1/servers/survival/logs/download?file=latest.log', bearer($token))
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8')
        ->assertSee("one\ntwo");

    $without = $this->admin->createToken('Files', ['files:read'])->plainTextToken;
    $this->getJson('/api/v1/servers/survival/logs/download?file=latest.log', bearer($without))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the logs:read ability.');
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiFileTest`
Expected: FAIL with 404.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/FileController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileRequest;
use App\Http\Requests\LogRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\NodeClient;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Make a folder inside the server's data folder.
     */
    public function folder(FileRequest $request, Server $server): JsonResponse
    {
        (new NodeClient($server->node))->makeFolder($server, $request->path());
        ActivityLog::record('file.folder', $server, ['path' => $request->path()]);

        return response()->json(['message' => __('The folder was created.')], 201);
    }

    /**
     * Rename or move a file inside the server's data folder.
     */
    public function rename(FileRequest $request, Server $server): JsonResponse
    {
        (new NodeClient($server->node))->renameFile($server, $request->path('from'), $request->path('to'));
        ActivityLog::record('file.rename', $server, ['from' => $request->path('from'), 'to' => $request->path('to')]);

        return response()->json(['message' => __('Renamed.')]);
    }

    /**
     * A whole log file as plain text. Logs are read over the node's signed API, not with a
     * browser token, so the panel passes the content on itself.
     */
    public function log(LogRequest $request, Server $server): StreamedResponse
    {
        $file = $request->validated('file') ?? abort(404);
        $content = (new NodeClient($server->node))->log($server, $file);

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            $server->slug.'-'.preg_replace('/\.gz$/', '', $file),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
```

In `routes/api.php`, inside the existing `can:act,server,"files"` group:

```php
        Route::post('servers/{server}/files/folder', [FileController::class, 'folder'])->middleware('ability:files:write');
        Route::post('servers/{server}/files/rename', [FileController::class, 'rename'])->middleware('ability:files:write');
```

and, beside the existing logs route (it follows the `logs` permission, not `files`):

```php
    Route::get('servers/{server}/logs/download', [FileController::class, 'log'])
        ->middleware(['ability:logs:read', 'can:act,server,"logs"']);
```

with `use App\Http\Controllers\Api\V1\FileController;` at the top.

- [ ] **Step 4: Document the three paths in `resources/api/openapi.json`**

Also change the description of the existing `/servers/{slug}/files/transfer` entry to say what
Task 3 made true: "Needs files:read for the download address; the upload address is only in
the answer for a token with files:write."

```json
"/servers/{slug}/files/folder": {
  "post": { "summary": "Make a folder", "description": "Needs files:write and the files permission on the server.", "responses": { "201": { "description": "Created" }, "422": { "description": "The path was refused" } } }
},
"/servers/{slug}/files/rename": {
  "post": { "summary": "Rename or move a file", "description": "Needs files:write and the files permission on the server.", "responses": { "200": { "description": "Renamed" } } }
},
"/servers/{slug}/logs/download": {
  "get": { "summary": "Download a whole log file", "description": "Needs logs:read and the logs permission on the server. Comes back as plain text, gzipped files unpacked.", "responses": { "200": { "description": "The log" }, "404": { "description": "No such log file" } } }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiFileTest|api documentation covers|ApiGuardTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1/FileController.php panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiFileTest.php
git commit -m "Add folders, renaming and downloads to the file API"
```

---

### Task 8: Schedules

**Files:**
- Create: `app/Http/Controllers/Api/V1/ScheduleController.php`
- Modify: `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiScheduleTest.php`

**Interfaces:**
- Consumes: `Schedule::ACTIONS`, the server's `schedules()` relation.
- Produces: `GET|POST /servers/{server}/schedules`, `PUT|DELETE /servers/{server}/schedules/{schedule}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Node;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->server = Server::factory()->for(Node::factory()->create(['base_url' => 'http://node.test:8080']))->create(['slug' => 'survival']);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('Phone', ['schedules:manage'])->plainTextToken;
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
});

test('a schedule is added, paused and removed through the API', function () {
    $this->postJson('/api/v1/servers/survival/schedules', ['action' => 'backup', 'time' => '04:30'], bearer($this->token))
        ->assertStatus(201)->assertJsonPath('data.action', 'backup');

    $schedule = Schedule::sole();

    $this->putJson("/api/v1/servers/survival/schedules/{$schedule->id}", ['is_active' => false], bearer($this->token))
        ->assertOk()->assertJsonPath('data.is_active', false);

    $this->getJson('/api/v1/servers/survival/schedules', bearer($this->token))
        ->assertOk()->assertJsonCount(1, 'data');

    $this->deleteJson("/api/v1/servers/survival/schedules/{$schedule->id}", [], bearer($this->token))
        ->assertStatus(204);
    expect(Schedule::count())->toBe(0);
});

test('a command schedule needs a command, and a schedule of another server is not found', function () {
    $this->postJson('/api/v1/servers/survival/schedules', ['action' => 'command', 'time' => '04:30'], bearer($this->token))
        ->assertStatus(422);

    $elsewhere = Server::factory()->create(['slug' => 'creative']);
    $other = $elsewhere->schedules()->create(['action' => 'backup', 'time' => '05:00']);

    $this->deleteJson("/api/v1/servers/survival/schedules/{$other->id}", [], bearer($this->token))
        ->assertStatus(404);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiScheduleTest`
Expected: FAIL with 404.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/ScheduleController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Schedule;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ScheduleController extends Controller
{
    /**
     * The server's scheduled actions.
     */
    public function index(Server $server): JsonResponse
    {
        return response()->json(['data' => $server->schedules()->orderBy('time')->get()]);
    }

    /**
     * Add a scheduled backup, restart, start, stop or console command.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(Schedule::ACTIONS)],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            // The same limits the Rust service applies to a command.
            'command' => ['nullable', 'required_if:action,command', 'string', 'max:500', 'regex:/^[^-\x00-\x1F\x7F][^\x00-\x1F\x7F]*$/'],
        ]);

        $schedule = $server->schedules()->create([
            ...$data,
            'command' => $data['action'] === 'command' ? ltrim((string) $data['command'], '/') : null,
        ]);
        ActivityLog::record('schedule.create', $server, array_filter(['action' => $data['action'], 'time' => $data['time']]));

        return response()->json(['data' => $schedule], 201);
    }

    /**
     * Pause or resume a schedule.
     */
    public function update(Request $request, Server $server, Schedule $schedule): JsonResponse
    {
        abort_unless($schedule->server_id === $server->id, 404);

        $schedule->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => $schedule]);
    }

    /**
     * Remove a schedule.
     */
    public function destroy(Server $server, Schedule $schedule): Response
    {
        abort_unless($schedule->server_id === $server->id, 404);

        $schedule->delete();
        ActivityLog::record('schedule.delete', $server, ['action' => $schedule->action, 'time' => $schedule->time]);

        return response()->noContent();
    }
}
```

In `routes/api.php`, inside the `can:act,server,"backups"` group (schedules follow the backup
permission on the web too), with `use App\Http\Controllers\Api\V1\ScheduleController;`:

```php
        Route::get('servers/{server}/schedules', [ScheduleController::class, 'index'])->middleware('ability:schedules:manage');
        Route::post('servers/{server}/schedules', [ScheduleController::class, 'store'])->middleware('ability:schedules:manage');
        Route::put('servers/{server}/schedules/{schedule}', [ScheduleController::class, 'update'])->middleware('ability:schedules:manage');
        Route::delete('servers/{server}/schedules/{schedule}', [ScheduleController::class, 'destroy'])->middleware('ability:schedules:manage');
```

- [ ] **Step 4: Document the two paths in `resources/api/openapi.json`**

```json
"/servers/{slug}/schedules": {
  "get": { "summary": "List the server's scheduled actions", "description": "Needs schedules:manage and the backups permission on the server.", "responses": { "200": { "description": "The schedules" } } },
  "post": { "summary": "Add a scheduled action", "description": "Needs schedules:manage. A backup, restart, start, stop or console command, daily or on one weekday.", "responses": { "201": { "description": "The schedule" }, "422": { "description": "The form was refused" } } }
},
"/servers/{slug}/schedules/{id}": {
  "put": { "summary": "Pause or resume a schedule", "description": "Needs schedules:manage.", "responses": { "200": { "description": "The schedule" } } },
  "delete": { "summary": "Remove a schedule", "description": "Needs schedules:manage.", "responses": { "204": { "description": "Removed" } } }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiScheduleTest|api documentation covers|ApiGuardTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1/ScheduleController.php panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiScheduleTest.php
git commit -m "Add schedules to the API"
```

---

### Task 9: Nodes

**Files:**
- Create: `app/Http/Controllers/Api/V1/NodeController.php`
- Modify: `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiNodeTest.php`

**Interfaces:**
- Consumes: `Node`, `MemoryLimit::mb/freeMb`, `NodeClient::servers()`.
- Produces: `GET|POST /nodes`, `PUT|DELETE /nodes/{node}`. The token is shown once, on creation, exactly as on the web.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->home = Node::factory()->create(['name' => 'home', 'base_url' => 'http://node.test:8080']);
    $this->admin = User::factory()->admin()->create();
    Http::fake(['*' => Http::response([])]);
});

test('nodes are listed with their state, and reading is a different ability from managing', function () {
    $read = $this->admin->createToken('Read', ['nodes:read'])->plainTextToken;

    $this->getJson('/api/v1/nodes', bearer($read))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'home')
        ->assertJsonPath('data.0.online', true)
        // The token is never sent back.
        ->assertJsonMissingPath('data.0.token');

    $this->postJson('/api/v1/nodes', ['name' => 'attic', 'base_url' => 'http://attic.test:8080'], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the nodes:manage ability.');
});

test('a node is added, changed and removed, and its token is shown once', function () {
    $token = $this->admin->createToken('Admin', ['nodes:manage'])->plainTextToken;

    $response = $this->postJson('/api/v1/nodes', ['name' => 'attic', 'base_url' => 'https://attic.test:8080/'], bearer($token))
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'attic')
        ->assertJsonPath('data.base_url', 'https://attic.test:8080');

    expect($response->json('token'))->toBeString()->toHaveLength(64);

    $node = Node::firstWhere('name', 'attic');
    $this->putJson("/api/v1/nodes/{$node->id}", ['name' => 'loft', 'base_url' => 'http://attic.test:8080', 'memory_limit_mb' => '4096'], bearer($token))
        ->assertOk()->assertJsonPath('data.name', 'loft');

    $this->deleteJson("/api/v1/nodes/{$node->id}", [], bearer($token))->assertStatus(204);
});

test('a node with servers is not removed, and a viewer is refused', function () {
    Server::factory()->for($this->home)->create();
    $token = $this->admin->createToken('Admin', ['nodes:manage'])->plainTextToken;

    $this->deleteJson("/api/v1/nodes/{$this->home->id}", [], bearer($token))->assertStatus(422);

    $viewer = User::factory()->create()->createToken('Phone', ['nodes:read'])->plainTextToken;
    $this->getJson('/api/v1/nodes', bearer($viewer))->assertStatus(403);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiNodeTest`
Expected: FAIL with 404.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/NodeController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Services\MemoryLimit;
use App\Services\NodeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NodeController extends Controller
{
    /**
     * The hosts that run servers, with whether they answered just now.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Node::query()->withCount('servers')->orderBy('id')->get()->map(function (Node $node): array {
            try {
                (new NodeClient($node))->servers();
                $online = true;
            } catch (ConnectionException|RequestException) {
                $online = false;
            }

            return [
                ...$node->only('id', 'name', 'base_url', 'dns_target', 'bluemap_url', 'memory_limit_mb'),
                'online' => $online,
                'servers' => $node->servers_count,
                'allocated_mb' => (int) $node->servers()->sum('memory_mb'),
                'limit_mb' => $online ? MemoryLimit::mb($node) : null,
                'local' => $node->name === Node::LOCAL,
            ];
        })]);
    }

    /**
     * Register a node. Its token is in the answer and is never retrievable again.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $token = Str::random(64);
        $node = Node::create([...$data, 'base_url' => rtrim($data['base_url'], '/'), 'token' => $token]);
        ActivityLog::record('node.create', properties: ['name' => $node->name]);

        return response()->json([
            'data' => $node->only('id', 'name', 'base_url', 'dns_target', 'bluemap_url', 'memory_limit_mb'),
            // Stored encrypted, so this is the one time it can be read.
            'token' => $token,
        ], 201);
    }

    /**
     * Change a node's name, address or host settings.
     */
    public function update(Request $request, Node $node): JsonResponse
    {
        $data = $request->validate($this->rules($node));

        // The local node's address comes from the panel's environment and would be put back on the next start.
        if ($node->name === Node::LOCAL) {
            unset($data['name'], $data['base_url']);
        } else {
            $data['base_url'] = rtrim($data['base_url'], '/');
        }

        $node->update($data);
        ActivityLog::record('node.update', properties: ['name' => $node->name]);

        return response()->json(['data' => $node->only('id', 'name', 'base_url', 'dns_target', 'bluemap_url', 'memory_limit_mb')]);
    }

    /**
     * Remove a node that no server runs on any more.
     */
    public function destroy(Node $node): JsonResponse|Response
    {
        if ($node->servers()->exists()) {
            return response()->json(['message' => __('Delete or move the servers on this node first.')], 422);
        }

        if ($node->name === Node::LOCAL) {
            return response()->json(['message' => __('The local node belongs to the panel itself and comes back on every start.')], 422);
        }

        $node->delete();
        ActivityLog::record('node.delete', properties: ['name' => $node->name]);

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Node $node = null): array
    {
        $local = $node?->name === Node::LOCAL;

        return [
            'name' => [$local ? 'nullable' : 'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]*$/', Rule::unique('nodes', 'name')->ignore($node)],
            // https is a node that was given a certificate (SPAWNPOINT_TLS_CERT).
            'base_url' => [$local ? 'nullable' : 'required', 'string', 'max:255', 'regex:/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]{1,5})?\/?$/'],
            'dns_target' => ['nullable', 'string', 'max:253', 'regex:/^([0-9a-fA-F:.]+|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)$/'],
            'bluemap_url' => ['nullable', 'url:http,https', 'max:255'],
            'memory_limit_mb' => ['nullable', 'integer', 'between:1024,4194304'],
        ];
    }
}
```

In `routes/api.php`, with `use App\Http\Controllers\Api\V1\NodeController;`:

```php
    Route::middleware('can:manage-users')->group(function () {
        Route::get('nodes', [NodeController::class, 'index'])->middleware('ability:nodes:read');
        Route::post('nodes', [NodeController::class, 'store'])->middleware('ability:nodes:manage');
        Route::put('nodes/{node}', [NodeController::class, 'update'])->middleware('ability:nodes:manage');
        Route::delete('nodes/{node}', [NodeController::class, 'destroy'])->middleware('ability:nodes:manage');
    });
```

- [ ] **Step 4: Document the two paths in `resources/api/openapi.json`**

```json
"/nodes": {
  "get": { "summary": "List the hosts that run servers", "description": "Needs nodes:read and an admin owner. A node's token is never sent back.", "responses": { "200": { "description": "The nodes" }, "403": { "description": "The token lacks nodes:read, or its owner is not an admin" } } },
  "post": { "summary": "Register a node", "description": "Needs nodes:manage and an admin owner. The answer holds the node's token; it is stored encrypted and can never be read again.", "responses": { "201": { "description": "The node and its token" }, "422": { "description": "The form was refused" } } }
},
"/nodes/{id}": {
  "put": { "summary": "Change a node", "description": "Needs nodes:manage and an admin owner. The local node's name and address come from the panel's environment and cannot be changed here.", "responses": { "200": { "description": "The node" } } },
  "delete": { "summary": "Remove a node", "description": "Needs nodes:manage and an admin owner. Only when no server runs on it.", "responses": { "204": { "description": "Removed" }, "422": { "description": "Servers still run on it, or it is the local node" } } }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiNodeTest|api documentation covers|ApiGuardTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1/NodeController.php panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiNodeTest.php
git commit -m "Add nodes to the API"
```

---

### Task 10: Users and their shares

**Files:**
- Create: `app/Http/Controllers/Api/V1/UserController.php`
- Modify: `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiUserTest.php`

**Interfaces:**
- Consumes: `User`, `Server::PERMISSIONS`, `PasswordBroker::sendResetLink()`.
- Produces: `GET|POST /users`, `PUT|DELETE /users/{user}`. Shares are sent and accepted as `{"<server id>": ["power", "files"]}`, which is simpler for an app than the web form's `"12:power"` strings.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // Named, and owning the server, so the list is exactly Admin then Sam: the factory would
    // otherwise make a second user with a random name as the server's owner.
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->server = Server::factory()->create(['slug' => 'survival', 'owner_id' => $this->admin->id]);
    $this->token = $this->admin->createToken('Admin', ['users:read', 'users:manage'])->plainTextToken;
});

test('users are listed with their role and shares', function () {
    $viewer = User::factory()->create(['name' => 'Sam']);
    $viewer->servers()->attach($this->server, ['permissions' => json_encode(['power', 'files'])]);

    $this->getJson('/api/v1/users', bearer($this->token))
        ->assertOk()
        ->assertJsonPath('data.1.name', 'Sam')
        ->assertJsonPath("data.1.shares.{$this->server->id}", ['power', 'files']);
});

test('a user is invited with shares, changed and removed', function () {
    Notification::fake();

    $this->postJson('/api/v1/users', [
        'name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'viewer',
        'shares' => [$this->server->id => ['power']],
    ], bearer($this->token))->assertStatus(201)->assertJsonPath('data.invited', true);

    $sam = User::firstWhere('email', 'sam@example.com');
    expect($sam->servers()->count())->toBe(1);

    $this->putJson("/api/v1/users/{$sam->id}", ['role' => 'viewer', 'shares' => [$this->server->id => ['power', 'backups']]], bearer($this->token))
        ->assertOk();

    $this->deleteJson("/api/v1/users/{$sam->id}", [], bearer($this->token))->assertStatus(204);
    expect(User::count())->toBe(1);
});

test('an admin cannot demote or delete themselves through the API either', function () {
    $this->putJson("/api/v1/users/{$this->admin->id}", ['role' => 'viewer'], bearer($this->token))
        ->assertStatus(422);
    $this->deleteJson("/api/v1/users/{$this->admin->id}", [], bearer($this->token))
        ->assertStatus(422);
});

test('a viewer token with users:manage is still refused: a token never exceeds its owner', function () {
    $viewer = User::factory()->create()->createToken('Phone', ['users:read', 'users:manage'])->plainTextToken;

    $this->getJson('/api/v1/users', bearer($viewer))->assertStatus(403);
    $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'], bearer($viewer))->assertStatus(403);
    expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
});

test('reading users is a different ability from managing them', function () {
    $read = $this->admin->createToken('Read', ['users:read'])->plainTextToken;

    $this->getJson('/api/v1/users', bearer($read))->assertOk();
    $this->postJson('/api/v1/users', [], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the users:manage ability.');
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiUserTest`
Expected: FAIL with 404.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/UserController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * The users with their role and the servers shared with them.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()->orderBy('name')->get()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            // Server id to what that share allows; an empty list is "may see it".
            'shares' => (object) DB::table('server_user')->where('user_id', $user->id)->pluck('permissions', 'server_id')
                ->map(fn (?string $permissions): array => json_decode((string) $permissions, true) ?? [])->all(),
        ])]);
    }

    /**
     * Create a user. Without a password they are invited by e-mail.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', Password::defaults()],
            ...$this->accessRules(),
        ]);

        $invited = blank($data['password'] ?? null);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $invited ? Str::password(40) : $data['password']]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->grant($user, $data);
        ActivityLog::record('user.create', properties: ['email' => $user->email, 'role' => $data['role'], 'invited' => $invited]);

        if ($invited) {
            PasswordBroker::sendResetLink(['email' => $user->email]);
        }

        return response()->json(['data' => [...$user->only('id', 'name', 'email', 'role'), 'invited' => $invited]], 201);
    }

    /**
     * Change a user's role and what is shared with them.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate($this->accessRules());

        // Keeps at least one admin: the one doing the editing.
        if ($user->is($request->user()) && $data['role'] !== 'admin') {
            return response()->json(['message' => __('You cannot remove your own admin role.')], 422);
        }

        $this->grant($user, $data);
        ActivityLog::record('user.update', properties: ['email' => $user->email, 'role' => $data['role']]);

        return response()->json(['data' => $user->only('id', 'name', 'email', 'role')]);
    }

    /**
     * Delete a user; servers they created pass to the admin deleting them.
     */
    public function destroy(Request $request, User $user): JsonResponse|Response
    {
        if ($user->is($request->user())) {
            return response()->json(['message' => __('You cannot delete your own account here.')], 422);
        }

        Server::query()->where('owner_id', $user->id)->update(['owner_id' => $request->user()->id]);
        $user->delete();
        ActivityLog::record('user.delete', properties: ['email' => $user->email]);

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function accessRules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'viewer'])],
            // Server id to what the share allows: {"3": ["power", "files"]}.
            'shares' => ['nullable', 'array'],
            'shares.*' => ['array'],
            'shares.*.*' => [Rule::in(Server::PERMISSIONS)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function grant(User $user, array $data): void
    {
        // The role is not mass assignable, so a profile form can never change it.
        $user->forceFill(['role' => $data['role']])->save();

        // Admins see every server, so sharing only means something for viewers.
        $shares = $data['role'] === 'viewer' ? ($data['shares'] ?? []) : [];
        $existing = Server::query()->whereKey(array_keys($shares))->pluck('id');

        $user->servers()->sync($existing->mapWithKeys(fn (int $id): array => [
            $id => ['permissions' => json_encode(array_values($shares[$id] ?? $shares[(string) $id] ?? []))],
        ])->all());
    }
}
```

In `routes/api.php`, inside the same `can:manage-users` group as the nodes, with
`use App\Http\Controllers\Api\V1\UserController;`:

```php
        Route::get('users', [UserController::class, 'index'])->middleware('ability:users:read');
        Route::post('users', [UserController::class, 'store'])->middleware('ability:users:manage');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('ability:users:manage');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('ability:users:manage');
```

- [ ] **Step 4: Document the two paths in `resources/api/openapi.json`**

```json
"/users": {
  "get": { "summary": "List the users with their role and shares", "description": "Needs users:read and an admin owner.", "responses": { "200": { "description": "The users" } } },
  "post": { "summary": "Create or invite a user", "description": "Needs users:manage and an admin owner. Without a password the user is sent an invitation e-mail.", "responses": { "201": { "description": "The user" }, "422": { "description": "The form was refused" } } }
},
"/users/{id}": {
  "put": { "summary": "Change a user's role and shares", "description": "Needs users:manage and an admin owner. Shares are given as a server id to a list of permissions.", "responses": { "200": { "description": "The user" }, "422": { "description": "Refused, for instance removing your own admin role" } } },
  "delete": { "summary": "Delete a user", "description": "Needs users:manage and an admin owner. Servers they created pass to the admin deleting them.", "responses": { "204": { "description": "Deleted" }, "422": { "description": "You cannot delete your own account here" } } }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiUserTest|api documentation covers|ApiGuardTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1/UserController.php panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiUserTest.php
git commit -m "Add users and their shares to the API"
```

---

### Task 11: Panel settings and the activity log

**Files:**
- Create: `app/Http/Controllers/Api/V1/PanelController.php`
- Modify: `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiPanelTest.php`

**Interfaces:**
- Consumes: `Setting::value/put`, `ActivityLog`, `MemoryLimit`, `CloudflareDns::enabled()`.
- Produces: `GET|PUT /settings`, `GET /activity`. Secrets are reported as a flag, never a value.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('settings are readable without ever sending a secret', function () {
    Setting::put('curseforge_key', 'super-secret');
    Setting::put('base_domain', 'mc.example.com');
    $token = $this->admin->createToken('Read', ['settings:read'])->plainTextToken;

    $response = $this->getJson('/api/v1/settings', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.base_domain', 'mc.example.com')
        ->assertJsonPath('data.has_curseforge_key', true);

    expect($response->content())->not->toContain('super-secret');
});

test('a saved secret can be removed, and an empty one keeps what was saved', function () {
    Setting::put('curseforge_key', 'super-secret');
    $token = $this->admin->createToken('Write', ['settings:manage'])->plainTextToken;
    $form = ['base_domain' => 'mc.example.net', 'bluemap_url' => 'https://maps.example.com'];

    $this->putJson('/api/v1/settings', $form, bearer($token))->assertOk()->assertJsonPath('data.has_curseforge_key', true);
    $this->putJson('/api/v1/settings', [...$form, 'remove_curseforge_key' => true], bearer($token))
        ->assertOk()->assertJsonPath('data.has_curseforge_key', false);
});

test('changing settings needs its own ability', function () {
    $read = $this->admin->createToken('Read', ['settings:read'])->plainTextToken;
    $write = $this->admin->createToken('Write', ['settings:manage'])->plainTextToken;

    $this->putJson('/api/v1/settings', ['base_domain' => 'mc.example.net', 'bluemap_url' => 'https://maps.example.com'], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the settings:manage ability.');

    $this->putJson('/api/v1/settings', ['base_domain' => 'mc.example.net', 'bluemap_url' => 'https://maps.example.com', 'activity_retention_days' => '30'], bearer($write))
        ->assertOk();

    expect(Setting::value('base_domain'))->toBe('mc.example.net')
        ->and(Setting::value('activity_retention_days'))->toBe('30');
});

test('the activity log is read newest first and can be filtered by server', function () {
    ActivityLog::create(['actor' => 'System', 'event' => 'server.start', 'subject' => 'survival', 'created_at' => now()->subMinute()]);
    ActivityLog::create(['actor' => 'System', 'event' => 'server.stop', 'subject' => 'creative', 'created_at' => now()]);
    $token = $this->admin->createToken('Read', ['activity:read'])->plainTextToken;

    $this->getJson('/api/v1/activity', bearer($token))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.event', 'server.stop');

    $this->getJson('/api/v1/activity?server=survival', bearer($token))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'survival');
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiPanelTest`
Expected: FAIL with 404.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/PanelController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Setting;
use App\Services\CloudflareDns;
use App\Services\MemoryLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    /**
     * The panel-wide settings. A secret is reported as a flag, never as its value.
     */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => [
            'base_domain' => Setting::value('base_domain'),
            'bluemap_url' => Setting::value('bluemap_url'),
            'memory_limit_mb' => Setting::value('memory_limit_mb'),
            'require_two_factor' => (bool) Setting::value('require_two_factor'),
            'activity_retention_days' => Setting::value('activity_retention_days'),
            'dns_target' => Setting::value('dns_target'),
            'privacy_contact' => Setting::value('privacy_contact'),
            'has_curseforge_key' => filled(Setting::value('curseforge_key')),
            'has_cloudflare_token' => filled(Setting::value('cloudflare_token')),
            'dns_enabled' => CloudflareDns::enabled(),
            'effective_memory_limit_mb' => MemoryLimit::mb(Node::query()->first()),
        ]]);
    }

    /**
     * Change the panel-wide settings. An empty secret keeps the saved one.
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_domain' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/'],
            'bluemap_url' => ['required', 'url:http,https', 'max:255'],
            'memory_limit_mb' => ['nullable', 'integer', 'between:1024,4194304'],
            'activity_retention_days' => ['nullable', 'integer', 'between:0,3650'],
            'require_two_factor' => ['nullable', 'boolean'],
            'privacy_contact' => ['nullable', 'string', 'max:200'],
            'dns_target' => ['nullable', 'string', 'max:253', 'regex:/^([0-9a-fA-F:.]+|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)$/'],
            'curseforge_key' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'cloudflare_token' => ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
            'remove_curseforge_key' => ['nullable', 'boolean'],
            'remove_cloudflare_token' => ['nullable', 'boolean'],
        ]);

        Setting::put('base_domain', $data['base_domain']);
        Setting::put('bluemap_url', $data['bluemap_url']);
        Setting::put('memory_limit_mb', isset($data['memory_limit_mb']) ? (string) $data['memory_limit_mb'] : null);
        Setting::put('activity_retention_days', isset($data['activity_retention_days']) ? (string) $data['activity_retention_days'] : null);
        Setting::put('require_two_factor', ($data['require_two_factor'] ?? false) ? '1' : null);
        Setting::put('dns_target', $data['dns_target'] ?? null);
        Setting::put('privacy_contact', $data['privacy_contact'] ?? null);

        // An empty secret means "keep the saved one"; removing it is asked for by name.
        foreach (['curseforge_key', 'cloudflare_token'] as $secret) {
            if ($data["remove_{$secret}"] ?? false) {
                Setting::put($secret, null);
            } elseif (filled($data[$secret] ?? null)) {
                Setting::put($secret, $data[$secret]);
            }
        }

        ActivityLog::record('settings.update');

        return $this->settings();
    }

    /**
     * Who did what, newest first, fifty at a time.
     */
    public function activity(Request $request): JsonResponse
    {
        $server = $request->string('server')->toString();
        $page = ActivityLog::query()
            ->when($server !== '', fn ($query) => $query->where('subject', $server))
            ->latest('id')
            ->simplePaginate(50)
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $entry): array => [
                'id' => $entry->id,
                'actor' => $entry->actor,
                'event' => $entry->event,
                'subject' => $entry->subject,
                'properties' => $entry->properties,
                'at' => $entry->created_at->toIso8601String(),
            ]),
            'older_url' => $page->nextPageUrl(),
            'newer_url' => $page->previousPageUrl(),
        ]);
    }
}
```

In `routes/api.php`, inside the `can:manage-users` group, with
`use App\Http\Controllers\Api\V1\PanelController;`:

```php
        Route::get('settings', [PanelController::class, 'settings'])->middleware('ability:settings:read');
        Route::put('settings', [PanelController::class, 'saveSettings'])->middleware('ability:settings:manage');
        Route::get('activity', [PanelController::class, 'activity'])->middleware('ability:activity:read');
```

- [ ] **Step 4: Document the two paths in `resources/api/openapi.json`**

```json
"/settings": {
  "get": { "summary": "Read the panel settings", "description": "Needs settings:read and an admin owner. A saved secret is reported as a flag, never as its value.", "responses": { "200": { "description": "The settings" } } },
  "put": { "summary": "Change the panel settings", "description": "Needs settings:manage and an admin owner. Leaving a secret empty keeps the saved one; remove_curseforge_key or remove_cloudflare_token deletes it.", "responses": { "200": { "description": "The settings as they now are" }, "422": { "description": "The form was refused" } } }
},
"/activity": {
  "get": { "summary": "Read the activity log", "description": "Needs activity:read and an admin owner. Newest first, fifty at a time; add ?server= to filter by slug.", "responses": { "200": { "description": "The entries" } } }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiPanelTest|api documentation covers|ApiGuardTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1/PanelController.php panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiPanelTest.php
git commit -m "Add the panel settings and the activity log to the API"
```

---

### Task 12: Webhooks, modpack search, and changing your own name

**Files:**
- Create: `app/Http/Controllers/Api/V1/WebhookController.php`, `app/Http/Controllers/Api/V1/ModpackController.php`
- Modify: `app/Http/Controllers/Api/V1/MeController.php` (add `update()`), `routes/api.php`, `resources/api/openapi.json`
- Test: `tests/Feature/ApiAccountTest.php`

**Interfaces:**
- Consumes: `Webhook::FORMATS/GROUPS/isSafeUrl()`, `SendWebhook`, `Modpacks`, `Languages::available()`.
- Produces: `GET|POST /webhooks`, `PUT|DELETE /webhooks/{webhook}`, `POST /webhooks/{webhook}/test`, `GET /modpacks/search`, `GET /modpacks/versions`, `PATCH /me`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->admin()->create(['name' => 'Joppe']);
});

test('a token may manage its owner\'s webhooks and no one else\'s', function () {
    Http::fake(['93.184.216.34/*' => Http::response(status: 204)]);
    $token = $this->user->createToken('Phone', ['webhooks:manage'])->plainTextToken;
    $other = User::factory()->create()->webhooks()->create(['name' => 'Theirs', 'url' => 'https://93.184.216.34/theirs', 'format' => 'json', 'events' => ['crash']]);

    $this->postJson('/api/v1/webhooks', ['name' => 'Mine', 'url' => 'https://93.184.216.34/mine', 'format' => 'discord', 'events' => ['power']], bearer($token))
        ->assertStatus(201)
        // The address is the secret and is never sent back.
        ->assertJsonMissingPath('data.url');

    $this->getJson('/api/v1/webhooks', bearer($token))->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/webhooks/{$other->id}", [], bearer($token))->assertStatus(404);

    $mine = $this->user->webhooks()->sole();
    $this->putJson("/api/v1/webhooks/{$mine->id}", ['is_active' => false], bearer($token))->assertOk()->assertJsonPath('data.is_active', false);
    // Paused, so nothing is sent and there is no status to report.
    $this->postJson("/api/v1/webhooks/{$mine->id}/test", [], bearer($token))->assertOk()->assertJsonPath('data.last_status', null);
});

test('a webhook address must still be https on the public internet', function () {
    $token = $this->user->createToken('Phone', ['webhooks:manage'])->plainTextToken;

    $this->postJson('/api/v1/webhooks', ['name' => 'Local', 'url' => 'https://192.168.1.10/hook', 'format' => 'json', 'events' => ['crash']], bearer($token))
        ->assertStatus(422);
});

test('modpack search needs its own ability', function () {
    Http::fake(['api.modrinth.com/*' => Http::response(['hits' => []])]);
    $token = $this->user->createToken('Phone', ['modpacks:search'])->plainTextToken;

    $this->getJson('/api/v1/modpacks/search?platform=MODRINTH&q=create', bearer($token))->assertOk();

    $without = $this->user->createToken('Other', ['servers:read'])->plainTextToken;
    $this->getJson('/api/v1/modpacks/search?platform=MODRINTH&q=create', bearer($without))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the modpacks:search ability.');
});

test('a token may change the owner\'s name and language, and nothing else', function () {
    $token = $this->user->createToken('Phone', ['profile:manage'])->plainTextToken;

    $this->patchJson('/api/v1/me', ['name' => 'Joppe K', 'locale' => 'en'], bearer($token))
        ->assertOk()->assertJsonPath('data.name', 'Joppe K');

    // An e-mail change is not part of this ability and is simply ignored.
    $this->patchJson('/api/v1/me', ['name' => 'Joppe K', 'email' => 'new@example.com'], bearer($token))->assertOk();
    expect($this->user->refresh()->email)->not->toBe('new@example.com');
});

test('there is no API way to sign in, change a password or make a token', function () {
    $paths = collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/'));

    foreach (['login', 'password', 'two-factor', 'passkey', 'tokens'] as $forbidden) {
        expect($paths->filter(fn (string $uri): bool => str_contains($uri, $forbidden))->all())->toBe([]);
    }
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiAccountTest`
Expected: FAIL with 404 on the webhook, modpack and profile calls.

- [ ] **Step 3: Write the two controllers and the profile method**

`app/Http/Controllers/Api/V1/WebhookController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhook;
use App\Models\Webhook;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    /**
     * The token owner's own webhooks. Only the host is sent back: the rest of the address is the secret.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->webhooks()->latest()->get()->map(fn (Webhook $webhook): array => [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'host' => parse_url($webhook->url, PHP_URL_HOST),
            'format' => $webhook->format,
            'events' => $webhook->events,
            'is_active' => $webhook->is_active,
            'last_status' => $webhook->last_status,
            'last_sent_at' => $webhook->last_sent_at?->toIso8601String(),
        ])]);
    }

    /**
     * Add a webhook.
     */
    public function store(Request $request): JsonResponse
    {
        $webhook = $request->user()->webhooks()->create($this->validated($request));

        return response()->json(['data' => ['id' => $webhook->id, 'name' => $webhook->name, 'host' => parse_url($webhook->url, PHP_URL_HOST)]], 201);
    }

    /**
     * Pause or resume a webhook. As on the web page, nothing else about it changes: its address
     * is a secret the panel never sends back, so it could not be edited in place anyway.
     */
    public function update(Request $request, Webhook $webhook): JsonResponse
    {
        abort_unless($webhook->user_id === $request->user()->id, 404);

        $webhook->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => ['id' => $webhook->id, 'name' => $webhook->name, 'is_active' => $webhook->is_active]]);
    }

    /**
     * Remove a webhook.
     */
    public function destroy(Request $request, Webhook $webhook): Response
    {
        abort_unless($webhook->user_id === $request->user()->id, 404);

        $webhook->delete();

        return response()->noContent();
    }

    /**
     * Send a test message to the service.
     */
    public function test(Request $request, Webhook $webhook): JsonResponse
    {
        abort_unless($webhook->user_id === $request->user()->id, 404);

        // The same message and fields as the web page's test: Webhook::payload() reads them all.
        $message = (string) __('This is a test from :name.', ['name' => config('app.name')]);

        SendWebhook::dispatchSync($webhook->id, $message, [
            'event' => 'test', 'server' => null, 'name' => null, 'hostname' => null, 'actor' => $request->user()->name,
            'message' => $message, 'at' => now()->toIso8601String(),
        ]);

        return response()->json(['data' => ['last_status' => $webhook->refresh()->last_status]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'string', 'max:2000', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Webhook::isSafeUrl((string) $value)) {
                    $fail(__('Use an https address on the public internet.'));
                }
            }],
            'format' => ['required', Rule::in(Webhook::FORMATS)],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhook::GROUPS))],
        ]);
    }
}
```

`app/Http/Controllers/Api/V1/ModpackController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ModpackSearchController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The same search the server forms use; the web controller already answers JSON.
 */
class ModpackController extends Controller
{
    public function search(Request $request, ModpackSearchController $controller): JsonResponse
    {
        return $controller($request);
    }

    public function versions(Request $request, ModpackSearchController $controller): JsonResponse
    {
        return $controller->versions($request);
    }
}
```

Add to `app/Http/Controllers/Api/V1/MeController.php`, with
`use App\Services\Languages;` and `use Illuminate\Validation\Rule;`:

```php
    /**
     * Change the owner's display name and language. Never the e-mail, password, two-factor or tokens.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(array_keys(Languages::available()))],
        ]);

        $request->user()->forceFill(array_filter([
            'name' => $data['name'],
            'locale' => $data['locale'] ?? null,
        ]))->save();

        return $this->show($request);
    }
```

In `routes/api.php`, inside the `v1` group, with the three new imports:

```php
    Route::patch('me', [MeController::class, 'update'])->middleware('ability:profile:manage');

    Route::middleware('ability:webhooks:manage')->group(function () {
        Route::get('webhooks', [WebhookController::class, 'index']);
        Route::post('webhooks', [WebhookController::class, 'store']);
        Route::put('webhooks/{webhook}', [WebhookController::class, 'update']);
        Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy']);
        Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test']);
    });

    Route::middleware(['ability:modpacks:search', 'can:search-modpacks'])->group(function () {
        Route::get('modpacks/search', [ModpackController::class, 'search']);
        Route::get('modpacks/versions', [ModpackController::class, 'versions']);
    });
```

- [ ] **Step 4: Document the six paths in `resources/api/openapi.json`**

```json
"/webhooks": {
  "get": { "summary": "List your own webhooks", "description": "Needs webhooks:manage. Only the host of each address is sent back; the rest is the secret.", "responses": { "200": { "description": "The webhooks" } } },
  "post": { "summary": "Add a webhook", "description": "Needs webhooks:manage. The address must be https on the public internet.", "responses": { "201": { "description": "The webhook" }, "422": { "description": "The address was refused" } } }
},
"/webhooks/{id}": {
  "put": { "summary": "Pause or resume a webhook", "description": "Needs webhooks:manage, and the webhook must be yours. Only is_active changes; to change the address, remove the webhook and add it again.", "responses": { "200": { "description": "The webhook" }, "404": { "description": "Not your webhook" } } },
  "delete": { "summary": "Remove a webhook", "description": "Needs webhooks:manage, and the webhook must be yours.", "responses": { "204": { "description": "Removed" } } }
},
"/webhooks/{id}/test": {
  "post": { "summary": "Send a test message", "description": "Needs webhooks:manage. Answers with what the receiving service replied.", "responses": { "200": { "description": "The status the service answered" } } }
},
"/modpacks/search": {
  "get": { "summary": "Search modpacks", "description": "Needs modpacks:search. Modrinth, CurseForge or FTB, chosen with ?platform=.", "responses": { "200": { "description": "The results" }, "422": { "description": "CurseForge needs an API key" } } }
},
"/modpacks/versions": {
  "get": { "summary": "List the versions of a modpack", "description": "Needs modpacks:search.", "responses": { "200": { "description": "The versions" } } }
},
```

and add `patch` to the existing `/me` entry: summary "Change your name and language",
description "Needs profile:manage. Nothing else about the account can be changed through the API."

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiAccountTest|api documentation covers|ApiGuardTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/app/Http/Controllers/Api/V1 panel/routes/api.php panel/resources/api/openapi.json panel/tests/Feature/ApiAccountTest.php
git commit -m "Add webhooks, modpack search and the own profile to the API"
```

---

### Task 13: The ability picker component

**Files:**
- Create: `resources/js/components/AbilityPicker.vue`
- Modify: `app/Http/Controllers/Settings/ApiTokenController.php`, `resources/js/pages/settings/ApiTokens.vue`
- Test: `tests/Feature/ApiTokenFormTest.php`

**Note:** this project has no JavaScript test runner — `npm run check` is lint and format, and
`npm run types:check` is `vue-tsc`. The component is proven by Pest assertions on the props the
page receives and on what the form accepts, plus a look in a browser, which `CLAUDE.md` requires
before calling a page done.

**Interfaces:**
- Consumes: `Abilities::groups()` and `Abilities::presets()` (Task 1).
- Produces: `<AbilityPicker v-model="abilities" :groups="groups" :presets="presets" />`, emitting `string[]` through `update:modelValue`, and a hidden `<input name="abilities[]">` per selected ability so a plain `<Form>` submit carries them.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Api\Abilities;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the token page is given the grouped abilities and the presets', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ApiTokens')
            ->has('groups', count(Abilities::groups()))
            ->where('groups.0.key', 'servers')
            ->where('groups.0.abilities.0.name', 'servers:read')
            ->where('presets.read-only', Abilities::presets()['read-only'])
            // The flat list is gone; the page reads the groups now.
            ->missing('abilities'));
});

test('a viewer is not offered the administration abilities on the token page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups', count(Abilities::groups()) - 1)
            ->where('presets.read-only', ['servers:read', 'logs:read', 'files:read', 'backups:read']));
});

test('a token is created from the abilities the picker submitted', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->post(route('api-tokens.store'), [
        'name' => 'Phone',
        'abilities' => ['servers:read', 'servers:power', 'console:use'],
    ])->assertSessionHasNoErrors();

    expect($user->tokens()->sole()->abilities)->toBe(['servers:read', 'servers:power', 'console:use']);
});

test('a retired or invented ability is refused', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('api-tokens.store'), ['name' => 'Phone', 'abilities' => ['backups:manage']])
        ->assertSessionHasErrors('abilities.0');

    $this->post(route('api-tokens.store'), ['name' => 'Phone', 'abilities' => ['servers:everything']])
        ->assertSessionHasErrors('abilities.0');
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiTokenFormTest`
Expected: FAIL — the page has `abilities`, not `groups` and `presets`.

- [ ] **Step 3: Give the page the grouped abilities**

In `app/Http/Controllers/Settings/ApiTokenController.php`: delete the `ABILITIES` constant,
import `App\Api\Abilities`, and change the two places that used it.

```php
            // Only what this user could ever use: a viewer is not shown the administration group.
            ...Abilities::offeredTo($request->user()),
```

replaces `'abilities' => self::ABILITIES,` in `index()` (it spreads into `groups` and
`presets`), and in `store()`:

```php
            'abilities.*' => [Rule::in(Abilities::all())],
```

`store()` keeps accepting every ability, also for a viewer: one they cannot use is harmless,
because the `can:` middleware refuses the request whatever the token says.

- [ ] **Step 4: Write the component**

`resources/js/components/AbilityPicker.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Ability = { name: string; label: string; description: string };
type Group = { key: string; label: string; abilities: Ability[] };

const props = defineProps<{
    groups: Group[];
    presets: Record<string, string[]>;
    // Groups that start closed; administration is noise for most tokens.
    collapsed?: string[];
}>();

const model = defineModel<string[]>({ required: true });

const open = ref<Record<string, boolean>>(
    Object.fromEntries(
        props.groups.map((group) => [
            group.key,
            !(props.collapsed ?? ['administration']).includes(group.key),
        ]),
    ),
);

const presetNames: Record<string, string> = {
    'read-only': t('Read-only'),
    operator: t('Operator'),
    'full': t('Full access'),
};

const same = (a: string[], b: string[]) =>
    a.length === b.length && [...a].sort().join() === [...b].sort().join();

const activePreset = computed(
    () =>
        Object.keys(props.presets).find((key) =>
            same(props.presets[key], model.value),
        ) ?? null,
);

const choose = (key: string) => {
    model.value = [...props.presets[key]];
};

const granted = (name: string) => model.value.includes(name);

const toggle = (name: string) => {
    model.value = granted(name)
        ? model.value.filter((ability) => ability !== name)
        : [...model.value, name];
};

const countIn = (group: Group) =>
    group.abilities.filter((ability) => granted(ability.name)).length;

const toggleGroup = (group: Group) => {
    const names = group.abilities.map((ability) => ability.name);
    model.value =
        countIn(group) === group.abilities.length
            ? model.value.filter((ability) => !names.includes(ability))
            : [...new Set([...model.value, ...names])];
};

// What the token can do, in one sentence, for the person approving it.
const summary = computed(() => {
    if (model.value.length === 0) {
        return t('This token can do nothing yet. Choose what it may do.');
    }
    if (activePreset.value === 'full') {
        return t('This token can do everything you can do in the panel.');
    }

    const parts = props.groups
        .filter((group) => countIn(group) > 0)
        .map((group) =>
            countIn(group) === group.abilities.length
                ? group.label
                : group.abilities
                      .filter((ability) => granted(ability.name))
                      .map((ability) => ability.label)
                      .join(', '),
        );

    return t('This token can: :what. Nothing else.', { what: parts.join('; ') });
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-muted-foreground">{{ t('Start from') }}</span>
            <button
                v-for="(abilities, key) in presets"
                :key="key"
                type="button"
                class="rounded-full border px-3 py-1 text-sm"
                :class="
                    activePreset === key
                        ? 'border-primary bg-primary/10 text-primary'
                        : 'border-input text-muted-foreground'
                "
                :aria-pressed="activePreset === key"
                @click="choose(key)"
            >
                {{ presetNames[key] ?? key }}
            </button>
            <span
                v-if="activePreset === null"
                class="rounded-full border border-primary bg-primary/10 px-3 py-1 text-sm text-primary"
            >
                {{ t('Custom') }}
            </span>
        </div>

        <div
            v-for="group in groups"
            :key="group.key"
            class="rounded-lg border border-input"
        >
            <div class="flex items-center justify-between gap-3 px-3 py-2">
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium"
                    :aria-expanded="open[group.key]"
                    @click="open[group.key] = !open[group.key]"
                >
                    <span aria-hidden="true">{{ open[group.key] ? '▾' : '▸' }}</span>
                    {{ group.label }}
                    <span class="text-xs font-normal text-muted-foreground">
                        {{
                            t(':granted of :total', {
                                granted: countIn(group),
                                total: group.abilities.length,
                            })
                        }}
                    </span>
                </button>
                <button
                    type="button"
                    role="switch"
                    class="size-5 shrink-0 rounded border border-input"
                    :class="
                        countIn(group) === group.abilities.length
                            ? 'bg-primary'
                            : countIn(group) > 0
                              ? 'bg-primary/40'
                              : 'bg-transparent'
                    "
                    :aria-checked="countIn(group) === group.abilities.length"
                    :aria-label="
                        t('Everything under :group', { group: group.label })
                    "
                    @click="toggleGroup(group)"
                />
            </div>

            <div
                v-show="open[group.key]"
                class="flex flex-col gap-3 border-t border-input px-3 py-3"
            >
                <label
                    v-for="ability in group.abilities"
                    :key="ability.name"
                    class="flex items-start gap-3"
                >
                    <input
                        type="checkbox"
                        class="mt-1 size-4 shrink-0 accent-primary"
                        :checked="granted(ability.name)"
                        @change="toggle(ability.name)"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm">{{ ability.label }}</span>
                        <span class="block text-[0.8125rem] text-muted-foreground">
                            {{ ability.description }}
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <p class="text-[0.8125rem] text-muted-foreground" aria-live="polite">
            {{ summary }}
        </p>

        <!-- So a plain form submit carries the selection. -->
        <input
            v-for="ability in model"
            :key="ability"
            type="hidden"
            name="abilities[]"
            :value="ability"
        />
    </div>
</template>
```

Note for the implementer: `t()` must never be called at module top level — here every call is
inside `setup` or a template, which is allowed. `presetNames` is built during setup, not at
import time, so it is safe.

- [ ] **Step 5: Use it on the token page**

In `resources/js/pages/settings/ApiTokens.vue`: replace the checkbox list with

```vue
<AbilityPicker v-model="abilities" :groups="groups" :presets="presets" />
```

declaring `const abilities = ref<string[]>([...props.presets['read-only']]);` so a new token
starts read-only, and changing the page props from `abilities: string[]` to
`groups: Group[]` and `presets: Record<string, string[]>`. The page calls `defineProps`
without keeping the result today; assign it (`const props = defineProps<…>()`). Delete the
`abilityLabels` map: the labels come from the server now, and its seven `t()` keys would
otherwise linger in every language file. Where the list of a token's abilities is shown, keep
showing the raw names — they are what the API documentation uses.

- [ ] **Step 6: Run the tests and look at it**

```sh
npm run build && php artisan test --compact --filter=ApiTokenFormTest
npm run check:fix && npm run types:check
```

Then `composer dev`, open `/settings/api-tokens`, and check: a preset fills the groups; a group
switch turns its rows on and off and shows the half state; the summary changes as you click;
the Administration group starts closed; the page is usable at 400px wide; and the blocky theme
(`PANEL_THEME=blocky`) still looks right.

- [ ] **Step 7: Commit**

```sh
git add panel/resources/js/components/AbilityPicker.vue panel/resources/js/pages/settings/ApiTokens.vue panel/app/Http/Controllers/Settings/ApiTokenController.php panel/tests/Feature/ApiTokenFormTest.php
git commit -m "Pick token abilities by preset and group"
```

---

### Task 14: The pairing record and its endpoints

**Files:**
- Create: `database/migrations/2026_11_15_210000_create_device_pairings_table.php`, `app/Models/DevicePairing.php`, `app/Http/Controllers/Api/V1/PairingController.php`
- Modify: `routes/api.php`, `resources/api/openapi.json`, `app/Console/Commands/PruneActivity.php`
- Test: `tests/Feature/ApiPairingTest.php`

**Interfaces:**
- Consumes: `Abilities::all()` (Task 1).
- Produces: `DevicePairing` with `code`, `secret_hash`, `device_name`, `token` (encrypted), `approved_at`, `expires_at`; `POST /api/v1/pair`; `POST /api/v1/pair/token`; and `DevicePairing::newCode(): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\DevicePairing;
use App\Models\User;

test('an app asks to pair and gets a code and a secret', function () {
    $response = $this->postJson('/api/v1/pair', ['device_name' => "Joppe's phone"])
        ->assertStatus(201)
        ->assertJsonPath('data.device_name', "Joppe's phone");

    expect($response->json('data.code'))->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/')
        ->and($response->json('data.secret'))->toHaveLength(64)
        ->and($response->json('data.interval'))->toBe(3);

    $pairing = DevicePairing::sole();
    // Only the hash of the secret is stored.
    expect($pairing->secret_hash)->toBe(hash('sha256', $response->json('data.secret')))
        ->and($pairing->token)->toBeNull()
        ->and($pairing->expires_at->isAfter(now()->addMinutes(9)))->toBeTrue();
});

test('polling says pending until a user approves, then hands the token over exactly once', function () {
    $user = User::factory()->admin()->create();
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Phone'])->json('data');

    $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending');

    DevicePairing::sole()->approve($user, ['servers:read', 'servers:power']);

    $claimed = $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])
        ->assertOk()
        ->assertJsonPath('abilities', ['servers:read', 'servers:power'])
        ->assertJsonPath('user.name', $user->name);

    expect($claimed->json('token'))->toContain('|')
        ->and(DevicePairing::count())->toBe(0);

    // The code is gone, so it cannot be claimed twice.
    $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])
        ->assertStatus(404);
});

test('a wrong secret and an expired code are both refused', function () {
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Phone'])->json('data');

    $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => str_repeat('a', 64)])
        ->assertStatus(404);

    DevicePairing::sole()->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])
        ->assertStatus(404);
});

test('polling at the advertised interval is never throttled, but starting pairings is', function () {
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Phone'])->json('data');

    // Every 3 seconds is 20 polls a minute, on top of the call that started it.
    foreach (range(1, 20) as $poll) {
        $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])->assertStatus(202);
    }

    // Five new pairings a minute from one address; this is the sixth.
    foreach (range(2, 5) as $again) {
        $this->postJson('/api/v1/pair', ['device_name' => 'Phone'])->assertStatus(201);
    }
    $this->postJson('/api/v1/pair', ['device_name' => 'Phone'])->assertStatus(429);
});

test('pairings nobody finished are thrown away at night, whatever the activity retention is', function () {
    $this->postJson('/api/v1/pair', ['device_name' => 'Phone']);
    DevicePairing::sole()->forceFill(['expires_at' => now()->subMinute()])->save();

    // No retention is set, which is the default: the activity log is left alone, the pairing is not.
    $this->artisan('activity:prune')->assertSuccessful();

    expect(DevicePairing::count())->toBe(0);
});

test('the minted token belongs to the approving user and holds what they chose', function () {
    $user = User::factory()->create();
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Phone'])->json('data');
    DevicePairing::sole()->approve($user, ['servers:read']);

    $token = $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])->json('token');

    $this->getJson('/api/v1/me', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.abilities', ['servers:read']);
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=ApiPairingTest`
Expected: FAIL, `Class "App\Models\DevicePairing" not found`.

- [ ] **Step 3: Write the migration, the model and the controller**

`database/migrations/2026_11_15_210000_create_device_pairings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_pairings', function (Blueprint $table): void {
            $table->id();
            // Typed by a person in the panel, so short and without look-alike characters.
            $table->string('code', 8)->unique();
            $table->string('secret_hash');
            $table->string('device_name', 60);
            // The minted token, encrypted, from approval until the app collects it.
            $table->text('token')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('abilities')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairings');
    }
};
```

`app/Models/DevicePairing.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A device waiting to be paired. The app holds the secret, a person types the code in the panel,
 * and the token the approval mints waits here until the app collects it, once.
 *
 * @property int $id
 * @property string $code
 * @property string $secret_hash
 * @property string $device_name
 * @property string|null $token
 * @property list<string>|null $abilities
 * @property Carbon|null $approved_at
 * @property Carbon $expires_at
 */
#[Fillable(['code', 'secret_hash', 'device_name', 'expires_at', 'created_at'])]
#[Hidden(['secret_hash', 'token'])]
class DevicePairing extends Model
{
    public $timestamps = false;

    // Long enough to walk to a computer, short enough that a shoulder-surfed code is useless.
    public const int MINUTES = 10;

    // No I, L, O, 0 or 1: these are read aloud and typed by hand.
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * A code that no pending pairing is already using.
     */
    public static function newCode(): string
    {
        do {
            $code = collect(range(1, 8))->map(fn (): string => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Mint the token for this device and keep it until the app collects it.
     *
     * @param  list<string>  $abilities
     */
    public function approve(User $user, array $abilities): void
    {
        $this->forceFill([
            'user_id' => $user->id,
            'abilities' => $abilities,
            'token' => $user->createToken($this->device_name, $abilities)->plainTextToken,
            'approved_at' => now(),
        ])->save();

        ActivityLog::record('token.pair', properties: ['device' => $this->device_name, 'abilities' => $abilities]);
    }

    /**
     * Throw away what nobody collected.
     */
    public static function pruneExpired(): int
    {
        return self::query()->where('expires_at', '<', now())->delete();
    }

    /**
     * Whether this is the secret the app was given.
     */
    public function matches(string $secret): bool
    {
        // A plain SHA-256, as Sanctum does for its tokens: the secret is 64 random characters, so
        // there is nothing to brute-force, and a slow password hash on an endpoint anyone may
        // poll would only be a way to keep the processor busy.
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public static function secret(): string
    {
        return Str::random(64);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'abilities' => 'array',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
```

`app/Http/Controllers/Api/V1/PairingController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DevicePairing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Getting a token onto a device without a password ever reaching it: the app shows a code, a
 * person approves it in the panel, and the app collects the token that approval minted.
 */
class PairingController extends Controller
{
    /**
     * Start a pairing. The answer holds the code to show and the secret to keep.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['device_name' => ['required', 'string', 'max:60']]);

        // Nobody will ever collect these; this is the cheapest moment to clear them out.
        DevicePairing::pruneExpired();

        $secret = DevicePairing::secret();
        $pairing = DevicePairing::create([
            'code' => DevicePairing::newCode(),
            'secret_hash' => hash('sha256', $secret),
            'device_name' => $data['device_name'],
            'expires_at' => now()->addMinutes(DevicePairing::MINUTES),
            'created_at' => now(),
        ]);

        return response()->json(['data' => [
            'code' => $pairing->code,
            'secret' => $secret,
            'device_name' => $pairing->device_name,
            'expires_at' => $pairing->expires_at->toIso8601String(),
            // Seconds between polls.
            'interval' => 3,
        ]], 201);
    }

    /**
     * Collect the token once it has been approved. The pairing is gone afterwards.
     */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:8'],
            'secret' => ['required', 'string', 'size:64'],
        ]);

        $pairing = DevicePairing::query()->where('code', $data['code'])->where('expires_at', '>', now())->first();

        // One answer for unknown, expired and wrong: a guesser learns nothing from it.
        abort_if($pairing === null || ! $pairing->matches($data['secret']), 404, 'No such pairing.');

        if ($pairing->approved_at === null) {
            return response()->json(['status' => 'pending'], 202);
        }

        $token = $pairing->token;
        $abilities = $pairing->abilities ?? [];
        $user = $pairing->user()->first();
        $pairing->delete();

        return response()->json([
            'token' => $token,
            'abilities' => $abilities,
            'user' => ['id' => $user?->id, 'name' => $user?->name, 'role' => $user?->role],
        ]);
    }
}
```

Add the relation to the model:

```php
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

In `routes/api.php`, **outside** the `auth:sanctum` group — an app has no token yet — with
`use App\Http\Controllers\Api\V1\PairingController;`:

```php
// Pairing a device. No token yet, so each of the two is guarded by a limiter of its own.
Route::prefix('v1')->group(function () {
    Route::post('pair', [PairingController::class, 'start'])->middleware('throttle:pairing');
    Route::post('pair/token', [PairingController::class, 'token'])->middleware('throttle:pairing-poll');
});
```

and in `app/Providers/AppServiceProvider::boot()`, beside the `api` limiter. They must be two
named limiters: one shared budget would let the app's own polling use up its right to start a
pairing, and `throttle:5,1` written inline counts both routes on one key.

```php
        // Unauthenticated, so by address. Starting a pairing writes a row, so it is tight; the
        // app then polls every 3 seconds, which is 20 a minute, and gets room above that.
        RateLimiter::for('pairing', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('pairing-poll', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
```

In `app/Console/Commands/PruneActivity.php`, at the **top** of `handle()`, add the lines below.
Not at the end: the command returns early when no activity retention is set, which is the
default, and the pairings would then never be cleared.

```php
        $pairings = DevicePairing::pruneExpired();
        $this->info("{$pairings} pairings that nobody finished deleted.");
```

with `use App\Models\DevicePairing;`.

- [ ] **Step 4: Document the two paths in `resources/api/openapi.json`**

```json
"/pair": {
  "post": { "summary": "Start pairing a device", "description": "Needs no token. Answers with a code to show the person and a secret to keep. Show the code; they approve it in the panel.", "responses": { "201": { "description": "The code and the secret" }, "429": { "description": "More than 5 pairings started from this address in a minute" } } }
},
"/pair/token": {
  "post": { "summary": "Collect the token once the pairing is approved", "description": "Needs no token. Poll with the code and the secret, waiting the interval the pairing answer gave: 202 while it is pending, 200 with the token once approved. The pairing is gone after that.", "responses": { "200": { "description": "The token" }, "202": { "description": "Nobody has approved it yet" }, "404": { "description": "Unknown, expired or already collected" } } }
},
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --compact --filter="ApiPairingTest|api documentation covers|ApiGuardTest|activity log is pruned"`
Expected: PASS.

- [ ] **Step 6: Commit**

```sh
vendor/bin/pint --dirty --format agent && composer types:check
git add panel/database/migrations panel/app/Models/DevicePairing.php panel/app/Http/Controllers/Api/V1/PairingController.php panel/routes/api.php panel/app/Providers/AppServiceProvider.php panel/app/Console/Commands/PruneActivity.php panel/resources/api/openapi.json panel/tests/Feature/ApiPairingTest.php
git commit -m "Pair a device with the panel instead of pasting a token"
```

---

### Task 15: Approving a device in the panel

**Files:**
- Modify: `app/Http/Controllers/Settings/ApiTokenController.php`, `routes/settings.php`, `resources/js/pages/settings/ApiTokens.vue`
- Test: `tests/Feature/ApiPairingTest.php` (append)

**Interfaces:**
- Consumes: `DevicePairing` (Task 14), `AbilityPicker` (Task 13).
- Produces: `POST settings/api-tokens/pairing` (look a code up) and `POST settings/api-tokens/pairing/approve` (mint the token), named `api-tokens.pairing` and `api-tokens.pairing.approve`.

- [ ] **Step 1: Write the failing test**

```php
use App\Api\Abilities;

test('a person looks a code up and sees which device is asking', function () {
    $user = User::factory()->admin()->create();
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Pixel 9'])->json('data');

    $this->actingAs($user)
        ->post(route('api-tokens.pairing'), ['code' => strtolower($started['code'])])
        ->assertSessionHasNoErrors();

    expect(session('pairing')['device_name'])->toBe('Pixel 9');

    $this->post(route('api-tokens.pairing'), ['code' => 'ZZZZZZZZ'])->assertSessionHasErrors('code');
});

test('approving mints a token the device can collect, and is recorded', function () {
    $user = User::factory()->admin()->create();
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Pixel 9'])->json('data');

    $this->actingAs($user)->post(route('api-tokens.pairing.approve'), [
        'code' => $started['code'],
        'abilities' => ['servers:read', 'servers:power'],
    ])->assertSessionHasNoErrors();

    expect($user->tokens()->sole()->name)->toBe('Pixel 9')
        ->and(App\Models\ActivityLog::query()->where('event', 'token.pair')->exists())->toBeTrue();

    $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])
        ->assertOk()->assertJsonPath('abilities', ['servers:read', 'servers:power']);
});

test('approving refuses an invented ability and an expired code', function () {
    $user = User::factory()->admin()->create();
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Pixel 9'])->json('data');

    $this->actingAs($user)
        ->post(route('api-tokens.pairing.approve'), ['code' => $started['code'], 'abilities' => ['servers:everything']])
        ->assertSessionHasErrors('abilities.0')
        // The device is still on the page, so the picker comes back instead of the code field.
        ->assertSessionHas('pairing.device_name', 'Pixel 9');

    DevicePairing::sole()->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->post(route('api-tokens.pairing.approve'), ['code' => $started['code'], 'abilities' => Abilities::presets()['read-only']])
        ->assertSessionHasErrors('code');
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter="looks a code up|Approving|approving"`
Expected: FAIL — the routes do not exist.

- [ ] **Step 3: Add the two actions**

In `app/Http/Controllers/Settings/ApiTokenController.php`, with
`use App\Models\DevicePairing;` and `use Illuminate\Validation\ValidationException;`:

```php
    /**
     * Look a pairing code up, so the person sees which device is asking before they approve it.
     */
    public function pairing(Request $request): RedirectResponse
    {
        $pairing = $this->pendingPairing($request);

        return back()->with('pairing', $pairing->only('code', 'device_name'));
    }

    /**
     * Approve a device: mint a token with the chosen abilities and let the device collect it.
     */
    public function approve(Request $request): RedirectResponse
    {
        $pairing = $this->pendingPairing($request);

        // The looked-up device lives in the session for one request only. Kept for one more, so
        // a refused form comes back with the device and the picker instead of the empty code field.
        $request->session()->flash('pairing', $pairing->only('code', 'device_name'));

        $data = $request->validate([
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(Abilities::all())],
        ]);

        $pairing->approve($request->user(), array_values($data['abilities']));
        $request->session()->forget('pairing');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('":device" is paired. It can sign in now.', ['device' => $pairing->device_name])]);

        return back();
    }

    /**
     * The pairing a code belongs to, if it is still waiting. The code is typed by hand, so case
     * and spaces are forgiven.
     */
    private function pendingPairing(Request $request): DevicePairing
    {
        $code = strtoupper(str_replace([' ', '-'], '', (string) $request->input('code')));
        $pairing = DevicePairing::query()->where('code', $code)->where('expires_at', '>', now())->whereNull('approved_at')->first();

        if ($pairing === null) {
            throw ValidationException::withMessages(['code' => __('No device is waiting with that code. Codes expire after ten minutes.')]);
        }

        return $pairing;
    }
```

In `routes/settings.php`, beside the existing api-token routes:

```php
    Route::post('settings/api-tokens/pairing', [ApiTokenController::class, 'pairing'])->name('api-tokens.pairing');
    Route::post('settings/api-tokens/pairing/approve', [ApiTokenController::class, 'approve'])->name('api-tokens.pairing.approve');
```

Then run `php artisan wayfinder:generate --with-form`.

- [ ] **Step 4: Add the screen**

In `resources/js/pages/settings/ApiTokens.vue`, add a section above the token list:

```vue
<SectionCard :title="t('Pair a device')">
    <Form
        v-if="!pairing"
        v-bind="pairingRoute.form()"
        :options="{ preserveScroll: true }"
        class="grid gap-2"
        v-slot="{ errors, processing }"
    >
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'Open the app, choose "Pair with a panel", and type the code it shows here.',
                )
            }}
        </p>
        <Label for="pairing_code">{{ t('Code from the app') }}</Label>
        <Input
            id="pairing_code"
            name="code"
            maxlength="12"
            autocomplete="off"
            class="max-w-48 font-mono tracking-widest uppercase"
        />
        <InputError :message="errors.code" />
        <Button type="submit" class="mt-1 w-fit" :disabled="processing">
            {{ t('Look it up') }}
        </Button>
    </Form>

    <Form
        v-else
        v-bind="approveRoute.form()"
        :options="{ preserveScroll: true }"
        class="grid gap-3"
        v-slot="{ errors, processing }"
    >
        <p class="text-sm">
            {{
                t('":device" is asking to be paired. Choose what it may do.', {
                    device: pairing.device_name,
                })
            }}
        </p>
        <input type="hidden" name="code" :value="pairing.code" />
        <AbilityPicker
            v-model="pairingAbilities"
            :groups="groups"
            :presets="presets"
        />
        <InputError :message="errors.code ?? errors['abilities.0']" />
        <Button type="submit" class="w-fit" :disabled="processing">
            {{ t('Approve this device') }}
        </Button>
    </Form>
</SectionCard>
```

with, in the script: `pairing` from the page props (`pairing: { code: string; device_name: string } | null`, filled from the session), `const pairingAbilities = ref<string[]>([...props.presets['read-only']]);`, and imports of `AbilityPicker`, `SectionCard` and the two generated routes. In the controller's `index()`, pass
`'pairing' => $request->session()->get('pairing')`.

- [ ] **Step 5: Run the tests and look at it**

```sh
npm run build && php artisan test --compact
npm run check:fix && npm run types:check
```

Then in a browser: start a pairing with
`curl -X POST http://localhost:8000/api/v1/pair -H 'Accept: application/json' -d device_name=Phone`,
type the code on `/settings/api-tokens`, approve with the Operator preset, and poll
`/api/v1/pair/token` with the code and secret to see the token come back once and 404 after.

- [ ] **Step 6: Commit**

```sh
git add panel/app/Http/Controllers/Settings/ApiTokenController.php panel/routes/settings.php panel/resources/js panel/tests/Feature/ApiPairingTest.php
git commit -m "Approve a paired device from the panel"
```

---

### Task 16: Documentation and the final sweep

**Files:**
- Modify: `README.md`, `CLAUDE.md`, `panel/resources/js/pages/ApiDocs.vue` (only if the new paths need grouping), `docs/superpowers/specs/2026-11-15-api-parity-and-token-abilities-design.md` (mark as built)

- [ ] **Step 1: Check the whole surface is documented and guarded**

```sh
php artisan test --compact
php artisan route:list --path=api/v1
```

Expected: every route in the list appears in `resources/api/openapi.json`, which the
documentation test already proves, and every one but `me` and the two pairing routes carries an
`ability:` middleware, which the guard test proves.

- [ ] **Step 2: Write the README section**

Under "Languages", before "Work on it", add:

```markdown
## The API

Everything the panel can do, a token can do: `/api/v1`, documented at `/api-docs` and described
by `panel/resources/api/openapi.json`. A token never exceeds its owner — a viewer's token can
only reach the servers shared with them, whatever abilities it holds.

Make a token under Settings, API tokens. Choose a starting point (read-only, operator or full
access) and adjust the groups underneath; the sentence below the form says what the token will
be able to do.

Signing in, changing a password, two-factor, passkeys and making tokens have no API: a stolen
token must not be able to lock you out or mint another one.

### Pairing an app

An app never needs your password. It shows an eight-character code; under Settings, API tokens,
type the code, check which device is asking, choose what it may do, and approve. The app then
collects its token once. A code is good for ten minutes.
```

- [ ] **Step 3: Note it in CLAUDE.md**

Under **Access**, after the existing API sentence, add:

```markdown
  Abilities live in `App\Api\Abilities` and are checked by the `ability:` middleware beside
  `can:`; `backups:manage` is a retired name kept for tokens issued before 1.5.0. A device is
  paired by code (`device_pairings`), never by handing over a password.
```

- [ ] **Step 4: Run everything one last time**

```sh
cd panel
npm run build
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer types:check
npm run check:fix && npm run types:check
php artisan wayfinder:generate --with-form
cd .. && sh scripts/check-version.sh origin/main
```

- [ ] **Step 5: Commit**

Name the files: `git add panel` would sweep in anything else that is lying in the tree.

```sh
git add README.md CLAUDE.md docs/superpowers/specs/2026-11-15-api-parity-and-token-abilities-design.md
git commit -m "Document the expanded API and device pairing"
```
