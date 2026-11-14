<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function packForm(array $overrides = []): array
{
    return [
        'name' => 'Cobblemon',
        'slug' => 'cobblemon',
        'server_type' => 'MODRINTH',
        'mc_version' => 'LATEST',
        'java_version' => '21',
        'memory_mb' => '6144',
        'motd' => 'Hello',
        'difficulty' => 'normal',
        'mode' => 'survival',
        'max_players' => '20',
        'eula' => '1',
        'modpack_project' => 'cobblemon-fabric',
        'modpack_name' => 'Cobblemon Official',
        'modpack_icon' => 'https://cdn.modrinth.com/data/x/icon.png',
        ...$overrides,
    ];
}

test('modrinth search returns packs in the shape the picker shows', function () {
    Http::fake(['api.modrinth.com/*' => Http::response(['hits' => [
        ['slug' => 'cobblemon-fabric', 'title' => 'Cobblemon Official', 'description' => 'Pokemon', 'icon_url' => '', 'downloads' => 5000],
    ]])]);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('modpacks.search', ['platform' => 'MODRINTH', 'q' => 'cobble']))
        ->assertExactJson(['packs' => [
            ['project' => 'cobblemon-fabric', 'name' => 'Cobblemon Official', 'description' => 'Pokemon', 'icon' => null, 'downloads' => 5000],
        ]]);

    Http::assertSent(fn (Request $request) => $request['query'] === 'cobble' && str_contains($request['facets'], 'project_type:modpack'));
});

test('curseforge search sends the key and never returns it', function () {
    config(['panel.curseforge_key' => 'secret-key']);
    Http::fake(['api.curseforge.com/*' => Http::response(['data' => [
        ['slug' => 'all-the-mods-10', 'name' => 'All the Mods 10', 'summary' => 'Kitchen sink', 'logo' => ['thumbnailUrl' => 'https://media.forgecdn.net/a.png'], 'downloadCount' => 9],
    ]])]);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('modpacks.search', ['platform' => 'AUTO_CURSEFORGE', 'q' => 'atm']))
        ->assertJsonPath('packs.0.project', 'all-the-mods-10');

    expect($response->getContent())->not->toContain('secret-key');
    Http::assertSent(fn (Request $request) => $request->header('x-api-key') === ['secret-key']);
});

test('curseforge cannot be searched or installed without a key', function () {
    Node::factory()->create();
    Http::fake();
    $this->actingAs(User::factory()->admin()->create());

    $this->getJson(route('modpacks.search', ['platform' => 'AUTO_CURSEFORGE']))->assertStatus(422);
    $this->post(route('servers.store'), packForm(['server_type' => 'AUTO_CURSEFORGE', 'modpack_project' => 'all-the-mods-10']))
        ->assertSessionHasErrors('modpack_project');

    Http::assertNothingSent();
});

test('ftb search looks up each hit, and a pack is installed by its number and chosen version', function () {
    Node::factory()->create();
    Http::fake([
        'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response(['packs' => [100]]),
        'api.feed-the-beast.com/v1/modpacks/public/modpack/100' => Http::response([
            'status' => 'success', 'id' => 100, 'name' => 'FTB StoneBlock 3', 'synopsis' => 'Stone', 'installs' => 7,
            'art' => [['type' => 'splash', 'url' => 'https://cdn.example/s.png'], ['type' => 'square', 'url' => 'https://cdn.example/q.png']],
            'versions' => [['id' => 1, 'name' => '1.0.0', 'type' => 'release'], ['id' => 2, 'name' => '1.1.0', 'type' => 'beta']],
        ]),
        'node.test:8080/servers/cobblemon' => Http::response(status: 202),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $this->getJson(route('modpacks.search', ['platform' => 'FTBA', 'q' => 'stoneblock']))
        ->assertExactJson(['packs' => [['project' => '100', 'name' => 'FTB StoneBlock 3', 'description' => 'Stone', 'icon' => 'https://cdn.example/q.png', 'downloads' => 7]]]);
    $this->getJson(route('modpacks.versions', ['platform' => 'FTBA', 'project' => '100']))
        ->assertExactJson(['versions' => [['id' => '2', 'name' => '1.1.0 (beta)'], ['id' => '1', 'name' => '1.0.0 (release)']]]);
    $this->getJson(route('modpacks.versions', ['platform' => 'FTBA', 'project' => '../../secret']))->assertStatus(422);

    $this->post(route('servers.store'), packForm(['server_type' => 'FTBA', 'modpack_project' => 'stoneblock']))->assertSessionHasErrors('modpack_project');
    $this->post(route('servers.store'), packForm(['server_type' => 'FTBA', 'modpack_project' => '100', 'modpack_version' => '2']))->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['server_type'] === 'FTBA' && $request['modpack'] === '100' && $request['modpack_version'] === '2');
});

test('a chosen modrinth version is sent as a link to that version', function () {
    Node::factory()->create();
    Http::fake(['node.test:8080/servers/cobblemon' => Http::response(status: 202)]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('servers.store'), packForm(['modpack_version' => 'OWMBuM7X', 'modpack_version_name' => '26.5.1']))
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request['modpack'] === 'https://modrinth.com/modpack/cobblemon-fabric/version/OWMBuM7X' && $request['modpack_version'] === null);
});

test('viewers cannot search modpacks', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('modpacks.search', ['platform' => 'MODRINTH']))
        ->assertForbidden();
});

test('a modrinth pack is stored and passed to the node', function () {
    Node::factory()->create();
    Http::fake(['node.test:8080/servers/cobblemon' => Http::response(status: 202)]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('servers.store'), packForm())
        ->assertRedirect(route('servers.show', 'cobblemon'));

    expect(Server::sole()->modpack)->toBe(['project' => 'cobblemon-fabric', 'name' => 'Cobblemon Official', 'icon' => 'https://cdn.modrinth.com/data/x/icon.png', 'version' => null, 'version_name' => null]);
    Http::assertSent(fn (Request $request) => $request['server_type'] === 'MODRINTH'
        && $request['modpack'] === 'cobblemon-fabric'
        && $request['cf_api_key'] === null);
});

test('a curseforge pack sends the key to the node only', function () {
    config(['panel.curseforge_key' => 'secret-key']);
    Node::factory()->create();
    Http::fake(['node.test:8080/servers/cobblemon' => Http::response(status: 202)]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('servers.store'), packForm(['server_type' => 'AUTO_CURSEFORGE', 'modpack_project' => 'all-the-mods-10']));

    Http::assertSent(fn (Request $request) => $request['modpack'] === 'all-the-mods-10' && $request['cf_api_key'] === 'secret-key');
});

test('a pack must be a slug or a modrinth link', function (array $overrides) {
    Node::factory()->create();
    Http::fake();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('servers.store'), packForm($overrides))
        ->assertSessionHasErrors('modpack_project');

    Http::assertNothingSent();
})->with([
    'missing' => [['modpack_project' => '']],
    'another site' => [['modpack_project' => 'https://evil.example/pack.mrpack']],
    'extra text' => [['modpack_project' => 'pack -e X=1']],
]);

test('a plain loader server drops any pack fields', function () {
    Node::factory()->create();
    Http::fake(['node.test:8080/servers/cobblemon' => Http::response(status: 202)]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('servers.store'), packForm(['server_type' => 'FABRIC']));

    expect(Server::sole()->modpack)->toBeNull();
    Http::assertSent(fn (Request $request) => $request['server_type'] === 'FABRIC' && $request['modpack'] === null);
});
