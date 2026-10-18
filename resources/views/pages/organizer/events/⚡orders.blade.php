<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('注文と売上')] class extends Component {
    use WithPagination;

    #[Locked]
    public Event $event;

    /**
     * Only the owner and administrators can see the buyers and sales of the event.
     */
    public function mount(Event $event): void
    {
        $this->authorize('viewOrders', $event);

        $this->event = $event;
    }

    /**
     * Get the ticket types with their paid quantity and sales.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->withSales()->oldest('id')->get();
    }

    /**
     * Get the number of paid orders.
     */
    #[Computed]
    public function paidOrdersCount(): int
    {
        return $this->event->orders()->where('status', OrderStatus::Paid)->count();
    }

    /**
     * Get the paid orders, most recently paid first.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->event->orders()
            ->where('status', OrderStatus::Paid)
            ->with('user', 'items.ticketType')
            ->latest('paid_at')
            ->latest('id')
            ->paginate(10);
    }
}; ?>

<section class="w-full space-y-8">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">注文と売上</flux:heading>
        <flux:text>{{ $event->title }}（{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}）</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>売上合計</flux:text>
            <flux:heading size="xl"><x-yen :amount="$this->ticketTypes->sum(fn (TicketType $ticketType) => $ticketType->salesAmount())" /></flux:heading>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>販売枚数</flux:text>
            <flux:heading size="xl">{{ number_format($this->ticketTypes->sum(fn (TicketType $ticketType) => $ticketType->soldQuantity())) }}枚</flux:heading>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>注文数</flux:text>
            <flux:heading size="xl">{{ number_format($this->paidOrdersCount) }}件</flux:heading>
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">券種ごとの販売状況</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種がまだありません。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column align="end">販売枚数 / 定員</flux:table.column>
                    <flux:table.column align="end">売上</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($ticketType->soldQuantity()) }} / {{ number_format($ticketType->capacity) }}枚</flux:table.cell>
                            <flux:table.cell align="end"><x-yen :amount="$ticketType->salesAmount()" /></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">支払い済みの注文</flux:heading>

        @if ($this->orders->isEmpty())
            <flux:text>支払い済みの注文はまだありません。</flux:text>
        @else
            <flux:table :paginate="$this->orders">
                <flux:table.columns>
                    <flux:table.column>支払い日時</flux:table.column>
                    <flux:table.column>購入者</flux:table.column>
                    <flux:table.column>内容</flux:table.column>
                    <flux:table.column align="end">合計</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->orders as $order)
                        <flux:table.row :key="$order->id">
                            <flux:table.cell>{{ $order->paid_at?->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $order->user->name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $order->user->email }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $order->items->map(fn ($item) => "{$item->ticketType->name} × {$item->quantity}枚")->join('、') }}
                            </flux:table.cell>
                            <flux:table.cell align="end"><x-yen :amount="$order->total_amount" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" :href="route('orders.show', $order)" wire:navigate>詳細</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    @can('update', $event)
        <flux:link :href="route('organizer.events.index')" wire:navigate>イベント管理へ戻る</flux:link>
    @endcan
</section>
