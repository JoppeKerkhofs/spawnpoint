<?php

use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// A real PNG of one colour, built by hand so the tests need no image extension.
function png(int $width, int $height): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\x40\x80\xC0", $width), $height);

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($rows)).$chunk('IEND', '');
}

function dataUrl(string $bytes): string
{
    return 'data:image/png;base64,'.base64_encode($bytes);
}

beforeEach(function () {
    $this->server = Server::factory()->create(['slug' => 'survival']);
    $this->admin = User::factory()->admin()->create();
    Http::fake(['node.test:8080/*' => Http::response(status: 204)]);
});

test('an uploaded picture is kept with the server and written where Minecraft reads it', function () {
    $picture = png(64, 64);

    $this->actingAs($this->admin)->put(route('servers.icon.update', $this->server), ['icon' => dataUrl($picture)])
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/servers/survival/files/content?path=server-icon.png')
        && $request->body() === $picture);
    expect($this->server->refresh()->icon)->toBe(base64_encode($picture))
        ->and(ActivityLog::query()->sole()->event)->toBe('server.icon');

    $this->get(route('servers.configuration', $this->server))->assertInertia(fn (Assert $page) => $page
        ->where('server.picture', dataUrl($picture))
        ->missing('server.icon'));
});

test('only a 64 by 64 PNG is accepted', function (string $icon) {
    $this->actingAs($this->admin)->put(route('servers.icon.update', $this->server), ['icon' => $icon])->assertSessionHasErrors('icon');

    Http::assertNothingSent();
    expect($this->server->refresh()->icon)->toBeNull();
})->with([
    'another size' => [dataUrl(png(32, 32))],
    'not an image' => [dataUrl('<?php echo 1;')],
    'another kind of image' => ['data:image/jpeg;base64,'.base64_encode(png(64, 64))],
    'not base64' => ['data:image/png;base64,***'],
    'far too large' => [dataUrl(png(64, 64).str_repeat('x', 60_000))],
]);

test('removing the picture deletes the file, also when the file is already gone', function () {
    $this->server->update(['icon' => base64_encode(png(64, 64))]);
    Http::swap(new Factory);
    Http::fake(['node.test:8080/*' => Http::response(status: 404)]);

    $this->actingAs($this->admin)->delete(route('servers.icon.destroy', $this->server))->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/servers/survival/files?path=server-icon.png'));
    expect($this->server->refresh()->icon)->toBeNull();
});

test('a picture needs the configure permission', function () {
    $viewer = User::factory()->create();
    $viewer->servers()->attach($this->server, ['permissions' => '["files"]']);

    $this->actingAs($viewer)->put(route('servers.icon.update', $this->server), ['icon' => dataUrl(png(64, 64))])->assertForbidden();
    $this->delete(route('servers.icon.destroy', $this->server))->assertForbidden();
    Http::assertNothingSent();
});

test('a modpack server shows the picture of its pack until one is uploaded', function () {
    $pack = Server::factory()->create(['server_type' => 'MODRINTH', 'modpack' => ['project' => 'cobblemon', 'name' => 'Cobblemon', 'icon' => 'https://cdn.modrinth.com/data/x/icon.png']]);

    expect($pack->forUser($this->admin)['picture'])->toBe('https://cdn.modrinth.com/data/x/icon.png')
        ->and($this->server->forUser($this->admin)['picture'])->toBeNull();

    $pack->update(['icon' => base64_encode(png(64, 64))]);
    expect($pack->forUser($this->admin)['picture'])->toStartWith('data:image/png;base64,');
});

test('the container of a modpack server is given the pack picture, when that picture answers', function () {
    $pack = Server::factory()->create(['slug' => 'pack', 'server_type' => 'MODRINTH', 'modpack' => ['project' => 'cobblemon', 'name' => 'Cobblemon', 'icon' => 'https://cdn.modrinth.com/data/x/icon.png']]);
    Http::swap(new Factory);
    Http::fake([
        'cdn.modrinth.com/*' => Http::sequence()->push('', 200)->push('', 404),
        'node.test:8080/*' => Http::response(status: 204),
    ]);
    $sent = fn (string $slug): array => Http::recorded(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), "/servers/{$slug}"))
        ->map(fn (array $pair): array => $pair[0]->data())->values()->all();

    (new NodeClient($pack->node))->apply($pack);
    // The Minecraft image stops the server when it cannot fetch the picture, so a dead address is left out.
    (new NodeClient($pack->node))->apply($pack);
    (new NodeClient($this->server->node))->apply($this->server);

    expect($sent('pack')[0]['icon'])->toBe('https://cdn.modrinth.com/data/x/icon.png')
        ->and($sent('pack')[1])->not->toHaveKey('icon')
        // Left out altogether, so a node that does not know the field yet still takes the request.
        ->and($sent('survival')[0])->not->toHaveKey('icon');
});

test('the API sets and removes the picture with the configure ability', function () {
    $read = $this->admin->createToken('Read', ['servers:read'])->plainTextToken;
    $configure = $this->admin->createToken('Configure', ['servers:configure'])->plainTextToken;
    $body = ['icon' => dataUrl(png(64, 64))];

    $this->putJson('/api/v1/servers/survival/icon', $body, bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the servers:configure ability.');
    $this->putJson('/api/v1/servers/survival/icon', ['icon' => dataUrl(png(16, 16))], bearer($configure))->assertStatus(422);

    $this->putJson('/api/v1/servers/survival/icon', $body, bearer($configure))->assertOk()->assertJsonPath('picture', $body['icon']);
    $this->deleteJson('/api/v1/servers/survival/icon', [], bearer($configure))->assertOk()->assertJsonPath('picture', null);
    expect($this->server->refresh()->icon)->toBeNull();
});
