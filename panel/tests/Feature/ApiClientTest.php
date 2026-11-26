<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function clientServer(array $overrides = []): Server
{
    return Server::factory()->for(Node::factory()->create(['base_url' => 'http://node.test:8080']))->create(['slug' => 'modded', ...$overrides]);
}

// What the node answers for a file in the server's folder, by its path.
function nodeFiles(array $files): Closure
{
    return function (Request $request) use ($files) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($files[$query['path']]) ? Http::response($files[$query['path']]) : Http::response(status: 404);
    };
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->join = $this->admin->createToken('Launcher', ['servers:read', 'servers:join'])->plainTextToken;
});

test('a server says which pack version and loader it really runs', function () {
    clientServer([
        'server_type' => 'MODRINTH',
        'modpack' => ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon', 'icon' => null, 'version' => null],
    ]);
    Http::fake([
        'node.test:8080/servers/modded/download*' => nodeFiles([
            '.modrinth-modpack-manifest.json' => ['files' => ['mods/a.jar'], 'versionId' => 'NEW', 'dependencies' => ['minecraft' => '1.21.1', 'fabric-loader' => '0.20.1']],
            '.fabric-manifest.json' => ['origin' => ['game' => '1.21.1', 'loader' => '0.20.1']],
        ]),
        'node.test:8080/servers' => Http::response([]),
        'api.modrinth.com/v2/project/cobblemon-fabric/version' => Http::response([['loaders' => ['fabric'], 'game_versions' => ['1.21.1']]]),
    ]);

    $this->getJson('/api/v1/servers/modded', bearer($this->join))->assertOk()
        ->assertJsonPath('data.game_version', '1.21.1')
        ->assertJsonPath('data.modpack', ['catalogue' => 'modrinth', 'project' => 'cobblemon-fabric', 'version' => 'NEW', 'version_name' => null])
        ->assertJsonPath('data.loader', ['name' => 'fabric', 'version' => '0.20.1']);
});

test('a server without mods has no pack and no loader, and one not installed yet no versions', function () {
    clientServer(['mc_version' => '1.21.10', 'settings' => [...Server::factory()->make()->settings, 'client_jvm_opts' => '-XX:+UseZGC']]);
    Server::factory()->for(Node::first())->create(['slug' => 'fresh', 'server_type' => 'NEOFORGE', 'mc_version' => '1.21.1']);
    Http::fake(['node.test:8080/servers' => Http::response([]), 'node.test:8080/*' => Http::response(status: 404)]);

    $this->getJson('/api/v1/servers/modded', bearer($this->join))->assertOk()
        ->assertJsonPath('data.game_version', '1.21.10')->assertJsonPath('data.modpack', null)->assertJsonPath('data.loader', null)
        ->assertJsonPath('data.client_jvm_opts', '-XX:+UseZGC');
    $this->getJson('/api/v1/servers/fresh', bearer($this->join))->assertOk()
        ->assertJsonPath('data.game_version', '1.21.1')->assertJsonPath('data.loader', ['name' => 'neoforge', 'version' => null])
        ->assertJsonPath('data.client_jvm_opts', null);
});

test('an ftb pack names its own loader', function () {
    clientServer(['server_type' => 'FTBA', 'modpack' => ['project' => '100', 'name' => 'StoneBlock', 'icon' => null, 'version' => '100359']]);
    Http::fake([
        'node.test:8080/servers/modded/download*' => nodeFiles(['.manifest.json' => [
            'versionName' => '1.11.6', 'versionId' => 100359,
            'modPackTargets' => ['modLoader' => ['name' => 'forge', 'version' => '40.2.34'], 'mcVersion' => '1.18.2'],
            'files' => [['name' => 'jei.jar', 'path' => './mods']],
        ]]),
        'node.test:8080/servers' => Http::response([]),
        '*' => Http::response(status: 500),
    ]);

    $this->getJson('/api/v1/servers/modded', bearer($this->join))->assertOk()
        ->assertJsonPath('data.game_version', '1.18.2')
        ->assertJsonPath('data.modpack', ['catalogue' => 'ftb', 'project' => '100', 'version' => '100359', 'version_name' => '1.11.6'])
        ->assertJsonPath('data.loader', ['name' => 'forge', 'version' => '40.2.34']);
});

