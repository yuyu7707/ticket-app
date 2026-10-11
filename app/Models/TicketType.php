<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property int $price
 * @property int $capacity
 * @property Carbon|null $sales_start_at
 * @property Carbon|null $sales_end_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'price', 'capacity', 'sales_start_at', 'sales_end_at'])]
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    /**
     * Minutes a pending order keeps its tickets held.
     */
    public const PENDING_MINUTES = 15;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'capacity' => 'integer',
            'sales_start_at' => 'datetime',
            'sales_end_at' => 'datetime',
        ];
    }

    /**
     * Get the event the ticket type belongs to.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the order items that bought the ticket type.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the number of tickets left: capacity minus paid orders and pending orders created within PENDING_MINUTES.
     */
    public function remaining(): int
    {
        $held = (int) $this->orderItems()
            ->whereHas('order', function (Builder $query) {
                $query->where('status', OrderStatus::Paid)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', OrderStatus::Pending)
                        ->where('created_at', '>', now()->subMinutes(self::PENDING_MINUTES)));
            })
            ->sum('quantity');

        return max(0, $this->capacity - $held);
    }

    /**
     * Orders other than pending ones are records to keep, so the ticket type cannot be deleted while they exist.
     */
    public function hasOrdersBlockingDeletion(): bool
    {
        return $this->orderItems()
            ->whereHas('order', fn (Builder $query) => $query->where('status', '!=', OrderStatus::Pending))
            ->exists();
    }

    /**
     * Sales start immediately when no start is set, and end when the event starts when no end is set.
     */
    public function isOnSale(): bool
    {
        $now = now();

        if ($this->sales_start_at !== null && $now->lt($this->sales_start_at)) {
            return false;
        }

        return $now->lt($this->sales_end_at ?? $this->event->starts_at);
    }
}
