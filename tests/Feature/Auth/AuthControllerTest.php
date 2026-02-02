<?php

use App\Models\User;
use function Pest\Laravel\postJson;

test('user can register with valid data', function () {
    $response = postJson('/api/auth/register', [
        'name' => 'John Doe',
        'username' => 'johndoe',
        'email' => 'john@example.com',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'message',
            'user' => ['id', 'name', 'username', 'email'],
            'token',
        ]);
});

test('user cannot login with invalid credentials', function () {
    $user = User::factory()->create([
        'email' => 'john@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = postJson('/api/auth/login', [
        'identity' => 'john@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(422);
});
