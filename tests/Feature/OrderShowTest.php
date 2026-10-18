<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;

test('the buyer sees the items and the ticket codes of a paid order', function () {
    $order = Order::factory()->paid()->create();
    $item = OrderItem::factory()->for($order)->create(['quantity' => 1, 'unit_price' => 3000]);
    $ticket = Ticket::factory()->for($item)->create();

    $this->actingAs($order->user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee($order->event->title)
        ->assertSee($item->ticketType->name)
        ->assertSee('3,000円')
        ->assertSee($ticket->code)
        ->assertDontSee('支払いが完了していません');
});

test('a pending order shows that the payment is not complete', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create();

    $this->actingAs($order->user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('支払いが完了していません')
        ->assertDontSee('チケットコード');
});

test('the event organizer and admins can see the order with a link back to the sales page', function (string $viewer) {
    $order = Order::factory()->paid()->create();
    OrderItem::factory()->for($order)->create();

    $user = $viewer === 'organizer' ? $order->event->organizer : User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee(route('organizer.events.orders', $order->event))
        ->assertDontSee('マイチケットへ戻る');
})->with(['organizer', 'admin']);

test('other users cannot see the order', function () {
    $order = Order::factory()->paid()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', $order))
        ->assertForbidden();
});
