<?php

use App\Models\ActivityLog;
use App\Models\DevicePairing;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function launcherPairing(string $device = 'Spawnpoint Portal on DESKTOP'): array
{
    return test()->postJson('/api/v1/pair', ['device_name' => $device, 'abilities' => ['servers:read', 'servers:join']])
        ->assertStatus(201)
        ->json('data');
}

test('an app that names what it needs gets the path of the page to open', function () {
    $started = launcherPairing();

    expect($started['approve_path'])->toBe("/pair/{$started['code']}")
        ->and(DevicePairing::sole()->requested_abilities)->toBe(['servers:read', 'servers:join']);
});

test('an app cannot ask for more than a launcher needs', function () {
    $this->postJson('/api/v1/pair', ['device_name' => 'Phone', 'abilities' => ['servers:read', 'servers:power']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('abilities.1');

    $this->postJson('/api/v1/pair', ['device_name' => 'Phone', 'abilities' => []])->assertStatus(422);

    expect(DevicePairing::count())->toBe(0);
});

test('a pairing started without abilities has no page', function () {
    $started = $this->postJson('/api/v1/pair', ['device_name' => 'Phone'])->json('data');

    expect($started)->not->toHaveKey('approve_path');

    $this->actingAs(User::factory()->create())
        ->get("/pair/{$started['code']}")
        ->assertInertia(fn (Assert $page) => $page->component('auth/PairDevice')->where('pairing', null));
});

test('a guest is sent to sign in and comes back to the page', function () {
    $started = launcherPairing();

    $this->get($started['approve_path'])->assertRedirect(route('login'));

    expect(session('url.intended'))->toEndWith($started['approve_path']);
});

test('a viewer sees which device asks, for what, and under which code', function () {
    $started = launcherPairing();

    $this->actingAs(User::factory()->create())
        ->get(strtolower($started['approve_path']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/PairDevice')
            ->where('pairing.code', $started['code'])
            ->where('pairing.device_name', 'Spawnpoint Portal on DESKTOP')
            ->where('pairing.abilities.0.name', 'servers:read')
            ->where('pairing.abilities.1.name', 'servers:join')
            ->has('pairing.abilities', 2)
            ->where('outcome', null));
});

test('allowing mints a token with what was asked for, under the name the person chose', function () {
    $user = User::factory()->create();
    $started = launcherPairing();

    $this->actingAs($user)
        ->post($started['approve_path'], ['device_name' => 'Gaming PC'])
        ->assertRedirect($started['approve_path'])
        ->assertSessionHas('pair_outcome', ['state' => 'linked', 'device' => 'Gaming PC']);

    $token = $user->tokens()->sole();
    expect($token->name)->toBe('Gaming PC')
        ->and($token->abilities)->toBe(['servers:read', 'servers:join'])
        ->and(ActivityLog::query()->where('event', 'token.pair')->sole()->properties['device'])->toBe('Gaming PC');

    $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])
        ->assertOk()
        ->assertJsonPath('abilities', ['servers:read', 'servers:join'])
        ->assertJsonPath('user.id', $user->id);
});

test('a device needs a name of at most sixty characters', function () {
    $user = User::factory()->create();
    $started = launcherPairing();

    $this->actingAs($user)->post($started['approve_path'], ['device_name' => ''])->assertSessionHasErrors('device_name');
    $this->post($started['approve_path'], ['device_name' => str_repeat('a', 61)])->assertSessionHasErrors('device_name');

    expect($user->tokens()->count())->toBe(0);
});

test('refusing throws the pairing away, and the app is told it is gone', function () {
    $started = launcherPairing();

    $this->actingAs(User::factory()->create())
        ->delete($started['approve_path'])
        ->assertRedirect($started['approve_path'])
        ->assertSessionHas('pair_outcome.state', 'refused');

    expect(DevicePairing::count())->toBe(0);

    $this->postJson('/api/v1/pair/token', ['code' => $started['code'], 'secret' => $started['secret']])->assertStatus(404);
});

test('an expired, an approved and an unknown code all show that no device is waiting', function () {
    $user = User::factory()->create();
    $expired = launcherPairing();
    DevicePairing::sole()->forceFill(['expires_at' => now()->subMinute()])->save();
    $approved = launcherPairing();
    DevicePairing::query()->where('code', $approved['code'])->sole()->approve($user, ['servers:read']);

    foreach ([$expired['code'], $approved['code'], 'ZZZZZZZZ'] as $code) {
        $this->actingAs($user)->get("/pair/{$code}")->assertInertia(fn (Assert $page) => $page->where('pairing', null));
        $this->post("/pair/{$code}", ['device_name' => 'PC'])->assertRedirect("/pair/{$code}")->assertSessionMissing('pair_outcome');
    }

    // Only the one approval above made a token.
    expect($user->tokens()->count())->toBe(1);
});
