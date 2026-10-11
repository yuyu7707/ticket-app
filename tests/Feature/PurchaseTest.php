<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->published()->create(['starts_at' => now()->addMonth()]);
});

test('guests are redirected to the login page from the purchase page', function () {
    $this->get(route('events.purchase', $this->event))
        ->assertRedirect(route('login'));
});

test('proceeding without choosing any tickets shows an error and keeps nothing in the session', function (?string $quantity) {
    $general = TicketType::factory()->for($this->event)->create();
    $student = TicketType::factory()->for($this->event)->create(['name' => '学生', 'price' => 1500]);

    $this->actingAs($this->user);

    Livewire::test('pages::events.show', ['event' => $this->event])
        ->set('quantities', $quantity === null ? [] : [$general->id => $quantity, $student->id => $quantity])
        ->call('proceed')
        ->assertHasErrors('quantities')
        ->assertNoRedirect();

    expect(session()->has("purchase.{$this->event->id}"))->toBeFalse();
})->with([
    'nothing selected' => [null],
    'all zero' => ['0'],
]);

test('proceeding keeps the chosen quantities in the session and moves to the purchase page', function () {
    $general = TicketType::factory()->for($this->event)->create();
    $student = TicketType::factory()->for($this->event)->create(['name' => '学生', 'price' => 1500]);

    $this->actingAs($this->user);

    Livewire::test('pages::events.show', ['event' => $this->event])
        ->set('quantities', [$general->id => '2', $student->id => '0'])
        ->call('proceed')
        ->assertHasNoErrors()
        ->assertRedirect(route('events.purchase', $this->event));

    expect(session("purchase.{$this->event->id}"))->toBe([$general->id => 2]);
});

test('the purchase page shows the total in yen', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['price' => 1500]);

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 2]]);

    Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->assertSee('1,500円')
        ->assertSeeInOrder(['合計', '3,000円']);
});

test('paying creates a pending order and moves to the complete page', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['price' => 1500]);

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 2]]);

    $component = Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay');

    $order = Order::sole();

    $component->assertRedirect(route('orders.complete', $order));

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($this->user->id)
        ->and($order->event_id)->toBe($this->event->id)
        ->and($order->total_amount)->toBe(3000)
        ->and($order->items->sole())
        ->ticket_type_id->toBe($ticketType->id)
        ->quantity->toBe(2)
        ->unit_price->toBe(1500);

    expect(session()->has("purchase.{$this->event->id}"))->toBeFalse();
});

test('paying twice creates only one order', function () {
    $ticketType = TicketType::factory()->for($this->event)->create();

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 2]]);

    Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->call('pay')
        ->assertRedirect(route('events.show', $this->event));

    expect(Order::count())->toBe(1);
});

test('paying shows an error without creating an order when the tickets run out after opening the page', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 2]);

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 2]]);

    $component = Livewire::test('pages::events.purchase', ['event' => $this->event]);

    OrderItem::factory()
        ->for(Order::factory()->paid()->for($this->event))
        ->for($ticketType)
        ->create(['quantity' => 1]);

    $component->call('pay')
        ->assertSet('errorMessage', '一般は残り1枚です。')
        ->assertSee('一般は残り1枚です。')
        ->assertNoRedirect();

    expect($this->user->orders()->count())->toBe(0);
});

test('the purchase page of a draft event is not found', function () {
    $event = Event::factory()->create(['starts_at' => now()->addMonth()]);
    $ticketType = TicketType::factory()->for($event)->create();

    $this->actingAs($event->organizer)
        ->withSession(["purchase.{$event->id}" => [$ticketType->id => 1]])
        ->get(route('events.purchase', $event))
        ->assertNotFound();
});

test('the buyer can see the complete page', function () {
    $order = Order::factory()->for($this->user)->for($this->event)->create();

    $this->actingAs($this->user)
        ->get(route('orders.complete', $order))
        ->assertOk()
        ->assertSee('注文を受け付けました（支払いは平日課題で実装）');
});

test('other users cannot see the complete page of someone else\'s order', function () {
    $order = Order::factory()->for($this->user)->for($this->event)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('orders.complete', $order))
        ->assertForbidden();
});
