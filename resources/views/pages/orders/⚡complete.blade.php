<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Title('注文完了')] class extends Component {
    public Order $order;

    /**
     * The Checkout Session ID that Stripe appends to the success URL.
     */
    #[Locked]
    public ?string $sessionId = null;

    /**
     * Only the buyer, the event organizer and administrators can open the order.
     */
    public function mount(Order $order, CheckoutService $checkoutService, OrderService $orderService): void
    {
        $this->authorize('view', $order);

        $this->order = $order;
        $this->sessionId = request()->query('session_id');

        $this->confirmPayment($checkoutService, $orderService);
    }

    /**
     * Ask Stripe whether the order's Checkout Session has been paid, in case the webhook has not arrived yet.
     * Polled while the order is pending.
     */
    public function confirmPayment(CheckoutService $checkoutService, OrderService $orderService): void
    {
        $this->order->refresh();

        if ($this->order->status === OrderStatus::Pending
            && $this->sessionId !== null
            && $this->sessionId === $this->order->stripe_checkout_session_id) {
            try {
                $session = $checkoutService->retrieveSession($this->sessionId);

                if ($session->payment_status === 'paid') {
                    $orderService->markAsPaid($this->order, $session->payment_intent);
                }
            } catch (ApiErrorException $exception) {
                report($exception);
            }
        }

        $this->order->load('event', 'items.ticketType');
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    @switch($order->status)
        @case(OrderStatus::Paid)
            <flux:heading size="xl" level="1">購入が完了しました</flux:heading>

            <flux:text>チケットを発行しました。</flux:text>
            @break

        @case(OrderStatus::Pending)
            <div wire:poll.5s="confirmPayment" class="space-y-2">
                <flux:heading size="xl" level="1">支払いを確認中です</flux:heading>

                <flux:text>確認が終わるとこのページが自動で切り替わります。しばらくお待ちください。</flux:text>
            </div>
            @break

        @default
            <flux:heading size="xl" level="1">この注文は{{ $order->status->label() }}です</flux:heading>
    @endswitch

    <dl class="grid gap-2 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
        <dt class="font-medium text-zinc-500 dark:text-zinc-400">イベント</dt>
        <dd class="text-zinc-800 dark:text-white">{{ $order->event->title }}</dd>

        <dt class="font-medium text-zinc-500 dark:text-zinc-400">日時</dt>
        <dd class="text-zinc-800 dark:text-white">{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</dd>

        <dt class="font-medium text-zinc-500 dark:text-zinc-400">券種</dt>
        <dd class="text-zinc-800 dark:text-white">
            <ul>
                @foreach ($order->items as $item)
                    <li wire:key="item-{{ $item->id }}">{{ $item->ticketType->name }} × {{ $item->quantity }}枚</li>
                @endforeach
            </ul>
        </dd>

        <dt class="font-medium text-zinc-500 dark:text-zinc-400">合計金額</dt>
        <dd class="text-zinc-800 dark:text-white"><x-yen :amount="$order->total_amount" /></dd>
    </dl>

    <flux:link :href="route('events.show', $order->event)" wire:navigate>イベント詳細へ戻る</flux:link>
</section>
