<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Orders are visible to the buyer, the organizer of the event, and administrators.
     */
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id
            || $order->event->isOwnedBy($user)
            || $user->isAdmin();
    }
}
