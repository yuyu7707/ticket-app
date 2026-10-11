<?php

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Minutes a pending order keeps its tickets held.
     */
    public const PENDING_MINUTES = TicketType::PENDING_MINUTES;

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
