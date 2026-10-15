<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * Confirm the payment of an order when Stripe reports that its Checkout Session has completed.
     *
     * Stripe retries until it gets a 2xx response, so events that need no handling are acknowledged with 200.
     */
    public function __invoke(Request $request, CheckoutService $checkoutService, OrderService $orderService): Response
    {
        try {
            $event = $checkoutService->constructWebhookEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('', 400);
        }

        if ($event->type !== 'checkout.session.completed') {
            return response('', 200);
        }

        /** @var Session $session */
        $session = $event->data->object;

        if ($session->payment_status !== 'paid') {
            return response('', 200);
        }

        $order = Order::find((int) ($session->metadata['order_id'] ?? 0));

        if ($order === null || (! $orderService->markAsPaid($order, $session->payment_intent) && ! $order->isPaid())) {
            $this->logUnconfirmedPayment($session, $order);
        }

        return response('', 200);
    }

    /**
     * Record a payment Stripe has taken for an order that was deleted or cancelled meanwhile, so it can be refunded.
     * Stripe would only retry in vain, so the event is still acknowledged with 200.
     */
    private function logUnconfirmedPayment(Session $session, ?Order $order): void
    {
        Log::error('Stripe took a payment for an order that cannot be marked as paid. It needs a refund.', [
            'order_id' => $session->metadata['order_id'] ?? null,
            'order_status' => $order?->status->value,
            'checkout_session_id' => $session->id,
            'payment_intent_id' => $session->payment_intent,
        ]);
    }
}
