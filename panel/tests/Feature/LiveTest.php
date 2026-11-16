<?php

use App\Console\Commands\BroadcastServers;
use App\Events\Changed;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\LiveListeners;
use App\Services\NodeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
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
        ->and(channelsOf(new Changed('survival', 'backup')))->not->toContain("private-users.{$stranger->id}");
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

// One node with one server, as its /servers answer; the watcher is told someone is connected.
function watching(bool $connected, array ...$answers): void
{
    test()->mock(LiveListeners::class)->shouldReceive('any')->andReturn($connected);
    Server::factory()->create(['slug' => 'survival']);
    $sequence = Http::sequence();
    foreach ($answers as $answer) {
        $sequence->push([['slug' => 'survival', 'state' => 'running', 'detail' => null, 'players' => null, 'usage' => ['cpu' => 1], 'disk_bytes' => 1, 'last_backup' => null, 'mods_changed' => 0, ...$answer]]);
    }
    Http::fake(['node.test:8080/servers' => $sequence]);
    Event::fake([Changed::class]);
}

test('the nodes are left alone while no one is connected', function () {
    watching(false, []);

    $this->artisan('servers:broadcast --once')->assertSuccessful();

    Http::assertNothingSent();
});

test('a server whose state changed is announced, once', function () {
    watching(true, [], [], ['state' => 'offline'], ['state' => 'offline']);

    foreach (range(1, 4) as $run) {
        $this->artisan('servers:broadcast --once');
    }

    Event::assertDispatchedTimes(Changed::class, 1);
    Event::assertDispatched(fn (Changed $event): bool => $event->server === 'survival' && $event->what === 'state');
});

test('numbers that always move are announced every fifth round only', function () {
    watching(true, ...array_map(fn (int $cpu): array => ['usage' => ['cpu' => $cpu]], range(1, 6)));

    foreach (range(1, 4) as $run) {
        $this->artisan('servers:broadcast --once');
    }
    Event::assertNotDispatched(Changed::class);

    $this->artisan('servers:broadcast --once');
    Event::assertDispatchedTimes(Changed::class, 1);
});

test('pages read the state the watcher just fetched instead of asking the nodes again', function () {
    watching(true, [], ['state' => 'offline']);

    $this->artisan('servers:broadcast --once');
    expect(NodeClient::recentStates(User::factory()->admin()->create())['servers']['survival']['state'])->toBe('running');
    Http::assertSentCount(1);

    // Without a watcher the page asks for itself.
    Cache::flush();
    expect(NodeClient::recentStates(User::factory()->admin()->create())['servers']['survival']['state'])->toBe('offline');
});

test('a channel is only signed for someone who could open the panel itself', function () {
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
    $user = User::factory()->create();
    $join = ['socket_id' => '1.1', 'channel_name' => "private-users.{$user->id}"];

    $this->postJson('/broadcasting/auth', $join)->assertUnauthorized();

    // Held at the two-factor setup, a user gets no pages and so no announcements either.
    Setting::put('require_two_factor', '1');
    $this->actingAs($user)->postJson('/broadcasting/auth', $join)->assertRedirect(route('security.edit'));
});

test('a guest is not told how to connect', function () {
    config(['broadcasting.default' => 'reverb']);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('live', null));
});

test('a viewer gets the state of their own servers only, also from what the watcher fetched', function () {
    $shared = Server::factory()->create(['slug' => 'shared']);
    Server::factory()->for($shared->node)->create(['slug' => 'hidden']);
    $viewer = User::factory()->create();
    $viewer->servers()->attach($shared, ['permissions' => '[]']);
    $state = ['state' => 'running', 'detail' => null, 'players' => null, 'usage' => null, 'disk_bytes' => null, 'last_backup' => null, 'mods_changed' => 0];
    Cache::put('server-states', ['online' => true, 'servers' => ['shared' => $state, 'hidden' => $state]], 6);

    expect(array_keys(NodeClient::recentStates($viewer)['servers']))->toBe(['shared'])
        ->and(array_keys(NodeClient::recentStates(User::factory()->admin()->create())['servers']))->toBe(['shared', 'hidden']);
});

test('the watcher stops in time for the next one to start', function () {
    $event = collect(Schedule::events())->first(fn ($event): bool => str_contains((string) $event->command, 'servers:broadcast'));

    expect($event->expression)->toBe('* * * * *')
        ->and($event->runInBackground)->toBeTrue()
        ->and(BroadcastServers::SECONDS)->toBeLessThanOrEqual(50);
});
