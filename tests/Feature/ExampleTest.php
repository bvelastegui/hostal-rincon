<?php

use App\Models\User;

test('guests are redirected to login from the home page', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('authenticated users are redirected to the dashboard from the home page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
