<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('orders can be viewed by the buyer, the event organizer and admins', function () {
    $order = Order::factory()->create();

    expect(Gate::forUser($order->user)->allows('view', $order))->toBeTrue();
    expect(Gate::forUser($order->event->organizer)->allows('view', $order))->toBeTrue();
    expect(Gate::forUser(User::factory()->admin()->create())->allows('view', $order))->toBeTrue();
    expect(Gate::forUser(User::factory()->create())->allows('view', $order))->toBeFalse();
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('view', $order))->toBeFalse();
});
