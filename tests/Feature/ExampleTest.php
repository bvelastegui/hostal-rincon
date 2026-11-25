<?php

use App\Models\User;

test('guests are redirected to login from the home page', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('los empleados son enviados a turno desde la página inicial', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirectToRoute('cash-closings.index');
});

test('la administración es enviada al panel desde la página inicial', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('home'))
        ->assertRedirectToRoute('dashboard');
});
