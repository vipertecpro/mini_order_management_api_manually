<?php

use App\Models\Product;
use App\Models\User;

test('login attempts are throttled', function () {
    User::factory()->create(['email' => 'wasim@example.com']);

    foreach (range(1, 10) as $attempt) {
        $this->postJson('/api/login', [
            'email' => 'wasim@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/login', [
        'email' => 'wasim@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(429)->assertHeader('Retry-After');
});

test('placing orders is throttled', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $product = Product::factory()->create(['stock' => 1000]);

    foreach (range(1, 20) as $attempt) {
        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertStatus(429);
});

test('every api response carries the rate limit headers', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/products')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 60)
        ->assertHeader('X-RateLimit-Remaining', 59);
});

test('two users get their own allowance', function () {
    $product = Product::factory()->create(['stock' => 1000]);

    $this->actingAs(User::factory()->create(), 'sanctum');

    foreach (range(1, 20) as $attempt) {
        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();
});
