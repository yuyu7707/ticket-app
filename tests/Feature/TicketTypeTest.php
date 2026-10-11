<?php

use App\Models\Event;
use App\Models\TicketType;

test('ticket types are on sale only within the sales period', function (string $now, ?string $salesStartAt, ?string $salesEndAt, bool $expected) {
    $event = Event::factory()->create(['starts_at' => '2026-12-01 10:00']);
    $ticketType = TicketType::factory()->for($event)->create([
        'sales_start_at' => $salesStartAt,
        'sales_end_at' => $salesEndAt,
    ]);

    $this->travelTo($now);

    expect($ticketType->isOnSale())->toBe($expected);
})->with([
    'before the sales start' => ['2026-10-31 23:59', '2026-11-01 00:00', '2026-11-20 00:00', false],
    'at the sales start' => ['2026-11-01 00:00', '2026-11-01 00:00', '2026-11-20 00:00', true],
    'at the sales end' => ['2026-11-20 00:00', '2026-11-01 00:00', '2026-11-20 00:00', false],
    'no period before the event starts' => ['2026-11-30 12:00', null, null, true],
    'no end at the event start' => ['2026-12-01 10:00', '2026-11-01 00:00', null, false],
]);
