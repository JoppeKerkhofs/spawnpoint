<?php

use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->server = Server::factory()->create(['slug' => 'survival', 'server_type' => 'FABRIC', 'mc_version' => '1.21.10']);
    $this->admin = User::factory()->admin()->create();
    $this->read = $this->admin->createToken('Read', ['files:read'])->plainTextToken;
    $this->write = $this->admin->createToken('Write', ['files:write'])->plainTextToken;

    Http::fake([
        'node.test:8080/servers/survival/mods' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response([['name' => 'sodium.jar', 'size' => 9, 'sha512' => 'h-sodium']])
            : Http::response(status: 204),
        'node.test:8080/servers/survival/files*' => Http::response(status: 204),
        'api.modrinth.com/v2/version_files/update' => Http::response(['h-sodium' => ['id' => 'v2']]),
        'api.modrinth.com/v2/version_files' => Http::response(['h-sodium' => ['id' => 'v1', 'project_id' => 'SODIUM', 'version_number' => '0.6.13']]),
        'api.modrinth.com/v2/project/*/version*' => Http::response([[
            'id' => 'v2', 'project_id' => 'SODIUM', 'dependencies' => [],
            'files' => [['primary' => true, 'filename' => 'sodium-2.jar', 'url' => 'https://cdn.modrinth.com/data/SODIUM/sodium-2.jar', 'hashes' => ['sha512' => 'h2']]],
        ]]),
        'api.modrinth.com/v2/projects*' => Http::response([['id' => 'SODIUM', 'title' => 'Sodium', 'icon_url' => null]]),
        'api.modrinth.com/v2/search*' => Http::response(['total_hits' => 1, 'hits' => [
            ['project_id' => 'LITHIUM', 'title' => 'Lithium', 'description' => 'Faster', 'icon_url' => null, 'downloads' => 12],
        ]]),
    ]);
});

test('a files:read token lists and searches mods but cannot change them', function () {
    $this->getJson('/api/v1/servers/survival/mods', bearer($this->read))
        ->assertOk()
        ->assertJsonPath('target', ['loader' => 'fabric', 'version' => '1.21.10'])
        ->assertJsonPath('data.0', ['file' => 'sodium.jar', 'size' => 9, 'project' => 'SODIUM', 'name' => 'Sodium', 'icon' => null, 'version' => '0.6.13', 'update' => true]);

    $this->getJson('/api/v1/servers/survival/mods/search?q=lith', bearer($this->read))
        ->assertOk()->assertJsonPath('data.0.project', 'LITHIUM')->assertJsonPath('more', false);

    foreach ([['postJson', 'mods', ['project' => 'sodium']], ['putJson', 'mods/sodium.jar', []], ['deleteJson', 'mods/sodium.jar', []]] as [$verb, $path, $body]) {
        $this->{$verb}("/api/v1/servers/survival/{$path}", $body, bearer($this->read))
            ->assertStatus(403)->assertJsonPath('message', 'This token lacks the files:write ability.');
    }
});

test('a files:write token installs, updates and removes mods', function () {
    $this->postJson('/api/v1/servers/survival/mods', ['project' => 'sodium'], bearer($this->write))
        ->assertStatus(201)->assertJsonPath('installed', ['Sodium'])->assertJsonPath('missing', []);
    $this->putJson('/api/v1/servers/survival/mods/sodium.jar', [], bearer($this->write))->assertOk()->assertJsonPath('installed', ['Sodium']);
    $this->deleteJson('/api/v1/servers/survival/mods/sodium.jar', [], bearer($this->write))->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['name'] === 'sodium-2.jar');
    expect(ActivityLog::query()->pluck('event')->all())->toBe(['mod.install', 'mod.update', 'mod.remove']);
});

test('the API explains what it could not do', function () {
    $this->postJson('/api/v1/servers/survival/mods', ['project' => '../x'], bearer($this->write))->assertStatus(422);
    $this->putJson('/api/v1/servers/survival/mods/private.jar', [], bearer($this->write))
        ->assertStatus(422)->assertJsonPath('message', 'Modrinth does not know this file, so it cannot be updated here.');

    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['node.test:8080/*' => Http::response([]), 'api.modrinth.com/*' => Http::response(status: 500)]);

    $this->postJson('/api/v1/servers/survival/mods', ['project' => 'sodium'], bearer($this->write))
        ->assertStatus(502)->assertJsonPath('message', 'Modrinth could not be reached.');
    $this->getJson('/api/v1/servers/survival/mods/search', bearer($this->read))->assertStatus(502);
});

test('a server without mods has no mods in the API', function () {
    Server::factory()->create(['slug' => 'lobby', 'server_type' => 'PAPER']);

    $this->getJson('/api/v1/servers/lobby/mods', bearer($this->read))->assertNotFound();
    $this->postJson('/api/v1/servers/lobby/mods', ['project' => 'sodium'], bearer($this->write))->assertNotFound();
});
