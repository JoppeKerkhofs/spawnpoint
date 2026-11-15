<?php

use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->node = Node::factory()->create(['base_url' => 'http://node.test:8080']);
    $this->server = Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $this->admin = User::factory()->admin()->create();
    Http::fake([
        'node.test:8080/servers/survival/backups' => Http::response([
            ['name' => '1794600000.tar.gz', 'size' => 10, 'created' => 1794600000, 'locked' => false, 'status' => 'done', 'by' => null, 'reason' => null],
        ]),
        'node.test:8080/*' => Http::response(status: 202),
    ]);
});

test('each backup action needs its own ability', function () {
    $read = $this->admin->createToken('Read', ['backups:read'])->plainTextToken;
    $restore = $this->admin->createToken('Restore', ['backups:restore'])->plainTextToken;

    $this->getJson('/api/v1/servers/survival/backups', bearer($read))
        ->assertOk()->assertJsonPath('data.0.name', '1794600000.tar.gz');

    $this->postJson('/api/v1/servers/survival/backups/1794600000.tar.gz/restore', [], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the backups:restore ability.');

    $this->postJson('/api/v1/servers/survival/backups/1794600000.tar.gz/restore', [], bearer($restore))
        ->assertStatus(202);

    $this->deleteJson('/api/v1/servers/survival/backups/1794600000.tar.gz', [], bearer($restore))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the backups:delete ability.');
});

test('retention and cancelling belong to making backups', function () {
    $token = $this->admin->createToken('Maker', ['backups:create'])->plainTextToken;

    $this->putJson('/api/v1/servers/survival/backups/retention', ['backup_keep' => '5', 'backup_retention_days' => '30'], bearer($token))
        ->assertOk();
    expect($this->server->refresh()->backup_keep)->toBe(5);

    $this->deleteJson('/api/v1/servers/survival/backups/running', [], bearer($token))->assertStatus(202);

    // The same events the web pages record.
    expect(ActivityLog::query()->pluck('event')->all())->toBe(['backup.retention', 'backup.cancel']);
});

test('the record of a failed backup can be deleted too, and the import folder can be listed', function () {
    $delete = $this->admin->createToken('Delete', ['backups:delete'])->plainTextToken;
    $this->deleteJson('/api/v1/servers/survival/backups/1794600000.failed', [], bearer($delete))->assertOk();

    Http::swap(new Factory);
    Http::fake(['node.test:8080/imports' => Http::response(['worlds/old'])]);
    $restore = $this->admin->createToken('Restore', ['backups:restore'])->plainTextToken;
    $this->getJson('/api/v1/servers/survival/import', bearer($restore))->assertOk()->assertJsonPath('data', ['worlds/old']);
});

test('a download gives back an address with a short-lived token', function () {
    $token = $this->admin->createToken('Read', ['backups:read'])->plainTextToken;

    $this->getJson('/api/v1/servers/survival/backups/1794600000.tar.gz/download', bearer($token))
        ->assertOk()
        ->assertJsonPath('url', fn (string $url): bool => str_contains($url, '/servers/survival/backups/1794600000.tar.gz/download?token='));
});

test('importing a world needs the restore ability and reaches the node', function () {
    $token = $this->admin->createToken('Restore', ['backups:restore'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/import', ['source' => 'zip', 'path' => 'world.zip'], bearer($token))
        ->assertStatus(202);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://node.test:8080/servers/survival/import'
        && $request['source'] === 'zip' && $request['path'] === 'world.zip');
});

test('a backup name that the node never writes is refused by the route', function () {
    $token = $this->admin->createToken('Delete', ['backups:delete'])->plainTextToken;

    $this->deleteJson('/api/v1/servers/survival/backups/..%2F..%2Fetc%2Fpasswd', [], bearer($token))
        ->assertStatus(404);
});
