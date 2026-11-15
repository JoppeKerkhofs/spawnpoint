<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

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
    $paths = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/'));

    foreach (['login', 'password', 'two-factor', 'passkey', 'tokens'] as $forbidden) {
        expect($paths->filter(fn (string $uri): bool => str_contains($uri, $forbidden))->all())->toBe([]);
    }
});
