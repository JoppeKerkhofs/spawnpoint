<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->server = Server::factory()->create(['slug' => 'survival', 'name' => 'Survival']);
    $this->admin = User::factory()->admin()->create(['name' => 'Joppe']);
});

test('a webhook must be an https address on the public internet', function (string $url) {
    $this->actingAs($this->admin)
        ->post(route('webhooks.store'), ['name' => 'Hook', 'url' => $url, 'format' => 'discord', 'events' => ['crash']])
        ->assertSessionHasErrors('url');

    expect($this->admin->webhooks()->count())->toBe(0);
})->with([
    'plain http' => ['http://93.184.216.34/hook'],
    'this machine' => ['https://127.0.0.1/hook'],
    'the local network' => ['https://192.168.1.10/hook'],
    'cloud metadata' => ['https://169.254.169.254/latest'],
]);

test('a webhook is stored without ever sending its address back to the browser', function () {
    $this->actingAs($this->admin)
        ->post(route('webhooks.store'), ['name' => 'Discord', 'url' => 'https://93.184.216.34/secret-path', 'format' => 'discord', 'events' => ['crash', 'power']])
        ->assertSessionHasNoErrors();

    $this->get(route('webhooks.index'))->assertInertia(fn (Assert $page) => $page
        ->where('webhooks.0.host', '93.184.216.34')
        ->missing('webhooks.0.url'));
    expect($this->admin->webhooks()->sole()->getRawOriginal('url'))->not->toContain('secret-path');
});

test('a power action reaches the webhooks that asked for it, of users who can see the server', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202), '93.184.216.34/*' => Http::response(status: 204)]);
    $discord = $this->admin->webhooks()->create(['name' => 'Discord', 'url' => 'https://93.184.216.34/discord', 'format' => 'discord', 'events' => ['power']]);
    $this->admin->webhooks()->create(['name' => 'Crashes', 'url' => 'https://93.184.216.34/crash', 'format' => 'json', 'events' => ['crash']]);
    $this->admin->webhooks()->create(['name' => 'Paused', 'url' => 'https://93.184.216.34/paused', 'format' => 'json', 'events' => ['power'], 'is_active' => false]);
    User::factory()->create()->webhooks()->create(['name' => 'Outsider', 'url' => 'https://93.184.216.34/outsider', 'format' => 'slack', 'events' => ['power']]);

    $this->actingAs($this->admin)->post(route('servers.power', [$this->server, 'stop']));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.34/discord'
        && data_get($request->data(), 'embeds.0.title') === 'Survival'
        && data_get($request->data(), 'embeds.0.description') === 'Joppe stopped Survival.'
        && data_get($request->data(), 'embeds.0.color') === 0xE0A526
        && data_get($request->data(), 'embeds.0.fields.0.value') === $this->server->hostname
        && ! isset($request->data()['content']));
    Http::assertNotSent(fn (Request $request) => (bool) preg_match('#/(crash|paused|outsider)$#', $request->url()));
    expect($discord->refresh()->last_status)->toBe(204);
});

test('a user may only test, pause and delete their own webhooks', function () {
    Http::fake();
    $webhook = $this->admin->webhooks()->create(['name' => 'Mine', 'url' => 'https://93.184.216.34/mine', 'format' => 'json', 'events' => ['crash']]);
    $this->actingAs(User::factory()->create());

    $this->post(route('webhooks.test', $webhook->id))->assertNotFound();
    $this->put(route('webhooks.update', $webhook->id), ['is_active' => false])->assertNotFound();
    $this->delete(route('webhooks.destroy', $webhook->id));

    Http::assertNothingSent();
    expect($webhook->refresh()->is_active)->toBeTrue();
});

test('watching the servers reports a crash once, with every field for a json webhook', function () {
    $this->admin->webhooks()->create(['name' => 'SMS', 'url' => 'https://93.184.216.34/sms', 'format' => 'json', 'events' => ['crash']]);
    $crashed = [['slug' => 'survival', 'state' => 'error', 'detail' => 'Exited with code 1']];
    Http::fake([
        'node.test:8080/servers' => Http::sequence()->push([['slug' => 'survival', 'state' => 'running', 'detail' => null]])->push($crashed)->push($crashed),
        '93.184.216.34/*' => Http::response(),
    ]);

    $this->artisan('servers:watch');
    $this->artisan('servers:watch');
    $this->artisan('servers:watch');

    $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sms'));
    expect($sent)->toHaveCount(1)
        ->and($sent->first()[0]['event'])->toBe('server.crashed')
        ->and($sent->first()[0]['server'])->toBe('survival')
        ->and($sent->first()[0]['message'])->toBe('Survival crashed. Exited with code 1');
});

test('the automatic backup is a daily schedule, and backups carry the age limit to the node', function () {
    Http::fake(['node.test:8080/*' => Http::response(status: 202)]);
    $this->actingAs($this->admin);

    $this->put(route('servers.backups.retention', $this->server), ['backup_keep' => '7', 'backup_retention_days' => '30', 'auto_backup' => '1', 'auto_backup_at' => '04:30'])
        ->assertSessionHasNoErrors();

    expect($this->server->refresh()->backup_retention_days)->toBe(30)
        ->and($this->server->schedules()->sole()->only('action', 'time', 'weekday', 'is_active'))
        ->toBe(['action' => 'backup', 'time' => '04:30', 'weekday' => null, 'is_active' => true]);

    $this->post(route('servers.backups.store', $this->server));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['keep'] === 7 && $request['max_age_days'] === 30);

    $this->put(route('servers.backups.retention', $this->server), ['backup_keep' => '7'])->assertSessionHasNoErrors();
    expect($this->server->refresh()->backup_retention_days)->toBeNull()
        ->and($this->server->schedules()->sole()->is_active)->toBeFalse();

    $this->put(route('servers.backups.retention', $this->server), ['backup_keep' => '7', 'auto_backup' => '1'])->assertSessionHasErrors('auto_backup_at');
});
