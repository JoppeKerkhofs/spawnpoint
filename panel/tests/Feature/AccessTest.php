<?php

use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->shared = Server::factory()->create(['slug' => 'shared', 'name' => 'Shared']);
    $this->hidden = Server::factory()->for($this->shared->node)->create(['slug' => 'hidden', 'name' => 'Hidden']);
    $this->viewer = User::factory()->create();
    $this->viewer->servers()->attach($this->shared, ['permissions' => '["logs"]']);
    $this->admin = User::factory()->admin()->create();

    Http::fake([
        'node.test:8080/servers' => Http::response([
            ['slug' => 'shared', 'state' => 'running', 'detail' => null],
            ['slug' => 'hidden', 'state' => 'offline', 'detail' => null],
        ]),
        'node.test:8080/servers/*' => Http::response(status: 202),
    ]);
});

test('a viewer sees only the servers shared with them, and only their states', function () {
    $this->actingAs($this->viewer)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('servers', 1)
            ->where('servers.0.slug', 'shared')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('states.servers', ['shared' => ['state' => 'running', 'detail' => null, 'players' => null, 'usage' => null, 'disk_bytes' => null, 'last_backup' => null, 'mods_changed' => 0]])),
        );
});

test('a viewer cannot control a server that is shared with them', function () {
    $this->actingAs($this->viewer);

    $this->post(route('servers.power', [$this->shared, 'stop']))->assertForbidden();
    $this->get(route('servers.configuration', $this->shared))->assertForbidden();
    $this->get(route('servers.files', $this->shared))->assertForbidden();
    $this->post(route('servers.schedules.store', $this->shared), ['action' => 'stop', 'time' => '04:00'])->assertForbidden();
    $this->get(route('users.index'))->assertForbidden();
    $this->post(route('users.store'), ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'role' => 'admin'])->assertForbidden();

    Http::assertNothingSent();
});

test('a viewer can watch the console and read the logs of a shared server only, without sending commands', function () {
    $this->freezeTime();
    Http::swap(new HttpFactory);
    Http::fake(['node.test:8080/servers/shared/logs' => Http::response([]), 'node.test:8080/servers' => Http::response([])]);
    $this->actingAs($this->viewer);

    $this->get(route('servers.show', $this->shared))
        ->assertInertia(fn (Assert $page) => $page
            ->where('server.can', ['logs'])
            ->where('console.url', 'ws://node.test:8080/servers/shared/console?token='
                .NodeClient::token('console', $this->shared->node->token, 'shared', now()->getTimestamp() + 60, false)),
        );
    $this->get(route('servers.logs', $this->shared))->assertOk();

    $this->get(route('servers.show', $this->hidden))->assertForbidden();
    $this->get(route('servers.logs', $this->hidden))->assertForbidden();
    $this->get(route('servers.logs.download', [$this->hidden, 'file' => 'latest.log']))->assertForbidden();
});

test('a user created without a password is emailed a link to set one', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('users.store'), ['name' => 'Lien', 'email' => 'lien@example.com', 'role' => 'viewer'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo(User::firstWhere('email', 'lien@example.com'), ResetPassword::class);
});

test('an admin creates a viewer and shares servers with them', function () {
    $this->actingAs($this->admin)
        ->post(route('users.store'), [
            'name' => 'Lien', 'email' => 'lien@example.com', 'password' => 'a-long-password', 'role' => 'viewer',
            'shared' => [$this->hidden->id],
            // The tick on a server that is not shared is dropped.
            'permissions' => ["{$this->hidden->id}:files", "{$this->hidden->id}:power", "{$this->shared->id}:configure"],
        ])
        ->assertSessionHasNoErrors();

    $user = User::firstWhere('email', 'lien@example.com');
    expect($user->role)->toBe('viewer')
        ->and(Hash::check('a-long-password', $user->password))->toBeTrue()
        ->and($user->servers->pluck('slug')->all())->toBe(['hidden'])
        ->and($this->hidden->permissionsFor($user))->toBe(['power', 'files'])
        ->and($this->shared->permissionsFor($user))->toBe([]);
});

test('promoting a viewer to admin drops their shares, and an admin cannot demote or delete themselves', function () {
    $this->actingAs($this->admin);

    $this->put(route('users.update', $this->viewer), ['role' => 'admin', 'shared' => [$this->shared->id]])->assertSessionHasNoErrors();
    expect($this->viewer->refresh()->role)->toBe('admin')->and($this->viewer->servers()->count())->toBe(0);

    $this->put(route('users.update', $this->admin), ['role' => 'viewer'])->assertSessionHasErrors('role');
    $this->delete(route('users.destroy', $this->admin))->assertSessionHasErrors('user');
    expect($this->admin->refresh()->role)->toBe('admin');
});

