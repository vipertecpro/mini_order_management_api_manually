<?php

use App\Models\User;

test('a user can register and gets a token back', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Wasim',
        'email' => 'wasim@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email']]);

    $this->assertDatabaseHas('users', ['email' => 'wasim@example.com']);
});

test('registering with a taken email fails', function () {
    User::factory()->create(['email' => 'wasim@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Wasim',
        'email' => 'wasim@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('the password must be confirmed', function () {
    $this->postJson('/api/register', [
        'name' => 'Wasim',
        'email' => 'wasim@example.com',
        'password' => 'password123',
        'password_confirmation' => 'something-else',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

test('a user can log in', function () {
    User::factory()->create([
        'email' => 'wasim@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/login', [
        'email' => 'wasim@example.com',
        'password' => 'password123',
    ])->assertOk()->assertJsonStructure(['token']);
});

test('logging in with the wrong password fails', function () {
    User::factory()->create([
        'email' => 'wasim@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/login', [
        'email' => 'wasim@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('logging out deletes the token that was used', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withToken($token)->postJson('/api/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

test('protected routes reject requests without a token', function () {
    $this->getJson('/api/orders')->assertUnauthorized();
});
