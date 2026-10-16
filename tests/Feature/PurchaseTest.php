<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;

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

test('paying creates a pending order and moves to the Stripe payment page', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['price' => 1500]);

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->withArgs(fn (Order $order) => $order->is(Order::sole()))
        ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 2]]);

    Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

    $order = Order::sole();

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

test('a free order skips Stripe, is paid at once with its tickets issued and moves to the complete page', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['name' => '無料券', 'price' => 0]);

    $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 2]]);

    $component = Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay');

    $order = Order::sole();

    $component->assertRedirect(route('orders.complete', $order));

    expect($order->total_amount)->toBe(0)
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and(Ticket::count())->toBe(2);
});

test('a Stripe error while opening the payment page is reported', function () {
    Exceptions::fake();

    $ticketType = TicketType::factory()->for($this->event)->create();
    $exception = new ApiConnectionException('Could not connect to Stripe.');

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->andThrow($exception);

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 1]]);

    Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay');

    Exceptions::assertReported(fn (ApiConnectionException $reported) => $reported === $exception);
});

test('the order is cancelled to release its tickets when the Stripe payment page cannot be opened', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 1]);

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->andThrow(new ApiConnectionException('Could not connect to Stripe.'));

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 1]]);

    Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay');

    expect(Order::sole())
        ->status->toBe(OrderStatus::Cancelled)
        ->stripe_checkout_session_id->toBeNull()
        ->and($ticketType->remaining())->toBe(1)
        ->and(session("purchase.{$this->event->id}"))->toBe([$ticketType->id => 1]);
});

test('paying again after a Stripe error moves to the Stripe payment page with a new order', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 1]);

    $this->mock(CheckoutService::class, function ($mock) {
        $mock->expects('createSession')->andThrow(new ApiConnectionException('Could not connect to Stripe.'));
        $mock->expects('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');
    });

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 1]]);

    Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->call('pay')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

    expect(Order::where('status', OrderStatus::Pending)->count())->toBe(1)
        ->and(Order::where('status', OrderStatus::Cancelled)->count())->toBe(1)
        ->and(session()->has("purchase.{$this->event->id}"))->toBeFalse();
});

test('any kind of Stripe error shows the same error without Stripe\'s own message', function (ApiErrorException $exception) {
    $ticketType = TicketType::factory()->for($this->event)->create();

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->andThrow($exception);

    $this->actingAs($this->user)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 1]]);

    Livewire::test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->assertSee('決済ページを開けませんでした。時間をおいてお試しください。')
        ->assertDontSee($exception->getMessage())
        ->assertNoRedirect();
})->with([
    'connection failure' => [fn () => new ApiConnectionException('Could not connect to Stripe.')],
    'invalid API key' => [fn () => new AuthenticationException('Invalid API Key provided: sk_test_dummy')],
    'invalid request' => [fn () => new InvalidRequestException('Missing required param: line_items.')],
    'rate limit' => [fn () => new RateLimitException('Too many requests hit the API too quickly.')],
]);

test('paying twice creates only one order', function () {
    $ticketType = TicketType::factory()->for($this->event)->create();

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');

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
        ->assertHasErrors('purchase')
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
        ->assertSee('支払いを確認中です');
});

test('other users cannot see the complete page of someone else\'s order', function () {
    $order = Order::factory()->for($this->user)->for($this->event)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('orders.complete', $order))
        ->assertForbidden();
});

test('paying is not found and creates no order when the event stops being published after opening the page', function (EventStatus $status) {
    $ticketType = TicketType::factory()->for($this->event)->create();

    $this->actingAs($this->event->organizer)
        ->withSession(["purchase.{$this->event->id}" => [$ticketType->id => 1]]);

    $component = Livewire::test('pages::events.purchase', ['event' => $this->event]);

    $this->event->forceFill(['status' => $status])->save();

    $component->call('pay')->assertStatus(404);

    expect(Order::count())->toBe(0);
})->with([
    'draft' => [EventStatus::Draft],
    'unpublished' => [EventStatus::Unpublished],
]);

test('proceeding is not found for events that are not published', function (EventStatus $status) {
    $event = Event::factory()->create(['status' => $status, 'starts_at' => now()->addMonth()]);
    $ticketType = TicketType::factory()->for($event)->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::events.show', ['event' => $event])
        ->set('quantities', [$ticketType->id => '1'])
        ->call('proceed')
        ->assertStatus(404);

    expect(session()->has("purchase.{$event->id}"))->toBeFalse();
})->with([
    'draft' => [EventStatus::Draft],
    'unpublished' => [EventStatus::Unpublished],
]);

test('the event organizer and admins can see the complete page', function (callable $makeViewer) {
    $order = Order::factory()->for($this->user)->for($this->event)->create();

    $this->actingAs($makeViewer($this->event))
        ->get(route('orders.complete', $order))
        ->assertOk();
})->with([
    'organizer' => [fn (Event $event) => $event->organizer],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

test('guests are redirected to the login page from the complete page', function () {
    $order = Order::factory()->for($this->user)->for($this->event)->create();

    $this->get(route('orders.complete', $order))
        ->assertRedirect(route('login'));
});
