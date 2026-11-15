<?php

use App\Api\Abilities;
use App\Models\ActivityLog;
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
        ->and(ActivityLog::query()->where('event', 'token.pair')->exists())->toBeTrue();

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
