<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // Named, and owning the server, so the list is exactly Admin then Sam: the factory would
    // otherwise make a second user with a random name as the server's owner.
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->server = Server::factory()->create(['slug' => 'survival', 'owner_id' => $this->admin->id]);
    $this->token = $this->admin->createToken('Admin', ['users:read', 'users:manage'])->plainTextToken;
});

test('users are listed with their role and shares', function () {
    $viewer = User::factory()->create(['name' => 'Sam']);
    $viewer->servers()->attach($this->server, ['permissions' => json_encode(['power', 'files'])]);

    $this->getJson('/api/v1/users', bearer($this->token))
        ->assertOk()
        ->assertJsonPath('data.1.name', 'Sam')
        ->assertJsonPath("data.1.shares.{$this->server->id}", ['power', 'files']);
});

test('a user is invited with shares, changed and removed', function () {
    Notification::fake();

    $this->postJson('/api/v1/users', [
        'name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'viewer',
        'shares' => [$this->server->id => ['power']],
    ], bearer($this->token))->assertStatus(201)->assertJsonPath('data.invited', true);

    $sam = User::firstWhere('email', 'sam@example.com');
    expect($sam->servers()->count())->toBe(1);

    $this->putJson("/api/v1/users/{$sam->id}", ['role' => 'viewer', 'shares' => [$this->server->id => ['power', 'backups']]], bearer($this->token))
        ->assertOk();

    $this->deleteJson("/api/v1/users/{$sam->id}", [], bearer($this->token))->assertStatus(204);
    expect(User::count())->toBe(1);
});

test('an admin cannot demote or delete themselves through the API either', function () {
    $this->putJson("/api/v1/users/{$this->admin->id}", ['role' => 'viewer'], bearer($this->token))
        ->assertStatus(422);
    $this->deleteJson("/api/v1/users/{$this->admin->id}", [], bearer($this->token))
        ->assertStatus(422);
});

test('a viewer token with users:manage is still refused: a token never exceeds its owner', function () {
    $viewer = User::factory()->create()->createToken('Phone', ['users:read', 'users:manage'])->plainTextToken;

    $this->getJson('/api/v1/users', bearer($viewer))->assertStatus(403);
    $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'], bearer($viewer))->assertStatus(403);
    expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
});

test('reading users is a different ability from managing them', function () {
    $read = $this->admin->createToken('Read', ['users:read'])->plainTextToken;

    $this->getJson('/api/v1/users', bearer($read))->assertOk();
    $this->postJson('/api/v1/users', [], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the users:manage ability.');
});
