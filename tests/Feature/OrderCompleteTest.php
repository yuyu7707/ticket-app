<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;

beforeEach(function () {
    $this->order = Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_123']);
    OrderItem::factory()->for($this->order)->create(['quantity' => 2]);
});

test('the complete page confirms the order when Stripe reports the session as paid', function () {
    $this->mock(CheckoutService::class)
        ->expects('retrieveSession')
        ->with('cs_test_123')
        ->andReturn(Session::constructFrom(['payment_status' => 'paid', 'payment_intent' => 'pi_test_123']));

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', ['order' => $this->order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('購入が完了しました')
        ->assertDontSee('wire:poll', escape: false);

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->stripe_payment_intent_id->toBe('pi_test_123')
        ->and(Ticket::count())->toBe(2);
});

test('the complete page keeps checking while Stripe has not reported the payment yet', function () {
    $this->mock(CheckoutService::class)
        ->expects('retrieveSession')
        ->andReturn(Session::constructFrom(['payment_status' => 'unpaid', 'payment_intent' => null]));

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', ['order' => $this->order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('支払いを確認中です')
        ->assertSee('wire:poll', escape: false);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('the complete page does not ask Stripe when the session ID does not match the order', function () {
    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', ['order' => $this->order, 'session_id' => 'cs_test_other']))
        ->assertOk()
        ->assertSee('支払いを確認中です');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('the complete page does not ask Stripe again for an order that is already paid', function () {
    $this->order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', ['order' => $this->order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('購入が完了しました');
});

test('the complete page keeps checking and reports the error when Stripe cannot be reached', function () {
    Exceptions::fake();

    $this->mock(CheckoutService::class)
        ->expects('retrieveSession')
        ->andThrow(new ApiConnectionException('Could not connect to Stripe.'));

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', ['order' => $this->order, 'session_id' => 'cs_test_123']))
        ->assertOk()
        ->assertSee('支払いを確認中です')
        ->assertDontSee('Could not connect');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);

    Exceptions::assertReported(ApiConnectionException::class);
});

test('polling confirms the order once Stripe reports the session as paid', function () {
    $this->mock(CheckoutService::class)
        ->expects('retrieveSession')
        ->twice()
        ->with('cs_test_123')
        ->andReturn(
            Session::constructFrom(['payment_status' => 'unpaid', 'payment_intent' => null]),
            Session::constructFrom(['payment_status' => 'paid', 'payment_intent' => 'pi_test_123']),
        );

    $this->actingAs($this->order->user);

    Livewire::withQueryParams(['session_id' => 'cs_test_123'])
        ->test('pages::orders.complete', ['order' => $this->order])
        ->assertSee('支払いを確認中です')
        ->call('confirmPayment')
        ->assertSee('購入が完了しました');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::count())->toBe(2);
});
