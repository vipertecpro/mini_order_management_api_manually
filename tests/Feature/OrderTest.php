<?php

use App\Jobs\ProcessOrder;
use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

test('an order can be placed', function () {
    $keyboard = Product::factory()->create(['price' => 100.00, 'stock' => 10]);
    $mouse = Product::factory()->create(['price' => 25.50, 'stock' => 4]);

    $response = $this->postJson('/api/orders', [
        'items' => [
            ['product_id' => $keyboard->id, 'quantity' => 2],
            ['product_id' => $mouse->id, 'quantity' => 1],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.total_amount', '225.50')
        ->assertJsonCount(2, 'data.items');

    $this->assertDatabaseHas('orders', [
        'user_id' => $this->user->id,
        'total_amount' => 225.50,
    ]);
});

test('placing an order reduces the stock', function () {
    $product = Product::factory()->create(['stock' => 10]);

    $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 3]],
    ])->assertCreated();

    expect($product->fresh()->stock)->toBe(7);
});

test('an order is rejected when there is not enough stock', function () {
    $product = Product::factory()->create(['name' => 'Webcam', 'stock' => 2]);

    $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 5]],
    ])->assertStatus(422)->assertJsonValidationErrors('items');

    expect($product->fresh()->stock)->toBe(2);
    $this->assertDatabaseCount('orders', 0);
});

test('nothing is saved when one line of a multi line order is short', function () {
    $available = Product::factory()->create(['stock' => 10]);
    $short = Product::factory()->create(['stock' => 1]);

    $this->postJson('/api/orders', [
        'items' => [
            ['product_id' => $available->id, 'quantity' => 2],
            ['product_id' => $short->id, 'quantity' => 4],
        ],
    ])->assertStatus(422);

    expect($available->fresh()->stock)->toBe(10);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});

test('the same product twice in one order is added up', function () {
    $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);

    $this->postJson('/api/orders', [
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 3],
        ],
    ])->assertCreated()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quantity', 5)
        ->assertJsonPath('data.total_amount', '50.00');

    expect($product->fresh()->stock)->toBe(0);
});

test('an order needs at least one item', function () {
    $this->postJson('/api/orders', ['items' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');
});

test('an order cannot reference a product that does not exist', function () {
    $this->postJson('/api/orders', [
        'items' => [['product_id' => 999, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
});

test('a user only sees their own orders', function () {
    Order::factory()->count(2)->for($this->user)->create();
    Order::factory()->count(3)->create();

    $this->getJson('/api/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a user can view their own order with its items', function () {
    $product = Product::factory()->create(['stock' => 5]);

    $orderId = $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->json('data.id');

    $this->getJson("/api/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.items.0.product_name', $product->name);
});

test('a user cannot view somebody else order', function () {
    $order = Order::factory()->create();

    $this->getJson("/api/orders/{$order->id}")->assertForbidden();
});

test('placing an order queues the processing job', function () {
    Queue::fake();

    $product = Product::factory()->create(['stock' => 5]);

    $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();

    Queue::assertPushed(ProcessOrder::class);
});

test('the job emails the customer and completes the order', function () {
    Mail::fake();

    $product = Product::factory()->create(['stock' => 5]);

    $orderId = $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->json('data.id');

    Mail::assertSent(OrderPlaced::class, fn ($mail) => $mail->hasTo($this->user->email));

    expect(Order::find($orderId)->status)->toBe('completed');
});

test('the job does not send a second email if the order is already done', function () {
    Mail::fake();

    $order = Order::factory()->for($this->user)->create(['status' => 'completed']);

    (new ProcessOrder($order))->handle();

    Mail::assertNothingSent();
});

test('the confirmation email shows the order details', function () {
    $product = Product::factory()->create(['name' => 'Mechanical Keyboard', 'price' => 100, 'stock' => 5]);

    $orderId = $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->json('data.id');

    $rendered = (new OrderPlaced(Order::find($orderId)))->render();

    expect($rendered)
        ->toContain($this->user->name)
        ->toContain('Mechanical Keyboard')
        ->toContain('200.00');
});
