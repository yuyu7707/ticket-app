<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EventPolicy
{
    /**
     * Only organizers have a list of their own events.
     */
    public function viewAny(User $user): bool
    {
        return $user->isOrganizer();
    }

    /**
     * Published events are public; drafts and unpublished events are visible only to the owner and administrators.
     */
    public function view(?User $user, Event $event): Response
    {
        if ($event->isPublished()) {
            return Response::allow();
        }

        return $user !== null && ($user->isAdmin() || $event->isOwnedBy($user))
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Only published events can be bought, even by the owner and administrators.
     */
    public function purchase(?User $user, Event $event): Response
    {
        return $event->isPublished() ? Response::allow() : Response::denyAsNotFound();
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
     * The orders and sales of an event, including the buyers' names and emails, are visible only to the owner and administrators.
     */
    public function viewOrders(User $user, Event $event): bool
    {
        return $event->isOwnedBy($user) || $user->isAdmin();
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
