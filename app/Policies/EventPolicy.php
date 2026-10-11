<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * Published events are public; drafts and unpublished events are visible only to the owner and administrators.
     */
    public function view(?User $user, Event $event): bool
    {
        if ($event->isPublished()) {
            return true;
        }

        return $user !== null && ($user->isAdmin() || $event->isOwnedBy($user));
    }

    /**
     * Determine whether the user can create events.
     */
    public function create(User $user): bool
    {
        return $user->isOrganizer();
    }

    /**
     * Determine whether the user can update the event.
     */
    public function update(User $user, Event $event): bool
    {
        return $event->isOwnedBy($user);
    }

    /**
     * Owners can switch between draft and published unless an administrator has unpublished the event.
     */
    public function publish(User $user, Event $event): bool
    {
        return $event->isOwnedBy($user) && $event->status !== EventStatus::Unpublished;
    }

    /**
     * Determine whether the user can delete the event.
     */
    public function delete(User $user, Event $event): bool
    {
        return $event->isOwnedBy($user);
    }

    /**
     * Determine whether the user can unpublish the event.
     */
    public function unpublish(User $user, Event $event): bool
    {
        return $user->isAdmin();
    }
}
