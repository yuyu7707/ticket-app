<?php

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('イベント一覧')] class extends Component {
    use WithPagination;

    /**
     * Get the published events that have not ended, nearest first, with their lowest ticket price.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        return Event::published()
            ->notEnded()
            ->orderBy('starts_at')
            ->orderBy('id')
            ->withMin('ticketTypes', 'price')
            ->paginate(12);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">イベント一覧</flux:heading>

    @if ($this->events->isEmpty())
        <flux:text>公開中のイベントはありません。</flux:text>
    @else
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->events as $event)
                <a wire:key="event-{{ $event->id }}" href="{{ route('events.show', $event) }}" wire:navigate class="group block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                    <flux:card class="flex h-full flex-col gap-4 p-0 overflow-hidden transition group-hover:border-zinc-400 dark:group-hover:border-zinc-500">
                        <div class="flex aspect-video items-center justify-center bg-zinc-100 dark:bg-zinc-700">
                            <flux:icon.photo class="size-10 text-zinc-400" />
                        </div>

                        <div class="flex flex-1 flex-col gap-2 px-4 pb-4">
                            <flux:heading size="lg" level="2">{{ $event->title }}</flux:heading>

                            <flux:text class="flex items-center gap-1.5">
                                <flux:icon.calendar-days variant="micro" />
                                {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                            </flux:text>

                            <flux:text class="flex items-center gap-1.5">
                                <flux:icon.map-pin variant="micro" />
                                {{ $event->venue }}
                            </flux:text>

                            @if ($event->ticket_types_min_price !== null)
                                <flux:text variant="strong" class="mt-auto pt-2 text-lg font-semibold">
                                    {{ (int) $event->ticket_types_min_price === 0 ? '無料' : number_format($event->ticket_types_min_price).'円〜' }}
                                </flux:text>
                            @endif
                        </div>
                    </flux:card>
                </a>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->events" />
    @endif
</section>
