<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Services\CheckoutService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stand in for Stripe's HTTP client so no request leaves the test, and remember what was sent.
 */
function fakeStripeHttpClient(array $response): object
{
    $client = new class($response) implements ClientInterface
    {
        /** @var list<array{method: string, url: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function __construct(private array $response) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            return [json_encode($this->response), 200, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('creating a session sends the order items as JPY line items and saves the session ID on the order', function () {
    $this->freezeTime();

    $event = Event::factory()->published()->create(['title' => '雪上ヨガ', 'starts_at' => now()->addMonth()]);
    $general = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);
    $student = TicketType::factory()->for($event)->create(['name' => '学生', 'price' => 1500]);
    $order = Order::factory()->for($event)->create(['total_amount' => 7000]);
    OrderItem::factory()->for($order)->for($general)->create(['quantity' => 2, 'unit_price' => 2500]);
    OrderItem::factory()->for($order)->for($student)->create(['quantity' => 1, 'unit_price' => 2000]);

    $client = fakeStripeHttpClient([
        'id' => 'cs_test_123',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
    ]);

    $url = app(CheckoutService::class)->createSession($order);

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_123')
        ->and($order->fresh()->stripe_checkout_session_id)->toBe('cs_test_123')
        ->and($client->requests)->toHaveCount(1);

    $request = $client->requests[0];

    expect($request['method'])->toBe('post')
        ->and($request['url'])->toEndWith('/v1/checkout/sessions')
        ->and($request['params'])->toMatchArray([
            'mode' => 'payment',
            'metadata' => ['order_id' => (string) $order->id],
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
            'expires_at' => now()->addMinutes(30)->addSeconds(30)->getTimestamp(),
        ])
        ->and($request['params'])->not->toHaveKey('payment_method_types')
        ->and($request['params']['line_items'])->toEqualCanonicalizing([
            [
                'price_data' => ['currency' => 'jpy', 'unit_amount' => 2500, 'product_data' => ['name' => '雪上ヨガ 一般']],
                'quantity' => 2,
            ],
            [
                'price_data' => ['currency' => 'jpy', 'unit_amount' => 2000, 'product_data' => ['name' => '雪上ヨガ 学生']],
                'quantity' => 1,
            ],
        ]);
});

test('expiring a session asks Stripe to expire it', function () {
    $client = fakeStripeHttpClient([
        'id' => 'cs_test_123',
        'object' => 'checkout.session',
        'status' => 'expired',
    ]);

    app(CheckoutService::class)->expireSession('cs_test_123');

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['method'])->toBe('post')
        ->and($client->requests[0]['url'])->toEndWith('/v1/checkout/sessions/cs_test_123/expire');
});
