<?php

use App\Models\Server;
use App\Models\Setting;
use App\Services\Mods;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function modServer(string $type = 'FABRIC', array $attributes = []): Server
{
    return Server::factory()->create(['slug' => 'survival', 'server_type' => $type, 'mc_version' => '1.21.10', ...$attributes]);
}

test('only loader and modpack servers have mods', function (string $type, bool $has) {
    expect(Server::factory()->make(['server_type' => $type])->hasMods())->toBe($has);
})->with([
    ['FABRIC', true], ['FORGE', true], ['NEOFORGE', true], ['MODRINTH', true], ['AUTO_CURSEFORGE', true], ['FTBA', true],
    ['VANILLA', false], ['PAPER', false], ['PUMPKIN', false],
]);

test('a loader server needs mods for its own loader and version', function () {
    Http::fake();

    expect(Mods::target(modServer()))->toBe(['loader' => 'fabric', 'version' => '1.21.10']);
    Http::assertNothingSent();
});

test('"latest" is resolved to the newest release, or to nothing when Mojang is down', function () {
    $server = modServer('NEOFORGE', ['mc_version' => 'LATEST']);
    Http::fake(['piston-meta.mojang.com/*' => Http::sequence()
        ->push(status: 500)
        ->push(['versions' => [['id' => '25w01a', 'type' => 'snapshot'], ['id' => '1.21.11', 'type' => 'release']]])]);

    expect(Mods::target($server))->toBeNull()
        ->and(Mods::target($server))->toBe(['loader' => 'neoforge', 'version' => '1.21.11']);
});

test('a Modrinth pack is asked what it runs on', function (array $pack, string $address) {
    $server = modServer('MODRINTH', ['mc_version' => 'LATEST', 'modpack' => ['name' => 'Cobblemon', 'icon' => null, ...$pack]]);
    $version = ['loaders' => ['fabric'], 'game_versions' => ['1.21.1']];
    Http::fake([$address => Http::response(str_ends_with($address, '/version') ? [$version] : $version)]);

    expect(Mods::target($server))->toBe(['loader' => 'fabric', 'version' => '1.21.1']);
    // Asked once: the answer for this pack is remembered.
    Mods::target($server);
    Http::assertSentCount(1);
})->with([
    'a chosen version' => [['project' => 'cobblemon-fabric', 'version' => 'abc'], 'api.modrinth.com/v2/project/cobblemon-fabric/version/abc'],
    'the newest' => [['project' => 'cobblemon-fabric'], 'api.modrinth.com/v2/project/cobblemon-fabric/version'],
    'a pasted link' => [['project' => 'https://modrinth.com/modpack/cobblemon-fabric/version/1.6'], 'api.modrinth.com/v2/project/cobblemon-fabric/version/1.6'],
]);

test('a CurseForge pack is asked what it runs on', function () {
    Setting::put('curseforge_key', 'key');
    $server = modServer('AUTO_CURSEFORGE', ['modpack' => ['project' => 'all-the-mods-10', 'name' => 'ATM10', 'icon' => null]]);
    Http::fake(['api.curseforge.com/*' => Http::response(['data' => [['latestFilesIndexes' => [['gameVersion' => '1.21.1', 'modLoader' => 6]]]]])]);

    expect(Mods::target($server))->toBe(['loader' => 'neoforge', 'version' => '1.21.1']);
    Http::assertSent(fn (Request $request): bool => $request['slug'] === 'all-the-mods-10' && $request->header('x-api-key') === ['key']);
});

test('an FTB pack is asked what it runs on', function () {
    $server = modServer('FTBA', ['modpack' => ['project' => '126', 'name' => 'FTB', 'icon' => null, 'version' => '100']]);
    Http::fake(['api.feed-the-beast.com/v1/modpacks/public/modpack/126/100' => Http::response(['targets' => [
        ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.90'],
        ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
    ]])]);

    expect(Mods::target($server))->toBe(['loader' => 'neoforge', 'version' => '1.21.1']);
});

