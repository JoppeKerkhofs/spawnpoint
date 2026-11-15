<?php

use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\MemoryLimit;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Joppe']);
    $this->server = Server::factory()->create(['slug' => 'survival', 'name' => 'Survival']);
});

function settingsForm(array $overrides = []): array
{
    return ['base_domain' => 'mc.example.com', 'bluemap_url' => 'https://maps.example.com', ...$overrides];
}

test('panel settings override the environment and an emptied one falls back to it', function () {
    config(['panel.base_domain' => 'mc.localhost', 'panel.memory_limit_mb' => 4096]);

    $this->actingAs($this->admin)
        ->put(route('panel-settings.update'), settingsForm(['memory_limit_mb' => '8192']))
        ->assertSessionHasNoErrors();

    expect(Setting::value('base_domain'))->toBe('mc.example.com')
        ->and(MemoryLimit::mb())->toBe(8192);

    $this->put(route('panel-settings.update'), settingsForm(['memory_limit_mb' => '']));
    expect(MemoryLimit::mb())->toBe(4096);

    // Turning on required two-factor is saved; this admin has none, so the next page sends them to set it up.
    $this->put(route('panel-settings.update'), settingsForm(['require_two_factor' => '1']));
    expect((bool) Setting::value('require_two_factor'))->toBeTrue();
    $this->get(route('panel-settings.edit'))->assertRedirect(route('security.edit'));
});

test('the curseforge key is stored encrypted, kept when the field is empty, and never sent to the browser', function () {
    $this->actingAs($this->admin)->put(route('panel-settings.update'), settingsForm(['curseforge_key' => '$2a$10$secret']));

    expect(DB::table('settings')->where('key', 'curseforge_key')->value('value'))->not->toContain('secret')
        ->and(Setting::value('curseforge_key'))->toBe('$2a$10$secret');

    $this->put(route('panel-settings.update'), settingsForm());
    expect(Setting::value('curseforge_key'))->toBe('$2a$10$secret');

    $response = $this->get(route('panel-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('hasCurseforgeKey', true));
    expect($response->getContent())->not->toContain('secret');

    $this->put(route('panel-settings.update'), settingsForm(['remove_curseforge_key' => '1']));
    expect(Setting::value('curseforge_key'))->toBeEmpty();
});

test('panel settings reject a bad domain and are closed to viewers', function () {
    $this->actingAs($this->admin)
        ->put(route('panel-settings.update'), settingsForm(['base_domain' => 'not a domain/..']))
        ->assertSessionHasErrors('base_domain');

    $this->actingAs(User::factory()->create());
    $this->get(route('panel-settings.edit'))->assertForbidden();
    $this->put(route('panel-settings.update'), settingsForm())->assertForbidden();
    $this->get(route('activity.index'))->assertForbidden();
});

test('when two-factor is required, users without it are sent to set it up', function () {
    Setting::put('require_two_factor', '1');

    $this->actingAs($this->admin)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('profile.edit'))->assertOk();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get(route('dashboard'))->assertOk();
});

test('actions leave a trail with who, what and which server', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
    $this->actingAs($this->admin);

    $this->post(route('servers.power', [$this->server, 'stop']));
    $this->post(route('servers.backups.restore', [$this->server, '1794600000.tar.gz']));
    $this->put(route('servers.files.save', $this->server), ['path' => 'ops.json', 'content' => '[]']);

    expect(ActivityLog::query()->orderBy('id')->get()->map->only('actor', 'event', 'subject', 'properties')->all())->toBe([
        ['actor' => 'Joppe', 'event' => 'server.stop', 'subject' => 'survival', 'properties' => null],
        ['actor' => 'Joppe', 'event' => 'backup.restore', 'subject' => 'survival', 'properties' => ['backup' => '1794600000.tar.gz']],
        ['actor' => 'Joppe', 'event' => 'file.save', 'subject' => 'survival', 'properties' => ['path' => 'ops.json']],
    ]);

    $this->get(route('activity.index', ['server' => 'survival']))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 3)->where('entries.0.event', 'file.save'));
    $this->get(route('activity.index', ['server' => 'other']))
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('nothing is recorded when the node refuses', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 409)]);

    $this->actingAs($this->admin)->post(route('servers.power', [$this->server, 'stop']));

    expect(ActivityLog::count())->toBe(0);
});

test('an action through the api names the token that did it', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
    $token = $this->admin->createToken('Phone', ['servers:power'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/power/start', [], ['Authorization' => "Bearer {$token}"])->assertStatus(202);

    expect(ActivityLog::sole()->only('actor', 'event'))->toBe(['actor' => 'Joppe (API token: Phone)', 'event' => 'server.start']);
});

test('ops, whitelist, jvm flags and cpu limit are parsed, stored and sent to the node', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->actingAs($this->admin)->put(route('servers.update', $this->server), [
        'name' => 'Survival', 'server_type' => 'PAPER', 'mc_version' => 'LATEST', 'java_version' => '25', 'memory_mb' => '4096',
        'motd' => 'Hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20',
        'ops' => "JoppeK, Lien_B\nJoppeK", 'whitelist' => '1', 'whitelist_names' => 'MaartenVH', 'jvm_opts' => '-XX:+UseZGC', 'cpu_limit' => '2.5',
    ])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request['ops'] === ['JoppeK', 'Lien_B']
        && $request['whitelist'] === ['MaartenVH']
        && $request['whitelist_on'] === true
        && $request['jvm_opts'] === '-XX:+UseZGC'
        && $request['cpu_limit'] === 2.5);
});

test('player names and cpu limits are validated', function (array $overrides, string $field) {
    Http::fake();

    $this->actingAs($this->admin)->put(route('servers.update', $this->server), [
        'name' => 'Survival', 'server_type' => 'PAPER', 'mc_version' => 'LATEST', 'java_version' => '25', 'memory_mb' => '4096',
        'motd' => 'Hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20', ...$overrides,
    ])->assertSessionHasErrors($field);

    Http::assertNothingSent();
})->with([
    'op with a bad character' => [['ops' => 'Steve; rm -rf'], 'ops'],
    'whitelist name too long' => [['whitelist_names' => 'ANameThatIsFarTooLong'], 'whitelist_names'],
    'cpu limit too small' => [['cpu_limit' => '0.01'], 'cpu_limit'],
    'jvm flags with a line break' => [['jvm_opts' => "-Xmx1G\n-evil"], 'jvm_opts'],
]);

test('a server saved before these options existed still sends valid defaults', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
    $this->server->update(['settings' => ['motd' => 'Old', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => 20]]);
    Sanctum::actingAs($this->admin, ['servers:power']);

    (new NodeClient($this->server->node))->apply($this->server->refresh());

    Http::assertSent(fn (Request $request) => $request['ops'] === [] && $request['whitelist_on'] === false && $request['cpu_limit'] === null);
});

test('the api documentation covers every api route', function () {
    $documented = [];
    foreach (json_decode(file_get_contents(resource_path('api/openapi.json')), true)['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            $documented[] = strtoupper($method).' api/v1'.$path;
        }
    }

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
        ->sort()->values()->all();
    sort($documented);

    expect($documented)->toBe($routes);

    $this->actingAs(User::factory()->create())->get(route('api-docs'))->assertOk();
    $this->get(route('api-docs.spec'))->assertOk()->assertJsonPath('openapi', '3.1.0');
});
