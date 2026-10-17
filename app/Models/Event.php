<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organizer_id
 * @property string $title
 * @property string $description
 * @property string $venue
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property EventStatus $status
 * @property string|null $image_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'description', 'venue', 'starts_at', 'ends_at'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => EventStatus::Draft->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    /**
     * Get the organizer who owns the event.
     *
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * Get the ticket types of the event.
     *
     * @return HasMany<TicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /**
     * Get the orders placed for the event.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the order items of all orders placed for the event.
     *
     * @return HasManyThrough<OrderItem, Order, $this>
     */
    public function orderItems(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, Order::class);
    }

    /**
     * Scope a query to add the paid quantity and sales of each event as sold_quantity and sales_amount.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withSales(Builder $query): void
    {
        $query->withSum(['orderItems as sold_quantity' => fn (Builder $query) => $query->where('orders.status', OrderStatus::Paid)], 'quantity')
            ->withSum(['orders as sales_amount' => fn (Builder $query) => $query->where('status', OrderStatus::Paid)], 'total_amount');
    }

    /**
     * Scope a query to only include published events.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    /**
     * Scope a query to events that have not started yet, nearest first.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now())->orderBy('starts_at');
    }

    /**
     * Scope a query to events that have not ended yet. Events without an end are treated as ending when they start.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notEnded(Builder $query): void
    {
        $now = now();

        $query->where(function (Builder $query) use ($now) {
            $query->where('ends_at', '>', $now)
                ->orWhere(fn (Builder $query) => $query->whereNull('ends_at')->where('starts_at', '>', $now));
        });
    }

    /**
     * Determine if the event is published.
     */
    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }

    /**
     * Determine if the event has at least one ticket type.
     */
    public function hasTicketTypes(): bool
    {
        return $this->ticketTypes()->exists();
    }

    /**
     * Orders other than pending and cancelled ones are records to keep, so the event cannot be deleted while they exist.
     */
    public function hasOrdersBlockingDeletion(): bool
    {
        return $this->orders()->whereNotIn('status', OrderStatus::discardable())->exists();
    }

    /**
     * Get the number of tickets sold in paid orders. Uses the value loaded by withSales() when present.
     */
    public function soldQuantity(): int
    {
        if (array_key_exists('sold_quantity', $this->attributes)) {
            return (int) $this->attributes['sold_quantity'];
        }

        return (int) $this->orderItems()->where('orders.status', OrderStatus::Paid)->sum('quantity');
    }

    /**
     * Get the sales in yen of paid orders. Uses the value loaded by withSales() when present.
     */
    public function salesAmount(): int
    {
        if (array_key_exists('sales_amount', $this->attributes)) {
            return (int) $this->attributes['sales_amount'];
        }

        return (int) $this->orders()->where('status', OrderStatus::Paid)->sum('total_amount');
    }

    /**
     * Determine if the given user owns the event.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }
}
