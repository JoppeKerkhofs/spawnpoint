<?php

use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->server = Server::factory()->create(['slug' => 'survival', 'backup_keep' => 7]);
    $this->actingAs(User::factory()->admin()->create());
});

test('the backups page lists backups and importable worlds from the node', function () {
    Http::fake([
        'node.test:8080/servers/survival/backups' => Http::response([
            ['name' => '1794600000.tar.gz', 'size' => 1024, 'created' => 1794600000, 'locked' => false],
        ]),
        'node.test:8080/imports' => Http::response(['old-server/world']),
    ]);

    $this->get(route('servers.backups', $this->server))
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/Backups')
            ->where('backups.0.name', '1794600000.tar.gz')
            ->where('imports', ['old-server/world'])
            ->where('error', null),
        );
});

test('a manual backup passes the retention count to the node', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->post(route('servers.backups.store', $this->server))->assertRedirect();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/servers/survival/backups')
        && $request['keep'] === 7);
});

test('restore, lock, delete and download address the backup by its name', function () {
    $this->freezeTime();
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
    $name = '1794600000.tar.gz';

    $this->post(route('servers.backups.restore', [$this->server, $name]));
    $this->post(route('servers.backups.lock', [$this->server, $name]), ['locked' => true]);
    $this->delete(route('servers.backups.destroy', [$this->server, $name]));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/backups/{$name}/restore"));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/backups/{$name}/lock") && $request['locked'] === true);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), "/backups/{$name}"));

    $this->get(route('servers.backups.download', [$this->server, $name]))
        ->assertRedirect("http://node.test:8080/servers/survival/backups/{$name}/download?token="
            .NodeClient::token('files', $this->server->node->token, 'survival', now()->getTimestamp() + 300, false));
});

test('a backup name that the node did not write is not routed', function (string $name) {
    Http::fake();

    $this->post("/servers/survival/backups/{$name}/restore")->assertNotFound();
    $this->delete("/servers/survival/backups/{$name}")->assertNotFound();

    Http::assertNothingSent();
})->with(['..%2F..%2Fetc%2Fpasswd', 'world.tar.gz', '1794600000.zip', '1794600000.tar.gz.sh']);

test('the daily backup starts only for servers whose time is this minute', function () {
    $this->travelTo(now()->setTime(4, 0, 30));
    $this->server->update(['backup_at' => '04:00']);
    Server::factory()->for($this->server->node)->create(['slug' => 'later', 'backup_at' => '05:00']);
    Server::factory()->for($this->server->node)->create(['slug' => 'never', 'backup_at' => null]);
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->artisan('backups:run')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/survival/backups'));
});

test('the backup schedule is validated and saved', function () {
    $this->put(route('servers.backups.schedule', $this->server), ['backup_at' => '25:99', 'backup_keep' => 0])
        ->assertSessionHasErrors(['backup_at', 'backup_keep']);

    $this->put(route('servers.backups.schedule', $this->server), ['backup_at' => '04:30', 'backup_keep' => 10])
        ->assertSessionHasNoErrors();

    expect($this->server->refresh()->only('backup_at', 'backup_keep'))->toBe(['backup_at' => '04:30', 'backup_keep' => 10]);
});

test('a world import is passed to the node and paths cannot step out', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->post(route('servers.import', $this->server), ['source' => 'host', 'path' => '../../etc'])->assertSessionHasErrors('path');
    $this->post(route('servers.import', $this->server), ['source' => 'ftp', 'path' => 'world.zip'])->assertSessionHasErrors('source');
    Http::assertNothingSent();

    $this->post(route('servers.import', $this->server), ['source' => 'zip', 'path' => 'uploads/world.zip'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/survival/import')
        && $request->data() === ['source' => 'zip', 'path' => 'uploads/world.zip']);
});

test('turning bluemap on is passed to the node and shown on the console page', function () {
    config(['panel.bluemap_url' => 'https://bluemap.example.com/']);
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->put(route('servers.update', $this->server), [
        'name' => 'Survival', 'server_type' => 'PAPER', 'mc_version' => 'LATEST', 'java_version' => '25', 'memory_mb' => '4096',
        'motd' => 'Hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20', 'bluemap' => '1',
    ])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request['bluemap'] === true);
    $this->get(route('servers.show', $this->server))
        ->assertInertia(fn (Assert $page) => $page->where('bluemapUrl', 'https://bluemap.example.com/survival/'));
});
