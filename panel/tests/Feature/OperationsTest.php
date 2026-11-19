<?php

use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->server = Server::factory()->create(['slug' => 'survival', 'backup_keep' => 7]);
    $this->admin = User::factory()->admin()->create(['name' => 'Joppe']);
    $this->actingAs($this->admin);
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
        && $request['keep'] === 7
        && $request['by'] === $this->admin->name);
});

test('a backup that the node saves in its fallback folder is recorded and reported to webhooks', function () {
    Http::fake([
        'node.test:8080/*' => Http::response(['fallback' => true], 202),
        '93.184.216.34/*' => Http::response(status: 204),
    ]);
    $this->admin->webhooks()->create(['name' => 'Backups', 'url' => 'https://93.184.216.34/backups', 'format' => 'slack', 'events' => ['backups']]);

    $this->post(route('servers.backups.store', $this->server))->assertRedirect();

    expect(ActivityLog::query()->pluck('event')->all())->toBe(['backup.fallback', 'backup.create']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.34/backups'
        && str_contains($request['text'], 'is saved in the fallback folder'));
});

test('a backup in the backup folder itself records no fallback', function () {
    Http::fake(['node.test:8080/*' => Http::response(['fallback' => false], 202)]);

    $this->post(route('servers.backups.store', $this->server));

    expect(ActivityLog::query()->pluck('event')->all())->toBe(['backup.create']);
});

test('settings show the backup folder of each node, and say when a node does not answer', function () {
    Http::fake(['node.test:8080/host' => Http::response([
        'memory_mb' => 8192,
        'backup' => ['path' => '/mnt/nas/minecraft', 'reachable' => false, 'fallback' => '/srv/mc-backups'],
    ])]);
    $silent = Node::factory()->create(['name' => 'attic', 'base_url' => 'http://attic.test:8080']);
    Http::fake(['attic.test:8080/*' => Http::response(status: 500)]);

    $this->get(route('panel-settings.edit'))->assertInertia(fn (Assert $page) => $page
        ->missing('backupFolders')
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('backupFolders.0.folder.path', '/mnt/nas/minecraft')
            ->where('backupFolders.0.folder.reachable', false)
            ->where('backupFolders.0.folder.fallback', '/srv/mc-backups')
            ->where('backupFolders.1', ['node' => $silent->name, 'folder' => null])));
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

test('a running backup can be cancelled and the record of a failed one deleted', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->delete(route('servers.backups.cancel', $this->server))->assertRedirect();
    $this->delete(route('servers.backups.destroy', [$this->server, '1794600000.failed']))->assertRedirect();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/backups/running'));
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/backups/1794600000.failed'));
    // A failure record cannot be restored or downloaded.
    $this->post('/servers/survival/backups/1794600000.failed/restore')->assertNotFound();
    $this->get('/servers/survival/backups/1794600000.failed/download')->assertNotFound();
});

test('a server made for a world zip is created without starting and lands on backups', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->post(route('servers.store'), [
        'name' => 'Zipped', 'slug' => 'zipped', 'server_type' => 'PAPER', 'mc_version' => 'LATEST', 'java_version' => '25',
        'memory_mb' => '2048', 'motd' => 'Hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20', 'eula' => '1', 'world_zip' => '1',
    ])->assertRedirect(route('servers.backups', 'zipped'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['start'] === false);

    $this->post(route('servers.import', 'zipped'), ['source' => 'zip', 'path' => 'world-import.zip', 'start' => '1']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/zipped/import') && $request['start'] === true);
});

test('a backup name that the node did not write is not routed', function (string $name) {
    Http::fake();

    $this->post("/servers/survival/backups/{$name}/restore")->assertNotFound();
    $this->delete("/servers/survival/backups/{$name}")->assertNotFound();

    Http::assertNothingSent();
})->with(['..%2F..%2Fetc%2Fpasswd', 'world.tar.gz', '1794600000.zip', '1794600000.tar.gz.sh']);

test('only the schedules due this minute and weekday run, each with its own action', function () {
    $this->travelTo(now()->next('Monday')->setTime(4, 0, 30));
    $due = fn (array $attributes) => $this->server->schedules()->create(['time' => '04:00', ...$attributes]);
    $due(['action' => 'backup']);
    $due(['action' => 'command', 'command' => 'say Backup starting']);
    $due(['action' => 'restart', 'weekday' => 1]);
    $due(['action' => 'stop', 'weekday' => 2]);
    $due(['action' => 'start', 'is_active' => false]);
    $due(['action' => 'backup', 'time' => '05:00']);
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->artisan('schedules:run')->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/survival/backups') && $request['keep'] === 7 && $request['by'] === 'Schedule');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/survival/command') && $request['command'] === 'say Backup starting');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/survival/restart'));
    expect(ActivityLog::query()->where('event', 'schedule.run')->pluck('actor')->unique()->all())->toBe(['System']);
});

