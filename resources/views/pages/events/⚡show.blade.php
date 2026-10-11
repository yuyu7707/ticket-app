<?php

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Event $event;

    /**
     * Drafts and unpublished events return 404 to anyone but the owner and administrators.
     */
    public function mount(Event $event): void
    {
        $this->authorize('view', $event);

        $this->event = $event->load('organizer');
    }

    /**
     * Get the ticket types of the event, cheapest first.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->orderBy('price')->get();
    }

    public function render(): View
    {
        return $this->view()->title($this->event->title);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <flux:link :href="route('home')" wire:navigate class="inline-flex items-center gap-1 text-sm">
        <flux:icon.arrow-left variant="micro" />
        イベント一覧へ戻る
    </flux:link>

    <div class="flex aspect-[3/1] items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-700">
        <flux:icon.photo class="size-12 text-zinc-400" />
    </div>

    <div class="space-y-4">
        @unless ($event->isPublished())
            <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
        @endunless

        <flux:heading size="xl" level="1">{{ $event->title }}</flux:heading>

        <dl class="grid gap-2 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
            <dt class="font-medium text-zinc-500 dark:text-zinc-400">日時</dt>
            <dd class="text-zinc-800 dark:text-white">
                {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                @if ($event->ends_at)
                    〜 {{ $event->ends_at->isSameDay($event->starts_at) ? $event->ends_at->isoFormat('HH:mm') : $event->ends_at->isoFormat('M月D日(ddd) HH:mm') }}
                @endif
            </dd>

            <dt class="font-medium text-zinc-500 dark:text-zinc-400">会場</dt>
            <dd class="text-zinc-800 dark:text-white">{{ $event->venue }}</dd>

            <dt class="font-medium text-zinc-500 dark:text-zinc-400">主催者</dt>
            <dd class="text-zinc-800 dark:text-white">{{ $event->organizer->name }}</dd>
        </dl>

        <flux:text class="whitespace-pre-line">{{ $event->description }}</flux:text>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg" level="2">券種</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種は準備中です。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column>価格</flux:table.column>
                    <flux:table.column>残り</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        @php($remaining = $ticketType->remaining())
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                            <flux:table.cell>{{ $ticketType->price === 0 ? '無料' : number_format($ticketType->price).'円' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($remaining === 0)
                                    <flux:badge size="sm" color="red">売り切れ</flux:badge>
                                @else
                                    残り{{ number_format($remaining) }}枚
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $ticketType->sales_start_at?->isoFormat('M月D日(ddd) HH:mm') ?? 'すぐ' }}
                                〜
                                {{ $ticketType->sales_end_at?->isoFormat('M月D日(ddd) HH:mm') ?? 'イベント開始まで' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
