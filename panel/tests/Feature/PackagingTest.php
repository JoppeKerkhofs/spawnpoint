<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    putenv('SPAWNPOINT_TOKEN');
    putenv('PANEL_ADMIN_EMAIL');
    putenv('PANEL_ADMIN_PASSWORD');
});

test('every page carries the version from composer.json', function () {
    expect(config('app.version'))->toMatch('/^\d+\.\d+\.\d+$/');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('appVersion', config('app.version')));
});

test('setup registers the node and creates the first admin once', function () {
    putenv('SPAWNPOINT_TOKEN='.str_repeat('a', 40));
    putenv('PANEL_ADMIN_EMAIL=admin@example.com');
    putenv('PANEL_ADMIN_PASSWORD=first-password');

    $this->artisan('panel:setup')->assertSuccessful();

    $admin = User::sole();
    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('first-password', $admin->password))->toBeTrue()
        ->and(Node::sole()->token)->toBe(str_repeat('a', 40));

    // A later start with a rotated token and other credentials changes only the token.
    putenv('SPAWNPOINT_TOKEN='.str_repeat('b', 40));
    putenv('PANEL_ADMIN_EMAIL=other@example.com');
    $this->artisan('panel:setup')->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(Node::sole()->token)->toBe(str_repeat('b', 40));
});

test('setup refuses a short token and needs no admin credentials', function () {
    putenv('SPAWNPOINT_TOKEN=short');
    $this->artisan('panel:setup')->assertFailed();
    expect(Node::count())->toBe(0);

    putenv('SPAWNPOINT_TOKEN='.str_repeat('a', 40));
    $this->artisan('panel:setup')->assertSuccessful();
    expect(User::count())->toBe(0)->and(Node::count())->toBe(1);
});

test('browser addresses go through the panel when a public node path is set', function () {
    config(['panel.node_public_url' => '/node', 'app.url' => 'https://panel.example.com']);
    URL::forceRootUrl('https://panel.example.com');
    URL::forceScheme('https');
    $server = Server::factory()->create(['slug' => 'survival']);
    $client = new NodeClient($server->node);

    expect($client->consoleUrl($server, true))->toStartWith("wss://panel.example.com/node/{$server->node_id}/servers/survival/console?token=")
        ->and($client->fileUrl($server, 'upload'))->toStartWith("https://panel.example.com/node/{$server->node_id}/servers/survival/upload?token=")
        ->and($client->backupUrl($server, '1.tar.gz'))->toStartWith("https://panel.example.com/node/{$server->node_id}/servers/survival/backups/1.tar.gz/download?token=");
});

test('only a trusted proxy is believed about https and the visitor\'s address', function () {
    Route::get('whoami', fn () => [request()->ip(), request()->isSecure()]);
    $forwarded = ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'];

    config(['trustedproxy.proxies' => null]);
    $this->get('whoami', $forwarded)->assertExactJson(['127.0.0.1', false]);

    config(['trustedproxy.proxies' => '127.0.0.0/8,10.0.0.0/8']);
    $this->get('whoami', $forwarded)->assertExactJson(['203.0.113.9', true]);
});

test('the theme comes from the environment and falls back to the default one', function () {
    config(['panel.theme' => 'default']);
    $this->get(route('login'))->assertSee('data-theme="default"', false)->assertDontSee('fonts.googleapis.com');

    config(['panel.theme' => 'blocky']);
    $this->get(route('login'))
        ->assertSee('data-theme="blocky"', false)
        ->assertSee('fonts.googleapis.com')
        ->assertInertia(fn (Assert $page) => $page->where('theme', 'blocky'));
});

test('a user sees the panel in the language they chose, from a file in the lang folder', function () {
    $folder = sys_get_temp_dir().'/spawnpoint-lang-'.uniqid();
    mkdir($folder);
    file_put_contents("{$folder}/nl.json", json_encode(['@language' => 'Nederlands', 'Servers' => 'Servers (nl)', 'Profile updated.' => 'Profiel bijgewerkt.']));
    app()->useLangPath($folder);
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('appearance.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'en')
        ->where('languages', ['en' => 'English', 'nl' => 'Nederlands'])
        ->where('translations', []));

    $this->put(route('locale.update'), ['locale' => 'de'])->assertSessionHasErrors('locale');
    $this->put(route('locale.update'), ['locale' => 'nl'])->assertSessionHasNoErrors();

    $this->get(route('appearance.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'nl')
        ->where('translations.Servers', 'Servers (nl)'));
    expect(__('Profile updated.'))->toBe('Profiel bijgewerkt.');

    unlink("{$folder}/nl.json");
    rmdir($folder);
});

test('lang:sync writes every text of the panel to a language file and keeps what was translated', function () {
    $folder = sys_get_temp_dir().'/spawnpoint-lang-'.uniqid();
    mkdir($folder);
    file_put_contents("{$folder}/nl.json", json_encode(['@language' => 'Nederlands', 'Cancel' => 'Annuleren']));
    app()->useLangPath($folder);

    $this->artisan('lang:sync', ['locale' => 'nl'])->assertSuccessful();
    $this->artisan('lang:sync', ['locale' => 'en'])->assertFailed();

    $written = json_decode(file_get_contents("{$folder}/nl.json"), true);
    expect($written['@language'])->toBe('Nederlands')
        ->and($written['Cancel'])->toBe('Annuleren')
        ->and($written['Profile updated.'])->toBe('Profile updated.')
        ->and($written)->toHaveKey('Language')
        // From a page's options, a text that reaches t() through a variable, and one passed to a dialog.
        ->and($written)->toHaveKeys(['Confirm password', 'running', 'Delete :name? This cannot be undone.'])
        // What a webhook says, and a text of the API documentation.
        ->and($written)->toHaveKeys([':actor started :server.', 'List the servers with their live state']);

    // Laravel's own messages for rejected forms, copied in to be translated.
    expect(require "{$folder}/nl/validation.php")->toHaveKey('accepted');

    unlink("{$folder}/nl/validation.php");
    rmdir("{$folder}/nl");
    unlink("{$folder}/nl.json");
    rmdir($folder);
});

test('a webhook speaks the language of the panel, not of the user who set it off', function () {
    $folder = sys_get_temp_dir().'/spawnpoint-lang-'.uniqid();
    mkdir($folder);
    file_put_contents("{$folder}/nl.json", json_encode(['@language' => 'Nederlands', ':actor stopped :server.' => ':actor heeft :server gestopt.']));
    app()->useLangPath($folder);
    config(['app.locale' => 'nl']);
    Http::fake(['node.test:8080/*' => Http::response(status: 202), '93.184.216.34/*' => Http::response(status: 204)]);

    $admin = User::factory()->admin()->create(['name' => 'Joppe']);
    $server = Server::factory()->create(['slug' => 'survival', 'name' => 'Survival']);
    $admin->webhooks()->create(['name' => 'Discord', 'url' => 'https://93.184.216.34/discord', 'format' => 'slack', 'events' => ['power']]);

    $this->actingAs($admin)->post(route('servers.power', [$server, 'stop']));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/discord'
        && $request->data()['text'] === 'Joppe heeft Survival gestopt.');

    unlink("{$folder}/nl.json");
    rmdir($folder);
});
