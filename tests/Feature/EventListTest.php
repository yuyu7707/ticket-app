<?php

use App\Models\Event;
use App\Models\TicketType;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-01 12:00');
});

test('guests can see published events that have not ended, nearest first', function () {
    Event::factory()->published()->create(['title' => '後のイベント', 'starts_at' => '2026-11-20 10:00', 'ends_at' => null]);
    $sooner = Event::factory()->published()->create(['title' => '先のイベント', 'starts_at' => '2026-11-05 10:00', 'ends_at' => '2026-11-05 18:00']);
    Event::factory()->published()->create(['title' => '開催中のイベント', 'starts_at' => '2026-11-01 10:00', 'ends_at' => '2026-11-01 18:00']);
    Event::factory()->published()->create(['title' => '終了したイベント', 'starts_at' => '2026-10-31 10:00', 'ends_at' => '2026-10-31 18:00']);
    Event::factory()->published()->create(['title' => '終了時刻なしの過去イベント', 'starts_at' => '2026-10-31 10:00', 'ends_at' => null]);
    Event::factory()->create(['title' => '下書きのイベント', 'starts_at' => '2026-11-10 10:00', 'ends_at' => null]);
    Event::factory()->unpublished()->create(['title' => '非公開のイベント', 'starts_at' => '2026-11-10 10:00', 'ends_at' => null]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('events.show', $sooner))
        ->assertSeeInOrder(['開催中のイベント', '先のイベント', '後のイベント'])
        ->assertDontSee('終了したイベント')
        ->assertDontSee('終了時刻なしの過去イベント')
        ->assertDontSee('下書きのイベント')
        ->assertDontSee('非公開のイベント');
});

test('the lowest ticket price is shown', function (array $prices, string $expected) {
    $event = Event::factory()->published()->create(['starts_at' => '2026-11-10 10:00', 'ends_at' => null]);

    foreach ($prices as $price) {
        TicketType::factory()->for($event)->create(['price' => $price]);
    }

    $this->get(route('home'))->assertSee($expected);
})->with([
    'paid' => [[3000, 1500], '1,500円〜'],
    'free' => [[0, 2000], '無料'],
]);

test('events are paginated by 12', function () {
    foreach (range(1, 13) as $day) {
        Event::factory()->published()->create([
            'title' => sprintf('イベント%02d', $day),
            'starts_at' => sprintf('2026-11-%02d 10:00', $day + 1),
            'ends_at' => null,
        ]);
    }

    Livewire::test('pages::events.index')
        ->assertSee('イベント12')
        ->assertDontSee('イベント13')
        ->call('nextPage')
        ->assertSee('イベント13')
        ->assertDontSee('イベント12');
});
