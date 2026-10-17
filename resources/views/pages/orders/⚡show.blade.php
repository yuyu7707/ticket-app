<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文詳細')] class extends Component {
    public Order $order;

    /**
     * Only the buyer, the event organizer and administrators can open the order.
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event', 'items.ticketType', 'items.tickets');
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="xl" level="1">注文詳細</flux:heading>

        <flux:badge :color="$order->status->color()">{{ $order->status->label() }}</flux:badge>
    </div>

    @if ($order->status === OrderStatus::Pending)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="支払いが完了していません">
            <flux:callout.text>この注文はまだ支払われていないため、チケットは発行されていません。</flux:callout.text>
        </flux:callout>
    @endif

    <dl class="grid gap-2 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
        <dt class="font-medium text-zinc-500 dark:text-zinc-400">イベント</dt>
        <dd class="text-zinc-800 dark:text-white">
            <flux:link :href="route('events.show', $order->event)" wire:navigate>{{ $order->event->title }}</flux:link>
        </dd>

        <dt class="font-medium text-zinc-500 dark:text-zinc-400">日時</dt>
        <dd class="text-zinc-800 dark:text-white">{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</dd>

        <dt class="font-medium text-zinc-500 dark:text-zinc-400">会場</dt>
        <dd class="text-zinc-800 dark:text-white">{{ $order->event->venue }}</dd>

        <dt class="font-medium text-zinc-500 dark:text-zinc-400">注文日</dt>
        <dd class="text-zinc-800 dark:text-white">{{ $order->created_at->isoFormat('M月D日(ddd) HH:mm') }}</dd>
    </dl>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">明細</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>券種</flux:table.column>
                <flux:table.column align="end">単価</flux:table.column>
                <flux:table.column align="end">枚数</flux:table.column>
                <flux:table.column align="end">小計</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($order->items as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell variant="strong">{{ $item->ticketType->name }}</flux:table.cell>
                        <flux:table.cell align="end"><x-yen :amount="$item->unit_price" /></flux:table.cell>
                        <flux:table.cell align="end">{{ $item->quantity }}枚</flux:table.cell>
                        <flux:table.cell align="end"><x-yen :amount="$item->subtotal()" /></flux:table.cell>
                    </flux:table.row>
                @endforeach

                <flux:table.row>
                    <flux:table.cell variant="strong">合計</flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                    <flux:table.cell align="end">{{ $order->items->sum('quantity') }}枚</flux:table.cell>
                    <flux:table.cell align="end" variant="strong"><x-yen :amount="$order->total_amount" /></flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($order->isPaid())
        <div class="space-y-3">
            <flux:heading size="lg" level="2">チケット</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column>チケットコード</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($order->items as $item)
                        @foreach ($item->tickets as $ticket)
                            <flux:table.row :key="$ticket->id">
                                <flux:table.cell>{{ $item->ticketType->name }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $ticket->code }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    @if ($order->user_id === auth()->id())
        <flux:link :href="route('orders.index')" wire:navigate>マイチケットへ戻る</flux:link>
    @elseif (auth()->user()->can('viewOrders', $order->event))
        <flux:link :href="route('organizer.events.orders', $order->event)" wire:navigate>注文と売上へ戻る</flux:link>
    @endif
</section>