test('a pack that cannot be read has no target, and is asked again next time', function () {
    $server = modServer('MODRINTH', ['modpack' => ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon', 'icon' => null]]);
    Http::fake(['api.modrinth.com/*' => Http::response(status: 500)]);

    expect(Mods::target($server))->toBeNull()
        ->and(Mods::target($server))->toBeNull();
    Http::assertSentCount(2);
});

const FABRIC = ['loader' => 'fabric', 'version' => '1.21.10'];

// A Modrinth version as its API lists it.
function modVersion(string $project, string $file, array $dependencies = [], string $id = 'v-new'): array
{
    return [
        'id' => $id, 'project_id' => $project, 'version_number' => '1.0',
        'files' => [
            ['primary' => false, 'filename' => 'sources.jar', 'url' => 'https://cdn.modrinth.com/data/x/sources.jar', 'hashes' => ['sha512' => 'no']],
            ['primary' => true, 'filename' => $file, 'url' => "https://cdn.modrinth.com/data/{$project}/{$file}", 'hashes' => ['sha512' => "hash-{$file}"]],
        ],
        'dependencies' => array_map(fn (array $dependency): array => ['project_id' => $dependency[0], 'dependency_type' => $dependency[1]], $dependencies),
    ];
}

function nodeDownloads(): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/servers/survival/mods'))
        ->map(fn (array $pair): array => $pair[0]->data())->values()->all();
}

test('the catalogue is limited to mods that fit the server', function () {
    Http::fake(['api.modrinth.com/v2/search*' => Http::response(['total_hits' => 45, 'hits' => [
        ['project_id' => 'AANobbMI', 'title' => 'Sodium', 'description' => 'Fast', 'icon_url' => '', 'downloads' => 40],
    ]])]);

    expect(Mods::search(FABRIC, '', 20))->toBe([
        'mods' => [['project' => 'AANobbMI', 'name' => 'Sodium', 'description' => 'Fast', 'icon' => null, 'downloads' => 40]],
        'more' => true,
    ])->and(Mods::search(FABRIC, 'sod', 40)['more'])->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request['facets'] === '[["project_type:mod"],["categories:fabric"],["versions:1.21.10"],["server_side:required","server_side:optional"]]'
        && $request['limit'] == 20 && $request['offset'] == 20 && $request['index'] === 'downloads');
    Http::assertSent(fn (Request $request): bool => $request['query'] === 'sod' && $request['index'] === 'relevance');
});

test('installed jars are named by Modrinth, and an update is marked', function () {
    Http::fake([
        'node.test:8080/servers/survival/mods' => Http::response([
            ['name' => 'private.jar', 'size' => 5, 'sha512' => 'h-private'],
            ['name' => 'sodium.jar', 'size' => 9, 'sha512' => 'h-sodium'],
        ]),
        'api.modrinth.com/v2/version_files/update' => Http::response(['h-sodium' => ['id' => 'v2']]),
        'api.modrinth.com/v2/version_files' => Http::response(['h-sodium' => ['id' => 'v1', 'project_id' => 'AANobbMI', 'version_number' => '0.6.13']]),
        'api.modrinth.com/v2/projects*' => Http::response([['id' => 'AANobbMI', 'title' => 'Sodium', 'icon_url' => 'https://cdn.modrinth.com/icon.png']]),
    ]);

    expect(Mods::installed(modServer(), FABRIC))->toBe([
        ['file' => 'private.jar', 'size' => 5, 'project' => null, 'name' => null, 'icon' => null, 'version' => null, 'update' => false],
        ['file' => 'sodium.jar', 'size' => 9, 'project' => 'AANobbMI', 'name' => 'Sodium', 'icon' => 'https://cdn.modrinth.com/icon.png', 'version' => '0.6.13', 'update' => true],
    ]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/version_files/update')
        && $request['hashes'] === ['h-private', 'h-sodium'] && $request['loaders'] === ['fabric'] && $request['game_versions'] === ['1.21.10']);
});

test('installed jars are listed by file name when Modrinth is down', function () {
    Http::fake([
        'node.test:8080/servers/survival/mods' => Http::response([['name' => 'sodium.jar', 'size' => 9, 'sha512' => 'h-sodium']]),
        'api.modrinth.com/*' => Http::response(status: 500),
    ]);

    expect(Mods::installed(modServer(), FABRIC))->toBe([
        ['file' => 'sodium.jar', 'size' => 9, 'project' => null, 'name' => null, 'icon' => null, 'version' => null, 'update' => false],
    ]);
});

