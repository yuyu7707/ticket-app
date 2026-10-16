<?php

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('マイチケット')] class extends Component {
    use WithPagination;

    /**
     * Get the current user's orders, newest first.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return Auth::user()->orders()
            ->with('event')
            ->withSum('items', 'quantity')
            ->latest()
            ->latest('id')
            ->paginate(10);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">マイチケット</flux:heading>

    @if ($this->orders->isEmpty())
        <flux:text>まだ注文がありません。</flux:text>

        <flux:link :href="route('home')" wire:navigate>イベント一覧を見る</flux:link>
    @else
        <flux:table :paginate="$this->orders">
            <flux:table.columns>
                <flux:table.column>注文日</flux:table.column>
                <flux:table.column>イベント</flux:table.column>
                <flux:table.column>開催日</flux:table.column>
                <flux:table.column align="end">枚数</flux:table.column>
                <flux:table.column align="end">合計</flux:table.column>
                <flux:table.column>状態</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->orders as $order)
                    <flux:table.row :key="$order->id">
                        <flux:table.cell>{{ $order->created_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell variant="strong">{{ $order->event->title }}</flux:table.cell>
                        <flux:table.cell>{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $order->items_sum_quantity }}枚</flux:table.cell>
                        <flux:table.cell align="end"><x-yen :amount="$order->total_amount" /></flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$order->status->color()">{{ $order->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" :href="route('orders.show', $order)" wire:navigate>
                                詳細
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
