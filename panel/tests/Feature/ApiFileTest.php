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
    Server::factory()->for($this->node)->create(['slug' => 'survival']);
    $this->admin = User::factory()->admin()->create();
    Http::fake(['node.test:8080/*' => Http::response(status: 204)]);
});

test('making a folder and renaming need files:write', function () {
    $read = $this->admin->createToken('Read', ['files:read'])->plainTextToken;
    $write = $this->admin->createToken('Write', ['files:write'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/files/folder', ['path' => 'mods/new'], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the files:write ability.');

    $this->postJson('/api/v1/servers/survival/files/folder', ['path' => 'mods/new'], bearer($write))
        ->assertStatus(201);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://node.test:8080/servers/survival/files/mkdir' && $request['path'] === 'mods/new');

    $this->postJson('/api/v1/servers/survival/files/rename', ['from' => 'a.txt', 'to' => 'b.txt'], bearer($write))
        ->assertOk();

    // The same events the web pages record.
    expect(ActivityLog::query()->pluck('event')->all())->toBe(['file.folder', 'file.rename']);
});

test('a path that tries to leave the server folder is refused', function () {
    $write = $this->admin->createToken('Write', ['files:write'])->plainTextToken;

    $this->postJson('/api/v1/servers/survival/files/folder', ['path' => '../escape'], bearer($write))
        ->assertStatus(422);
});

test('a whole log file comes back as plain text with logs:read', function () {
    Http::swap(new Factory);
    Http::fake(['node.test:8080/servers/survival/logs/latest.log' => Http::response("one\ntwo\n")]);
    $token = $this->admin->createToken('Logs', ['logs:read'])->plainTextToken;

    $response = $this->get('/api/v1/servers/survival/logs/download?file=latest.log', bearer($token))
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');
    // Streamed, so the body is only there once it is asked for.
    expect($response->streamedContent())->toBe("one\ntwo\n");

    $without = $this->admin->createToken('Files', ['files:read'])->plainTextToken;
    $this->getJson('/api/v1/servers/survival/logs/download?file=latest.log', bearer($without))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the logs:read ability.');
});
