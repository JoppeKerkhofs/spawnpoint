<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the front page sends a guest to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('an error is shown on the panel\'s own page, with its status', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
});
