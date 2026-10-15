<?php

use App\Enums\OrderStatus;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);

    $this->order = Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_123']);
    OrderItem::factory()->for($this->order)->create(['quantity' => 2]);
    OrderItem::factory()->for($this->order)->create(['quantity' => 1]);
});

/**
 * Post a Stripe event about the order's Checkout Session, signed the way Stripe signs webhooks.
 */
function postStripeWebhook(
    Order $order,
    string $secret = 'whsec_test',
    string $type = 'checkout.session.completed',
    string $paymentStatus = 'paid',
    ?string $orderId = null,
): TestResponse {
    $payload = json_encode([
        'id' => 'evt_test_123',
        'object' => 'event',
        'type' => $type,
        'data' => [
            'object' => [
                'id' => 'cs_test_123',
                'object' => 'checkout.session',
                'payment_status' => $paymentStatus,
                'payment_intent' => $paymentStatus === 'paid' ? 'pi_test_123' : null,
                'metadata' => ['order_id' => $orderId ?? (string) $order->id],
            ],
        ],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return test()->call('POST', route('stripe.webhook'), server: [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);
}

test('a correctly signed checkout.session.completed marks the order as paid and issues one ticket per quantity', function () {
    postStripeWebhook($this->order)->assertOk();

    $order = $this->order->fresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->stripe_payment_intent_id)->toBe('pi_test_123')
        ->and(Ticket::count())->toBe(3)
        ->and(Ticket::distinct()->count('code'))->toBe(3);
});

test('an invalid signature is rejected with 400 and changes nothing', function () {
    postStripeWebhook($this->order, secret: 'whsec_wrong')->assertBadRequest();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('the same webhook delivered twice does not issue the tickets twice', function () {
    Log::spy();

    postStripeWebhook($this->order)->assertOk();
    postStripeWebhook($this->order)->assertOk();

    expect(Ticket::count())->toBe(3);

    Log::shouldNotHaveReceived('error');
});

test('the buyer receives one purchase mail even when the webhook is delivered twice', function () {
    Mail::fake();

    postStripeWebhook($this->order)->assertOk();
    postStripeWebhook($this->order)->assertOk();

    Mail::assertSentCount(1);
    Mail::assertSent(OrderPaidMail::class, function (OrderPaidMail $mail) {
        $mail->assertSeeInHtml($this->order->event->title)
            ->assertSeeInHtml(route('orders.show', $this->order));

        return $mail->hasTo($this->order->user->email)
            && $mail->hasSubject("【まちチケ】チケットの購入が完了しました（{$this->order->event->title}）");
    });
});

test('an unpaid session sends no purchase mail', function () {
    Mail::fake();

    postStripeWebhook($this->order, paymentStatus: 'unpaid')->assertOk();

    Mail::assertNothingSent();
});

test('a completed session that is not paid yet leaves the order pending without tickets', function () {
    postStripeWebhook($this->order, paymentStatus: 'unpaid')->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('events other than checkout.session.completed are acknowledged with 200 and change nothing', function () {
    postStripeWebhook($this->order, type: 'checkout.session.expired')->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a payment for an order that no longer exists is logged for a refund and acknowledged with 200', function () {
    Log::spy();

    postStripeWebhook($this->order, orderId: '999999')->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['order_id'] === '999999'
        && $context['order_status'] === null
        && $context['payment_intent_id'] === 'pi_test_123');
});

test('a payment for a cancelled order is logged for a refund and leaves the order cancelled', function () {
    Log::spy();

    $this->order->forceFill(['status' => OrderStatus::Cancelled])->save();

    postStripeWebhook($this->order)->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Ticket::count())->toBe(0);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['order_id'] === (string) $this->order->id
        && $context['order_status'] === 'cancelled'
        && $context['checkout_session_id'] === 'cs_test_123');
});
