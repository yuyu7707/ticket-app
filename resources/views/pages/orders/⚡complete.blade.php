<?php

use App\Models\Order;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文完了')] class extends Component {
    public Order $order;

    /**
     * Only the buyer, the event organizer and administrators can open the order.
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">注文を受け付けました（支払いは平日課題で実装）</flux:heading>

    <dl class="grid gap-2 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
        <dt class="font-medium text-zinc-500 dark:text-zinc-400">イベント</dt>
        <dd class="text-zinc-800 dark:text-white">{{ $order->event->title }}</dd>

        <dt class="font-medium text-zinc-500 dark:text-zinc-400">合計金額</dt>
        <dd class="text-zinc-800 dark:text-white">{{ number_format($order->total_amount) }}円</dd>
    </dl>

    <flux:link :href="route('events.show', $order->event)" wire:navigate>イベント詳細へ戻る</flux:link>
</section>
