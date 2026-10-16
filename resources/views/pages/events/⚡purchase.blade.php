<?php

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new class extends Component {
    #[Locked]
    public Event $event;

    /**
     * Selected quantities keyed by ticket type ID.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $quantities = [];

    /**
     * Load the quantities chosen on the event page, keeping only this event's ticket types.
     */
    public function mount(Event $event): void
    {
        $this->authorize('purchase', $event);

        $this->event = $event;

        $ticketTypeIds = $event->ticketTypes()->pluck('id')->all();

        foreach ((array) session("purchase.{$event->id}", []) as $ticketTypeId => $quantity) {
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

            if (in_array((int) $ticketTypeId, $ticketTypeIds, true) && $quantity !== false && $quantity > 0) {
                $this->quantities[(int) $ticketTypeId] = $quantity;
            }
        }

        if ($this->quantities === []) {
            $this->redirectRoute('events.show', $event, navigate: true);
        }
    }

    /**
     * Get the selected ticket types, cheapest first.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->whereKey(array_keys($this->quantities))->orderBy('price')->get();
    }

    /**
     * Get the total in yen at the current prices.
     */
    #[Computed]
    public function total(): int
    {
        return $this->ticketTypes->sum(fn (TicketType $ticketType) => $ticketType->price * $this->quantities[$ticketType->id]);
    }

    /**
     * Place the order and move to the Stripe payment page. Free orders skip Stripe, are paid at once and go straight to the complete page.
     */
    public function pay(OrderService $orderService, CheckoutService $checkoutService): void
    {
        $this->authorize('purchase', $this->event);

        if (! session()->has("purchase.{$this->event->id}")) {
            $this->redirectRoute('events.show', $this->event, navigate: true);

            return;
        }

        try {
            $order = $orderService->createOrder(Auth::user(), $this->event, $this->quantities);
        } catch (PurchaseException $exception) {
            $this->addError('purchase', $exception->getMessage());

            return;
        }

        if ($order->total_amount === 0) {
            $orderService->markAsPaid($order, null);

            session()->forget("purchase.{$this->event->id}");

            $this->redirectRoute('orders.complete', $order, navigate: true);

            return;
        }

        try {
            $checkoutUrl = $checkoutService->createSession($order);
        } catch (ApiErrorException $exception) {
            report($exception);

            // The order can never be paid, so release its tickets and keep the selection for a retry.
            $order->forceFill(['status' => OrderStatus::Cancelled])->save();

            $this->addError('purchase', '決済ページを開けませんでした。時間をおいてお試しください。');

            return;
        }

        session()->forget("purchase.{$this->event->id}");

        $this->redirect($checkoutUrl);
    }

    public function render(): View
    {
        return $this->view()->title('購入手続き');
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:link :href="route('events.show', $event)" wire:navigate class="inline-flex items-center gap-1 text-sm">
        <flux:icon.arrow-left variant="micro" />
        イベント詳細へ戻る
    </flux:link>

    <div class="space-y-1">
        <flux:heading size="xl" level="1">購入手続き</flux:heading>
        <flux:text>{{ $event->title }}（{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}）</flux:text>
    </div>

    @error('purchase')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">単価</flux:table.column>
            <flux:table.column align="end">枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->ticketTypes as $ticketType)
                @php($subtotal = $ticketType->price * $quantities[$ticketType->id])
                <flux:table.row :key="$ticketType->id">
                    <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                    <flux:table.cell align="end"><x-yen :amount="$ticketType->price" /></flux:table.cell>
                    <flux:table.cell align="end">{{ $quantities[$ticketType->id] }}枚</flux:table.cell>
                    <flux:table.cell align="end"><x-yen :amount="$subtotal" /></flux:table.cell>
                </flux:table.row>
            @endforeach

            <flux:table.row>
                <flux:table.cell variant="strong" colspan="3">合計</flux:table.cell>
                <flux:table.cell variant="strong" align="end" data-test="order-total"><x-yen :amount="$this->total" /></flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>

    <flux:button variant="primary" wire:click="pay" wire:loading.attr="disabled" data-test="pay-button">支払いへ進む</flux:button>
</section>
