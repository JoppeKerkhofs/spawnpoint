<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email is stored in lowercase so the user can still log in', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'Test@Example.com',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('test@example.com');

    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => 'Test@Example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('email must be unique regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'Taken@Example.com',
        ]);

    $response
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('a minecraft account is linked by name, gives the user its face, and can be unlinked', function () {
    Http::fake([
        'api.mojang.com/users/profiles/minecraft/joppek' => Http::response(['id' => '069a79f444e94726a5befca90e38aaf5', 'name' => 'JoppeK']),
        'api.mojang.com/users/profiles/minecraft/nobody_here' => Http::response(status: 404),
    ]);
    $user = User::factory()->create();
    $profile = ['name' => $user->name, 'email' => $user->email];
    $this->actingAs($user);

    $this->patch(route('profile.update'), [...$profile, 'minecraft_name' => 'joppek'])->assertSessionHasNoErrors();
    expect($user->refresh()->minecraft_name)->toBe('JoppeK')
        ->and($user->avatar)->toBe('https://mc-heads.net/avatar/069a79f444e94726a5befca90e38aaf5/64');

    $this->patch(route('profile.update'), [...$profile, 'minecraft_name' => 'nobody_here'])->assertSessionHasErrors('minecraft_name');
    $this->patch(route('profile.update'), [...$profile, 'minecraft_name' => 'has spaces'])->assertSessionHasErrors('minecraft_name');
    expect($user->refresh()->minecraft_name)->toBe('JoppeK');

    $this->patch(route('profile.update'), $profile)->assertSessionHasNoErrors();
    expect($user->refresh()->minecraft_uuid)->toBeNull()->and($user->avatar)->toBeNull();
});
