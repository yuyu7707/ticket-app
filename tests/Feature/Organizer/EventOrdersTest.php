<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;

beforeEach(function () {
    $this->event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($this->event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 100]);

    $this->paidOrder = Order::factory()->paid()->for($this->event)
        ->for(User::factory()->create(['email' => 'paid@example.com']))
        ->create(['total_amount' => 6000]);
    OrderItem::factory()->for($this->paidOrder)->for($ticketType)->create(['quantity' => 2, 'unit_price' => 3000]);

    $pendingOrder = Order::factory()->for($this->event)
        ->for(User::factory()->create(['email' => 'pending@example.com']))
        ->create(['status' => OrderStatus::Pending, 'total_amount' => 15000]);
    OrderItem::factory()->for($pendingOrder)->for($ticketType)->create(['quantity' => 5, 'unit_price' => 3000]);
});

test('the owner sees the paid orders and the sales of the event', function () {
    $this->actingAs($this->event->organizer)
        ->get(route('organizer.events.orders', $this->event))
        ->assertOk()
        ->assertSee('6,000円')
        ->assertSee('2 / 100枚')
        ->assertSee('1件')
        ->assertSee('paid@example.com')
        ->assertSee('一般 × 2枚')
        ->assertSee(route('orders.show', $this->paidOrder))
        ->assertDontSee('pending@example.com');
});

test('admins can see the orders of any event', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('organizer.events.orders', $this->event))
        ->assertOk();
});

test('other organizers cannot see the orders', function () {
    $this->actingAs(User::factory()->organizer()->create())
        ->get(route('organizer.events.orders', $this->event))
        ->assertForbidden();
});

test('the organizer menu shows the sold quantity and sales of each event', function () {
    $this->actingAs($this->event->organizer)
        ->get(route('organizer.events.index'))
        ->assertOk()
        ->assertSee('2枚')
        ->assertSee('6,000円')
        ->assertSee(route('organizer.events.orders', $this->event));
});

test('the sold quantity and sales count only paid orders', function () {
    expect($this->event->soldQuantity())->toBe(2)
        ->and($this->event->salesAmount())->toBe(6000)
        ->and($this->event->ticketTypes->first()->soldQuantity())->toBe(2)
        ->and($this->event->ticketTypes->first()->salesAmount())->toBe(6000);
});
