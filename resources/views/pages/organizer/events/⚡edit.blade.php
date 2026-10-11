<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Livewire\Forms\EventForm;
use App\Livewire\Forms\TicketTypeForm;
use App\Models\Event;
use App\Models\TicketType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント編集')] class extends Component {
    #[Locked]
    public Event $event;

    public EventForm $form;

    public TicketTypeForm $ticketTypeForm;

    #[Locked]
    public ?int $editingTicketTypeId = null;

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('update', $event);

        $this->event = $event;
        $this->form->setEvent($event);
    }

    /**
     * Update the event.
     */
    public function save(): void
    {
        $this->authorize('update', $this->event);

        $this->event->update($this->form->toAttributes());

        Flux::toast(variant: 'success', text: '保存しました。');
    }

    /**
     * Switch the event between draft and published.
     */
    public function togglePublish(): void
    {
        $this->authorize('publish', $this->event);

        if (! $this->event->isPublished() && ! $this->event->hasTicketTypes()) {
            Flux::toast(variant: 'danger', text: '券種を1つ以上登録してください。');

            return;
        }

        $this->event->status = $this->event->isPublished() ? EventStatus::Draft : EventStatus::Published;

        $this->event->save();

        Flux::toast(
            variant: 'success',
            text: $this->event->isPublished() ? '公開しました。' : '下書きに戻しました。',
        );
    }

    /**
     * Get the ticket types of the event.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->oldest('id')->get();
    }

    /**
     * Fill the ticket type form with the given ticket type for editing.
     */
    public function editTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        $this->ticketTypeForm->resetValidation();
        $this->ticketTypeForm->setTicketType($ticketType);
        $this->editingTicketTypeId = $ticketType->id;
    }

    /**
     * Create a ticket type, or update the one being edited.
     */
    public function saveTicketType(): void
    {
        $this->authorize('update', $this->event);

        $attributes = $this->ticketTypeForm->toAttributes();

        if ($this->editingTicketTypeId !== null) {
            $this->event->ticketTypes()->findOrFail($this->editingTicketTypeId)->update($attributes);

            Flux::toast(variant: 'success', text: '券種を更新しました。');
        } else {
            $this->event->ticketTypes()->create($attributes);

            Flux::toast(variant: 'success', text: '券種を追加しました。');
        }

        $this->cancelTicketTypeEdit();
    }

    /**
     * Clear the ticket type form and leave edit mode.
     */
    public function cancelTicketTypeEdit(): void
    {
        $this->ticketTypeForm->reset();
        $this->ticketTypeForm->resetValidation();
        $this->editingTicketTypeId = null;
    }

    /**
     * Delete the given ticket type along with its pending orders. A published event must keep at least one ticket type,
     * and ticket types with orders to keep cannot be deleted.
     */
    public function deleteTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        if ($this->event->isPublished() && $this->event->ticketTypes()->count() === 1) {
            Flux::toast(variant: 'danger', text: '公開中のイベントは券種を0件にできません。');

            return;
        }

        if ($ticketType->hasOrdersBlockingDeletion()) {
            Flux::toast(variant: 'danger', text: '支払い済みの注文があるため削除できません。');

            return;
        }

        DB::transaction(function () use ($ticketType) {
            $this->event->orders()
                ->where('status', OrderStatus::Pending)
                ->whereHas('items', fn (Builder $query) => $query->where('ticket_type_id', $ticketType->id))
                ->delete();
            $ticketType->delete();
        });

        if ($this->editingTicketTypeId === $ticketType->id) {
            $this->cancelTicketTypeEdit();
        }

        Flux::toast(variant: 'success', text: '券種を削除しました。');
    }

    /**
     * Delete the event along with its pending orders, unless it has orders that must be kept.
     */
    public function deleteEvent(): void
    {
        $this->authorize('delete', $this->event);

        if ($this->event->hasOrdersBlockingDeletion()) {
            Flux::toast(variant: 'danger', text: '支払い済みの注文があるため削除できません。');

            return;
        }

        DB::transaction(function () {
            $this->event->orders()->where('status', OrderStatus::Pending)->delete();
            $this->event->delete();
        });

        Flux::toast(variant: 'success', text: 'イベントを削除しました。');

        $this->redirectRoute('organizer.events.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:heading size="xl" level="1">イベント編集</flux:heading>
            <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
        </div>

        @can('publish', $event)
            <flux:button wire:click="togglePublish" :variant="$event->isPublished() ? 'filled' : 'primary'" data-test="toggle-publish-button">
                {{ $event->isPublished() ? '下書きに戻す' : '公開する' }}
            </flux:button>
        @else
            <flux:text>管理者により非公開になっています。</flux:text>
        @endcan
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-pages::organizer.events.form-fields />

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit" data-test="save-event-button">保存</flux:button>
            <flux:button variant="ghost" :href="route('organizer.events.index')" wire:navigate>一覧に戻る</flux:button>
        </div>
    </form>

    <flux:separator />

    <div class="space-y-4">
        <flux:heading size="lg">券種</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種がまだありません。公開するには1つ以上登録してください。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種名</flux:table.column>
                    <flux:table.column align="end">価格</flux:table.column>
                    <flux:table.column align="end">定員</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                            <flux:table.cell align="end"><x-yen :amount="$ticketType->price" /></flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($ticketType->capacity) }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $ticketType->sales_start_at?->isoFormat('M月D日(ddd) HH:mm') ?? 'すぐ' }}
                                〜
                                {{ $ticketType->sales_end_at?->isoFormat('M月D日(ddd) HH:mm') ?? 'イベント開始まで' }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" icon="pencil-square" wire:click="editTicketType({{ $ticketType->id }})">編集</flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        icon="trash"
                                        wire:click="deleteTicketType({{ $ticketType->id }})"
                                        wire:confirm="「{{ $ticketType->name }}」を削除しますか？"
                                    >
                                        削除
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        <form wire:submit="saveTicketType" class="space-y-6 rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
            <flux:heading>{{ $editingTicketTypeId === null ? '券種を追加' : '券種を編集' }}</flux:heading>

            <flux:input wire:model="ticketTypeForm.name" label="券種名" type="text" required maxlength="50" placeholder="一般、学生 など" />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="ticketTypeForm.price" label="価格（円）" type="number" required min="0" max="1000000" step="1" />

                <flux:input wire:model="ticketTypeForm.capacity" label="定員" type="number" required min="1" max="100000" step="1" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="ticketTypeForm.sales_start_at" label="販売開始" type="datetime-local" description="空欄ならすぐに販売します。" />

                <flux:input wire:model="ticketTypeForm.sales_end_at" label="販売終了" type="datetime-local" description="空欄ならイベント開始まで販売します。" />
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="save-ticket-type-button">
                    {{ $editingTicketTypeId === null ? '追加' : '更新' }}
                </flux:button>

                @if ($editingTicketTypeId !== null)
                    <flux:button variant="ghost" wire:click="cancelTicketTypeEdit">キャンセル</flux:button>
                @endif
            </div>
        </form>
    </div>

    <flux:separator />

    <div class="space-y-4">
        <div>
            <flux:heading size="lg">イベントの削除</flux:heading>
            <flux:subheading>削除すると元に戻せません。</flux:subheading>
        </div>

        <flux:modal.trigger name="confirm-event-deletion">
            <flux:button variant="danger" icon="trash">削除する</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="confirm-event-deletion" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">「{{ $event->title }}」を削除しますか？</flux:heading>
                <flux:subheading>削除すると元に戻せません。</flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">キャンセル</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteEvent" data-test="confirm-delete-event-button">削除する</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
