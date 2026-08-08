<x-mail::message>
# Thanks for your order, {{ $order->user->name }}!

Your order **#{{ $order->id }}** has been received. Here is what you bought:

<x-mail::table>
| Product | Qty | Unit price | Subtotal |
| :------ | :-: | ---------: | -------: |
@foreach ($order->items as $item)
| {{ $item->product->name }} | {{ $item->quantity }} | {{ number_format($item->unit_price, 2) }} | {{ number_format($item->subtotal, 2) }} |
@endforeach
</x-mail::table>

**Total: {{ number_format($order->total_amount, 2) }}**

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