test('deleting a user hands their servers to the admin', function () {
    $owner = User::find($this->shared->owner_id);

    $this->actingAs($this->admin)->delete(route('users.destroy', $owner))->assertSessionHasNoErrors();

    expect(User::find($owner->id))->toBeNull()->and($this->shared->refresh()->owner_id)->toBe($this->admin->id);
});

test('the role cannot be set through the profile form', function () {
    $this->actingAs($this->viewer)
        ->patch(route('profile.update'), ['name' => 'Sneaky', 'email' => $this->viewer->email, 'role' => 'admin']);

    expect($this->viewer->refresh()->role)->toBe('viewer');
});

test('an api token is shown once and stored only as a hash', function () {
    $this->actingAs($this->admin)
        ->post(route('api-tokens.store'), ['name' => 'Phone', 'abilities' => ['servers:read']])
        ->assertSessionHas('new_token');

    $plain = session('new_token');
    $stored = $this->admin->tokens()->sole();
    expect($stored->token)->not->toBe($plain)
        ->and($stored->abilities)->toBe(['servers:read']);

    $this->post(route('api-tokens.store'), ['name' => 'Bad', 'abilities' => ['users:delete']])->assertSessionHasErrors('abilities.0');
    $this->delete(route('api-tokens.destroy', $stored->id));
    expect($this->admin->tokens()->count())->toBe(0);
});

test('a token cannot be revoked by another user', function () {
    $token = $this->admin->createToken('Phone', ['servers:read']);

    $this->actingAs($this->viewer)->delete(route('api-tokens.destroy', $token->accessToken->id));

    expect($this->admin->tokens()->count())->toBe(1);
});

test('the api needs a token', function () {
    $this->getJson('/api/v1/servers')->assertUnauthorized();
    $this->postJson('/api/v1/servers/shared/power/stop')->assertUnauthorized();
});

