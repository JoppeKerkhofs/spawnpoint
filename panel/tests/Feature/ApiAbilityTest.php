<?php

use App\Api\Abilities;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

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
    $viewer = Abilities::offeredTo(User::factory()->create());
    $admin = Abilities::offeredTo(User::factory()->admin()->create());

    expect(array_column($viewer['groups'], 'key'))->not->toContain('administration')
        ->and($viewer['presets']['read-only'])->not->toContain('activity:read')
        ->and($viewer['presets']['full'])->not->toContain('users:manage')
        ->and($admin['groups'])->toBe(Abilities::groups())
        ->and($admin['presets'])->toBe(Abilities::presets());
});

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

    Sanctum::actingAs(User::factory()->create(), ['backups:manage']);
    $this->getJson('/api/v1/testing-mock')->assertOk();

    Sanctum::actingAs(User::factory()->create(), ['files:read']);
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
})->skip('Until Task 6 adds the restore route.');
