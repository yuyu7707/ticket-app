<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array<string, int|string>
 */
function validTicketTypeInput(): array
{
    return [
        'name' => '学生',
        'price' => '1500',
        'capacity' => '30',
        'sales_start_at' => '2026-11-01T10:00',
        'sales_end_at' => '2026-11-30T23:59',
    ];
}

test('the edit page lists the event\'s ticket types', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    TicketType::factory()->for($event)->create([
        'name' => '一般',
        'price' => 3000,
        'sales_start_at' => '2026-11-01 10:00',
    ]);
    $otherTicketType = TicketType::factory()->create(['name' => '他のイベントの券種']);

    $this->actingAs($event->organizer)
        ->get(route('organizer.events.edit', $event))
        ->assertOk()
        ->assertSee('一般')
        ->assertSee('3,000円')
        ->assertSee('11月1日(日) 10:00')
        ->assertSee('イベント開始まで')
        ->assertDontSee($otherTicketType->name);
});

test('owners can add a ticket type', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticketTypeForm', validTicketTypeInput())
        ->call('saveTicketType')
        ->assertHasNoErrors()
        ->assertSet('ticketTypeForm.name', '');

    $ticketType = $event->ticketTypes()->sole();

    expect($ticketType->name)->toBe('学生');
    expect($ticketType->price)->toBe(1500);
    expect($ticketType->capacity)->toBe(30);
    expect($ticketType->sales_start_at->format('Y-m-d H:i'))->toBe('2026-11-01 10:00');
    expect($ticketType->sales_end_at->format('Y-m-d H:i'))->toBe('2026-11-30 23:59');
});

test('empty sales period is saved as null', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticketTypeForm', [...validTicketTypeInput(), 'sales_start_at' => '', 'sales_end_at' => ''])
        ->call('saveTicketType')
        ->assertHasNoErrors();

    $ticketType = $event->ticketTypes()->sole();

    expect($ticketType->sales_start_at)->toBeNull();
    expect($ticketType->sales_end_at)->toBeNull();
});

test('owners can edit a ticket type with the same form', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('editTicketType', $ticketType->id)
        ->assertSet('ticketTypeForm.name', '一般')
        ->assertSet('ticketTypeForm.price', 3000)
        ->set('ticketTypeForm.price', '2500')
        ->call('saveTicketType')
        ->assertHasNoErrors()
        ->assertSet('editingTicketTypeId', null);

    expect($event->ticketTypes()->count())->toBe(1);
    expect($ticketType->fresh()->price)->toBe(2500);
});

test('owners can delete a ticket type', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $ticketType = TicketType::factory()->for($event)->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteTicketType', $ticketType->id);

    expect(TicketType::count())->toBe(0);
});

test('ticket types with pending or cancelled orders only are deleted along with the orders', function (OrderStatus $status) {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    [$ticketType, $otherTicketType] = TicketType::factory()->for($event)->count(2)->create();
    OrderItem::factory()->for(Order::factory()->for($event)->create(['status' => $status]))->for($ticketType)->create();
    $otherOrder = Order::factory()->for($event)->create();
    OrderItem::factory()->for($otherOrder)->for($otherTicketType)->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteTicketType', $ticketType->id);

    expect($ticketType->fresh())->toBeNull()
        ->and(Order::sole()->is($otherOrder))->toBeTrue()
        ->and(OrderItem::count())->toBe(1);
})->with([
    'pending' => [OrderStatus::Pending],
    'cancelled' => [OrderStatus::Cancelled],
]);

test('ticket types with orders to keep cannot be deleted', function (OrderStatus $status) {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $ticketType = TicketType::factory()->for($event)->create();
    OrderItem::factory()
        ->for(Order::factory()->for($event)->create(['status' => $status]))
        ->for($ticketType)
        ->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteTicketType', $ticketType->id);

    expect($ticketType->fresh())->not->toBeNull()
        ->and(Order::count())->toBe(1)
        ->and(OrderItem::count())->toBe(1);
})->with([
    'paid' => [OrderStatus::Paid],
    'refunded' => [OrderStatus::Refunded],
]);

