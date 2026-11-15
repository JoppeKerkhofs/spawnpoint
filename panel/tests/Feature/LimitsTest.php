<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\MemoryLimit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function limitForm(array $overrides = []): array
{
    return [
        'name' => 'Creative', 'slug' => 'creative', 'server_type' => 'PAPER', 'mc_version' => 'LATEST', 'java_version' => '25',
        'memory_mb' => '4096', 'motd' => 'Hi', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => '20', 'eula' => '1',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->existing = Server::factory()->create(['slug' => 'survival', 'memory_mb' => 4096]);
    $this->actingAs(User::factory()->admin()->create());
});

test('a server cannot be created with more memory than is left under the limit', function () {
    config(['panel.memory_limit_mb' => 6144]);
    Http::fake(['node.test:8080/servers/*' => Http::response(status: 202)]);

    $this->post(route('servers.store'), limitForm())
        ->assertSessionHasErrors(['memory_mb' => 'Only 2 GB is left under the 6 GB memory limit.']);
    expect(Server::count())->toBe(1);
    Http::assertNothingSent();

    $this->post(route('servers.store'), limitForm(['memory_mb' => '2048']))->assertSessionHasNoErrors();
    expect(Server::count())->toBe(2);
});

test('a server can keep or raise its own memory up to the limit, but not past it', function () {
    config(['panel.memory_limit_mb' => 6144]);
    $form = limitForm(['name' => 'Survival']);
    Http::fake(['node.test:8080/servers/*' => Http::response(status: 202)]);

    $this->put(route('servers.update', $this->existing), [...$form, 'memory_mb' => '6144'])->assertSessionHasNoErrors();
    $this->put(route('servers.update', $this->existing), [...$form, 'memory_mb' => '7168'])->assertSessionHasErrors('memory_mb');

    expect($this->existing->refresh()->memory_mb)->toBe(6144);
});

test('without a configured limit, nine tenths of the host memory is the limit', function () {
    config(['panel.memory_limit_mb' => null]);
    Http::fake(['node.test:8080/host' => Http::response(['memory_mb' => 8192])]);
    Http::fake(['node.test:8080/servers/*' => Http::response(status: 202)]);

    expect(MemoryLimit::mb(Node::first()))->toBe(7372)
        ->and(MemoryLimit::freeMb(Node::first()))->toBe(7372 - 4096)
        ->and(MemoryLimit::freeMb(Node::first(), $this->existing))->toBe(7372);

    $this->get(route('servers.create'))
        ->assertInertia(fn (Assert $page) => $page->where('memory', ['limitMb' => 7372, 'freeMb' => 3276]));
});

test('there is no limit when the host memory cannot be read', function () {
    config(['panel.memory_limit_mb' => null]);
    Http::fake(['node.test:8080/host' => Http::failedConnection()]);
    Http::fake(['node.test:8080/servers/*' => Http::response(status: 202)]);

    expect(MemoryLimit::mb(Node::first()))->toBeNull();
    $this->post(route('servers.store'), limitForm(['memory_mb' => '32768']))->assertSessionHasNoErrors();
});

test('making a server the default demotes the previous one and recreates both containers', function () {
    Http::fake(['node.test:8080/servers/*' => Http::response(status: 202)]);
    $this->existing->update(['is_default' => true]);

    $this->post(route('servers.store'), limitForm(['is_default' => '1']))->assertSessionHasNoErrors();

    expect($this->existing->refresh()->is_default)->toBeFalse()
        ->and(Server::firstWhere('slug', 'creative')->is_default)->toBeTrue();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/survival') && $request['is_default'] === false && $request['start'] === false);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/creative') && $request['is_default'] === true);
});

test('nothing changes when the previous default cannot be recreated', function () {
    $this->existing->update(['is_default' => true]);
    $other = Server::factory()->for(Node::first())->create(['slug' => 'creative', 'name' => 'Creative']);
    Http::fake(['node.test:8080/servers/survival' => Http::response(status: 409)]);

    $this->put(route('servers.update', $other), limitForm(['is_default' => '1']))->assertSessionHasErrors('node');

    expect($this->existing->refresh()->is_default)->toBeTrue()
        ->and($other->refresh()->is_default)->toBeFalse();
});
