<?php

namespace App\Livewire\Forms;

use App\Models\TicketType;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

class TicketTypeForm extends Form
{
    public string $name = '';

    /**
     * Livewire turns an emptied number input into null for int-typed properties, so null must be allowed.
     */
    public int|string|null $price = '';

    /**
     * Livewire turns an emptied number input into null for int-typed properties, so null must be allowed.
     */
    public int|string|null $capacity = '';

    public string $sales_start_at = '';

    public string $sales_end_at = '';

    /**
     * Fill the form with the given ticket type's values.
     */
    public function setTicketType(TicketType $ticketType): void
    {
        $this->name = $ticketType->name;
        $this->price = $ticketType->price;
        $this->capacity = $ticketType->capacity;
        $this->sales_start_at = $ticketType->sales_start_at?->format('Y-m-d\TH:i') ?? '';
        $this->sales_end_at = $ticketType->sales_end_at?->format('Y-m-d\TH:i') ?? '';
    }

    /**
     * Get the validated values as model attributes.
     *
     * @return array{name: string, price: int, capacity: int, sales_start_at: string|null, sales_end_at: string|null}
     */
    public function toAttributes(): array
    {
        $this->validate();

        return [
            'name' => $this->name,
            'price' => (int) $this->price,
            'capacity' => (int) $this->capacity,
            'sales_start_at' => $this->sales_start_at !== '' ? $this->sales_start_at : null,
            'sales_end_at' => $this->sales_end_at !== '' ? $this->sales_end_at : null,
        ];
    }

    /**
     * Get the validation rules for the ticket type.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $component = $this->getComponent();
        $eventStartsAt = $component->event->starts_at;
        $soldQuantity = $component->editingTicketTypeId !== null
            ? ($component->event->ticketTypes()->find($component->editingTicketTypeId)?->soldQuantity() ?? 0)
            : 0;

        return [
            'name' => ['required', 'string', 'max:50'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'capacity' => [
                'required',
                'integer',
                'min:1',
                'max:100000',
                function (string $attribute, mixed $value, Closure $fail) use ($soldQuantity) {
                    if ((int) $value < $soldQuantity) {
                        $fail("定員は販売済みの枚数（{$soldQuantity}枚）以上にしてください。");
                    }
                },
            ],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => [
                'nullable',
                'date',
                Rule::when($this->sales_start_at !== '', 'after:sales_start_at'),
                'before_or_equal:'.$eventStartsAt->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
