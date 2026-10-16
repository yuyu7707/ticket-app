<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

test('the order list shows only the current user\'s orders', function () {
    $user = User::factory()->create();
    $ownOrder = Order::factory()->for($user)->for(Event::factory()->published()->create(['title' => '自分のイベント']))->create();
    OrderItem::factory()->for($ownOrder)->create(['quantity' => 2]);
    Order::factory()->for(Event::factory()->published()->create(['title' => '他人のイベント']))->create();

    $this->actingAs($user)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSee('自分のイベント')
        ->assertSee('2枚')
        ->assertSee(route('orders.show', $ownOrder))
        ->assertDontSee('他人のイベント');
});

test('guests are redirected to the login page', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
});
