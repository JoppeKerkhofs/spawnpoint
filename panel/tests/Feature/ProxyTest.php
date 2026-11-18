<?php

use App\Models\Node;
use App\Models\Server;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->folder = storage_path('framework/testing/proxy-'.uniqid());
    config(['panel.proxy_dir' => $this->folder]);
    $this->routes = fn (): array => json_decode(File::get("{$this->folder}/minecraft.json"), true);
    $this->maps = fn (): array => json_decode(File::get("{$this->folder}/maps/maps.yml"), true);
});

afterEach(fn () => File::deleteDirectory($this->folder));

test('servers on a node without addresses of its own are routed through the panel', function () {
    $local = Node::factory()->create(['name' => Node::LOCAL, 'base_url' => 'http://node:8080']);
    $attic = Node::factory()->create(['name' => 'attic', 'base_url' => 'https://10.0.0.7:8443']);
    Server::factory()->create(['node_id' => $local->id, 'slug' => 'home', 'hostname' => 'home.mc.example.com', 'bluemap_enabled' => true]);
    Server::factory()->create(['node_id' => $attic->id, 'slug' => 'far', 'hostname' => 'far.mc.example.com', 'bluemap_enabled' => true, 'is_default' => true]);
    Server::factory()->create(['node_id' => $attic->id, 'slug' => 'plain', 'hostname' => 'plain.mc.example.com']);

    expect(($this->routes)())->toBe([
        'mappings' => ['far.mc.example.com' => '10.0.0.7:25565', 'plain.mc.example.com' => '10.0.0.7:25565'],
        'default-server' => '10.0.0.7:25565',
    ])->and(($this->maps)())->toBe(['http' => [
        'routers' => ['map-far' => ['rule' => 'PathPrefix(`/far/`)', 'entryPoints' => ['bluemap'], 'service' => "node-{$attic->id}"]],
        'services' => ["node-{$attic->id}" => ['loadBalancer' => ['servers' => [['url' => 'http://10.0.0.7:8100']]]]],
    ]]);

    // With an address of its own players go straight to the node, and the maps still do not.
    $attic->update(['dns_target' => '198.51.100.9']);
    expect(($this->routes)())->toBe(['mappings' => []])
        ->and(($this->maps)()['http']['routers'])->toHaveKey('map-far');

    $attic->update(['bluemap_url' => 'https://maps.attic.example.com']);
    expect(($this->maps)())->toBe([]);

    $attic->update(['dns_target' => null]);
    Server::firstWhere('slug', 'far')->delete();
    expect(array_keys(($this->routes)()['mappings']))->toBe(['plain.mc.example.com']);

    // The router would forget a default server on its own host, so that one is named too.
    Server::firstWhere('slug', 'home')->update(['is_default' => true]);
    expect(($this->routes)()['default-server'])->toBe('mc-home:25565');
});

test('without a folder nothing is written', function () {
    config(['panel.proxy_dir' => null]);
    Server::factory()->create(['node_id' => Node::factory()->create(['name' => 'attic'])->id]);

    expect(File::exists($this->folder))->toBeFalse();
});
