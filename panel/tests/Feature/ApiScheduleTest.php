<?php

use App\Models\Node;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->server = Server::factory()->for(Node::factory()->create(['base_url' => 'http://node.test:8080']))->create(['slug' => 'survival']);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('Phone', ['schedules:manage'])->plainTextToken;
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
});

test('a schedule is added, paused and removed through the API', function () {
    $this->postJson('/api/v1/servers/survival/schedules', ['action' => 'backup', 'time' => '04:30'], bearer($this->token))
        ->assertStatus(201)->assertJsonPath('data.action', 'backup');

    $schedule = Schedule::sole();

    $this->putJson("/api/v1/servers/survival/schedules/{$schedule->id}", ['is_active' => false], bearer($this->token))
        ->assertOk()->assertJsonPath('data.is_active', false);

    $this->getJson('/api/v1/servers/survival/schedules', bearer($this->token))
        ->assertOk()->assertJsonCount(1, 'data');

    $this->deleteJson("/api/v1/servers/survival/schedules/{$schedule->id}", [], bearer($this->token))
        ->assertStatus(204);
    expect(Schedule::count())->toBe(0);
});

test('a command schedule needs a command, and a schedule of another server is not found', function () {
    $this->postJson('/api/v1/servers/survival/schedules', ['action' => 'command', 'time' => '04:30'], bearer($this->token))
        ->assertStatus(422);

    $elsewhere = Server::factory()->create(['slug' => 'creative']);
    $other = $elsewhere->schedules()->create(['action' => 'backup', 'time' => '05:00']);

    $this->deleteJson("/api/v1/servers/survival/schedules/{$other->id}", [], bearer($this->token))
        ->assertStatus(404);
});