test('a mod is installed with the required dependencies that are missing', function () {
    Http::fake([
        'node.test:8080/servers/survival/mods' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response([['name' => 'have.jar', 'size' => 1, 'sha512' => 'h-have']])
            : Http::response(status: 204),
        'api.modrinth.com/v2/version_files/update' => Http::response([]),
        'api.modrinth.com/v2/version_files' => Http::response(['h-have' => ['id' => 'v', 'project_id' => 'HAVE', 'version_number' => '1']]),
        'api.modrinth.com/v2/project/sodium/version*' => Http::response([
            modVersion('SODIUM', 'sodium.jar', [['API', 'required'], ['OPTIONAL', 'optional'], ['HAVE', 'required']]),
            modVersion('SODIUM', 'older.jar'),
        ]),
        // Needs Sodium back: a circle must not be followed forever.
        'api.modrinth.com/v2/project/API/version*' => Http::response([modVersion('API', 'api.jar', [['SODIUM', 'required']])]),
        'api.modrinth.com/v2/projects*' => Http::response([['id' => 'API', 'title' => 'Fabric API'], ['id' => 'SODIUM', 'title' => 'Sodium'], ['id' => 'HAVE', 'title' => 'Have']]),
    ]);

    expect(Mods::install(modServer(), FABRIC, 'sodium'))->toBe(['installed' => ['Sodium', 'Fabric API'], 'missing' => []])
        ->and(nodeDownloads())->toBe([
            ['url' => 'https://cdn.modrinth.com/data/SODIUM/sodium.jar', 'name' => 'sodium.jar', 'sha512' => 'hash-sodium.jar'],
            ['url' => 'https://cdn.modrinth.com/data/API/api.jar', 'name' => 'api.jar', 'sha512' => 'hash-api.jar'],
        ]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/project/sodium/version')
        && $request['loaders'] === '["fabric"]' && $request['game_versions'] === '["1.21.10"]');
});

test('on a CurseForge pack the dependencies are named, not installed', function () {
    $server = modServer('AUTO_CURSEFORGE', ['modpack' => ['project' => 'atm', 'name' => 'ATM', 'icon' => null]]);
    Http::fake([
        'node.test:8080/servers/survival/mods' => fn (Request $request) => $request->method() === 'GET' ? Http::response([]) : Http::response(status: 204),
        'api.modrinth.com/v2/project/jei/version*' => Http::response([modVersion('JEI', 'jei.jar', [['ARCH', 'required']])]),
        'api.modrinth.com/v2/projects*' => Http::response([['id' => 'JEI', 'title' => 'JEI'], ['id' => 'ARCH', 'title' => 'Architectury']]),
    ]);

    expect(Mods::install($server, ['loader' => 'neoforge', 'version' => '1.21.1'], 'jei'))
        ->toBe(['installed' => ['JEI'], 'missing' => [['project' => 'ARCH', 'name' => 'Architectury']]])
        ->and(nodeDownloads())->toHaveCount(1);
});

test('a mod without a build for the server is refused before anything is downloaded', function () {
    Http::fake([
        'node.test:8080/servers/survival/mods' => Http::response([]),
        'api.modrinth.com/v2/project/sodium/version*' => Http::response([]),
    ]);

    expect(fn () => Mods::install(modServer(), FABRIC, 'sodium'))->toThrow(Illuminate\Validation\ValidationException::class)
        ->and(nodeDownloads())->toBe([]);
});

test('installing a mod that is already there replaces its jar', function () {
    Http::fake([
        'node.test:8080/servers/survival/mods' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response([['name' => 'sodium-old.jar', 'size' => 1, 'sha512' => 'h-old']])
            : Http::response(status: 204),
        'node.test:8080/servers/survival/files*' => Http::response(status: 204),
        'api.modrinth.com/v2/version_files/update' => Http::response(['h-old' => ['id' => 'v2']]),
        'api.modrinth.com/v2/version_files' => Http::response(['h-old' => ['id' => 'v1', 'project_id' => 'SODIUM', 'version_number' => '1']]),
        'api.modrinth.com/v2/project/SODIUM/version*' => Http::response([modVersion('SODIUM', 'sodium-new.jar')]),
        'api.modrinth.com/v2/projects*' => Http::response([['id' => 'SODIUM', 'title' => 'Sodium']]),
    ]);

    Mods::install(modServer(), FABRIC, 'SODIUM');

    expect(nodeDownloads())->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/servers/survival/files?path=mods%2Fsodium-old.jar'));
});
