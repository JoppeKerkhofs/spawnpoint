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

    expect(Server::sole()->modpack)->toBe(['project' => 'cobblemon-fabric', 'name' => 'Cobblemon Official', 'icon' => 'https://cdn.modrinth.com/data/x/icon.png']);
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