test('schedules are validated, added, paused and removed', function () {
    $this->post(route('servers.schedules.store', $this->server), ['action' => 'format', 'time' => '25:99', 'weekday' => 9])
        ->assertSessionHasErrors(['action', 'time', 'weekday']);
    $this->post(route('servers.schedules.store', $this->server), ['action' => 'command', 'time' => '03:55'])
        ->assertSessionHasErrors('command');
    $this->post(route('servers.schedules.store', $this->server), ['action' => 'command', 'time' => '03:55', 'command' => '--help'])
        ->assertSessionHasErrors('command');

    $this->post(route('servers.schedules.store', $this->server), ['action' => 'command', 'time' => '03:55', 'command' => '/say Backup in 5 minutes'])
        ->assertSessionHasNoErrors();
    $schedule = $this->server->schedules()->sole();
    expect($schedule->only('action', 'time', 'weekday', 'command', 'is_active'))
        ->toBe(['action' => 'command', 'time' => '03:55', 'weekday' => null, 'command' => 'say Backup in 5 minutes', 'is_active' => true]);

    $this->put(route('servers.schedules.update', [$this->server, $schedule]), ['is_active' => false]);
    expect($schedule->refresh()->is_active)->toBeFalse();

    // A schedule cannot be reached through another server's address.
    $other = Server::factory()->for($this->server->node)->create();
    $this->delete(route('servers.schedules.destroy', [$other, $schedule]))->assertNotFound();
    $this->delete(route('servers.schedules.destroy', [$this->server, $schedule]));
    expect($this->server->schedules()->count())->toBe(0);
});

test('retention is validated and saved', function () {
    $this->put(route('servers.backups.retention', $this->server), ['backup_keep' => 0])->assertSessionHasErrors('backup_keep');
    $this->put(route('servers.backups.retention', $this->server), ['backup_keep' => 10])->assertSessionHasNoErrors();

    expect($this->server->refresh()->backup_keep)->toBe(10);
});

test('a new server can start from a seed or from a world in the import folder', function () {
    Http::fake(['node.test:8080/imports' => Http::response(['old/world']), 'node.test:8080/*' => Http::response(status: 202)]);
    $form = [
        'name' => 'Imported', 'slug' => 'imported', 'server_type' => 'PAPER', 'mc_version' => 'LATEST', 'java_version' => '25',
        'memory_mb' => '2048', 'motd' => 'Hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20', 'eula' => '1',
    ];

    $this->get(route('servers.create'))->assertInertia(fn (Assert $page) => $page->where('imports', ['old/world']));
    $this->post(route('servers.store'), [...$form, 'import_path' => '../../etc'])->assertSessionHasErrors('import_path');

    $this->post(route('servers.store'), [...$form, 'seed' => '12345', 'import_path' => 'old/world'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['seed'] === '12345' && $request['import_host'] === 'old/world');

    // A later settings change keeps the seed and never imports again.
    $created = Server::firstWhere('slug', 'imported');
    $this->put(route('servers.update', $created), [...$form, 'motd' => 'Changed'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['motd'] === 'Changed' && $request['seed'] === '12345' && $request['import_host'] === null);
});

test('a motd keeps its colors, symbols and the written line break', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
    $motd = '§6§lSpawnpoint §r★\n§aꜱᴜʀᴠɪᴠᴀʟ';

    $this->post(route('servers.store'), [
        'name' => 'Styled', 'slug' => 'styled', 'server_type' => 'PAPER', 'mc_version' => 'LATEST', 'java_version' => '25',
        'memory_mb' => '2048', 'motd' => $motd, 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20', 'eula' => '1',
    ])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request['motd'] === $motd);
});

test('a world import is passed to the node and paths cannot step out', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);

    $this->post(route('servers.import', $this->server), ['source' => 'host', 'path' => '../../etc'])->assertSessionHasErrors('path');
    $this->post(route('servers.import', $this->server), ['source' => 'ftp', 'path' => 'world.zip'])->assertSessionHasErrors('source');
    Http::assertNothingSent();

    $this->post(route('servers.import', $this->server), ['source' => 'zip', 'path' => 'uploads/world.zip'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/survival/import')
        && $request->data() === ['source' => 'zip', 'path' => 'uploads/world.zip', 'start' => false]);
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
