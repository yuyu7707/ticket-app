<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Mail\OrderPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class OrderService
{
    /**
     * Maximum number of tickets per ticket type in one order.
     */
    public const MAX_QUANTITY = 10;

    /**
     * Create a pending order and its items at the current ticket prices.
     *
     * @param  array<int|string, mixed>  $quantities  Quantities keyed by ticket type ID. Zero means not selected.
     *
     * @throws PurchaseException
     */
    public function createOrder(User $user, Event $event, array $quantities): Order
    {
        if (! $event->isPublished()) {
            throw new PurchaseException('このイベントは購入できません。');
        }

        $quantities = array_filter(
            array_map(fn (mixed $quantity) => filter_var($quantity, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE), $quantities),
            fn (?int $quantity) => $quantity !== 0,
        );

        if ($quantities === []) {
            throw new PurchaseException('枚数を選んでください。');
        }

        return DB::transaction(function () use ($user, $event, $quantities) {
            $ticketTypes = $event->ticketTypes()
                ->whereKey(array_keys($quantities))
                ->lockForUpdate()
                ->get()
                ->each->setRelation('event', $event);

            if ($ticketTypes->count() !== count($quantities)) {
                throw new PurchaseException('選択した券種が見つかりません。');
            }

            foreach ($ticketTypes as $ticketType) {
                $this->ensurePurchasable($ticketType, $quantities[$ticketType->id]);
            }

            $order = new Order([
                'total_amount' => $ticketTypes->sum(fn (TicketType $ticketType) => $ticketType->price * $quantities[$ticketType->id]),
            ]);
            $order->user()->associate($user);
            $order->event()->associate($event);
            $order->save();

            foreach ($ticketTypes as $ticketType) {
                $order->items()->create([
                    'ticket_type_id' => $ticketType->id,
                    'quantity' => $quantities[$ticketType->id],
                    'unit_price' => $ticketType->price,
                ]);
            }

            return $order;
        });
    }

    /**
     * Mark a pending order as paid and issue one ticket per quantity of each item.
     *
     * The webhook and the complete page may both confirm the same payment at the same time, so only the
     * request whose conditional update changes the pending row issues the tickets.
     *
     * @return bool Whether the order was marked as paid by this call. False when it was no longer pending.
     */
    public function markAsPaid(Order $order, ?string $paymentIntentId): bool
    {
        $marked = DB::transaction(function () use ($order, $paymentIntentId) {
            $updated = Order::whereKey($order->id)
                ->where('status', OrderStatus::Pending)
                ->update([
                    'status' => OrderStatus::Paid,
                    'paid_at' => now(),
                    'stripe_payment_intent_id' => $paymentIntentId,
                ]);

            if ($updated === 0) {
                return false;
            }

            foreach ($order->items()->get() as $item) {
                $item->tickets()->createMany(
                    array_map(fn () => ['code' => (string) Str::ulid()], range(1, $item->quantity)),
                );
            }

            return true;
        });

        $order->refresh();

        if ($marked) {
            $this->sendPaidMail($order);
        }

        return $marked;
    }

    /**
     * The payment is already confirmed, so a mail failure is reported without failing the webhook or the page.
     */
    private function sendPaidMail(Order $order): void
    {
        try {
            Mail::to($order->user)->send(new OrderPaidMail($order));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Livewire properties can be tampered with, so the quantity is checked here as well as on screen.
     *
     * @throws PurchaseException
     */
    private function ensurePurchasable(TicketType $ticketType, ?int $quantity): void
    {
        if ($quantity === null || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new PurchaseException("{$ticketType->name}は1〜".self::MAX_QUANTITY.'枚の範囲で選んでください。');
        }

        if (! $ticketType->isOnSale()) {
            throw new PurchaseException("{$ticketType->name}は販売期間外です。");
        }

        $remaining = $ticketType->remaining();

        if ($remaining < $quantity) {
            throw new PurchaseException("{$ticketType->name}は残り{$remaining}枚です。");
        }
    }
}