test('the api lists servers with their state for a real token', function () {
    $token = $this->admin->createToken('Phone', ['servers:read'])->plainTextToken;

    $this->getJson('/api/v1/servers', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('service_online', true)
        ->assertJsonPath('data.0.slug', 'hidden')
        ->assertJsonPath('data.1.state', 'running')
        ->assertJsonMissingPath('data.0.owner_id');
});

test('the api shows a viewer only their shared servers', function () {
    Sanctum::actingAs($this->viewer, ['servers:read', 'servers:power']);

    $this->getJson('/api/v1/servers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'shared');
    // The ability is on the token, but the share does not include power.
    $this->postJson('/api/v1/servers/shared/power/stop')->assertForbidden();
});

test('the api gives console, logs and backups only with the matching ability and role', function () {
    Http::swap(new HttpFactory);
    Http::fake([
        'node.test:8080/servers' => Http::response([['slug' => 'shared', 'state' => 'running', 'detail' => null]]),
        'node.test:8080/servers/shared/backups' => Http::sequence()->push([])->push(status: 202)->push([]),
        'node.test:8080/servers/shared/logs' => Http::response([['name' => 'latest.log', 'size' => 1, 'modified' => 1]]),
        'node.test:8080/servers/shared/logs/latest.log?q=joined' => Http::response("Steve joined the game\n"),
    ]);

    Sanctum::actingAs($this->viewer, ['servers:read', 'console:use', 'logs:read', 'backups:manage']);
    $this->getJson('/api/v1/servers/shared')->assertJsonPath('data.state', 'running');
    $this->getJson('/api/v1/servers/hidden')->assertForbidden();
    $this->getJson('/api/v1/servers/shared/console')->assertJsonPath('write', false);
    $this->getJson('/api/v1/servers/shared/logs')->assertJsonPath('files.0.name', 'latest.log');
    $this->getJson('/api/v1/servers/shared/logs?file=latest.log&q=joined')->assertJsonPath('content', "Steve joined the game\n");
    $this->getJson('/api/v1/servers/shared/logs?file=../ops.json')->assertStatus(422);
    $this->getJson('/api/v1/servers/shared/backups')->assertForbidden();

    Sanctum::actingAs($this->admin, ['servers:read']);
    $this->getJson('/api/v1/servers/shared/console')->assertForbidden();
    $this->getJson('/api/v1/servers/shared/logs')->assertForbidden();
    $this->postJson('/api/v1/servers/shared/backups')->assertForbidden();

    Sanctum::actingAs($this->admin, ['console:use', 'backups:manage']);
    $this->getJson('/api/v1/servers/shared/console')->assertJsonPath('write', true);
    $this->getJson('/api/v1/servers/shared/backups')->assertOk();
    $this->postJson('/api/v1/servers/shared/backups')->assertStatus(202);
});

test('the api reads and writes files only with the files abilities, for admins', function () {
    Http::swap(new HttpFactory);
    Http::fake([
        'node.test:8080/servers/shared/files?path=mods' => Http::response([['name' => 'a.jar', 'dir' => false, 'size' => 1, 'modified' => 1]]),
        'node.test:8080/servers/shared/files/content?path=ops.json' => Http::sequence()->push('[]')->push(status: 204),
        'node.test:8080/servers/shared/files?path=old.txt' => Http::response(status: 204),
        'node.test:8080/servers/shared/files?path=gone.txt' => Http::response(status: 404),
    ]);

    Sanctum::actingAs($this->viewer, ['files:read', 'files:write']);
    $this->getJson('/api/v1/servers/shared/files?path=mods')->assertForbidden();

    Sanctum::actingAs($this->admin, ['files:read']);
    $this->getJson('/api/v1/servers/shared/files?path=mods')->assertJsonPath('entries.0.name', 'a.jar');
    $this->getJson('/api/v1/servers/shared/files?path=ops.json&file=1')->assertJsonPath('content', '[]');
    $this->getJson('/api/v1/servers/shared/files?path=../other')->assertStatus(422);
    $this->putJson('/api/v1/servers/shared/files', ['path' => 'ops.json', 'content' => '[]'])->assertForbidden();
    $this->getJson('/api/v1/servers/shared/files/transfer')->assertForbidden();

    Sanctum::actingAs($this->admin, ['files:write']);
    $this->putJson('/api/v1/servers/shared/files', ['path' => 'ops.json', 'content' => '[]'])->assertOk();
    $this->deleteJson('/api/v1/servers/shared/files', ['path' => 'old.txt'])->assertOk();
    $this->deleteJson('/api/v1/servers/shared/files', ['path' => 'gone.txt'])->assertNotFound();
    $this->getJson('/api/v1/servers/shared/files/transfer')->assertJsonStructure(['upload', 'download']);
});

test('the api answers 502 when the rust service is down', function () {
    Http::swap(new HttpFactory);
    Http::fake(['node.test:8080/*' => Http::failedConnection()]);
    Sanctum::actingAs($this->admin, ['backups:manage']);

    $this->getJson('/api/v1/servers/shared/backups')->assertStatus(502)->assertJsonStructure(['message']);
});

test('the api starts and stops servers only with the power ability', function () {
    Sanctum::actingAs($this->admin, ['servers:read']);
    $this->postJson('/api/v1/servers/shared/power/stop')->assertForbidden();
    Http::assertNothingSent();

    Sanctum::actingAs($this->admin, ['servers:power']);
    $this->postJson('/api/v1/servers/shared/power/stop')->assertStatus(202);
    $this->postJson('/api/v1/servers/shared/power/format')->assertNotFound();
    $this->getJson('/api/v1/servers')->assertForbidden();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/shared/stop'));
});

test('a viewer can do what their share allows and nothing more', function () {
    $this->freezeTime();
    $this->viewer->servers()->sync([$this->shared->id => ['permissions' => '["power","commands"]']]);
    $this->actingAs($this->viewer);

    $this->post(route('servers.power', [$this->shared, 'stop']))->assertRedirect();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/shared/stop'));

    $this->get(route('servers.show', $this->shared))
        ->assertInertia(fn (Assert $page) => $page
            ->where('server.can', ['commands', 'power'])
            ->where('console.url', 'ws://node.test:8080/servers/shared/console?token='
                .NodeClient::token('console', $this->shared->node->token, 'shared', now()->getTimestamp() + 60, true)),
        );

    $this->get(route('servers.logs', $this->shared))->assertForbidden();
    $this->get(route('servers.files', $this->shared))->assertForbidden();
    $this->get(route('servers.backups', $this->shared))->assertForbidden();
    $this->get(route('servers.configuration', $this->shared))->assertForbidden();
    $this->get(route('modpacks.search', ['platform' => 'MODRINTH']))->assertForbidden();
    $this->delete(route('servers.destroy', $this->shared))->assertForbidden();
    $this->post(route('servers.power', [$this->hidden, 'stop']))->assertForbidden();

    Sanctum::actingAs($this->viewer, ['servers:power', 'logs:read']);
    $this->postJson('/api/v1/servers/shared/power/start')->assertStatus(202);
    $this->getJson('/api/v1/servers/shared/logs')->assertForbidden();
});

test('a viewer who may configure cannot delete the server or make it the default', function () {
    $this->viewer->servers()->sync([$this->shared->id => ['permissions' => '["configure"]']]);
    $this->actingAs($this->viewer);

    $this->get(route('servers.configuration', $this->shared))->assertOk();
    $this->put(route('servers.update', $this->shared), [
        ...$this->shared->only('name', 'server_type', 'mc_version', 'java_version', 'memory_mb'),
        ...$this->shared->settings, 'is_default' => '1',
    ])->assertSessionHasNoErrors();

    expect($this->shared->refresh()->is_default)->toBeFalse();
    $this->delete(route('servers.destroy', $this->shared))->assertForbidden();
});
