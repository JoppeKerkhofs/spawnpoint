<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('settings are readable without ever sending a secret', function () {
    Setting::put('curseforge_key', 'super-secret');
    Setting::put('base_domain', 'mc.example.com');
    $token = $this->admin->createToken('Read', ['settings:read'])->plainTextToken;

    $response = $this->getJson('/api/v1/settings', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.base_domain', 'mc.example.com')
        ->assertJsonPath('data.has_curseforge_key', true);

    expect($response->content())->not->toContain('super-secret');
});

test('a saved secret can be removed, and an empty one keeps what was saved', function () {
    Setting::put('curseforge_key', 'super-secret');
    $token = $this->admin->createToken('Write', ['settings:manage'])->plainTextToken;
    $form = ['base_domain' => 'mc.example.net', 'bluemap_url' => 'https://maps.example.com'];

    $this->putJson('/api/v1/settings', $form, bearer($token))->assertOk()->assertJsonPath('data.has_curseforge_key', true);
    $this->putJson('/api/v1/settings', [...$form, 'remove_curseforge_key' => true], bearer($token))
        ->assertOk()->assertJsonPath('data.has_curseforge_key', false);
});

test('changing settings needs its own ability', function () {
    $read = $this->admin->createToken('Read', ['settings:read'])->plainTextToken;
    $write = $this->admin->createToken('Write', ['settings:manage'])->plainTextToken;

    $this->putJson('/api/v1/settings', ['base_domain' => 'mc.example.net', 'bluemap_url' => 'https://maps.example.com'], bearer($read))
        ->assertStatus(403)->assertJsonPath('message', 'This token lacks the settings:manage ability.');

    $this->putJson('/api/v1/settings', ['base_domain' => 'mc.example.net', 'bluemap_url' => 'https://maps.example.com', 'activity_retention_days' => '30'], bearer($write))
        ->assertOk();

    expect(Setting::value('base_domain'))->toBe('mc.example.net')
        ->and(Setting::value('activity_retention_days'))->toBe('30');
});

test('the activity log is read newest first and can be filtered by server', function () {
    ActivityLog::create(['actor' => 'System', 'event' => 'server.start', 'subject' => 'survival', 'created_at' => now()->subMinute()]);
    ActivityLog::create(['actor' => 'System', 'event' => 'server.stop', 'subject' => 'creative', 'created_at' => now()]);
    $token = $this->admin->createToken('Read', ['activity:read'])->plainTextToken;

    $this->getJson('/api/v1/activity', bearer($token))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.event', 'server.stop');

    $this->getJson('/api/v1/activity?server=survival', bearer($token))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'survival');
});

test('a setting that is left out of the request keeps its value', function () {
    Setting::put('memory_limit_mb', '4096');
    Setting::put('privacy_contact', 'Joppe');
    Setting::put('activity_retention_days', '30');
    $token = $this->admin->createToken('Write', ['settings:manage'])->plainTextToken;

    // An app sends what it changes, not the whole form as the web page does.
    $this->putJson('/api/v1/settings', ['base_domain' => 'mc.example.net', 'bluemap_url' => 'https://maps.example.com', 'activity_retention_days' => null], bearer($token))
        ->assertOk();

    expect(Setting::value('memory_limit_mb'))->toBe('4096')
        ->and(Setting::value('privacy_contact'))->toBe('Joppe')
        // Sent as null, so this one is cleared on purpose and the environment's value applies again.
        ->and(Setting::query()->find('activity_retention_days'))->toBeNull();
});
