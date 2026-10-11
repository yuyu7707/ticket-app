<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\QueryException;

test('new orders are pending', function () {
    $order = new Order(['total_amount' => 3000]);
    $order->user()->associate(User::factory()->create());
    $order->event()->associate(Event::factory()->published()->create());
    $order->save();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->isPaid())->toBeFalse();
});

test('order statuses have japanese labels', function (OrderStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    [OrderStatus::Pending, '支払い待ち'],
    [OrderStatus::Paid, '支払い済み'],
    [OrderStatus::Cancelled, 'キャンセル'],
    [OrderStatus::Refunded, '返金済み'],
]);

test('deleting an order deletes its items', function () {
    $item = OrderItem::factory()->create();

    $item->order->delete();

    expect(OrderItem::find($item->id))->toBeNull();
});

test('events with orders cannot be deleted', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->event->delete())->toThrow(QueryException::class);
});

test('ticket types that were ordered cannot be deleted', function () {
    $ticketType = TicketType::factory()->create();
    OrderItem::factory()->for($ticketType)->create();

    expect(fn () => $ticketType->delete())->toThrow(QueryException::class);
});
