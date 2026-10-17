<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('published events can be viewed by anyone', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(null)->allows('view', $event))->toBeTrue();
    expect(Gate::forUser(User::factory()->create())->allows('view', $event))->toBeTrue();
});

test('draft and unpublished events can be viewed only by the owner and admins', function (EventStatus $status) {
    $event = Event::factory()->create(['status' => $status]);

    expect(Gate::forUser(null)->allows('view', $event))->toBeFalse();
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('view', $event))->toBeFalse();
    expect(Gate::forUser($event->organizer)->allows('view', $event))->toBeTrue();
    expect(Gate::forUser(User::factory()->admin()->create())->allows('view', $event))->toBeTrue();
})->with([
    'draft' => [EventStatus::Draft],
    'unpublished' => [EventStatus::Unpublished],
]);

test('only organizers can create events', function () {
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('create', Event::class))->toBeTrue();
    expect(Gate::forUser(User::factory()->create())->allows('create', Event::class))->toBeFalse();
    expect(Gate::forUser(User::factory()->admin()->create())->allows('create', Event::class))->toBeFalse();
});

test('only the owner can update and delete the event', function (string $ability) {
    $event = Event::factory()->create();

    expect(Gate::forUser($event->organizer)->allows($ability, $event))->toBeTrue();
    expect(Gate::forUser(User::factory()->organizer()->create())->allows($ability, $event))->toBeFalse();
    expect(Gate::forUser(User::factory()->admin()->create())->allows($ability, $event))->toBeFalse();
})->with(['update', 'delete']);

test('events with paid orders cannot be deleted even by the owner', function () {
    $order = Order::factory()->paid()->create();

    $response = Gate::forUser($order->event->organizer)->inspect('delete', $order->event);

    expect($response->denied())->toBeTrue()
        ->and($response->message())->toBe('支払い済みの注文があるため削除できません。');
});

test('only the owner can publish the event unless an admin has unpublished it', function () {
    $event = Event::factory()->create();
    $unpublishedEvent = Event::factory()->unpublished()->create();

    expect(Gate::forUser($event->organizer)->allows('publish', $event))->toBeTrue();
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('publish', $event))->toBeFalse();
    expect(Gate::forUser($unpublishedEvent->organizer)->allows('publish', $unpublishedEvent))->toBeFalse();
});

test('only admins can unpublish the event', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(User::factory()->admin()->create())->allows('unpublish', $event))->toBeTrue();
    expect(Gate::forUser($event->organizer)->allows('unpublish', $event))->toBeFalse();
});

test('published events can be bought by anyone', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(null)->allows('purchase', $event))->toBeTrue();
    expect(Gate::forUser(User::factory()->create())->allows('purchase', $event))->toBeTrue();
});

test('draft and unpublished events cannot be bought even by the owner and admins', function (EventStatus $status) {
    $event = Event::factory()->create(['status' => $status]);

    expect(Gate::forUser(null)->allows('purchase', $event))->toBeFalse();
    expect(Gate::forUser(User::factory()->create())->allows('purchase', $event))->toBeFalse();
    expect(Gate::forUser($event->organizer)->allows('purchase', $event))->toBeFalse();
    expect(Gate::forUser(User::factory()->admin()->create())->allows('purchase', $event))->toBeFalse();
})->with([
    'draft' => [EventStatus::Draft],
    'unpublished' => [EventStatus::Unpublished],
]);

test('only organizers can view the list of their events', function () {
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('viewAny', Event::class))->toBeTrue();
    expect(Gate::forUser(User::factory()->create())->allows('viewAny', Event::class))->toBeFalse();
    expect(Gate::forUser(User::factory()->admin()->create())->allows('viewAny', Event::class))->toBeFalse();
});
