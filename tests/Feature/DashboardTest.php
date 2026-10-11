<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated customers are redirected to a page that opens', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect();

    $this->get($response->headers->get('Location'))->assertOk();
});

test('authenticated organizers are redirected to their event list', function () {
    $user = User::factory()->create(['role' => UserRole::Organizer]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('organizer.events.index'));

    $this->get($response->headers->get('Location'))->assertOk();
});

test('authenticated admins are redirected to a page that opens', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect();

    $this->get($response->headers->get('Location'))->assertOk();
});
