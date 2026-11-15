<?php

use App\Api\Abilities;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the token page is given the grouped abilities and the presets', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ApiTokens')
            ->has('groups', count(Abilities::groups()))
            ->where('groups.0.key', 'servers')
            ->where('groups.0.abilities.0.name', 'servers:read')
            ->where('presets.read-only', Abilities::presets()['read-only'])
            // The flat list is gone; the page reads the groups now.
            ->missing('abilities'));
});

test('a viewer is not offered the administration abilities on the token page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups', count(Abilities::groups()) - 1)
            ->where('presets.read-only', ['servers:read', 'logs:read', 'files:read', 'backups:read']));
});

test('a token is created from the abilities the picker submitted', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->post(route('api-tokens.store'), [
        'name' => 'Phone',
        'abilities' => ['servers:read', 'servers:power', 'console:use'],
    ])->assertSessionHasNoErrors();

    expect($user->tokens()->sole()->abilities)->toBe(['servers:read', 'servers:power', 'console:use']);
});

test('a retired or invented ability is refused', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('api-tokens.store'), ['name' => 'Phone', 'abilities' => ['backups:manage']])
        ->assertSessionHasErrors('abilities.0');

    $this->post(route('api-tokens.store'), ['name' => 'Phone', 'abilities' => ['servers:everything']])
        ->assertSessionHasErrors('abilities.0');
});
