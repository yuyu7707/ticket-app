<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Get the number of tickets left: capacity minus the held quantity.
     */
    public function remaining(): int
    {
        return max(0, $this->capacity - $this->heldQuantity());
    }

    /**
     * Get the number of tickets held by paid orders and pending orders created within the hold minutes.
     * A pending order can still be paid, so its tickets must stay within the capacity.
     */
    public function heldQuantity(): int
    {
        return (int) $this->orderItems()
            ->whereHas('order', function (Builder $query) {
                $query->where('status', OrderStatus::Paid)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', OrderStatus::Pending)
                        ->where('created_at', '>', now()->subMinutes(config('tickets.pending_hold_minutes'))));
            })
            ->sum('quantity');
    }

    /**
     * Scope a query to add the paid quantity and sales of each ticket type as sold_quantity and sales_amount.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withSales(Builder $query): void
    {
        $paid = fn (Builder $query) => $query->whereHas('order', fn (Builder $query) => $query->where('status', OrderStatus::Paid));

        $query->withSum(['orderItems as sold_quantity' => $paid], 'quantity')
            ->withSum(['orderItems as sales_amount' => $paid], DB::raw('unit_price * quantity'));
    }

    /**
     * Get the number of tickets sold in paid orders. Uses the value loaded by withSales() when present.
     */
    public function soldQuantity(): int
    {
        if (array_key_exists('sold_quantity', $this->attributes)) {
            return (int) $this->attributes['sold_quantity'];
        }

        return (int) $this->paidOrderItems()->sum('quantity');
    }

    /**
     * Get the sales in yen of paid orders at the prices when they were placed. Uses the value loaded by withSales() when present.
     */
    public function salesAmount(): int
    {
        if (array_key_exists('sales_amount', $this->attributes)) {
            return (int) $this->attributes['sales_amount'];
        }

        return (int) $this->paidOrderItems()->sum(DB::raw('unit_price * quantity'));
    }

    /**
     * Get the order items of the ticket type in paid orders.
     *
     * @return HasMany<OrderItem, $this>
     */
    private function paidOrderItems(): HasMany
    {
        return $this->orderItems()->whereHas('order', fn (Builder $query) => $query->where('status', OrderStatus::Paid));
    }

    /**
     * Orders other than pending and cancelled ones are records to keep, so the ticket type cannot be deleted while they exist.
     */
    public function hasOrdersBlockingDeletion(): bool
    {
        return $this->orderItems()
            ->whereHas('order', fn (Builder $query) => $query->whereNotIn('status', OrderStatus::discardable()))
            ->exists();
    }

    /**
     * Sales start immediately when no start is set, and end at the sales end or when the event starts, whichever is earlier.
     */
    public function isOnSale(): bool
    {
        $now = now();

        if ($this->sales_start_at !== null && $now->lt($this->sales_start_at)) {
            return false;
        }

        $salesEndAt = $this->sales_end_at !== null && $this->sales_end_at->lt($this->event->starts_at)
            ? $this->sales_end_at
            : $this->event->starts_at;

        return $now->lt($salesEndAt);
    }
}