test('the capacity cannot be lowered below the paid and still held pending quantity', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 20]);
    OrderItem::factory()->for(Order::factory()->paid()->for($event)->create())->for($ticketType)->create(['quantity' => 3]);
    OrderItem::factory()->for(Order::factory()->for($event)->create())->for($ticketType)->create(['quantity' => 5]);
    OrderItem::factory()
        ->for(Order::factory()->for($event)->create(['created_at' => now()->subMinutes(config('tickets.pending_hold_minutes') + 1)]))
        ->for($ticketType)
        ->create(['quantity' => 4]);

    $this->actingAs($event->organizer);

    $component = Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('editTicketType', $ticketType->id)
        ->set('ticketTypeForm.capacity', '7')
        ->call('saveTicketType')
        ->assertHasErrors(['ticketTypeForm.capacity'])
        ->assertSee('定員は販売済み・支払い手続き中の枚数（8枚）以上にしてください。');

    expect($ticketType->fresh()->capacity)->toBe(20);

    $component->set('ticketTypeForm.capacity', '8')
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->capacity)->toBe(8);
});

test('the last ticket type of a published event cannot be deleted', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteTicketType', $ticketType->id);

    expect($ticketType->fresh())->not->toBeNull();
});

test('ticket type input is validated', function (string $field, string $value, string $rule) {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticketTypeForm', validTicketTypeInput())
        ->set("ticketTypeForm.{$field}", $value)
        ->call('saveTicketType')
        ->assertHasErrors(["ticketTypeForm.{$field}" => $rule]);

    expect(TicketType::count())->toBe(0);
})->with([
    'name is required' => ['name', '', 'required'],
    'name max 50' => ['name', str_repeat('あ', 51), 'max'],
    'price is required' => ['price', '', 'required'],
    'price cannot be negative' => ['price', '-1', 'min'],
    'price must be an integer' => ['price', '1.5', 'integer'],
    'price max 1000000' => ['price', '1000001', 'max'],
    'capacity is required' => ['capacity', '', 'required'],
    'capacity min 1' => ['capacity', '0', 'min'],
    'capacity max 100000' => ['capacity', '100001', 'max'],
    'sales_end_at equal to sales_start_at' => ['sales_end_at', '2026-11-01T10:00', 'after'],
    'sales_end_at cannot be before sales_start_at' => ['sales_end_at', '2026-11-01T09:00', 'after'],
    'sales_end_at cannot be after the event starts' => ['sales_end_at', '2026-12-01T10:01', 'before_or_equal'],
]);

test('ticket type errors call the name field the ticket type name', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticketTypeForm', validTicketTypeInput())
        ->set('ticketTypeForm.name', '')
        ->call('saveTicketType')
        ->assertSee('券種名は必須項目です。');
});

test('sales end can be set without a sales start', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticketTypeForm', [...validTicketTypeInput(), 'sales_start_at' => ''])
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($event->ticketTypes()->count())->toBe(1);
});

test('events without ticket types cannot be published', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish');

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

test('ticket type actions are forbidden for other organizers', function (string $method, bool $passesTicketTypeId) {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般']);

    $this->actingAs($event->organizer);

    $component = Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticketTypeForm', validTicketTypeInput());

    $event->organizer()->associate(User::factory()->organizer()->create())->save();

    $component->call($method, ...($passesTicketTypeId ? [$ticketType->id] : []))->assertForbidden();

    expect(TicketType::count())->toBe(1);
    expect($ticketType->fresh()->name)->toBe('一般');
})->with([
    'save' => ['saveTicketType', false],
    'edit' => ['editTicketType', true],
    'delete' => ['deleteTicketType', true],
]);

test('ticket types of other events cannot be touched', function (string $method) {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $otherTicketType = TicketType::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call($method, $otherTicketType->id)
        ->assertNotFound();

    expect($otherTicketType->fresh())->not->toBeNull();
})->with(['editTicketType', 'deleteTicketType']);

test('publishing an event without ticket types shows an error toast', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => $params['dataset']['variant'] === 'danger'
            && $params['slots']['text'] === '券種を1つ以上登録してください。');
});

test('a free ticket type with price 0 can be added', function () {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticketTypeForm', [...validTicketTypeInput(), 'price' => '0'])
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($event->ticketTypes()->sole()->price)->toBe(0);
});
