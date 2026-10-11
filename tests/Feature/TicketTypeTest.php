<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;

test('ticket types are on sale only within the sales period', function (string $now, ?string $salesStartAt, ?string $salesEndAt, bool $expected) {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $ticketType = TicketType::factory()->for($event)->create([
        'sales_start_at' => $salesStartAt,
        'sales_end_at' => $salesEndAt,
    ]);

    $this->travelTo($now);

    expect($ticketType->isOnSale())->toBe($expected);
})->with([
    'before the sales start' => ['2026-10-31 23:59', '2026-11-01 00:00', '2026-11-20 00:00', false],
    'at the sales start' => ['2026-11-01 00:00', '2026-11-01 00:00', '2026-11-20 00:00', true],
    'at the sales end' => ['2026-11-20 00:00', '2026-11-01 00:00', '2026-11-20 00:00', false],
    'no period before the event starts' => ['2026-11-30 12:00', null, null, true],
    'no end at the event start' => ['2026-12-01 10:00', '2026-11-01 00:00', null, false],
]);

test('remaining counts paid orders and pending orders within the hold time', function () {
    $this->travelTo('2026-11-01 12:00');

    $ticketType = TicketType::factory()->create(['capacity' => 100]);
    $order = fn (OrderStatus $status, string $createdAt) => Order::factory()
        ->for($ticketType->event)
        ->create(['status' => $status, 'created_at' => $createdAt]);

    OrderItem::factory()->for($order(OrderStatus::Paid, '2026-10-01 12:00'))->for($ticketType)->create(['quantity' => 3]);
    OrderItem::factory()->for($order(OrderStatus::Pending, '2026-11-01 11:50'))->for($ticketType)->create(['quantity' => 2]);
    OrderItem::factory()->for($order(OrderStatus::Pending, '2026-11-01 11:40'))->for($ticketType)->create(['quantity' => 4]);
    OrderItem::factory()->for($order(OrderStatus::Cancelled, '2026-11-01 11:59'))->for($ticketType)->create(['quantity' => 5]);
    OrderItem::factory()->for($order(OrderStatus::Paid, '2026-11-01 11:59'))->create(['quantity' => 6]);

    expect($ticketType->remaining())->toBe(95);
});

test('pending orders stop holding tickets after the hold time', function (string $now, int $expected) {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    $order = Order::factory()->for($ticketType->event)->create(['created_at' => '2026-11-01 12:00']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 4]);

    $this->travelTo($now);

    expect($ticketType->remaining())->toBe($expected);
})->with([
    'just before the hold time' => ['2026-11-01 12:14:59', 6],
    'at the hold time' => ['2026-11-01 12:15:00', 10],
]);

test('remaining never goes below zero', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 2]);
    OrderItem::factory()
        ->for(Order::factory()->paid()->for($ticketType->event))
        ->for($ticketType)
        ->create(['quantity' => 3]);

    expect($ticketType->remaining())->toBe(0);
});

test('refunded orders do not hold tickets', function () {
    $this->travelTo('2026-11-01 12:00');

    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    $order = Order::factory()
        ->for($ticketType->event)
        ->create(['status' => OrderStatus::Refunded, 'created_at' => '2026-11-01 11:59']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 4]);

    expect($ticketType->remaining())->toBe(10);
});
