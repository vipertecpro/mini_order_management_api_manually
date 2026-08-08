<?php

namespace App\Services;

use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Place an order for the given user.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function place(User $user, array $items): Order
    {
        $order = DB::transaction(function () use ($user, $items) {
            $quantities = $this->mergeQuantities($items);

            // Lock the rows so two people buying the last unit at the same
            // time can't both pass the stock check below.
            $products = Product::whereIn('id', array_keys($quantities))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $this->guardAgainstMissingStock($quantities, $products);

            $order = $user->orders()->create([
                'total_amount' => 0,
                'status' => 'pending',
            ]);

            $total = 0;

            foreach ($quantities as $productId => $quantity) {
                $product = $products[$productId];
                $subtotal = round($product->price * $quantity, 2);
                $total += $subtotal;

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'subtotal' => $subtotal,
                ]);

                $product->decrement('stock', $quantity);
            }

            $order->update(['total_amount' => round($total, 2)]);

            return $order;
        });

        // Stock changed, so any cached product listing is out of date.
        Cache::tags('products')->flush();

        ProcessOrder::dispatch($order);

        return $order->load('items.product');
    }

    /**
     * Collapse duplicated product lines into a single quantity per product.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return array<int, int>
     */
    private function mergeQuantities(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $item['quantity'];
        }

        return $quantities;
    }

    /**
     * Reject the whole order if any line asks for more than we have.
     *
     * @param  array<int, int>  $quantities
     * @param  Collection<int, Product>  $products
     */
    private function guardAgainstMissingStock(array $quantities, Collection $products): void
    {
        $errors = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product) {
                $errors[] = "Product #{$productId} is no longer available.";

                continue;
            }

            if ($product->stock < $quantity) {
                $errors[] = "{$product->name}: you asked for {$quantity} but only {$product->stock} left in stock.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['items' => $errors]);
        }
    }
}
