<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Exceptions;
use Stripe\Exception\InvalidRequestException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->published()->create(['starts_at' => now()->addMonth()]);
});

test('the buyer cancelling the payment closes the Stripe payment page, cancels the order and releases its tickets', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 5]);
    $order = Order::factory()->for($this->user)->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_123']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 2]);

    $this->mock(CheckoutService::class)->expects('expireSession')->with('cs_test_123');

    expect($ticketType->remaining())->toBe(3);

    $this->actingAs($this->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いを中断しました')
        ->assertSee($this->event->title);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($ticketType->remaining())->toBe(5);
});

test('the order stays pending when the Stripe payment page cannot be closed', function () {
    Exceptions::fake();

    $order = Order::factory()->for($this->user)->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)
        ->expects('expireSession')
        ->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of open can be expired.'));

    $this->actingAs($this->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いの状況を確認できませんでした')
        ->assertDontSee('Only Checkout Sessions');

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    Exceptions::assertReported(InvalidRequestException::class);
});

test('opening the cancel page of an order that is no longer pending changes nothing and leaves Stripe alone', function (OrderStatus $status, string $heading) {
    $order = Order::factory()->for($this->user)->for($this->event)->create([
        'status' => $status,
        'stripe_checkout_session_id' => 'cs_test_123',
    ]);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    $this->actingAs($this->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee($heading);

    expect($order->fresh()->status)->toBe($status);
})->with([
    'paid' => [OrderStatus::Paid, '支払いは完了しています'],
    'cancelled' => [OrderStatus::Cancelled, '支払いを中断しました'],
    'refunded' => [OrderStatus::Refunded, 'この注文は中断できません'],
]);

test('anyone but the buyer cannot cancel the order', function (callable $makeViewer) {
    $order = Order::factory()->for($this->user)->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    $this->actingAs($makeViewer($this->event))
        ->get(route('orders.cancel', $order))
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'other user' => [fn () => User::factory()->create()],
    'organizer' => [fn (Event $event) => $event->organizer],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('guests are redirected to the login page from the cancel page', function () {
    $order = Order::factory()->for($this->user)->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    $this->get(route('orders.cancel', $order))
        ->assertRedirect(route('login'));

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});
