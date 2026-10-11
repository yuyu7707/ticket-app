<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;

test('guests can see a published event with its ticket types', function () {
    $organizer = User::factory()->organizer()->create(['name' => '山田商店街']);
    $event = Event::factory()->published()->for($organizer, 'organizer')->create([
        'title' => '秋祭り',
        'venue' => '中央公園',
        'starts_at' => '2026-11-10 10:00',
        'ends_at' => '2026-11-10 16:00',
    ]);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 100]);
    TicketType::factory()->for($event)->free()->create(['name' => '子ども']);
    OrderItem::factory()
        ->for(Order::factory()->paid()->for($event))
        ->for($ticketType)
        ->create(['quantity' => 3]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('秋祭り')
        ->assertSee('中央公園')
        ->assertSee('山田商店街')
        ->assertSee('11月10日(火) 10:00')
        ->assertSee('3,000円')
        ->assertSee('無料')
        ->assertSee('残り97枚');
});

test('sold out ticket types are marked', function () {
    $ticketType = TicketType::factory()
        ->for(Event::factory()->published())
        ->create(['capacity' => 2]);
    OrderItem::factory()
        ->for(Order::factory()->paid()->for($ticketType->event))
        ->for($ticketType)
        ->create(['quantity' => 2]);

    $this->get(route('events.show', $ticketType->event))->assertSee('売り切れ');
});

test('drafts and unpublished events are hidden from everyone but the owner and admins', function (EventStatus $status) {
    $event = Event::factory()->create(['status' => $status]);

    $this->get(route('events.show', $event))->assertNotFound();

    $this->actingAs(User::factory()->organizer()->create())
        ->get(route('events.show', $event))
        ->assertNotFound();

    $this->actingAs($event->organizer)
        ->get(route('events.show', $event))
        ->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('events.show', $event))
        ->assertOk();
})->with([EventStatus::Draft, EventStatus::Unpublished]);

test('customers cannot see drafts and unpublished events', function (EventStatus $status) {
    $event = Event::factory()->create(['status' => $status]);

    $this->actingAs(User::factory()->create())
        ->get(route('events.show', $event))
        ->assertNotFound();
})->with([
    'draft' => [EventStatus::Draft],
    'unpublished' => [EventStatus::Unpublished],
]);
