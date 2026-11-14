<?php

use App\Models\Server;
use App\Models\User;
use App\Services\NodeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->server = Server::factory()->create(['slug' => 'survival']);
    $this->actingAs(User::factory()->admin()->create());
});

test('a folder is listed and a text file opened through the node', function () {
    $this->freezeTime();
    Http::fake([
        'node.test:8080/servers/survival/files?path=config' => Http::response([
            ['name' => 'paper.yml', 'dir' => false, 'size' => 20, 'modified' => 1794600000],
        ]),
        'node.test:8080/servers/survival/files/content?path=config%2Fpaper.yml' => Http::response("a: 1\n"),
    ]);

    $this->get(route('servers.files', [$this->server, 'path' => '/config/', 'edit' => 'config/paper.yml']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/Files')
            ->where('path', 'config')
            ->where('entries.0.name', 'paper.yml')
            ->where('file', ['path' => 'config/paper.yml', 'content' => "a: 1\n"])
            ->where('error', null)
            ->where('upload.url', 'http://node.test:8080/servers/survival/upload?token='
                .NodeClient::token('files', $this->server->node->token, 'survival', now()->getTimestamp() + 300, true)),
        );
});

test('the page explains why a file cannot be opened', function () {
    Http::fake([
        'node.test:8080/servers/survival/files?path=' => Http::response([]),
        'node.test:8080/servers/survival/files/content*' => Http::response(status: 415),
    ]);

    $this->get(route('servers.files', [$this->server, 'edit' => 'world/level.dat']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('file', null)
            ->where('error', 'That is not a text file. Download it instead.'),
        );
});

test('saving sends the exact text as a signed raw body', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 204)]);

    $this->put(route('servers.files.save', $this->server), ['path' => 'server.properties', 'content' => "pvp=false\n"])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/servers/survival/files/content?path=server.properties')
        && $request->body() === "pvp=false\n"
        && $request->header('X-Signature') === [NodeClient::signature(
            $this->server->node->token,
            (int) $request->header('X-Timestamp')[0],
            'PUT',
            '/servers/survival/files/content?path=server.properties',
            "pvp=false\n",
        )]);
});

test('folders, renames and deletes are passed to the node', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 204)]);

    $this->post(route('servers.files.folder', $this->server), ['path' => 'mods/disabled']);
    $this->post(route('servers.files.rename', $this->server), ['from' => 'mods/a.jar', 'to' => 'mods/disabled/a.jar']);
    $this->delete(route('servers.files.destroy', $this->server), ['path' => 'mods/old.jar']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/files/mkdir') && $request['path'] === 'mods/disabled');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/files/rename') && $request['to'] === 'mods/disabled/a.jar');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/files?path=mods%2Fold.jar'));
});

test('paths that step out of the folder never reach the node', function (string $path) {
    Http::fake();

    $this->get(route('servers.files', [$this->server, 'path' => $path]))->assertSessionHasErrors('path');
    $this->get(route('servers.files.download', [$this->server, 'path' => $path]))->assertSessionHasErrors('path');
    $this->put(route('servers.files.save', $this->server), ['path' => $path, 'content' => 'x'])->assertSessionHasErrors('path');
    $this->delete(route('servers.files.destroy', $this->server), ['path' => $path])->assertSessionHasErrors('path');
    $this->post(route('servers.files.rename', $this->server), ['from' => 'a', 'to' => $path])->assertSessionHasErrors('to');

    Http::assertNothingSent();
})->with(['..', '../other', 'mods/../../other', 'mods/..', 'mods\\..\\x', "a\nb"]);

test('download redirects to the node with a read-only token', function () {
    $this->freezeTime();

    $this->get(route('servers.files.download', [$this->server, 'path' => 'mods/a b.jar']))
        ->assertRedirect('http://node.test:8080/servers/survival/download?token='
            .NodeClient::token('files', $this->server->node->token, 'survival', now()->getTimestamp() + 300, false)
            .'&path=mods%2Fa%20b.jar');
});

test('viewers cannot use the file manager', function () {
    Http::fake();
    $this->actingAs(User::factory()->create());

    $this->get(route('servers.files', $this->server))->assertForbidden();
    $this->put(route('servers.files.save', $this->server), ['path' => 'a', 'content' => 'x'])->assertForbidden();
    $this->get(route('servers.files.download', [$this->server, 'path' => 'a']))->assertForbidden();

    Http::assertNothingSent();
});
