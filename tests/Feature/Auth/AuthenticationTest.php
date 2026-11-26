<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
});

test('el acceso usa el tema claro aunque se haya guardado una preferencia oscura', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('name="color-scheme" content="light"', escape: false)
        ->assertDontSee('class="dark"', escape: false);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('el inicio de sesión rechaza recordar credenciales aunque se envíe el campo remember', function () {
    $user = User::factory()->create(['remember_token' => null]);
    $cookie = Auth::guard('web')->getRecallerName();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ])->assertRedirect()->assertCookieMissing($cookie);

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'remember_token' => null]);
});

test('una cookie persistente anterior no autentica y se elimina del navegador', function () {
    $token = Str::random(60);
    $user = User::factory()->create(['remember_token' => $token]);
    $cookie = Auth::guard('web')->getRecallerName();

    $this->withCookie($cookie, "{$user->id}|{$token}|{$user->password}")->get(route('home'))
        ->assertRedirectToRoute('login')->assertCookieExpired($cookie);
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'))->assertSessionHas(SessionKey::CLEAR_HISTORY, true);

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
