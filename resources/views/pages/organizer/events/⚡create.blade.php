<?php

use App\Livewire\Forms\EventForm;
use App\Models\Event;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント登録')] class extends Component {
    public EventForm $form;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Event::class);
    }

    /**
     * Save the event as a draft.
     */
    public function save(): void
    {
        $this->authorize('create', Event::class);

        $validated = $this->form->validate();

        $event = Auth::user()->events()->create($validated);

        Flux::toast(variant: 'success', text: '下書きとして保存しました。');

        $this->redirectRoute('organizer.events.edit', $event, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">イベント登録</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <x-pages::organizer.events.form-fields />

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit" data-test="save-event-button">下書きで保存</flux:button>
            <flux:button variant="ghost" :href="route('organizer.events.index')" wire:navigate>キャンセル</flux:button>
        </div>
    </form>
</section>
