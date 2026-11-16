<?php

use App\Events\Changed;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

function channelsOf(Changed $event): array
{
    $names = array_map(fn ($channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

test('a change to a server goes to the admins and to the viewers it is shared with', function () {
    $server = Server::factory()->create(['slug' => 'survival']);
    $admin = User::factory()->admin()->create();
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();
    $viewer->servers()->attach($server, ['permissions' => '[]']);
    // The owner the factory made is a viewer without a share.
    $expected = ["private-users.{$admin->id}", "private-users.{$viewer->id}"];
    sort($expected);

    expect(channelsOf(new Changed('survival', 'backup')))->toBe($expected)
        ->and((new Changed('survival', 'backup'))->broadcastWith())->toBe(['server' => 'survival', 'what' => 'backup'])
        ->and((new Changed('survival', 'backup'))->broadcastAs())->toBe('changed');

    // Taking the share away stops the events at once, also for a tab that is still open.
    $viewer->servers()->detach($server);
    expect(channelsOf(new Changed('survival', 'backup')))->toBe(["private-users.{$admin->id}"])
        ->and($stranger->id)->not->toBe($admin->id);
});

test('a change without a server goes to the admins only', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create();

    expect(channelsOf(new Changed(null, 'user')))->toBe(["private-users.{$admin->id}"]);
});

test('everything recorded in the activity log is announced', function () {
    Event::fake([Changed::class]);
    $server = Server::factory()->create(['slug' => 'survival']);

    ActivityLog::record('backup.create', $server);
    ActivityLog::record('user.create');

    Event::assertDispatched(fn (Changed $event): bool => $event->server === 'survival' && $event->what === 'backup');
    Event::assertDispatched(fn (Changed $event): bool => $event->server === null && $event->what === 'user');
});

test('an announcement that cannot be sent never breaks what caused it', function () {
    Event::listen(Changed::class, fn () => throw new RuntimeException('Reverb is not there'));

    Changed::send(Server::factory()->create(), 'backup');
})->throwsNoExceptions();

test('a user may listen to their own channel only', function () {
    config(['broadcasting.default' => 'reverb']);
    // Channels were registered for the tests' own broadcaster, which has none.
    require base_path('routes/channels.php');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);

    $this->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-users.{$user->id}"])->assertOk()->assertJsonStructure(['auth']);
    $this->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-users.{$other->id}"])->assertForbidden();
});

test('pages are told how to connect only when there is something to connect to', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('live', null));

    config(['broadcasting.default' => 'reverb']);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('live.key', config('broadcasting.connections.reverb.key'))
        ->missing('live.secret'));
});
