<x-mail::message>
# チケットの購入が完了しました

{{ $order->user->name }} 様

ご購入ありがとうございます。チケットを発行しました。

**イベント**: {{ $order->event->title }}<br>
**日時**: {{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}<br>
**会場**: {{ $order->event->venue }}

<x-mail::table>
| 券種 | 単価 | 枚数 | 小計 |
|:-|-:|-:|-:|
@foreach ($order->items as $item)
| {{ $item->ticketType->name }} | {{ number_format($item->unit_price) }}円 | {{ $item->quantity }}枚 | {{ number_format($item->subtotal()) }}円 |
@endforeach
| **合計** | | | **{{ number_format($order->total_amount) }}円** |
</x-mail::table>

チケットのコードは注文詳細のページで確認できます。

<x-mail::button :url="route('orders.show', $order)">
注文詳細を見る
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
