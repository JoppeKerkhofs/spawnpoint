<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->shared = Server::factory()->create(['slug' => 'shared', 'name' => 'Shared']);
    $this->hidden = Server::factory()->for($this->shared->node)->create(['slug' => 'hidden', 'name' => 'Hidden']);
    $this->viewer = User::factory()->create();
    $this->viewer->servers()->attach($this->shared);
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
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('states.servers', ['shared' => ['state' => 'running', 'detail' => null, 'players' => null]])),
        );
});

test('a viewer cannot control a server that is shared with them', function () {
    $this->actingAs($this->viewer);

    $this->post(route('servers.power', [$this->shared, 'stop']))->assertForbidden();
    $this->get(route('servers.show', $this->shared))->assertForbidden();
    $this->get(route('users.index'))->assertForbidden();
    $this->post(route('users.store'), ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'role' => 'admin'])->assertForbidden();

    Http::assertNothingSent();
});

test('an admin creates a viewer and shares servers with them', function () {
    $this->actingAs($this->admin)
        ->post(route('users.store'), [
            'name' => 'Lien', 'email' => 'lien@example.com', 'password' => 'a-long-password', 'role' => 'viewer', 'servers' => [$this->hidden->id],
        ])
        ->assertSessionHasNoErrors();

    $user = User::firstWhere('email', 'lien@example.com');
    expect($user->role)->toBe('viewer')
        ->and(Hash::check('a-long-password', $user->password))->toBeTrue()
        ->and($user->servers->pluck('slug')->all())->toBe(['hidden']);
});

test('promoting a viewer to admin drops their shares, and an admin cannot demote or delete themselves', function () {
    $this->actingAs($this->admin);

    $this->put(route('users.update', $this->viewer), ['role' => 'admin', 'servers' => [$this->shared->id]])->assertSessionHasNoErrors();
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
    // The ability is on the token, but the role still decides.
    $this->postJson('/api/v1/servers/shared/power/stop')->assertForbidden();
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
