<?php

use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Str;

it('deletes only stale guest carts via carts cleanup command', function (): void {
    $staleGuestCart = Cart::query()->create([
        'user_id' => null,
        'token' => (string) Str::uuid(),
    ]);

    $freshGuestCart = Cart::query()->create([
        'user_id' => null,
        'token' => (string) Str::uuid(),
    ]);

    $userCart = Cart::query()->create([
        'user_id' => User::factory()->create()->id,
        'token' => (string) Str::uuid(),
    ]);

    $staleGuestCart->forceFill([
        'created_at' => now()->subDays(45),
        'updated_at' => now()->subDays(45),
    ])->saveQuietly();

    $freshGuestCart->forceFill([
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ])->saveQuietly();

    $userCart->forceFill([
        'created_at' => now()->subDays(60),
        'updated_at' => now()->subDays(60),
    ])->saveQuietly();

    $this->artisan('carts:cleanup', ['days' => 30])
        ->expectsOutput('Guest carts older than 30 days deleted.')
        ->assertExitCode(0);

    $this->assertDatabaseMissing('carts', [
        'id' => $staleGuestCart->id,
    ]);

    $this->assertDatabaseHas('carts', [
        'id' => $freshGuestCart->id,
        'user_id' => null,
    ]);

    $this->assertDatabaseHas('carts', [
        'id' => $userCart->id,
        'user_id' => $userCart->user_id,
    ]);
});
