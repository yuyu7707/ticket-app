<?php

use App\Models\Event;

test('published upcoming events are listed nearest first', function () {
    $this->travelTo('2026-11-01 12:00');

    $later = Event::factory()->published()->create(['starts_at' => '2026-11-20 10:00']);
    $sooner = Event::factory()->published()->create(['starts_at' => '2026-11-05 10:00']);
    Event::factory()->published()->create(['starts_at' => '2026-10-31 10:00']);
    Event::factory()->create(['starts_at' => '2026-11-10 10:00']);
    Event::factory()->unpublished()->create(['starts_at' => '2026-11-10 10:00']);

    expect(Event::published()->upcoming()->pluck('id')->all())->toBe([$sooner->id, $later->id]);
});
