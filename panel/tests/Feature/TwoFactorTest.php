<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('users with two factor enabled are sent to the challenge instead of being logged in', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    $this->get(route('two-factor.login'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorChallenge'));
});

test('a recovery code completes the challenge', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a wrong code does not complete the challenge', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-code']);

    $this->assertGuest();
});
