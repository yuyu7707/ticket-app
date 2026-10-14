<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * Get the statuses of orders that hold no record worth keeping and can be deleted along with their event, ticket type or user.
     *
     * @return list<self>
     */
    public static function discardable(): array
    {
        return [self::Pending, self::Cancelled];
    }

    /**
     * Get the Japanese label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => '支払い待ち',
            self::Paid => '支払い済み',
            self::Cancelled => 'キャンセル',
            self::Refunded => '返金済み',
        };
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Paid => 'green',
            self::Cancelled => 'zinc',
            self::Refunded => 'red',
        };
    }
}
