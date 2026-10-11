<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

test('users can register as an organizer', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => '主催 太郎',
        'email' => 'organizer@example.com',
        'role' => 'organizer',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    expect(User::where('email', 'organizer@example.com')->first()->role)->toBe(UserRole::Organizer);
});

test('registration requires a valid role', function (?string $role) {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), array_filter([
        'name' => '参加 次郎',
        'email' => 'invalid-role@example.com',
        'role' => $role,
        'password' => 'password',
        'password_confirmation' => 'password',
    ], fn (?string $value) => $value !== null));

    $response->assertSessionHasErrors('role');
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'invalid-role@example.com']);
})->with([
    'missing' => [null],
    'unknown' => ['hacker'],
]);

test('role is not mass assignable', function () {
    $user = User::factory()->create();

    $user->update(['role' => UserRole::Admin->value]);

    expect($user->fresh()->role)->toBe(UserRole::Customer);
});

test('users cannot register as an admin', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => '管理 花子',
        'email' => 'admin@example.com',
        'role' => 'admin',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('role');
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
});

test('registration screen shows the role error', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->from(route('register'))
        ->followingRedirects()
        ->post(route('register.store'), [
            'name' => '管理 花子',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertSee(__('validation.in', ['attribute' => __('validation.attributes.role')]));
});

test('dashboard redirects each role to its home, or to the event list until that page exists', function (UserRole $role, string $routeName) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirectToRoute(Route::has($routeName) ? $routeName : 'home');
})->with([
    'customer' => [UserRole::Customer, 'orders.index'],
    'organizer' => [UserRole::Organizer, 'organizer.events.index'],
    'admin' => [UserRole::Admin, 'admin.events.index'],
]);

test('customers cannot access organizer only routes', function () {
    Route::middleware(['web', 'auth', 'role:organizer'])->get('/_test/organizer-only', fn () => 'ok');

    $this->actingAs(User::factory()->create())
        ->get('/_test/organizer-only')
        ->assertForbidden();
});

test('organizers can access organizer only routes', function () {
    Route::middleware(['web', 'auth', 'role:organizer'])->get('/_test/organizer-only', fn () => 'ok');

    $this->actingAs(User::factory()->organizer()->create())
        ->get('/_test/organizer-only')
        ->assertOk();
});

test('admins cannot access organizer only routes', function () {
    Route::middleware(['web', 'auth', 'role:organizer'])->get('/_test/organizer-only', fn () => 'ok');

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/_test/organizer-only')
        ->assertForbidden();
});

test('guests are forbidden by the role middleware', function () {
    Route::middleware(['web', 'role:organizer'])->get('/_test/organizer-only', fn () => 'ok');

    $this->get('/_test/organizer-only')
        ->assertForbidden();
});

test('any of the listed roles can access the route', function (UserRole $role) {
    Route::middleware(['web', 'auth', 'role:organizer,admin'])->get('/_test/organizer-or-admin', fn () => 'ok');

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get('/_test/organizer-or-admin')
        ->assertOk();
})->with([
    'organizer' => [UserRole::Organizer],
    'admin' => [UserRole::Admin],
]);
