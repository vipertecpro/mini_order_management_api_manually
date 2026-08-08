<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

test('products are listed with pagination', function () {
    Product::factory()->count(20)->create();

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'price', 'stock']], 'meta' => ['total']]);
});

test('a product can be created', function () {
    $this->postJson('/api/products', [
        'name' => 'Mechanical Keyboard',
        'description' => 'Blue switches.',
        'price' => 129.99,
        'stock' => 10,
    ])->assertCreated()->assertJsonPath('data.name', 'Mechanical Keyboard');

    $this->assertDatabaseHas('products', [
        'name' => 'Mechanical Keyboard',
        'created_by' => $this->user->id,
    ]);
});

test('creating a product validates the input', function () {
    $this->postJson('/api/products', [
        'name' => '',
        'price' => -5,
        'stock' => 'ten',
    ])->assertStatus(422)->assertJsonValidationErrors(['name', 'price', 'stock']);
});

test('a single product can be fetched', function () {
    $product = Product::factory()->create();

    $this->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id);
});

test('a missing product returns 404', function () {
    $this->getJson('/api/products/999')->assertNotFound();
});

test('the owner can update their product', function () {
    $product = Product::factory()->for($this->user, 'creator')->create();

    $this->patchJson("/api/products/{$product->id}", ['price' => 49.50])
        ->assertOk()
        ->assertJsonPath('data.price', '49.50');
});

test('you cannot update somebody else product', function () {
    $product = Product::factory()->create();

    $this->patchJson("/api/products/{$product->id}", ['price' => 1])->assertForbidden();
});

test('the owner can delete their product', function () {
    $product = Product::factory()->for($this->user, 'creator')->create();

    $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

    $this->assertDatabaseMissing('products', ['id' => $product->id]);
});

test('you cannot delete somebody else product', function () {
    $product = Product::factory()->create();

    $this->deleteJson("/api/products/{$product->id}")->assertForbidden();

    $this->assertDatabaseHas('products', ['id' => $product->id]);
});

test('products can be searched by name', function () {
    Product::factory()->create(['name' => 'Mechanical Keyboard']);
    Product::factory()->create(['name' => 'Office Chair']);

    $this->getJson('/api/products?search=keyboard')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mechanical Keyboard');
});

test('products can be filtered by price range', function () {
    Product::factory()->create(['name' => 'Cheap', 'price' => 10]);
    Product::factory()->create(['name' => 'Mid', 'price' => 100]);
    Product::factory()->create(['name' => 'Pricey', 'price' => 900]);

    $this->getJson('/api/products?min_price=50&max_price=500')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mid');
});

test('products can be filtered by availability', function () {
    Product::factory()->create(['name' => 'In stock', 'stock' => 5]);
    Product::factory()->outOfStock()->create(['name' => 'Sold out']);

    $this->getJson('/api/products?in_stock=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'In stock');
});
