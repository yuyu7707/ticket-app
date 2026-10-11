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

    expect(User::where('email', 'organizer@example.com')->first()->role)->toBe(UserRole::Organizer);
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

test('dashboard redirects each role to its home', function (UserRole $role, string $path) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect($path);
})->with([
    'customer' => [UserRole::Customer, '/orders'],
    'organizer' => [UserRole::Organizer, '/organizer/events'],
    'admin' => [UserRole::Admin, '/admin/events'],
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
