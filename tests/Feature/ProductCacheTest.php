<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

test('a repeated listing is served from the cache', function () {
    Product::factory()->count(3)->create();

    $this->getJson('/api/products')->assertOk();

    DB::enableQueryLog();
    $this->getJson('/api/products')->assertOk();

    $queries = collect(DB::getQueryLog())->filter(
        fn ($query) => str_contains($query['query'], 'from "products"')
    );

    expect($queries)->toBeEmpty();
});

test('different filters are cached separately', function () {
    Product::factory()->create(['name' => 'Mechanical Keyboard']);
    Product::factory()->create(['name' => 'Office Chair']);

    $this->getJson('/api/products')->assertJsonCount(2, 'data');
    $this->getJson('/api/products?search=chair')->assertJsonCount(1, 'data');
});

test('creating a product clears the cache', function () {
    Product::factory()->create();

    $this->getJson('/api/products')->assertJsonCount(1, 'data');

    $this->postJson('/api/products', [
        'name' => 'Webcam',
        'price' => 59.99,
        'stock' => 3,
    ])->assertCreated();

    $this->getJson('/api/products')->assertJsonCount(2, 'data');
});

test('updating a product clears the cache', function () {
    $product = Product::factory()->for($this->user, 'creator')->create(['name' => 'Old name']);

    $this->getJson('/api/products')->assertJsonPath('data.0.name', 'Old name');

    $this->patchJson("/api/products/{$product->id}", ['name' => 'New name'])->assertOk();

    $this->getJson('/api/products')->assertJsonPath('data.0.name', 'New name');
});

test('deleting a product clears the cache', function () {
    $product = Product::factory()->for($this->user, 'creator')->create();

    $this->getJson('/api/products')->assertJsonCount(1, 'data');

    $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

    $this->getJson('/api/products')->assertJsonCount(0, 'data');
});

test('placing an order clears the cache so stock is not stale', function () {
    $product = Product::factory()->create(['stock' => 10]);

    $this->getJson('/api/products')->assertJsonPath('data.0.stock', 10);

    $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 4]],
    ])->assertCreated();

    $this->getJson('/api/products')->assertJsonPath('data.0.stock', 6);
});
