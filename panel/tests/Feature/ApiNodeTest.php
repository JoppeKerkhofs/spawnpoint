<?php

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->home = Node::factory()->create(['name' => 'home', 'base_url' => 'http://node.test:8080']);
    $this->admin = User::factory()->admin()->create();
    Http::fake(['*' => Http::response([])]);
});

test('nodes are listed with their state, and reading is a different ability from managing', function () {
    $read = $this->admin->createToken('Read', ['nodes:read'])->plainTextToken;

    $this->getJson('/api/v1/nodes', bearer($read))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'home')
        ->assertJsonPath('data.0.online', true)
        // The token is never sent back.
        ->assertJsonMissingPath('data.0.token');

    $this->postJson('/api/v1/nodes', ['name' => 'attic', 'base_url' => 'http://attic.test:8080'], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the nodes:manage ability.');
});

test('a node is added, changed and removed, and its token is shown once', function () {
    $token = $this->admin->createToken('Admin', ['nodes:manage'])->plainTextToken;

    $response = $this->postJson('/api/v1/nodes', ['name' => 'attic', 'base_url' => 'https://attic.test:8080/'], bearer($token))
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'attic')
        ->assertJsonPath('data.base_url', 'https://attic.test:8080');

    expect($response->json('token'))->toBeString()->toHaveLength(64);

    $node = Node::firstWhere('name', 'attic');
    $this->putJson("/api/v1/nodes/{$node->id}", ['name' => 'loft', 'base_url' => 'http://attic.test:8080', 'memory_limit_mb' => '4096'], bearer($token))
        ->assertOk()->assertJsonPath('data.name', 'loft');

    $this->deleteJson("/api/v1/nodes/{$node->id}", [], bearer($token))->assertStatus(204);
});

test('a node with servers is not removed, and a viewer is refused', function () {
    Server::factory()->for($this->home)->create();
    $token = $this->admin->createToken('Admin', ['nodes:manage'])->plainTextToken;

    $this->deleteJson("/api/v1/nodes/{$this->home->id}", [], bearer($token))->assertStatus(422);

    $viewer = User::factory()->create()->createToken('Phone', ['nodes:read'])->plainTextToken;
    $this->getJson('/api/v1/nodes', bearer($viewer))->assertStatus(403);
});
