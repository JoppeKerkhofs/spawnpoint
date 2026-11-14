<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    putenv('MCPANEL_TOKEN');
    putenv('PANEL_ADMIN_EMAIL');
    putenv('PANEL_ADMIN_PASSWORD');
});

test('every page carries the version from composer.json', function () {
    expect(config('app.version'))->toMatch('/^\d+\.\d+\.\d+$/');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('appVersion', config('app.version')));
});

test('setup registers the node and creates the first admin once', function () {
    putenv('MCPANEL_TOKEN='.str_repeat('a', 40));
    putenv('PANEL_ADMIN_EMAIL=admin@example.com');
    putenv('PANEL_ADMIN_PASSWORD=first-password');

    $this->artisan('panel:setup')->assertSuccessful();

    $admin = User::sole();
    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('first-password', $admin->password))->toBeTrue()
        ->and(Node::sole()->token)->toBe(str_repeat('a', 40));

    // A later start with a rotated token and other credentials changes only the token.
    putenv('MCPANEL_TOKEN='.str_repeat('b', 40));
    putenv('PANEL_ADMIN_EMAIL=other@example.com');
    $this->artisan('panel:setup')->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(Node::sole()->token)->toBe(str_repeat('b', 40));
});

test('setup refuses a short token and needs no admin credentials', function () {
    putenv('MCPANEL_TOKEN=short');
    $this->artisan('panel:setup')->assertFailed();
    expect(Node::count())->toBe(0);

    putenv('MCPANEL_TOKEN='.str_repeat('a', 40));
    $this->artisan('panel:setup')->assertSuccessful();
    expect(User::count())->toBe(0)->and(Node::count())->toBe(1);
});

test('browser addresses go through the panel when a public node path is set', function () {
    config(['panel.node_public_url' => '/node', 'app.url' => 'https://panel.example.com']);
    URL::forceRootUrl('https://panel.example.com');
    URL::forceScheme('https');
    $server = Server::factory()->create(['slug' => 'survival']);
    $client = new NodeClient($server->node);

    expect($client->consoleUrl($server, true))->toStartWith('wss://panel.example.com/node/servers/survival/console?token=')
        ->and($client->fileUrl($server, 'upload'))->toStartWith('https://panel.example.com/node/servers/survival/upload?token=')
        ->and($client->backupUrl($server, '1.tar.gz'))->toStartWith('https://panel.example.com/node/servers/survival/backups/1.tar.gz/download?token=');
});
