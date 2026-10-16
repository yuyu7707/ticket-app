<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array<string, string>
 */
function validEventInput(): array
{
    return [
        'title' => '春のクラフト市',
        'description' => '地元の作家が集まる手作り市です。',
        'venue' => '市民ホール',
        'starts_at' => '2026-12-01T10:00',
        'ends_at' => '2026-12-01T16:00',
    ];
}

test('organizer event pages are forbidden for other roles', function (UserRole $role) {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->create(['role' => $role]));

    $this->get(route('organizer.events.index'))->assertForbidden();
    $this->get(route('organizer.events.create'))->assertForbidden();
    $this->get(route('organizer.events.edit', $event))->assertForbidden();
})->with([
    'customer' => [UserRole::Customer],
    'admin' => [UserRole::Admin],
]);

test('guests are redirected to the login page', function () {
    $this->get(route('organizer.events.index'))->assertRedirect(route('login'));
});

test('the index lists only the organizer\'s own events', function () {
    $organizer = User::factory()->organizer()->create();
    $ownEvent = Event::factory()->for($organizer, 'organizer')->published()->create([
        'starts_at' => '2026-12-01 10:00',
    ]);
    $otherEvent = Event::factory()->create();

    $this->actingAs($organizer)
        ->get(route('organizer.events.index'))
        ->assertOk()
        ->assertSee($ownEvent->title)
        ->assertSee('12月1日(火) 10:00')
        ->assertSee('公開中')
        ->assertDontSee($otherEvent->title);
});

test('organizers can create an event as a draft', function () {
    $organizer = User::factory()->organizer()->create();

    $this->actingAs($organizer);

    Livewire::test('pages::organizer.events.create')
        ->set('form', validEventInput())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('organizer.events.edit', Event::first()));

    $event = Event::sole();

    expect($event->organizer_id)->toBe($organizer->id);
    expect($event->status)->toBe(EventStatus::Draft);
    expect($event->title)->toBe('春のクラフト市');
    expect($event->starts_at->format('Y-m-d H:i'))->toBe('2026-12-01 10:00');
});

test('event input is validated', function (string $field, string $value, string $rule) {
    $this->actingAs(User::factory()->organizer()->create());

    Livewire::test('pages::organizer.events.create')
        ->set('form', validEventInput())
        ->set("form.{$field}", $value)
        ->call('save')
        ->assertHasErrors(["form.{$field}" => $rule]);

    expect(Event::count())->toBe(0);
})->with([
    'title is required' => ['title', '', 'required'],
    'title max 100' => ['title', str_repeat('あ', 101), 'max'],
    'description is required' => ['description', '', 'required'],
    'venue is required' => ['venue', '', 'required'],
    'venue max 100' => ['venue', str_repeat('あ', 101), 'max'],
    'starts_at is required' => ['starts_at', '', 'required'],
    'ends_at equal to starts_at' => ['ends_at', '2026-12-01T10:00', 'after'],
    'ends_at cannot be before starts_at' => ['ends_at', '2026-12-01T09:00', 'after'],
]);

test('customers cannot mount the create component', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]));

    Livewire::test('pages::organizer.events.create')->assertForbidden();
});

test('owners can update their event', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('form', validEventInput())
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->title)->toBe('春のクラフト市');
});

test('owners can switch between draft and published', function () {
    $event = Event::factory()->create();
    TicketType::factory()->for($event)->create();

    $this->actingAs($event->organizer);

    $component = Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish');

    expect($event->fresh()->status)->toBe(EventStatus::Published);

    $component->call('togglePublish');

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

test('owners cannot republish an event unpublished by an admin', function () {
    $event = Event::factory()->unpublished()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish')
        ->assertForbidden();

    expect($event->fresh()->status)->toBe(EventStatus::Unpublished);
});

test('owners can delete their event', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertRedirect(route('organizer.events.index'));

    expect(Event::count())->toBe(0);
});

test('events with pending or cancelled orders only are deleted along with the orders', function (OrderStatus $status) {
    $ticketType = TicketType::factory()->create();
    $event = $ticketType->event;
    OrderItem::factory()->for(Order::factory()->for($event)->create(['status' => $status]))->for($ticketType)->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertRedirect(route('organizer.events.index'));

    expect(Event::count())->toBe(0)
        ->and(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)
        ->and(TicketType::count())->toBe(0);
})->with([
    'pending' => [OrderStatus::Pending],
    'cancelled' => [OrderStatus::Cancelled],
]);

test('events with orders to keep cannot be deleted', function (array $statuses) {
    $ticketType = TicketType::factory()->create();
    $event = $ticketType->event;

    foreach ($statuses as $status) {
        OrderItem::factory()
            ->for(Order::factory()->for($event)->create(['status' => $status]))
            ->for($ticketType)
            ->create();
    }

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertNoRedirect();

    expect($event->fresh())->not->toBeNull()
        ->and(Order::count())->toBe(count($statuses))
        ->and(OrderItem::count())->toBe(count($statuses));
})->with([
    'paid' => [[OrderStatus::Paid]],
    'paid and pending' => [[OrderStatus::Paid, OrderStatus::Pending]],
    'refunded' => [[OrderStatus::Refunded]],
]);

test('other organizers cannot open the edit page', function () {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->organizer()->create())
        ->get(route('organizer.events.edit', $event))
        ->assertForbidden();
});

test('other organizers cannot mount the edit component', function () {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->organizer()->create());

    Livewire::test('pages::organizer.events.edit', ['event' => $event])->assertForbidden();
});

test('actions are authorized even after the edit page has been opened', function (string $method) {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    $component = Livewire::test('pages::organizer.events.edit', ['event' => $event]);

    $event->organizer()->associate(User::factory()->organizer()->create())->save();

    $component->set('form.title', '書き換え')->call($method)->assertForbidden();

    $event->refresh();

    expect($event->title)->not->toBe('書き換え');
    expect($event->status)->toBe(EventStatus::Draft);
})->with(['save', 'togglePublish', 'deleteEvent']);

test('an event can be created without an end time', function () {
    $organizer = User::factory()->organizer()->create();

    $this->actingAs($organizer);

    Livewire::test('pages::organizer.events.create')
        ->set('form', [...validEventInput(), 'ends_at' => ''])
        ->call('save')
        ->assertHasNoErrors();

    expect(Event::sole()->ends_at)->toBeNull();
});
