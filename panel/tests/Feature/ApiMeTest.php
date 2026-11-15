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