test('a pack server lists only what was changed on top of the pack, without mods the game cannot run', function () {
    clientServer(['server_type' => 'MODRINTH', 'modpack' => ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon', 'icon' => null, 'version' => 'NEW']]);
    Http::fake([
        'node.test:8080/servers/modded/download*' => nodeFiles([
            '.modrinth-modpack-manifest.json' => ['files' => ['mods/pack.jar', 'mods/taken-out.jar', 'config/x.toml'], 'versionId' => 'NEW'],
        ]),
        'node.test:8080/servers/modded/mods' => Http::response([
            ['name' => 'pack.jar', 'size' => 1, 'sha512' => 'h-pack'],
            ['name' => 'sodium.jar', 'size' => 9, 'sha512' => 'h-sodium'],
            ['name' => 'server-only.jar', 'size' => 5, 'sha512' => 'h-server'],
            ['name' => 'own-mod.jar', 'size' => 4, 'sha512' => 'h-own'],
        ]),
        'api.modrinth.com/v2/version_files' => Http::response([
            'h-sodium' => ['project_id' => 'SODIUM', 'files' => [['url' => 'https://cdn.modrinth.com/data/SODIUM/sodium.jar', 'hashes' => ['sha512' => 'h-sodium']]]],
            'h-server' => ['project_id' => 'CHUNKY', 'files' => [['url' => 'https://cdn.modrinth.com/data/CHUNKY/c.jar', 'hashes' => ['sha512' => 'h-server']]]],
        ]),
        'api.modrinth.com/v2/projects*' => Http::response([['id' => 'SODIUM', 'client_side' => 'required'], ['id' => 'CHUNKY', 'client_side' => 'unsupported']]),
        'api.modrinth.com/v2/project/*' => Http::response(['loaders' => ['fabric'], 'game_versions' => ['1.21.1']]),
    ]);

    $this->getJson('/api/v1/servers/modded/client-mods', bearer($this->join))->assertOk()->assertExactJson([
        'mods' => [
            ['file' => 'sodium.jar', 'sha512' => 'h-sodium', 'size' => 9, 'url' => 'https://cdn.modrinth.com/data/SODIUM/sodium.jar'],
            ['file' => 'own-mod.jar', 'sha512' => 'h-own', 'size' => 4, 'url' => null],
        ],
        'removed' => ['taken-out.jar'],
    ]);
    // The pack's own jar is not looked up: the launcher has it from the pack.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/version_files') && $request['hashes'] === ['h-sodium', 'h-server', 'h-own']);
});

test('a pack that is not installed yet has no list, and a server without mods no such page', function () {
    clientServer(['server_type' => 'MODRINTH', 'modpack' => ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon', 'icon' => null, 'version' => null]]);
    Server::factory()->for(Node::first())->create(['slug' => 'paper']);
    Http::fake(['api.modrinth.com/*' => Http::response([]), '*' => Http::response(status: 404)]);

    $this->getJson('/api/v1/servers/modded/client-mods', bearer($this->join))->assertStatus(409);
    $this->getJson('/api/v1/servers/paper/client-mods', bearer($this->join))->assertNotFound();
});

test('one jar is passed on from the mods folder and nothing else', function () {
    clientServer(['server_type' => 'FABRIC', 'mc_version' => '1.21.10']);
    Http::fake(['node.test:8080/servers/modded/download*' => Http::response('jar-bytes', 200, ['Content-Length' => '9'])]);

    $response = $this->get('/api/v1/servers/modded/client-mods/own-mod.jar', bearer($this->join))->assertOk();
    expect($response->streamedContent())->toBe('jar-bytes');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'path=mods%2Fown-mod.jar'));

    $this->get('/api/v1/servers/modded/client-mods/..%2Fserver.properties', bearer($this->join))->assertNotFound();
    $this->get('/api/v1/servers/modded/client-mods/notes.txt', bearer($this->join))->assertNotFound();
});

test('joining needs its ability and a server the owner may see, not the files permission', function () {
    clientServer(['server_type' => 'FABRIC', 'mc_version' => '1.21.10']);
    Http::fake(['node.test:8080/servers/modded/mods' => Http::response([]), '*' => Http::response(status: 404)]);
    $viewer = User::factory()->create();
    $outsider = User::factory()->create()->createToken('Launcher', ['servers:join'])->plainTextToken;
    Server::first()->viewers()->attach($viewer, ['permissions' => json_encode([])]);

    $this->getJson('/api/v1/servers/modded/client-mods', bearer($viewer->createToken('Launcher', ['servers:join'])->plainTextToken))
        ->assertOk()->assertExactJson(['mods' => [], 'removed' => []]);
    $this->getJson('/api/v1/servers/modded/client-mods', bearer($outsider))->assertForbidden();
    $this->getJson('/api/v1/servers/modded/client-mods', bearer($this->admin->createToken('Read', ['servers:read', 'files:read'])->plainTextToken))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the servers:join ability.');
});

test('a curseforge server says where its pack and the files in it are downloaded, with the key kept here', function () {
    config(['panel.curseforge_key' => 'secret-key']);
    clientServer(['server_type' => 'AUTO_CURSEFORGE', 'modpack' => ['project' => 'all-the-mods-10', 'name' => 'ATM10', 'icon' => null]]);
    Server::factory()->for(Node::first())->create(['slug' => 'fabric', 'server_type' => 'FABRIC']);
    Http::fake([
        'node.test:8080/servers/modded/download*' => nodeFiles(['.curseforge-manifest.json' => ['fileId' => 7001, 'modpackVersion' => '4.2']]),
        'api.curseforge.com/v1/mods/search*' => Http::response(['data' => [[
            'id' => 925200, 'mainFileId' => 7002, 'links' => ['websiteUrl' => 'https://www.curseforge.com/minecraft/modpacks/all-the-mods-10'],
            'latestFilesIndexes' => [['modLoader' => 6, 'gameVersion' => '1.21.1']],
        ]]]),
        'api.curseforge.com/v1/mods/925200/files/7001' => Http::response(['data' => ['downloadUrl' => 'https://edge.forgecdn.net/files/7/1/atm10.zip']]),
        'api.curseforge.com/v1/mods/files' => Http::response(['data' => [
            ['id' => 5101366, 'modId' => 238222, 'fileName' => 'jei.jar', 'downloadUrl' => 'https://edge.forgecdn.net/files/5101/366/jei.jar'],
            ['id' => 222222, 'modId' => 111111, 'fileName' => 'x.jar', 'downloadUrl' => null],
        ]]),
        'api.curseforge.com/v1/mods' => Http::response(['data' => [['id' => 111111, 'links' => ['websiteUrl' => 'https://www.curseforge.com/minecraft/mc-mods/x']]]]),
        '*' => Http::response(status: 404),
    ]);

    $pack = $this->getJson('/api/v1/servers/modded/client-pack', bearer($this->join))->assertOk()->assertExactJson([
        'project' => 925200, 'file' => 7001, 'archive' => 'https://edge.forgecdn.net/files/7/1/atm10.zip',
        'page' => 'https://www.curseforge.com/minecraft/modpacks/all-the-mods-10',
    ]);
    $files = $this->postJson('/api/v1/servers/modded/client-pack/files', ['files' => [5101366, 222222]], bearer($this->join))->assertOk()->assertExactJson(['files' => [
        ['project' => 238222, 'file' => 5101366, 'name' => 'jei.jar', 'url' => 'https://edge.forgecdn.net/files/5101/366/jei.jar', 'page' => null],
        ['project' => 111111, 'file' => 222222, 'name' => 'x.jar', 'url' => null, 'page' => 'https://www.curseforge.com/minecraft/mc-mods/x/files/222222'],
    ]]);

    expect($pack->getContent().$files->getContent())->not->toContain('secret-key');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/mods') && $request['modIds'] === [111111] && $request->header('x-api-key') === ['secret-key']);
    $this->postJson('/api/v1/servers/modded/client-pack/files', ['files' => ['5; drop']], bearer($this->join))->assertStatus(422);
    $this->getJson('/api/v1/servers/fabric/client-pack', bearer($this->join))->assertNotFound();

    config(['panel.curseforge_key' => null]);
    $this->getJson('/api/v1/servers/modded/client-pack', bearer($this->join))->assertStatus(409);
});
