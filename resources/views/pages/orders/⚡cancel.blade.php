<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Title('支払いの中断')] class extends Component {
    public Order $order;

    /**
     * Close the Stripe payment page first, then cancel the order to release its tickets.
     * If the payment page cannot be closed, the buyer may have paid already, so the order stays pending.
     */
    public function mount(Order $order, CheckoutService $checkoutService): void
    {
        $this->authorize('cancel', $order);

        if ($order->status === OrderStatus::Pending && $this->closePaymentPage($order, $checkoutService)) {
            Order::whereKey($order->id)
                ->where('status', OrderStatus::Pending)
                ->update(['status' => OrderStatus::Cancelled]);
        }

        $this->order = $order->refresh()->load('event');
    }

    /**
     * Expire the order's Checkout Session so it can no longer be paid.
     */
    private function closePaymentPage(Order $order, CheckoutService $checkoutService): bool
    {
        if ($order->stripe_checkout_session_id === null) {
            return true;
        }

        try {
            $checkoutService->expireSession($order->stripe_checkout_session_id);
        } catch (ApiErrorException $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    @switch($order->status)
        @case(OrderStatus::Cancelled)
            <flux:heading size="xl" level="1">支払いを中断しました</flux:heading>

            <flux:text>注文は取り消されました。料金は請求されていません。</flux:text>
            @break

        @case(OrderStatus::Paid)
            <flux:heading size="xl" level="1">支払いは完了しています</flux:heading>

            <flux:text>この注文はすでに支払い済みのため、中断されていません。</flux:text>
            @break

        @case(OrderStatus::Pending)
            <flux:heading size="xl" level="1">支払いの状況を確認できませんでした</flux:heading>

            <flux:text>しばらくしてから注文の状況をご確認ください。</flux:text>
            @break

        @default
            <flux:heading size="xl" level="1">この注文は中断できません</flux:heading>

            <flux:text>この注文は{{ $order->status->label() }}です。</flux:text>
    @endswitch

    <dl class="grid gap-2 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
        <dt class="font-medium text-zinc-500 dark:text-zinc-400">イベント</dt>
        <dd class="text-zinc-800 dark:text-white">{{ $order->event->title }}</dd>
    </dl>

    @if ($order->isPaid())
        <flux:link :href="route('orders.complete', $order)" wire:navigate>注文完了ページへ</flux:link>
    @else
        <flux:link :href="route('events.show', $order->event)" wire:navigate>イベント詳細へ戻る</flux:link>
    @endif
</section>
