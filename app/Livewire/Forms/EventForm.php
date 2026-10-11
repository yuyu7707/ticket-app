<?php

namespace App\Livewire\Forms;

use App\Models\Event;
use Livewire\Form;

class EventForm extends Form
{
    public string $title = '';

    public string $description = '';

    public string $venue = '';

    public string $starts_at = '';

    public string $ends_at = '';

    /**
     * Fill the form with the given event's values.
     */
    public function setEvent(Event $event): void
    {
        $this->title = $event->title;
        $this->description = $event->description;
        $this->venue = $event->venue;
        $this->starts_at = $event->starts_at->format('Y-m-d\TH:i');
        $this->ends_at = $event->ends_at?->format('Y-m-d\TH:i') ?? '';
    }

    /**
     * Get the validated values as model attributes. An empty end becomes null.
     *
     * @return array{title: string, description: string, venue: string, starts_at: string, ends_at: string|null}
     */
    public function toAttributes(): array
    {
        $validated = $this->validate();

        $validated['ends_at'] = $validated['ends_at'] !== '' ? $validated['ends_at'] : null;

        return $validated;
    }

    /**
     * Get the validation rules for the event.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'venue' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
