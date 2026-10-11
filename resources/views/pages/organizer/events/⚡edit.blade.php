<?php

use App\Enums\EventStatus;
use App\Livewire\Forms\EventForm;
use App\Models\Event;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント編集')] class extends Component {
    #[Locked]
    public Event $event;

    public EventForm $form;

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

        $this->event->update($this->form->validate());

        Flux::toast(variant: 'success', text: '保存しました。');
    }

    /**
     * Switch the event between draft and published.
     */
    public function togglePublish(): void
    {
        $this->authorize('publish', $this->event);

        $this->event->status = $this->event->isPublished() ? EventStatus::Draft : EventStatus::Published;

        $this->event->save();

        Flux::toast(
            variant: 'success',
            text: $this->event->isPublished() ? '公開しました。' : '下書きに戻しました。',
        );
    }

    /**
     * Delete the event.
     */
    public function deleteEvent(): void
    {
        $this->authorize('delete', $this->event);

        $this->event->delete();

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
