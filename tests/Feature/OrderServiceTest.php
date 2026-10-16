<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\OrderService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->published()->create(['starts_at' => now()->addMonth()]);
});

test('creates a pending order with items at the current prices', function () {
    $general = TicketType::factory()->for($this->event)->create(['price' => 3000]);
    $student = TicketType::factory()->for($this->event)->create(['name' => '学生', 'price' => 1500]);

    $order = app(OrderService::class)->createOrder($this->user, $this->event, [
        $general->id => 2,
        $student->id => '1',
    ]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($this->user->id)
        ->and($order->event_id)->toBe($this->event->id)
        ->and($order->total_amount)->toBe(7500)
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->firstWhere('ticket_type_id', $general->id))
        ->quantity->toBe(2)
        ->unit_price->toBe(3000)
        ->and($order->items->firstWhere('ticket_type_id', $student->id))
        ->quantity->toBe(1)
        ->unit_price->toBe(1500);
});

test('fails without creating an order when not enough tickets remain', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 2]);

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => 3]))
        ->toThrow(PurchaseException::class, '一般は残り2枚です。');

    expect(Order::count())->toBe(0);
});

test('fails when the ticket type is not on sale', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['sales_end_at' => now()->subDay()]);

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => 1]))
        ->toThrow(PurchaseException::class, '一般は販売期間外です。');

    expect(Order::count())->toBe(0);
});

test('fails when more than the maximum quantity is requested', function () {
    $ticketType = TicketType::factory()->for($this->event)->create();

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => OrderService::MAX_QUANTITY + 1]))
        ->toThrow(PurchaseException::class, '一般は1〜10枚の範囲で選んでください。');

    expect(Order::count())->toBe(0);
});

test('fails without creating an order when the event is not published', function (EventStatus $status) {
    $this->event->forceFill(['status' => $status])->save();
    $ticketType = TicketType::factory()->for($this->event)->create();

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => 1]))
        ->toThrow(PurchaseException::class, 'このイベントは購入できません。');

    expect(Order::count())->toBe(0);
})->with([
    'draft' => [EventStatus::Draft],
    'unpublished' => [EventStatus::Unpublished],
]);

test('fails when the ticket type belongs to another event', function () {
    $otherTicketType = TicketType::factory()->for(Event::factory()->published()->create(['starts_at' => now()->addMonth()]))->create();

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$otherTicketType->id => 1]))
        ->toThrow(PurchaseException::class, '選択した券種が見つかりません。');

    expect(Order::count())->toBe(0);
});

test('fails when the quantity is not a positive whole number', function (string $quantity) {
    $ticketType = TicketType::factory()->for($this->event)->create();

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => $quantity]))
        ->toThrow(PurchaseException::class, '一般は1〜10枚の範囲で選んでください。');

    expect(Order::count())->toBe(0);
})->with([
    'negative' => ['-1'],
    'not a number' => ['abc'],
    'decimal' => ['1.5'],
]);

test('fails when every quantity is zero', function () {
    $ticketType = TicketType::factory()->for($this->event)->create();

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => 0]))
        ->toThrow(PurchaseException::class, '枚数を選んでください。');
});

test('marking an order that is no longer pending as paid changes nothing and issues no tickets', function (OrderStatus $status) {
    $order = Order::factory()->for($this->user)->for($this->event)->create(['status' => $status]);
    OrderItem::factory()->for($order)->create(['quantity' => 2]);

    expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeFalse()
        ->and($order->fresh()->status)->toBe($status)
        ->and(Ticket::count())->toBe(0);
})->with([
    'paid' => [OrderStatus::Paid],
    'cancelled' => [OrderStatus::Cancelled],
]);
