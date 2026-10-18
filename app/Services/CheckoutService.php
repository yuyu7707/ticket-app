<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class CheckoutService
{
    public function __construct(private StripeClient $stripe) {}

    /**
     * Create a Checkout Session for the order, remember its ID on the order and return the payment page URL.
     *
     * The payment page closes 30 seconds before the order stops holding its tickets, so nobody can pay for
     * tickets that have already been released. Stripe needs at least 30 minutes, hence the hold of 31 minutes.
     *
     * Stripe no longer accepts payment_method_types, so the payment methods come from the Stripe Dashboard.
     * Only cards should be turned on there, because delayed methods such as konbini are paid after the hold ends.
     *
     * @throws ApiErrorException
     */
    public function createSession(Order $order): string
    {
        $order->loadMissing('event', 'items.ticketType');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $order->items->map(fn (OrderItem $item) => [
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => $item->unit_price,
                    'product_data' => [
                        'name' => "{$order->event->title} {$item->ticketType->name}",
                    ],
                ],
                'quantity' => $item->quantity,
            ])->all(),
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
            // Stripe replaces the placeholder, so it is appended as is instead of being URL-encoded by route().
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
            'expires_at' => now()->addMinutes((int) config('tickets.pending_hold_minutes'))->subSeconds(30)->getTimestamp(),
        ]);

        $order->update(['stripe_checkout_session_id' => $session->id]);

        return (string) $session->url;
    }

    /**
     * Expire a Checkout Session so its payment page can no longer be paid. Fails when the session is no longer open.
     *
     * @throws ApiErrorException
     */
    public function expireSession(string $id): void
    {
        $this->stripe->checkout->sessions->expire($id);
    }

    /**
     * Retrieve a Checkout Session by its ID.
     *
     * @throws ApiErrorException
     */
    public function retrieveSession(string $id): Session
    {
        return $this->stripe->checkout->sessions->retrieve($id);
    }

    /**
     * Verify the signature of a webhook request with the webhook secret and build the Stripe event from it.
     *
     * @throws SignatureVerificationException
     * @throws UnexpectedValueException
     */
    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        return Webhook::constructEvent($payload, $signature, (string) config('services.stripe.webhook_secret'));
    }
}
